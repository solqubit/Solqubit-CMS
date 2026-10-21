<?php

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use App\Models\Media;
use App\Services\MediaLibrary;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreateMedia extends CreateRecord
{
    protected static string $resource = MediaResource::class;

    /**
     * Each selected file becomes its own library item; the text fields apply to all of them. If one file fails, the
     * files already stored are removed from disk too, since the panel's transaction only undoes their rows.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $files = is_array($data['upload']) ? array_values($data['upload']) : [$data['upload']];
        $metadata = array_intersect_key($data, array_flip(['alt_text', 'caption']));
        $created = [];
        try {
            foreach ($files as $file) {
                $created[] = app(MediaLibrary::class)->upload($file, $metadata);
            }
        } catch (\Throwable $error) {
            Storage::disk('media')->delete(array_map(fn (Media $media): string => $media->path, $created));
            if ($error instanceof ValidationException && isset($error->errors()['upload'])) {
                throw ValidationException::withMessages(['data.upload' => $error->errors()['upload']]);
            }
            throw $error;
        }

        return end($created);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
