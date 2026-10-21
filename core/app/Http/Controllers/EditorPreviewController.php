<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContentDocument;
use App\Services\ContentSchema;
use App\Services\HomeSections;
use App\Services\MediaAccess;
use App\Services\SiteRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Paints the real public page with the editor's unsaved state. Nothing is stored. */
class EditorPreviewController extends Controller
{
    public function __invoke(Request $request, SiteRenderer $renderer): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active && in_array($user->role, ['admin', 'editor'], true), 403);
        $data = $request->validate([
            'context' => 'required|in:post,page,home', 'record_id' => 'nullable|integer',
            'document' => 'nullable|array', 'fields' => 'present|array', 'mode' => 'required|in:light,dark',
            'fields.title' => 'nullable|string|max:255', 'fields.excerpt' => 'nullable|string|max:1500',
            'fields.slug' => 'nullable|string|max:255', 'fields.url_prefix' => 'nullable|string|max:255',
            'fields.cover_id' => 'nullable|integer', 'fields.user_id' => 'nullable|integer',
            'fields.category_ids' => 'nullable|array|max:20', 'fields.category_ids.*' => 'integer',
            'fields.tags' => 'nullable|array|max:100', 'fields.tags.*' => 'string|max:255',
            'fields.reading_minutes' => 'nullable|integer|min:1|max:300', 'fields.difficulty' => 'nullable|string|max:40',
            'fields.published_at' => 'nullable|date', 'fields.show_updated_at' => 'nullable|boolean',
            'fields.settings' => 'nullable|array', 'fields.sections' => 'nullable|array',
            'fields.settings.heading' => 'nullable|string|max:180', 'fields.settings.accent_heading' => 'nullable|string|max:180',
            'fields.settings.intro' => 'nullable|string|max:1500', 'fields.settings.show_heading' => 'sometimes|boolean',
            'fields.settings.show_intro' => 'sometimes|boolean', 'fields.settings.hide_heading' => 'sometimes|boolean',
        ]);
        if ($data['context'] !== 'post') {
            abort_unless($user->isAdmin(), 403);
        }
        $fields = $data['fields'] ?? [];
        $blocks = isset($data['document']) ? app(ContentDocument::class)->toBlocks($data['document']) : null;

        $view = match ($data['context']) {
            'post' => $renderer->article($this->post($user, $data['record_id'] ?? null, $fields, $blocks ?? []), live: true),
            default => $this->page($request, $renderer, $user, $data['context'], $data['record_id'] ?? null, $fields, $blocks),
        };

        return response()->json(['html' => $view->with('previewMode', $data['mode'])->render()])->header('Cache-Control', 'private, no-store');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function post(User $user, ?int $recordId, array $fields, array $blocks): Post
    {
        $record = $recordId ? Post::withTrashed()->findOrFail($recordId) : null;
        abort_if($record && ! $user->can('update', $record), 403);
        $post = $record ? clone $record : new Post;
        $post->title = filled($fields['title'] ?? null) ? $fields['title'] : ($record?->title ?: 'Sin título');
        foreach (['excerpt', 'slug', 'url_prefix', 'difficulty', 'show_updated_at'] as $key) {
            if (array_key_exists($key, $fields)) {
                $post->{$key} = $fields[$key];
            }
        }
        foreach (['difficulty', 'url_prefix'] as $key) {
            // "Sin nivel" and "Sin prefijo" travel as a placeholder value until the form saves them as empty.
            if ($post->{$key} === '__none') {
                $post->{$key} = '';
            }
        }
        $post->slug = $post->slug ?: 'vista-previa';
        $post->content = $blocks;
        $post->reading_minutes = $fields['reading_minutes'] ?? $post->estimatedReadingMinutes();
        $post->published_at = filled($fields['published_at'] ?? null) ? Carbon::parse($fields['published_at'], Setting::read('site', [])['timezone'] ?? 'UTC')->utc() : ($record?->published_at ?? now());
        $authorId = $user->isAdmin() && filled($fields['user_id'] ?? null) ? (int) $fields['user_id'] : ($record?->user_id ?? $user->id);

        $categoryIds = array_map('intval', $fields['category_ids'] ?? $record?->category_ids ?? []);
        $categories = Category::whereKey($categoryIds)->get()->sortBy(fn (Category $category): int => array_search($category->id, $categoryIds, true))->values();

        return $post->setRelation('cover', $this->visibleImage($user, $fields['cover_id'] ?? null))
            ->setRelation('categories', $categories)
            ->setRelation('tags', array_key_exists('tags', $fields) ? $this->typedTags($fields['tags'] ?? []) : ($record?->tags()->orderBy('name')->get() ?? collect()))
            ->setRelation('author', User::with('avatar')->find($authorId) ?? $user)
            ->setRelation('media', $this->visibleMedia($user, $blocks));
    }

    /**
     * The tags typed in the form: existing ones by name, and the new ones as they will be once saved.
     *
     * @param  array<int, string>  $names
     * @return Collection<int, Tag>
     */
    private function typedTags(array $names): Collection
    {
        return collect(Tag::cleanNames($names))
            ->map(fn (string $name): Tag => Tag::findByName($name) ?? new Tag(['name' => $name, 'slug' => Str::slug($name)]))
            ->sortBy('name')->values();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, array<string, mixed>>|null  $blocks
     */
    private function page(Request $request, SiteRenderer $renderer, User $user, string $context, ?int $recordId, array $fields, ?array $blocks): View
    {
        $record = $context === 'home' ? Page::where('kind', 'home')->firstOrFail() : ($recordId ? Page::findOrFail($recordId) : null);
        $page = $record ? clone $record : new Page;
        if (filled($fields['title'] ?? null)) {
            $page->title = $fields['title'];
        }
        $page->title = $page->title ?: 'Sin título';
        $page->settings = array_replace($record?->settings ?? [], $fields['settings'] ?? []);
        if (array_key_exists('hide_heading', $page->settings)) {
            $page->settings = array_replace($page->settings, ['show_heading' => ! $page->settings['hide_heading']]);
        }
        if ($page->kind === 'home' && is_array($fields['sections'] ?? null)) {
            $sections = app(HomeSections::class);
            $page->settings = array_replace($page->settings, ['sections' => $sections->validate($sections->fromBuilder($fields['sections']))]);
        }
        if ($blocks !== null) {
            $page->content = $blocks;
        }
        $coverId = array_key_exists('cover_id', $fields) ? $fields['cover_id'] : $record?->cover_id;
        $page->setRelation('cover', $this->visibleImage($user, $coverId))->setRelation('media', $this->visibleMedia($user, $page->content ?? [], $page->kind === 'home' ? app(HomeSections::class)->mediaIds($page->settings['sections'] ?? []) : []));

        return match ($page->kind) {
            'home' => $renderer->home(Request::create('/'), $page, live: true),
            'series' => $renderer->series(Request::create('/series'), $page, live: true),
            default => $renderer->page($page, live: true),
        };
    }

    private function visibleImage(User $user, mixed $id): ?Media
    {
        $media = filled($id) ? app(MediaAccess::class)->visibleTo(Media::query(), $user)->find((int) $id) : null;

        return $media?->isImage() ? $media : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<int, int>  $extraIds
     * @return Collection<int, Media>
     */
    private function visibleMedia(User $user, array $blocks, array $extraIds = []): Collection
    {
        return app(MediaAccess::class)->visibleTo(Media::query(), $user)->whereIn('id', [...app(ContentSchema::class)->mediaIds($blocks), ...$extraIds])->get();
    }
}
