<?php

namespace App\Http\Middleware;

use App\Services\AdminAccess;
use App\Services\Monetization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Policy kept for the panel, Livewire, previews and the installer, which need inline scripts and Alpine.
     */
    public const MINIMAL_POLICY = "base-uri 'self'; object-src 'none'; frame-ancestors 'self'";

    /**
     * Google AdSense hosts allowed only while ads are active.
     *
     * @var array{script: array<int, string>, frame: array<int, string>, connect: array<int, string>}
     */
    public const ADSENSE_HOSTS = [
        'script' => [
            'https://pagead2.googlesyndication.com', 'https://tpc.googlesyndication.com', 'https://partner.googleadservices.com',
            'https://www.googletagservices.com', 'https://adservice.google.com', 'https://fundingchoicesmessages.google.com',
            'https://*.adtrafficquality.google', 'https://www.google.com', 'https://www.gstatic.com',
        ],
        'frame' => [
            'https://googleads.g.doubleclick.net', 'https://tpc.googlesyndication.com', 'https://*.googlesyndication.com',
            'https://www.google.com', 'https://fundingchoicesmessages.google.com', 'https://*.adtrafficquality.google',
        ],
        'connect' => [
            'https://pagead2.googlesyndication.com', 'https://googleads.g.doubleclick.net', 'https://adservice.google.com',
            'https://fundingchoicesmessages.google.com', 'https://*.adtrafficquality.google', 'https://csi.gstatic.com',
        ],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private static array $themeScriptHashes = [];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $isPrivate = AdminAccess::isProtectedRequest($request) || $request->is('install*');
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $isPrivate ? self::MINIMAL_POLICY : $this->publicPolicy());
        }
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($isPrivate) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * Strict policy for the public blog: only local scripts, the theme's own inline scripts by hash
     * and, when ads are active, the AdSense hosts.
     */
    public function publicPolicy(): string
    {
        $ads = $this->adsActive();
        $script = ["'self'", ...array_map(fn (string $hash): string => "'".$hash."'", $this->themeScriptHashes())];
        $frame = ["'self'", 'https://www.youtube-nocookie.com'];
        $connect = ["'self'"];
        if ($ads) {
            $script = [...$script, ...self::ADSENSE_HOSTS['script']];
            $frame = [...$frame, ...self::ADSENSE_HOSTS['frame']];
            $connect = [...$connect, ...self::ADSENSE_HOSTS['connect']];
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "media-src 'self'",
            'frame-src '.implode(' ', $frame),
            'connect-src '.implode(' ', $connect),
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
        ]);
    }

    /**
     * Hashes of the static inline scripts in every theme layout, read from the template itself so
     * a changed script is allowed again without editing this policy. Scripts with Blade output are
     * skipped: their content is not known ahead of the request and they must not be allowed.
     *
     * @return array<int, string>
     */
    public function themeScriptHashes(): array
    {
        $hashes = [];
        foreach (glob(resource_path('views/themes/*/layout.blade.php')) ?: [] as $layout) {
            $cacheKey = $layout.'@'.filemtime($layout);
            if (! isset(self::$themeScriptHashes[$cacheKey])) {
                preg_match_all('~<script>(.*?)</script>~s', (string) file_get_contents($layout), $matches);
                self::$themeScriptHashes[$cacheKey] = array_values(array_map(
                    fn (string $script): string => 'sha256-'.base64_encode(hash('sha256', $script, true)),
                    array_filter($matches[1], fn (string $script): bool => ! preg_match('/\{\{|\{!!|@[a-z]/i', $script)),
                ));
            }
            $hashes = [...$hashes, ...self::$themeScriptHashes[$cacheKey]];
        }

        return array_values(array_unique($hashes));
    }

    private function adsActive(): bool
    {
        if (! is_file(config('solqubit.installed_file'))) {
            return false;
        }
        try {
            return (app(Monetization::class)->forPage(true)['enabled'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
