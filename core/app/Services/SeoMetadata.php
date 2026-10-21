<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Str;

class SeoMetadata
{
    public function settings(): array
    {
        $saved = Setting::read('seo', []);
        $values = [];
        foreach (['google_verification', 'bing_verification'] as $key) {
            $value = is_array($saved) ? ($saved[$key] ?? '') : '';
            $values[$key] = is_string($value) && preg_match('/^[a-zA-Z0-9_-]{8,160}$/D', $value) ? $value : '';
        }
        $values['default_image_id'] = is_array($saved) ? (filter_var($saved['default_image_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null) : null;
        $values['noindex'] = is_array($saved) && ($saved['noindex'] ?? false) === true;

        return $values;
    }

    /** The image shared on social networks when a page or post has no cover of its own. */
    public function defaultImage(): ?Media
    {
        $id = $this->settings()['default_image_id'];

        return $id ? Media::whereKey($id)->whereIn('mime_type', SiteIdentity::IMAGE_MIMES)->first() : null;
    }

    private function plain(?string $value): string
    {
        return Str::squish(html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br>', '<br/>', '<br />'], ' ', $value ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function contentSummary(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $data = $block['data'] ?? [];
            $value = match ($block['type'] ?? '') {
                'paragraph' => $data['html'] ?? '',
                'notice', 'details' => app(ContentSchema::class)->inline($data['text'] ?? ''),
                'list' => app(ContentSchema::class)->inline(implode(' ', array_column($data['items'] ?? [], 'text'))),
                default => '',
            };
            $parts[] = $this->plain($value);
            if (mb_strlen(implode(' ', $parts)) >= 180) {
                break;
            }
        }

        return Str::limit(Str::squish(implode(' ', $parts)), 160);
    }

    /**
     * The site language as a BCP 47 tag for inLanguage and RSS ("es", "es-MX").
     */
    public function language(): string
    {
        return str_replace('_', '-', app()->getLocale());
    }

    /**
     * The site language as an Open Graph locale ("es" becomes "es_ES").
     */
    public function openGraphLocale(): string
    {
        $locale = str_replace('-', '_', app()->getLocale());
        if (str_contains($locale, '_')) {
            [$language, $region] = explode('_', $locale, 2);

            return strtolower($language).'_'.strtoupper($region);
        }

        return strtolower($locale).'_'.strtoupper(match (strtolower($locale)) {
            'en' => 'us', 'pt' => 'br', 'ca', 'gl', 'eu' => 'es', default => $locale,
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function organization(array $site, string $siteName, string $homeUrl): array
    {
        $organization = ['@type' => 'Organization', '@id' => $homeUrl.'#organization', 'name' => $siteName, 'url' => $homeUrl];
        $logo = app(SiteIdentity::class)->assets($site)['brandImage'];
        if ($logo) {
            $organization['logo'] = array_filter(['@type' => 'ImageObject', 'url' => $logo->url(), 'width' => $logo->width, 'height' => $logo->height]);
        }

        return $organization;
    }

    public function forPage(string $name, array $data, array $site): array
    {
        $record = $data['post'] ?? $data['page'];
        $isPost = $record instanceof Post;
        $preview = $data['preview'] ?? false;
        $noIndex = $data['noIndex'] ?? false;
        $siteName = $this->plain($site['name'] ?? 'Blog');
        $isHome = ! $isPost && $record->kind === 'home';
        $heading = $this->plain($record->seo_title ?: ($isHome ? ($site['name'] ?? 'Blog') : $record->title));
        $pagination = $data['posts'] ?? $data['series'] ?? null;
        $pageNumber = $pagination?->currentPage() ?? 1;
        $title = $heading.($pageNumber > 1 ? ' · Página '.$pageNumber : '').($heading === $siteName ? '' : ' — '.$siteName);
        $intro = $isPost ? $record->excerpt : $record->option('intro', '');
        $description = $this->plain($record->seo_description);
        if ($description === '') {
            $description = $this->plain($intro);
            if ($description === '') {
                $description = $this->contentSummary($record->content ?? []);
            }
            if ($description === '') {
                $description = $this->plain($site['description'] ?? '') ?: $this->plain($record->title).' — '.$siteName;
            }
            $description = Str::limit($description, 160);
        }
        $canonical = $data['canonicalUrl'] ?? $record->url();
        $cover = $record->cover ?? $this->defaultImage();
        $image = $cover?->url();
        $imageAlt = $cover ? $this->plain($cover->alt_text) : '';
        $published = $isPost ? $record->published_at?->toIso8601String() : null;
        $modified = $isPost && $record->content_updated_at?->gt($record->published_at ?? $record->created_at)
            ? $record->content_updated_at->toIso8601String() : $published;
        $homeUrl = route('home');
        $language = $this->language();
        $organization = $this->organization($site, $siteName, $homeUrl);
        $website = ['@type' => 'WebSite', '@id' => $homeUrl.'#website', 'url' => $homeUrl, 'name' => $siteName, 'inLanguage' => $language, 'publisher' => ['@id' => $organization['@id']]];
        $webpage = [
            '@type' => in_array($name, ['home', 'series'], true) ? 'CollectionPage' : ($record instanceof Page && $record->kind === 'about' ? 'AboutPage' : 'WebPage'),
            '@id' => $canonical.'#webpage', 'url' => $canonical, 'name' => $title,
            'description' => $description, 'inLanguage' => $language, 'isPartOf' => ['@id' => $website['@id']],
        ];
        $graph = [$organization, $website];
        if ($name !== 'home') {
            // Mirrors the visible trail of an entry (Blog › category) and ends at the current address.
            $trail = $isPost ? [['Blog', $homeUrl]] : [['Inicio', $homeUrl]];
            $category = $isPost ? $record->displayCategory() : null;
            if ($category) {
                $trail[] = [$this->plain($category->name), route('home', ['category' => $category->slug])];
            }
            $trail[] = [$this->plain($record->title), $canonical];
            $breadcrumbs = [
                '@type' => 'BreadcrumbList', '@id' => $canonical.'#breadcrumb',
                'itemListElement' => array_map(fn (array $crumb, int $index): array => ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $crumb[0], 'item' => $crumb[1]], $trail, array_keys($trail)),
            ];
            $graph[] = $breadcrumbs;
            $webpage['breadcrumb'] = ['@id' => $breadcrumbs['@id']];
        }
        $graph[] = $webpage;
        if ($isPost) {
            $article = [
                '@type' => 'BlogPosting', '@id' => $canonical.'#article', 'url' => $canonical,
                'headline' => $this->plain($record->title), 'description' => $description,
                'mainEntityOfPage' => ['@id' => $webpage['@id']], 'isPartOf' => ['@id' => $website['@id']], 'inLanguage' => $language,
                'publisher' => ['@id' => $organization['@id']],
            ];
            if ($published) {
                $article['datePublished'] = $published;
                $article['dateModified'] = $modified;
            }
            if ($record->author) {
                $article['author'] = ['@type' => 'Person', 'name' => $this->plain($record->author->name)];
            }
            if ($image) {
                $article['image'] = $cover->width && $cover->height
                    ? [['@type' => 'ImageObject', 'url' => $image, 'width' => $cover->width, 'height' => $cover->height]]
                    : [$image];
            }
            $graph[] = $article;
        }

        return [
            'title' => $title, 'description' => $description, 'canonical' => $canonical,
            'robots' => $preview || $this->settings()['noindex'] ? 'noindex,nofollow' : ($noIndex ? 'noindex,follow' : 'index,follow,max-image-preview:large'),
            'image' => $image, 'image_alt' => $imageAlt, 'image_width' => $image ? $cover->width : null, 'image_height' => $image ? $cover->height : null,
            'locale' => $this->openGraphLocale(), 'site_name' => $siteName, 'type' => $isPost ? 'article' : 'website',
            'published' => $published, 'modified' => $modified,
            'verification' => $preview ? [] : $this->settings(),
            'structured_data' => $preview || $noIndex ? null : json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }
}
