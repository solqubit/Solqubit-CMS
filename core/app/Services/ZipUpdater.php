<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ZipUpdater
{
    public function root(): string
    {
        return config('solqubit.update_storage', storage_path('app/updates'));
    }

    public function currentVersion(): string
    {
        return config('solqubit.version');
    }

    public function target(string $relative): string
    {
        require_once base_path('bootstrap/update-guard.php');

        return solqubit_update_target($relative, config('solqubit.update_core', base_path()), config('solqubit.update_site', public_path()));
    }

    /** @param 'github'|'unverified' $origin */
    public function apply(string $zip, string $resumeToken, int $actor, string $origin = 'unverified'): void
    {
        require_once base_path('bootstrap/update-guard.php');
        $root = $this->root();
        File::ensureDirectoryExists($root, 0700);
        $lock = fopen($root.'/update.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Hay otra actualización en curso.');
        }
        $journal = $root.'/transaction.json';
        $state = null;
        try {
            if (is_file($journal)) {
                throw new \RuntimeException('Hay una actualización pendiente de finalizar.');
            }
            $inspector = app(UpdatePackage::class);
            $manifest = $inspector->inspect($zip, $this->currentVersion());
            $free = disk_free_space($root);
            if ($free === false || $free < $manifest['unpacked_size'] * 3 + 50 * 1024 ** 2) {
                throw new \RuntimeException('No hay espacio suficiente para preparar y aplicar el paquete con seguridad.');
            }
            $this->ensureNewPublicFoldersAreFree(array_keys($manifest['files']));
            $releaseFiles = app(ReleaseFiles::class);
            $remove = $releaseFiles->obsolete($manifest);
            // Obsolete files go first so a retired file can become a directory in the same update.
            $paths = array_unique([...$remove, ...array_keys($manifest['files'])]);
            foreach ($paths as $relative) {
                $target = $this->target($relative);
                $parent = dirname($target);
                while (! is_dir($parent)) {
                    $parent = dirname($parent);
                }
                if (! is_writable($parent) || (file_exists($target) && ! is_file($target))) {
                    throw new \RuntimeException('PHP no puede actualizar '.$relative.'. Revisa el propietario y los permisos.');
                }
                if (str_starts_with($relative, 'core/database/migrations/') && is_file($target) && ! hash_equals(hash_file('sha256', $target), $manifest['files'][$relative]['sha256'])) {
                    throw new \RuntimeException('El paquete modifica una migración existente. Debe incluir una migración nueva.');
                }
            }
            File::deleteDirectory($root.'/stage');
            File::deleteDirectory($root.'/rollback');
            $inspector->inspect($zip, $this->currentVersion(), $root.'/stage');
            $state = ['phase' => 'copying', 'version' => $manifest['version'], 'from' => $this->currentVersion(), 'actor' => $actor, 'origin' => $origin, 'written' => [], 'resume_hash' => hash('sha256', $resumeToken), 'resume_expires' => time() + 3600];
            foreach ($paths as $relative) {
                $target = $this->target($relative);
                $exists = is_file($target);
                if ($exists) {
                    $saved = $root.'/rollback/'.$relative;
                    File::ensureDirectoryExists(dirname($saved), 0700);
                    if (! copy($target, $saved)) {
                        throw new \RuntimeException('No se pudo preparar la recuperación del código.');
                    }
                }
                $state['written'][$relative] = $exists;
            }
            solqubit_update_write($journal, $state);
            foreach ($remove as $relative) {
                $target = $this->target($relative);
                if (is_file($target) && ! unlink($target)) {
                    throw new \RuntimeException('No se pudo retirar un archivo obsoleto del código.');
                }
            }
            $releaseFiles->pruneEmptyDirectories($remove);
            foreach (array_keys($manifest['files']) as $relative) {
                solqubit_update_replace($root.'/stage/'.$relative, $this->target($relative));
            }
            $cache = dirname($this->target('core/bootstrap/app.php')).'/cache';
            foreach (glob($cache.'/*.php') ?: [] as $cached) {
                if (! unlink($cached)) {
                    throw new \RuntimeException('No se pudo limpiar la caché del código.');
                }
            }
            $state['phase'] = 'migrating';
            solqubit_update_write($journal, $state);
        } catch (\Throwable $error) {
            if ($state && $state['phase'] === 'copying') {
                solqubit_update_rollback($state, $root, config('solqubit.update_core', base_path()), config('solqubit.update_site', public_path()));
                @unlink($journal);
            }
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * A folder the public site does not have yet would be served instead of the panel, a page or a post with the same
     * address, so a package that adds one is rejected while that address is in use.
     *
     * @param  list<string>  $paths
     */
    private function ensureNewPublicFoldersAreFree(array $paths): void
    {
        $folders = array_unique(array_filter(array_map(fn (string $path): ?string => preg_match('~^site/([^/]+)/~', $path, $match) ? $match[1] : null, $paths)));
        foreach ($folders as $folder) {
            if (is_dir($this->target('site/'.$folder))) {
                continue;
            }
            $taken = match (true) {
                $folder === AdminAccess::path() => 'el panel de administración',
                Page::withTrashed()->where('kind', 'custom')->where('slug', $folder)->exists(), DB::table('page_paths')->where('path', $folder)->exists() => 'una página',
                in_array($folder, array_column(app(PostPermalinks::class)->configured(), 'slug'), true) => 'un prefijo de entradas',
                DB::table('post_paths')->where('path', $folder)->orWhere('path', 'like', $folder.'/%')->exists() => 'una entrada',
                default => null,
            };
            if ($taken) {
                throw new \RuntimeException('El paquete añade la carpeta pública site/'.$folder.', que taparía '.$taken.' con la misma dirección del sitio. Cambia esa dirección y vuelve a intentarlo.');
            }
        }
    }

    /** Called on a fresh request, after the new autoloader and application have been loaded. */
    public function finish(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        require_once base_path('bootstrap/update-guard.php');
        $root = $this->root();
        $journal = $root.'/transaction.json';
        $state = json_decode(file_get_contents($journal), true, 32, JSON_THROW_ON_ERROR);
        try {
            if (config('solqubit.version') !== $state['version']) {
                throw new \RuntimeException('El código instalado no coincide con la versión del paquete.');
            }
            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new \RuntimeException('No se pudieron completar las migraciones de la actualización.');
            }
            if (Artisan::call('view:clear') !== 0) {
                throw new \RuntimeException('No se pudo limpiar la caché de vistas.');
            }
            Setting::write('cms_version', $state['version']);
            Setting::write('last_update', ['version' => $state['version'], 'from' => $state['from'], 'at' => now()->toIso8601String()]);
            AuditEvent::create(['user_id' => $state['actor'], 'action' => 'system.updated', 'subject' => $state['version'], 'metadata' => ['from' => $state['from'], 'origin' => $state['origin'] ?? 'unverified']]);
            if (! unlink($journal)) {
                throw new \RuntimeException('No se pudo cerrar el estado de actualización.');
            }
        } catch (\Throwable $error) {
            $state['phase'] = 'failed';
            solqubit_update_write($journal, $state);
            report($error);
            throw new \RuntimeException('La actualización quedó pendiente al finalizar la base de datos. El sitio permanece protegido.');
        }
        foreach (['stage', 'rollback', 'packages'] as $directory) {
            try {
                File::deleteDirectory($root.'/'.$directory);
            } catch (\Throwable $error) {
                report($error);
            }
        }
    }
}
