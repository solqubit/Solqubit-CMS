<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Concerns\HasWritingWorkspace;
use App\Filament\Pages\HomeSettings;
use App\Filament\Resources\Pages\PageResource;
use App\Models\EditorDraft;
use App\Models\Page;
use App\Services\SiteNavigation;
use Filament\Resources\Pages\EditRecord;

class EditPage extends EditRecord
{
    use HasWritingWorkspace;

    protected static string $resource = PageResource::class;

    public static bool $formActionsAreSticky = true;

    /** The front page is configured from Ajustes → Portada, not as an ordinary page. */
    public function mount(int|string $record): void
    {
        if ($home = Page::whereKey($record)->where('kind', 'home')->first()) {
            $this->record = $home;
            $this->redirect(HomeSettings::getUrl());

            return;
        }
        parent::mount($record);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['in_menu'] = app(SiteNavigation::class)->contains($this->record);
        $data['in_footer'] = app(SiteNavigation::class)->contains($this->record, 'footer');

        return PageResource::fillTitles($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['in_menu'], $data['in_footer']);

        return PageResource::normalizeTitles($data);
    }

    protected function afterSave(): void
    {
        EditorDraft::clearFor('page', $this->record->id);
        app(SiteNavigation::class)->setPage($this->record, (bool) ($this->data['in_menu'] ?? false));
        app(SiteNavigation::class)->setPage($this->record, (bool) ($this->data['in_footer'] ?? false), 'footer');
        $this->dispatch('editor-saved', context: 'page', recordId: $this->record->id);
    }

    protected function getHeaderActions(): array
    {
        return [
            ...PageResource::deletionActions(),
        ];
    }
}
