<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Post;
use App\Services\SeoMetadata;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class SeoController extends Controller
{
    private const SITEMAP_SIZE = 1000;

    /**
     * Seconds browsers and proxies may reuse robots.txt, sitemaps and the feed. These routes run without
     * session or CSRF middleware, so the public response never carries a visitor's cookies.
     */
    public const PUBLIC_MAX_AGE = 3600;

    public function robots(): Response
    {
        if (app(SeoMetadata::class)->settings()['noindex']) {
            return $this->shareable(response("User-agent: *\nDisallow: /\n")->header('Content-Type', 'text/plain; charset=UTF-8'));
        }
        $previews = (parse_url(url('preview'), PHP_URL_PATH) ?: '/preview').'/';

        return $this->shareable(response("User-agent: *\nAllow: /\nDisallow: ".$previews."\n\nSitemap: ".route('sitemap')."\n")
            ->header('Content-Type', 'text/plain; charset=UTF-8'));
    }

    public function sitemap(): Response
    {
        $total = Page::published()->count() + Post::published()->count();
        if ($total > self::SITEMAP_SIZE) {
            return $this->shareable(response()->view('feeds.sitemap-index', ['parts' => (int) ceil($total / self::SITEMAP_SIZE)])
                ->header('Content-Type', 'application/xml; charset=UTF-8'));
        }

        return $this->sitemapPage('1');
    }

    public function sitemapPage(string $page): Response
    {
        $page = (int) $page;
        $pageCount = Page::published()->count();
        $total = $pageCount + Post::published()->count();
        abort_if($page < 1 || $page > max(1, (int) ceil($total / self::SITEMAP_SIZE)), 404);
        $offset = ($page - 1) * self::SITEMAP_SIZE;
        $urls = [];
        if ($offset < $pageCount) {
            $latestPost = Post::published()->selectRaw('MAX(CASE WHEN content_updated_at > published_at THEN content_updated_at ELSE published_at END) as latest')->value('latest');
            foreach (Page::published()->orderBy('id')->skip($offset)->take(self::SITEMAP_SIZE)->get(['id', 'kind', 'slug', 'updated_at']) as $item) {
                $modified = $item->updated_at;
                if (in_array($item->kind, ['home', 'series'], true) && $latestPost && $modified->lt($latestPost)) {
                    $modified = Carbon::parse($latestPost);
                }
                $urls[] = ['loc' => $item->url(), 'lastmod' => $modified->toIso8601String()];
            }
        }
        $remaining = self::SITEMAP_SIZE - count($urls);
        if ($remaining > 0) {
            foreach (Post::published()->orderBy('id')->skip(max(0, $offset - $pageCount))->take($remaining)->get(['id', 'slug', 'url_prefix', 'published_at', 'content_updated_at']) as $item) {
                $modified = $item->content_updated_at?->gt($item->published_at) ? $item->content_updated_at : $item->published_at;
                $urls[] = ['loc' => $item->url(), 'lastmod' => $modified->toIso8601String()];
            }
        }

        return $this->shareable(response()->view('feeds.sitemap', compact('urls'))->header('Content-Type', 'application/xml; charset=UTF-8'));
    }

    private function shareable(Response $response): Response
    {
        return $response->header('Cache-Control', 'public, max-age='.self::PUBLIC_MAX_AGE);
    }
}
