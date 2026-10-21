<?php

namespace App\Filament\Resources\Media;

use App\Models\Media;
use App\Services\MediaAccess;
use App\Services\MediaLibrary;
use Filament\Actions;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static ?string $modelLabel = 'archivo';

    protected static ?string $pluralModelLabel = 'Biblioteca';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static string|\UnitEnum|null $navigationGroup = 'Contenido';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'original_name';

    protected static int $globalSearchResultsLimit = 8;

    public static function getGloballySearchableAttributes(): array
    {
        return ['original_name', 'alt_text', 'caption'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\FileUpload::make('upload')->label('Archivos')->helperText('Imágenes, videos y documentos · hasta 20 archivos de 32 MB como máximo cada uno. Después puedes reutilizarlos en varias entradas.')->multiple()->maxFiles(20)->storeFiles(false)->acceptedFileTypes(MediaLibrary::MIMES)->maxSize(32768)->required(fn (string $operation) => $operation === 'create')->visibleOn('create')->columnSpanFull(),
            View::make('filament.media-details')->visibleOn('edit')->columnSpanFull(),
            Forms\TextInput::make('original_name')->label('Archivo original')->disabled()->dehydrated(false)->visibleOn('edit'),
            Forms\TextInput::make('alt_text')->label('Texto alternativo de la imagen')->visible(fn (?Media $record): bool => ! $record || $record->isImage())->maxLength(255)->helperText('Describe la imagen para quienes no puedan verla.'),
            Forms\Textarea::make('caption')->label('Pie de imagen o descripción')->maxLength(3000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->emptyStateHeading('Sin archivos que mostrar')->emptyStateDescription('Sube imágenes, videos o documentos para usarlos en tus entradas y páginas. Si buscabas un archivo, prueba con otro nombre.')->columns([
            Tables\Columns\ImageColumn::make('thumbnail')->label('')->getStateUsing(fn (Media $record) => $record->isImage() ? $record->url('avatar') : null),
            Tables\Columns\TextColumn::make('original_name')->label('Archivo')->searchable()->wrap(),
            Tables\Columns\TextColumn::make('mime_type')->label('Formato')->formatStateUsing(fn (string $state): string => match ($state) {
                'image/jpeg' => 'JPG', 'image/svg+xml' => 'SVG', 'text/plain' => 'TXT', default => strtoupper(explode('/', $state)[1] ?? $state)
            })->searchable(),
            Tables\Columns\TextColumn::make('size')->label('Tamaño')->formatStateUsing(fn ($state) => $state >= 1048576 ? number_format($state / 1048576, 1).' MB' : number_format($state / 1024, 1).' KB'),
            Tables\Columns\TextColumn::make('created_at')->label('Subido')->dateTime('d/m/Y')->sortable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('kind')->label('Tipo de archivo')->options(['image' => 'Imágenes', 'video' => 'Videos', 'document' => 'Documentos y otros'])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? '') {
                    'image' => $query->where('mime_type', 'like', 'image/%'),
                    'video' => $query->where('mime_type', 'like', 'video/%'),
                    'document' => $query->where('mime_type', 'not like', 'image/%')->where('mime_type', 'not like', 'video/%'),
                    default => $query,
                }),
        ])->recordActions([Actions\EditAction::make(), Actions\Action::make('download')->label('Descargar')->url(fn (Media $record) => $record->url().'?download=1')->openUrlInNewTab(), Actions\DeleteAction::make()->modalDescription('Los archivos que todavía usa el sitio no se pueden eliminar.')
            ->before(function (Media $record, Actions\DeleteAction $action): void {
                if ($record->isReferenced()) {
                    Notification::make()->danger()->title('No se puede eliminar este archivo')->body($record->inUseMessage())->persistent()->send();
                    $action->cancel();
                }
            })])->defaultSort('created_at', 'desc');
    }

    /**
     * Editors browse their own uploads and files already public on the site.
     */
    public static function getEloquentQuery(): Builder
    {
        return app(MediaAccess::class)->visibleTo(parent::getEloquentQuery(), auth()->user());
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMedia::route('/'), 'create' => Pages\CreateMedia::route('/create'), 'edit' => Pages\EditMedia::route('/{record}/edit')];
    }
}
