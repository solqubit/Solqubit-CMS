<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\Posts\PostResource;
use App\Models\AuditEvent;
use App\Models\Setting;
use App\Services\SeoMetadata;
use App\Services\SiteIdentity;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class SeoSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'SEO';

    protected static ?string $title = 'SEO y buscadores';

    protected static string|\UnitEnum|null $navigationGroup = 'Publicación';

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'seo';

    protected string $view = 'filament.pages.seo-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(app(SeoMetadata::class)->settings());
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Al compartir y en los buscadores')->schema([
                PostResource::mediaSelect('default_image_id', images: true)->label('Imagen al compartir (por defecto)')->nullable()
                    ->rule('integer')->rule(Rule::exists('media', 'id')->whereIn('mime_type', SiteIdentity::IMAGE_MIMES))
                    ->helperText('Se muestra al compartir la portada o cualquier página o entrada sin imagen propia. Recomendado: 1200 × 630 px.'),
                Toggle::make('noindex')->label('Pedir a los buscadores que no indexen el sitio')
                    ->helperText('Útil mientras preparas el blog. Añade noindex a todas las páginas y robots.txt pide no rastrear nada. Desactívalo al publicar.'),
            ]),
            Section::make('Conectar con los buscadores')->description('Opcional. El SEO automático funciona aunque estos campos estén vacíos. Solo verifican que el sitio es tuyo; no instalan estadísticas ni cookies.')->schema([
                TextInput::make('google_verification')->label('Código de Google Search Console')->maxLength(160)
                    ->regex('/^[a-zA-Z0-9_-]{8,160}$/D')->nullable()
                    ->helperText('Para una propiedad de tipo «Prefijo de URL», elige Etiqueta HTML y copia solo el valor de content="…". Una propiedad de dominio se verifica por DNS en tu hosting.')
                    ->validationMessages(['regex' => 'Pega únicamente el código de verificación, sin la etiqueta HTML.']),
                TextInput::make('bing_verification')->label('Código de Bing Webmaster Tools')->maxLength(160)
                    ->regex('/^[a-zA-Z0-9_-]{8,160}$/D')->nullable()
                    ->helperText('Si eliges la etiqueta meta de Bing, copia solo su valor content="…". Puedes dejarlo vacío.')
                    ->validationMessages(['regex' => 'Pega únicamente el código de verificación, sin la etiqueta HTML.']),
            ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $data['default_image_id'] = filled($data['default_image_id'] ?? null) ? (int) $data['default_image_id'] : null;
        $data['noindex'] = (bool) ($data['noindex'] ?? false);
        Setting::write('seo', array_intersect_key($data, app(SeoMetadata::class)->settings()));
        AuditEvent::record('seo.updated');
        Notification::make()->title('Ajustes SEO guardados')->success()->send();
        $this->rememberData();
    }
}
