<?php

namespace Tests\Feature;

use App\Filament\Pages\HomeSettings;
use App\Filament\Pages\NavigationSettings;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Series;
use App\Models\Setting;
use App\Models\User;
use App\Services\HomeSections;
use App\Services\MediaLibrary;
use App\Services\PostPermalinks;
use App\Services\SiteNavigation;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SitePagesTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-pages-'.bin2hex(random_bytes(8));
        file_put_contents($this->marker, '{}');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Mi canal', 'description' => 'Mis apuntes', 'timezone' => 'UTC']);
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
        return Post::create($attributes + ['user_id' => $this->admin->id, 'title' => $title, 'slug' => str($title)->slug()->toString(), 'status' => 'published', 'content' => []]);
    }

    public function test_home_settings_saves_welcome_and_listing(): void
    {
        $page = $this->home();
        $this->actingAs($this->admin);
        Livewire::test(HomeSettings::class)->set('data.sections', app(HomeSections::class)->toBuilder([
            ['type' => 'welcome', 'visible' => true, 'heading' => 'Ideas para crear', 'accent_heading' => 'con inteligencia artificial',
                'intro' => 'Comandos y notas de mis videos.', 'icon' => 'terminal', 'show_heading' => true, 'show_intro' => true],
            ['type' => 'latest', 'visible' => true, 'title' => 'Las notas del canal', 'per_page' => 6, 'show_search' => true, 'show_filter' => true],
        ]))->call('save')->assertHasNoErrors();
        $this->assertSame('terminal', $page->fresh()->option('icon'));
        $this->assertEquals(6, $page->fresh()->option('per_page'));
        $this->get('/')->assertOk()->assertSee('Ideas para crear')->assertSee('con inteligencia artificial')
            ->assertSee('Comandos y notas de mis videos.')->assertSee('Las notas del canal');
    }

    public function test_heading_and_description_can_be_hidden_without_empty_intro_markup(): void
    {
        $page = $this->home();
        $page->update(['settings' => array_replace($page->settings, ['show_heading' => false, 'show_intro' => false])]);
        $this->get('/')->assertOk()->assertDontSee('journal-intro"')
            ->assertDontSee('journal-intro-note')->assertDontSee('Tecnología,')
            ->assertSee('<h1 class="sr-only" id="home-heading">Inicio</h1>', false);
    }

    public function test_icon_can_be_hidden_while_description_remains_visible(): void
    {
        $page = $this->home();
        $page->update(['settings' => array_replace($page->settings, ['icon' => 'none', 'intro' => 'Mi descripción personal'])]);
        $this->get('/')->assertOk()->assertSee('Mi descripción personal')->assertDontSee('intro-spark');
    }

    public function test_custom_page_can_be_created_with_blocks_and_added_to_the_menu(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreatePage::class)->fillForm([
            'title' => 'Contacto', 'slug' => 'contacto', 'published' => true, 'in_menu' => true,
            'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Escríbeme para colaborar.</p>']]],
        ])->call('create')->assertHasNoFormErrors();
        $page = Page::where('slug', 'contacto')->firstOrFail();
        $this->assertTrue(app(SiteNavigation::class)->contains($page));
        $this->get('/contacto')->assertOk()->assertSee('Escríbeme para colaborar.')
            ->assertSee('rel="canonical" href="'.url('/contacto').'"', false);
        $this->get('/pagina/contacto?utm_source=video')->assertStatus(301)->assertRedirect(url('/contacto').'?utm_source=video');
        $this->get('/')->assertSee('href="'.route('pages.show', 'contacto').'"', false);
        $this->get('/sitemap.xml')->assertSee(url('/contacto'))->assertDontSee('/pagina/contacto');
        Livewire::test(EditPage::class, ['record' => $page->id])->assertSee('URL completa: '.url('/contacto'));
    }

    public function test_pages_and_posts_work_at_the_root_with_compiled_routes(): void
    {
        Page::factory()->create(['title' => 'Mi contacto', 'slug' => 'contacto', 'published' => true]);
        $post = $this->createPost('Artículo sin prefijo', ['url_prefix' => '']);
        $router = app('router');
        $routes = $router->getRoutes();
        $router->setCompiledRoutes($routes->compile());
        try {
            $this->get('/contacto')->assertOk()->assertSee('Mi contacto');
            $this->get('/pagina/contacto')->assertStatus(301)->assertRedirect(url('/contacto'));
            $response = $this->get($post->url())->assertOk()->assertSee('Artículo sin prefijo');
            $this->assertTrue(collect($response->viewData('navigation'))->firstWhere('label', 'Blog')['active']);
            $this->get('/series')->assertOk();
            $this->get('/acerca')->assertOk();
            $this->get('/admin/login')->assertOk();
            $this->get('/no-existe')->assertNotFound();
        } finally {
            $router->setRoutes($routes);
        }
    }

    #[TestWith(['admin'])]
    #[TestWith(['install'])]
    #[TestWith(['media'])]
    #[TestWith(['solqubit-core'])]
    public function test_page_editor_rejects_reserved_root_addresses(string $slug): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreatePage::class)->fillForm([
            'title' => 'Página nueva', 'slug' => $slug, 'published' => true,
        ])->call('create')->assertHasFormErrors(['slug'])
            ->assertSee('Esta dirección está reservada para una sección del sitio. Elige otro nombre.');
        $this->assertDatabaseMissing('pages', ['slug' => $slug]);
    }

    public function test_pages_cannot_take_current_or_historical_post_addresses(): void
    {
        $post = $this->createPost('Soporte', ['url_prefix' => '']);
        $post->update(['slug' => 'ayuda']);
        $this->actingAs($this->admin);
        foreach (['soporte', 'ayuda'] as $slug) {
            Livewire::test(CreatePage::class)->fillForm([
                'title' => 'Información', 'slug' => $slug,
            ])->call('create')->assertHasFormErrors(['slug'])
                ->assertSee('Esta dirección pertenece a una entrada o a uno de sus enlaces anteriores. Elige otro nombre para la página.');
            $this->assertDatabaseMissing('pages', ['slug' => $slug]);
        }
        $this->get('/soporte')->assertStatus(301)->assertRedirect(url('/ayuda'));
    }

    public function test_posts_need_a_prefix_to_reuse_a_page_address_even_while_the_page_is_in_trash(): void
    {
        $page = Page::factory()->create(['slug' => 'contacto']);
        $page->delete();
        app(PostPermalinks::class)->configure([['label' => 'Blog', 'slug' => 'blog']], '');
        $post = $this->createPost('La entrada', ['slug' => 'contacto', 'url_prefix' => 'blog']);
        $this->actingAs($this->admin);
        Livewire::test(EditPost::class, ['record' => $post->id])
            ->fillForm(['url_prefix' => '__none'])->call('save')->assertHasFormErrors(['slug'])
            ->assertSee('Esta dirección pertenece a una página. Elige otro nombre o un prefijo para la entrada.');
        $this->assertSame('blog', $post->fresh()->url_prefix);
        $this->get('/blog/contacto')->assertOk();
        $this->get('/contacto')->assertNotFound();
        $this->get('/pagina/contacto')->assertNotFound();
    }

    public function test_existing_conflicting_pages_keep_their_old_address_until_renamed(): void
    {
        $post = $this->createPost('La entrada existente', ['slug' => 'contacto', 'url_prefix' => '']);
        $page = Page::factory()->create(['title' => 'Contacto anterior', 'slug' => 'otro-contacto', 'published' => true]);
        DB::table('pages')->where('id', $page->id)->update(['slug' => 'contacto']);
        $page->refresh();
        $this->assertSame(url('/pagina/contacto'), $page->url());
        $this->get('/pagina/contacto')->assertOk()->assertSee('Contacto anterior');
        $this->get('/contacto')->assertOk()->assertSee('La entrada existente');
        $post->update(['title' => 'Entrada actualizada']);
        $this->actingAs($this->admin);
        Livewire::test(EditPage::class, ['record' => $page->id])->assertSee('URL completa: '.url('/pagina/contacto'))
            ->fillForm(['title' => 'Página actualizada'])->call('save')->assertHasNoFormErrors();
        $page->refresh()->update(['slug' => 'hablemos']);
        $this->get('/hablemos')->assertOk()->assertSee('Página actualizada');
        $this->get('/pagina/hablemos')->assertStatus(301)->assertRedirect(url('/hablemos'));
        $this->get('/contacto')->assertOk()->assertSee('Entrada actualizada');
    }

    public function test_menu_visibility_is_independent_of_publication(): void
    {
        $page = Page::factory()->create(['title' => 'Documentación adicional', 'slug' => 'documentacion', 'published' => true]);
        $this->get('/documentacion')->assertOk();
        $this->get('/')->assertDontSee('Documentación adicional');
        $this->actingAs($this->admin);
        Livewire::test(EditPage::class, ['record' => $page->id])->fillForm(['in_menu' => true])->call('save')->assertHasNoFormErrors();
        $this->get('/')->assertSee('Documentación adicional');
        Livewire::test(EditPage::class, ['record' => $page->id])->fillForm(['in_menu' => false])->call('save')->assertHasNoFormErrors();
        $this->get('/')->assertDontSee('Documentación adicional');
        $this->get('/documentacion')->assertOk();
    }

    public function test_drafts_have_private_previews_and_are_excluded_from_menu_and_sitemap(): void
    {
        $page = Page::factory()->create(['title' => 'Página privada', 'slug' => 'pagina-privada']);
        app(SiteNavigation::class)->setPage($page, true);
        $this->get('/pagina/pagina-privada')->assertNotFound();
        $this->get('/pagina-privada')->assertNotFound();
        $this->get('/')->assertDontSee('Página privada');
        $this->get('/sitemap.xml')->assertDontSee(url('/pagina-privada'));
        $this->get(route('pages.preview', $page))->assertRedirect('/admin/login');
        $this->actingAs($this->admin)->get(route('pages.preview', $page))->assertOk()
            ->assertSee('noindex,nofollow')->assertSee('Vista previa de la versión guardada');
    }

    public function test_series_and_about_headers_are_editable_and_pages_can_be_disabled(): void
    {
        $this->actingAs($this->admin);
        foreach (['series' => '/series', 'about' => '/acerca'] as $kind => $url) {
            $page = Page::where('kind', $kind)->firstOrFail();
            Livewire::test(EditPage::class, ['record' => $page->id])->fillForm([
                'settings.heading' => 'Título de '.$kind, 'settings.intro' => 'Descripción personal',
                'settings.show_intro' => true,
            ])->call('save')->assertHasNoFormErrors();
            $this->get($url)->assertOk()->assertSee('Título de '.$kind)->assertSee('Descripción personal');
            $page->refresh()->update(['published' => false]);
            $this->get($url)->assertNotFound();
            $this->get('/sitemap.xml')->assertDontSee(url($url));
        }
    }

    public function test_menu_preserves_order_labels_and_safe_external_links(): void
    {
        $this->actingAs($this->admin);
        $entries = [
            ['type' => 'url', 'url' => 'https://www.youtube.com/', 'label' => 'Mi canal', 'enabled' => true, 'new_tab' => true],
            ['type' => 'page', 'page_id' => $this->home()->id, 'label' => 'Apuntes', 'enabled' => true, 'new_tab' => false],
        ];
        Livewire::test(NavigationSettings::class)->fillForm(['items' => $entries])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['Mi canal', 'Apuntes'], array_column(app(SiteNavigation::class)->links(), 'label'));
        $this->get('/')->assertOk()->assertSee('href="https://www.youtube.com/"', false)
            ->assertSee('target="_blank" rel="noopener noreferrer"', false)->assertSee('Apuntes');
    }

    public function test_unsafe_menu_links_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(SiteNavigation::class)->save([['type' => 'url', 'url' => 'javascript:alert(1)', 'label' => 'No válido', 'enabled' => true, 'new_tab' => false]]);
    }

    public function test_empty_menu_does_not_restore_default_links(): void
    {
        app(SiteNavigation::class)->saveMenus([], []);
        $this->assertSame([], app(SiteNavigation::class)->links());
        $this->get('/')->assertOk()->assertDontSee('href="'.route('series').'"', false);
    }

    public function test_header_and_footer_can_be_ordered_independently_from_the_panel(): void
    {
        $this->actingAs($this->admin);
        $contact = Page::factory()->create(['title' => 'Contacto', 'published' => true]);
        $privacy = Page::factory()->create(['title' => 'Privacidad', 'published' => true]);
        $entry = fn (Page $page, string $label): array => ['type' => 'page', 'page_id' => $page->id, 'label' => $label, 'enabled' => true, 'new_tab' => false];

        Livewire::test(NavigationSettings::class)->fillForm([
            'items' => [$entry($contact, 'Hablemos'), $entry($this->home(), 'Inicio')],
            'footer_items' => [$entry($privacy, 'Privacidad'), $entry($contact, 'Contacto')],
        ])->call('save')->assertHasNoFormErrors();

        $response = $this->get('/')->assertOk();
        $this->assertSame(['Hablemos', 'Inicio'], array_column($response->viewData('navigation'), 'label'));
        $this->assertSame(['Privacidad', 'Contacto'], array_column($response->viewData('footerNavigation'), 'label'));
        $panel = Livewire::test(NavigationSettings::class);
        $this->assertSame(['Hablemos', 'Inicio'], array_column($panel->get('data.items'), 'label'));
        $this->assertSame(['Privacidad', 'Contacto'], array_column($panel->get('data.footer_items'), 'label'));
    }

    public function test_a_page_can_appear_only_in_the_footer_and_be_hidden_when_unpublished(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreatePage::class)->fillForm([
            'title' => 'Privacidad', 'slug' => 'privacidad', 'published' => true,
            'in_menu' => false, 'in_footer' => true,
        ])->call('create')->assertHasNoFormErrors();
        $page = Page::where('slug', 'privacidad')->firstOrFail();
        $navigation = app(SiteNavigation::class);
        $this->assertFalse($navigation->contains($page));
        $this->assertTrue($navigation->contains($page, 'footer'));
        $this->assertContains('Privacidad', array_column($this->get('/')->viewData('footerNavigation'), 'label'));

        Livewire::test(EditPage::class, ['record' => $page->id])->fillForm(['published' => false])->call('save')->assertHasNoFormErrors();

        $this->assertNotContains('Privacidad', array_column($this->get('/')->viewData('footerNavigation'), 'label'));
        $this->assertTrue($navigation->contains($page, 'footer'));
    }

    public function test_a_full_footer_rolls_back_the_new_page_and_its_header_link(): void
    {
        $navigation = app(SiteNavigation::class);
        $navigation->save(array_fill(0, 12, ['type' => 'url', 'url' => 'https://example.com', 'label' => 'Enlace', 'enabled' => true, 'new_tab' => false]), 'footer');
        $header = $navigation->entries();
        $this->actingAs($this->admin);

        Livewire::test(CreatePage::class)->fillForm([
            'title' => 'Sin espacio', 'slug' => 'sin-espacio', 'published' => true, 'in_menu' => true, 'in_footer' => true,
        ])->call('create')->assertHasFormErrors(['in_footer']);

        $this->assertDatabaseMissing('pages', ['slug' => 'sin-espacio']);
        $this->assertSame($header, $navigation->entries());
    }

    public function test_invalid_footer_links_do_not_partially_save_the_header(): void
    {
        $navigation = app(SiteNavigation::class);
        $header = $navigation->entries();
        try {
            $navigation->saveMenus([], [['type' => 'url', 'url' => 'javascript:alert(1)', 'label' => 'No válido', 'enabled' => true, 'new_tab' => false]]);
            $this->fail('Se aceptó un enlace inseguro.');
        } catch (ValidationException) {
            $this->assertSame($header, $navigation->entries());
            $this->assertNull(Setting::read('navigation_footer'));
        }
    }

    public function test_deleted_pages_do_not_block_editing_the_menu(): void
    {
        $page = Page::factory()->create(['title' => 'Temporal', 'published' => true]);
        $navigation = app(SiteNavigation::class);
        $navigation->setPage($page, true);
        $page->delete();
        $this->get('/')->assertDontSee('Temporal');
        $page->restore();
        $this->get('/')->assertSee('Temporal');
        $page->forceDelete();
        $navigation->save($navigation->entries());
        $navigation->setPage($this->home(), true);
        $this->assertCount(3, $navigation->entries());
    }

    public function test_pages_can_be_deleted_from_the_list_and_restored_from_the_trash(): void
    {
        $page = Page::factory()->create(['title' => 'Página recuperable', 'published' => true]);
        $navigation = app(SiteNavigation::class);
        $navigation->setPage($page, true);
        $navigation->setPage($page, true, 'footer');
        $this->actingAs($this->admin);

        $list = Livewire::test(ListPages::class)
            ->assertCanSeeTableRecords([$page])
            ->assertActionVisible(TestAction::make('delete')->table($page))
            ->assertActionHidden(TestAction::make('forceDelete')->table($page))
            ->callAction(TestAction::make('delete')->table($page))
            ->assertHasNoErrors()->assertNotified('Página movida a la papelera')
            ->assertCanNotSeeTableRecords([$page]);

        $this->assertSoftDeleted($page);
        $this->get($page->url())->assertNotFound();
        $this->get('/')->assertDontSee('Página recuperable');
        $this->get('/sitemap.xml')->assertDontSee($page->url());

        $list->set('activeTab', 'trash')->assertCanSeeTableRecords([$page])
            ->assertCanNotSeeTableRecords([$this->home()])
            ->assertActionHidden(TestAction::make('delete')->table($page))
            ->assertActionHidden(TestAction::make('preview')->table($page))
            ->callAction(TestAction::make('restore')->table($page))->assertHasNoErrors()
            ->assertNotified('Página restaurada')->assertCanNotSeeTableRecords([$page]);

        $this->assertNotSoftDeleted($page);
        $this->get($page->url())->assertOk();
        foreach (['header', 'footer'] as $location) {
            $this->assertContains($page->url(), array_column($navigation->links($location), 'url'));
        }
    }

    public function test_page_editor_can_send_a_custom_page_to_the_trash(): void
    {
        $page = Page::factory()->create();
        $this->actingAs($this->admin);

        Livewire::test(EditPage::class, ['record' => $page->id])
            ->assertActionVisible('delete')->callAction('delete')->assertHasNoErrors()
            ->assertNotified('Página movida a la papelera')->assertRedirect('/admin/pages');

        $this->assertSoftDeleted($page);
    }

    public function test_permanent_page_deletion_preserves_the_media_library(): void
    {
        Storage::fake('media');
        $this->actingAs($this->admin);
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('pagina.png'));
        $page = Page::factory()->create(['cover_id' => $media->id]);
        $page->delete();

        Livewire::test(ListPages::class)->set('activeTab', 'trash')
            ->assertActionVisible(TestAction::make('forceDelete')->table($page))
            ->callAction(TestAction::make('forceDelete')->table($page))->assertHasNoErrors()
            ->assertNotified('Página eliminada definitivamente')->assertCanNotSeeTableRecords([$page]);

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
        Storage::disk('media')->assertExists($media->path);
        $this->assertFalse($media->fresh()->isReferenced());
    }

    public function test_system_pages_do_not_offer_deletion_and_reject_a_direct_action_call(): void
    {
        $this->actingAs($this->admin);
        $this->assertFalse($this->admin->can('delete', $this->home()));
        foreach (Page::whereIn('kind', ['series', 'about'])->get() as $page) {
            $this->assertFalse($this->admin->can('delete', $page));
            $this->assertFalse($this->admin->can('forceDelete', $page));
            Livewire::test(ListPages::class)
                ->assertActionHidden(TestAction::make('delete')->table($page))
                ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $page->id]);
            Livewire::test(EditPage::class, ['record' => $page->id])->assertActionHidden('delete');
            $this->assertNotSoftDeleted($page);
        }
    }

    public function test_a_full_menu_does_not_leave_a_partially_created_page(): void
    {
        app(SiteNavigation::class)->save(array_fill(0, 12, ['type' => 'url', 'url' => 'https://example.com', 'label' => 'Enlace', 'enabled' => true, 'new_tab' => false]));
        $this->get('/')->assertOk()->assertSee('nav-more-links');
        $this->actingAs($this->admin);
        Livewire::test(CreatePage::class)->fillForm([
            'title' => 'Sin espacio', 'slug' => 'sin-espacio', 'published' => true, 'in_menu' => true,
        ])->call('create')->assertHasFormErrors(['in_menu']);
        $this->assertDatabaseMissing('pages', ['slug' => 'sin-espacio']);
    }

    public function test_only_administrators_can_manage_site_pages_and_menu(): void
    {
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);
        $this->actingAs($editor)->get('/admin/pages')->assertForbidden();
        $this->get('/admin/ajustes/menus')->assertForbidden();
        $this->get(route('pages.preview', $this->home()))->assertForbidden();
    }

    public function test_numbered_pagination_does_not_duplicate_or_skip_posts_and_preserves_filters(): void
    {
        $this->freezeTime();
        $page = $this->home();
        $page->update(['settings' => array_replace($page->settings, ['per_page' => 6])]);
        $category = Category::create(['name' => 'Linux', 'slug' => 'linux']);
        $featured = $this->createPost('Linux destacado', ['featured' => true, 'category_id' => $category->id]);
        $ids = [];
        for ($i = 1; $i <= 14; $i++) {
            $ids[] = $this->createPost('Linux entrada '.$i, ['category_id' => $category->id])->id;
        }
        $seen = [];
        for ($number = 1; $number <= 3; $number++) {
            $response = $this->get('/?page='.$number)->assertOk();
            $canonicalUrl = url('/').($number > 1 ? '?page='.$number : '');
            $response->assertSee('rel="canonical" href="'.$canonicalUrl.'"', false)->assertDontSee('noindex,follow');
            $this->assertSame(15, $response->viewData('totalEntries'));
            $posts = $response->viewData('posts');
            $this->assertSame(6, $posts->perPage());
            $seen = array_merge($seen, $posts->pluck('id')->all());
            $this->assertSame($number === 1 ? $featured->id : null, $response->viewData('featured')?->id);
            $response->assertSee('aria-current="page"', false)->assertSee('Página '.$number.' de 3');
        }
        $this->assertEqualsCanonicalizing($ids, $seen);
        $this->assertCount(14, array_unique($seen));
        $filtered = $this->get('/?q=Linux&category=linux&page=2')->assertOk();
        $this->assertSame(15, $filtered->viewData('posts')->total());
        $filtered->assertSee('q=Linux&amp;category=linux&amp;page=3', false);
        $filtered->assertSee('rel="canonical" href="'.url('/').'?q=Linux&amp;category=linux&amp;page=2"', false)->assertSee('noindex,follow');
        $page->update(['settings' => array_replace($page->settings, ['show_featured' => false])]);
        $this->assertSame(15, $this->get('/')->viewData('posts')->total());
    }

    public function test_page_media_visibility_and_deletion_follow_the_page(): void
    {
        Storage::fake('media');
        $this->actingAs($this->admin);
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('portada.png'));
        $page = Page::factory()->create(['cover_id' => $media->id, 'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Texto seguro</p><script>alert(1)</script>']]]]);
        $this->assertFalse($media->fresh()->isPublic());
        $this->assertTrue($media->fresh()->isReferenced());
        $this->assertStringNotContainsString('<script', $page->fresh()->content[0]['data']['html']);
        $page->update(['published' => true]);
        $this->assertTrue($media->fresh()->isPublic());
        $this->expectException(ValidationException::class);
        $media->delete();
    }

    public function test_invalid_pagination_does_not_render_an_unbounded_number_of_links(): void
    {
        $featured = $this->createPost('Destacada', ['featured' => true]);
        for ($i = 1; $i <= 13; $i++) {
            $this->createPost('Entrada '.$i);
        }
        $this->get('/?page=99999999')->assertNotFound();
        $this->get('/series?page=99999999')->assertNotFound();
        $this->assertSame($featured->id, $this->get('/?page=0')->assertOk()->viewData('featured')?->id);
    }

    public function test_series_pagination_and_page_files_are_rendered(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $series = Series::create(['name' => 'Serie '.$i, 'slug' => 'serie-'.$i]);
            $this->createPost('Entrada de la serie '.$i, ['series_id' => $series->id]);
        }
        $this->get('/series')->assertOk()->assertSee('Página 1 de 2');
        $response = $this->get('/series?page=2')->assertOk()->assertSee('Página 2 de 2');
        $response->assertSee('rel="canonical" href="'.url('/series').'?page=2"', false);
        $this->assertCount(1, $response->viewData('series'));

        Storage::fake('media');
        $this->actingAs($this->admin);
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->createWithContent('nota.txt', 'Archivo de apoyo.'));
        $page = Page::factory()->create(['published' => true, 'content' => [['type' => 'file', 'data' => ['media_id' => $media->id]]]]);
        auth()->logout();
        $this->get($page->url())->assertOk()->assertSee('nota.txt')->assertSee('Descargar');
        $this->get($media->url())->assertOk();
        $page->update(['published' => false]);
        $this->get($media->url())->assertNotFound();
    }

    public function test_builtin_pages_cannot_be_deleted(): void
    {
        $this->assertFalse($this->admin->can('delete', $this->home()));
        $this->expectException(ValidationException::class);
        $this->home()->delete();
    }

    public function test_home_cannot_be_unpublished(): void
    {
        $this->expectException(ValidationException::class);
        $this->home()->update(['published' => false]);
    }

    public function test_pages_list_hides_home(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ListPages::class)->assertCanNotSeeTableRecords([$this->home()])
            ->assertCanSeeTableRecords(Page::whereIn('kind', ['series', 'about'])->get());
    }

    public function test_editing_home_redirects_to_settings(): void
    {
        $this->actingAs($this->admin)->get('/admin/pages/'.$this->home()->id.'/edit')->assertRedirect('/admin/ajustes/portada');
        $this->get('/admin/ajustes/portada')->assertOk()->assertSee('Bienvenida')->assertSee('Últimas entradas');
    }

    public function test_custom_page_can_use_system_slugs(): void
    {
        $this->actingAs($this->admin);
        foreach (['sobre-mi', 'inicio'] as $slug) {
            Livewire::test(CreatePage::class)->fillForm(['title' => 'Página '.$slug, 'slug' => $slug, 'published' => true])
                ->call('create')->assertHasNoFormErrors();
            $this->get('/'.$slug)->assertOk()->assertSee('Página '.$slug);
        }
    }

    public function test_trashed_page_still_reserves_slug_with_clear_message(): void
    {
        $this->actingAs($this->admin);
        Page::factory()->create(['slug' => 'contacto'])->delete();
        Livewire::test(CreatePage::class)->fillForm(['title' => 'Contacto', 'slug' => 'contacto'])
            ->call('create')->assertHasFormErrors(['slug'])->assertSee('papelera');
    }

    public function test_home_title_is_site_name(): void
    {
        $this->get('/')->assertOk()->assertSee('<title>Mi canal</title>', false);
        $this->home()->update(['seo_title' => 'Apuntes de mi canal']);
        $this->get('/')->assertSee('<title>Apuntes de mi canal — Mi canal</title>', false);
    }

    public function test_listing_defaults_match(): void
    {
        $home = $this->home();
        $home->update(['settings' => array_merge($home->settings ?? [], ['catalog_title' => ''])]);
        $this->createPost('Una entrada visible');
        $this->get('/')->assertSee('Últimas entradas.');
        Page::factory()->create(['slug' => 'aviso', 'published' => true, 'settings' => ['intro' => 'Texto de presentación']]);
        $this->get('/aviso')->assertOk()->assertDontSee('page-intro-text">Texto de presentación', false);
        $this->actingAs($this->admin);
        Livewire::test(CreatePage::class)->assertSet('data.settings.show_intro', false);
    }

    public function test_section_toggle_label(): void
    {
        $this->actingAs($this->admin);
        $series = Page::where('kind', 'series')->firstOrFail();
        Livewire::test(EditPage::class, ['record' => $series->id])->assertSee('Mostrar esta sección en el sitio')->assertDontSee('Página publicada');
    }

    public function test_changed_page_slug_redirects(): void
    {
        $this->actingAs($this->admin);
        $page = Page::factory()->create(['title' => 'Contacto', 'slug' => 'contacto', 'published' => true]);
        Livewire::test(EditPage::class, ['record' => $page->id])->fillForm(['slug' => 'escribeme'])->call('save')->assertHasNoFormErrors();
        $this->get('/contacto')->assertRedirect('/escribeme')->assertStatus(301);
        $this->get('/escribeme')->assertOk();
        Livewire::test(CreatePage::class)->fillForm(['title' => 'Otra', 'slug' => 'contacto'])->call('create')->assertHasFormErrors(['slug']);
    }
}
