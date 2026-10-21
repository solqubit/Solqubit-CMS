<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Media;
use App\Models\Series;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Decides which library files a user may browse and attach.
 *
 * Administrators see the whole library. Editors see their own uploads plus files that are
 * already public on the site (the same rules as {@see Media::isPublic()}), so they can reuse
 * shared images without browsing other authors' unpublished material.
 */
class MediaAccess
{
    /**
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function visibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->isAdmin()) {
            return $query;
        }
        if (! $user?->is_active || $user->role !== 'editor') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($user): void {
            $publishedPosts = fn (Builder $posts): Builder => $posts->published();
            $query->where('user_id', $user->getKey())
                ->orWhereHas('posts', $publishedPosts)
                ->orWhereHas('pages', fn (Builder $pages): Builder => $pages->published())
                ->orWhereIn('id', User::query()->whereNotNull('avatar_id')->whereHas('posts', $publishedPosts)->select('avatar_id'))
                ->orWhereIn('id', Category::query()->whereNotNull('cover_id')->whereHas('posts', $publishedPosts)->select('cover_id'))
                ->orWhereIn('id', Series::query()->whereNotNull('cover_id')->whereHas('posts', $publishedPosts)->select('cover_id'))
                ->orWhere(fn (Builder $identity): Builder => $identity->whereIn('id', $this->identityImageIds())->whereIn('mime_type', SiteIdentity::IMAGE_MIMES));
        });
    }

    public function canUse(?User $user, mixed $id): bool
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id !== false && $this->visibleTo(Media::query(), $user)->whereKey($id)->exists();
    }

    /**
     * Reject files the user may not attach, except those the record already uses.
     *
     * @param  array<int, mixed>  $ids
     *
     * @throws ValidationException
     */
    public function ensureUsable(?User $user, array $ids, ?Model $record, string $field): void
    {
        $kept = $this->attachedIds($record);
        foreach (array_unique(array_filter($ids, fn (mixed $id): bool => filled($id))) as $id) {
            if (! in_array((int) $id, $kept, true) && ! $this->canUse($user, $id)) {
                throw ValidationException::withMessages([$field => 'Elige un archivo propio o uno que ya esté publicado en el sitio.']);
            }
        }
    }

    /**
     * Files the record already references, so editing it never fails over earlier choices.
     *
     * @return array<int, int>
     */
    public function attachedIds(?Model $record): array
    {
        if (! $record?->exists) {
            return [];
        }
        $ids = method_exists($record, 'media') ? $record->media()->pluck('media.id')->all() : [];
        foreach (['cover_id', 'avatar_id'] as $attribute) {
            if (filled($record->getAttribute($attribute))) {
                $ids[] = $record->getAttribute($attribute);
            }
        }

        return array_map('intval', $ids);
    }

    /**
     * @return array<int, int>
     */
    private function identityImageIds(): array
    {
        $site = Setting::read('site', []);
        $ids = [$site['favicon_id'] ?? null];
        if (($site['brand_icon'] ?? '') === 'custom') {
            $ids[] = $site['brand_image_id'] ?? null;
        }

        return array_values(array_filter(array_map(fn (mixed $id): int|false => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), $ids)));
    }
}
