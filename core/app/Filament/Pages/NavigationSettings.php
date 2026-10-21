<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Services\SiteNavigation;
use Filament\Actions\Action;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class NavigationSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bars-3';

    protected static ?string $navigationLabel = 'Menús';

    protected static ?string $title = 'Menús del sitio';

    protected static string|\UnitEnum|null $navigationGroup = 'Sitio';

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'menus';

    protected string $view = 'filament.pages.navigation-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $navigation = app(SiteNavigation::class);
        $this->form->fill(['items' => $navigation->entries('header'), 'footer_items' => $navigation->entries('footer')]);
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Ubicación del menú')->tabs([
                Tab::make('Encabezado (header)')->icon('heroicon-o-window')->schema([
                    Section::make('Arriba de todas las páginas')->description('Los enlaces principales del sitio. En el móvil aparecen al abrir el menú. Arrastra los enlaces o usa las flechas para ordenarlos.')->schema([$this->menu('items')]),
                ]),
                Tab::make('Pie de página (footer)')->icon('heroicon-o-bars-3-bottom-left')->schema([
                    Section::make('Al final de todas las páginas')->description('Ideal para Privacidad, Contacto y Uso del contenido. Tiene su propio orden: cambiarlo no modifica el encabezado.')->schema([$this->menu('footer_items')]),
                ]),
            ])->extraAttributes(['class' => 'sq-admin-tabs'])->columnSpanFull(),
        ])->statePath('data');
    }

    private function menu(string $name): Forms\Repeater
    {
        return Forms\Repeater::make($name)->label('Enlaces · máximo 12')->helperText('Solo se muestran los enlaces activados y las páginas publicadas. Puedes dejar este menú vacío.')->schema([
            Forms\TextInput::make('label')->label('Texto del enlace')->required()->maxLength(40)->live(onBlur: true),
            Forms\Select::make('type')->label('Destino')->options(['page' => 'Página del sitio', 'url' => 'Enlace externo'])->default('page')->required()->live(),
            Forms\Select::make('page_id')->label('Página')->options(fn () => \App\Models\Page::withTrashed()->orderBy('title')->get(['id', 'title', 'published', 'deleted_at'])->mapWithKeys(fn ($page) => [$page->id => $page->title.($page->trashed() ? ' · Papelera' : ($page->published ? '' : ' · Borrador'))]))->searchable()->required(fn (Get $get): bool => $get('type') === 'page')->visible(fn (Get $get): bool => $get('type') === 'page')->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                    if (blank($get('label')) && filled($state)) {
                        $set('label', mb_substr((string) \App\Models\Page::withTrashed()->whereKey($state)->value('title'), 0, 40));
                    }
                }),
            Forms\TextInput::make('url')->label('Dirección web')->url()->rule('url:http,https')->maxLength(2048)->placeholder('https://')->required(fn (Get $get): bool => $get('type') === 'url')->visible(fn (Get $get): bool => $get('type') === 'url'),
            Forms\Toggle::make('enabled')->label('Mostrar enlace')->default(true),
            Forms\Toggle::make('new_tab')->label('Abrir en otra pestaña')->default(false),
        ])->columns(2)->reorderableWithButtons()->collapsible()->collapsed()
            ->addAction(fn (Action $action) => $action->action(function (Forms\Repeater $component): void {
                $key = $component->generateUuid();
                $items = $component->getRawState() ?? [];
                $items[$key] = [];
                $component->rawState($items);
                $newItem = $component->getChildSchema($key);
                $newItem->fill();
                $newPath = $newItem->getStatePath();
                $component->collapsed(fn (Schema $item): bool => $item->getStatePath() !== $newPath);
                $component->callAfterStateUpdated();
            }))
            ->itemLabel(fn (array $state): string => ($state['label'] ?? 'Nuevo enlace').(($state['enabled'] ?? true) ? '' : ' · Oculto'))->addActionLabel('Añadir enlace al menú')->maxItems(12)->default([])->columnSpanFull();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        app(SiteNavigation::class)->saveMenus($data['items'] ?? [], $data['footer_items'] ?? []);
        Notification::make()->title('Encabezado y pie de página actualizados')->success()->send();
        $this->rememberData();
    }
}
