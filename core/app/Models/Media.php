<?php

namespace App\Models;

use App\Filament;
use App\Services\ContentDocument;
use App\Services\ContentSchema;
use App\Services\ImageVariants;
use App\Services\SiteIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class Media extends Model
{
    protected $table = 'media';

    /**
     * Bitmap formats whose dimensions can be read with getimagesize().
     */
    public const RASTER_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Formats ImageVariants can resize into the card and hero widths.
     */
    public const RESPONSIVE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Widths offered in srcset, smallest first. Larger images are served unresized by ImageVariants.
     */
    private const SRCSET_VARIANTS = ['card', 'hero'];

    private const MAX_RESIZABLE_PIXELS = 10000000;

    protected $fillable = ['uuid', 'path', 'original_name', 'mime_type', 'size', 'alt_text', 'caption', 'user_id', 'width', 'height'];

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Media $media): void {
            if (($media->width === null || $media->height === null) && in_array($media->mime_type, self::RASTER_MIMES, true)) {
                [$media->width, $media->height] = self::readDimensions($media->path, $media->mime_type) ?? [null, null];
            }
        });
        static::deleting(function (Media $media) {
            if ($media->isReferenced()) {
                throw ValidationException::withMessages(['media' => $media->inUseMessage()]);
            }
        });
        static::deleted(function (Media $media): void {
            Storage::disk('media')->delete($media->path);
            Storage::disk('media')->deleteDirectory('variants/'.$media->uuid);
        });
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class);
    }

    // SVG is reserved for trusted bundled seed illustrations; upload validation rejects SVG.
    public function isImage(): bool
    {
        return in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'], true);
    }

    public function url(?string $variant = null): string
    {
        return route('media.show', ['media' => $this->uuid] + ($variant ? ['variant' => $variant] : []));
    }

    /**
     * Displayed width and height of a stored bitmap, honouring the EXIF orientation browsers apply.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function readDimensions(string $path, string $mime): ?array
    {
        if (! in_array($mime, self::RASTER_MIMES, true)) {
            return null;
        }
        try {
            $file = Storage::disk('media')->path($path);
            $size = is_file($file) ? @getimagesize($file) : false;
            if (! $size || $size[0] < 1 || $size[1] < 1) {
                return null;
            }
            $orientation = $mime === 'image/jpeg' && function_exists('exif_read_data') ? (int) (@exif_read_data($file)['Orientation'] ?? 1) : 1;

            return in_array($orientation, [5, 6, 7, 8], true) ? [$size[1], $size[0]] : [$size[0], $size[1]];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Responsive candidates for an <img srcset>: the card (720w) and hero (1440w) variants narrower than
     * the original, then the original at its own width. Empty when there is nothing to choose from.
     */
    public function srcset(): string
    {
        if (! in_array($this->mime_type, self::RESPONSIVE_MIMES, true) || ! $this->width || ! $this->height || $this->width * $this->height > self::MAX_RESIZABLE_PIXELS) {
            return '';
        }
        $candidates = [];
        foreach (self::SRCSET_VARIANTS as $variant) {
            if (ImageVariants::WIDTHS[$variant] < $this->width) {
                $candidates[] = $this->url($variant).' '.ImageVariants::WIDTHS[$variant].'w';
            }
        }
        if (! $candidates) {
            return '';
        }
        $candidates[] = $this->url().' '.$this->width.'w';

        return implode(', ', $candidates);
    }

    /**
     * Escaped width, height, srcset and sizes attributes for an <img> showing this file.
     */
    public function imageAttributes(string $sizes = '100vw'): HtmlString
    {
        $attributes = [];
        if ($this->width && $this->height) {
            $attributes['width'] = $this->width;
            $attributes['height'] = $this->height;
        }
        $srcset = $this->srcset();
        if ($srcset !== '') {
            $attributes['srcset'] = $srcset;
            $attributes['sizes'] = $sizes;
        }

        return new HtmlString(implode(' ', array_map(fn (string $name, string|int $value): string => $name.'="'.e((string) $value).'"', array_keys($attributes), $attributes)));
    }

    public function isVideo(): bool
    {
        return in_array($this->mime_type, ['video/mp4', 'video/webm'], true);
    }

    public function isPublic(): bool
    {
        return app(SiteIdentity::class)->usesMedia($this, publicOnly: true)
            || $this->posts()->published()->exists()
            || $this->pages()->published()->exists()
            || User::where('avatar_id', $this->id)->whereHas('posts', fn ($q) => $q->published())->exists()
            || Category::where('cover_id', $this->id)->whereHas('posts', fn ($q) => $q->published())->exists()
            || Series::where('cover_id', $this->id)->whereHas('posts', fn ($q) => $q->published())->exists();
    }

    /** Why this file cannot be deleted, naming where it is still used. */
    public function inUseMessage(): string
    {
        $labels = array_column($this->usages(), 'label');

        return 'Este archivo se usa en: '.($labels ? implode(', ', array_slice($labels, 0, 5)).(count($labels) > 5 ? ' y '.(count($labels) - 5).' más' : '') : 'un borrador reciente del editor').'. Retíralo de esos lugares antes de eliminarlo.';
    }

    /**
     * Every place that still uses this file, for the file's page and for the message shown when it cannot be deleted.
     *
     * @return list<array{label: string, url: ?string}>
     */
    public function usages(): array
    {
        $usages = [];
        $user = auth()->user();
        $admin = $user?->isAdmin() ?? true;
        $site = Setting::read('site', []);
        $seo = Setting::read('seo', []) ?: [];
        foreach (['favicon_id' => 'Identidad del sitio · icono de la pestaña', 'brand_image_id' => 'Identidad del sitio · imagen de marca'] as $key => $label) {
            if ((int) ($site[$key] ?? 0) === $this->id) {
                $usages[] = $admin ? ['label' => $label, 'url' => Filament\Pages\SiteSettings::getUrl()] : ['label' => 'Ajustes del sitio', 'url' => null];
            }
        }
        if ((int) ($seo['default_image_id'] ?? 0) === $this->id) {
            $usages[] = $admin ? ['label' => 'SEO · imagen al compartir', 'url' => Filament\Pages\SeoSettings::getUrl()] : ['label' => 'Ajustes del sitio', 'url' => null];
        }
        foreach ($this->posts()->withTrashed()->get(['posts.id', 'posts.user_id', 'title', 'status', 'published_at', 'deleted_at']) as $post) {
            if (! $admin && $post->user_id !== $user?->getKey()) {
                $usages[] = ['label' => $post->isPublished() && ! $post->trashed() ? 'Entrada «'.$post->title.'» de otro autor' : 'Una entrada de otro autor', 'url' => null];

                continue;
            }
            $usages[] = ['label' => 'Entrada «'.$post->title.'»'.($post->trashed() ? ' (en la papelera)' : ''), 'url' => $post->trashed() ? null : Filament\Resources\Posts\PostResource::getUrl('edit', ['record' => $post])];
        }
        foreach ($this->pages()->withTrashed()->get(['pages.id', 'title', 'kind', 'deleted_at']) as $page) {
            if (! $admin) {
                $usages[] = ['label' => 'Una página del sitio', 'url' => null];

                continue;
            }
            $usages[] = ['label' => ($page->kind === 'home' ? 'Portada' : 'Página «'.$page->title.'»').($page->trashed() ? ' (en la papelera)' : ''), 'url' => $page->trashed() ? null : ($page->kind === 'home' ? Filament\Pages\HomeSettings::getUrl() : Filament\Resources\Pages\PageResource::getUrl('edit', ['record' => $page]))];
        }
        foreach (User::where('avatar_id', $this->id)->get(['id', 'name']) as $author) {
            $usages[] = ['label' => 'Autor «'.$author->name.'»', 'url' => $admin ? Filament\Resources\Users\UserResource::getUrl('edit', ['record' => $author]) : null];
        }
        foreach (Category::where('cover_id', $this->id)->get(['id', 'name']) as $category) {
            $usages[] = ['label' => 'Categoría «'.$category->name.'»', 'url' => Filament\Resources\Categories\CategoryResource::getUrl('edit', ['record' => $category])];
        }
        foreach (Series::where('cover_id', $this->id)->get(['id', 'name']) as $series) {
            $usages[] = ['label' => 'Serie «'.$series->name.'»', 'url' => Filament\Resources\Series\SeriesResource::getUrl('edit', ['record' => $series])];
        }

        return $usages;
    }

    public function isReferenced(): bool
    {
        if (app(SiteIdentity::class)->usesMedia($this) || $this->posts()->withTrashed()->exists() || $this->pages()->withTrashed()->exists() || User::where('avatar_id', $this->id)->exists() || Category::where('cover_id', $this->id)->exists() || Series::where('cover_id', $this->id)->exists()) {
            return true;
        }
        foreach (EditorDraft::where('updated_at', '>', now()->subDays(30))->cursor() as $draft) {
            $payload = $draft->payload;
            if (($payload['fields']['cover_id'] ?? null) == $this->id || in_array($this->id, app(ContentSchema::class)->mediaIds(app(ContentDocument::class)->toBlocks($payload['document'])), true)) {
                return true;
            }
        }

        return false;
    }
}
