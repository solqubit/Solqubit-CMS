<?php

use App\Services\ContentSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('posts')->whereNotNull('youtube_url')->where('youtube_url', '!=', '')->orderBy('id')->chunkById(100, function ($posts): void {
            foreach ($posts as $post) {
                $blocks = json_decode($post->content ?? '[]', true);
                if (! is_array($blocks) || ! array_is_list($blocks) || ! app(ContentSchema::class)->youtubeId($post->youtube_url)) {
                    continue;
                }
                if (! collect($blocks)->contains(fn ($block) => ($block['type'] ?? '') === 'video' && ($block['data']['url'] ?? '') === $post->youtube_url)) {
                    array_unshift($blocks, ['type' => 'video', 'data' => ['url' => $post->youtube_url, 'caption' => $post->youtube_credit ?: $post->title]]);
                }
                DB::table('posts')->where('id', $post->id)->update(['content' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'youtube_url' => null, 'youtube_credit' => null]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Videos remain ordinary content blocks when rolling back; no content is discarded.
    }
};
