<?php

namespace App\Forms\Editor;

use App\Filament\Resources\Posts\PostResource;
use Filament\Forms\Components as Forms;

class ImageBlock extends ContentBlock
{
    public const TYPE = 'image';

    public static function label(): string
    {
        return 'Imagen';
    }

    public static function icon(): string
    {
        return 'heroicon-o-photo';
    }

    public static function description(): string
    {
        return 'Biblioteca o subir una imagen';
    }

    public static function schema(): array
    {
        return [
            PostResource::mediaSelect(images: true)->required(),
            Forms\Textarea::make('caption')->label('Pie de imagen')->maxLength(500),
        ];
    }

    public static function rules(): array
    {
        return ['media_id' => 'required|integer|exists:media,id', 'caption' => 'nullable|string|max:500', 'label' => 'nullable|string|max:180'];
    }
}
