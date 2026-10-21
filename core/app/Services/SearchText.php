<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Builds the plain, accent-free text stored in posts.search_text so public search matches what readers see
 * (never block type names or JSON keys) and works the same on MySQL/MariaDB and SQLite.
 */
class SearchText
{
    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function forPost(?string $title, ?string $excerpt, array $blocks): string
    {
        $parts = [$title ?? '', $excerpt ?? ''];
        foreach ($blocks as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $parts[] = match ($block['type'] ?? '') {
                'paragraph' => $this->plain($data['html'] ?? ''),
                'heading' => $data['text'] ?? '',
                'code', 'output' => [$data['label'] ?? '', $data['text'] ?? ''],
                'image', 'file', 'media_video' => [$data['label'] ?? '', $data['caption'] ?? ''],
                'video' => $data['caption'] ?? '',
                'table' => [...($data['columns'] ?? []), ...array_column($data['rows'] ?? [], 'cells')],
                'notice', 'details' => [$data['title'] ?? '', $this->markdown($data['text'] ?? '')],
                'list' => [$data['title'] ?? '', ...array_map(fn (mixed $text): string => $this->markdown($text), array_column($data['items'] ?? [], 'text'))],
                'links' => array_column($data['items'] ?? [], 'label'),
                default => '',
            };
        }

        return $this->normalize(implode(' ', array_map(fn (mixed $part): string => is_array($part) ? implode(' ', array_filter($part, 'is_string')) : (is_string($part) ? $part : ''), $parts)));
    }

    /**
     * Lowercase ASCII without repeated spaces: "Canción ÚNICA" becomes "cancion unica".
     */
    public function normalize(string $text): string
    {
        return Str::squish(Str::lower(Str::ascii($text)));
    }

    private function markdown(mixed $text): string
    {
        return is_string($text) && $text !== '' ? $this->plain(app(ContentSchema::class)->inline($text)) : '';
    }

    private function plain(string $html): string
    {
        return html_entity_decode(strip_tags(preg_replace('~<(?:/p|/li|/h[1-6]|/blockquote|br\s*/?)>~i', '$0 ', $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
