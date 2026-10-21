<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Services\PostPermalinks;
use App\Services\SeoMetadata;
use App\Services\SiteRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BlogController extends Controller
{
    public function __construct(private SiteRenderer $renderer) {}

    private function page(string $name, array $data = [])
    {
        return $this->renderer->render($name, $data);
    }

    public function index(Request $request)
    {
        return $this->home($request, Page::where('kind', 'home')->published()->firstOrFail());
    }

    private function home(Request $request, Page $page, bool $preview = false)
    {
        $this->validateFilters($request);

        return $this->renderer->home($request, $page, $preview);
    }

    public function show(Request $request, string $path)
    {
        $permalinks = app(PostPermalinks::class);
        $postId = DB::table('post_paths')->where('path', $path)->value('post_id');
        abort_if($postId === null, 404);
        $post = Post::published()->findOrFail($postId);
        if ($path !== $permalinks->path($post->slug, $post->url_prefix)) {
            $query = $request->getQueryString();

            return redirect()->to($post->url().($query ? '?'.$query : ''), 301);
        }

        return $this->article($post);
    }

    public function preview(Request $request, Post $post)
    {
        abort_unless($request->user()?->can('update', $post), 403);

        return $this->article($post, true);
    }

    private function article(Post $post, bool $preview = false)
    {
        return $this->renderer->article($post, $preview);
    }

    public function series(Request $request)
    {
        $this->validateFilters($request);

        return $this->seriesPage(Page::where('kind', 'series')->published()->firstOrFail());
    }

    private function seriesPage(Page $page, bool $preview = false)
    {
        return $this->renderer->series(request(), $page, $preview);
    }

    public function about()
    {
        return $this->page('page', ['page' => Page::where('kind', 'about')->published()->firstOrFail(), 'preview' => false]);
    }

    public function custom(Request $request, string $slug)
    {
        if (DB::table('post_paths')->where('path', $slug)->exists()) {
            return $this->show($request, $slug);
        }
        $page = Page::where('kind', 'custom')->published()->where('slug', $slug)->first();
        if (! $page) {
            $moved = Page::where('kind', 'custom')->published()->whereKey(DB::table('page_paths')->where('path', $slug)->value('page_id'))->firstOrFail();
            $query = $request->getQueryString();

            return redirect()->to($moved->url().($query ? '?'.$query : ''), 301);
        }

        return $this->page('page', ['page' => $page, 'preview' => false]);
    }

    public function legacyPage(Request $request, string $slug)
    {
        $page = Page::where('kind', 'custom')->published()->where('slug', $slug)->firstOrFail();
        $url = $page->url();
        if ($url === route('pages.legacy', $slug)) {
            return $this->page('page', ['page' => $page, 'preview' => false]);
        }

        $query = $request->getQueryString();

        return redirect()->to($url.($query ? '?'.$query : ''), 301);
    }

    public function previewPage(Request $request, Page $page)
    {
        abort_unless($request->user()?->can('update', $page), 403);
        $this->validateFilters($request);

        return match ($page->kind) {
            'home' => $this->home($request, $page, true),
            'series' => $this->seriesPage($page, true),
            default => $this->page('page', ['page' => $page, 'preview' => true]),
        };
    }

    public function feed()
    {
        $posts = Post::published()->select(['id', 'user_id', 'title', 'slug', 'url_prefix', 'excerpt', 'published_at', 'content_updated_at'])
            ->with('author:id,name')->latest('published_at')->orderByDesc('id')->limit(20)->get();
        $updated = $posts->map(fn (Post $post) => $post->content_updated_at?->gt($post->published_at) ? $post->content_updated_at : $post->published_at)->max();

        return response()->view('feeds.rss', [
            'posts' => $posts, 'site' => Setting::read('site', []), 'updated' => $updated,
            'language' => app(SeoMetadata::class)->language(),
        ])->header('Content-Type', 'application/rss+xml; charset=UTF-8')->header('Cache-Control', 'public, max-age='.SeoController::PUBLIC_MAX_AGE);
    }

    private function validateFilters(Request $request): void
    {
        $validator = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:0'],
        ]);
        abort_if($validator->fails(), 400, 'Los filtros de búsqueda o el número de página no son válidos.');
    }
}
