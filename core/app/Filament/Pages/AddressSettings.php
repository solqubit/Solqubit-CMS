<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Services\PostPermalinks;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** The URL prefixes a post may use and the default for new posts. */
class AddressSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Direcciones';

    protected static ?string $title = 'Direcciones de las entradas';

    protected static string|\UnitEnum|null $navigationGroup = 'Publicación';

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'direcciones';

    protected string $view = 'filament.pages.address-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $permalinks = app(PostPermalinks::class);
        $this->form->fill([
            'post_prefixes' => $permalinks->configured(),
            'default_post_prefix' => $permalinks->defaultPrefix() !== '' ? $permalinks->defaultPrefix() : '__none',
        ]);
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Prefijos disponibles')->description('Las opciones que podrás elegir al escribir una entrada, por ejemplo /tutoriales/mi-entrada. Son opcionales: una entrada también puede ir directamente tras el dominio.')->schema([
                Forms\Repeater::make('post_prefixes')->label('Tus prefijos')->schema([
                    Forms\TextInput::make('label')->label('Nombre visible')->placeholder('Tutoriales')->required()->maxLength(60)->live(onBlur: true),
                    Forms\TextInput::make('slug')->label('Prefijo en la URL')->placeholder('tutoriales')->required()->maxLength(60)->distinct()
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->notIn(PostPermalinks::reserved())->live(onBlur: true)
                        ->validationMessages(['distinct' => 'Este prefijo ya está en la lista.', 'regex' => 'Usa minúsculas, números o guiones, sin barras.', 'not_in' => 'Esta dirección está reservada para otra sección del sitio.'])
                        ->helperText('Solo minúsculas, números y guiones; sin barras.'),
                ])->columns(2)->defaultItems(0)->maxItems(50)->reorderable(false)->collapsible()->itemLabel(fn (array $state): string => $state['label'] ?? 'Nuevo prefijo')
                    ->addActionLabel('Añadir prefijo')->helperText('Renombrar o quitar una opción no cambia las URL de las entradas existentes. Cada entrada puede cambiar su dirección desde su editor.'),
                Forms\Select::make('default_post_prefix')->label('Predeterminado para nuevas entradas')->required()->selectablePlaceholder(false)->live()->options(function (Get $get): array {
                    $options = ['__none' => 'Sin prefijo'];
                    foreach ($get('post_prefixes') ?? [] as $prefix) {
                        if (filled($prefix['slug'] ?? null)) {
                            $options[$prefix['slug']] = ($prefix['label'] ?: $prefix['slug']).' · /'.$prefix['slug'].'/';
                        }
                    }

                    return $options;
                })->validationMessages(['in' => 'Elige un prefijo de la lista o Sin prefijo.'])->helperText(fn (Get $get): string => 'Ejemplo: '.app(PostPermalinks::class)->url('nombre-de-la-entrada', $get('default_post_prefix') === '__none' ? '' : ($get('default_post_prefix') ?? '')).'. Las categorías no cambian la dirección.'),
            ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        app(PostPermalinks::class)->configure($data['post_prefixes'] ?? [], $data['default_post_prefix'] === '__none' ? '' : $data['default_post_prefix']);
        Notification::make()->title('Direcciones guardadas')->success()->send();
        $this->rememberData();
    }
}
