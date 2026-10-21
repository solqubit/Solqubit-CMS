<?php

namespace App\Services;

use App\Forms\Editor;
use App\Forms\Editor\ContentBlock;

/** The registry of editor blocks, in the order they appear in menus. */
class EditorBlocks
{
    private const BLOCKS = [
        Editor\CodeBlock::class, Editor\ImageBlock::class, Editor\VideoBlock::class,
        Editor\MediaVideoBlock::class, Editor\FileBlock::class, Editor\TableBlock::class, Editor\NoticeBlock::class,
        Editor\ListBlock::class, Editor\DetailsBlock::class, Editor\LinksBlock::class,
    ];

    /** @return array<string, class-string<ContentBlock>> */
    public static function all(): array
    {
        $blocks = [];
        foreach (self::BLOCKS as $block) {
            $blocks[$block::TYPE] = $block;
        }

        return $blocks;
    }

    /** Formatting the editor does natively, as [label, detail, icon]. */
    private const NATIVE = [
        'paragraph' => ['Párrafo', 'Texto normal', 'heroicon-o-bars-3-bottom-left'],
        'h2' => ['Título', 'Sección del contenido', 'heroicon-o-h2'],
        'h3' => ['Subtítulo', 'Subsección', 'heroicon-o-h3'],
        'bulletList' => ['Lista', 'Viñetas', 'heroicon-o-list-bullet'],
        'orderedList' => ['Lista numerada', 'Pasos en orden', 'heroicon-o-numbered-list'],
        'blockquote' => ['Cita', 'Texto destacado', 'heroicon-o-chat-bubble-bottom-center-text'],
        'horizontalRule' => ['Separador', 'Dividir el contenido', 'heroicon-o-minus'],
    ];

    /** The sections of the block panel and the commands in each, in order. */
    private const SECTIONS = [
        'Texto' => ['paragraph', 'h2', 'h3', 'bulletList', 'orderedList', 'blockquote'],
        'Multimedia' => ['sq_image', 'sq_video', 'sq_media_video', 'sq_file'],
        'Destacados' => ['sq_notice', 'sq_list', 'sq_details', 'sq_links', 'sq_table', 'sq_code'],
        'Diseño' => ['horizontalRule'],
    ];

    /**
     * Commands of the "/" menu as [label, detail, command], the format the editor script reads.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function commands(): array
    {
        $commands = [];
        foreach (self::inserter() as $items) {
            foreach ($items as $item) {
                $commands[] = [$item['label'], $item['description'], $item['command']];
            }
        }

        return $commands;
    }

    /**
     * The block panel: its sections with a card per block.
     *
     * @return array<string, array<int, array{command: string, label: string, description: string, icon: string}>>
     */
    public static function inserter(): array
    {
        $blocks = self::all();
        $panel = [];
        foreach (self::SECTIONS as $section => $commands) {
            foreach ($commands as $command) {
                $block = $blocks[substr($command, 3)] ?? null;
                [$label, $description, $icon] = $block ? [$block::label(), $block::description(), $block::icon()] : self::NATIVE[$command];
                $panel[$section][] = ['command' => $command, 'label' => $label, 'description' => $description, 'icon' => $icon];
            }
        }

        return $panel;
    }
}
