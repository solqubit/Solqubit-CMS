<?php

namespace App\Filament\Resources\Series\Pages;

use App\Filament\Resources\Series\SeriesResource;
use App\Services\SeriesEntries;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditSeries extends EditRecord
{
    protected static string $resource = SeriesResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['entries'] = $this->record->posts->map(fn ($post): array => ['post_id' => $post->id])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $entries = $data['entries'] ?? [];
            unset($data['entries']);
            SeriesResource::ensureEditableEntries($record, $entries);
            app(SeriesEntries::class)->sync($record, $entries);
            $record->update($data);

            return $record;
        });
    }
}
