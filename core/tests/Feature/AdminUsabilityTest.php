<?php

namespace Tests\Feature;

use App\Filament\Pages\AccessSettings;
use App\Filament\Pages\AddressSettings;
use App\Filament\Pages\GeneralSettings;
use App\Filament\Pages\MailSettings;
use App\Filament\Pages\SiteSettings;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Series\Pages\CreateSeries;
use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\PostPermalinks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminUsabilityTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-admin-review');
        config(['solqubit.installed_file' => $this->marker]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        return $user;
    }

    public function test_identity_and_general_settings_save_independently_even_from_an_older_open_form(): void
    {
        $this->admin();
        Setting::write('site', ['name' => 'Mi blog', 'description' => 'Ciencia', 'timezone' => 'UTC', 'footer_description' => 'Mi pie']);
        $identity = Livewire::test(SiteSettings::class);
        Livewire::test(GeneralSettings::class)->fillForm(['timezone' => 'America/Guayaquil'])->call('save')->assertHasNoFormErrors();
        Livewire::test(AddressSettings::class)->fillForm([
            'post_prefixes' => [['label' => 'Artículos', 'slug' => 'articulos']],
            'default_post_prefix' => 'articulos',
        ])->call('save')->assertHasNoFormErrors();
        $identity->fillForm(['name' => 'Otro nombre'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Otro nombre', Setting::read('site')['name']);
        $this->assertSame('America/Guayaquil', Setting::read('site')['timezone']);
        $this->assertSame('Mi pie', Setting::read('site')['footer_description']);
        $this->assertSame('articulos', app(PostPermalinks::class)->defaultPrefix());
        Livewire::test(GeneralSettings::class)->fillForm(['timezone' => 'Not/AZone'])
            ->call('save')->assertHasFormErrors(['timezone']);
        $this->assertSame('America/Guayaquil', Setting::read('site')['timezone']);
    }

    public function test_panel_uses_the_blog_fonts(): void
    {
        $this->admin();

        $this->get('/admin')->assertOk()
            ->assertSee('themes/solqubit/fonts/panel.css', false)
            ->assertSee("--font-family: 'DM Sans'", false);
    }

    public function test_panel_dates_use_site_timezone(): void
    {
        Setting::write('site', ['name' => 'Mi blog', 'timezone' => 'America/Guayaquil']);
        $admin = $this->admin();
        $post = Post::create(['user_id' => $admin->id, 'title' => 'Hora local', 'slug' => 'hora-local', 'content' => [], 'status' => 'draft']);
        Post::whereKey($post->id)->update(['updated_at' => '2026-10-20 15:00:00']);
        $this->get('/admin/posts')->assertOk()->assertSee('20/10/2026 10:00')->assertDontSee('20/10/2026 15:00');
    }

    public function test_updates_page_order(): void
    {
        $this->admin();
        $this->get('/admin/actualizaciones')->assertOk()
            ->assertSeeInOrder(['Buscar actualizaciones', 'Cómo actualizar', 'Actualizar desde GitHub', 'Subir el ZIP de actualización', 'Se conserva', 'Requisitos del servidor', 'Comprobar los archivos', 'Ubicación de los archivos']);
    }

    public function test_updates_page_mentions_update_zip_name(): void
    {
        $this->admin();
        $this->get('/admin/actualizaciones')->assertOk()
            ->assertSee('solqubit-X.Y.Z-actualizacion.zip')->assertDontSee('El asistente se bloquea')->assertDontSee('Un mismo ZIP sirve para instalar');
        $this->assertStringContainsString('/admin/actualizaciones/', route('update.upload'));
    }

    public function test_only_administrators_can_view_the_installation_paths(): void
    {
        $this->get('/admin/actualizaciones')->assertRedirect('/admin/login');
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get('/admin/actualizaciones')->assertForbidden();

        $this->admin();
        $this->get('/admin/actualizaciones')->assertOk()->assertSee('Ubicación de los archivos')
            ->assertSee(base_path())->assertSee(public_path());
        $this->get('/')->assertOk()->assertDontSee(base_path());
    }

    public function test_security_changes_do_not_validate_or_overwrite_mail_configuration(): void
    {
        $this->admin();
        $mail = ['enabled' => true, 'host' => '', 'password' => 'existing-encrypted-value'];
        Setting::write('mail', $mail);
        Livewire::test(AccessSettings::class)->fillForm(['blocked_ips' => '203.0.113.9'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($mail, Setting::read('mail'));
        $this->assertSame(['203.0.113.9'], Setting::read('access')['blocked_ips']);
    }

    public function test_mail_changes_preserve_security_settings(): void
    {
        $this->admin();
        Setting::write('access', ['blocked_ips' => ['203.0.113.9']]);
        Livewire::test(MailSettings::class)->fillForm(['mail' => [
            'enabled' => false, 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls',
            'from_address' => 'blog@example.test', 'from_name' => 'Mi blog',
        ]])->call('save')->assertHasNoFormErrors();
        $this->assertSame('smtp.example.test', Setting::read('mail')['host']);
        $this->assertFalse(Setting::read('mail')['enabled']);
        $this->assertSame(['203.0.113.9'], Setting::read('access')['blocked_ips']);
    }

    public function test_smtp_server_url_is_rejected_when_saving_not_only_when_testing(): void
    {
        $this->admin();
        Setting::write('mail', ['enabled' => false]);
        Livewire::test(MailSettings::class)->fillForm(['mail' => [
            'enabled' => true, 'host' => 'https://smtp.example.test', 'port' => 587, 'encryption' => 'tls',
            'from_address' => 'blog@example.test', 'from_name' => 'Mi blog',
        ]])->call('save')->assertHasFormErrors(['mail.host'])
            ->assertSee('Escribe el nombre del servidor SMTP sin https:// ni espacios.');
        $this->assertSame(['enabled' => false], Setting::read('mail'));
    }

    #[TestWith([GeneralSettings::class, 'editor', true])]
    #[TestWith([GeneralSettings::class, 'admin', false])]
    #[TestWith([AddressSettings::class, 'editor', true])]
    #[TestWith([AddressSettings::class, 'admin', false])]
    #[TestWith([MailSettings::class, 'editor', true])]
    #[TestWith([MailSettings::class, 'admin', false])]
    public function test_settings_refuse_access_and_save_after_permissions_are_revoked(string $page, string $role, bool $active): void
    {
        $this->admin();
        $component = Livewire::test($page);
        $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $active]));
        $component->call('save')->assertForbidden();
        Livewire::test($page)->assertForbidden();
        $this->get($page::getUrl())->assertForbidden();
    }

    #[TestWith([CreateCategory::class, 'categories'])]
    #[TestWith([CreateSeries::class, 'series'])]
    public function test_renaming_new_organization_items_preserves_a_manually_chosen_identifier(string $page, string $table): void
    {
        $this->admin();
        Livewire::test($page)->set('data.name', 'Primer nombre')->assertSet('data.slug', 'primer-nombre')
            ->set('data.name', 'Segundo nombre')->assertSet('data.slug', 'segundo-nombre')
            ->set('data.slug', 'mi-identificador')->set('data.name', 'Nombre definitivo')
            ->assertSet('data.slug', 'mi-identificador')->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas($table, ['name' => 'Nombre definitivo', 'slug' => 'mi-identificador']);
    }

    public function test_admin_category_filter_includes_secondary_categories(): void
    {
        $admin = $this->admin();
        $main = Category::create(['name' => 'Ciencia', 'slug' => 'ciencia']);
        $secondary = Category::create(['name' => 'Astronomía', 'slug' => 'astronomia']);
        $post = Post::create(['title' => 'Universo', 'slug' => 'universo', 'content' => [], 'category_id' => $main->id, 'user_id' => $admin->id]);
        $post->categories()->sync([$main->id, $secondary->id]);
        $other = Post::create(['title' => 'Otras ciencias', 'slug' => 'otras', 'content' => [], 'category_id' => $main->id, 'user_id' => $admin->id]);
        Livewire::test(ListPosts::class)->filterTable('category', $secondary->id)
            ->assertCanSeeTableRecords([$post])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_media_filters_distinguish_images_videos_and_documents(): void
    {
        $admin = $this->admin();
        $images = [];
        foreach (['image/png' => 'cielo.png', 'video/mp4' => 'orbita.mp4', 'application/pdf' => 'lectura.pdf'] as $mime => $name) {
            $images[] = Media::create(['uuid' => $name, 'path' => $name, 'original_name' => $name, 'mime_type' => $mime, 'size' => 100, 'user_id' => $admin->id]);
        }
        [$image, $video, $document] = $images;
        Livewire::test(ListMedia::class)->filterTable('kind', 'image')->assertCanSeeTableRecords([$image])->assertCanNotSeeTableRecords([$video, $document])
            ->filterTable('kind', 'video')->assertCanSeeTableRecords([$video])->assertCanNotSeeTableRecords([$image, $document])
            ->filterTable('kind', 'document')->assertCanSeeTableRecords([$document])->assertCanNotSeeTableRecords([$image, $video]);
    }
}
