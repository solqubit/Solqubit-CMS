<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Series;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Renders the public pages. The site and the editor's live preview share it, so both paint exactly the same thing.
 * A live preview implies a preview: no ads, no visit counting, and no "saved version" banner.
 */
class SiteRenderer
{
    public const SUMMARY_COLUMNS = ['id', 'title', 'slug', 'url_prefix', 'excerpt', 'cover_id', 'series_id', 'reading_minutes', 'difficulty', 'published_at'];

    public function __construct(private ThemeRegistry $themes) {}

    /** @param  array<string, mixed>  $data */
    public function render(string $name, array $data = []): View
    {
        $live = $data['live'] ?? false;
        $preview = ($data['preview'] ?? false) || $live;
        $advertisingEligible = ($name === 'post' && count($data['blocks'] ?? []) > 0)
            || ($name === 'home' && ($data['totalEntries'] ?? 0) > 0 && ($data['search'] ?? '') === '' && ($data['category'] ?? '') === '');

        $layout = app(SiteLayout::class)->data($advertisingEligible, $preview);

        return view($this->themes->view($name), array_merge($layout, [
            'seo' => app(SeoMetadata::class)->forPage($name, $data, $layout['site']),
        ], $data, ['preview' => $preview, 'live' => $live]));
    }

    public function home(Request $request, Page $page, bool $preview = false, bool $live = false): View
    {
        $search = trim(mb_substr((string) $request->query('q', ''), 0, 160));
        $category = mb_substr((string) $request->query('category', ''), 0, 255);
        $pageNumber = max(1, (int) $request->query('page', 1));
        $filtering = $search !== '' || $category !== '' || $pageNumber > 1;
        $homeSections = app(HomeSections::class);
        $visible = array_values(array_filter($homeSections->fromPage($page), fn (array $section): bool => $section['visible']));
        $sections = array_values(array_filter($visible, fn (array $section): bool => ! ($filtering && in_array($section['type'], HomeSections::SELECTED_TYPES, true))));
        $latest = collect($sections)->firstWhere('type', 'latest');
        if ($latest === null && ($search !== '' || $category !== '')) {
            $sections[] = $latest = $homeSections->withDefaults(['type' => 'latest']);
        }
        // Without a listing there are no numbered pages to show.
        abort_if($latest === null && $pageNumber > 1, 404);
        $latest ??= $homeSections->withDefaults(['type' => 'latest']);

        $query = Post::published()->select(self::SUMMARY_COLUMNS)->with(['cover', 'categories']);
        $searchText = app(SearchText::class);
        foreach (array_slice(preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY), 0, 10) as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $normalized = $searchText->normalize($word);
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('excerpt', 'like', $like)
                ->when($normalized !== '', fn ($q) => $q->orWhere('search_text', 'like', '%'.addcslashes($normalized, '%_\\').'%'))
                ->orWhereHas('tags', fn ($tags) => $tags->where('name', 'like', $like)));
        }
        if ($category) {
            $query->whereHas('categories', fn ($q) => $q->where('slug', $category));
        }

        // The featured entry stays out of every listing page, but is only shown on the first one.
        $featuredSection = $search === '' && $category === '' ? collect($visible)->firstWhere('type', 'featured') : null;
        $featuredCandidate = $featuredSection ? $this->featuredPost($featuredSection) : null;
        $posts = (clone $query)->when($featuredCandidate, fn ($q) => $q->whereKeyNot($featuredCandidate->id))->latest('published_at')->orderByDesc('id')
            ->paginate($latest['per_page'])->withQueryString();
        abort_if($posts->currentPage() > max(1, $posts->lastPage()), 404);
        $featured = $featuredCandidate && $posts->onFirstPage() ? $featuredCandidate : null;

        foreach ($sections as $index => $section) {
            if ($section['type'] === 'topic') {
                $topic = $homeSections->category($section);
                $sections[$index]['category'] = $topic;
                $sections[$index]['posts'] = $topic ? Post::published()->select(self::SUMMARY_COLUMNS)->with(['cover', 'categories'])
                    ->whereHas('categories', fn ($q) => $q->whereKey($topic->id))->latest('published_at')->orderByDesc('id')->limit($section['count'])->get() : collect();
            } elseif ($section['type'] === 'series') {
                $sections[$index]['items'] = Series::whereHas('posts', fn ($q) => $q->published())
                    ->with(['cover', 'posts' => fn ($q) => $q->published()->select(self::SUMMARY_COLUMNS)->with('cover')->orderBy('series_order')->orderBy('id')])
                    ->orderBy('name')->orderBy('id')->limit($section['count'])->get();
            }
        }
        $sections = array_values(array_filter($sections, fn (array $section): bool => $section['type'] !== 'topic' || $section['category'] !== null));
        $seriesPage = Page::where('kind', 'series')->published()->first();

        return $this->render('home', compact('posts', 'featured', 'search', 'category', 'page', 'preview', 'live', 'sections', 'seriesPage') + [
            'canonicalUrl' => $this->listingUrl($request, $posts->currentPage(), array_filter(['q' => $search, 'category' => $category], fn (string $value): bool => $value !== '')),
            'noIndex' => $search !== '' || $category !== '',
            'totalEntries' => $posts->total() + ($featuredCandidate ? 1 : 0),
            'categories' => Category::whereHas('posts', fn ($q) => $q->published())->orderBy('name')->get(),
        ]);
    }

    /** @param  array<string, mixed>  $section */
    private function featuredPost(array $section): ?Post
    {
        $published = fn () => Post::published()->select(self::SUMMARY_COLUMNS)->with(['cover', 'categories']);
        $chosen = filled($section['post_id']) ? $published()->find($section['post_id']) : null;

        return $chosen ?? ($section['fallback_latest'] ? $published()->latest('published_at')->orderByDesc('id')->first() : null);
    }

    /** The post's relations are used as already set, so a live preview can pass an unsaved post with its own. */
    public function article(Post $post, bool $preview = false, bool $live = false): View
    {
        request()->attributes->set('solqubit.post', true);
        $post->loadMissing(['cover', 'categories', 'author.avatar', 'tags', 'media']);
        $blocks = $post->content ?? [];
        $toc = [];
        foreach ($blocks as $index => &$block) {
            if ($block['type'] === 'heading') {
                $block['anchor'] = Str::slug($block['data']['text']).'-'.$index;
                if ($block['data']['level'] === 'h2') {
                    $toc[] = ['id' => $block['anchor'], 'title' => $block['data']['text']];
                }
            }
        }
        unset($block);
        $categoryIds = $post->categories->modelKeys();
        $related = Post::published()->select(self::SUMMARY_COLUMNS)->when($post->exists, fn ($query) => $query->whereKeyNot($post->id))->with(['cover', 'categories'])
            ->withCount(['categories as matching_categories_count' => fn ($query) => $query->whereIn('categories.id', $categoryIds)])
            ->orderByDesc('matching_categories_count')->latest('published_at')->first();

        return $this->render('post', compact('post', 'blocks', 'toc', 'related', 'preview', 'live'));
    }

    public function page(Page $page, bool $preview = false, bool $live = false): View
    {
        return $this->render('page', compact('page', 'preview', 'live'));
    }

    public function series(Request $request, Page $page, bool $preview = false, bool $live = false): View
    {
        $series = Series::whereHas('posts', fn ($q) => $q->published())
            ->with(['cover', 'posts' => fn ($q) => $q->published()->select(self::SUMMARY_COLUMNS)->with('cover')->orderBy('series_order')->orderBy('id')])
            ->orderBy('name')->orderBy('id')->paginate($page->option('per_page', 6));
        abort_if($series->currentPage() > max(1, $series->lastPage()), 404);

        return $this->render('series', compact('page', 'series', 'preview', 'live') + [
            'canonicalUrl' => $this->listingUrl($request, $series->currentPage()),
        ]);
    }

    /** @param  array<string, string|int>  $filters */
    public function listingUrl(Request $request, int $page, array $filters = []): string
    {
        if ($page > 1) {
            $filters['page'] = $page;
        }

        return url($request->path()).($filters ? '?'.http_build_query($filters, '', '&', PHP_QUERY_RFC3986) : '');
    }
}
