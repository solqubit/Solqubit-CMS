<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Clusters\Classification;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Tag;
use Filament\Actions;
use Filament\Forms\Components as Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?string $modelLabel = 'etiqueta';

    protected static ?string $pluralModelLabel = 'Etiquetas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $cluster = Classification::class;

    protected static ?string $slug = 'etiquetas';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static int $globalSearchResultsLimit = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\TextInput::make('name')->label('Nombre')->required()->maxLength(255)->helperText('Así la verán tus lectores en cada entrada.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->emptyStateHeading('Sin etiquetas que mostrar')->emptyStateDescription('Las etiquetas relacionan entradas mediante palabras clave. Crea una con el botón superior o revisa tu búsqueda.')->columns([
            Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('posts_count')->label('Entradas')->counts(['posts' => fn (Builder $query): Builder => PostResource::visibleToCurrentUser($query)]),
        ])->recordActions([Actions\EditAction::make(), Actions\DeleteAction::make()->modalDescription(fn (Tag $record): string => self::deletionNotice($record->posts()->count(), 'la etiqueta'))]);
    }

    /** What deleting a category, tag or series does to its posts. */
    public static function deletionNotice(int $posts, string $what): string
    {
        return $posts === 0 ? 'Ninguna entrada usa '.$what.'.' : ($posts === 1 ? '1 entrada' : $posts.' entradas').' perderán '.$what.'. Las entradas no se borran.';
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTags::route('/'), 'create' => Pages\CreateTag::route('/create'), 'edit' => Pages\EditTag::route('/{record}/edit')];
    }
}
