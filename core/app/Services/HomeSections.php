<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The front page as an ordered list of sections, stored in pages.settings['sections'] of the home record.
 * A home saved before sections existed is converted on the fly so it looks exactly as before.
 */
class HomeSections
{
    public const TYPES = ['welcome', 'featured', 'latest', 'topic', 'series', 'text', 'ad'];

    public const LABELS = [
        'welcome' => 'Bienvenida', 'featured' => 'Entrada destacada', 'latest' => 'Últimas entradas',
        'topic' => 'Entradas de un tema', 'series' => 'Series', 'text' => 'Texto libre', 'ad' => 'Anuncio',
    ];

    /** Sections that show hand-picked entries; they step aside while searching, filtering or paging. */
    public const SELECTED_TYPES = ['featured', 'topic', 'series'];

    public const PER_PAGE = [6, 9, 12, 18, 24];

    /** @return array<int, array<string, mixed>> */
    public function fromPage(Page $home): array
    {
        $saved = $home->settings['sections'] ?? null;
        if (is_array($saved)) {
            // A type this version does not know (for example one retired by an update) is skipped instead of breaking the page.
            $known = array_filter($saved, fn ($section): bool => is_array($section) && in_array($section['type'] ?? null, self::TYPES, true));

            return array_map(fn (array $section): array => $this->withDefaults($section), array_values($known));
        }

        $sections = [$this->withDefaults(['type' => 'welcome'] + array_intersect_key($home->settings ?? [], array_flip(['heading', 'accent_heading', 'intro', 'icon', 'show_heading', 'show_intro'])))];
        if (filled($home->content)) {
            $sections[] = $this->withDefaults(['type' => 'text', 'content' => $home->content]);
        }
        $sections[] = $this->withDefaults(['type' => 'featured', 'visible' => (bool) $home->option('show_featured', true),
            'post_id' => Post::published()->where('featured', true)->latest('published_at')->orderByDesc('id')->value('id'), 'fallback_latest' => false]);
        $sections[] = $this->withDefaults(['type' => 'latest', 'title' => $home->option('catalog_title') ?: 'Últimas entradas.', 'per_page' => (int) $home->option('per_page', 12)]);
        if (app(Monetization::class)->configuration()['status'] !== 'off') {
            $sections[] = $this->withDefaults(['type' => 'ad']);
        }

        return $sections;
    }

    /**
     * Defaults of each type, merged under what was given.
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    public function withDefaults(array $section): array
    {
        $defaults = match ($section['type'] ?? null) {
            'welcome' => ['heading' => '', 'accent_heading' => '', 'intro' => '', 'icon' => 'none', 'show_heading' => true, 'show_intro' => true],
            'featured' => ['post_id' => null, 'fallback_latest' => true],
            'latest' => ['title' => 'Últimas entradas.', 'per_page' => 12, 'show_search' => true, 'show_filter' => true],
            'topic' => ['category_id' => null, 'title' => '', 'count' => 3],
            'series' => ['title' => '', 'count' => 3],
            'text' => ['content' => []],
            default => [],
        };

        return ['type' => $section['type'] ?? null, 'visible' => (bool) ($section['visible'] ?? true)] + array_replace($defaults, array_intersect_key($section, $defaults));
    }

    /**
     * Normalizes sections sent by the panel and rejects anything the home page could not paint.
     *
     * @param  array<int|string, mixed>  $sections
     * @return array<int, array<string, mixed>>
     */
    public function validate(array $sections): array
    {
        $sections = array_values($sections);
        Validator::make(['sections' => $sections], [
            'sections' => ['array', 'max:30'],
            'sections.*' => ['array'],
            'sections.*.type' => ['required', Rule::in(self::TYPES)],
        ], [], ['sections.*.type' => 'tipo de sección'])->validate();
        foreach (['welcome', 'featured', 'latest', 'ad'] as $single) {
            if (count(array_filter($sections, fn (array $section): bool => $section['type'] === $single)) > 1) {
                throw ValidationException::withMessages(['sections' => $single === 'latest'
                    ? 'La portada solo puede tener una sección «Últimas entradas», porque es la que lleva las páginas numeradas.'
                    : 'La portada solo puede tener una sección «'.self::LABELS[$single].'».']);
            }
        }

        $normalized = [];
        foreach ($sections as $index => $section) {
            $section = $this->withDefaults($section);
            $number = $index + 1;
            $rules = match ($section['type']) {
                'welcome' => ['heading' => 'nullable|string|max:180', 'accent_heading' => 'nullable|string|max:180', 'intro' => 'nullable|string|max:1500',
                    'icon' => ['required', Rule::in(array_keys(Page::iconOptions()))], 'show_heading' => 'boolean', 'show_intro' => 'boolean'],
                'featured' => ['post_id' => 'nullable|integer|exists:posts,id', 'fallback_latest' => 'boolean'],
                'latest' => ['title' => 'nullable|string|max:180', 'per_page' => ['required', Rule::in(self::PER_PAGE)], 'show_search' => 'boolean', 'show_filter' => 'boolean'],
                'topic' => ['category_id' => 'required|integer|exists:categories,id', 'title' => 'nullable|string|max:180', 'count' => ['required', Rule::in([3, 6, 9])]],
                'series' => ['title' => 'nullable|string|max:180', 'count' => ['required', Rule::in([3, 6])]],
                'text' => ['content' => 'array'],
                default => [],
            };
            $validator = Validator::make($section, $rules + ['visible' => 'boolean']);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['sections' => 'Sección '.$number.' ('.self::LABELS[$section['type']].'): '.$validator->errors()->first()]);
            }
            foreach (['per_page', 'count', 'post_id', 'category_id'] as $key) {
                if (isset($section[$key])) {
                    $section[$key] = (int) $section[$key];
                }
            }
            if ($section['type'] === 'text') {
                try {
                    $section['content'] = app(ContentDocument::class)->toBlocks($section['content']);
                } catch (ValidationException $error) {
                    throw ValidationException::withMessages(['sections' => 'Sección '.$number.' (Texto libre): '.collect($error->errors())->flatten()->first()]);
                }
            }
            $normalized[] = $section;
        }

        return $normalized;
    }

    /**
     * Library files used by the free-text sections.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, int>
     */
    public function mediaIds(array $sections): array
    {
        $ids = [];
        foreach ($sections as $section) {
            if (($section['type'] ?? null) === 'text' && is_array($section['content'] ?? null)) {
                array_push($ids, ...app(ContentSchema::class)->mediaIds($section['content']));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Builder items from the panel ({type, data}) to stored sections ({type, visible, ...options}).
     *
     * @param  array<int|string, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    public function fromBuilder(array $items): array
    {
        return array_values(array_map(fn ($item): array => is_array($item) ? ['type' => $item['type'] ?? null] + (is_array($item['data'] ?? null) ? $item['data'] : []) : [], $items));
    }

    /**
     * Stored sections to Builder items for the panel form.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, array{type: string, data: array<string, mixed>}>
     */
    public function toBuilder(array $sections): array
    {
        $items = [];
        foreach ($sections as $section) {
            $items[(string) str()->uuid()] = ['type' => $section['type'], 'data' => array_diff_key($section, ['type' => true])];
        }

        return $items;
    }

    /** The category a topic section lists, or null when it no longer exists. */
    public function category(array $section): ?Category
    {
        return filled($section['category_id'] ?? null) ? Category::find($section['category_id']) : null;
    }
}
