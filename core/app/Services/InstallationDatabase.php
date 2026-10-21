<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InstallationDatabase
{
    /**
     * Connect with the submitted details. "localhost" falls back to 127.0.0.1 when PHP cannot find
     * the MySQL socket, which is common on CloudPanel and VPS panels.
     *
     * @return array{resuming: bool, host: string}
     */
    public function check(array $data): array
    {
        $hosts = strtolower($data['db_host']) === 'localhost' ? [$data['db_host'], '127.0.0.1'] : [$data['db_host']];
        $mysql = config('database.connections.mysql');
        foreach ($hosts as $attempt => $host) {
            $connection = array_merge($mysql, [
                'host' => $host, 'port' => $data['db_port'], 'database' => $data['db_database'],
                'username' => $data['db_username'], 'password' => $data['db_password'], 'url' => null,
                'options' => array_replace($mysql['options'] ?? [], [\PDO::ATTR_TIMEOUT => 5]),
            ]);
            config(['database.default' => 'mysql', 'database.connections.mysql' => $connection]);
            DB::purge('mysql');
            try {
                $version = DB::selectOne('select version() as version')->version;
                $hasTables = count(Schema::getTables()) > 0;
                $data['db_host'] = $host;
                break;
            } catch (\Throwable $error) {
                if ($attempt === count($hosts) - 1) {
                    throw ValidationException::withMessages(['db_host' => 'No se pudo conectar. Comprueba servidor, puerto, nombre, usuario, contraseña y permisos de la base de datos. En cPanel, la base y el usuario llevan el prefijo de tu cuenta (por ejemplo, cuenta_blog).']);
                }
            }
        }
        $maria = str_contains(strtolower($version), 'mariadb');
        if (version_compare($version, $maria ? '10.11' : '8.0', '<')) {
            throw ValidationException::withMessages(['db_host' => 'Se requiere MariaDB 10.11+ o MySQL 8.0+.']);
        }
        $pending = storage_path('app/install-pending');
        $resuming = is_file($pending) && hash_equals(trim(file_get_contents($pending)), $this->identity($data));
        if ($hasTables && ! $resuming) {
            throw ValidationException::withMessages(['db_database' => 'Selecciona una base de datos vacía. La instalación no sobrescribe tablas existentes.']);
        }

        return ['resuming' => $hasTables && $resuming, 'host' => $data['db_host']];
    }

    public function identity(array $data): string
    {
        return hash('sha256', implode('|', [$data['db_host'], $data['db_port'], $data['db_database'], $data['db_username']]));
    }
}
