<?php

namespace App\Http\Middleware;

use App\Services\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectAdminAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (AdminAccess::isProtectedRequest($request)) {
            abort_if(app(AdminAccess::class)->blocked($request->ip()), 403);
        }

        return $next($request);
    }
}
