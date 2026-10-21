<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class ContentSchema
{
    public const TYPES = ['heading', 'paragraph', 'code', 'image', 'video', 'media_video', 'file', 'table', 'notice', 'list', 'details', 'links', 'divider'];

    /** Block types that no longer exist, and the type their content becomes. */
    private const RETIRED = ['output' => 'code'];

    public function validate(array $blocks, int $version = 1): array
    {
        if ($version !== 1) {
            throw ValidationException::withMessages(['content' => 'Esta versión de contenido necesita una versión más reciente de Solqubit. No se ha modificado.']);
        }
        Validator::make(['content' => $blocks], [
            'content' => ['array', 'max:200'], 'content.*' => ['array:type,data'],
            'content.*.type' => ['required', Rule::in([...self::TYPES, ...array_keys(self::RETIRED)])], 'content.*.data' => ['present', 'array'],
        ])->validate();
        foreach ($blocks as $index => &$block) {
            if (isset(self::RETIRED[$block['type']])) {
                $block = ['type' => self::RETIRED[$block['type']], 'data' => $block['data']];
            }
            $rules = match ($block['type']) {
                'heading' => ['text' => 'required|string|max:180', 'level' => ['required', Rule::in(['h2', 'h3'])]],
                'paragraph' => ['html' => 'required|string|max:100000'],
                'divider' => [],
                default => EditorBlocks::all()[$block['type']]::rules(),
            };
            $validator = Validator::make($block['data'], $rules);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['content' => 'Bloque '.($index + 1).': '.$validator->errors()->first()]);
            }
            if ($block['type'] === 'code') {
                $block['data'] = ['text' => $block['data']['text']];
            }
            if ($block['type'] === 'paragraph') {
                $block['data']['html'] = $this->sanitize($block['data']['html']);
            }
            if ($block['type'] === 'image' && ! Media::find($block['data']['media_id'])->isImage()) {
                throw ValidationException::withMessages(['content' => 'Selecciona una imagen válida en el bloque '.($index + 1).'.']);
            }
            if ($block['type'] === 'media_video' && ! Media::find($block['data']['media_id'])->isVideo()) {
                throw ValidationException::withMessages(['content' => 'Selecciona un video MP4 o WebM en el bloque '.($index + 1).'.']);
            }
            if ($block['type'] === 'video' && ! $this->youtubeId($block['data']['url'])) {
                throw ValidationException::withMessages(['content' => 'El bloque de video admite enlaces de YouTube.']);
            }
        }

        return array_values($blocks);
    }

    public function sanitize(string $html): string
    {
        if (strlen($html) > 400000) {
            throw ValidationException::withMessages(['content' => 'Este bloque de texto es demasiado largo. Divídelo en varios párrafos.']);
        }
        $config = (new HtmlSanitizerConfig)->withMaxInputLength(400000);
        foreach (['p', 'strong', 'b', 'em', 'i', 'u', 's', 'br', 'code', 'blockquote', 'ul', 'li', 'h2', 'h3', 'h4'] as $element) {
            $config = $config->allowElement($element);
        }
        $config = $config->allowElement('ol', ['start']);
        $config = $config->allowElement('a', ['href', 'title'])->allowLinkSchemes(['https', 'http', 'mailto'])->forceAttribute('a', 'rel', 'noopener noreferrer');

        return (new HtmlSanitizer($config))->sanitize($html);
    }

    public function inline(string $text): string
    {
        return $this->sanitize(Str::inlineMarkdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }

    public function youtubeId(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $id = null;
        if ($host === 'youtu.be') {
            $id = trim($path, '/');
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com'], true)) {
            parse_str($parts['query'] ?? '', $query);
            $id = $path === '/watch' ? ($query['v'] ?? null) : (preg_match('~^/(?:embed|shorts)/([^/]+)$~', $path, $match) ? $match[1] : null);
        }

        return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{11}$/', $id) ? $id : null;
    }

    public function mediaIds(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (in_array($block['type'] ?? '', ['image', 'file', 'media_video'], true) && isset($block['data']['media_id'])) {
                $ids[] = (int) $block['data']['media_id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
