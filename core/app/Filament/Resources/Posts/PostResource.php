<?php

namespace App\Filament\Resources\Posts;

use App\Filament\Pages\AddressSettings;
use App\Filament\Resources\Series\SeriesResource;
use App\Filament\Resources\Users\UserResource;
use App\Forms\Components\ContentEditor;
use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Tag;
use App\Services\ContentSchema;
use App\Services\MediaAccess;
use App\Services\MediaLibrary;
use App\Services\PostPermalinks;
use Filament\Actions;
use Filament\Forms\Components as Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $modelLabel = 'entrada';

    protected static ?string $pluralModelLabel = 'Entradas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Contenido';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    protected static int $globalSearchResultsLimit = 8;

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'slug', 'excerpt', 'categories.name', 'tags.name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return static::visibleToCurrentUser(parent::getGlobalSearchEloquentQuery()->withoutTrashed())
            ->select(['id', 'user_id', 'title', 'status', 'published_at'])->orderByDesc('updated_at');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Estado' => match ($record->status) {
            'published' => $record->isPublished() ? 'Publicado' : 'Programado',
            'draft' => 'Borrador',
            default => 'Archivado',
        }];
    }

    public static function mediaSelect(string $name = 'media_id', bool $images = false, bool $videos = false): Forms\Select
    {
        $library = fn () => app(MediaAccess::class)->visibleTo(Media::query(), auth()->user())
            ->when($images, fn ($q) => $q->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']))
            ->when($videos, fn ($q) => $q->whereIn('mime_type', ['video/mp4', 'video/webm']));

        return Forms\Select::make($name)->label($images ? 'Imagen de la biblioteca' : 'Archivo de la biblioteca')->searchable()
            ->getSearchResultsUsing(fn (string $search) => $library()->where('original_name', 'like', '%'.$search.'%')->limit(50)->pluck('original_name', 'id'))
            ->getOptionLabelUsing(fn ($value, ?Model $record) => in_array((int) $value, app(MediaAccess::class)->attachedIds($record), true) ? Media::find($value)?->original_name : $library()->whereKey($value)->value('original_name'))
            ->options(fn () => $library()->latest()->limit(30)->pluck('original_name', 'id'))
            ->rule(fn (?Model $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                try {
                    app(MediaAccess::class)->ensureUsable(auth()->user(), [$value], $record, $attribute);
                } catch (ValidationException $error) {
                    $fail(collect($error->errors())->flatten()->first());
                }
            })
            ->createOptionForm([
                Forms\FileUpload::make('upload')->label('Seleccionar archivo')->storeFiles(false)->required()->maxSize(32768)->acceptedFileTypes($images ? ['image/jpeg', 'image/png', 'image/webp', 'image/gif'] : ($videos ? ['video/mp4', 'video/webm'] : MediaLibrary::MIMES)),
                Forms\TextInput::make('alt_text')->label('Descripción de la imagen')->visible($images)->maxLength(255),
            ])->createOptionUsing(fn (array $data): int => app(MediaLibrary::class)->upload($data['upload'], ['alt_text' => $data['alt_text'] ?? null])->id)
            ->helperText('Elige un archivo o pulsa + para subir uno sin salir del editor.');
    }

    public static function form(Schema $schema): Schema
    {
        $sections = [
            'cover' => Section::make('Imagen de cabecera')->extraAttributes(['class' => 'sq-document-cover'])->schema([
                self::mediaSelect('cover_id', true)->label('Imagen de cabecera')->placeholder('Añadir imagen de cabecera')->live(),
                View::make('filament.cover-preview')->viewData(fn (Get $get): array => ['media' => self::coverPreview($get('cover_id'))]),
            ])->columnSpanFull(),
            'identity' => Section::make('Tu entrada')->extraAttributes(['class' => 'sq-document-title'])->schema([
                Forms\Textarea::make('title')->label('Título')->placeholder('Escribe el título…')->rows(2)->autosize()->required()->maxLength(255)->columnSpanFull()->live(debounce: 500)
                    ->afterStateUpdated(function ($state, $old, Get $get, Set $set, string $operation): void {
                        if ($operation === 'create' && (blank($get('slug')) || $get('slug') === Str::slug($old ?? ''))) {
                            $set('slug', Str::slug($state ?? ''));
                        }
                    }),
                Forms\Textarea::make('excerpt')->label('Entradilla')->placeholder('Una o dos frases que resuman la entrada…')->rows(2)->autosize()->maxLength(1500)
                    ->helperText('Aparece bajo el título y en las tarjetas del blog.')->extraAttributes(['class' => 'sq-document-lead'])->columnSpanFull(),
            ])->columnSpanFull(),
            'address' => Section::make('Dirección de la entrada')->description('Elige un prefijo o deja la entrada directamente tras el dominio.')->collapsible()->collapsed()->schema([
                Forms\Select::make('url_prefix')->label('Prefijo de esta entrada')->required()->selectablePlaceholder(false)->live()
                    ->options(fn (?Post $record): array => ['__none' => 'Sin prefijo'] + array_diff_key(app(PostPermalinks::class)->options($record), ['' => '']))
                    ->default(fn (): string => ($prefix = app(PostPermalinks::class)->defaultPrefix()) !== '' ? $prefix : '__none')
                    ->formatStateUsing(fn ($state): string => filled($state) ? $state : '__none')
                    ->dehydrateStateUsing(fn ($state): string => $state === '__none' ? '' : $state)
                    ->helperText(fn (): HtmlString => new HtmlString('Una opción por entrada, independiente de sus categorías.'.(auth()->user()?->isAdmin() ? ' Configúralas en <a class="underline" href="'.e(AddressSettings::getUrl()).'">Ajustes · Direcciones</a>.' : ''))),
                Forms\TextInput::make('slug')->label('Parte final de la URL')->required()->maxLength(255)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->unique(ignoreRecord: true)->live(debounce: 500)
                    ->rule(fn (Get $get, ?Post $record) => function (string $attribute, mixed $value, \Closure $fail) use ($get, $record): void {
                        $candidate = $record ? clone $record : new Post;
                        $candidate->slug = $value;
                        $candidate->url_prefix = $get('url_prefix') === '__none' ? '' : (string) $get('url_prefix');
                        try {
                            app(PostPermalinks::class)->validatePost($candidate);
                        } catch (ValidationException $error) {
                            $fail(collect($error->errors())->flatten()->first());
                        }
                    })
                    ->helperText(fn (Get $get): string => 'URL completa: '.app(PostPermalinks::class)->url($get('slug') ?: (Str::slug($get('title') ?? '') ?: 'tu-entrada'), $get('url_prefix') === '__none' ? '' : ($get('url_prefix') ?? '')).'. Si cambias una dirección existente, la anterior redirigirá aquí.'),
            ])->columns(1)->columnSpanFull(),
            'content' => Section::make('Escribe tu entrada')->extraAttributes(['class' => 'sq-document-content'])->schema([
                ContentEditor::make('content')->label('Documento')->hiddenLabel(),
            ])->columnSpanFull(),
            'publication' => Section::make('Publicación')->schema([
                Forms\Select::make('user_id')->label('Autor de la entrada')->relationship('author', 'name', fn (Builder $query) => $query->orderBy('name'))
                    ->default(fn (): ?int => auth()->id())->required()->searchable()->preload()->columnSpanFull()
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->helperText(fn (): HtmlString => new HtmlString('Su nombre, foto y biografía aparecen en la entrada. Se cambian en <a class="underline" href="'.e(UserResource::getUrl()).'">Usuarios</a>.')),
                Forms\TextInput::make('author_display_name')->label('Autor de la entrada')
                    ->formatStateUsing(fn (?Post $record): string => $record ? ($record->author?->name ?: 'Autor') : (auth()->user()?->name ?? 'Autor'))
                    ->disabled()->dehydrated(false)->columnSpanFull()->hidden(fn (): bool => auth()->user()?->isAdmin() ?? false)
                    ->helperText('Puedes cambiar tu nombre público desde tu perfil.'),
                Forms\Select::make('status')->label('Estado')->options(['draft' => 'Borrador', 'published' => 'Publicada', 'archived' => 'Archivada'])->required()->default('draft')->live(),
                Forms\DateTimePicker::make('published_at')->label('Publicar el')->timezone(fn () => Setting::read('site', [])['timezone'] ?? 'UTC')->live()
                    ->visible(fn (Get $get): bool => $get('status') === 'published')->helperText('En blanco, se publica al guardar. Una fecha futura la programa.'),
            ])->columns(2)->columnSpanFull(),
            'organization' => Section::make('Clasificación')->description('Las categorías sirven para filtrar, las etiquetas para encontrar y una serie reúne entradas relacionadas.')->schema([
                Forms\Select::make('category_ids')->label('Categorías')->options(fn () => Category::orderBy('name')->pluck('name', 'id'))->multiple()->searchable()->maxItems(20)->default([])
                    ->helperText('Puedes elegir varias. La entrada aparecerá en todos esos filtros y conservará el prefijo elegido en su dirección.'),
                // Like WordPress: type a tag and press Enter or a comma. New names become tags when the post is saved.
                Forms\TagsInput::make('tags')->label('Etiquetas')->placeholder('Escribe una etiqueta y pulsa Enter')->splitKeys([','])
                    ->suggestions(fn (): array => Tag::orderBy('name')->pluck('name')->all())
                    ->rules(['array', 'max:30'])->nestedRecursiveRules(['string', 'max:255'])
                    ->afterStateHydrated(fn (Forms\TagsInput $component, ?Post $record) => $component->state($record ? $record->tags()->orderBy('name')->pluck('name')->all() : []))
                    ->dehydrated(false)
                    ->helperText('Escribe y pulsa Enter o coma. Te sugiere las que ya existen; las nuevas se crean al guardar.'),
                Forms\Select::make('series_id')->label('Serie (opcional)')->relationship('series', 'name')->searchable()->preload()->live()->helperText(fn (): HtmlString => new HtmlString('Para añadir varias entradas y ordenar la lectura, abre <a class="underline" href="'.e(SeriesResource::getUrl()).'">Clasificación · Series</a>.')),
                Forms\TextInput::make('series_order')->label('Posición en la serie')->visible(fn (Get $get): bool => filled($get('series_id')))->dehydratedWhenHidden()->helperText('Los números menores aparecen primero. También puedes ordenar desde Series.')->numeric()->minValue(0)->default(0),
            ])->columns(2)->columnSpanFull(),
            'details' => Section::make('Lectura')->schema([
                Forms\TextInput::make('reading_minutes')->label('Minutos de lectura')->numeric()->minValue(1)->maxValue(300)->nullable()->placeholder('Automático')
                    ->helperText('En blanco se calcula a partir del texto, a unas 200 palabras por minuto.'),
                Forms\Select::make('difficulty')->label('Nivel')->options(['__none' => 'Sin nivel', 'Divulgación' => 'Divulgación', 'Básico' => 'Básico', 'Intermedio' => 'Intermedio', 'Avanzado' => 'Avanzado'])
                    ->default('__none')->required()->selectablePlaceholder(false)
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? $state : '__none')
                    ->dehydrateStateUsing(fn (?string $state): string => $state === '__none' ? '' : (string) $state)
                    ->helperText('Sin nivel no se muestra en el sitio.'),
                Forms\Toggle::make('show_updated_at')->label('Mostrar la última actualización')->default(false)->helperText('Se actualiza al cambiar el texto, título, entradilla o imagen de cabecera. No cambia por ordenar etiquetas o categorías.'),
            ])->columns(2)->columnSpanFull(),
            'seo' => Section::make('Buscadores')->description('Opcional. En blanco se utilizan el título y la entradilla; si no hay entradilla, se obtiene una descripción del texto.')->schema([
                Forms\TextInput::make('seo_title')->label('Título para buscadores')->maxLength(255),
                Forms\Textarea::make('seo_description')->label('Descripción para buscadores')->maxLength(320),
            ])->columnSpanFull(),
        ];

        return $schema->components([
            Group::make([
                Group::make([$sections['cover'], $sections['identity'], $sections['content']])->extraAttributes(['class' => 'sq-writing-document']),
                Group::make([
                    $sections['publication']->columns(1),
                    $sections['organization']->columns(1)->collapsible()->collapsed(),
                    $sections['details']->columns(1)->collapsible()->collapsed(),
                    $sections['address'],
                    $sections['seo']->collapsible()->collapsed(),
                ])->extraAttributes(['class' => 'sq-writing-settings', 'id' => 'writing-settings']),
            ])->extraAttributes(['class' => 'sq-writing-grid'])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with('cover'))->columns([
            Tables\Columns\ImageColumn::make('cover')->label('')->getStateUsing(fn (Post $record): ?string => $record->cover?->isImage() ? $record->cover->url('card') : null)
                ->imageHeight(40)->extraImgAttributes(['class' => 'sq-list-thumbnail', 'alt' => '']),
            Tables\Columns\TextColumn::make('title')->label('Título')->searchable()->sortable()->wrap(),
            Tables\Columns\TextColumn::make('categories.name')->label('Categorías')->badge()->wrap(),
            Tables\Columns\TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state, Post $record) => $state === 'published' ? ($record->isPublished() ? 'Publicado' : 'Programado') : ($state === 'draft' ? 'Borrador' : 'Archivado'))->color(fn ($state) => match ($state) {
                'published' => 'success', 'draft' => 'warning', default => 'gray'
            }),
            Tables\Columns\TextColumn::make('author.name')->label('Autor')->toggleable(),
            Tables\Columns\TextColumn::make('published_at')->label('Publicada')->sortable()->placeholder('—')
                ->formatStateUsing(fn ($state, Post $record): string => ($record->published_at?->isFuture() ? 'Programada para ' : '').$record->published_at?->timezone(FilamentTimezone::get())->format('d/m/Y H:i')),
            Tables\Columns\TextColumn::make('updated_at')->label('Actualizada')->dateTime('d/m/Y H:i')->sortable()->toggleable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->label('Estado')->options(['draft' => 'Borrador', 'published' => 'Publicada o programada', 'archived' => 'Archivada']),
            Tables\Filters\Filter::make('mine')->label('Mías')->query(fn (Builder $query): Builder => $query->where('user_id', auth()->id())),
            Tables\Filters\Filter::make('published')->label('Publicadas')->query(fn (Builder $query): Builder => $query->published()),
            Tables\Filters\Filter::make('scheduled')->label('Programadas')->query(fn (Builder $query): Builder => $query->where('status', 'published')->where('published_at', '>', now())),
            Tables\Filters\SelectFilter::make('category')->label('Categoría')->relationship('categories', 'name'),
            Tables\Filters\TrashedFilter::make(),
        ])->recordActions([Actions\EditAction::make(), Actions\ActionGroup::make([self::duplicateAction(), Actions\Action::make('preview')->label('Vista previa')->icon('heroicon-o-eye')->url(fn (Post $record) => route('posts.preview', $record))->openUrlInNewTab()->authorize('update')->hidden(fn (Post $record): bool => $record->trashed()),
            Actions\ForceDeleteAction::make()->label('Eliminar definitivamente')->modalDescription('La entrada se eliminará para siempre y no podrás recuperarla. Los archivos de la biblioteca se conservan.'),
        ])->label('Más acciones')->tooltip('Más acciones')])
            ->toolbarActions([Actions\BulkActionGroup::make([self::statusBulkAction('publish', 'Publicar', 'heroicon-o-check-circle', 'published'), self::statusBulkAction('archive', 'Archivar', 'heroicon-o-archive-box', 'archived'), Actions\DeleteBulkAction::make()->authorizeIndividualRecords('delete'), Actions\RestoreBulkAction::make()->authorizeIndividualRecords('restore'), Actions\ForceDeleteBulkAction::make()->label('Eliminar definitivamente')->authorizeIndividualRecords('forceDelete')])])->defaultSort('updated_at', 'desc');
    }

    /** The image shown above the title while writing, when the user may see it. */
    public static function coverPreview(mixed $id): ?Media
    {
        $media = filled($id) ? app(MediaAccess::class)->visibleTo(Media::query(), auth()->user())->find((int) $id) : null;

        return $media?->isImage() ? $media : null;
    }

    /** The label of the editor's main button, which says what saving will do. */
    public static function primaryActionLabel(?string $status, mixed $publishedAt, ?Post $record): string
    {
        if ($status !== 'published') {
            return 'Guardar borrador';
        }
        $date = filled($publishedAt) ? Carbon::parse($publishedAt, Setting::read('site', [])['timezone'] ?? 'UTC') : null;
        if ($date?->isFuture()) {
            return 'Programar';
        }

        return $record?->isPublished() ? 'Actualizar' : 'Publicar';
    }

    public static function getEloquentQuery(): Builder
    {
        return static::visibleToCurrentUser(parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]));
    }

    /** Editors see their own posts and the published posts of other authors; administrators see everything. */
    public static function visibleToCurrentUser(Builder $query): Builder
    {
        $user = auth()->user();
        if ($user?->isAdmin()) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query->where('user_id', $user?->getKey())->orWhere(fn (Builder $query) => $query->published()));
    }

    /** Changes the status of the selected posts the user may edit; publishing keeps an existing date or uses now. */
    public static function statusBulkAction(string $name, string $label, string $icon, string $status): Actions\BulkAction
    {
        return Actions\BulkAction::make($name)->label($label)->icon($icon)->requiresConfirmation()->authorizeIndividualRecords('update')
            ->action(function (Collection $records) use ($status): void {
                foreach ($records as $record) {
                    $record->status = $status;
                    if ($status === 'published') {
                        $record->published_at ??= now();
                    }
                    $record->save();
                }
            })->deselectRecordsAfterCompletion();
    }

    public static function duplicateAction(): Actions\Action
    {
        return Actions\Action::make('duplicate')->label('Duplicar como borrador')->icon('heroicon-o-document-duplicate')
            ->visible(fn (Post $record): bool => (auth()->user()?->can('create', Post::class) ?? false) && auth()->user()->can('view', $record))
            ->action(fn (Post $record) => redirect()->to(static::getUrl('edit', ['record' => $record->duplicateAsDraft()])));
    }

    /**
     * Ensure every library file in the submitted cover and document may be used by the current user.
     *
     * @param  array<string, mixed>  $data
     */
    public static function ensureUsableMedia(array $data, ?Post $record): void
    {
        $access = app(MediaAccess::class);
        $access->ensureUsable(auth()->user(), [$data['cover_id'] ?? null], $record, 'data.cover_id');
        $access->ensureUsable(auth()->user(), app(ContentSchema::class)->mediaIds(is_array($data['content'] ?? null) ? $data['content'] : []), $record, 'data.content');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPosts::route('/'), 'create' => Pages\CreatePost::route('/create'), 'edit' => Pages\EditPost::route('/{record}/edit')];
    }
}
