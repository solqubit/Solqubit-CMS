<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Services\ImageVariants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class MediaController extends Controller
{
    /**
     * Seconds a public file may be reused before its visibility is checked again.
     */
    public const PUBLIC_MAX_AGE = 86400;

    public function show(Request $request, Media $media)
    {
        $isPublic = $media->isPublic();
        abort_unless($isPublic || $request->user()?->can('view', $media), 404);
        $disk = Storage::disk('media');
        abort_unless($disk->exists($media->path), 404);
        $variant = $request->query('variant');
        abort_if($variant !== null && (! is_string($variant) || ! array_key_exists($variant, ImageVariants::WIDTHS)), 400);
        $file = $request->boolean('download') ? ['path' => $media->path, 'mime' => $media->mime_type] : app(ImageVariants::class)->resolve($media, $variant);
        $disposition = ($media->isImage() || $media->isVideo()) && ! $request->boolean('download') ? 'inline' : 'attachment';
        $fallback = preg_replace('/[^a-zA-Z0-9 ._\-]/', '', Str::ascii($media->original_name));
        if (! $fallback) {
            $fallback = 'archivo.'.pathinfo($media->path, PATHINFO_EXTENSION);
        }
        $response = response()->file($disk->path($file['path']), [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $media->original_name, $fallback),
            'Cache-Control' => $isPublic ? 'public, max-age='.self::PUBLIC_MAX_AGE : 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
        if ($isPublic) {
            // Browsers and proxies may reuse a public file for a day; after that, visibility is checked again.
            $response->setPublic()->setEtag(hash('sha256', implode('|', [
                $media->uuid, $file['path'], $media->size, $media->updated_at?->getTimestamp(),
                $disk->lastModified($file['path']), $disk->size($file['path']), $disposition,
            ])));
            $response->isNotModified($request);
        } else {
            $response->setPrivate();
        }

        return $response;
    }
}
