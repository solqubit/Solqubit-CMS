<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Concerns\HasWritingWorkspace;
use App\Filament\Resources\Posts\PostResource;
use App\Models\EditorDraft;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    use HasWritingWorkspace;

    protected static string $resource = PostResource::class;

    protected static bool $canCreateAnother = false;

    public static bool $formActionsAreSticky = true;

    protected function afterCreate(): void
    {
        EditorDraft::clearFor('post', 'new');
        $this->record->syncTagNames($this->data['tags'] ?? []);
        $this->record->rememberRevisionTags();
        $this->dispatch('editor-saved', context: 'post', recordId: 'new');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        PostResource::ensureUsableMedia($data, null);
        $data['user_id'] = auth()->user()?->isAdmin() && filled($data['user_id'] ?? null) ? (int) $data['user_id'] : auth()->id();
        $data['content_version'] = 1;

        return $data;
    }
}
