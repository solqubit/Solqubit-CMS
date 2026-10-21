<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Models\Setting;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** The site's time zone, used for scheduling and for every date on the site and in the panel. */
class GeneralSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-americas';

    protected static ?string $navigationLabel = 'General';

    protected static ?string $title = 'General';

    protected static string|\UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'general';

    protected string $view = 'filament.pages.general-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['timezone' => Setting::read('site', [])['timezone'] ?? 'UTC']);
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Zona horaria')->description('Se usa al programar entradas y para mostrar las fechas en el sitio y en este panel.')->schema([
                Forms\Select::make('timezone')->label('Zona horaria')->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))->searchable()->required()->helperText('Busca tu ciudad o la zona horaria de tu país.'),
            ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        Setting::write('site', array_replace(Setting::read('site', []), ['timezone' => $data['timezone']]));
        Notification::make()->title('Zona horaria guardada')->success()->send();
        $this->rememberData();
    }
}
