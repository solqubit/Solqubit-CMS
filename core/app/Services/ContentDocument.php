<?php

namespace App\Services;

use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Validation\ValidationException;

class ContentDocument
{
    public function toDocument(array $blocks): array
    {
        if (($blocks['type'] ?? null) === 'doc') {
            return $blocks;
        }
        $nodes = [];
        foreach ($blocks as $block) {
            $data = $block['data'];
            if ($block['type'] === 'paragraph') {
                $document = RichContentRenderer::make()->getEditor()->setContent(app(ContentSchema::class)->sanitize($data['html']))->getDocument();
                array_push($nodes, ...($document['content'] ?? []));
            } elseif ($block['type'] === 'heading') {
                $nodes[] = ['type' => 'heading', 'attrs' => ['level' => $data['level'] === 'h3' ? 3 : 2], 'content' => [['type' => 'text', 'text' => $data['text']]]];
            } elseif ($block['type'] === 'divider') {
                $nodes[] = ['type' => 'horizontalRule'];
            } elseif ($block['type'] === 'table') {
                $cell = fn (string $text, string $type): array => ['type' => $type, 'content' => [['type' => 'paragraph', 'content' => $text !== '' ? [['type' => 'text', 'text' => $text]] : []]]];
                $rows = [['type' => 'tableRow', 'content' => array_map(fn ($text): array => $cell((string) $text, 'tableHeader'), $data['columns'])]];
                foreach ($data['rows'] as $row) {
                    $values = array_map('trim', explode('|', $row['cells'] ?? ''));
                    $rows[] = ['type' => 'tableRow', 'content' => array_map(fn ($text): array => $cell((string) $text, 'tableCell'), array_pad($values, count($data['columns']), ''))];
                }
                $nodes[] = ['type' => 'table', 'content' => $rows];
            } elseif (in_array($block['type'], ['code', 'output'], true)) {
                $nodes[] = ['type' => 'customBlock', 'attrs' => ['id' => 'code', 'config' => ['text' => (string) ($data['text'] ?? '')]]];
            } else {
                $nodes[] = ['type' => 'customBlock', 'attrs' => ['id' => $block['type'], 'config' => $data]];
            }
        }
        if (! $nodes || end($nodes)['type'] !== 'paragraph') {
            $nodes[] = ['type' => 'paragraph'];
        }

        return ['type' => 'doc', 'content' => $nodes];
    }

    public function toBlocks(array $document): array
    {
        if (($document['type'] ?? null) !== 'doc') {
            return app(ContentSchema::class)->validate($document);
        }
        $count = 0;
        $this->checkNodes($document, $count);
        $blocks = [];
        foreach ($document['content'] ?? [] as $index => $node) {
            $type = $node['type'];
            if ($type === 'customBlock') {
                $id = $node['attrs']['id'] ?? '';
                $data = $node['attrs']['config'] ?? [];
                if (! is_array($data)) {
                    throw ValidationException::withMessages(['content' => 'El elemento no tiene un formato válido.']);
                }
                if (in_array($id, ['notice', 'details', 'code', 'output'], true) && blank($data['text'] ?? '') && (! in_array($id, ['notice', 'details'], true) || blank($data['title'] ?? ''))) {
                    continue;
                }
                if (in_array($id, ['list', 'links'], true) && is_array($data['items'] ?? null)) {
                    $data['items'] = array_values(array_filter($data['items'], fn ($item): bool => ! is_array($item) || filled($item['text'] ?? '') || filled($item['label'] ?? '') || filled($item['url'] ?? '')));
                    if (! $data['items'] && blank($data['title'] ?? '')) {
                        continue;
                    }
                }
                $blocks[] = ['type' => $id, 'data' => $data];
            } elseif ($type === 'heading') {
                if (blank($this->text($node))) {
                    continue;
                }
                $blocks[] = ['type' => 'heading', 'data' => ['text' => $this->text($node), 'level' => ($node['attrs']['level'] ?? 2) === 3 ? 'h3' : 'h2']];
            } elseif ($type === 'horizontalRule') {
                $blocks[] = ['type' => 'divider', 'data' => []];
            } elseif ($type === 'codeBlock') {
                $blocks[] = ['type' => 'code', 'data' => ['text' => $this->text($node), 'label' => 'Código']];
            } elseif ($type === 'table') {
                if (blank($this->text($node))) {
                    continue;
                }
                $rows = $node['content'] ?? [];
                $columns = array_map(fn (array $cell): string => $this->text($cell), array_shift($rows)['content'] ?? []);
                $blocks[] = ['type' => 'table', 'data' => ['columns' => $columns, 'rows' => array_map(fn (array $row): array => ['cells' => implode(' | ', array_map(fn (array $cell): string => str_replace('|', '¦', $this->text($cell)), $row['content'] ?? []))], $rows)]];
            } else {
                $html = RichContentRenderer::make()->getEditor()->setContent(['type' => 'doc', 'content' => [$node]])->getHtml();
                if ($index === array_key_last($document['content'] ?? []) && in_array($html, ['<p></p>', '<p><br></p>'], true)) {
                    continue;
                }
                $blocks[] = ['type' => 'paragraph', 'data' => ['html' => $html]];
            }
        }

        return app(ContentSchema::class)->validate($blocks);
    }

