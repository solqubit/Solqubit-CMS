<?php

namespace Tests\Feature;

use App\Filament\Pages\HomeSettings;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\HomeSections;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HomeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-home-settings-'.bin2hex(random_bytes(8));
        file_put_contents($this->marker, '{}');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Mi blog', 'description' => 'Notas', 'timezone' => 'UTC']);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
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

    public function test_home_settings_loads_converted_sections(): void
    {
        $sections = Livewire::test(HomeSettings::class)->assertOk()->get('data.sections');

        $this->assertSame(['welcome', 'featured', 'latest'], array_column(array_values($sections), 'type'));
    }

    public function test_home_settings_shows_the_live_preview(): void
    {
        $this->get(HomeSettings::getUrl())->assertOk()
            ->assertSee('data-live-preview="home"', false)->assertSee('Añadir sección');
    }

    public function test_home_settings_saves_reordered_sections(): void
    {
        Post::create(['user_id' => $this->admin->id, 'title' => 'Una entrada', 'slug' => 'una-entrada', 'status' => 'published', 'published_at' => now()->subHour(), 'content' => []]);
        $builder = app(HomeSections::class)->toBuilder([
            ['type' => 'latest', 'visible' => true, 'title' => 'Primero el listado', 'per_page' => 12, 'show_search' => false, 'show_filter' => true],
            ['type' => 'welcome', 'visible' => true, 'heading' => 'Hola', 'accent_heading' => '', 'intro' => 'Bienvenida', 'icon' => 'none', 'show_heading' => true, 'show_intro' => true],
        ]);

        Livewire::test(HomeSettings::class)->set('data.sections', $builder)->call('save')->assertHasNoErrors();

        $home = $this->home();
        $this->assertSame(['latest', 'welcome'], array_column($home->settings['sections'], 'type'));
        $this->assertSame('Hola', $home->option('heading'));
        $this->get('/')->assertOk()->assertSeeInOrder(['Primero el listado', 'Una entrada', 'Hola'])->assertDontSee('id="guide-search"', false);
    }

    public function test_home_settings_rejects_invalid_sections(): void
    {
        $builder = app(HomeSections::class)->toBuilder([['type' => 'latest', 'visible' => true, 'per_page' => 12], ['type' => 'latest', 'visible' => true, 'per_page' => 12]]);

        Livewire::test(HomeSettings::class)->set('data.sections', $builder)->call('save')->assertHasErrors('data.sections');
        $this->assertArrayNotHasKey('sections', $this->home()->settings ?? []);
    }

    public function test_text_sections_do_not_share_an_autosave_draft(): void
    {
        $text = ['type' => 'text', 'visible' => true, 'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Uno</p>']]]];
        $home = $this->home();
        $home->settings = array_replace($home->settings ?? [], ['sections' => [$text, $text]]);
        $home->save();

        $html = Livewire::test(HomeSettings::class)->html();

        $this->assertSame(2, substr_count($html, 'data-editor-context='));
        $this->assertStringNotContainsString('data-draft-url', $html);
    }

    public function test_featured_selector_labels_a_chosen_older_post(): void
    {
        $old = Post::create(['user_id' => $this->admin->id, 'title' => 'Entrada muy antigua', 'slug' => 'antigua', 'status' => 'published', 'published_at' => now()->subYears(3), 'content' => []]);

        $this->assertSame('Entrada muy antigua', HomeSettings::postLabel($old->id));
        $this->assertNull(HomeSettings::postLabel(999999));
    }

    public function test_text_section_survives_saving_from_the_panel(): void
    {
        $builder = app(HomeSections::class)->toBuilder([
            ['type' => 'text', 'visible' => true, 'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Texto de la portada</p>']]]],
            ['type' => 'latest', 'visible' => true, 'per_page' => 12],
        ]);

        Livewire::test(HomeSettings::class)->set('data.sections', $builder)->call('save')->assertHasNoErrors();
        Livewire::test(HomeSettings::class)->call('save')->assertHasNoErrors();

        $this->assertSame('<p>Texto de la portada</p>', $this->home()->settings['sections'][0]['content'][0]['data']['html']);
        $this->get('/')->assertOk()->assertSee('Texto de la portada');
    }

    public function test_ad_section_is_offered_only_with_monetization(): void
    {
        $this->assertNotContains('ad', HomeSettings::availableSectionTypes());

        Setting::write('monetization', ['status' => 'active', 'publisher_id' => 'ca-pub-1234567890123456', 'format' => 'manual', 'privacy_ready' => true, 'home_bottom' => '1234567890', 'article_top' => '', 'article_bottom' => '']);

        $this->assertContains('ad', HomeSettings::availableSectionTypes());
    }
}
