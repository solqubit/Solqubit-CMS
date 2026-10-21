<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    public function getSubheading(): ?string
    {
        return 'Escribe, organiza y publica tus entradas. Los borradores solo se ven en el panel.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
