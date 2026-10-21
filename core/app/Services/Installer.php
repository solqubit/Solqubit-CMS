<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class Installer
{
    public function install(array $data): void
    {
        $lock = fopen(storage_path('app/installation.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw ValidationException::withMessages(['installation' => 'Hay otra instalación en curso. Espera y vuelve a intentarlo.']);
        }
        try {
            abort_if(is_file(config('solqubit.installed_file')), 403, 'La instalación ya está completada.');
            if (! app(Requirements::class)->passes()) {
                throw ValidationException::withMessages(['installation' => 'Corrige los requisitos pendientes.']);
            }
            $database = app(InstallationDatabase::class);
            $data['db_host'] = $database->check($data)['host'] ?? $data['db_host'];
            $pending = storage_path('app/install-pending');
            file_put_contents($pending, $database->identity($data), LOCK_EX);
            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new \RuntimeException('No se pudieron ejecutar las migraciones.');
            }
            DB::transaction(function () use ($data) {
                if (User::exists()) {
                    $admin = User::where('email', $data['email'])->where('role', 'admin')->first();
                    if (! $admin || ! Hash::check($data['password'], $admin->password)) {
                        throw ValidationException::withMessages(['email' => 'Para reanudar, usa los datos del administrador creado en el intento anterior.']);
                    }
                } else {
                    User::create(['name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'], 'password' => $data['password'], 'role' => 'admin', 'is_active' => true]);
                }
                Setting::write('site', ['name' => $data['site_name'], 'description' => 'Ideas, historias y conocimiento para compartir.', 'youtube_url' => '', 'timezone' => $data['timezone'], 'about' => 'Bienvenido a mi blog.']);
                Setting::write('theme', 'solqubit');
                Setting::write('cms_version', config('solqubit.version'));
                if (($data['starter_content'] ?? 'empty') === 'sample') {
                    app(WelcomePages::class)->create();
                    if (! Post::withTrashed()->exists()) {
                        app(WelcomeSample::class)->create(User::where('email', $data['email'])->firstOrFail());
                    }
                }
            });
            $this->writeEnvironment([
                'APP_NAME' => $data['site_name'], 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => rtrim($data['site_url'], '/'),
                'APP_LOCALE' => 'es', 'APP_FALLBACK_LOCALE' => 'es', 'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $data['db_host'], 'DB_PORT' => $data['db_port'], 'DB_DATABASE' => $data['db_database'],
                'DB_USERNAME' => $data['db_username'], 'DB_PASSWORD' => $data['db_password'], 'DB_URL' => '',
                'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync',
                'SESSION_SECURE_COOKIE' => str_starts_with($data['site_url'], 'https://') ? 'true' : 'false',
                'SESSION_HTTP_ONLY' => 'true', 'SESSION_SAME_SITE' => 'lax',
            ]);
            if (file_put_contents(config('solqubit.installed_file'), json_encode(['version' => config('solqubit.version'), 'installed_at' => now()->toIso8601String()], JSON_PRETTY_PRINT), LOCK_EX) === false) {
                throw new \RuntimeException('No se pudo bloquear el instalador. Revisa los permisos y reanuda con los mismos datos.');
            }
            @chmod(config('solqubit.installed_file'), 0600);
            @unlink($pending);
            Artisan::call('config:clear');
            foreach (['install-core.php', '.solqubit-release.json'] as $preparationFile) {
                if (is_file(public_path($preparationFile))) {
                    @unlink(public_path($preparationFile));
                }
            }
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function writeEnvironment(array $values): void
    {
        $path = base_path('.env');
        $env = is_file($path) ? file_get_contents($path) : 'APP_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL;
        foreach ($values as $key => $value) {
            $encoded = '"'.str_replace(['\\', '"', '$', "\r", "\n"], ['\\\\', '\\"', '\\$', '', ''], (string) $value).'"';
            $line = $key.'='.$encoded;
            $env = preg_match('/^'.preg_quote($key, '/').'=/m', $env)
                ? preg_replace_callback('/^'.preg_quote($key, '/').'=.*$/m', fn () => $line, $env)
                : rtrim($env).PHP_EOL.$line.PHP_EOL;
        }
        $temporary = $path.'.installing';
        if (file_put_contents($temporary, $env, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo guardar la configuración privada.');
        }
        @chmod($temporary, 0600);
        if (! rename($temporary, $path)) {
            throw new \RuntimeException('No se pudo aplicar la configuración privada.');
        }
    }
}
