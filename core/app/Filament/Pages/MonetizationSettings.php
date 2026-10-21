<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Services\Monetization;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class MonetizationSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Monetización';

    protected static ?string $title = 'Monetización';

    protected static string|\UnitEnum|null $navigationGroup = 'Publicación';

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'monetizacion';

    protected string $view = 'filament.pages.monetization-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(app(Monetization::class)->configuration());
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('1. Conecta tu cuenta')->description('Compatible con Google AdSense. Puedes guardar los datos ahora y activarlos más adelante.')->schema([
                Forms\Select::make('status')->label('Estado de la monetización')->options(Monetization::STATUSES)->required()->selectablePlaceholder(false)->live()
                    ->afterStateUpdated(fn (Set $set) => $set('privacy_ready', false))
                    ->helperText(fn (Get $get): string => match ($get('status')) {
                        'verification' => 'Publica la etiqueta de verificación y ads.txt. Todavía no carga anuncios ni conecta al visitante con Google.',
                        'active' => 'Al guardar, podrán mostrarse anuncios en Inicio y en las entradas publicadas.',
                        default => 'No se carga publicidad, no se publica ads.txt y no se reservan espacios. Tus datos se conservan.',
                    }),
                Forms\TextInput::make('publisher_id')->label('Identificador de tu cuenta')->placeholder('ca-pub-1234567890123456')->maxLength(32)
                    ->required(fn (Get $get): bool => $get('status') !== 'off')->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => $set('privacy_ready', false))
                    ->helperText('Está en AdSense → Cuenta → Información de la cuenta. Acepta ca-pub-, pub- o los 16 números; no pegues el script.'),
            ])->columns(2),
            Section::make('2. Elige cómo se muestran')->collapsible()->collapsed(fn (Get $get): bool => $get('status') === 'off')->description('Las páginas informativas, el administrador y las vistas previas quedan fuera de la publicidad.')->schema([
                Forms\Select::make('format')->label('Ubicación de los anuncios')->options([
                    'manual' => 'Ubicaciones del blog · tú eliges',
                    'automatic' => 'Anuncios automáticos · Google decide',
                ])->required()->selectablePlaceholder(false)->live()->helperText(fn (Get $get): string => $get('format') === 'automatic'
                    ? 'Activa también Anuncios automáticos en AdSense. Su ubicación y cantidad se controlan desde Google.'
                    : 'Crea bloques de tipo Display adaptables en AdSense y copia su identificador data-ad-slot. Deja vacías las zonas que no quieras usar. Desactiva Anuncios automáticos en Google si quieres conservar solo estas ubicaciones.'),
                ...array_map(fn (string $key, string $label) => Forms\TextInput::make($key)->label($label)->placeholder('Identificador del bloque · opcional')
                    ->maxLength(20)->inputMode('numeric')->visible(fn (Get $get): bool => $get('format') === 'manual')->dehydratedWhenHidden(), array_keys(Monetization::PLACEMENTS), array_values(Monetization::PLACEMENTS)),
            ]),
            Section::make('3. Antes de mostrar anuncios')->visible(fn (Get $get): bool => $get('status') === 'active')->schema([
                Forms\Checkbox::make('privacy_ready')->label('Mi sitio está aprobado en AdSense y he preparado la privacidad y el consentimiento correspondientes a mi audiencia.')
                    ->helperText('Configura la CMP en AdSense → Privacidad y mensajes. Este CMS integra el código de anuncios; no sustituye una plataforma de consentimiento ni comprueba la aprobación de Google.')
                    ->dehydratedWhenHidden(),
            ]),
        ])->statePath('data');
    }

    protected function getViewData(): array
    {
        $status = app(Monetization::class)->configuration()['status'];

        return ['savedStatus' => Monetization::STATUSES[$status] ?? Monetization::STATUSES['off'], 'adsTxtAvailable' => app(Monetization::class)->adsTxt() !== null];
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        app(Monetization::class)->save($this->form->getState());
        $this->form->fill(app(Monetization::class)->configuration());
        Notification::make()->title('Monetización guardada')->body(Monetization::STATUSES[$this->data['status']])->success()->send();
        $this->rememberData();
    }
}
