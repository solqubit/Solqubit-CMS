<?php

namespace Tests\Feature;

use App\Filament\Pages\AccessSettings;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-navigation');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Solqubit', 'timezone' => 'UTC']);
        Setting::write('theme', 'solqubit');
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    /** @return list<string> */
    private function sidebar(User $user): array
    {
        $this->actingAs($user);

        return collect(Filament::getNavigation())
            ->flatMap(fn (NavigationGroup $group) => $group->getItems())
            ->map(fn ($item): string => $item->getLabel())
            ->values()->all();
    }

    public function test_admin_sidebar_has_nine_entries_in_order(): void
    {
        $this->assertSame(['Escritorio', 'Estadísticas', 'Entradas', 'Páginas', 'Biblioteca', 'Clasificación', 'Ajustes', 'Usuarios', 'Actualizaciones'], $this->sidebar($this->admin));
    }

    public function test_editor_sidebar(): void
    {
        $this->assertSame(['Escritorio', 'Entradas', 'Biblioteca', 'Clasificación'], $this->sidebar(User::factory()->create(['role' => 'editor', 'is_active' => true])));
    }

    public function test_settings_subnavigation_groups(): void
    {
        $this->actingAs($this->admin)->get('/admin/ajustes/identidad')->assertOk()
            ->assertSeeInOrder(['Sitio', 'Identidad', 'Colores', 'Portada', 'Menús', 'Publicación', 'Direcciones', 'SEO', 'Monetización', 'Sistema', 'General', 'Correo', 'Seguridad']);
    }

    public function test_settings_entry_redirects_to_identity(): void
    {
        $this->actingAs($this->admin)->get('/admin/ajustes')->assertRedirect('/admin/ajustes/identidad');
    }

    public function test_classification_groups_categories_tags_and_series(): void
    {
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);
        $this->actingAs($editor)->get('/admin/clasificacion/categorias')->assertOk()->assertSee('Etiquetas')->assertSee('Series');
        $this->get('/admin/clasificacion/etiquetas')->assertOk();
        $this->get('/admin/clasificacion/series')->assertOk();
    }

    public function test_old_settings_urls_are_gone(): void
    {
        $this->actingAs($this->admin);
        foreach (['/admin/site-settings', '/admin/system-status', '/admin/general-settings', '/admin/categories'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->get('/admin/actualizaciones')->assertOk();
    }

    public function test_editor_cannot_open_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]))->get('/admin/ajustes/identidad')->assertForbidden();
    }

    public function test_admin_path_change_redirects_into_cluster(): void
    {
        $accessFile = tempnam(sys_get_temp_dir(), 'sq-admin-path');
        config(['solqubit.admin_access_file' => $accessFile]);
        $this->actingAs($this->admin);
        Livewire::test(AccessSettings::class)->set('data.path', 'panel-nuevo')->call('save')
            ->assertRedirect(url('/panel-nuevo/ajustes/seguridad'));
        @unlink($accessFile);
    }
}
