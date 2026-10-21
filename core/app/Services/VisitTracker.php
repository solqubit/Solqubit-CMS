<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Records one public page view sent by visitas.js. No cookie and no IP is stored: the visitor is a
 * 16-character hash of IP and browser with a salt that changes every day and is then forgotten.
 */
class VisitTracker
{
    /**
     * Public routes whose pages are counted.
     */
    public const COUNTED_ROUTES = ['home', 'series', 'about', 'pages.show', 'posts.show', 'pages.legacy'];

    public const BOT_PATTERN = '/bot|crawl|spider|slurp|scrap|fetch|preview|headless|lighthouse|pagespeed|pingdom|uptime|monitor|curl|wget|python|java\/|go-http|okhttp|axios|libwww|httpclient|phantom|selenium|puppeteer|playwright|facebookexternalhit|embedly|validator/i';

    private const PER_MINUTE = 60;

    /**
     * Known sources grouped under one name, so google.com and google.es count together.
     *
     * @var array<string, string>
     */
    private const SOURCES = [
        '/(^|\.)google\.|googlequicksearchbox/' => 'Google', '/(^|\.)bing\.com$/' => 'Bing', '/(^|\.)duckduckgo\.com$/' => 'DuckDuckGo',
        '/(^|\.)yahoo\./' => 'Yahoo', '/(^|\.)yandex\./' => 'Yandex', '/(^|\.)ecosia\.org$/' => 'Ecosia', '/(^|\.)search\.brave\.com$/' => 'Brave Search',
        '/(^|\.)facebook\.com$|^fb\.me$/' => 'Facebook', '/(^|\.)instagram\.com$/' => 'Instagram', '/^t\.co$|(^|\.)twitter\.com$|(^|\.)x\.com$/' => 'X (Twitter)',
        '/(^|\.)linkedin\.com$|^lnkd\.in$/' => 'LinkedIn', '/(^|\.)reddit\.com$/' => 'Reddit', '/(^|\.)youtube\.com$|^youtu\.be$/' => 'YouTube',
        '/(^|\.)tiktok\.com$/' => 'TikTok', '/(^|\.)pinterest\./' => 'Pinterest', '/(^|\.)whatsapp\.com$|^wa\.me$/' => 'WhatsApp',
        '/(^|\.)telegram\.org$|^t\.me$/' => 'Telegram', '/(^|\.)github\.com$/' => 'GitHub', '/(^|\.)chatgpt\.com$|^chat\.openai\.com$/' => 'ChatGPT',
        '/(^|\.)perplexity\.ai$/' => 'Perplexity', '/(^|\.)claude\.ai$/' => 'Claude', '/(^|\.)gemini\.google\.com$/' => 'Gemini',
    ];

    public function __construct(private CountryDatabase $countries) {}

    /**
     * @return array{enabled: bool, returning: bool}
     */
    public function settings(): array
    {
        $saved = Setting::read('visits', []);

        return ['enabled' => (bool) ($saved['enabled'] ?? true), 'returning' => (bool) ($saved['returning'] ?? true)];
    }

    public function day(?Carbon $moment = null): string
    {
        return ($moment ?? now())->copy()->setTimezone(Setting::read('site', [])['timezone'] ?? 'UTC')->toDateString();
    }

    /**
     * Store the view if it looks like a real person on a counted public page. Returns whether it was stored.
     */
    public function record(Request $request): bool
    {
        $agent = mb_substr((string) $request->userAgent(), 0, 500);
        if (! $this->settings()['enabled'] || $agent === '' || preg_match(self::BOT_PATTERN, $agent)) {
            return false;
        }
        $ip = $this->clientIp($request);
        if (! RateLimiter::attempt('solqubit-visit:'.sha1($ip), self::PER_MINUTE, fn (): bool => true)) {
            return false;
        }
        $path = $this->path((string) $request->input('p', ''), $request->getBasePath());
        if ($path === null) {
            return false;
        }
        $day = $this->day();
        DB::table('visits')->insert([
            'day' => $day,
            'path' => $path,
            'visitor' => substr(hash_hmac('sha256', $ip.'|'.$agent, $this->salt($day)), 0, 16),
            'is_returning' => $this->settings()['returning'] && $request->input('n') === '1',
            'country' => $this->country($request, $ip) ?? '',
            'source' => $this->source($request) ?? '',
            'device' => $this->device($agent),
            'browser' => $this->browser($agent),
            'os' => $this->system($agent),
            'created_at' => now(),
        ]);
        if (random_int(1, 50) === 1) {
            app(VisitStatistics::class)->compact();
        }

        return true;
    }

