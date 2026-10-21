<?php

namespace Tests\Feature;

use App\Filament\Pages\SeoSettings;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\MediaLibrary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private string $accessFile;

    protected function setUp(): void
    {
        $this->accessFile = tempnam(sys_get_temp_dir(), 'sq-seo-access');
        file_put_contents($this->accessFile, json_encode(['path' => 'mi-acceso-privado']));
        $_ENV['SOLQUBIT_ADMIN_ACCESS_FILE'] = $_SERVER['SOLQUBIT_ADMIN_ACCESS_FILE'] = $this->accessFile;
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-seo-installed');
        config(['solqubit.installed_file' => $this->marker]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->accessFile);
        @unlink($this->marker);
        unset($_ENV['SOLQUBIT_ADMIN_ACCESS_FILE'], $_SERVER['SOLQUBIT_ADMIN_ACCESS_FILE']);
        parent::tearDown();
    }

    private function createPost(array $attributes = []): Post
    {
        return Post::create($attributes + [
            'title' => 'Vida en el universo', 'slug' => 'vida-en-el-universo', 'status' => 'published',
            'user_id' => User::factory()->create(['name' => 'Autora Real'])->id,
            'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Exploramos <strong>otros mundos</strong> y aprendemos.</p><p>Una pregunta abierta.</p>']]],
        ]);
    }

    private function graph(string $html): array
    {
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $matches);
        $this->assertArrayHasKey(1, $matches, 'Faltan los datos estructurados.');
        $data = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://schema.org', $data['@context']);

        return collect($data['@graph'])->keyBy('@type')->all();
    }

    public function test_robots_allows_public_content_and_protects_a_custom_admin_path_without_disclosing_it(): void
    {
        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertContent("User-agent: *\nAllow: /\nDisallow: /preview/\n\nSitemap: ".url('/sitemap.xml')."\n")
            ->assertDontSee('mi-acceso-privado')->assertDontSee("Disallow: /\n");
        $this->get('/mi-acceso-privado/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/')->assertOk()->assertSee('index,follow,max-image-preview:large')->assertDontSee('mi-acceso-privado');
    }

    public function test_posts_generate_metadata_and_article_schema_from_real_content_and_author(): void
    {
        $this->freezeTime();
        Setting::write('site', ['name' => 'Mi Blog', 'description' => 'Descripción general']);
        $post = $this->createPost();
        $response = $this->get($post->url().'?utm_source=prueba')->assertOk()
            ->assertSee('<title>Vida en el universo — Mi Blog</title>', false)
            ->assertSee('<meta name="description" content="Exploramos otros mundos y aprendemos. Una pregunta abierta.">', false)
            ->assertSee('rel="canonical" href="'.$post->url().'"', false)
            ->assertSee('name="twitter:card" content="summary"', false);
        $graph = $this->graph($response->getContent());
        $article = $graph['BlogPosting'];
        $this->assertSame('Vida en el universo', $article['headline']);
        $this->assertSame(['@type' => 'Person', 'name' => 'Autora Real'], $article['author']);
        $this->assertSame(now()->toIso8601String(), $article['datePublished']);
        $this->assertSame($article['datePublished'], $article['dateModified']);
        $this->assertSame($post->url(), $article['url']);
        $this->assertArrayNotHasKey('image', $article);
        $this->assertSame($post->url(), $graph['BreadcrumbList']['itemListElement'][1]['item']);
        $this->assertSame('Mi Blog', $graph['WebSite']['name']);
    }

    public function test_custom_metadata_wins_and_is_escaped_in_html_and_json(): void
    {
        Setting::write('site', ['name' => 'Blog & ciencia']);
        $post = $this->createPost(['title' => 'Una "pregunta" & respuesta', 'seo_title' => 'Título elegido',
            'seo_description' => 'Descripción "especial" & propia', 'excerpt' => 'Resumen visible']);
        $post->author->update(['name' => '&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;']);
        $response = $this->get($post->url())->assertOk()->assertSee('<title>Título elegido — Blog &amp; ciencia</title>', false)
            ->assertSee('content="Descripción &quot;especial&quot; &amp; propia"', false)->assertDontSee('<script>alert(1)</script>', false);
        $graph = $this->graph($response->getContent());
        $this->assertSame('Una "pregunta" & respuesta', $graph['BlogPosting']['headline']);
        $this->assertSame('Descripción "especial" & propia', $graph['BlogPosting']['description']);
        $this->assertSame('</script><script>alert(1)</script>', $graph['BlogPosting']['author']['name']);
    }

    public function test_pages_and_entries_use_their_covers_in_social_previews_and_updated_dates_are_truthful(): void
    {
        $this->freezeTime();
        Storage::fake('media');
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('portada.png'), ['alt_text' => 'Un planeta azul']);
        $post = $this->createPost(['cover_id' => $media->id]);
        $published = now()->toIso8601String();
        $this->travel(1)->day();
        $post->update(['featured' => true]);
        $this->assertSame($published, $this->graph($this->get($post->url())->getContent())['BlogPosting']['dateModified']);
        $post->update(['title' => 'Nuevo título']);
        $response = $this->get($post->url())->assertOk()->assertSee('property="og:image" content="'.$media->url().'"', false)
            ->assertSee('name="twitter:image:alt" content="Un planeta azul"', false)->assertSee('summary_large_image');
        $this->assertSame(now()->toIso8601String(), $this->graph($response->getContent())['BlogPosting']['dateModified']);
        $page = Page::factory()->create(['published' => true, 'cover_id' => $media->id, 'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Descripción de mi página.</p>']]]]);
        $response = $this->get($page->url())->assertOk()->assertSee('content="Descripción de mi página."', false)
            ->assertSee('property="og:image" content="'.$media->url().'"', false);
        $this->assertArrayHasKey('WebPage', $this->graph($response->getContent()));
        $this->assertArrayNotHasKey('BlogPosting', $this->graph($response->getContent()));
    }

    public function test_descriptions_fall_back_to_intro_notices_and_site_settings_without_becoming_empty(): void
    {
        Setting::write('site', ['name' => 'Mi Blog', 'description' => 'Descripción del sitio.']);
        $page = Page::factory()->create(['published' => true, 'settings' => ['intro' => 'Presentación de la página.']]);
        $this->get($page->url())->assertOk()->assertSee('name="description" content="Presentación de la página."', false);
        $page->update(['settings' => [], 'content' => [['type' => 'notice', 'data' => ['text' => 'Una **nota** informativa.', 'tone' => 'info']]]]);
        $this->get($page->url())->assertSee('name="description" content="Una nota informativa."', false);
        $page->update(['content' => []]);
        $this->get($page->url())->assertSee('name="description" content="Descripción del sitio."', false);
        Setting::write('site', ['name' => 'Mi Blog']);
        $this->get($page->url())->assertSee('name="description" content="'.$page->title.' — Mi Blog"', false);
    }

    public function test_previews_and_internal_search_have_no_indexable_structured_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $post = $this->createPost(['user_id' => $admin->id, 'status' => 'draft']);
        $page = Page::factory()->create();
        foreach (['/preview/'.$post->id, '/preview/pagina/'.$page->id] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('noindex,nofollow')
                ->assertDontSee('application/ld+json')->assertDontSee('rel="canonical"', false);
        }
        $this->get('/?q=universo')->assertOk()->assertSee('noindex,follow')->assertDontSee('application/ld+json');
        $this->get('/?category=ciencia')->assertOk()->assertSee('noindex,follow')->assertDontSee('application/ld+json');
    }

    public function test_paginated_lists_have_distinct_titles_and_preserve_their_canonical_page(): void
    {
        Page::where('kind', 'home')->firstOrFail()->update(['settings' => ['per_page' => 6, 'show_featured' => false]]);
        for ($i = 1; $i <= 7; $i++) {
            $this->createPost(['slug' => 'entrada-'.$i]);
        }
        $response = $this->get('/?page=2&utm_source=test')->assertOk()->assertSee('· Página 2')
            ->assertSee('rel="canonical" href="'.url('/').'?page=2"', false)->assertDontSee('noindex,follow');
        $this->assertSame(url('/').'?page=2', $this->graph($response->getContent())['CollectionPage']['url']);
        $this->get('/?page=999999')->assertNotFound();
    }

    public function test_sitemap_lists_only_current_published_urls_and_real_modification_dates(): void
    {
        $this->freezeTime();
        $post = $this->createPost(['published_at' => now()->subDay()]);
        $this->createPost(['slug' => 'borrador', 'status' => 'draft']);
        $this->createPost(['slug' => 'programada', 'published_at' => now()->addDay()]);
        $this->createPost(['slug' => 'archivada', 'status' => 'archived']);
        $deleted = $this->createPost(['slug' => 'eliminada']);
        $deleted->delete();
        $page = Page::factory()->create(['slug' => 'pagina-privada']);
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertDontSee('/borrador')->assertDontSee('/programada')->assertDontSee('/archivada')->assertDontSee('/eliminada')->assertDontSee('/pagina-privada');
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $entries = [];
        foreach ($xml->url as $entry) {
            $entries[(string) $entry->loc] = (string) $entry->lastmod;
        }
        $this->assertSame(now()->subDay()->toIso8601String(), $entries[$post->url()]);
        $oldUrl = $post->url();
        $post->update(['slug' => 'nueva-direccion', 'title' => 'Título actualizado']);
        $this->get($oldUrl)->assertStatus(301)->assertRedirect($post->fresh()->url());
        $this->get('/sitemap.xml')->assertDontSee($oldUrl)->assertSee($post->fresh()->url())
            ->assertSee('<lastmod>'.now()->toIso8601String().'</lastmod>', false);
    }

    public function test_large_sitemaps_are_split_without_missing_or_duplicate_urls(): void
    {
        Page::factory()->count(1001)->create(['published' => true]);
        $post = $this->createPost();
        $expected = Page::published()->count() + 1;
        $response = $this->get('/sitemap.xml')->assertOk()->assertSee('<sitemapindex', false);
        $index = simplexml_load_string($response->getContent());
        $this->assertCount(2, $index->sitemap);
        $urls = [];
        foreach ($index->sitemap as $part) {
            $xml = simplexml_load_string($this->get((string) $part->loc)->assertOk()->getContent());
            $this->assertLessThanOrEqual(1000, count($xml->url));
            foreach ($xml->url as $entry) {
                $urls[] = (string) $entry->loc;
            }
        }
        $this->assertCount($expected, $urls);
        $this->assertCount($expected, array_unique($urls));
        $this->assertContains($post->url(), $urls);
        foreach (['0', '3', '999999999999999999999999'] as $part) {
            $this->get('/sitemap-'.$part.'.xml')->assertNotFound();
        }
    }

    public function test_public_domain_and_https_are_used_for_metadata_robots_and_sitemaps(): void
    {
        // Installed over plain HTTP; a request that arrives over HTTPS keeps HTTPS in every link.
        config(['app.url' => 'http://blog.example.test']);
        $post = $this->createPost();
        $this->get('https://blog.example.test/'.$post->slug)->assertOk()
            ->assertSee('rel="canonical" href="https://blog.example.test/'.$post->slug.'"', false);
        $this->get('https://blog.example.test/robots.txt')->assertSee('Sitemap: https://blog.example.test/sitemap.xml');
        $this->get('https://blog.example.test/sitemap.xml')->assertSee('https://blog.example.test/'.$post->slug)->assertDontSee('localhost');
    }

    public function test_admin_can_save_optional_verification_codes_without_allowing_arbitrary_tags(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(SeoSettings::class)->fillForm(['google_verification' => 'google_token-123', 'bing_verification' => 'ABCDEF1234567890'])
            ->set('data.unexpected', '<script>evil()</script>')->call('save')->assertHasNoErrors()->assertNotified('Ajustes SEO guardados');
        $saved = Setting::read('seo');
        $this->assertSame('google_token-123', $saved['google_verification']);
        $this->assertArrayNotHasKey('unexpected', $saved);
        $this->get('/')->assertOk()->assertSee('name="google-site-verification" content="google_token-123"', false)
            ->assertSee('name="msvalidate.01" content="ABCDEF1234567890"', false);
        Livewire::test(SeoSettings::class)->fillForm(['google_verification' => '<meta content="injection">'])->call('save')
            ->assertHasFormErrors(['google_verification'])->assertSee('Pega únicamente el código de verificación');
        $this->assertSame($saved, Setting::read('seo'));
        Livewire::test(SeoSettings::class)->fillForm(['google_verification' => '', 'bing_verification' => ''])->call('save')->assertHasNoErrors();
        $this->get('/')->assertDontSee('google-site-verification')->assertDontSee('msvalidate.01');
    }

    #[TestWith(['editor', true])]
    #[TestWith(['admin', false])]
    public function test_unprivileged_users_cannot_manage_seo_settings(string $role, bool $active): void
    {
        $this->get(SeoSettings::getUrl())->assertRedirect();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $component = Livewire::test(SeoSettings::class);
        $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $active]));
        $component->call('save')->assertForbidden();
        Livewire::test(SeoSettings::class)->assertForbidden();
        $this->assertNull(Setting::read('seo'));
    }

    public function test_invalid_stored_verification_codes_are_not_rendered(): void
    {
        Setting::write('seo', ['google_verification' => '"><script>alert(1)</script>', 'bing_verification' => ['bad']]);
        $this->get('/')->assertOk()->assertDontSee('google-site-verification')->assertDontSee('msvalidate.01')
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_default_share_image_used_when_page_has_no_cover(): void
    {
        Storage::fake('media');
        Setting::write('site', ['name' => 'Mi Blog', 'timezone' => 'UTC']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $image = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('compartir.png', 1200, 630));
        Livewire::test(SeoSettings::class)->fillForm(['default_image_id' => $image->id])->call('save')->assertHasNoFormErrors();
        $this->get('/')->assertOk()->assertSee('<meta property="og:image" content="'.$image->url(), false);
    }

    public function test_noindex_setting(): void
    {
        Setting::write('site', ['name' => 'Mi Blog', 'timezone' => 'UTC']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(SeoSettings::class)->fillForm(['noindex' => true])->call('save')->assertHasNoFormErrors();
        $this->get('/')->assertSee('<meta name="robots" content="noindex', false);
        $this->get('/robots.txt')->assertSee("Disallow: /\n", false)->assertDontSee('Sitemap:');
        Livewire::test(SeoSettings::class)->fillForm(['noindex' => false])->call('save')->assertHasNoFormErrors();
        $this->get('/')->assertDontSee('<meta name="robots" content="noindex', false);
        $this->get('/robots.txt')->assertSee('Allow: /')->assertSee('Sitemap:');
    }

    public function test_default_share_image_cannot_be_deleted_while_in_use(): void
    {
        Storage::fake('media');
        $image = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('compartir.png'));
        Setting::write('seo', ['default_image_id' => $image->id]);
        $this->assertTrue($image->fresh()->isReferenced());
    }
}
