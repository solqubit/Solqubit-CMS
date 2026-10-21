<?php

namespace Tests\Feature;

use App\Filament\Pages;
use App\Filament\Resources;
use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminSearchTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private string $accessFile;

    protected function setUp(): void
    {
        $this->accessFile = tempnam(sys_get_temp_dir(), 'sq-search-access');
        file_put_contents($this->accessFile, json_encode(['path' => 'mi-panel']));
        $_ENV['SOLQUBIT_ADMIN_ACCESS_FILE'] = $_SERVER['SOLQUBIT_ADMIN_ACCESS_FILE'] = $this->accessFile;
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-search-installed');
        config(['solqubit.installed_file' => $this->marker]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        @unlink($this->accessFile);
        unset($_ENV['SOLQUBIT_ADMIN_ACCESS_FILE'], $_SERVER['SOLQUBIT_ADMIN_ACCESS_FILE']);
        parent::tearDown();
    }

    private function results(string $query): Collection
    {
        return Filament::getGlobalSearchProvider()->getResults($query)?->getCategories()->flatten(1) ?? collect();
    }

    private function createPost(User $author, array $attributes = []): Post
    {
        return Post::create($attributes + [
            'title' => 'Vida en el universo', 'slug' => 'vida-universo', 'user_id' => $author->id,
            'status' => 'draft', 'content' => [],
        ]);
    }

    #[TestWith(['seo', 'SEO', Pages\SeoSettings::class])]
    #[TestWith(['robots', 'SEO', Pages\SeoSettings::class])]
    #[TestWith(['  SMTP  ', 'Correo', Pages\MailSettings::class])]
    #[TestWith(['apariencia', 'Colores', Pages\AppearanceSettings::class])]
    #[TestWith(['MENÚ', 'Menús', Pages\NavigationSettings::class])]
    #[TestWith(['adsense', 'Monetización', Pages\MonetizationSettings::class])]
    #[TestWith(['zona horaria', 'General', Pages\GeneralSettings::class])]
    #[TestWith(['prefijos', 'Direcciones', Pages\AddressSettings::class])]
    #[TestWith(['favicon', 'Identidad', Pages\SiteSettings::class])]
    #[TestWith(['visitas', 'Estadísticas', Pages\Statistics::class])]
    #[TestWith(['imagenes', 'Biblioteca', Resources\Media\MediaResource::class])]
    public function test_search_finds_panel_sections_and_common_terms(string $query, string $label, string $section): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(GlobalSearch::class)->set('search', $query)
            ->assertSee('Secciones del panel')->assertSee($label)->assertSee($section::getUrl(), false);
        $this->assertStringContainsString('/mi-panel/', $section::getUrl());
    }

    public function test_search_finds_records_in_each_content_area_and_links_to_their_editor(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Observatorio Autor']);
        $this->actingAs($admin);
        $post = $this->createPost($admin, ['title' => 'Observatorio en el espacio']);
        $page = Page::create(['title' => 'Observatorio contacto', 'slug' => 'observatorio-contacto']);
        $category = Category::create(['name' => 'Observatorio Astronomía', 'slug' => 'observatorio-astronomia']);
        $series = Series::create(['name' => 'Observatorio colección', 'slug' => 'observatorio-coleccion']);
        $tag = Tag::create(['name' => 'Observatorio etiqueta', 'slug' => 'observatorio-etiqueta']);
        $media = Media::create(['uuid' => 'search-image', 'path' => 'search-image.jpg', 'original_name' => 'cielo.jpg',
            'mime_type' => 'image/jpeg', 'size' => 20, 'alt_text' => 'Observatorio en la montaña', 'user_id' => $admin->id]);

        $results = $this->results('Observatorio');
        $this->assertEqualsCanonicalizing([
            Resources\Posts\PostResource::getUrl('edit', ['record' => $post]),
            Resources\Pages\PageResource::getUrl('edit', ['record' => $page]),
            Resources\Categories\CategoryResource::getUrl('edit', ['record' => $category]),
            Resources\Series\SeriesResource::getUrl('edit', ['record' => $series]),
            Resources\Tags\TagResource::getUrl('edit', ['record' => $tag]),
            Resources\Media\MediaResource::getUrl('edit', ['record' => $media]),
            Resources\Users\UserResource::getUrl('edit', ['record' => $admin]),
        ], $results->pluck('url')->all());
        $this->assertSame(['Estado' => 'Borrador'], $results->firstWhere('title', $post->title)->details);
    }

    public function test_posts_can_be_found_by_excerpt_slug_category_and_tag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $post = $this->createPost($admin, ['excerpt' => 'Configura tu observatorio', 'slug' => 'mapa-estelar']);
        $post->categories()->attach(Category::create(['name' => 'Astrofotografía', 'slug' => 'astrofotografia']));
        $post->tags()->attach(Tag::create(['name' => 'Telescopios', 'slug' => 'telescopios']));

        foreach (['observatorio', 'mapa-estelar', 'Astrofotografía', 'Telescopios', 'universo observatorio'] as $query) {
            $this->assertContains($post->title, $this->results($query)->pluck('title')->all());
        }
    }

    public function test_editors_only_receive_sections_and_records_they_can_access(): void
    {
        $editor = User::factory()->create(['role' => 'editor', 'name' => 'Secreto usuario']);
        $post = $this->createPost($editor, ['title' => 'Secreto editorial']);
        Page::create(['title' => 'Secreto página', 'slug' => 'secreto-pagina']);
        $this->actingAs($editor);

        $this->assertSame([$post->title], $this->results('Secreto')->pluck('title')->all());
        foreach (['seo', 'smtp', 'usuarios', 'colores', 'paginas', 'adsense'] as $query) {
            $this->assertCount(0, $this->results($query));
        }
        Livewire::test(GlobalSearch::class)->set('search', 'Secreto')->assertSee($post->title)
            ->assertDontSee('Secreto página')->assertDontSee('Secreto usuario');
        $this->get(Pages\SeoSettings::getUrl())->assertForbidden();
    }

    public function test_guests_and_disabled_accounts_receive_no_results(): void
    {
        $this->assertNull(Filament::getGlobalSearchProvider()->getResults('seo'));
        $this->get(Pages\SeoSettings::getUrl())->assertRedirect();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]));
        $this->assertNull(Filament::getGlobalSearchProvider()->getResults('seo'));
    }

    public function test_search_omits_trash_and_bounds_record_results(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $post = $this->createPost($admin, ['title' => 'Descartado artículo']);
        $post->delete();
        $page = Page::create(['title' => 'Descartado página', 'slug' => 'descartado-pagina']);
        $page->delete();
        $this->assertCount(0, $this->results('Descartado'));
        for ($i = 1; $i <= 10; $i++) {
            $this->createPost($admin, ['title' => 'Observación '.$i, 'slug' => 'observacion-'.$i]);
        }
        $this->assertCount(8, $this->results('Observación'));
    }

    public function test_search_treats_wildcards_as_text_and_escapes_titles(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $post = $this->createPost($admin, ['title' => 'Prueba <script>alert(1)</script>']);
        Livewire::test(GlobalSearch::class)->set('search', 'Prueba')->assertSee($post->title)
            ->assertDontSee('<script>alert(1)</script>', false);
        foreach (['%', '_', "' OR 1=1 --", 'sincoincidencias'] as $query) {
            $this->assertCount(0, $this->results($query));
        }
        $this->assertNull(Filament::getGlobalSearchProvider()->getResults('   '));
        $this->assertNull(Filament::getGlobalSearchProvider()->getResults(str_repeat('a', 161)));
    }
}
