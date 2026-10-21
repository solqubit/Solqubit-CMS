<?php

namespace Tests\Feature;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PageEditorLayoutTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-page-editor-'.bin2hex(random_bytes(8));
        file_put_contents($this->marker, '{}');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Mi blog', 'description' => 'Notas', 'timezone' => 'UTC']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    public function test_normalize_titles_fills_the_name_and_drops_a_repeated_heading(): void
    {
        $this->assertSame(['title' => 'Hablemos', 'settings' => ['heading' => '', 'show_heading' => true]],
            PageResource::normalizeTitles(['title' => '', 'settings' => ['heading' => 'Hablemos', 'hide_heading' => false]]));
        $this->assertSame(['title' => 'Contacto', 'settings' => ['heading' => 'Hablemos.', 'show_heading' => false]],
            PageResource::normalizeTitles(['title' => 'Contacto', 'settings' => ['heading' => 'Hablemos.', 'hide_heading' => true]]));
    }

    public function test_saving_with_empty_name_uses_visible_title(): void
    {
        Livewire::test(CreatePage::class)->fillForm(['settings.heading' => 'Preguntas frecuentes', 'title' => '', 'slug' => 'preguntas', 'published' => true])
            ->call('create')->assertHasNoFormErrors();

        $page = Page::where('slug', 'preguntas')->firstOrFail();
        $this->assertSame('Preguntas frecuentes', $page->title);
        $this->assertSame('', $page->option('heading'));
        $this->get('/preguntas')->assertOk()->assertSee('<h1', false)->assertSee('Preguntas frecuentes');
    }

    public function test_existing_page_keeps_its_name_and_heading(): void
    {
        $page = Page::factory()->create(['title' => 'Contacto', 'slug' => 'contacto-prueba', 'published' => true, 'settings' => ['heading' => 'Hablemos.', 'show_heading' => true]]);

        Livewire::test(EditPage::class, ['record' => $page->id])
            ->assertSet('data.settings.heading', 'Hablemos.')->assertSet('data.title', 'Contacto')->assertSet('data.settings.hide_heading', false)
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('Contacto', $page->fresh()->title);
        $this->assertSame('Hablemos.', $page->fresh()->option('heading'));
        $this->get('/contacto-prueba')->assertOk()->assertSee('Hablemos.');
    }

    public function test_visible_title_equal_to_name_is_stored_empty(): void
    {
        $page = Page::factory()->create(['title' => 'Aviso legal', 'slug' => 'aviso-legal', 'published' => true, 'settings' => []]);

        Livewire::test(EditPage::class, ['record' => $page->id])->assertSet('data.settings.heading', 'Aviso legal')->call('save')->assertHasNoFormErrors();

        $this->assertSame('', $page->fresh()->option('heading'));
    }

    public function test_a_long_page_name_is_not_copied_into_the_title(): void
    {
        $name = str_repeat('Nombre largo ', 16);
        $page = Page::factory()->create(['title' => trim($name), 'slug' => 'nombre-largo', 'published' => true, 'settings' => []]);

        Livewire::test(EditPage::class, ['record' => $page->id])->assertSet('data.settings.heading', '')->call('save')->assertHasNoFormErrors();

        $this->assertSame(trim($name), $page->fresh()->title);
    }

    public function test_new_page_slug_is_suggested_from_visible_title(): void
    {
        Livewire::test(CreatePage::class)->fillForm(['settings.heading' => 'Mi primera página'])->assertSet('data.slug', 'mi-primera-pagina');
    }

    public function test_page_settings_are_in_order(): void
    {
        $page = Page::factory()->create(['title' => 'Otra', 'slug' => 'otra', 'published' => true]);
        $html = Livewire::test(EditPage::class, ['record' => $page->id])->html();
        $settings = substr($html, strpos($html, 'sq-writing-settings'));

        $this->assertMatchesRegularExpression('/Visibilidad y menú.*Encabezado.*Nombre de la página.*Ocultar el título en la página.*Dirección de la página.*Buscadores/s', $settings);
    }
}
