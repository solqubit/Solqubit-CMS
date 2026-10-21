<?php

namespace App\Models;

use App\Services\ContentSchema;
use App\Services\HomeSections;
use App\Services\PagePermalinks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Page extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['title', 'slug', 'published', 'settings', 'content', 'content_version', 'cover_id', 'seo_title', 'seo_description'];

    protected $attributes = ['kind' => 'custom', 'published' => false, 'content_version' => 1];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'settings' => 'array', 'content' => 'array', 'content_version' => 'integer'];
    }

    public static function iconOptions(): array
    {
        return ['none' => 'Sin icono', 'sparkles' => 'Destellos', 'code' => 'Código', 'terminal' => 'Terminal', 'video' => 'Video', 'server' => 'Servidor', 'layers' => 'Capas', 'globe' => 'Mundo', 'heart' => 'Corazón'];
    }

    protected static function booted(): void
    {
        static::saving(function (Page $page): void {
            if ($page->exists && ($page->isDirty('kind') || ($page->getOriginal('kind') !== 'custom' && $page->isDirty('slug')))) {
                throw ValidationException::withMessages(['slug' => 'La dirección y el tipo de una página del sistema no se pueden cambiar.']);
            }
            if ($page->kind === 'home' && ! $page->published) {
                throw ValidationException::withMessages(['published' => 'Inicio debe permanecer publicado. Puedes ocultar su título y descripción.']);
            }
            Validator::make($page->attributesToArray(), [
                'title' => 'required|string|max:255', 'slug' => 'required|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|max:255',
                'settings' => 'nullable|array', 'settings.heading' => 'nullable|string|max:180',
                'settings.accent_heading' => 'nullable|string|max:180', 'settings.intro' => 'nullable|string|max:1500',
                'settings.icon' => ['nullable', Rule::in(array_keys(self::iconOptions()))],
                'settings.per_page' => ['nullable', Rule::in([6, 9, 12, 18, 24])],
                'settings.catalog_title' => 'nullable|string|max:180',
                'settings.show_heading' => 'boolean', 'settings.show_intro' => 'boolean',
                'settings.show_featured' => 'boolean', 'settings.show_channel' => 'boolean',
            ])->validate();
            app(PagePermalinks::class)->validate($page);
            if ($page->cover_id && ! Media::find($page->cover_id)?->isImage()) {
                throw ValidationException::withMessages(['cover_id' => 'Selecciona una imagen válida.']);
            }
            $page->content = app(ContentSchema::class)->validate($page->content ?? [], $page->content_version ?? 1);
        });
        static::saved(function (Page $page): void {
            $ids = app(ContentSchema::class)->mediaIds($page->content);
            if ($page->kind === 'home') {
                array_push($ids, ...app(HomeSections::class)->mediaIds($page->settings['sections'] ?? []));
            }
            if ($page->cover_id) {
                $ids[] = $page->cover_id;
            }
            $page->media()->sync(array_unique($ids));
            app(PagePermalinks::class)->rememberPrevious($page);
            AuditEvent::record('page.saved', (string) $page->id);
        });
        static::deleting(function (Page $page): void {
            if ($page->kind !== 'custom') {
                throw ValidationException::withMessages(['page' => 'Las páginas del sistema se conservan. Puedes desactivar Series y Sobre mí.']);
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class);
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_id');
    }

    public function url(): string
    {
        return match ($this->kind) {
            'home' => route('home'), 'series' => route('series'), 'about' => route('about'),
            default => app(PagePermalinks::class)->url($this->slug),
        };
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
