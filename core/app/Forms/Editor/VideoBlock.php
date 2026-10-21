<?php

namespace App\Forms\Editor;

use Filament\Forms\Components as Forms;

class VideoBlock extends ContentBlock
{
    public const TYPE = 'video';

    public static function label(): string
    {
        return 'YouTube';
    }

    public static function icon(): string
    {
        return 'heroicon-o-play-circle';
    }

    public static function description(): string
    {
        return 'Video por enlace';
    }

    public static function schema(): array
    {
        return [
            Forms\TextInput::make('url')->label('Enlace de YouTube')->url()->required(),
            Forms\TextInput::make('caption')->label('Título y crédito del video')->maxLength(500),
        ];
    }

    public static function rules(): array
    {
        return ['url' => 'required|url:http,https|max:255', 'caption' => 'nullable|string|max:500'];
    }
}
