<?php

namespace Tests\Feature;

use App\Filament\Pages;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsCleanupTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-settings-cleanup');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Solqubit', 'description' => 'Apuntes', 'timezone' => 'UTC']);
        Setting::write('theme', 'solqubit');
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    public function test_identity_single_save_persists_social_links_and_name(): void
    {
        Livewire::test(Pages\SiteSettings::class)->fillForm([
            'name' => 'Nuevo nombre',
            'social_links' => [['network' => 'youtube', 'url' => 'https://www.youtube.com/@canal']],
        ])->call('save')->assertHasNoFormErrors();
        $site = Setting::read('site');
        $this->assertSame('Nuevo nombre', $site['name']);
        $this->assertSame('https://www.youtube.com/@canal', $site['social_links'][0]['url']);
    }

    public function test_identity_has_no_separate_social_save(): void
    {
        $this->get('/admin/ajustes/identidad')->assertOk()->assertDontSee('Guardar redes sociales');
        $this->assertFalse(method_exists(Pages\SiteSettings::class, 'saveSocialLinks'));
    }

    public function test_settings_pages_warn_about_unsaved_changes(): void
    {
        foreach ([Pages\SiteSettings::class, Pages\HomeSettings::class, Pages\NavigationSettings::class, Pages\AddressSettings::class, Pages\SeoSettings::class, Pages\MonetizationSettings::class, Pages\GeneralSettings::class, Pages\MailSettings::class, Pages\AccessSettings::class] as $page) {
            $this->assertContains(HasUnsavedDataChangesAlert::class, class_uses_recursive($page), $page.' debe avisar de los cambios sin guardar.');
        }
    }

    public function test_help_texts_do_not_cite_menu_paths(): void
    {
        $offending = [];
        foreach ([app_path(), resource_path('views/filament'), resource_path('views/mail')] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                foreach (['Administración →', 'Sitio web →', 'Contenido →', 'Páginas → Inicio'] as $path) {
                    if (str_contains($file->getContents(), $path)) {
                        $offending[] = $file->getRelativePathname().': '.$path;
                    }
                }
            }
        }
        $this->assertSame([], $offending);
    }

    public function test_about_channel_toggle_warns_without_youtube(): void
    {
        $about = Page::where('kind', 'about')->firstOrFail();
        Livewire::test(EditPage::class, ['record' => $about->id])->assertSee('No tienes un perfil de YouTube');
        Setting::write('site', array_replace(Setting::read('site'), ['social_links' => [['network' => 'youtube', 'url' => 'https://www.youtube.com/@canal']]]));
        Livewire::test(EditPage::class, ['record' => $about->id])->assertDontSee('No tienes un perfil de YouTube');
    }

    public function test_social_help_says_where_icons_appear(): void
    {
        $this->get('/admin/ajustes/identidad')->assertSee('el encabezado, el pie de página y el menú del móvil');
    }

    public function test_repeated_guides_are_gone(): void
    {
        $this->get('/admin/ajustes/identidad')->assertDontSee('El nombre y los iconos de tu web, en un solo lugar');
        $this->get('/admin/ajustes/menus')->assertDontSee('Dos ubicaciones, dos menús independientes');
        $this->get('/admin/ajustes/correo')->assertDontSee('El correo que envía tu sitio');
        $this->get('/admin/ajustes/colores')->assertDontSee('Un nuevo acento, la misma comodidad al leer');
    }
}
