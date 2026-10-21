<?php

namespace App\Http\Controllers;

use App\Filament\Pages\SystemStatus;
use App\Services\GitHubReleases;
use App\Services\GitHubUnavailable;
use App\Services\PackageOrigin;
use App\Services\ReleaseFiles;
use App\Services\UpdatePackage;
use App\Services\ZipUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class UpdateController extends Controller
{
    public function upload(Request $request, ZipUpdater $updater): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $request->validate(['package' => ['required', 'file', 'mimes:zip', 'max:131072']]);
        $directory = $this->packagesDirectory($updater);
        $id = bin2hex(random_bytes(24));
        $path = $directory.'/'.$id.'.zip';
        $request->file('package')->move($directory, $id.'.zip');
        @chmod($path, 0600);

        return $this->prepare($request, $id, $path, null);
    }

    /** Private folder for packages waiting for confirmation; packages older than a day are discarded. */
    private function packagesDirectory(ZipUpdater $updater): string
    {
        $directory = $updater->root().'/packages';
        File::ensureDirectoryExists($directory, 0700);
        foreach (glob($directory.'/*.zip') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }

        return $directory;
    }

    /** Validates a stored package and leaves it pending for confirmation. A null origin is checked against GitHub. */
    private function prepare(Request $request, string $id, string $path, ?string $origin): RedirectResponse
    {
        try {
            $manifest = app(UpdatePackage::class)->inspect($path, app(ZipUpdater::class)->currentVersion());
            $origin ??= app(PackageOrigin::class)->verify($path, $manifest['version']);
        } catch (\Throwable $error) {
            @unlink($path);
            throw ValidationException::withMessages(['package' => $error->getMessage()]);
        }
        $request->session()->put('cms_update', ['id' => $id, 'user_id' => $request->user()->id, 'hash' => hash_file('sha256', $path), 'version' => $manifest['version'], 'origin' => $origin, 'files' => count($manifest['files']), 'remove' => count(app(ReleaseFiles::class)->obsolete($manifest)), 'changes' => $manifest['changes'] ?? [], 'expires' => time() + 3600]);

        return redirect(SystemStatus::getUrl());
    }

    /** Downloads the newest release from the trusted repository and leaves it pending like an uploaded ZIP. */
    public function download(Request $request, GitHubReleases $releases, ZipUpdater $updater): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $latest = $releases->latest();
        $version = $request->input('version');
        if (! is_string($version) || ! preg_match('/^\d+\.\d+\.\d+$/', $version) || ! $releases->newer($latest) || $latest['version'] !== $version) {
            throw ValidationException::withMessages(['package' => 'Busca actualizaciones de nuevo antes de descargar.']);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(330);
        }
        try {
            $expected = $releases->checksum($version);
        } catch (GitHubUnavailable) {
            throw ValidationException::withMessages(['package' => 'No se pudo contactar con GitHub. Inténtalo más tarde o sube el ZIP manualmente.']);
        }
        if ($expected === null) {
            throw ValidationException::withMessages(['package' => 'La release no publica la huella del ZIP.']);
        }
        $id = bin2hex(random_bytes(24));
        $path = $this->packagesDirectory($updater).'/'.$id.'.zip';
        try {
            $releases->download($version, $path);
            if (! hash_equals($expected, (string) hash_file('sha256', $path))) {
                throw new \RuntimeException('El ZIP descargado no coincide con su huella publicada.');
            }
        } catch (\Throwable $error) {
            @unlink($path);
            throw ValidationException::withMessages(['package' => $error->getMessage()]);
        }

        return $this->prepare($request, $id, $path, 'github');
    }

    public function check(Request $request, GitHubReleases $releases): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $releases->latest(refresh: true);

        return redirect(SystemStatus::getUrl());
    }

    public function verifyFiles(Request $request, ReleaseFiles $releaseFiles): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        $request->session()->flash('cms_files_check', $releaseFiles->check() ?? false);

        return redirect(SystemStatus::getUrl());
    }

    public function apply(Request $request, ZipUpdater $updater)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $pending = $request->session()->get('cms_update');
        if (! $pending || $pending['user_id'] !== $request->user()->id || $pending['expires'] < time() || ! preg_match('/^[a-f0-9]{48}$/', $pending['id'])) {
            throw ValidationException::withMessages(['package' => 'Vuelve a seleccionar y validar el ZIP antes de actualizar.']);
        }
        $path = $updater->root().'/packages/'.$pending['id'].'.zip';
        if (! is_file($path) || ! hash_equals($pending['hash'], hash_file('sha256', $path))) {
            $request->session()->forget('cms_update');
            throw ValidationException::withMessages(['package' => 'El paquete cambió después de validarlo. Vuelve a subirlo.']);
        }
        $token = bin2hex(random_bytes(32));
        $response = redirect(SystemStatus::getUrl())->withCookie(cookie('solqubit_update_resume', $token, 60, '/', null, $request->isSecure(), true, false, 'strict'));
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(180);
            }
            $updater->apply($path, $token, $request->user()->id, $pending['origin'] ?? 'unverified');
        } catch (\Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['package' => $error->getMessage()]);
        }
        $request->session()->forget('cms_update');

        return $response;
    }
}
