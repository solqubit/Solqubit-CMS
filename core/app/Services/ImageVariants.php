<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class ImageVariants
{
    public const WIDTHS = ['card' => 720, 'hero' => 1440, 'avatar' => 160];

    public function resolve(Media $media, ?string $variant): array
    {
        $original = ['path' => $media->path, 'mime' => $media->mime_type];
        if (! isset(self::WIDTHS[$variant ?? '']) || ! in_array($media->mime_type, ['image/png', 'image/jpeg', 'image/webp'], true) || ! function_exists('imagecreatefromstring')) {
            return $original;
        }
        $disk = Storage::disk('media');
        $format = function_exists('imagewebp') ? 'webp' : ($media->mime_type === 'image/jpeg' ? 'jpeg' : 'png');
        $path = 'variants/'.$media->uuid.'/'.$variant.'-v1.'.$format;
        $mime = 'image/'.$format;
        if ($disk->exists($path)) {
            return ['path' => $path, 'mime' => $mime];
        }
        $dimensions = @getimagesize($disk->path($media->path));
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 10000000 || $dimensions[0] <= self::WIDTHS[$variant]) {
            return $original;
        }
        $source = null;
        $target = null;
        try {
            $bytes = $disk->get($media->path);
            $orientation = 1;
            if ($media->mime_type === 'image/jpeg' && str_contains(substr($bytes, 0, 65536), "Exif\0\0")) {
                // Preserve camera orientation when this PHP installation cannot read EXIF.
                if (! function_exists('exif_read_data')) {
                    return $original;
                }
                $orientation = (int) (@exif_read_data($disk->path($media->path))['Orientation'] ?? 1);
            }
            $source = @imagecreatefromstring($bytes);
            unset($bytes);
            if (! $source) {
                return $original;
            }
            $source = $this->orient($source, $orientation);
            $dimensions = [imagesx($source), imagesy($source)];
            if ($dimensions[0] <= self::WIDTHS[$variant]) {
                return $original;
            }
            $width = self::WIDTHS[$variant];
            $height = max(1, (int) round($dimensions[1] * $width / $dimensions[0]));
            $target = imagecreatetruecolor($width, $height);
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $dimensions[0], $dimensions[1]);
            ob_start();
            try {
                $success = match ($format) {
                    'webp' => imagewebp($target, null, 82),
                    'jpeg' => imagejpeg($target, null, 82),
                    default => imagepng($target, null, 7),
                };
                $output = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! $success || ! $output || strlen($output) >= $disk->size($media->path)) {
                return $original;
            }
            File::ensureDirectoryExists(dirname($disk->path($path)));
            File::replace($disk->path($path), $output);

            return ['path' => $path, 'mime' => $mime];
        } catch (\Throwable $error) {
            logger()->warning('No se pudo generar una miniatura.', ['media_id' => $media->id, 'exception' => $error::class]);

            return $original;
        } finally {
            if ($source) {
                imagedestroy($source);
            }
            if ($target) {
                imagedestroy($target);
            }
        }
    }

    /**
     * Apply an EXIF orientation (1-8) to the pixels, because variants are written without EXIF.
     */
    private function orient(\GdImage $image, int $orientation): \GdImage
    {
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated) {
                imagedestroy($image);
                $image = $rotated;
            }
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }
}
