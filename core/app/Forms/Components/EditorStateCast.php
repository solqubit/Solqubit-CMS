<?php

namespace App\Forms\Components;

use App\Services\ContentDocument;
use Filament\Forms\Components\RichEditor\StateCasts\RichEditorStateCast;
use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

class EditorStateCast implements StateCast
{
    public function __construct(private ContentEditor $editor) {}

    public function get(mixed $state): array
    {
        return app(ContentDocument::class)->toBlocks($state ?? []);
    }

    public function set(mixed $state): array
    {
        return (new RichEditorStateCast($this->editor))->set(app(ContentDocument::class)->toDocument($state ?? []));
    }
}
