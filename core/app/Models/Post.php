<?php

namespace App\Models;

use App\Services\ContentSchema;
use App\Services\PostPermalinks;
use App\Services\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Post extends Model
{
    use SoftDeletes;

    protected $attributes = ['content_version' => 1, 'status' => 'draft', 'featured' => false, 'show_updated_at' => false, 'series_order' => 0, 'reading_minutes' => 5, 'difficulty' => 'Básico'];

    protected $fillable = ['user_id', 'category_id', 'category_ids', 'series_id', 'cover_id', 'title', 'slug', 'url_prefix', 'excerpt', 'content', 'content_version', 'status', 'published_at', 'featured', 'series_order', 'reading_minutes', 'difficulty', 'youtube_url', 'youtube_credit', 'seo_title', 'seo_description', 'show_updated_at'];

    protected $appends = ['category_ids'];

    /**
     * Derived from the title, excerpt and content on every save; never edited or exported directly.
     */
    protected $hidden = ['search_text'];

    private mixed $pendingCategoryIds = null;

    /** The revision written by the latest save of this instance, so its tags can be added once they are saved. */
    private ?PostRevision $savedRevision = null;

    public function save(array $options = []): bool
    {
        try {
            return DB::transaction(fn (): bool => parent::save($options));
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['slug' => 'Esta dirección ya está ocupada. Elige otro nombre para la entrada.']);
        }
    }

    protected function casts(): array
    {
        return ['content' => 'array', 'content_version' => 'integer', 'published_at' => 'datetime', 'featured' => 'boolean', 'show_updated_at' => 'boolean', 'content_updated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Post $post) {
            $post->url_prefix ??= app(PostPermalinks::class)->defaultPrefix();
            app(PostPermalinks::class)->validatePost($post);
            if ($post->pendingCategoryIds === null && $post->isDirty('category_id')) {
                $post->pendingCategoryIds = $post->category_id ? [$post->category_id] : [];
            }
            if ($post->pendingCategoryIds !== null) {
                Validator::make(['category_ids' => $post->pendingCategoryIds], [
                    'category_ids' => ['array', 'max:20'], 'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
                ])->validate();
                $post->pendingCategoryIds = array_map('intval', array_values($post->pendingCategoryIds));
                $post->category_id = $post->pendingCategoryIds[0] ?? null;
            }
            if (! in_array($post->status, ['draft', 'published', 'archived'], true)) {
                throw ValidationException::withMessages(['status' => 'Estado no válido.']);
            }
            $post->content = app(ContentSchema::class)->validate($post->content ?? [], $post->content_version ?? 1);
            if (! $post->exists || $post->isDirty(['title', 'excerpt', 'content'])) {
                $post->search_text = app(SearchText::class)->forPost($post->title, $post->excerpt, $post->content);
            }
            if (blank($post->reading_minutes)) {
                $post->reading_minutes = $post->estimatedReadingMinutes();
            }
            $post->difficulty ??= '';
            if ($post->exists && $post->isDirty(['title', 'excerpt', 'content', 'cover_id'])) {
                $post->content_updated_at = now();
            }
            $post->user_id ??= auth()->id();
            if ($post->status === 'published' && ! $post->published_at) {
                $post->published_at = now();
            }
        });
        static::saved(function (Post $post) {
            app(PostPermalinks::class)->remember($post);
            $categoriesChanged = false;
            if ($post->pendingCategoryIds !== null) {
                $changes = $post->categories()->sync($post->pendingCategoryIds);
                $categoriesChanged = count($changes['attached']) + count($changes['detached']) > 0;
                $post->pendingCategoryIds = null;
                $post->unsetRelation('categories');
            }
            $ids = app(ContentSchema::class)->mediaIds($post->content);
            if ($post->cover_id) {
                $ids[] = $post->cover_id;
            }
            $post->media()->sync(array_unique($ids));
            if ($post->featured && ($post->wasRecentlyCreated || $post->wasChanged('featured'))) {
                static::whereKeyNot($post->id)->where('featured', true)->update(['featured' => false]);
            }
            $post->savedRevision = null;
            if ($post->wasRecentlyCreated || $post->wasChanged($post->getFillable()) || $categoriesChanged) {
                $post->recordRevision($post->only($post->getFillable()));
            }
        });
    }

    /** @param  array<string, mixed>  $snapshot */
    private function recordRevision(array $snapshot): void
    {
        $this->savedRevision = $this->revisions()->create(['user_id' => auth()->id(), 'snapshot' => $snapshot]);
        $this->revisions()->whereNotIn('id', $this->revisions()->limit(self::REVISIONS_KEPT)->pluck('id'))->delete();
        AuditEvent::record('post.saved', (string) $this->id, ['status' => $this->status]);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function url(): string
    {
        return app(PostPermalinks::class)->url($this->slug, $this->url_prefix);
    }

    public function getCategoryIdsAttribute(): array
    {
        return $this->pendingCategoryIds ?? ($this->exists ? $this->categories->modelKeys() : []);
    }

    public function setCategoryIdsAttribute(mixed $value): void
    {
        $this->pendingCategoryIds = $value;
    }

    public function isPublished(): bool
    {
        return ! $this->trashed() && $this->status === 'published' && $this->published_at?->lte(now());
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function duplicateAsDraft(): self
    {
        abort_unless(auth()->user()?->can('create', self::class) && auth()->user()?->can('view', $this), 403);

        return DB::transaction(function (): self {
            $copy = new self($this->only($this->getFillable()));
            $copy->title = mb_substr($this->title, 0, 245).' (copia)';
            $copy->slug = mb_substr($this->slug, 0, 210).'-copia-'.strtolower(Str::random(10));
            if (! array_key_exists($copy->url_prefix, app(PostPermalinks::class)->options())) {
                $copy->url_prefix = app(PostPermalinks::class)->defaultPrefix();
            }
            $copy->user_id = auth()->id();
            $copy->status = 'draft';
            $copy->published_at = null;
            $copy->featured = false;
            $copy->save();
            $copy->tags()->sync($this->tags->modelKeys());

            return $copy;
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->orderBy('categories.name');
    }

    public function displayCategory(): ?Category
    {
        return $this->categories->first();
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PostRevision::class)->latest('id');
    }

    /** About 200 words a minute, at least one minute. */
    public function estimatedReadingMinutes(): int
    {
        $words = count(preg_split('/\s+/u', trim(app(SearchText::class)->forPost('', '', $this->content ?? [])), -1, PREG_SPLIT_NO_EMPTY));

        return max(1, (int) ceil($words / 200));
    }

    /** Revisions kept per post; older ones are discarded so they never pile up or pin library files. */
    public const REVISIONS_KEPT = 20;

    /**
     * Adds the tags chosen in the form to the revision of this save, since tags are saved after the post itself.
     * When only the tags changed there is no such revision, so a new one is written instead of altering an older one.
     */
    /**
     * Gives the post exactly these tags, by name: an existing tag is reused whatever its capitals, and a new name
     * becomes a tag. Blank and repeated names are ignored.
     *
     * @param  array<int, mixed>  $names
     */
    public function syncTagNames(array $names): void
    {
        $ids = array_map(fn (string $name): int => (Tag::findByName($name) ?? Tag::create(['name' => $name, 'slug' => Tag::uniqueSlug($name)]))->id, Tag::cleanNames($names));
        $this->tags()->sync($ids);
    }

    public function rememberRevisionTags(): void
    {
        $tags = $this->tags()->pluck('tags.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        if ($this->savedRevision) {
            $this->savedRevision->update(['snapshot' => ['tag_ids' => $tags] + $this->savedRevision->snapshot]);
        } elseif (($this->revisions()->first()?->snapshot['tag_ids'] ?? null) !== $tags) {
            $this->recordRevision($this->only($this->getFillable()) + ['tag_ids' => $tags]);
        }
        $this->savedRevision = null;
    }

    /**
     * The form data of an earlier revision, without saving anything. The address, author, status and date stay as
     * they are now. Library files deleted since then are left out and counted in "missing_media".
     *
     * @return array{data: array<string, mixed>, missing_media: int}
     */
    public function revisionFormData(PostRevision $revision): array
    {
        abort_unless($revision->post_id === $this->id, 404);
        $snapshot = $revision->snapshot;
        $tags = array_map('intval', $snapshot['tag_ids'] ?? $this->tags()->pluck('tags.id')->all());
        unset($snapshot['user_id'], $snapshot['status'], $snapshot['published_at'], $snapshot['slug'], $snapshot['url_prefix'], $snapshot['tag_ids'], $snapshot['featured']);
        if (! array_key_exists('category_ids', $snapshot)) {
            $snapshot['category_ids'] = isset($snapshot['category_id']) ? [$snapshot['category_id']] : [];
        }
        unset($snapshot['category_id']);
        $snapshot['content_version'] ??= 1;
        $snapshot['show_updated_at'] ??= false;
        $blocks = $snapshot['content'] ?? [];
        if (filled($snapshot['youtube_url'] ?? '') && app(ContentSchema::class)->youtubeId($snapshot['youtube_url'])) {
            if (! collect($blocks)->contains(fn ($block): bool => ($block['type'] ?? '') === 'video' && ($block['data']['url'] ?? '') === $snapshot['youtube_url'])) {
                array_unshift($blocks, ['type' => 'video', 'data' => ['url' => $snapshot['youtube_url'], 'caption' => $snapshot['youtube_credit'] ?? $snapshot['title'] ?? 'Video']]);
            }
        }
        $snapshot['youtube_url'] = null;
        $snapshot['youtube_credit'] = null;
        $existing = Media::whereKey([...app(ContentSchema::class)->mediaIds($blocks), ...array_filter([$snapshot['cover_id'] ?? null])])->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $missing = 0;
        $snapshot['content'] = array_values(array_filter($blocks, function ($block) use ($existing, &$missing): bool {
            if (in_array($block['type'] ?? '', ['image', 'file', 'media_video'], true) && ! in_array((int) ($block['data']['media_id'] ?? 0), $existing, true)) {
                $missing++;

                return false;
            }

            return true;
        }));
        if (($snapshot['cover_id'] ?? null) && ! in_array((int) $snapshot['cover_id'], $existing, true)) {
            $snapshot['cover_id'] = null;
            $missing++;
        }
        $snapshot['tags'] = $tags;

        return ['data' => $snapshot, 'missing_media' => $missing];
    }
}
