<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Setting;
use App\Services\SiteIdentity;
use App\Services\SocialLinks;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class SiteSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Identidad';

    protected static ?string $title = 'Identidad del sitio';

    protected static string|\UnitEnum|null $navigationGroup = 'Sitio';

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'identidad';

    protected string $view = 'filament.pages.site-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(Setting::read('site', []) + [
            'social_links' => app(SocialLinks::class)->configured(),
            'brand_lowercase' => true, 'brand_dot' => true, 'brand_icon' => 'cube',
            'brand_image_id' => null, 'favicon_id' => null,
            'footer_copyright' => '© {year} {site}',
            'footer_show_brand' => true, 'footer_show_description' => true, 'footer_show_socials' => true,
        ]);
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        $sections = [
            'identity' => Section::make('Nombre y descripción')->description('La identidad general de tu blog.')->schema([
                Forms\TextInput::make('name')->label('Nombre del sitio')->required()->maxLength(100)->placeholder('Por ejemplo, Solqubit')->helperText('Aparece en la cabecera, el pie de página, el panel y los títulos para buscadores.'),
                Forms\Textarea::make('description')->label('Descripción del sitio')->maxLength(320)->rows(3)->placeholder('Cuenta en una frase de qué trata tu blog.')->helperText('Se utiliza en buscadores, el RSS y el pie de página si no escribes un texto propio.'),
            ]),
            'icons' => Section::make('Iconos del sitio')->description('Uno acompaña al nombre; el otro identifica la pestaña del navegador.')->schema([
                Forms\Select::make('brand_icon')->label('Icono junto al nombre')->options(['custom' => 'Subir o elegir mi imagen', 'cube' => 'Cubo', ...\App\Models\Page::iconOptions()])->required()->live()
                    ->helperText('Visible en la cabecera. Elige un icono incluido, tu propia imagen o Sin icono.'),
                PostResource::mediaSelect('brand_image_id', images: true)->label('Tu imagen de marca')->live()
                    ->visible(fn (Get $get): bool => $get('brand_icon') === 'custom')->required(fn (Get $get): bool => $get('brand_icon') === 'custom')->dehydratedWhenHidden()
                    ->rule('integer')->rule(Rule::exists('media', 'id')->whereIn('mime_type', SiteIdentity::IMAGE_MIMES))
                    ->helperText('Elige una imagen de la biblioteca o pulsa + para subirla. Mejor cuadrada y con fondo transparente; aparecerá junto al nombre, sin recortarse.'),
                PostResource::mediaSelect('favicon_id', images: true)->label('Icono de la pestaña (favicon)')->live()
                    ->rule('integer')->rule(Rule::exists('media', 'id')->whereIn('mime_type', SiteIdentity::IMAGE_MIMES))
                    ->helperText('La pequeña imagen de las pestañas y favoritos, también en el administrador. Recomendado: PNG cuadrado de 512 × 512 px. Vacío conserva el icono incluido. Usa + para subirlo.'),
            ]),
            'brand_style' => Section::make('Cómo se muestra el nombre')->description('Opciones visuales de la cabecera y el pie de página.')->collapsible()->collapsed()->schema([
                Forms\Toggle::make('brand_lowercase')->label('Mostrar el nombre en minúsculas')->helperText('Desactivado conserva exactamente las mayúsculas que escribiste.'),
                Forms\Toggle::make('brand_dot')->label('Añadir un punto de color al final del nombre'),
            ]),
            'footer' => Section::make('Pie de página')->description('Los textos que cierran todas las páginas del sitio.')->schema([
                Forms\Toggle::make('footer_show_brand')->label('Mostrar el nombre del sitio')->live(),
                Forms\Toggle::make('footer_show_description')->label('Mostrar un texto debajo del nombre')->live(),
                Forms\Textarea::make('footer_description')->label('Texto debajo del nombre (opcional)')->rows(2)->maxLength(500)->helperText('En blanco utiliza la descripción del sitio.')
                    ->visible(fn (Get $get): bool => (bool) $get('footer_show_description'))->dehydratedWhenHidden(),
                Forms\Toggle::make('footer_show_socials')->label('Mostrar los iconos de tus redes sociales')->helperText('Solo en el pie de página. En la cabecera y el menú del móvil se siguen mostrando.'),
                Forms\TextInput::make('footer_copyright')->label('Texto de copyright')->maxLength(300)->helperText('Usa {year} para el año actual y {site} para el nombre del sitio. Déjalo vacío para ocultarlo.'),
            ]),
            'social' => Section::make('Tus redes sociales')->description('Hasta tres perfiles principales. Sus iconos aparecen en el encabezado, el pie de página y el menú del móvil de todas las páginas.')->schema([
                Forms\Repeater::make('social_links')->label('Perfiles públicos')->schema([
                    Forms\Select::make('network')->label('Red social')->options(SocialLinks::NETWORKS)->required()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems()->live(),
                    Forms\TextInput::make('url')->label('Enlace a tu perfil')->url()->rule('url:http,https')->required()->maxLength(2048)->placeholder(fn (Get $get): string => $get('network') === 'youtube' ? 'https://www.youtube.com/@tucanal' : 'https://')
                        ->helperText(fn (Get $get): string => $get('network') === 'youtube' ? 'Pega la dirección completa de tu canal, por ejemplo https://www.youtube.com/@tucanal. Seleccionar YouTube no basta para crear el enlace.' : 'Pega la dirección completa de tu perfil, empezando por https://.'),
                ])->columns(2)->default([])->maxItems(3)->addActionLabel('Añadir red social')->reorderableWithButtons(),
            ]),

        ];

        return $schema->components([
            Tabs::make('Identidad y ajustes')->tabs([
                Tab::make('Identidad')->icon('heroicon-o-identification')->schema([View::make('filament.identity-preview'), $sections['identity'], $sections['icons'], $sections['brand_style']]),
                Tab::make('Pie de página')->icon('heroicon-o-rectangle-stack')->schema([View::make('filament.settings-preview')->viewData(['previewType' => 'footer']), $sections['footer']]),
                Tab::make('Redes sociales')->icon('heroicon-o-globe-alt')->schema([$sections['social']]),
            ])->extraAttributes(['class' => 'sq-admin-tabs'])->columnSpanFull(),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = Arr::only($this->form->getState(), [
            'name', 'description', 'brand_icon', 'brand_image_id', 'favicon_id',
            'brand_lowercase', 'brand_dot', 'footer_description', 'footer_copyright', 'social_links',
            'footer_show_brand', 'footer_show_description', 'footer_show_socials',
        ]);
        $data['social_links'] = app(SocialLinks::class)->validate($data['social_links'] ?? []);
        $data['youtube_url'] = collect($data['social_links'])->firstWhere('network', 'youtube')['url'] ?? '';
        $site = array_replace(Setting::read('site', []), $data);
        unset($site['brand_text']);
        Setting::write('site', $site);
        Notification::make()->title('Sitio actualizado')->success()->send();
        $this->rememberData();
    }
}
