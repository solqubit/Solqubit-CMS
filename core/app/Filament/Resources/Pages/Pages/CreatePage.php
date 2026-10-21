<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Concerns\HasWritingWorkspace;
use App\Filament\Resources\Pages\PageResource;
use App\Models\EditorDraft;
use App\Services\SiteNavigation;
use Filament\Resources\Pages\CreateRecord;

class CreatePage extends CreateRecord
{
    use HasWritingWorkspace;

    protected static string $resource = PageResource::class;

    protected static bool $canCreateAnother = false;

    public static bool $formActionsAreSticky = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['in_menu'], $data['in_footer']);

        return PageResource::normalizeTitles($data);
    }

    protected function afterCreate(): void
    {
        EditorDraft::clearFor('page', 'new');
        app(SiteNavigation::class)->setPage($this->record, (bool) ($this->data['in_menu'] ?? false));
        app(SiteNavigation::class)->setPage($this->record, (bool) ($this->data['in_footer'] ?? false), 'footer');
        $this->dispatch('editor-saved', context: 'page', recordId: 'new');
    }
}
