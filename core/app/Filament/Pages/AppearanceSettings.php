<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Services\ColorPalettes;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AppearanceSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $navigationLabel = 'Colores';

    protected static ?string $title = 'Variantes de color';

    protected static string|\UnitEnum|null $navigationGroup = 'Sitio';

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'colores';

    protected string $view = 'filament.pages.appearance-settings';

    public string $variant = 'default';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->variant = app(ColorPalettes::class)->activeId();
    }

    protected function getViewData(): array
    {
        $colors = app(ColorPalettes::class);

        return ['variants' => $colors->all(), 'activeVariant' => $colors->activeId()];
    }

    public function activate(): void
    {
        abort_unless(static::canAccess(), 403);
        app(ColorPalettes::class)->activate($this->variant);
        $this->resetValidation('variant');
        Notification::make()->title('Variante de color aplicada')->body('Tu sitio ya utiliza '.app(ColorPalettes::class)->all()[$this->variant]['name'].' en los modos claro y oscuro.')->success()->send();
    }
}
