<?php

namespace App\Forms\Editor;

use Filament\Forms\Components as Forms;

class TableBlock extends ContentBlock
{
    public const TYPE = 'table';

    public static function label(): string
    {
        return 'Tabla';
    }

    public static function icon(): string
    {
        return 'heroicon-o-table-cells';
    }

    public static function description(): string
    {
        return 'Filas y columnas';
    }

    public static function schema(): array
    {
        return [
            Forms\TagsInput::make('columns')->label('Encabezados')->required()->helperText('Pulsa Enter para añadir cada columna.'),
            Forms\Repeater::make('rows')->label('Filas')->schema([Forms\TextInput::make('cells')->label('Celdas separadas por |')->required()])->addActionLabel('Añadir fila')->required(),
        ];
    }

    public static function rules(): array
    {
        return ['columns' => 'required|array|min:1|max:12', 'columns.*' => 'string|max:120', 'rows' => 'present|array|max:200', 'rows.*.cells' => 'present|string|max:4000'];
    }
}
