<?php

namespace App\Forms\Editor;

use App\Filament\Resources\Posts\PostResource;
use Filament\Forms\Components as Forms;

class MediaVideoBlock extends ContentBlock
{
    public const TYPE = 'media_video';

    public static function label(): string
    {
        return 'Video propio';
    }

    public static function icon(): string
    {
        return 'heroicon-o-film';
    }

    public static function description(): string
    {
        return 'MP4 o WebM';
    }

    public static function schema(): array
    {
        return [
            PostResource::mediaSelect(videos: true)->label('Video MP4 o WebM')->required(),
            Forms\TextInput::make('caption')->label('Pie del video')->maxLength(500),
        ];
    }

    public static function rules(): array
    {
        return ['media_id' => 'required|integer|exists:media,id', 'caption' => 'nullable|string|max:500', 'label' => 'nullable|string|max:180'];
    }
}
