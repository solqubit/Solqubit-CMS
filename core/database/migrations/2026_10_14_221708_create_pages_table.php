<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->default('custom')->index();
            $table->string('title');
            $table->string('slug')->unique();
            $table->boolean('published')->default(false);
            $table->json('settings')->nullable();
            $table->json('content');
            $table->unsignedSmallInteger('content_version')->default(1);
            $table->foreignId('cover_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('media_page', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->primary(['media_id', 'page_id']);
        });
        $site = json_decode(DB::table('settings')->where('key', 'site')->value('value') ?? '{}', true);
        $defaults = [
            ['home', 'Inicio', 'inicio', ['show_heading' => true, 'heading' => 'Tecnología,', 'accent_heading' => 'paso a paso.', 'show_intro' => true, 'intro' => 'Comandos, archivos y enlaces para acompañar tus videos. Consulta el material de cada video en su entrada.', 'icon' => 'sparkles', 'show_featured' => true, 'catalog_title' => 'Material de los videos.', 'per_page' => 12]],
            ['series', 'Series', 'series', ['show_heading' => true, 'heading' => 'Aprende con un hilo conductor.', 'show_intro' => true, 'intro' => 'El material de apoyo de los videos, organizado por tema para encontrar cada entrada.', 'per_page' => 6]],
            ['about', 'Sobre mí', 'sobre-mi', ['show_heading' => true, 'heading' => 'Sobre '.($site['name'] ?? 'mí'), 'show_intro' => false, 'show_channel' => true]],
        ];
        foreach ($defaults as [$kind, $title, $slug, $settings]) {
            $content = $kind === 'about' && ! empty($site['about']) ? [['type' => 'paragraph', 'data' => ['html' => $site['about']]]] : [];
            DB::table('pages')->insert(['kind' => $kind, 'title' => $title, 'slug' => $slug, 'published' => true, 'settings' => json_encode($settings), 'content' => json_encode($content), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_page');
        Schema::dropIfExists('pages');
    }
};
