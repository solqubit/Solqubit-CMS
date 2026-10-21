<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Series;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SeriesEntries
{
    public function sync(Series $series, array $entries): void
    {
        Validator::make(['data' => ['entries' => $entries]], [
            'data.entries' => ['array', 'max:500'],
            'data.entries.*.post_id' => ['required', 'integer', 'distinct'],
        ])->validate();
        $ids = array_map('intval', array_column(array_values($entries), 'post_id'));
        DB::transaction(function () use ($series, $ids): void {
            Series::whereKey($series->id)->lockForUpdate()->firstOrFail();
            $posts = Post::where(fn ($query) => $query->where('series_id', $series->id)->orWhereIn('id', $ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($ids as $index => $id) {
                $post = $posts->get($id);
                if (! $post || ($post->series_id && (int) $post->series_id !== (int) $series->id)) {
                    throw ValidationException::withMessages(['data.entries.'.$index.'.post_id' => 'Esta entrada ya no está disponible o pertenece a otra serie. Retírala de esa serie antes de añadirla aquí.']);
                }
            }
            foreach ($posts as $post) {
                $position = array_search($post->id, $ids, true);
                $post->update(['series_id' => $position === false ? null : $series->id, 'series_order' => $position === false ? 0 : $position + 1]);
            }
        });
    }
}
