<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShareablePublicResponses
{
    /**
     * Drop session and XSRF cookies from responses marked as publicly cacheable, so browsers,
     * proxies and CDNs can store them and no visitor's cookie is shared through a cache.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response->headers->hasCacheControlDirective('public')) {
            foreach ($response->headers->getCookies() as $cookie) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        return $response;
    }
}
