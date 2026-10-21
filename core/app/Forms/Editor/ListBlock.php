<?php

namespace App\Forms\Editor;

use Filament\Forms\Components as Forms;

class ListBlock extends ContentBlock
{
    public const TYPE = 'list';

    public static function label(): string
    {
        return 'Lista destacada';
    }

    public static function icon(): string
    {
        return 'heroicon-o-queue-list';
    }

    public static function description(): string
    {
        return 'Resumen informativo';
    }

    public static function schema(): array
    {
        return [
            Forms\TextInput::make('title')->label('Título'),
            Forms\Toggle::make('ordered')->label('Lista ordenada')->default(false),
            Forms\Repeater::make('items')->label('Elementos')->schema([Forms\Textarea::make('text')->label('Texto')->required()->rows(2)])->required()->addActionLabel('Añadir elemento'),
        ];
    }

    public static function rules(): array
    {
        return ['title' => 'nullable|string|max:180', 'items' => 'required|array|max:100', 'items.*.text' => 'required|string|max:2000', 'ordered' => 'boolean'];
    }

    public static function defaults(): ?array
    {
        return ['title' => '', 'ordered' => false, 'items' => [['text' => '']]];
    }
}
