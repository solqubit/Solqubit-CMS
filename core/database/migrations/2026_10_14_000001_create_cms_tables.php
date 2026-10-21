<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('editor');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('alt_text')->nullable();
            $table->text('caption')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        foreach (['categories', 'tags', 'series'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                if ($name !== 'tags') {
                    $table->string('tone', 20)->default('lilac');
                    $table->foreignId('cover_id')->nullable()->constrained('media')->nullOnDelete();
                }
                $table->timestamps();
            });
        }
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('series')->nullOnDelete();
            $table->foreignId('cover_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->json('content');
            $table->unsignedSmallInteger('content_version')->default(1);
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('series_order')->default(0);
            $table->unsignedSmallInteger('reading_minutes')->default(5);
            $table->string('difficulty', 30)->default('Básico');
            $table->string('youtube_url')->nullable();
            $table->string('youtube_credit')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'published_at']);
        });
        Schema::create('post_tag', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['post_id', 'tag_id']);
        });
        Schema::create('media_post', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->primary(['media_id', 'post_id']);
        });
        Schema::create('post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('subject')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'settings', 'post_revisions', 'media_post', 'post_tag', 'posts', 'series', 'tags', 'categories', 'media'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'is_active']));
    }
};
