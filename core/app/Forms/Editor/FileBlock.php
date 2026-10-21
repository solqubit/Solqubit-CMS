<?php

namespace App\Forms\Editor;

use App\Filament\Resources\Posts\PostResource;
use Filament\Forms\Components as Forms;

class FileBlock extends ContentBlock
{
    public const TYPE = 'file';

    public static function label(): string
    {
        return 'Archivo';
    }

    public static function icon(): string
    {
        return 'heroicon-o-paper-clip';
    }

    public static function description(): string
    {
        return 'Descarga para tus lectores';
    }

    public static function schema(): array
    {
        return [
            PostResource::mediaSelect()->required(),
            Forms\TextInput::make('label')->label('Nombre visible')->maxLength(180),
            Forms\Textarea::make('caption')->label('Descripción')->maxLength(500),
        ];
    }

    public static function rules(): array
    {
        return ['media_id' => 'required|integer|exists:media,id', 'caption' => 'nullable|string|max:500', 'label' => 'nullable|string|max:180'];
    }
}