    private function text(array $node): string
    {
        if ($node['type'] === 'hardBreak') {
            return "\n";
        }

        return ($node['text'] ?? '').implode('', array_map(fn (array $child): string => $this->text($child), $node['content'] ?? []));
    }

    private function checkNodes(array $node, int &$count, int $depth = 0): void
    {
        $allowed = ['doc', 'paragraph', 'text', 'heading', 'hardBreak', 'bulletList', 'orderedList', 'listItem', 'blockquote', 'codeBlock', 'horizontalRule', 'table', 'tableRow', 'tableHeader', 'tableCell', 'customBlock'];
        if (++$count > 5000 || $depth > 20 || ! in_array($node['type'] ?? '', $allowed, true) || (isset($node['text']) && ! is_string($node['text'])) || (isset($node['content']) && ! is_array($node['content']))) {
            throw ValidationException::withMessages(['content' => 'El contenido incluye un elemento no compatible o supera el tamaño permitido.']);
        }
        $this->checkAttributes($node['attrs'] ?? [], $node['type'] === 'customBlock');
        $marks = $node['marks'] ?? [];
        if (! is_array($marks) || count($marks) > 20) {
            throw ValidationException::withMessages(['content' => 'El formato del texto no es válido.']);
        }
        foreach ($marks as $mark) {
            if (! is_array($mark) || ! in_array($mark['type'] ?? '', ['bold', 'italic', 'underline', 'strike', 'code', 'link', 'highlight', 'subscript', 'superscript', 'textStyle'], true)) {
                throw ValidationException::withMessages(['content' => 'El formato del texto no es compatible.']);
            }
            $this->checkAttributes($mark['attrs'] ?? []);
        }
        foreach ($node['content'] ?? [] as $child) {
            if (! is_array($child)) {
                throw ValidationException::withMessages(['content' => 'El documento no tiene un formato válido.']);
            }
            $this->checkNodes($child, $count, $depth + 1);
        }
    }

    private function checkAttributes(mixed $attributes, bool $customBlock = false): void
    {
        if (! is_array($attributes)) {
            throw ValidationException::withMessages(['content' => 'Las propiedades del documento no tienen un formato válido.']);
        }
        foreach ($attributes as $key => $value) {
            if ($customBlock && $key === 'config' && is_array($value)) {
                continue;
            }
            if ($key === 'colwidth' && is_array($value) && count($value) <= 12 && collect($value)->every(fn ($width): bool => is_int($width) && $width >= 0)) {
                continue;
            }
            if ($value !== null && ! is_scalar($value)) {
                throw ValidationException::withMessages(['content' => 'Las propiedades del documento no tienen un formato válido.']);
            }
        }
    }
}
