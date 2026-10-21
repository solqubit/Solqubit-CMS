<?php

namespace App\Forms\Components;

use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;

class EditorPlugin implements RichContentPlugin
{
    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    public function getTipTapJsExtensions(): array
    {
        $version = max(filemtime(public_path('editor/solqubit-editor.js')), filemtime(public_path('editor/document-tools.js')));

        return [asset('editor/solqubit-editor.js').'?v='.$version];
    }

    public function getEditorTools(): array
    {
        return [];
    }

    public function getEditorActions(): array
    {
        return [];
    }
}
