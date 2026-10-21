<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Series;
use App\Models\Setting;
use App\Models\User;
use App\Services\HomeSections;
use App\Services\MediaLibrary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HomeSectionsTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-home-'.bin2hex(random_bytes(8));
        file_put_contents($this->marker, '{}');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Mi blog', 'description' => 'Notas', 'timezone' => 'UTC']);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function home(): Page
    {
        return Page::where('kind', 'home')->firstOrFail();
    }

    private function createPost(string $title, array $attributes = []): Post
    {
        return Post::create($attributes + ['user_id' => $this->admin->id, 'title' => $title, 'slug' => str($title)->slug()->toString(), 'status' => 'published', 'published_at' => now()->subHour(), 'content' => []]);
    }

    /** @param  array<int, array<string, mixed>>  $sections */
    private function saveSections(array $sections): void
    {
        $home = $this->home();
        $home->settings = array_replace($home->settings ?? [], ['sections' => app(HomeSections::class)->validate($sections)]);
        $home->save();
    }

    public function test_old_home_settings_convert_to_equivalent_sections(): void
    {
        $home = $this->home();
        $home->update(['settings' => ['heading' => 'Hola', 'intro' => 'Bienvenida', 'show_featured' => false, 'catalog_title' => 'Mis notas', 'per_page' => 6],
            'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Texto encima del listado.</p>']]]]);
        $this->createPost('Una entrada');

        $sections = app(HomeSections::class)->fromPage($home->fresh());

        $this->assertSame(['welcome', 'text', 'featured', 'latest'], array_column($sections, 'type'));
        $this->assertSame('Hola', $sections[0]['heading']);
        $this->assertFalse($sections[2]['visible']);
        $this->assertSame(['type' => 'latest', 'visible' => true, 'title' => 'Mis notas', 'per_page' => 6, 'show_search' => true, 'show_filter' => true], $sections[3]);
        $this->get('/')->assertOk()->assertSeeInOrder(['Hola', 'Texto encima del listado.', 'Mis notas', 'Una entrada']);
    }

    public function test_home_renders_sections_in_their_order(): void
    {
        $this->createPost('Primera entrada');
        $this->saveSections([
            ['type' => 'latest', 'visible' => true, 'title' => 'Lo último'],
            ['type' => 'welcome', 'visible' => true, 'heading' => 'Bienvenida abajo', 'show_heading' => true],
        ]);

        $this->get('/')->assertOk()->assertSeeInOrder(['Lo último', 'Primera entrada', 'Bienvenida abajo']);
    }

    public function test_hidden_sections_are_not_rendered(): void
    {
        $this->saveSections([
            ['type' => 'welcome', 'visible' => false, 'heading' => 'Saludo oculto', 'show_heading' => true],
            ['type' => 'latest', 'visible' => true],
        ]);

        $this->get('/')->assertOk()->assertDontSee('Saludo oculto')->assertSee('class="sr-only"', false);
    }

    public function test_featured_section_uses_chosen_post_or_latest(): void
    {
        $chosen = $this->createPost('Elegida', ['published_at' => now()->subDays(3)]);
        $latest = $this->createPost('Más reciente');
        $this->saveSections([['type' => 'featured', 'visible' => true, 'post_id' => $chosen->id, 'fallback_latest' => true], ['type' => 'latest', 'visible' => true]]);

        $response = $this->get('/')->assertOk();
        $this->assertSame($chosen->id, $response->viewData('featured')?->id);
        $this->assertNotContains($chosen->id, $response->viewData('posts')->pluck('id')->all());

        $this->saveSections([['type' => 'featured', 'visible' => true, 'post_id' => null, 'fallback_latest' => true], ['type' => 'latest', 'visible' => true]]);
        $this->assertSame($latest->id, $this->get('/')->viewData('featured')?->id);

        $this->saveSections([['type' => 'featured', 'visible' => true, 'post_id' => null, 'fallback_latest' => false], ['type' => 'latest', 'visible' => true]]);
        $this->assertNull($this->get('/')->viewData('featured'));
    }

    public function test_search_and_later_pages_skip_selected_sections(): void
    {
        $category = Category::create(['name' => 'Astronomía', 'slug' => 'astronomia']);
        foreach (range(1, 8) as $number) {
            $this->createPost('Entrada '.$number, ['category_ids' => [$category->id]]);
        }
        $this->saveSections([
            ['type' => 'welcome', 'visible' => true, 'heading' => 'Saludo', 'show_heading' => true],
            ['type' => 'featured', 'visible' => true, 'post_id' => null, 'fallback_latest' => true],
            ['type' => 'topic', 'visible' => true, 'category_id' => $category->id, 'title' => 'Sección de tema', 'count' => 3],
            ['type' => 'latest', 'visible' => true, 'per_page' => 6],
        ]);

        $this->get('/')->assertOk()->assertSee('Sección de tema')->assertSee('Entrada destacada');
        foreach (['/?q=Entrada', '/?page=2'] as $url) {
            $this->get($url)->assertOk()->assertSee('Saludo')->assertDontSee('Sección de tema')->assertDontSee('Entrada destacada');
        }
    }

    public function test_topic_section_lists_category_posts_and_skips_deleted_category(): void
    {
        $category = Category::create(['name' => 'Viajes', 'slug' => 'viajes']);
        $this->createPost('Viaje a Roma', ['category_ids' => [$category->id]]);
        $this->createPost('Otra cosa');
        $this->saveSections([['type' => 'topic', 'visible' => true, 'category_id' => $category->id, 'count' => 3]]);

        $this->get('/')->assertOk()->assertSee('Viajes')->assertSee('Viaje a Roma')->assertDontSee('Otra cosa')->assertSee('category=viajes', false);

        $category->delete();
        $this->get('/')->assertOk()->assertDontSee('Viaje a Roma');
    }

    public function test_series_section_lists_series_with_published_posts(): void
    {
        $series = Series::create(['name' => 'Mi colección', 'slug' => 'mi-coleccion']);
        $this->createPost('Capítulo uno', ['series_id' => $series->id]);
        $this->saveSections([['type' => 'series', 'visible' => true, 'title' => 'Colecciones', 'count' => 3]]);

        $this->get('/')->assertOk()->assertSee('Colecciones')->assertSee('Mi colección')->assertSee('Capítulo uno');
    }

    public function test_unknown_stored_section_types_are_ignored(): void
    {
        $home = $this->home();
        $home->settings = array_replace($home->settings ?? [], ['sections' => [['type' => 'retirada', 'visible' => true], ['type' => 'latest', 'visible' => true, 'title' => 'Sigue funcionando']]]);
        $home->saveQuietly();

        $this->get('/')->assertOk()->assertSee('Sigue funcionando');
    }

    public function test_only_one_welcome_featured_or_ad_section_is_accepted(): void
    {
        foreach (['welcome', 'featured', 'ad'] as $type) {
            try {
                app(HomeSections::class)->validate([['type' => $type, 'visible' => true], ['type' => $type, 'visible' => true]]);
                $this->fail('Se aceptaron dos secciones '.$type);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_later_pages_without_a_listing_are_not_found(): void
    {
        foreach (range(1, 13) as $number) {
            $this->createPost('Entrada '.$number);
        }
        $this->saveSections([['type' => 'welcome', 'visible' => true, 'heading' => 'Solo saludo', 'show_heading' => true]]);

        $this->get('/')->assertOk()->assertSee('Solo saludo');
        $this->get('/?page=2')->assertNotFound();
    }

    public function test_first_text_section_keeps_its_anchors(): void
    {
        $this->saveSections([['type' => 'text', 'visible' => true, 'content' => [['type' => 'heading', 'data' => ['text' => 'Sobre este blog', 'level' => 'h2']]]]]);

        $this->get('/')->assertOk()->assertSee('id="seccion-0"', false);
    }

    public function test_saving_two_latest_sections_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(HomeSections::class)->validate([['type' => 'latest', 'visible' => true], ['type' => 'latest', 'visible' => true]]);
    }

    public function test_unknown_section_types_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(HomeSections::class)->validate([['type' => 'script', 'visible' => true]]);
    }

    public function test_text_section_media_is_attached_to_home(): void
    {
        $this->actingAs($this->admin);
        $image = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('foto.png', 40, 30));
        $this->saveSections([['type' => 'text', 'visible' => true, 'content' => [['type' => 'image', 'data' => ['media_id' => $image->id]]]]]);

        $this->assertContains($image->id, $this->home()->media()->pluck('media.id')->all());
        $this->assertTrue($image->fresh()->isReferenced());
        $this->get('/')->assertOk()->assertSee($image->url('hero'), false);
    }

    public function test_ad_section_renders_home_bottom_slot(): void
    {
        Setting::write('monetization', ['status' => 'active', 'publisher_id' => 'ca-pub-1234567890123456', 'format' => 'manual', 'privacy_ready' => true, 'home_bottom' => '1234567890', 'article_top' => '', 'article_bottom' => '']);
        $this->createPost('Con anuncio');
        $this->saveSections([['type' => 'ad', 'visible' => true], ['type' => 'latest', 'visible' => true, 'title' => 'Debajo del anuncio']]);

        $this->get('/')->assertOk()->assertSeeInOrder(['data-ad-placement="home_bottom"', 'Debajo del anuncio'], false);
    }
}
