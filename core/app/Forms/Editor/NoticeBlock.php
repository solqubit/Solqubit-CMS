<?php

namespace App\Forms\Editor;

use Filament\Forms\Components as Forms;
use Illuminate\Validation\Rule;

class NoticeBlock extends ContentBlock
{
    public const TYPE = 'notice';

    public static function label(): string
    {
        return 'Aviso';
    }

    public static function icon(): string
    {
        return 'heroicon-o-information-circle';
    }

    public static function description(): string
    {
        return 'Información, nota o advertencia';
    }

    public static function schema(): array
    {
        return [
            Forms\TextInput::make('title')->label('Título'),
            Forms\ToggleButtons::make('tone')->label('Color y tipo de aviso')->options(['info' => 'Azul · Información', 'note' => 'Lavanda · Nota', 'warning' => 'Ámbar · Advertencia'])
                ->colors(['info' => 'info', 'note' => 'primary', 'warning' => 'warning'])->icons(['info' => 'heroicon-o-information-circle', 'note' => 'heroicon-o-light-bulb', 'warning' => 'heroicon-o-exclamation-triangle'])
                ->inline()->default('info')->required()->helperText('Puedes cambiarlo después con el botón de editar del aviso. Los tonos se adaptan al modo claro y oscuro.'),
            Forms\Textarea::make('text')->label('Texto')->required()->rows(5),
        ];
    }

    public static function rules(): array
    {
        return ['title' => 'nullable|string|max:180', 'text' => 'required|string|max:10000', 'tone' => ['required', Rule::in(['info', 'warning', 'note'])]];
    }

    public static function defaults(): ?array
    {
        return ['title' => '', 'text' => '', 'tone' => 'info'];
    }
}
