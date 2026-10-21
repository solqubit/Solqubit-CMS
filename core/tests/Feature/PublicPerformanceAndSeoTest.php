<?php

namespace Tests\Feature;

use App\Http\Controllers\SeoController;
use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\MediaLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPerformanceAndSeoTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-performance');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Mi Blog', 'description' => 'Ideas']);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    /**
     * Keep a trailing slash in request URLs; the default helper trims it before the request is sent.
     */
    protected function prepareUrlForRequest($uri)
    {
        $uri = (string) $uri;

        return str_starts_with($uri, 'http') ? $uri : rtrim(url('/'), '/').'/'.ltrim($uri, '/');
    }

    private function createPost(array $attributes = []): Post
    {
        return Post::create($attributes + [
            'user_id' => User::factory()->create(['name' => 'Autora Real'])->id, 'title' => 'Entrada de prueba',
            'slug' => 'entrada-'.fake()->unique()->numerify('######'), 'status' => 'published',
            'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Una canción sobre <strong>órbitas</strong>.</p>']]],
        ]);
    }

    private function settingQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"settings"') || str_contains($query['query'], '`settings`'))->count();
        DB::disableQueryLog();

        return $count;
    }

    public function test_settings_are_read_from_the_cache_and_refreshed_after_writes_and_deletes(): void
    {
        Setting::write('example', ['value' => 1]);
        $this->assertSame(['value' => 1], Setting::read('example'));
        $this->assertSame(0, $this->settingQueries(function (): void {
            Setting::read('example');
            Setting::read('site');
            Setting::read('missing', 'default');
        }));

        Setting::write('example', ['value' => 2]);
        $this->assertSame(['value' => 2], Setting::read('example'));
        Setting::find('example')->delete();
        $this->assertSame('default', Setting::read('example', 'default'));
    }

    public function test_settings_written_in_a_rolled_back_transaction_are_not_served_from_the_cache(): void
    {
        Setting::write('example', 'committed');
        DB::beginTransaction();
        Setting::write('example', 'uncommitted');
        $this->assertSame('uncommitted', Setting::read('example'));
        DB::rollBack();

        $this->assertSame('committed', Setting::read('example'));
    }

    public function test_public_pages_read_settings_from_the_database_at_most_once(): void
    {
        $post = $this->createPost();
        $this->get('/')->assertOk();
        $this->assertSame(0, $this->settingQueries(fn () => $this->get($post->url())->assertOk()));
    }

    public function test_search_matches_visible_text_without_block_names_and_ignores_accents(): void
    {
        $post = $this->createPost(['title' => 'Música del cosmos']);
        $this->assertSame('musica del cosmos una cancion sobre orbitas.', $post->fresh()->search_text);

        $this->get('/?q=paragraph')->assertOk()->assertDontSee('Música del cosmos');
        $this->get('/?q=strong')->assertOk()->assertDontSee('Música del cosmos');
        $this->get('/?q=cancion')->assertOk()->assertSee('Música del cosmos');
        $this->get('/?q=ÓRBITAS')->assertOk()->assertSee('Música del cosmos');
        $this->get('/?q=musica')->assertOk()->assertSee('Música del cosmos');

        $post->update(['content' => [['type' => 'list', 'data' => ['title' => 'Pasos', 'items' => [['text' => 'Instalar **Debian**']], 'ordered' => false]]]]);
        $this->get('/?q=debian')->assertOk()->assertSee('Música del cosmos');
        $this->get('/?q=cancion')->assertOk()->assertDontSee('Música del cosmos');
    }

    public function test_uploaded_images_store_their_dimensions_and_offer_narrower_variants(): void
    {
        Storage::fake('media');
        $library = app(MediaLibrary::class);
        $large = $library->upload(UploadedFile::fake()->image('grande.jpg', 2000, 1000));
        $this->assertSame([2000, 1000], [$large->width, $large->height]);
        $this->assertSame($large->url('card').' 720w, '.$large->url('hero').' 1440w, '.$large->url().' 2000w', $large->srcset());

        $medium = $library->upload(UploadedFile::fake()->image('media.png', 1000, 800));
        $this->assertSame($medium->url('card').' 720w, '.$medium->url().' 1000w', $medium->srcset());

        $small = $library->upload(UploadedFile::fake()->image('pequena.png', 600, 400));
        $this->assertSame('', $small->srcset());
        $this->assertSame('width="600" height="400"', (string) $small->imageAttributes());

        $text = $library->upload(UploadedFile::fake()->createWithContent('notas.txt', 'Hola'));
        $this->assertNull($text->width);
        $this->assertSame('', $text->srcset());
        $this->assertSame('', (string) $text->imageAttributes());

        $missing = Media::create(['uuid' => 'sin-archivo', 'path' => 'sin-archivo.jpg', 'original_name' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size' => 10]);
        $this->assertNull($missing->width);

        $post = $this->createPost(['title' => 'Con portada', 'cover_id' => $large->id]);
        $this->get($post->url())->assertOk()
            ->assertSee('width="2000" height="1000" srcset="'.e($large->srcset()).'" sizes="', false)
            ->assertSee('<meta property="og:image:width" content="2000"><meta property="og:image:height" content="1000">', false);
    }

    public function test_machine_readable_files_are_publicly_cacheable_without_cookies(): void
    {
        $this->createPost();
        foreach (['/robots.txt', '/sitemap.xml', '/sitemap-1.xml', '/feed.xml'] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertSame([], $response->headers->getCookies(), $url);
            $this->assertStringContainsString('public', $response->headers->get('Cache-Control'), $url);
            $this->assertStringContainsString('max-age='.SeoController::PUBLIC_MAX_AGE, $response->headers->get('Cache-Control'), $url);
        }
        $this->assertNotSame([], $this->get('/')->assertOk()->headers->getCookies());
    }

    public function test_addresses_with_a_trailing_slash_redirect_to_a_single_address(): void
    {
        $post = $this->createPost();
        $this->get('/acerca/')->assertRedirect(url('/acerca'))->assertStatus(301);
        $this->get('/series/?page=2')->assertRedirect(url('/series').'?page=2');
        $this->get(rtrim($post->url(), '/').'/')->assertStatus(301)->assertRedirect($post->url());
        $this->get('/')->assertOk();
    }

    public function test_entries_describe_language_publisher_and_category_trail(): void
    {
        $category = Category::create(['name' => 'Astronomía', 'slug' => 'astronomia']);
        $post = $this->createPost(['title' => 'Vida en Marte', 'category_ids' => [$category->id]]);
        $response = $this->get($post->url())->assertOk()->assertSee('<meta property="og:locale" content="es_ES">', false);
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $response->getContent(), $matches);
        $graph = collect(json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR)['@graph'])->keyBy('@type');

        $this->assertSame('es', $graph['BlogPosting']['inLanguage']);
        $this->assertSame(['@id' => route('home').'#organization'], $graph['BlogPosting']['publisher']);
        $this->assertSame('Mi Blog', $graph['Organization']['name']);
        $this->assertSame([
            ['Blog', route('home')],
            ['Astronomía', route('home', ['category' => 'astronomia'])],
            ['Vida en Marte', $post->url()],
        ], array_map(fn (array $item): array => [$item['name'], $item['item']], $graph['BreadcrumbList']['itemListElement']));
    }

    public function test_feed_is_valid_rss_with_self_link_dates_and_authors(): void
    {
        $this->freezeTime();
        $post = $this->createPost(['title' => 'Primera entrada']);
        $content = $this->get('/feed.xml')->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')->getContent();

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $content);
        $feed = simplexml_load_string($content);
        $this->assertNotFalse($feed);
        $channel = $feed->channel;
        $this->assertSame('es', (string) $channel->language);
        $this->assertSame(now()->toRssString(), (string) $channel->lastBuildDate);
        $this->assertSame(route('feed'), (string) $channel->children('http://www.w3.org/2005/Atom')->link->attributes()->href);
        $this->assertSame($post->url(), (string) $channel->item->guid);
        $this->assertSame('true', (string) $channel->item->guid->attributes()->isPermaLink);
        $this->assertSame('Autora Real', (string) $channel->item->children('http://purl.org/dc/elements/1.1/')->creator);
    }

    public function test_robots_keeps_previews_out_of_search_engines(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee("Disallow: /preview/\n", false);
    }
}
