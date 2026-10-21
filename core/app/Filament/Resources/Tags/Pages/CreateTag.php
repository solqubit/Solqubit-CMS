<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use App\Models\Tag;
use Filament\Resources\Pages\CreateRecord;

class CreateTag extends CreateRecord
{
    protected static string $resource = TagResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slug'] = Tag::uniqueSlug($data['name']);

        return $data;
    }
}
