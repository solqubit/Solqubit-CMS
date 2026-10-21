<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteSettings;
use App\Models\Media;
use App\Models\Setting;
use App\Models\User;
use App\Services\MediaLibrary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SiteIdentityAssetsTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-identity-assets');
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
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        Setting::write('site', ['name' => 'Mi Blog', 'description' => 'Una descripción', 'timezone' => 'UTC']);

        return $admin;
    }

    public function test_custom_identity_images_are_saved_rendered_and_served_to_visitors(): void
    {
        Storage::fake('media');
        $admin = $this->admin();
        $brand = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('marca.png', 512, 512));
        $favicon = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('icono.png', 512, 512));
        $unused = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('privada.png', 100, 100));
        Livewire::test(SiteSettings::class)->assertSee('Icono de la pestaña (favicon)')->fillForm([
            'brand_icon' => 'custom', 'brand_image_id' => $brand->id, 'favicon_id' => $favicon->id,
            'name' => 'Mi Observatorio', 'description' => 'Noticias de otros mundos.',
        ])->call('save')->assertHasNoFormErrors()->assertNotified('Sitio actualizado');
        $site = Setting::read('site');
        $this->assertEquals($brand->id, $site['brand_image_id']);
        $this->assertEquals($favicon->id, $site['favicon_id']);
        $this->assertSame('Mi Observatorio', $site['name']);
        $this->assertSame('Noticias de otros mundos.', $site['description']);
        $this->get(SiteSettings::getUrl())->assertOk()->assertSee($favicon->url(), false);
        auth()->logout();
        $this->get('/')->assertOk()->assertSee('class="brand-image" src="'.$brand->url().'"', false)
            ->assertSee('<link rel="icon" href="'.$favicon->url().'">', false)->assertSee('Noticias de otros mundos.');
        $this->get('/admin/login')->assertOk()->assertSee('href="'.$favicon->url().'"', false);
        $this->get($brand->url())->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($favicon->url())->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($unused->url())->assertNotFound();

        $this->actingAs($admin);
        Livewire::test(SiteSettings::class)->fillForm(['brand_icon' => 'none', 'brand_image_id' => null, 'favicon_id' => null])
            ->call('save')->assertHasNoFormErrors();
        auth()->logout();
        $this->get('/')->assertOk()->assertDontSee('class="brand-image"', false)
            ->assertSee('href="'.asset('themes/solqubit/assets/favicon.svg').'"', false);
        $this->get($brand->url())->assertNotFound();
        $this->get($favicon->url())->assertNotFound();
    }

    #[TestWith(['brand_image_id'])]
    #[TestWith(['favicon_id'])]
    public function test_identity_rejects_non_images_and_missing_files(string $field): void
    {
        $admin = $this->admin();
        $document = Media::create(['uuid' => 'document-identity', 'path' => 'manual.pdf', 'original_name' => 'manual.pdf',
            'mime_type' => 'application/pdf', 'size' => 10, 'user_id' => $admin->id]);
        $before = Setting::read('site');
        foreach ([$document->id, 999999] as $id) {
            $form = [$field => $id];
            if ($field === 'brand_image_id') {
                $form['brand_icon'] = 'custom';
            }
            Livewire::test(SiteSettings::class)->fillForm($form)->call('save')->assertHasFormErrors([$field]);
            $this->assertSame($before, Setting::read('site'));
        }
    }

    public function test_custom_brand_requires_an_image(): void
    {
        $this->admin();
        Livewire::test(SiteSettings::class)->fillForm(['brand_icon' => 'custom', 'brand_image_id' => null])
            ->call('save')->assertHasFormErrors(['brand_image_id' => 'required']);
    }

    #[TestWith(['brand_image_id'])]
    #[TestWith(['favicon_id'])]
    public function test_identity_images_cannot_be_deleted_until_removed_from_settings(string $field): void
    {
        Storage::fake('media');
        $this->admin();
        $image = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('identidad.png'));
        Setting::write('site', Setting::read('site') + [$field => $image->id, 'brand_icon' => 'custom']);
        try {
            $image->delete();
            $this->fail('Se eliminó una imagen que todavía usa la identidad del sitio.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('media', $error->errors());
        }
        Storage::disk('media')->assertExists($image->path);
        $this->assertModelExists($image);
        Setting::write('site', ['name' => 'Mi Blog']);
        $image->delete();
        Storage::disk('media')->assertMissing($image->path);
    }

    public function test_a_retained_brand_image_is_not_public_when_a_bundled_icon_is_selected(): void
    {
        Storage::fake('media');
        $this->admin();
        $image = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('oculta.png'));
        Setting::write('site', Setting::read('site') + ['brand_image_id' => $image->id, 'brand_icon' => 'cube']);
        auth()->logout();
        $this->get($image->url())->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('class="brand-image"', false);
        $this->assertTrue($image->isReferenced());
    }

    public function test_invalid_stored_image_ids_fall_back_without_exposing_other_media(): void
    {
        Setting::write('site', ['name' => 'Mi Blog', 'brand_icon' => 'custom', 'brand_image_id' => ['bad'], 'favicon_id' => 999999]);
        $this->get('/')->assertOk()->assertDontSee('class="brand-image"', false)
            ->assertSee('href="'.asset('themes/solqubit/assets/favicon.svg').'"', false);
    }

    #[TestWith(['editor', true])]
    #[TestWith(['admin', false])]
    public function test_unprivileged_accounts_cannot_mount_or_save_identity_settings(string $role, bool $active): void
    {
        $this->admin();
        $component = Livewire::test(SiteSettings::class);
        $before = Setting::read('site');
        $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $active]));
        $component->call('save')->assertForbidden();
        Livewire::test(SiteSettings::class)->assertForbidden();
        $this->assertSame($before, Setting::read('site'));
    }
}
