<?php

namespace App\Forms\Editor;

use Filament\Forms\Components as Forms;

class LinksBlock extends ContentBlock
{
    public const TYPE = 'links';

    public static function label(): string
    {
        return 'Enlaces';
    }

    public static function icon(): string
    {
        return 'heroicon-o-link';
    }

    public static function description(): string
    {
        return 'Referencias útiles';
    }

    public static function schema(): array
    {
        return [
            Forms\Repeater::make('items')->label('Enlaces')->schema([Forms\TextInput::make('label')->label('Texto')->required(), Forms\TextInput::make('url')->label('Dirección')->url()->required()])->required(),
        ];
    }

    public static function rules(): array
    {
        return ['items' => 'required|array|max:50', 'items.*.label' => 'required|string|max:180', 'items.*.url' => 'required|url:http,https|max:2048'];
    }

    public static function defaults(): ?array
    {
        return ['items' => [['label' => '', 'url' => '']]];
    }
}
