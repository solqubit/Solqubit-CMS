<?php

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\ProtectAdminAccess;
use App\Http\Middleware\SecureSessionCookie;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ShareablePublicResponses;
use App\Http\Middleware\UseConfiguredAddress;
use App\Services\ThemeErrorPage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['solqubit_update_resume']);
        // After TrustProxies, so HTTPS behind a trusted proxy is detected.
        $middleware->append(UseConfiguredAddress::class);
        $middleware->append(SecureSessionCookie::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(ShareablePublicResponses::class);
        $middleware->append(ProtectAdminAccess::class);
        $middleware->web(append: [EnsureInstalled::class]);
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'db_password', 'install_token']);
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (config('app.debug') || ! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json(['message' => 'No encontramos lo que buscas.'], 404);
        });
        $exceptions->render(fn (HttpExceptionInterface $exception, Request $request) => app(ThemeErrorPage::class)->render($request, $exception->getStatusCode()));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

$publicPointer = __DIR__.'/public-path.php';
$app->usePublicPath(is_file($publicPointer) ? require $publicPointer : dirname(__DIR__, 2).'/site');

return $app;
