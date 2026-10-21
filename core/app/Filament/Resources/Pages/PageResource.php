<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Pages\NavigationSettings;
use App\Filament\Pages\SiteSettings;
use App\Filament\Resources\Posts\PostResource;
use App\Forms\Components\ContentEditor;
use App\Models\Page;
use App\Services\PagePermalinks;
use App\Services\SocialLinks;
use Filament\Actions;
use Filament\Forms\Components as Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $modelLabel = 'página';

    protected static ?string $pluralModelLabel = 'Páginas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static string|\UnitEnum|null $navigationGroup = 'Contenido';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    protected static int $globalSearchResultsLimit = 8;

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'slug'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->withoutTrashed()->where('kind', '!=', 'home')
            ->select(['id', 'title', 'published'])->orderByDesc('updated_at');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Estado' => $record->published ? 'Publicada' : 'Borrador'];
    }

    public static function form(Schema $schema): Schema
    {
        $sections = [
            'cover' => Section::make('Imagen principal')->extraAttributes(['class' => 'sq-document-cover'])
                ->visible(fn (?Page $record): bool => ! $record || in_array($record->kind, ['about', 'custom'], true))->schema([
                    PostResource::mediaSelect('cover_id', true)->label('Imagen principal (opcional)')->placeholder('Añadir imagen principal')->live(),
                    View::make('filament.cover-preview')->viewData(fn (Get $get): array => ['media' => PostResource::coverPreview($get('cover_id'))]),
                ])->columnSpanFull(),
            'identity' => Section::make('Tu página')->description(fn (?Page $record): string => match ($record?->kind) {
                'series' => 'Series organiza las entradas por colección. Puedes desactivarla cuando no la necesites.',
                'about' => 'Una página opcional para presentarte. Escribe tu historia como escribirías una entrada.',
                default => 'Una página informativa para contacto, privacidad, avisos u otro contenido. Tú decides si aparece en el menú.',
            })->schema([
                Forms\Textarea::make('settings.heading')->label('Título')->placeholder(fn (Get $get): string => filled($get('title')) ? $get('title') : 'Escribe el título de la página…')
                    ->rows(2)->autosize()->requiredWithout('title')->maxLength(180)->live(debounce: 500)
                    ->afterStateUpdated(fn ($state, $old, Get $get, Set $set, string $operation, ?Page $record) => self::suggestSlug($state, $old, $get, $set, $operation, $record)),
            ])->extraAttributes(['class' => 'sq-document-title'])->columnSpanFull(),
            'address' => Section::make('Dirección de la página')->visible(fn (?Page $record): bool => ! $record || $record->kind === 'custom')->collapsible()->collapsed()->schema([
                Forms\TextInput::make('slug')->label('Dirección de la página')->required()->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->maxLength(255)->live(debounce: 500)
                    ->visible(fn (?Page $record): bool => ! $record || $record->kind === 'custom')
                    ->rule(fn (?Page $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        $candidate = $record ? clone $record : new Page;
                        $candidate->slug = $value;
                        try {
                            app(PagePermalinks::class)->validate($candidate);
                        } catch (ValidationException $error) {
                            $fail(collect($error->errors())->flatten()->first());
                        }
                    })
                    ->helperText(function (Get $get, ?Page $record): string {
                        $slug = $get('slug') ?: (Str::slug($get('title') ?? '') ?: 'tu-pagina');
                        $legacy = $record && $record->slug === $slug && app(PagePermalinks::class)->unavailableReason($slug);

                        return 'URL completa: '.($legacy ? $record->url() : route('pages.show', $slug)).($legacy ? ' · Esta página conserva su dirección anterior porque la dirección corta está ocupada. Puedes elegir otro nombre.' : ' · Directamente después del dominio. Se sugiere desde el nombre y puedes editarla.');
                    }),
            ])->columnSpanFull(),
            'content' => Section::make('Escribe tu contenido')->description(fn (?Page $record): string => $record?->kind === 'series' ? 'Este texto es opcional y aparece antes del listado de series. Las series se administran en Clasificación.' : 'Escribe como en un documento. Los botones y el menú / te permiten añadir imágenes, videos y otros elementos.')->schema([
                ContentEditor::make('content')->label('Documento')->hiddenLabel()->editorContext('page'),
            ])->extraAttributes(['class' => 'sq-document-content'])->columnSpanFull(),
            'publication' => Section::make('Visibilidad y menú')->description('Publicar una página y añadirla al menú son dos decisiones independientes.')->schema([
                Forms\Toggle::make('published')->label(fn (?Page $record): string => in_array($record?->kind, ['series', 'about'], true) ? 'Mostrar esta sección en el sitio' : 'Página publicada')->default(false)->columnSpanFull()
                    ->helperText(fn (?Page $record): string => in_array($record?->kind, ['series', 'about'], true) ? 'Desactivada, su dirección deja de existir y desaparece de los menús y del mapa del sitio. Puedes volver a activarla cuando quieras.' : 'Desactivado: borrador privado. Activado: accesible mediante su URL.'),
                Forms\Toggle::make('in_menu')->label('En el encabezado (header)')->default(false)->helperText('Enlace en el menú superior, también en móvil.'),
                Forms\Toggle::make('in_footer')->label('En el pie de página (footer)')->default(false)->helperText(fn (): HtmlString => new HtmlString('Enlace al final de la web. El orden de cada menú se cambia en <a class="underline" href="'.e(NavigationSettings::getUrl()).'">Ajustes · Menús</a>.')),
            ])->columns(2)->columnSpanFull(),
            'presentation' => Section::make('Encabezado')->schema([
                Forms\TextInput::make('title')->label('Nombre de la página')->maxLength(255)->live(debounce: 500)
                    ->afterStateUpdated(fn ($state, $old, Get $get, Set $set, string $operation, ?Page $record) => blank($get('settings.heading')) ? self::suggestSlug($state, $old, $get, $set, $operation, $record) : null)
                    ->helperText('Se usa en los menús, en el panel y en buscadores. En blanco, se usa el título.'),
                Forms\Toggle::make('settings.hide_heading')->label('Ocultar el título en la página')->default(false)
                    ->helperText('El título sigue disponible para los lectores de pantalla y los buscadores.'),
                Fieldset::make('Descripción')->schema([
                    Forms\Toggle::make('settings.show_intro')->label('Mostrar descripción')->default(false),
                    Forms\Textarea::make('settings.intro')->label('Descripción (opcional)')->rows(3)->maxLength(1500),
                ])->columns(1)->columnSpan(1),
                Forms\Toggle::make('settings.show_channel')->label('Mostrar enlace a mi canal de YouTube')->default(true)->visible(fn (?Page $record): bool => $record?->kind === 'about')
                    ->helperText(fn (): ?HtmlString => collect(app(SocialLinks::class)->configured())->contains('network', 'youtube') ? null
                        : new HtmlString('No tienes un perfil de YouTube en tus redes sociales, así que el enlace no aparecerá. Añádelo en <a class="underline" href="'.e(SiteSettings::getUrl()).'">Ajustes · Identidad</a>.')),
            ])->columns(2)->columnSpanFull(),
            'listing' => Section::make('Listado automático')->description('El CMS reúne aquí tus series publicadas. Tú eliges cuántas aparecen en cada página.')->visible(fn (?Page $record): bool => $record?->kind === 'series')->schema([
                Forms\Select::make('settings.per_page')->label('Series por página')->options([6 => '6', 9 => '9', 12 => '12', 18 => '18', 24 => '24'])->default(12)->required()->helperText('Al superar esta cantidad se muestran páginas numeradas.'),
            ])->columns(2)->columnSpanFull(),
            'seo' => Section::make('Buscadores')->description('Opcional. En blanco se utilizan el nombre de la página y una descripción de su presentación o contenido.')->schema([
                Forms\TextInput::make('seo_title')->label('Título para buscadores')->maxLength(255),
                Forms\Textarea::make('seo_description')->label('Descripción para buscadores')->rows(3)->maxLength(320),
            ])->columnSpanFull(),
        ];

        return $schema->components([
            Group::make([
                Group::make([$sections['cover'], $sections['identity'], $sections['content']])->extraAttributes(['class' => 'sq-writing-document']),
                Group::make([
                    $sections['publication']->columns(1),
                    $sections['presentation']->columns(1)->collapsible()->collapsed(),
                    $sections['address'],
                    $sections['listing']->columns(1)->collapsible()->collapsed(),
                    $sections['seo']->collapsible()->collapsed(),
                ])->extraAttributes(['class' => 'sq-writing-settings', 'id' => 'writing-settings']),
            ])->extraAttributes(['class' => 'sq-writing-grid'])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('title')->label('Página')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                'series' => 'Series', 'about' => 'Sobre mí', default => 'Página adicional'
            }),
            Tables\Columns\IconColumn::make('published')->label('Publicada')->boolean(),
            Tables\Columns\TextColumn::make('updated_at')->label('Actualizada')->dateTime('d/m/Y H:i')->sortable(),
        ])
            ->recordUrl(fn (Page $record): ?string => $record->trashed() ? null : static::getUrl('edit', ['record' => $record]))
            ->recordActions([
                Actions\EditAction::make()->hidden(fn (Page $record): bool => $record->trashed()),
                Actions\Action::make('preview')->label('Vista previa')->icon('heroicon-o-eye')->url(fn (Page $record): string => route('pages.preview', $record))->openUrlInNewTab()->hidden(fn (Page $record): bool => $record->trashed()),
                ...static::deletionActions(),
            ])
            ->defaultSort('id');
    }

    /** On a new custom page, the address follows the title until it is edited by hand. */
    public static function suggestSlug(?string $state, ?string $old, Get $get, Set $set, string $operation, ?Page $record): void
    {
        if ($operation === 'create' && (! $record || $record->kind === 'custom') && (blank($get('slug')) || $get('slug') === Str::slug($old ?? ''))) {
            $set('slug', Str::slug($state ?? ''));
        }
    }

    /**
     * The document edits the visible title; the settings hold the page's name. A blank name takes the title,
     * and a title equal to the name is stored empty, so the page keeps showing its name as before.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeTitles(array $data): array
    {
        $settings = $data['settings'] ?? [];
        $heading = trim((string) ($settings['heading'] ?? ''));
        $data['title'] = filled($data['title'] ?? null) ? trim((string) $data['title']) : $heading;
        $settings['heading'] = $heading === $data['title'] ? '' : $heading;
        $settings['show_heading'] = ! ($settings['hide_heading'] ?? false);
        unset($settings['hide_heading']);
        $data['settings'] = $settings;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillTitles(array $data): array
    {
        $data['settings'] ??= [];
        // A name longer than the visible title allows stays out of the title field; the page keeps showing its name.
        $name = (string) ($data['title'] ?? '');
        $data['settings']['heading'] = filled($data['settings']['heading'] ?? null) ? $data['settings']['heading'] : (mb_strlen($name) <= 180 ? $name : '');
        $data['settings']['hide_heading'] = ! ($data['settings']['show_heading'] ?? true);

        return $data;
    }

    public static function deletionActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Eliminar')->icon('heroicon-o-trash')
                ->modalHeading(fn (Page $record): string => 'Mover «'.$record->title.'» a la papelera')
                ->modalDescription('La página dejará de estar disponible y sus enlaces de página se ocultarán en los menús. Podrás recuperarla desde la pestaña Papelera. Los archivos de la biblioteca se conservan.')
                ->modalSubmitActionLabel('Mover a la papelera')->successNotificationTitle('Página movida a la papelera'),
            Actions\RestoreAction::make()->label('Restaurar')->icon('heroicon-o-arrow-uturn-left')
                ->modalDescription('La página recuperará su estado anterior. Si estaba publicada, volverá a estar disponible y aparecerá en los menús donde siga incluida.')
                ->successNotificationTitle('Página restaurada'),
            Actions\ForceDeleteAction::make()->label('Eliminar definitivamente')->icon('heroicon-o-trash')
                ->modalDescription('Se eliminará el contenido de esta página de forma permanente. No podrás recuperarla. Los archivos de la biblioteca se conservan.')
                ->modalSubmitActionLabel('Eliminar definitivamente')->successNotificationTitle('Página eliminada definitivamente'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])->where('kind', '!=', 'home');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPages::route('/'), 'create' => Pages\CreatePage::route('/create'), 'edit' => Pages\EditPage::route('/{record}/edit')];
    }
}
