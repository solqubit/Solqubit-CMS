<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaLibrary
{
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'application/pdf', 'application/zip', 'text/plain', 'application/json', 'text/x-shellscript', 'application/x-yaml', 'text/yaml'];

    public function upload(UploadedFile $file, array $metadata = []): Media
    {
        $metadata = Validator::make($metadata, [
            'alt_text' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:3000'],
        ])->validate();
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType();
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'webm', 'pdf', 'zip', 'txt', 'cfg', 'conf', 'json', 'yaml', 'yml', 'sh', 'md', 'csv', 'log'];
        if (! $file->isValid() || $file->getSize() > config('solqubit.max_upload_kb') * 1024 || ! in_array($extension, $allowed, true) || ! in_array($mime, self::MIMES, true)) {
            throw ValidationException::withMessages(['upload' => 'Formato no admitido o archivo mayor de 32 MB. Usa imágenes JPG/PNG/WebP/GIF, videos MP4/WebM, PDF, ZIP o archivos de texto.']);
        }
        if (str_starts_with($mime, 'image/')) {
            $image = @getimagesize($file->getRealPath());
            if (! $image || $image['mime'] !== $mime || $image[0] > 12000 || $image[1] > 12000) {
                throw ValidationException::withMessages(['upload' => 'La imagen no es válida o supera 12000 píxeles.']);
            }
        }
        $uuid = (string) Str::uuid();
        $path = $file->storeAs('files/'.date('Y/m'), $uuid.'.'.$extension, 'media');
        $dimensions = Media::readDimensions($path, $mime);
        try {
            return Media::create([
                'width' => $dimensions[0] ?? null, 'height' => $dimensions[1] ?? null,
                'uuid' => $uuid, 'path' => $path, 'original_name' => Str::limit(preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 240, '') ?: 'archivo.'.$extension,
                'mime_type' => $mime, 'size' => $file->getSize(), 'user_id' => auth()->id(),
                'alt_text' => $metadata['alt_text'] ?? null, 'caption' => $metadata['caption'] ?? null,
            ]);
        } catch (\Throwable $error) {
            Storage::disk('media')->delete($path);
            throw $error;
        }
    }
}
