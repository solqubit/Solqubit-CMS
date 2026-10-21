<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    public function getSubheading(): ?string
    {
        return 'Puedes eliminar tus páginas adicionales y recuperarlas desde Papelera. Inicio se conserva siempre; Series y Sobre mí se pueden desactivar desde su edición.';
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Páginas')->icon('heroicon-o-document-duplicate')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutTrashed()),
            'trash' => Tab::make('Papelera')->icon('heroicon-o-trash')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->onlyTrashed()),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
