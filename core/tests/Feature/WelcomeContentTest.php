<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\SiteNavigation;
use App\Services\WelcomePages;
use App\Services\WelcomeSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WelcomeContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_pages_include_explicit_examples_and_separate_menu_locations(): void
    {
        Setting::write('site', ['name' => 'Mi blog', 'description' => 'El material de apoyo para tus tutoriales de YouTube.', 'footer_description' => 'El material de apoyo para tus tutoriales de YouTube.']);
        app(WelcomePages::class)->create();

        $this->assertDatabaseCount('pages', 6);
        $navigation = app(SiteNavigation::class);
        $this->assertSame(['Blog', 'Series', 'Sobre mí', 'Contacto'], array_column($navigation->links(), 'label'));
        $this->assertSame(['Contacto', 'Privacidad', 'Uso del contenido'], array_column($navigation->links('footer'), 'label'));
        $privacy = Page::where('slug', 'privacidad')->firstOrFail();
        $this->assertTrue($privacy->published);
        $this->assertStringContainsString('ejemplo', $privacy->content[0]['data']['title']);
        $this->assertStringContainsString('[nombre de la persona o entidad]', json_encode($privacy->content));
        $this->assertSame('Ideas que nos llevan', Page::where('kind', 'home')->firstOrFail()->option('heading'));
        $this->assertSame('Mi blog', Setting::read('site')['name']);
        $this->assertSame('Ideas, historias y conocimiento para compartir.', Setting::read('site')['footer_description']);
    }

    public function test_preparing_examples_again_keeps_edits_and_does_not_duplicate_links(): void
    {
        app(WelcomePages::class)->create();
        $page = Page::where('slug', 'contacto')->firstOrFail();
        $page->update(['title' => 'Mi contacto', 'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Mi texto personal.</p>']]]]);
        $menus = [Setting::read('navigation'), Setting::read('navigation_footer')];
        Setting::write('site', ['name' => 'Mi nombre', 'description' => 'Mi presentación', 'footer_description' => 'Mi pie de página']);

        app(WelcomePages::class)->create();

        $this->assertDatabaseCount('pages', 6);
        $this->assertSame('Mi contacto', $page->fresh()->title);
        $this->assertSame('<p>Mi texto personal.</p>', $page->fresh()->content[0]['data']['html']);
        $this->assertSame($menus, [Setting::read('navigation'), Setting::read('navigation_footer')]);
        $this->assertSame('Mi presentación', Setting::read('site')['description']);
        $this->assertSame('Mi pie de página', Setting::read('site')['footer_description']);
    }

    public function test_existing_and_trashed_pages_are_not_replaced_by_examples(): void
    {
        $page = Page::factory()->create(['slug' => 'privacidad', 'title' => 'Mi privacidad', 'published' => false]);
        $page->delete();
        $about = Page::where('kind', 'about')->firstOrFail();
        $about->update(['content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Mi historia.</p>']]]]);

        app(WelcomePages::class)->create();

        $this->assertSoftDeleted($page);
        $this->assertSame('Mi privacidad', $page->fresh()->title);
        $this->assertSame('<p>Mi historia.</p>', $about->fresh()->content[0]['data']['html']);
        $this->assertNotContains('Mi privacidad', array_column(app(SiteNavigation::class)->links('footer'), 'label'));
    }

    public function test_refreshing_the_sample_preserves_its_address_author_and_previous_revision(): void
    {
        Storage::fake('media');
        $author = User::factory()->create();
        $post = Post::create(['user_id' => $author->id, 'title' => 'Muestra anterior', 'slug' => 'entrada-de-muestra', 'content' => []]);
        $url = $post->url();
        $previous = $post->revisions()->firstOrFail();

        app(WelcomeSample::class)->refreshExample($post);

        $this->assertDatabaseCount('posts', 1);
        $this->assertSame($url, $post->url());
        $this->assertSame($author->id, $post->user_id);
        $this->assertSame('draft', $post->status);
        $this->assertSame('¿Hay vida más allá de la Tierra?', $post->title);
        $this->assertSame('Muestra anterior', $previous->fresh()->snapshot['title']);
        foreach ($post->media as $media) {
            Storage::disk('media')->assertExists($media->path);
        }
    }

    public function test_refreshing_the_example_refuses_an_unrelated_post(): void
    {
        $author = User::factory()->create();
        $post = Post::create(['user_id' => $author->id, 'title' => 'Mi entrada', 'slug' => 'mi-entrada', 'content' => []]);

        $this->expectException(\RuntimeException::class);
        app(WelcomeSample::class)->refreshExample($post);
    }
}
