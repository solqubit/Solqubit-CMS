<?php

namespace App\Forms\Editor;

use Filament\Forms\Components as Forms;

class DetailsBlock extends ContentBlock
{
    public const TYPE = 'details';

    public static function label(): string
    {
        return 'Desplegable';
    }

    public static function icon(): string
    {
        return 'heroicon-o-chevron-down';
    }

    public static function description(): string
    {
        return 'Pregunta y respuesta';
    }

    public static function schema(): array
    {
        return [
            Forms\TextInput::make('title')->label('Pregunta')->required(),
            Forms\Textarea::make('text')->label('Respuesta')->required()->rows(5),
        ];
    }

    public static function rules(): array
    {
        return ['title' => 'required|string|max:180', 'text' => 'required|string|max:10000'];
    }

    public static function defaults(): ?array
    {
        return ['title' => '', 'text' => ''];
    }
}
