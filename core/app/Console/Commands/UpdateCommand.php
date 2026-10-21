<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Requirements;
use App\Services\ZipUpdater;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class UpdateCommand extends Command
{
    protected $signature = 'solqubit:update';

    protected $description = 'Aplica migraciones del código ya instalado sin recrear el contenido';

    public function handle(Requirements $requirements): int
    {
        $updater = app(ZipUpdater::class);
        if (is_file($updater->root().'/transaction.json')) {
            $lock = fopen($updater->root().'/update.lock', 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->error('Hay otra actualización en curso.');

                return self::FAILURE;
            }
            try {
                $state = json_decode(file_get_contents($updater->root().'/transaction.json'), true, 32, JSON_THROW_ON_ERROR);
                if (! in_array($state['phase'], ['migrating', 'failed'], true)) {
                    throw new \RuntimeException('La copia se interrumpió. Abre el sitio para recuperar primero el código anterior.');
                }
                $updater->finish();
                $this->info('Actualización ZIP completada.');

                return self::SUCCESS;
            } catch (\Throwable $error) {
                $this->error($error->getMessage());

                return self::FAILURE;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        if (! $requirements->passes()) {
            $this->error('Hay requisitos pendientes. Ejecuta solqubit:requirements.');

            return self::FAILURE;
        }
        if (! is_file(config('solqubit.installed_file'))) {
            $this->error('Completa primero la instalación web.');

            return self::FAILURE;
        }
        try {
            if (version_compare(config('solqubit.version'), Setting::read('cms_version', '0.0.0'), '<')) {
                throw new \RuntimeException('No se permiten regresiones de versión sobre una base de datos más reciente.');
            }
            Artisan::call('down', ['--retry' => 60]);
            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new \RuntimeException('La migración falló. Revisa los registros antes de continuar.');
            }
            Setting::write('cms_version', config('solqubit.version'));
            Artisan::call('optimize:clear');
            Artisan::call('up');
            $this->info('Actualización aplicada; entradas, archivos y tema conservados.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            $this->warn('El sitio permanece en mantenimiento; no se han borrado los datos para recrearlos.');

            return self::FAILURE;
        }
    }
}
