<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use Illuminate\Http\Request;

class AdminTransferController extends Controller
{
    public function export(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return response()->streamDownload(function () {
            echo json_encode(['format' => 'solqubit-content', 'schema' => config('solqubit.content_schema'), 'exported_at' => now()->toIso8601String(), 'posts' => Post::withTrashed()->with(['tags:id,name,slug', 'categories:id,name,slug'])->get(), 'categories' => Category::all(), 'tags' => Tag::all(), 'series' => Series::all(), 'media' => Media::all()->map(fn ($media) => $media->only(['id', 'uuid', 'original_name', 'mime_type', 'size', 'alt_text', 'caption']) + ['url' => $media->url()])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }, 'solqubit-contenido-'.date('Y-m-d').'.json', ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
