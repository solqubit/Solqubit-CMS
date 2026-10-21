<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteSettings;
use App\Models\Media;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ThemeDesignTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-theme-'.bin2hex(random_bytes(8));
        file_put_contents($this->marker, '{}');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Mi blog', 'description' => 'Notas', 'timezone' => 'UTC']);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function createPost(string $title, array $attributes = []): Post
    {
        return Post::create($attributes + ['user_id' => $this->admin->id, 'title' => $title, 'slug' => str($title)->slug()->toString(), 'status' => 'published', 'published_at' => now()->subHour(), 'content' => []]);
    }

    public function test_notice_uses_the_icon_of_its_tone(): void
    {
        $post = $this->createPost('Avisos', ['content' => [
            ['type' => 'notice', 'data' => ['title' => 'Info', 'text' => 'Uno', 'tone' => 'info']],
            ['type' => 'notice', 'data' => ['title' => 'Nota', 'text' => 'Dos', 'tone' => 'note']],
            ['type' => 'notice', 'data' => ['title' => 'Ojo', 'text' => 'Tres', 'tone' => 'warning']],
        ]]);

        $html = $this->get($post->url())->assertOk()->getContent();

        preg_match_all('/<aside class="article-notice[^"]*">\s*<svg[^>]*data-icon="([a-z]+)"/', $html, $icons);
        $this->assertSame(['info', 'bulb', 'warning'], $icons[1]);
    }

    public function test_cards_use_neutral_wording(): void
    {
        $this->createPost('Primera', ['featured' => true]);
        $this->createPost('Segunda');

        $this->get('/')->assertOk()
            ->assertSee('Leer la entrada')->assertSee('id="entradas"', false)
            ->assertDontSee('Consultar entrada')->assertDontSee('herramienta o comando')->assertDontSee('un comando')->assertDontSee('id="tutoriales"', false);
    }

    public function test_theme_switch_names_the_current_mode(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<span data-label-light>Modo claro<span class="sr-only">', false)
            ->assertSee('<span data-label-dark>Modo oscuro<span class="sr-only">', false)
            ->assertDontSee('role="switch"', false);
    }

    public function test_footer_brand_text_and_socials_can_be_hidden(): void
    {
        $this->actingAs($this->admin);
        $youtube = [['network' => 'youtube', 'url' => 'https://www.youtube.com/@mi-canal']];
        Livewire::test(SiteSettings::class)->fillForm(['social_links' => $youtube])->call('save')->assertHasNoFormErrors();

        $this->get('/')->assertOk()->assertSee('class="footer-brand"', false)->assertSee('footer-socials', false)->assertSee('Notas</p>', false);

        Livewire::test(SiteSettings::class)->fillForm(['footer_show_brand' => false, 'footer_show_description' => false, 'footer_show_socials' => false])
            ->call('save')->assertHasNoFormErrors();

        $html = $this->get('/')->assertOk()->assertDontSee('class="footer-brand"', false)->assertDontSee('footer-socials', false)->assertDontSee('Notas</p>', false)->getContent();
        $this->assertStringContainsString('https://www.youtube.com/@mi-canal', $html, 'Las redes siguen en la cabecera.');
    }

    public function test_media_video_without_caption_has_no_empty_caption(): void
    {
        $video = Media::create(['uuid' => (string) str()->uuid(), 'disk' => 'media', 'path' => 'clip.mp4', 'original_name' => 'clip.mp4', 'mime_type' => 'video/mp4', 'size' => 2048, 'user_id' => $this->admin->id]);
        $post = $this->createPost('Video', ['content' => [['type' => 'media_video', 'data' => ['media_id' => $video->id]]]]);

        $html = $this->get($post->url())->assertOk()->getContent();

        $this->assertStringContainsString('<video', $html);
        $this->assertDoesNotMatchRegularExpression('/<figcaption>\s*<\/figcaption>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<video[^>]*style=/', $html);
    }
}
