<?php

namespace App\Services;

/**
 * Reads and checks an update ZIP (format 3): ".solqubit-update.json" plus every file at its final path, "core/…" for
 * the private engine and "site/…" for the public folder. Nothing on the site changes until every file matches.
 */
class UpdatePackage
{
    public const MAX_BYTES = 134217728;

    public const MANIFEST = '.solqubit-update.json';

    private const MIN_SECONDS = 60;

    private const MIN_MEMORY = 128 * 1024 * 1024;

    /**
     * Whether an update may write this path. Decided by exclusion, so a newer release can add files in folders that
     * the installed version does not know yet; private data, configuration and the entry points never change.
     */
    public static function allowedPath(string $path): bool
    {
        if (strlen($path) > 240 || preg_match('~[^a-zA-Z0-9_./@+-]~', $path) || preg_match('~(^|/)(\.|\.\.|)(/|$)~', $path) || ! preg_match('~^(core|site)/.+~', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '.')) {
                return false;
            }
        }
        if (preg_match('~^core/(storage|bootstrap/cache)/~', $path) || preg_match('~^core/database/[^/]*\.sqlite~', $path)) {
            return false;
        }

        return ! in_array($path, ['core/bootstrap/public-path.php', 'core/bootstrap/hosting-protection.json', 'core/bootstrap/update-guard.php', 'site/index.php', 'site/install-core.php'], true);
    }

    /**
     * @return array<string, mixed> The manifest, with "unpacked_size".
     */
    public function inspect(string $path, string $currentVersion, ?string $destination = null): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new \RuntimeException('El ZIP no existe o supera los 128 MB.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('No es un archivo ZIP válido.');
        }
        try {
            if ($zip->numFiles > 40000) {
                throw new \RuntimeException('El paquete contiene demasiados archivos.');
            }
            if ($zip->locateName('install-core.php') !== false || $zip->locateName('.solqubit-release.json') !== false) {
                throw new \RuntimeException('Este es el ZIP de instalación. Para actualizar, usa el archivo solqubit-X.Y.Z-actualizacion.zip de la misma versión.');
            }
            $manifestStat = $zip->statName(self::MANIFEST);
            if (! $manifestStat || $manifestStat['size'] > 8 * 1024 ** 2) {
                throw new \RuntimeException('Este ZIP no es un paquete de actualización de Solqubit. Usa el archivo solqubit-X.Y.Z-actualizacion.zip de la release.');
            }
            $manifest = $this->manifest((string) $zip->getFromName(self::MANIFEST), $currentVersion);
            $this->checkServerLimits();

            return $this->files($zip, $manifest, $destination);
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, mixed> */
    private function manifest(string $raw, string $currentVersion): array
    {
        try {
            $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('El manifiesto del paquete está dañado.');
        }
        if (! is_array($manifest) || ($manifest['product'] ?? '') !== 'solqubit-cms' || ($manifest['format'] ?? null) !== 3 || ! is_array($manifest['files'] ?? null) || ! $manifest['files']) {
            throw new \RuntimeException('El paquete no tiene el formato de actualización de esta versión de Solqubit.');
        }
        foreach (['version', 'min_version', 'php'] as $field) {
            if (! is_string($manifest[$field] ?? null) || ! preg_match('/^\d+\.\d+\.\d+$/', $manifest[$field])) {
                throw new \RuntimeException('El paquete contiene una versión no válida.');
            }
        }
        if (version_compare($manifest['version'], $currentVersion, '<=') || version_compare($currentVersion, $manifest['min_version'], '<')) {
            throw new \RuntimeException('La versión debe ser más reciente y compatible con la instalada ('.$currentVersion.'). Este paquete es la '.$manifest['version'].' y necesita al menos la '.$manifest['min_version'].'. No se permiten reinstalaciones ni regresiones.');
        }
        if (version_compare(PHP_VERSION, $manifest['php'], '<')) {
            throw new \RuntimeException('Este paquete necesita PHP '.$manifest['php'].' o superior. Tu servidor tiene PHP '.PHP_VERSION.'.');
        }
        foreach ($manifest['extensions'] ?? [] as $extension) {
            if (! is_string($extension) || ! extension_loaded($extension)) {
                throw new \RuntimeException('Falta la extensión PHP '.(is_string($extension) ? $extension : '').' que necesita el paquete. Actívala desde tu hosting.');
            }
        }
        if (! is_array($manifest['remove'] ?? []) || ! is_array($manifest['changes'] ?? [])) {
            throw new \RuntimeException('La lista de cambios no es válida.');
        }
        foreach ($manifest['remove'] ?? [] as $relative) {
            if (! is_string($relative) || ! self::allowedPath($relative) || isset($manifest['files'][$relative]) || str_starts_with($relative, 'core/database/migrations/')) {
                throw new \RuntimeException('El paquete intenta eliminar un archivo protegido.');
            }
        }

        return $manifest;
    }

    /** Refuses before anything changes when the server cannot give the update enough time or memory. */
    private function checkServerLimits(): void
    {
        $limits = config('solqubit.update_limits') ?? [
            'time' => (int) ini_get('max_execution_time'),
            'memory' => $this->bytes((string) ini_get('memory_limit')),
            'can_extend_time' => function_exists('set_time_limit') && ! in_array('set_time_limit', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true),
        ];
        if ($limits['time'] > 0 && $limits['time'] < self::MIN_SECONDS && ! $limits['can_extend_time']) {
            throw new \RuntimeException('El servidor permite solo '.$limits['time'].' segundos por petición y no se puede ampliar. Pide a tu hosting al menos 60 segundos (max_execution_time) antes de actualizar.');
        }
        if ($limits['memory'] !== -1 && $limits['memory'] < self::MIN_MEMORY) {
            throw new \RuntimeException('PHP tiene '.(int) round($limits['memory'] / 1024 ** 2).' MB de memoria. Se necesitan al menos 128 MB (memory_limit) para actualizar.');
        }
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Checks every entry against the manifest and, with a destination, extracts them there.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function files(\ZipArchive $zip, array $manifest, ?string $destination): array
    {
        $total = 0;
        $seen = [];
        $payload = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            $name = $entry['name'];
            $folded = strtolower($name);
            $zip->getExternalAttributesIndex($i, $system, $attributes);
            $type = ($attributes >> 16) & 0170000;
            if (isset($seen[$folded]) || str_ends_with($name, '/') || ! in_array($type, [0, 0100000], true) || ($entry['encryption_method'] ?? 0) !== 0) {
                throw new \RuntimeException('No se aceptan rutas duplicadas, carpetas, enlaces ni archivos cifrados en el ZIP.');
            }
            $seen[$folded] = true;
            $total += $entry['size'];
            if ($entry['size'] > 64 * 1024 ** 2 || $total > 768 * 1024 ** 2) {
                throw new \RuntimeException('El contenido descomprimido supera el límite permitido.');
            }
            if ($name === self::MANIFEST) {
                continue;
            }
            $expected = $manifest['files'][$name] ?? null;
            if (! self::allowedPath($name) || ! is_array($expected) || ($expected['size'] ?? null) !== $entry['size'] || ! preg_match('/^[a-f0-9]{64}$/', $expected['sha256'] ?? '')) {
                throw new \RuntimeException('El ZIP incluye archivos no permitidos o que no coinciden con el manifiesto: '.$name.'.');
            }
            $stream = $zip->getStream($name);
            if (! $stream) {
                throw new \RuntimeException('No se pudo leer un archivo del ZIP.');
            }
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $stream, $entry['size'] + 1);
            fclose($stream);
            if ($bytes !== $entry['size'] || ! hash_equals($expected['sha256'], hash_final($hash))) {
                throw new \RuntimeException('El paquete está incompleto o dañado. No se ha cambiado el sitio.');
            }
            $payload[] = $name;
        }
        if (count($payload) !== count($manifest['files'])) {
            throw new \RuntimeException('Faltan archivos declarados en el paquete.');
        }
        if ($destination) {
            if (! is_dir($destination) && ! mkdir($destination, 0700, true)) {
                throw new \RuntimeException('No se pudo preparar la carpeta privada de actualización.');
            }
            foreach ($payload as $name) {
                $target = $destination.'/'.$name;
                if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0700, true)) {
                    throw new \RuntimeException('No se pudo preparar la carpeta privada de actualización.');
                }
                $input = $zip->getStream($name);
                $output = fopen($target, 'xb');
                if (! $input || ! $output) {
                    throw new \RuntimeException('No se pudo preparar un archivo de la actualización.');
                }
                try {
                    $written = stream_copy_to_stream($input, $output, $manifest['files'][$name]['size'] + 1);
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($written !== $manifest['files'][$name]['size'] || ! hash_equals($manifest['files'][$name]['sha256'], hash_file('sha256', $target))) {
                    throw new \RuntimeException('No se pudo extraer íntegramente el paquete.');
                }
            }
        }
        $manifest['unpacked_size'] = $total;

        return $manifest;
    }
}
