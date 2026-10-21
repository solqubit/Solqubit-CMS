<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the session and XSRF cookies Secure for requests that arrived over HTTPS, unless
 * SESSION_SECURE_COOKIE=true already forces it for every request.
 */
class SecureSessionCookie
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('session.secure') !== true && $request->secure()) {
            config(['session.secure' => true]);
        }

        return $next($request);
    }
}
