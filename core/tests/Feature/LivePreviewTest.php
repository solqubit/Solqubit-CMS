<?php

namespace Tests\Feature;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LivePreviewTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-live-'.bin2hex(random_bytes(8));
        file_put_contents($this->marker, '{}');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Mi blog', 'description' => 'Notas', 'timezone' => 'UTC']);
        Setting::write('monetization', ['status' => 'active', 'publisher_id' => 'ca-pub-1234567890123456', 'format' => 'manual', 'privacy_ready' => true, 'home_bottom' => '1234567890', 'article_top' => '2345678901', 'article_bottom' => '3456789012']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function paragraph(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function image(User $owner): Media
    {
        return Media::create(['uuid' => (string) str()->uuid(), 'path' => 'privada.png', 'original_name' => 'privada.png', 'mime_type' => 'image/png', 'size' => 2048, 'user_id' => $owner->id, 'width' => 10, 'height' => 10]);
    }

    public function test_post_preview_renders_the_real_article_without_saving(): void
    {
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true, 'name' => 'Ana Autora']);
        $category = Category::create(['name' => 'Astronomía', 'slug' => 'astronomia']);
        $this->actingAs($editor);

        $response = $this->postJson(route('editor.preview'), [
            'context' => 'post', 'mode' => 'light', 'document' => $this->paragraph('Un párrafo sin guardar.'),
            'fields' => ['title' => 'Título sin guardar', 'excerpt' => 'Entradilla de prueba', 'category_ids' => [$category->id]],
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $html = $response->json('html');
        $this->assertStringContainsString('class="article-intro"', $html);
        $this->assertStringContainsString('Título sin guardar', $html);
        $this->assertStringContainsString('Entradilla de prueba', $html);
        $this->assertStringContainsString('Un párrafo sin guardar.', $html);
        $this->assertStringContainsString('Astronomía', $html);
        $this->assertStringContainsString('Por Ana Autora', $html);
        $this->assertStringContainsString('noindex', $html);
        $this->assertStringNotContainsString('data-ad-placement', $html);
        $this->assertStringNotContainsString('visitas.js', $html);
        $this->assertStringNotContainsString('preview-banner', $html);
        $this->assertSame(0, Post::count());
    }

    public function test_the_public_article_still_shows_ads_and_counts_visits(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $post = Post::create(['user_id' => $admin->id, 'title' => 'Pública', 'slug' => 'publica', 'status' => 'published', 'published_at' => now()->subDay(),
            'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Texto.</p>']]]]);

        $this->get($post->url())->assertOk()->assertSee('data-ad-placement', false)->assertDontSee('noindex,nofollow', false);
    }

    public function test_preview_ignores_form_placeholders(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]));

        $html = $this->postJson(route('editor.preview'), [
            'context' => 'post', 'mode' => 'light', 'document' => $this->paragraph('Texto.'),
            'fields' => ['title' => 'Sin nivel', 'slug' => 'mi-entrada', 'difficulty' => '__none', 'url_prefix' => '__none'],
        ])->assertOk()->json('html');

        $this->assertStringNotContainsString('__none', $html);
    }

    public function test_preview_shows_typed_tags_before_they_exist(): void
    {
        Tag::create(['name' => 'Astronomía', 'slug' => 'astronomia']);
        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]));

        $html = $this->postJson(route('editor.preview'), [
            'context' => 'post', 'mode' => 'light', 'document' => $this->paragraph('Texto.'),
            'fields' => ['title' => 'Con etiquetas', 'tags' => ['astronomía', 'Vida en Marte']],
        ])->assertOk()->json('html');

        $this->assertStringContainsString('# Astronomía', $html);
        $this->assertStringContainsString('# Vida en Marte', $html);
        $this->assertSame(1, Tag::count());
    }

    public function test_preview_date_uses_the_site_timezone(): void
    {
        Setting::write('site', ['name' => 'Mi blog', 'description' => 'Notas', 'timezone' => 'Pacific/Honolulu']);
        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]));

        $html = $this->postJson(route('editor.preview'), [
            'context' => 'post', 'mode' => 'light', 'document' => $this->paragraph('Texto.'),
            'fields' => ['title' => 'Con fecha', 'published_at' => '2026-01-01 01:00:00'],
        ])->assertOk()->json('html');

        $this->assertStringContainsString('datetime="2026-01-01T11:00:00+00:00"', $html);
    }

    public function test_preview_hides_document_images_the_user_cannot_see(): void
    {
        $image = $this->image(User::factory()->create(['role' => 'editor', 'is_active' => true]));
        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]));

        $html = $this->postJson(route('editor.preview'), [
            'context' => 'post', 'mode' => 'light', 'fields' => ['title' => 'Con imagen ajena'],
            'document' => ['type' => 'doc', 'content' => [['type' => 'customBlock', 'attrs' => ['id' => 'image', 'config' => ['media_id' => $image->id]]], ['type' => 'paragraph']]],
        ])->assertOk()->json('html');

        $this->assertStringNotContainsString($image->uuid, $html);
    }

    public function test_preview_requests_are_limited(): void
    {
        $this->assertContains('throttle:120,1', app('router')->getRoutes()->getByName('editor.preview')->gatherMiddleware());
    }

    public function test_preview_of_an_existing_post_keeps_the_saved_record(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $post = Post::create(['user_id' => $admin->id, 'title' => 'Guardado', 'slug' => 'guardado', 'status' => 'published', 'published_at' => now()->subDay(), 'content' => []]);
        $this->actingAs($admin);

        $html = $this->postJson(route('editor.preview'), [
            'context' => 'post', 'record_id' => $post->id, 'mode' => 'dark', 'document' => $this->paragraph('Cambio nuevo.'),
            'fields' => ['title' => 'Título editado'],
        ])->assertOk()->json('html');

        $this->assertStringContainsString('Título editado', $html);
        $this->assertStringContainsString('data-theme="dark"', $html);
        $this->assertSame('Guardado', $post->fresh()->title);
        $this->assertSame([], $post->fresh()->content);
    }

    public function test_preview_hides_media_the_user_cannot_see(): void
    {
        $owner = User::factory()->create(['role' => 'editor', 'is_active' => true]);
        $image = $this->image($owner);
        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]));

        $html = $this->postJson(route('editor.preview'), [
            'context' => 'post', 'mode' => 'light', 'document' => $this->paragraph('Texto.'),
            'fields' => ['title' => 'Con portada ajena', 'cover_id' => $image->id],
        ])->assertOk()->json('html');

        $this->assertStringNotContainsString($image->uuid, $html);
    }

    public function test_editors_cannot_preview_other_authors_posts(): void
    {
        $author = User::factory()->create(['role' => 'editor', 'is_active' => true]);
        $draft = Post::create(['user_id' => $author->id, 'title' => 'Borrador ajeno', 'slug' => 'borrador-ajeno', 'status' => 'draft', 'content' => []]);
        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]));

        $this->postJson(route('editor.preview'), ['context' => 'post', 'record_id' => $draft->id, 'mode' => 'light', 'document' => $this->paragraph('X'), 'fields' => []])->assertForbidden();
    }

    public function test_editor_header_has_a_single_preview_button(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $post = Post::create(['user_id' => $admin->id, 'title' => 'Guardado', 'slug' => 'guardado', 'status' => 'draft', 'content' => []]);
        $this->actingAs($admin);

        $html = $this->get(PostResource::getUrl('edit', ['record' => $post]))->assertOk()->getContent();

        $this->assertStringContainsString('id="sq-live-preview"', $html);
        $this->assertStringContainsString('data-live-preview="post"', $html);
        $this->assertStringContainsString('Vista previa', $html);
        $this->assertStringContainsString('editor/live-preview.js', $html);
        $this->assertStringNotContainsString('Ver versión guardada', $html);
        $this->assertStringNotContainsString('data-rich-editor-tool="sq_preview"', $html);
    }

    public function test_page_and_home_preview_are_admin_only(): void
    {
        $home = Page::where('kind', 'home')->firstOrFail();
        $payload = ['context' => 'home', 'record_id' => $home->id, 'mode' => 'light', 'fields' => ['settings' => ['heading' => 'Hola desde la vista previa']]];

        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]))->postJson(route('editor.preview'), $payload)->assertForbidden();
        $html = $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]))->postJson(route('editor.preview'), $payload)->assertOk()->json('html');

        $this->assertStringContainsString('Hola desde la vista previa', $html);
        $this->assertStringContainsString('journal-home', $html);
        $this->assertStringNotContainsString('data-ad-placement', $html);
        $this->assertNotSame('Hola desde la vista previa', $home->fresh()->option('heading'));
    }
}
