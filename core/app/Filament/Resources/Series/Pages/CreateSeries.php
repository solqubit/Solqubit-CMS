<?php

namespace App\Filament\Resources\Series\Pages;

use App\Filament\Resources\Series\SeriesResource;
use App\Models\Series;
use App\Services\SeriesEntries;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateSeries extends CreateRecord
{
    protected static string $resource = SeriesResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $entries = $data['entries'] ?? [];
            unset($data['entries']);
            SeriesResource::ensureEditableEntries(null, $entries);
            $series = Series::create($data);
            app(SeriesEntries::class)->sync($series, $entries);

            return $series;
        });
    }
}
