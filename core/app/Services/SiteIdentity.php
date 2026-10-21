<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Setting;

class SiteIdentity
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** @return array{brandImage: ?Media, faviconUrl: string} */
    public function assets(array $site): array
    {
        $brandId = $this->imageId($site['brand_image_id'] ?? null);
        $faviconId = $this->imageId($site['favicon_id'] ?? null);
        $ids = array_filter([$brandId, $faviconId]);
        $images = $ids ? Media::whereIn('id', $ids)->whereIn('mime_type', self::IMAGE_MIMES)->get()->keyBy('id') : collect();

        return [
            'brandImage' => ($site['brand_icon'] ?? '') === 'custom' ? $images->get($brandId) : null,
            'faviconUrl' => $images->get($faviconId)?->url() ?? asset('themes/solqubit/assets/favicon.svg'),
        ];
    }

    public function faviconUrl(): string
    {
        $site = is_file(config('solqubit.installed_file')) ? Setting::read('site', []) : [];

        return $this->assets($site)['faviconUrl'];
    }

    public function usesMedia(Media $media, bool $publicOnly = false): bool
    {
        if (! in_array($media->mime_type, self::IMAGE_MIMES, true)) {
            return false;
        }

        $site = Setting::read('site', []);

        return $this->imageId($site['favicon_id'] ?? null) === $media->id
            || ($this->imageId($site['brand_image_id'] ?? null) === $media->id && (! $publicOnly || ($site['brand_icon'] ?? '') === 'custom'))
            || $this->imageId((Setting::read('seo', []) ?: [])['default_image_id'] ?? null) === $media->id;
    }

    private function imageId(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }
}
