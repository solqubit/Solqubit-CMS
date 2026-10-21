<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-theme-a11y');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Mi Blog', 'description' => 'Mis publicaciones', 'timezone' => 'America/Guayaquil']);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    public function test_article_landmarks_and_scrollable_regions_are_accessible(): void
    {
        $post = Post::create([
            'title' => 'Vida en el universo', 'slug' => 'vida-en-el-universo', 'status' => 'published',
            'user_id' => User::factory()->create()->id,
            'content' => [
                ['type' => 'code', 'data' => ['text' => 'echo hola', 'label' => 'bash']],
                ['type' => 'table', 'data' => ['columns' => ['Mundo', 'Agua'], 'rows' => [['cells' => 'Marte|Hielo']]]],
            ],
        ]);

        $html = $this->get($post->url())->assertOk()
            ->assertSee('<nav class="breadcrumbs" aria-label="Ruta de navegación">', false)
            ->assertSee('<span aria-current="page">Vida en el universo</span></li>', false)
            ->assertSee('<pre tabindex="0" role="region" aria-label="Código">', false)
            ->assertSee('<div class="table-wrap" tabindex="0" role="region" aria-label="Tabla">', false)
            ->getContent();

        $this->assertMatchesRegularExpression('~<article class="article-entry"[^>]*>\s*<header class="article-intro">.*?<h1 id="article-title">Vida en el universo</h1>~s', $html);
    }

    public function test_theme_toggle_names_itself_with_its_visible_label_and_fonts_are_self_hosted(): void
    {
        $html = $this->get('/')->assertOk()
            ->assertSee('<button class="theme-toggle" type="button">', false)
            ->assertSee('<span data-label-light>Modo claro<span class="sr-only">. Pulsa para cambiar a modo oscuro</span></span>', false)
            ->assertSee('<span data-label-dark>Modo oscuro<span class="sr-only">. Pulsa para cambiar a modo claro</span></span>', false)
            ->assertSee('rel="preload" href="'.asset('themes/solqubit/fonts/nunito-latin.woff2').'" as="font" type="font/woff2" crossorigin', false)
            ->assertDontSee('fonts.googleapis.com')
            ->getContent();

        $this->assertStringNotContainsString('fonts.googleapis.com', file_get_contents(public_path('themes/solqubit/editorial-theme.css')));
        $this->assertFileExists(public_path('themes/solqubit/fonts/nunito-latin.woff2'));
        $this->assertDoesNotMatchRegularExpression('~class="theme-toggle"[^>]*aria-label=~', $html);
    }
}
