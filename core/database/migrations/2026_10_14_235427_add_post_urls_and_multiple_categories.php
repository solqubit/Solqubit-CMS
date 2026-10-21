<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->string('url_prefix', 60)->default('');
        });
        Schema::create('category_post', function (Blueprint $table): void {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'post_id']);
        });
        Schema::create('post_paths', function (Blueprint $table): void {
            $table->string('path', 316)->primary();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
        });

        $settings = json_decode(DB::table('settings')->where('key', 'permalinks')->value('value') ?? '{}', true);
        $prefix = $settings['prefix'] ?? 'entrada';
        $prefixes = array_unique([$prefix, ...($settings['previous_prefixes'] ?? [])]);
        DB::table('posts')->orderBy('id')->chunkById(200, function ($posts) use ($prefix, $prefixes): void {
            foreach ($posts as $post) {
                DB::table('posts')->where('id', $post->id)->update(['url_prefix' => $prefix]);
                if ($post->category_id) {
                    DB::table('category_post')->insert(['category_id' => $post->category_id, 'post_id' => $post->id]);
                }
                foreach ($prefixes as $oldPrefix) {
                    DB::table('post_paths')->insert(['path' => ($oldPrefix !== '' ? $oldPrefix.'/' : '').$post->slug, 'post_id' => $post->id]);
                }
            }
        });
        $configured = $prefix !== '' && DB::table('posts')->exists() ? [['label' => ucfirst($prefix), 'slug' => $prefix]] : [];
        DB::table('settings')->updateOrInsert(['key' => 'permalinks'], [
            'value' => json_encode(['default_prefix' => '', 'prefixes' => $configured], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_paths');
        Schema::dropIfExists('category_post');
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn('url_prefix'));
    }
};
