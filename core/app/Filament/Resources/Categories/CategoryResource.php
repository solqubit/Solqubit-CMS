<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Clusters\Classification;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Tags\TagResource;
use App\Models\Category;
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

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $modelLabel = 'categoría';

    protected static ?string $pluralModelLabel = 'Categorías';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $cluster = Classification::class;

    protected static ?string $slug = 'categorias';

    protected static ?int $navigationSort = 1;

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
            Forms\Select::make('tone')->label('Color de las tarjetas')->options(['blue' => 'Azul', 'rose' => 'Rosa', 'peach' => 'Durazno', 'lilac' => 'Lavanda'])->default('lilac')->required()->helperText('Acento de las tarjetas. Con varias categorías se usa la primera por orden alfabético.'),
            Section::make('Identificador del filtro')->description('Opcional: revisa el identificador sugerido.')->collapsible()->collapsed()->schema([
                Forms\TextInput::make('slug')->label('Identificador del filtro')->helperText('Se completa a partir del nombre. Sirve para filtrar el blog (por ejemplo ?category=linux); el prefijo de cada entrada se elige en su editor. Si lo cambias, los enlaces antiguos al filtro dejan de funcionar.')->required()->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->maxLength(255)->unique(ignoreRecord: true),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->emptyStateHeading('Sin categorías que mostrar')->emptyStateDescription('Las categorías organizan las entradas por tema. Crea una con el botón superior o revisa tu búsqueda.')->columns([
            Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('slug')->label('Identificador'),
            Tables\Columns\TextColumn::make('posts_count')->label('Entradas')->counts(['posts' => fn (Builder $query): Builder => PostResource::visibleToCurrentUser($query)]),
        ])->recordActions([Actions\EditAction::make(), Actions\DeleteAction::make()->modalDescription(fn (Category $record): string => TagResource::deletionNotice($record->posts()->count(), 'esta categoría'))]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCategories::route('/'), 'create' => Pages\CreateCategory::route('/create'), 'edit' => Pages\EditCategory::route('/{record}/edit')];
    }
}
