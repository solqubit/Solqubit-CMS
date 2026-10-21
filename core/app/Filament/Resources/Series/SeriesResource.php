<?php

namespace App\Filament\Resources\Series;

use App\Filament\Clusters\Classification;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Tags\TagResource;
use App\Models\Post;
use App\Models\Series;
use Filament\Actions;
use Filament\Forms\Components as Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SeriesResource extends Resource
{
    protected static ?string $model = Series::class;

    protected static ?string $modelLabel = 'serie';

    protected static ?string $pluralModelLabel = 'Series';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $cluster = Classification::class;

    protected static ?string $slug = 'series';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    protected static int $globalSearchResultsLimit = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\TextInput::make('name')->label('Nombre')->required()->maxLength(255)->live(onBlur: true)->afterStateUpdated(function ($state, $old, Get $get, Set $set, string $operation): void {
                if ($operation === 'create' && (blank($get('slug')) || $get('slug') === Str::slug($old ?? ''))) {
                    $set('slug', Str::slug($state ?? ''));
                }
            }),
            Forms\TextInput::make('slug')->label('Identificador interno')->helperText('Se completa a partir del nombre. No cambia la dirección de las entradas de la serie.')->required()->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->maxLength(255)->unique(ignoreRecord: true),
            Forms\Textarea::make('description')->label('Descripción')->maxLength(3000)->columnSpanFull(),
            Forms\Select::make('tone')->label('Color de la colección')->options(['blue' => 'Azul', 'rose' => 'Rosa', 'peach' => 'Durazno', 'lilac' => 'Lavanda'])->default('lilac')->required(),
            PostResource::mediaSelect('cover_id', true)->label('Imagen'),
            Section::make('Entradas de la serie')
                ->description('Añade entradas existentes y arrástralas o usa las flechas para ordenar la lectura. Los borradores solo aparecen aquí hasta que los publiques. Una entrada pertenece a una sola serie.')
                ->schema([
                    Forms\Repeater::make('entries')->label('Orden de lectura')->schema([
                        Forms\Select::make('post_id')->label('Entrada')->required()->searchable()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->options(fn (?Series $record) => Post::where(fn ($query) => $query->whereNull('series_id')->when($record, fn ($query) => $query->orWhere('series_id', $record->id)))
                                ->when(! auth()->user()?->isAdmin(), fn ($query) => $query->where(fn ($query) => $query->where('user_id', auth()->id())->when($record, fn ($query) => $query->orWhere('series_id', $record->id))))
                                ->orderBy('title')->get(['id', 'user_id', 'title', 'status', 'published_at'])->mapWithKeys(fn (Post $post) => [$post->id => self::entryLabel($post)]))
                            ->live(),
                    ])->default([])->maxItems(500)->reorderableWithButtons()->addActionLabel('Añadir entrada')
                        ->itemLabel(fn (array $state): string => ($post = Post::find($state['post_id'] ?? null)) ? self::entryLabel($post) : 'Selecciona una entrada'),
                ])->columnSpanFull(),
        ]);
    }

    /** Editors see the titles of their own posts and of published ones; other authors' drafts stay private. */
    public static function entryLabel(Post $post): string
    {
        if (! $post->isPublished() && ! auth()->user()?->isAdmin() && $post->user_id !== auth()->id()) {
            return 'Entrada de otro autor (sin publicar)';
        }

        return $post->title.($post->isPublished() ? '' : ' · Sin publicar');
    }

    public static function table(Table $table): Table
    {
        return $table->emptyStateHeading('Sin series que mostrar')->emptyStateDescription('Una serie reúne entradas en un orden de lectura. Crea una con el botón superior o revisa tu búsqueda.')->columns([
            Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('slug')->label('Identificador interno')->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('posts_count')->label('Entradas')->counts(['posts' => fn (Builder $query): Builder => PostResource::visibleToCurrentUser($query)]),
        ])->recordActions([Actions\EditAction::make(), Actions\DeleteAction::make()->modalDescription(fn (Series $record): string => TagResource::deletionNotice($record->posts()->count(), 'esta serie'))]);
    }

    /**
     * Editors may add, remove or reorder their own posts; entries by other authors must stay in the series.
     *
     * @param  array<int, array{post_id?: mixed}>  $entries
     *
     * @throws ValidationException
     */
    public static function ensureEditableEntries(?Series $record, array $entries): void
    {
        $user = auth()->user();
        if ($user?->isAdmin()) {
            return;
        }
        $submitted = array_map('intval', array_filter(array_column(array_values($entries), 'post_id'), fn (mixed $id): bool => filled($id)));
        $current = $record ? $record->posts()->pluck('id')->all() : [];
        $changed = array_values(array_unique([...array_diff($submitted, $current), ...array_diff($current, $submitted)]));
        if ($changed && Post::whereKey($changed)->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', '!=', $user?->getKey()))->exists()) {
            throw ValidationException::withMessages(['data.entries' => 'Solo puedes añadir o retirar tus propias entradas. Las de otros autores deben permanecer en la serie.']);
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSeries::route('/'), 'create' => Pages\CreateSeries::route('/create'), 'edit' => Pages\EditSeries::route('/{record}/edit')];
    }
}
