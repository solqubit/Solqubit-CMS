<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectTrailingSlash
{
    /**
     * Give each public page one address: "/acerca/" answers with a 301 to "/acerca". Laravel matches both
     * forms, and nginx panels have no .htaccess rule. The path is relative to the installation, so
     * subdirectory installs keep their base path, and the installation root ("/") is never touched.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();
        if ($path !== '/' && str_ends_with($path, '/') && $request->isMethodSafe()) {
            $query = $request->getQueryString();

            return redirect()->to(url(rtrim($path, '/') ?: '/').($query !== null ? '?'.$query : ''), 301);
        }

        return $next($request);
    }
}