    /**
     * Normalise the reported path (without the folder the site is installed in, if any) and accept it
     * only if it is a counted public route whose post or page exists, so forged requests cannot fill the lists.
     */
    public function path(string $path, string $basePath = ''): ?string
    {
        $path = rawurldecode($path);
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath.'/'))) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        if ($path === '' || $path[0] !== '/' || mb_strlen($path) > 255 || ! mb_check_encoding($path, 'UTF-8') || preg_match('/[\x00-\x1F\x7F?#]|\/\.\.?(\/|$)|\/\//', $path)) {
            return null;
        }
        $path = $path === '/' ? '/' : rtrim($path, '/');
        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (HttpException) {
            return null;
        }

        $exists = match ($route->getName()) {
            'posts.show' => DB::table('post_paths')->where('path', $route->parameter('path'))->exists(),
            'pages.show', 'pages.legacy' => DB::table('post_paths')->where('path', $route->parameter('slug'))->exists()
                || Page::where('kind', 'custom')->published()->where('slug', $route->parameter('slug'))->exists(),
            default => in_array($route->getName(), self::COUNTED_ROUTES, true),
        };

        return $exists ? $path : null;
    }

    public function device(string $agent): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet|Kindle|Silk|PlayBook/i', $agent), str_contains($agent, 'Android') && ! str_contains($agent, 'Mobile') => 'tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android|Windows Phone/i', $agent) => 'mobile',
            default => 'desktop',
        };
    }

    public function browser(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Edg') => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $agent) => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            (bool) preg_match('/Firefox\/|FxiOS/', $agent) => 'Firefox',
            (bool) preg_match('/Chrome\/|CriOS/', $agent) => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Otro',
        };
    }

    public function system(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            (bool) preg_match('/iPhone|iPad|iPod/', $agent) => 'iOS',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Otro',
        };
    }

    /**
     * Where the visitor came from: the campaign (utm_source) if any, else the referring site.
     * Links between pages of this same site are not a source.
     */
    public function source(Request $request): ?string
    {
        $campaign = trim(mb_strtolower(mb_substr((string) $request->input('u', ''), 0, 60)));
        if ($campaign !== '' && preg_match('/^[\p{L}\p{N} ._\-]+$/u', $campaign)) {
            return $this->sourceName($campaign) ?? $campaign;
        }
        $referrer = (string) $request->input('r', '');
        $host = mb_strtolower((string) parse_url($referrer, PHP_URL_HOST));
        if (str_starts_with($referrer, 'android-app://')) {
            $host = mb_strtolower((string) parse_url(str_replace('android-app://', 'https://', $referrer), PHP_URL_HOST));
        }
        $host = preg_replace('/^www\./', '', $host);
        if ($host === '' || ! preg_match('/^[a-z0-9.\-]{1,80}$/', $host) || $host === preg_replace('/^www\./', '', mb_strtolower($request->getHost()))) {
            return null;
        }

        return $this->sourceName($host) ?? $host;
    }

    private function sourceName(string $host): ?string
    {
        foreach (self::SOURCES as $pattern => $name) {
            if (preg_match($pattern, $host) || mb_strtolower($name) === $host) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Country from Cloudflare's header when present, else from the local DB-IP list.
     */
    private function country(Request $request, string $ip): ?string
    {
        $header = strtoupper((string) $request->header('CF-IPCountry', ''));
        if (preg_match('/^[A-Z]{2}$/', $header)) {
            return in_array($header, ['XX', 'T1', 'ZZ'], true) ? null : $header;
        }

        return $this->countries->country($ip);
    }

    /**
     * Behind Cloudflare every request comes from its servers, so the visitor's address is in its header.
     */
    private function clientIp(Request $request): string
    {
        $forwarded = (string) $request->header('CF-Connecting-IP', '');

        return filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : (string) $request->ip();
    }

    /**
     * Today's random salt. A new day replaces it, so yesterday's hashes can never be linked to an address.
     */
    private function salt(string $day): string
    {
        $saved = Setting::read('visits_salt', []);
        if (($saved['day'] ?? null) === $day && is_string($saved['value'] ?? null)) {
            return $saved['value'];
        }

        return Cache::lock('solqubit-visit-salt', 5)->block(5, function () use ($day): string {
            $saved = Setting::query()->find('visits_salt')?->value ?? [];
            if (($saved['day'] ?? null) === $day && is_string($saved['value'] ?? null)) {
                return $saved['value'];
            }
            $salt = bin2hex(random_bytes(32));
            Setting::write('visits_salt', ['day' => $day, 'value' => $salt]);

            return $salt;
        });
    }
}
