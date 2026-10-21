<?php

namespace App\Forms\Editor;

use Filament\Forms\Components as Forms;

/** Only the code: readers get it with a «Copiar» button, without title or language. */
class CodeBlock extends ContentBlock
{
    public const TYPE = 'code';

    public static function label(): string
    {
        return 'Código';
    }

    public static function icon(): string
    {
        return 'heroicon-o-code-bracket';
    }

    public static function description(): string
    {
        return 'Comandos y fragmentos';
    }

    public static function schema(): array
    {
        return [
            Forms\Textarea::make('text')->label('Código')->required()->rows(6)->extraInputAttributes(['class' => 'font-mono']),
        ];
    }

    /** Older code blocks may still carry a label or language; they are accepted and dropped. */
    public static function rules(): array
    {
        return ['text' => 'required|string|max:100000', 'label' => 'nullable|string|max:100', 'language' => 'nullable|string|max:30'];
    }

    public static function defaults(): ?array
    {
        return ['text' => ''];
    }
}
