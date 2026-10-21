<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Validation\ValidationException;

class EditorPreview
{
    public function block(string $type, array $data): string
    {
        try {
            $block = app(ContentSchema::class)->validate([['type' => $type, 'data' => $data]])[0];
        } catch (ValidationException $error) {
            return '<p class="sq-block-placeholder">'.e($error->validator->errors()->first()).'</p>';
        }
        $block['anchor'] = 'editor-block';
        $mediaItems = app(MediaAccess::class)->visibleTo(Media::query(), auth()->user())->whereIn('id', app(ContentSchema::class)->mediaIds([$block]))->get()->keyBy('id');

        return '<div class="sq-block-preview">'.view('themes.solqubit.block', ['block' => $block, 'mediaItems' => $mediaItems, 'preview' => true])->render().'</div>';
    }
}
