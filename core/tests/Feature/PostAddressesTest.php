<?php

namespace Tests\Feature;

use App\Filament\Pages\AddressSettings;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\PostPermalinks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PostAddressesTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-addresses');
        config(['solqubit.installed_file' => $this->marker]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function prefixes(string $default = ''): void
    {
        app(PostPermalinks::class)->configure([
            ['label' => 'Tutoriales', 'slug' => 'tutoriales'], ['label' => 'Linux', 'slug' => 'linux'],
        ], $default);
    }

    private function createEntry(array $attributes = []): Post
    {
        return Post::create($attributes + ['user_id' => User::factory()->create()->id, 'title' => 'Instalar Nagios', 'slug' => 'instalar-nagios', 'content' => [], 'status' => 'published']);
    }

    public function test_admin_creates_prefixes_and_changes_only_the_default_for_new_entries(): void
    {
        Setting::write('site', ['name' => 'Mi blog', 'timezone' => 'UTC']);
        $existing = $this->createEntry();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(AddressSettings::class)->fillForm([
            'timezone' => 'UTC', 'post_prefixes' => [['label' => 'Tutoriales', 'slug' => 'tutoriales']], 'default_post_prefix' => 'tutoriales',
        ])->call('save')->assertHasNoFormErrors();
        $this->assertSame('tutoriales', app(PostPermalinks::class)->defaultPrefix());
        $this->assertSame('', $existing->fresh()->url_prefix);
        Livewire::test(CreatePost::class)->assertSet('data.url_prefix', 'tutoriales')->fillForm([
            'title' => 'Otra entrada', 'slug' => 'otra-entrada',
        ])->call('create')->assertHasNoFormErrors();
        $created = Post::where('slug', 'otra-entrada')->firstOrFail();
        $this->assertSame('tutoriales', $created->url_prefix);
        $this->assertSame(url('/tutoriales/otra-entrada'), $created->url());
        $this->get('/instalar-nagios')->assertOk();
    }

    public function test_multicategory_entry_uses_one_chosen_prefix_and_filters_in_each_category(): void
    {
        $this->prefixes('tutoriales');
        $linux = Category::create(['name' => 'Linux', 'slug' => 'linux']);
        $infra = Category::create(['name' => 'Infraestructura', 'slug' => 'infraestructura']);
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        Livewire::test(CreatePost::class)->fillForm([
            'title' => 'Instalar Nagios', 'slug' => 'instalar-nagios', 'url_prefix' => 'linux', 'category_ids' => [$linux->id, $infra->id], 'status' => 'published',
        ])->call('create')->assertHasNoFormErrors();
        $post = Post::where('slug', 'instalar-nagios')->firstOrFail();
        $this->assertEqualsCanonicalizing([$linux->id, $infra->id], $post->category_ids);
        $this->get('/linux/instalar-nagios')->assertOk()->assertSee('Linux')->assertSee('Infraestructura');
        foreach (['linux', 'infraestructura'] as $category) {
            $response = $this->get('/?category='.$category)->assertOk()->assertSee('Instalar Nagios');
            $this->assertSame(1, $response->viewData('posts')->total());
        }
        $this->get('/tutoriales/instalar-nagios')->assertNotFound();
        $this->get('/infraestructura/instalar-nagios')->assertNotFound();

        Livewire::test(EditPost::class, ['record' => $post->id])->assertSet('data.url_prefix', 'linux')->fillForm(['category_ids' => [$infra->id]])->call('save')->assertHasNoFormErrors();
        $this->assertSame('linux', $post->fresh()->url_prefix);
        $this->assertSame(0, $this->get('/?category=linux')->viewData('posts')->total());
        $linux->update(['slug' => 'gnu-linux']);
        $linux->delete();
        $this->get('/linux/instalar-nagios')->assertOk();
    }

    public function test_root_selection_overrides_default_and_updates_the_preview_without_publishing(): void
    {
        $this->prefixes('tutoriales');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(CreatePost::class)->fillForm(['title' => 'Mi nota', 'slug' => 'mi-nota', 'url_prefix' => '__none'])
            ->assertSee(url('/mi-nota'))->call('create')->assertHasNoFormErrors();
        $post = Post::where('slug', 'mi-nota')->firstOrFail();
        $this->assertSame('', $post->url_prefix);
        $this->assertSame('draft', $post->status);
        $this->get('/mi-nota')->assertNotFound();
    }

    public function test_slug_and_prefix_changes_redirect_directly_to_current_url_and_update_all_public_links(): void
    {
        $this->prefixes();
        $post = $this->createEntry();
        $post->update(['url_prefix' => 'tutoriales']);
        $post->update(['slug' => 'nagios-core', 'url_prefix' => 'linux']);
        foreach (['/instalar-nagios', '/tutoriales/instalar-nagios'] as $old) {
            $this->get($old.'?utm_source=youtube')->assertStatus(301)->assertRedirect(url('/linux/nagios-core').'?utm_source=youtube');
        }
        $this->get('/linux/nagios-core')->assertOk()->assertSee('rel="canonical" href="'.url('/linux/nagios-core').'"', false);
        foreach (['/', '/feed.xml', '/sitemap.xml'] as $page) {
            $this->get($page)->assertOk()->assertSee(url('/linux/nagios-core'))->assertDontSee(url('/tutoriales/instalar-nagios'));
        }
        $post->update(['slug' => 'instalar-nagios', 'url_prefix' => '']);
        $this->get('/instalar-nagios')->assertOk();
        $this->get('/linux/nagios-core')->assertStatus(301)->assertRedirect(url('/instalar-nagios'));
    }

    public function test_old_addresses_cannot_be_reused_by_a_different_entry(): void
    {
        $post = $this->createEntry();
        $post->update(['slug' => 'nuevo-nombre']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(CreatePost::class)->fillForm(['title' => 'Otra', 'slug' => 'instalar-nagios'])->call('create')->assertHasFormErrors(['slug']);
        $this->assertSame(1, Post::count());
        $this->get('/instalar-nagios')->assertRedirect(url('/nuevo-nombre'));
    }

    public function test_removing_a_prefix_keeps_existing_urls_and_allows_editing_their_content(): void
    {
        $this->prefixes('tutoriales');
        $post = $this->createEntry();
        app(PostPermalinks::class)->configure([], '');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(EditPost::class, ['record' => $post->id])->assertSee('Actual, retirado de la lista')
            ->fillForm(['title' => 'Contenido actualizado'])->call('save')->assertHasNoFormErrors();
        $this->get('/tutoriales/instalar-nagios')->assertOk()->assertSee('Contenido actualizado');
        Livewire::test(CreatePost::class)->fillForm(['title' => 'Otra', 'slug' => 'otra', 'url_prefix' => 'tutoriales'])->call('create')->assertHasFormErrors(['url_prefix']);
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['url_prefix' => '__none'])->call('save')->assertHasNoFormErrors();
        $this->get('/tutoriales/instalar-nagios')->assertStatus(301)->assertRedirect(url('/instalar-nagios'));
    }

    public function test_invalid_or_duplicate_prefixes_and_missing_defaults_are_not_saved(): void
    {
        $this->prefixes();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(AddressSettings::class)->fillForm([
            'name' => 'No guardar', 'timezone' => 'UTC', 'post_prefixes' => [['label' => 'Uno', 'slug' => 'linux'], ['label' => 'Dos', 'slug' => 'linux']],
        ])->call('save')->assertHasFormErrors();
        $this->assertNotSame('No guardar', Setting::read('site', [])['name'] ?? '');
        Livewire::test(AddressSettings::class)->fillForm(['default_post_prefix' => 'no-existe', 'name' => 'No guardar', 'timezone' => 'UTC'])->call('save')->assertHasFormErrors(['default_post_prefix']);
        $this->assertSame('', app(PostPermalinks::class)->defaultPrefix());
    }

    public function test_unconfigured_prefixes_and_nonexistent_categories_are_rejected_before_persistence(): void
    {
        $post = $this->createEntry();
        foreach ([['url_prefix' => 'inventado'], ['category_ids' => [999999]]] as $changes) {
            try {
                $post->fresh()->update($changes);
                $this->fail('La entrada no debe aceptar valores no configurados.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        $this->assertSame('', $post->fresh()->url_prefix);
        $this->assertSame([], $post->fresh()->category_ids);
    }

    public function test_restoring_content_restores_categories_without_reverting_its_address(): void
    {
        $this->prefixes();
        $linux = Category::create(['name' => 'Linux', 'slug' => 'linux']);
        $infra = Category::create(['name' => 'Infraestructura', 'slug' => 'infra']);
        $post = $this->createEntry(['category_ids' => [$linux->id, $infra->id]]);
        $revision = $post->revisions()->firstOrFail();
        $post->update(['category_ids' => [], 'slug' => 'nagios-actualizado', 'url_prefix' => 'tutoriales']);
        $restored = $post->fresh()->revisionFormData($revision)['data'];
        $this->assertEqualsCanonicalizing([$linux->id, $infra->id], $restored['category_ids']);
        $this->assertArrayNotHasKey('slug', $restored);
        $this->assertArrayNotHasKey('url_prefix', $restored);
        $this->assertArrayNotHasKey('status', $restored);
        $this->assertSame(url('/tutoriales/nagios-actualizado'), $post->fresh()->url());
        $this->get('/instalar-nagios')->assertRedirect(url('/tutoriales/nagios-actualizado'));
    }

    public function test_upgrading_preserves_existing_links_aliases_and_categories(): void
    {
        $migration = require database_path('migrations/2026_10_14_235427_add_post_urls_and_multiple_categories.php');
        $migration->down();
        Setting::write('permalinks', ['prefix' => 'notas', 'previous_prefixes' => ['entrada', '']]);
        $category = Category::create(['name' => 'Linux', 'slug' => 'linux']);
        $id = DB::table('posts')->insertGetId(['title' => 'Entrada existente', 'slug' => 'linux', 'user_id' => User::factory()->create()->id, 'category_id' => $category->id, 'content' => '[]', 'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $migration->up();
        $post = Post::findOrFail($id);
        $this->assertSame('notas', $post->url_prefix);
        $this->assertSame([$category->id], $post->category_ids);
        $this->get('/notas/linux')->assertOk();
        $this->get('/entrada/linux')->assertStatus(301)->assertRedirect(url('/notas/linux'));
        $this->get('/linux')->assertStatus(301)->assertRedirect(url('/notas/linux'));
        $this->assertSame('', app(PostPermalinks::class)->defaultPrefix());
        $this->assertSame([['label' => 'Notas', 'slug' => 'notas']], app(PostPermalinks::class)->configured());
    }

    public function test_numeric_prefix_zero_is_preserved_in_the_form_and_address(): void
    {
        app(PostPermalinks::class)->configure([['label' => 'Cero', 'slug' => '0']], '0');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(CreatePost::class)->assertSet('data.url_prefix', '0')->fillForm(['title' => 'Nota', 'slug' => 'nota'])->call('create')->assertHasNoFormErrors();
        $this->assertSame('0', Post::where('slug', 'nota')->firstOrFail()->url_prefix);
    }
}
