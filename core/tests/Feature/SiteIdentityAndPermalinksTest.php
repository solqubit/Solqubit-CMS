<?php

namespace Tests\Feature;

use App\Filament\Pages\AddressSettings;
use App\Filament\Pages\SiteSettings;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\PostPermalinks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SiteIdentityAndPermalinksTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-identity');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Solqubit', 'description' => 'Apuntes del canal', 'timezone' => 'America/Guayaquil']);
        app(PostPermalinks::class)->configure([['label' => 'Entrada', 'slug' => 'entrada'], ['label' => 'Blog', 'slug' => 'blog'], ['label' => 'Notas', 'slug' => 'notas']], 'entrada');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    private function createPost(array $attributes = []): Post
    {
        return Post::create($attributes + ['user_id' => User::factory()->create()->id, 'title' => 'Guía de Linux', 'slug' => 'linux', 'content' => [], 'status' => 'published']);
    }

    public function test_identity_and_copyright_are_edited_from_the_panel_and_render_safely(): void
    {
        $this->admin();
        $this->travelTo(now()->setDate(2026, 10, 14));
        Livewire::test(SiteSettings::class)->fillForm([
            'name' => 'Mi Laboratorio', 'brand_lowercase' => false,
            'brand_dot' => false, 'brand_icon' => 'none', 'footer_description' => 'Notas de mis videos',
            'footer_copyright' => '© {year} {site} · <b>Mi contenido</b>',
        ])->call('save')->assertHasNoFormErrors();
        $site = Setting::read('site');
        $this->assertSame('Mi Laboratorio', $site['name']);
        $this->assertArrayNotHasKey('brand_text', $site);
        $response = $this->get('/')->assertOk()->assertSee('Mi Laboratorio')->assertSee('Notas de mis videos')
            ->assertSee('© 2026 Mi Laboratorio · &lt;b&gt;Mi contenido&lt;/b&gt;', false)
            ->assertDontSee('<b>Mi contenido</b>', false)->assertDontSee('class="brand-mark"', false)->assertDontSee('class="brand-dot"', false);
        $this->assertStringContainsString('class="footer-brand" href="'.url('/').'">Mi Laboratorio</a>', $response->getContent());
        $this->get('/admin')->assertOk()->assertSee('Mi Laboratorio · Administración');
        $this->get('/feed.xml')->assertOk()->assertSee('Mi Laboratorio');
    }

    public function test_site_name_is_used_for_both_brands_and_copyright_can_be_hidden(): void
    {
        $this->admin();
        Livewire::test(SiteSettings::class)->fillForm(['name' => 'Nuevo Canal', 'footer_copyright' => ''])->call('save')->assertHasNoFormErrors();
        $response = $this->get('/')->assertOk()->assertSee('nuevo canal')->assertSee('class="brand-dot"', false)
            ->assertSee('class="brand-mark"', false)->assertSee('Apuntes del canal')->assertDontSee('©');
        $this->assertSame(2, substr_count($response->getContent(), 'nuevo canal'));
    }

    public function test_author_identity_comes_from_the_user_and_is_visible_without_reassigning_the_post(): void
    {
        $admin = $this->admin();
        $author = User::factory()->create(['name' => 'María López']);
        $post = $this->createPost(['user_id' => $author->id]);
        $this->get($post->url())->assertOk()->assertSee('Por María López')
            ->assertSee('class="author-avatar" aria-hidden="true">ML</span>', false);

        Livewire::test(CreatePost::class)->assertSet('data.author_display_name', $admin->name);
        Livewire::test(EditPost::class, ['record' => $post->id])->assertSee('Autor de la entrada')
            ->assertSet('data.author_display_name', 'María López')
            ->fillForm(['title' => 'Título actualizado', 'author_display_name' => 'Otro nombre'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($author->id, $post->fresh()->user_id);
        $this->assertSame('María López', $author->fresh()->name);

        Livewire::test(EditUser::class, ['record' => $author->id])->fillForm(['name' => 'Solqubit'])->call('save')->assertHasNoFormErrors();
        $this->get($post->url())->assertOk()->assertSee('Por Solqubit')
            ->assertSee('class="author-avatar" aria-hidden="true">S</span>', false);
        Livewire::test(EditPost::class, ['record' => $post->id])->assertSet('data.author_display_name', 'Solqubit');
    }

    public function test_prefix_changes_update_links_and_old_addresses_redirect_to_the_current_url(): void
    {
        $post = $this->createPost();
        $this->admin();
        $this->get('/entrada/linux')->assertOk();
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['url_prefix' => 'blog'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('blog', $post->fresh()->url_prefix);
        $this->get('/blog/linux')->assertOk()->assertSee('rel="canonical" href="'.url('/blog/linux').'"', false);
        $this->get('/entrada/linux?utm_source=youtube')->assertStatus(301)->assertRedirect(url('/blog/linux').'?utm_source=youtube');
        $this->get('/')->assertSee('href="'.url('/blog/linux').'"', false)->assertDontSee('href="'.url('/entrada/linux').'"', false);
        $this->get('/sitemap.xml')->assertSee(url('/blog/linux'))->assertDontSee(url('/entrada/linux'));
        $this->get('/feed.xml')->assertSee(url('/blog/linux'))->assertDontSee(url('/entrada/linux'));
        $this->get('/admin/posts/'.$post->id.'/edit')->assertOk()->assertSee(url('/blog/linux'));
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['url_prefix' => 'notas'])->call('save')->assertHasNoFormErrors();
        $this->get('/entrada/linux')->assertStatus(301)->assertRedirect(url('/notas/linux'));
        $this->get('/blog/linux')->assertStatus(301)->assertRedirect(url('/notas/linux'));
        $this->get('/cualquier-cosa/linux')->assertNotFound();
    }

    public function test_root_urls_work_with_compiled_routes_and_do_not_replace_system_pages(): void
    {
        $post = $this->createPost();
        $this->admin();
        $router = app('router');
        $routes = $router->getRoutes();
        $router->setCompiledRoutes($routes->compile());
        try {
            Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['url_prefix' => '__none'])->call('save')->assertHasNoFormErrors();
            $this->get('/linux')->assertOk()->assertSee('Guía de Linux');
            $this->get('/entrada/linux')->assertStatus(301)->assertRedirect(url('/linux'));
            $this->get('/series')->assertOk();
            $this->get('/admin')->assertOk();
            $this->get('/admin/pages')->assertOk();
            $this->get('/desconocida')->assertNotFound();
            Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['url_prefix' => 'blog'])->call('save')->assertHasNoFormErrors();
            $this->get('/linux')->assertStatus(301)->assertRedirect(url('/blog/linux'));
            $this->get('/blog/linux')->assertOk();
        } finally {
            $router->setRoutes($routes);
        }
    }

    #[TestWith(['admin'])]
    #[TestWith(['media'])]
    #[TestWith(['series'])]
    #[TestWith(['mis/entradas'])]
    #[TestWith(['../blog'])]
    #[TestWith(['https://example.com'])]
    public function test_reserved_or_malformed_prefixes_are_rejected_without_changing_settings(string $prefix): void
    {
        $this->admin();
        Livewire::test(AddressSettings::class)->fillForm(['post_prefixes' => [['label' => 'Inválido', 'slug' => $prefix]], 'default_post_prefix' => '__none', 'name' => 'No guardar'])->call('save')->assertHasFormErrors();
        $this->assertSame('Solqubit', Setting::read('site')['name']);
        $this->assertSame('entrada', app(PostPermalinks::class)->defaultPrefix());
    }

    public function test_reserved_post_slugs_cannot_switch_to_root_but_allow_a_root_default_for_other_entries(): void
    {
        $post = $this->createPost(['slug' => 'series']);
        $this->admin();
        Livewire::test(AddressSettings::class)->fillForm(['default_post_prefix' => '__none'])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['url_prefix' => '__none'])->call('save')->assertHasFormErrors(['slug']);
        $this->get('/entrada/series')->assertOk();
        $this->get('/series')->assertOk();
    }

    public function test_reserved_slugs_cannot_be_added_while_root_addresses_are_in_use(): void
    {
        app(PostPermalinks::class)->configure([], '');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Esta dirección está reservada');
        $this->createPost(['slug' => 'admin']);
    }

    public function test_private_entries_remain_private_at_current_and_old_addresses(): void
    {
        $draft = $this->createPost(['slug' => 'borrador', 'status' => 'draft']);
        $scheduled = $this->createPost(['slug' => 'programada', 'published_at' => now()->addDay()]);
        $archived = $this->createPost(['slug' => 'archivada', 'status' => 'archived']);
        $deleted = $this->createPost(['slug' => 'eliminada']);
        $deleted->delete();
        foreach ([$draft, $scheduled, $archived, $deleted] as $post) {
            $post->update(['url_prefix' => 'blog']);
            $post->update(['url_prefix' => '']);
            foreach (['/entrada/', '/blog/', '/'] as $prefix) {
                $this->get($prefix.$post->slug)->assertNotFound();
            }
        }
    }

    public function test_only_administrators_can_change_site_identity_and_permalink_settings(): void
    {
        $this->get('/admin/ajustes/identidad')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]));
        $this->get('/admin/ajustes/identidad')->assertForbidden();
        $this->assertSame('Solqubit', Setting::read('site')['name']);
        $this->assertSame('entrada', app(PostPermalinks::class)->defaultPrefix());
    }
}
