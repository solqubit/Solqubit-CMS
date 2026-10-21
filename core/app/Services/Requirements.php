<?php

namespace App\Services;

class Requirements
{
    public function check(): array
    {
        $checks = [['name' => 'PHP 8.3 o superior', 'ok' => version_compare(PHP_VERSION, '8.3.0', '>='), 'detail' => PHP_VERSION]];
        foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'intl', 'zip', 'gd', 'bcmath'] as $extension) {
            $checks[] = ['name' => 'Extensión '.$extension, 'ok' => extension_loaded($extension), 'detail' => extension_loaded($extension) ? 'Disponible' : 'Solicita su activación al alojamiento'];
        }
        foreach ([storage_path('app') => 'Archivos del blog', storage_path('framework') => 'Sesiones y vistas', storage_path('logs') => 'Registros', base_path('bootstrap/cache') => 'Caché del sistema', base_path() => 'Configuración del sitio'] as $path => $label) {
            $checks[] = ['name' => $label, 'ok' => is_dir($path) && is_writable($path), 'detail' => is_dir($path) && is_writable($path) ? 'Escritura disponible' : 'Revisa el propietario y los permisos de '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)];
        }
        $protected = HostingLayout::isProtected(base_path(), public_path());
        $checks[] = ['name' => 'Protección de los archivos internos', 'ok' => $protected, 'detail' => $protected ? 'Ubicación del motor verificada' : 'Completa la preparación y comprueba las reglas de protección del servidor'];
        $checks[] = ['name' => 'Memoria PHP: 128 MB o superior', 'ok' => $this->bytes(ini_get('memory_limit')) >= 128 * 1024 ** 2, 'detail' => ini_get('memory_limit')];

        return $checks;
    }

    public function passes(): bool
    {
        return ! in_array(false, array_column($this->check(), 'ok'), true);
    }

    private function bytes(string $value): int
    {
        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        return (int) $value * match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1
        };
    }
}
