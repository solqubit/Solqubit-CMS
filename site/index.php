<?php

declare(strict_types=1);
use App\Services\ZipUpdater;
use App\Services\HostingLayout;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));
$pointer = __DIR__.'/.solqubit-path.php';
$core = is_file($pointer) ? require $pointer : dirname(__DIR__).'/core';
$core = is_string($core) ? realpath($core) : false;
$public = realpath(__DIR__);
if (! is_file($pointer) && is_file(__DIR__.'/install-core.php')) {
    header('Location: install-core.php', true, 302);
    exit;
}
if (! $core || ! is_file($core.'/app/Services/HostingLayout.php')) {
    http_response_code(503);
    exit('Solqubit: no se encuentra el motor del CMS. Revisa los archivos de la instalación.');
}
require_once $core.'/app/Services/HostingLayout.php';
if (! HostingLayout::isProtected($core, $public)) {
    http_response_code(503);
    exit('Solqubit: falta la comprobación de protección de los archivos internos. Revisa la instalación.');
}
require_once $core.'/bootstrap/update-guard.php';
$update = solqubit_update_guard($core, $public);
try {
    if (file_exists($maintenance = $core.'/storage/framework/maintenance.php')) {
        require $maintenance;
    }
    require $core.'/vendor/autoload.php';
    $app = require_once $core.'/bootstrap/app.php';
    $app->usePublicPath(__DIR__);
    if ($update['finish']) {
        $app->make(Kernel::class)->bootstrap();
        $app->make(ZipUpdater::class)->finish();
        flock($update['lock'], LOCK_UN);
        fclose($update['lock']);
        $location = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
        if (! str_starts_with($location, '/') || preg_match('~^/[/\\\\]~', $location)) {
            $location = '/';
        }
        header('Location: '.$location, true, 303);
        exit;
    }
    $app->handleRequest(Request::capture());
} catch (Throwable $error) {
    if (! $update['finish']) {
        throw $error;
    }
    $journal = $core.'/storage/app/updates/transaction.json';
    $state = json_decode(file_get_contents($journal), true);
    $state['phase'] = 'failed';
    solqubit_update_write($journal, $state);
    error_log('Solqubit update finalization: '.$error->getMessage());
    $cookie = $_COOKIE['solqubit_update_resume'] ?? '';
    solqubit_update_unavailable(solqubit_update_can_resume($state, $cookie));
}
