<?php

use App\Services\SearchText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the plain text used by public search and fill it for existing entries.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->longText('search_text')->nullable();
        });
        $searchText = app(SearchText::class);
        DB::table('posts')->select(['id', 'title', 'excerpt', 'content'])->orderBy('id')->chunkById(100, function ($posts) use ($searchText): void {
            foreach ($posts as $post) {
                $blocks = json_decode($post->content ?? '[]', true);
                DB::table('posts')->where('id', $post->id)->update([
                    'search_text' => $searchText->forPost($post->title, $post->excerpt, is_array($blocks) ? $blocks : []),
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
