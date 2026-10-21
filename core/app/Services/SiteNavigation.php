<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteNavigation
{
    private function key(string $location): string
    {
        return match ($location) {
            'header' => 'navigation',
            'footer' => 'navigation_footer',
            default => throw new \InvalidArgumentException('Ubicación de menú desconocida.'),
        };
    }

    public function entries(string $location = 'header'): array
    {
        $saved = $this->savedEntries($location);
        if (! $saved['saved']) {
            return $saved['entries'];
        }
        $pageIds = Page::withTrashed()->whereIn('id', array_column($saved['entries'], 'page_id'))->pluck('id');

        return array_values(array_filter($saved['entries'], fn (array $entry): bool => $entry['type'] !== 'page' || $pageIds->contains($entry['page_id'])));
    }

    /**
     * Saved entries without checking that their pages still exist; the footer falls back to the header.
     *
     * @return array{entries: list<array<string, mixed>>, saved: bool}
     */
    private function savedEntries(string $location): array
    {
        $saved = Setting::read($this->key($location));
        if ($location === 'footer' && ! is_array($saved)) {
            return ['entries' => [], 'saved' => false];
        }
        if (is_array($saved)) {
            return ['entries' => array_values($saved), 'saved' => true];
        }

        return ['entries' => Page::whereIn('kind', ['home', 'series', 'about'])->orderBy('id')->get(['id', 'kind', 'title'])->map(fn (Page $page): array => [
            'type' => 'page', 'page_id' => $page->id, 'label' => $page->kind === 'home' ? 'Blog' : $page->title,
            'enabled' => true, 'new_tab' => false,
        ])->all(), 'saved' => false];
    }

    public function save(array $entries, string $location = 'header'): void
    {
        $key = $this->key($location);
        $validated = Validator::make(['items' => array_values($entries)], [
            'items' => 'present|array|max:12', 'items.*.type' => ['required', Rule::in(['page', 'url'])],
            'items.*.label' => 'required|string|max:40', 'items.*.enabled' => 'required|boolean',
            'items.*.new_tab' => 'required|boolean',
            'items.*.page_id' => 'required_if:items.*.type,page|nullable|integer|exists:pages,id',
            'items.*.url' => 'required_if:items.*.type,url|nullable|url:http,https|max:2048',
        ])->validate();
        $items = array_map(fn (array $entry): array => [
            'type' => $entry['type'], 'label' => $entry['label'],
            'enabled' => (bool) $entry['enabled'], 'new_tab' => (bool) $entry['new_tab'],
        ] + ($entry['type'] === 'page' ? ['page_id' => (int) $entry['page_id']] : ['url' => $entry['url']]), $validated['items']);
        DB::transaction(function () use ($key, $items): void {
            Setting::write($key, $items);
        });
    }

    public function saveMenus(array $header, array $footer): void
    {
        DB::transaction(function () use ($header, $footer): void {
            $this->save($header, 'header');
            $this->save($footer, 'footer');
        });
    }

    public function contains(Page $page, string $location = 'header'): bool
    {
        return collect($this->entries($location))->contains(fn (array $item): bool => $item['type'] === 'page' && (int) ($item['page_id'] ?? 0) === $page->id && $item['enabled']);
    }

    public function setPage(Page $page, bool $visible, string $location = 'header'): void
    {
        $entries = $this->entries($location);
        $found = false;
        foreach ($entries as &$entry) {
            if ($entry['type'] === 'page' && (int) ($entry['page_id'] ?? 0) === $page->id) {
                $entry['enabled'] = $visible;
                $found = true;
            }
        }
        unset($entry);
        if ($visible && ! $found) {
            if (count($entries) >= 12) {
                $field = $location === 'header' ? 'in_menu' : 'in_footer';
                $name = $location === 'header' ? 'encabezado' : 'pie de página';
                throw ValidationException::withMessages(['data.'.$field => 'El '.$name.' ya tiene 12 enlaces. Retira uno en Menús o guarda esta página sin añadirla a esta ubicación.']);
            }
            $entries[] = ['type' => 'page', 'page_id' => $page->id, 'label' => mb_substr($page->title, 0, 40), 'enabled' => true, 'new_tab' => false];
        }
        $this->save($entries, $location);
    }

    public function links(string $location = 'header'): array
    {
        return $this->menus([$location])[$location];
    }

    /**
     * Public links for several menus, loading their published pages with a single query.
     *
     * @param  list<string>  $locations
     * @return array<string, list<array{label: string, url: string, active: bool, new_tab: bool}>>
     */
    public function menus(array $locations = ['header', 'footer']): array
    {
        $entries = [];
        foreach ($locations as $location) {
            $this->key($location);
            $entries[$location] = $this->savedEntries($location)['entries'];
        }
        $pageIds = array_values(array_unique(array_filter(array_merge(...array_map(fn (array $items): array => array_column($items, 'page_id'), array_values($entries))))));
        $pages = $pageIds ? Page::published()->whereIn('id', $pageIds)->get()->keyBy('id') : collect();

        return array_map(fn (array $items): array => $this->resolveLinks($items, $pages), $entries);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  Collection<int, Page>  $pages
     * @return list<array{label: string, url: string, active: bool, new_tab: bool}>
     */
    private function resolveLinks(array $entries, Collection $pages): array
    {
        $links = [];
        foreach ($entries as $entry) {
            if (! ($entry['enabled'] ?? false)) {
                continue;
            }
            if ($entry['type'] === 'page') {
                $page = $pages->get($entry['page_id'] ?? 0);
                if (! $page) {
                    continue;
                }
                $url = $page->url();
                $active = request()->url() === $url || ($page->kind === 'home' && request()->attributes->get('solqubit.post', false));
            } else {
                $url = $entry['url'] ?? '';
                if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) {
                    continue;
                }
                $active = false;
            }
            $links[] = ['label' => $entry['label'], 'url' => $url, 'active' => $active, 'new_tab' => (bool) ($entry['new_tab'] ?? false)];
        }

        return $links;
    }
}
