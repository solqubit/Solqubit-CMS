<?php

declare(strict_types=1);

/** This recovery entry point is intentionally excluded from ordinary update packages. */
function solqubit_update_target(string $relative, string $core, string $public): string
{
    if (! preg_match('~^(core|site)/[a-zA-Z0-9_./@+-]+$~', $relative) || preg_match('~(^|/)(\.|\.\.|)(/|$)~', $relative)) {
        throw new RuntimeException('Ruta de actualización no válida.');
    }
    [$root, $path] = explode('/', $relative, 2);
    $base = $root === 'core' ? $core : $public;
    $walk = $base;
    foreach (explode('/', $path) as $part) {
        $walk .= '/'.$part;
        if (is_link($walk)) {
            throw new RuntimeException('La actualización no puede atravesar enlaces simbólicos.');
        }
    }
    // When the engine lives inside the public folder, a public path must never reach into it.
    $engine = realpath($core);
    $existing = $base.'/'.$path;
    while (! file_exists($existing) && $existing !== dirname($existing)) {
        $existing = dirname($existing);
    }
    $resolved = realpath($existing);
    if ($root === 'site' && $engine !== false && $resolved !== false && ($resolved === $engine || str_starts_with($resolved.'/', rtrim($engine, '/').'/'))) {
        throw new RuntimeException('La actualización no puede escribir dentro del motor desde la carpeta pública.');
    }

    return $base.'/'.$path;
}

function solqubit_update_write(string $path, array $state): void
{
    $temp = $path.'.writing';
    if (file_put_contents($temp, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false || ! rename($temp, $path)) {
        throw new RuntimeException('No se pudo guardar el estado de actualización.');
    }
    @chmod($path, 0600);
}

function solqubit_update_replace(string $source, string $target): void
{
    if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true)) {
        throw new RuntimeException('No se pudo crear una carpeta del código.');
    }
    $temp = $target.'.solqubit-writing';
    if (is_link($temp) || ! copy($source, $temp) || ! chmod($temp, 0644) || ! rename($temp, $target)) {
        throw new RuntimeException('No se pudo reemplazar un archivo del código.');
    }
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($target, true);
    }
}

function solqubit_update_rollback(array $state, string $root, string $core, string $public): void
{
    foreach (array_reverse($state['written'] ?? [], true) as $relative => $existed) {
        $target = solqubit_update_target($relative, $core, $public);
        if ($existed) {
            $saved = $root.'/rollback/'.$relative;
            if (! is_file($saved)) {
                throw new RuntimeException('Falta un archivo para recuperar el código anterior.');
            }
            solqubit_update_replace($saved, $target);
        } elseif (is_file($target) && ! unlink($target)) {
            throw new RuntimeException('No se pudo retirar un archivo de la actualización interrumpida.');
        }
    }
}

function solqubit_update_unavailable(bool $retry = false): never
{
    http_response_code(503);
    header('Retry-After: 60');
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Actualización del sitio</title><body style="font:16px system-ui;max-width:680px;margin:12vh auto;padding:24px;line-height:1.7"><h1>Estamos actualizando el sitio.</h1><p>El contenido permanece guardado. Vuelve a intentarlo en un momento.</p>';
    if ($retry) {
        $token = htmlspecialchars($_COOKIE['solqubit_update_resume'] ?? '', ENT_QUOTES, 'UTF-8');
        echo '<p>La actualización quedó pendiente. Puedes reintentar la finalización. Si vuelve a fallar, revisa los registros del servidor; no ejecutes el instalador.</p><form method="post"><input type="hidden" name="solqubit_update_retry" value="'.$token.'"><button type="submit">Reintentar actualización</button></form>';
    }
    echo '</body></html>';
    exit;
}

function solqubit_update_can_resume(array $state, mixed $cookie): bool
{
    return is_string($cookie) && strlen($cookie) === 64
        && ($state['resume_expires'] ?? 0) >= time()
        && is_string($state['resume_hash'] ?? null)
        && hash_equals($state['resume_hash'], hash('sha256', $cookie));
}

/** @return array{lock: mixed, finish: bool} */
function solqubit_update_guard(string $core, string $public): array
{
    $root = $core.'/storage/app/updates';
    $journal = $root.'/transaction.json';
    if (! is_file($journal)) {
        return ['lock' => null, 'finish' => false];
    }
    $lock = fopen($root.'/update.lock', 'c');
    if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
        solqubit_update_unavailable();
    }
    try {
        $state = json_decode(file_get_contents($journal), true, 32, JSON_THROW_ON_ERROR);
        if (! in_array($state['phase'] ?? null, ['copying', 'migrating', 'failed'], true)) {
            throw new RuntimeException('Estado de actualización no válido.');
        }
        if ($state['phase'] === 'copying') {
            solqubit_update_rollback($state, $root, $core, $public);
            unlink($journal);
            flock($lock, LOCK_UN);
            fclose($lock);

            return ['lock' => null, 'finish' => false];
        }
        if ($state['phase'] === 'failed') {
            $cookie = $_COOKIE['solqubit_update_resume'] ?? '';
            $authorized = solqubit_update_can_resume($state, $cookie);
            if (! $authorized || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ! hash_equals($cookie, (string) ($_POST['solqubit_update_retry'] ?? ''))) {
                solqubit_update_unavailable($authorized);
            }
        }

        return ['lock' => $lock, 'finish' => true];
    } catch (Throwable $error) {
        error_log('Solqubit update recovery: '.$error->getMessage());
        solqubit_update_unavailable();
    }
}
