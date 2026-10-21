<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class UseConfiguredAddress
{
    /**
     * Build every absolute URL from APP_URL once the site is installed, so a forged Host header
     * cannot leak into password reset links, canonical tags, feeds or sitemaps. A request that
     * really arrived over HTTPS keeps HTTPS even when the site was installed over HTTP.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $address = rtrim((string) config('app.url'), '/');
        $parts = parse_url($address);
        if (is_file(config('solqubit.installed_file')) && filter_var($address, FILTER_VALIDATE_URL)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && filled($parts['host'] ?? null)) {
            $scheme = $request->secure() ? 'https' : $parts['scheme'];
            $port = isset($parts['port']) && $scheme === $parts['scheme'] ? ':'.$parts['port'] : '';
            URL::forceRootUrl($scheme.'://'.$parts['host'].$port.rtrim($parts['path'] ?? '', '/'));
            URL::forceScheme($scheme);
        }

        return $next($request);
    }
}
