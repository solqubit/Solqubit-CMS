<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SocialLinks
{
    public const NETWORKS = ['youtube' => 'YouTube', 'instagram' => 'Instagram', 'github' => 'GitHub', 'linkedin' => 'LinkedIn', 'facebook' => 'Facebook', 'x' => 'X', 'tiktok' => 'TikTok'];

    private const HOSTS = ['youtube' => ['youtube.com'], 'instagram' => ['instagram.com'], 'github' => ['github.com'], 'linkedin' => ['linkedin.com'], 'facebook' => ['facebook.com'], 'x' => ['x.com', 'twitter.com'], 'tiktok' => ['tiktok.com']];

    public function configured(?array $site = null): array
    {
        $site ??= Setting::read('site', []);
        $links = $site['social_links'] ?? (filled($site['youtube_url'] ?? '') ? [['network' => 'youtube', 'url' => $site['youtube_url']]] : []);

        return array_values(array_slice(array_filter($links, fn ($link): bool => is_array($link) && $this->validUrl($link['network'] ?? '', $link['url'] ?? '')), 0, 3));
    }

    public function validUrl(mixed $network, mixed $url): bool
    {
        if (! is_string($network) || ! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true) || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS)) {
            return false;
        }
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (! in_array(preg_replace('/^(www\.|m\.)/', '', $host), self::HOSTS[$network] ?? [], true)) {
            return false;
        }
        $path = trim(parse_url($url, PHP_URL_PATH) ?? '', '/');

        return $path !== '' && ($network !== 'youtube' || preg_match('~^(?:@[^/]+|(?:channel|c|user)/[^/]+)/?$~', $path));
    }

    public function validate(array $links): array
    {
        Validator::make(['data' => ['social_links' => $links]], [
            'data.social_links' => ['array', 'max:3'],
            'data.social_links.*.network' => ['required', Rule::in(array_keys(self::NETWORKS)), 'distinct'],
            'data.social_links.*.url' => ['required', 'string', 'max:2048'],
        ])->after(function ($validator) use ($links): void {
            foreach ($links as $key => $link) {
                if (! $this->validUrl($link['network'] ?? '', $link['url'] ?? '')) {
                    $validator->errors()->add('data.social_links.'.$key.'.url', 'Introduce el enlace completo a tu perfil en esta red. En YouTube usa el canal, por ejemplo https://www.youtube.com/@tucanal.');
                }
            }
        })->validate();

        return array_values(array_map(fn ($link): array => ['network' => $link['network'], 'url' => $link['url']], $links));
    }
}
