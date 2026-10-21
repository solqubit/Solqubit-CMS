<?php

namespace App\Forms\Editor;

use App\Services\EditorPreview;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Schemas\Components\Component;

/** One editor block: its name, icon, form, validation rules and initial values live here and nowhere else. */
abstract class ContentBlock extends RichContentCustomBlock
{
    public const TYPE = '';

    abstract public static function label(): string;

    abstract public static function icon(): string;

    /** Short hint shown next to the label in the insert menu. */
    abstract public static function description(): string;

    /** @return array<int, Component> */
    abstract public static function schema(): array;

    /** @return array<string, mixed> */
    abstract public static function rules(): array;

    /**
     * The configuration inserted directly in the document, or null to open the form first.
     *
     * @return array<string, mixed>|null
     */
    public static function defaults(): ?array
    {
        return null;
    }

    public static function getId(): string
    {
        return static::TYPE;
    }

    public static function getLabel(): string
    {
        return static::label();
    }

    public static function getIcon(): string
    {
        return static::icon();
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action->modalHeading(static::label())->modalSubmitActionLabel('Aplicar')->schema(static::schema());
    }

    public static function toPreviewHtml(array $config): ?string
    {
        return app(EditorPreview::class)->block(static::TYPE, $config);
    }
}
