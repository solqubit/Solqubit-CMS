<?php

namespace Tests\Feature;

use App\Filament\Pages\AppearanceSettings;
use App\Filament\Pages\NavigationSettings;
use App\Filament\Pages\SiteSettings;
use App\Filament\Resources\Series\Pages\CreateSeries;
use App\Filament\Resources\Series\Pages\EditSeries;
use App\Models\Page;
use App\Models\Post;
use App\Models\Series;
use App\Models\Setting;
use App\Models\User;
use App\Services\ColorPalettes;
use App\Services\SocialLinks;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EditorialSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-editorial');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Mi Blog', 'description' => 'Mis publicaciones', 'timezone' => 'America/Guayaquil']);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function createPost(string $title, array $extra = []): Post
    {
        return Post::create($extra + ['title' => $title, 'slug' => Str::slug($title), 'user_id' => $this->admin->id, 'status' => 'published', 'content' => []]);
    }

    public function test_a_series_can_be_created_with_ordered_posts_and_reordered_without_deleting_posts(): void
    {
        $first = $this->createPost('Primer paso');
        $second = $this->createPost('Segundo paso');
        $draft = $this->createPost('Borrador privado', ['status' => 'draft']);
        Livewire::test(CreateSeries::class)->fillForm(['name' => 'Mi serie', 'slug' => 'mi-serie', 'tone' => 'lilac', 'entries' => [['post_id' => $second->id], ['post_id' => $first->id], ['post_id' => $draft->id]]])
            ->call('create')->assertHasNoFormErrors();
        $series = Series::where('slug', 'mi-serie')->firstOrFail();
        $this->assertSame([$second->id, $first->id, $draft->id], $series->posts->modelKeys());
        $this->get('/series')->assertOk()->assertSeeInOrder(['Segundo paso', 'Primer paso'])->assertDontSee('Borrador privado');
        Livewire::test(EditSeries::class, ['record' => $series->id])->fillForm(['entries' => [['post_id' => $first->id], ['post_id' => $second->id]]])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$first->id, $second->id], $series->fresh()->posts->modelKeys());
        $this->assertNull($draft->fresh()->series_id);
        $this->assertSame('draft', $draft->fresh()->status);
        Livewire::test(EditSeries::class, ['record' => $series->id])->fillForm(['entries' => []])->call('save')->assertHasNoFormErrors();
        $this->assertSame(0, $series->posts()->count());
        $this->assertSame(3, Post::count());
    }

    public function test_series_reject_duplicate_posts_and_do_not_take_posts_from_another_series(): void
    {
        $other = Series::create(['name' => 'Otra', 'slug' => 'otra']);
        $post = $this->createPost('Ya asignada', ['series_id' => $other->id]);
        Livewire::test(CreateSeries::class)->fillForm(['name' => 'No crear', 'slug' => 'no-crear', 'entries' => [['post_id' => $post->id]]])->call('create')->assertHasFormErrors();
        $this->assertDatabaseMissing('series', ['slug' => 'no-crear']);
        $this->assertSame($other->id, (int) $post->fresh()->series_id);
        $free = $this->createPost('Libre');
        Livewire::test(CreateSeries::class)->fillForm(['name' => 'Duplicada', 'slug' => 'duplicada', 'entries' => [['post_id' => $free->id], ['post_id' => $free->id]]])->call('create')->assertHasFormErrors();
        $this->assertDatabaseMissing('series', ['slug' => 'duplicada']);
    }

    public function test_adding_a_menu_link_keeps_existing_links_collapsed_and_opens_only_the_new_item(): void
    {
        $component = Livewire::test(NavigationSettings::class)->fillForm(['items' => [
            ['label' => 'Primero', 'type' => 'url', 'url' => 'https://example.com/uno', 'enabled' => true],
            ['label' => 'Segundo', 'type' => 'url', 'url' => 'https://example.com/dos', 'enabled' => true],
        ], 'footer_items' => []])->callAction(TestAction::make('add')->schemaComponent('items'))->assertHasNoFormErrors();
        $this->assertCount(3, $component->get('data.items'));
        $html = str_replace('\u0022', '"', html_entity_decode($component->html(), ENT_QUOTES));
        $this->assertSame(2, substr_count($html, '"isCollapsed":true'));
        $this->assertSame(1, substr_count($html, '"isCollapsed":false'));
    }

    public function test_social_profiles_render_in_the_header_of_home_pages_and_posts_without_a_main_video(): void
    {
        $links = [['network' => 'youtube', 'url' => 'https://www.youtube.com/@solqubit'], ['network' => 'github', 'url' => 'https://github.com/solqubit'], ['network' => 'instagram', 'url' => 'https://instagram.com/solqubit']];
        Livewire::test(SiteSettings::class)->fillForm(['social_links' => $links])->call('save')->assertHasNoFormErrors();
        $page = Page::factory()->create(['published' => true]);
        foreach (['/', '/series', '/acerca', $page->url()] as $url) {
            $response = $this->get($url)->assertOk()->assertSeeInOrder(['<header class="site-header">', 'class="site-socials"', '</header>'], false);
            foreach ($links as $link) {
                $response->assertSee('href="'.$link['url'].'"', false);
            }
        }
        foreach (['Primera entrada', 'Segunda entrada'] as $title) {
            $post = $this->createPost($title);
            $response = $this->get($post->url())->assertOk()->assertSee('aria-label="Redes de Mi Blog"', false);
            $this->assertSame(1, substr_count($response->getContent(), 'class="site-socials"'));
            foreach ($links as $link) {
                $response->assertSee('href="'.$link['url'].'"', false);
            }
            $this->get('/admin/posts/'.$post->id.'/edit')->assertOk()->assertDontSee('Portada y video')->assertDontSee('Video principal de YouTube')->assertSee('Imagen de cabecera');
        }
        Livewire::test(SiteSettings::class)->fillForm(['social_links' => []])->call('save')->assertHasNoFormErrors();
        $this->get($post->url())->assertDontSee('class="site-socials"', false);
        $this->get('/')->assertDontSee('class="site-socials"', false);
        $this->assertSame('', Setting::read('site')['youtube_url']);
    }

    public function test_social_profiles_are_saved_with_the_single_identity_save(): void
    {
        $links = [['network' => 'youtube', 'url' => 'https://www.youtube.com/@micanal']];
        $component = Livewire::test(SiteSettings::class)->fillForm(['social_links' => $links])->call('save')->assertHasNoFormErrors()
            ->assertNotified('Sitio actualizado');
        $this->assertSame($links, Setting::read('site')['social_links']);
        $this->assertSame($links[0]['url'], Setting::read('site')['youtube_url']);
        $this->get('/')->assertOk()->assertSee('aria-label="Mi Blog en YouTube"', false)->assertSee('href="https://www.youtube.com/@micanal"', false);
        $component->fillForm(['social_links' => []])->call('save')->assertHasNoFormErrors();
        $this->get('/')->assertDontSee('class="site-socials"', false);
    }

    public function test_selecting_youtube_without_a_channel_does_not_publish_an_empty_link(): void
    {
        $before = Setting::read('site');
        $component = Livewire::test(SiteSettings::class)->fillForm(['social_links' => [['network' => 'youtube', 'url' => '']]])
            ->call('save')->assertHasFormErrors();
        $this->assertSame($before, Setting::read('site'));
        $component->fillForm(['social_links' => [['network' => 'youtube', 'url' => 'https://www.youtube.com/@micanal']]])
            ->call('save')->assertHasNoFormErrors();
        $this->get('/')->assertSee('href="https://www.youtube.com/@micanal"', false);
    }

    public function test_social_settings_reject_videos_fake_domains_duplicates_and_more_than_three_links(): void
    {
        foreach (['https://www.youtube.com/watch?v=FsWac47RSNc', 'https://youtube.com.evil.test/@canal', 'javascript:alert(1)'] as $url) {
            Livewire::test(SiteSettings::class)->fillForm(['social_links' => [['network' => 'youtube', 'url' => $url]]])->call('save')->assertHasFormErrors();
        }
        $links = [['network' => 'github', 'url' => 'https://github.com/test'], ['network' => 'github', 'url' => 'https://github.com/other']];
        Livewire::test(SiteSettings::class)->fillForm(['social_links' => $links])->call('save')->assertHasFormErrors();
        $links = [['network' => 'github', 'url' => 'https://github.com/test'], ['network' => 'youtube', 'url' => 'https://youtube.com/@test'], ['network' => 'instagram', 'url' => 'https://instagram.com/test'], ['network' => 'x', 'url' => 'https://x.com/test']];
        Livewire::test(SiteSettings::class)->fillForm(['social_links' => $links])->call('save')->assertHasFormErrors();
        $this->assertSame([], app(SocialLinks::class)->configured());
    }

    public function test_the_old_channel_is_retained_and_legacy_video_becomes_an_editable_block_once(): void
    {
        Setting::write('site', ['name' => 'Anterior', 'timezone' => 'UTC', 'youtube_url' => 'https://youtube.com/@anterior']);
        $this->assertSame('https://youtube.com/@anterior', app(SocialLinks::class)->configured()[0]['url']);
        $post = $this->createPost('Con video', ['youtube_url' => 'https://www.youtube.com/watch?v=FsWac47RSNc', 'youtube_credit' => 'Su autor']);
        $oldRevision = $post->revisions()->firstOrFail();
        $migration = require database_path('migrations/2026_10_15_002334_move_post_videos_into_document.php');
        $migration->up();
        $migration->up();
        $this->assertCount(1, $post->fresh()->content);
        $this->assertSame('Su autor', $post->fresh()->content[0]['data']['caption']);
        $this->assertNull($post->fresh()->youtube_url);
        $this->get($post->url())->assertOk()->assertSee('FsWac47RSNc')->assertSee('https://youtube.com/@anterior');
        $restored = $post->refresh()->revisionFormData($oldRevision)['data'];
        $this->assertCount(1, $restored['content']);
        $this->assertSame('https://www.youtube.com/watch?v=FsWac47RSNc', $restored['content'][0]['data']['url']);
        $this->assertNull($restored['youtube_url']);
    }

    public function test_variants_preview_before_applying_and_can_return_to_the_original_without_changing_content(): void
    {
        $post = $this->createPost('Contenido intacto');
        $before = $post->fresh()->getRawOriginal();
        $site = Setting::read('site');
        $palettes = app(ColorPalettes::class);
        $component = Livewire::test(AppearanceSettings::class)->assertSee('Lavanda')->assertSee('Azul')->assertSee('Rosa')->assertSee('Ámbar')
            ->assertDontSee('Crear una copia')->assertDontSee('Guardar colores')->set('variant', 'blue')->assertSee('Así se ve Azul');
        $this->assertSame('default', $palettes->activeId());
        $this->get('/')->assertOk()->assertDontSee('--accent:#3662a1', false);
        $component->call('activate')->assertHasNoErrors()->assertNotified('Variante de color aplicada');
        $this->assertSame(['variant' => 'blue'], Setting::read('appearance'));
        $this->get('/')->assertOk()->assertSee('--accent:#3662a1', false)->assertSee('--accent:#a4bde1', false);
        $this->get($post->url())->assertOk()->assertSee('--accent:#3662a1', false)->assertSee('--accent:#a4bde1', false);
        Livewire::test(AppearanceSettings::class)->assertSet('variant', 'blue');
        $component->set('variant', 'default')->call('activate')->assertHasNoErrors();
        $this->assertSame('default', $palettes->activeId());
        $this->assertSame('', $palettes->css());
        $this->assertSame($before, $post->fresh()->getRawOriginal());
        $this->assertSame($site, Setting::read('site'));
    }

    public function test_unknown_variants_cannot_replace_the_active_colors_or_inject_css(): void
    {
        $palettes = app(ColorPalettes::class);
        $palettes->activate('rose');
        Livewire::test(AppearanceSettings::class)->set('variant', '</style><script>alert(1)</script>')->call('activate')
            ->assertHasErrors(['variant'])->assertSee('Elige una de las variantes disponibles.');
        $this->assertSame(['variant' => 'rose'], Setting::read('appearance'));
        Setting::write('appearance', ['variant' => 'removed-copy']);
        $this->assertSame('default', $palettes->activeId());
        $this->assertSame('', $palettes->css());
        $this->get('/')->assertOk()->assertDontSee('removed-copy');
    }

    public function test_curated_variants_preserve_brightness_and_readable_contrast_in_both_modes(): void
    {
        $palettes = app(ColorPalettes::class);
        foreach ($palettes->all() as $id => $variant) {
            $palettes->activate($id);
            foreach ($variant['colors'] as $mode => $colors) {
                foreach (['bg', 'surface', 'ink', 'muted', 'line', 'button-ink', 'info', 'note', 'warm'] as $fixed) {
                    $this->assertSame($palettes->defaults()[$mode][$fixed], $colors[$fixed], $id.' '.$mode.' '.$fixed);
                    $this->assertStringNotContainsString('--'.$fixed.':', $palettes->css());
                }
                foreach (['heading-primary', 'section-heading', 'accent', 'accent-soft', 'button-bg', 'button-shadow'] as $token) {
                    $this->assertEqualsWithDelta($palettes->contrast($palettes->defaults()[$mode][$token], '#000000'), $palettes->contrast($colors[$token], '#000000'), .06, $id.' '.$mode.' '.$token.' brightness');
                }
                foreach (['bg', 'surface'] as $background) {
                    foreach (['ink', 'muted', 'heading-primary', 'section-heading', 'accent'] as $token) {
                        $this->assertGreaterThanOrEqual(4.5, $palettes->contrast($colors[$token], $colors[$background]), $id.' '.$mode.' '.$token.' on '.$background);
                    }
                }
                $this->assertGreaterThanOrEqual(4.5, $palettes->contrast($colors['button-bg'], $colors['button-ink']), $id.' '.$mode.' button');
            }
        }
    }

    public function test_old_custom_palettes_are_removed_without_touching_other_settings_or_a_new_selection(): void
    {
        $site = Setting::read('site');
        Setting::write('appearance', ['active' => 'old-copy', 'palettes' => ['old-copy' => ['name' => 'Prueba colores', 'colors' => []]]]);
        $migration = require database_path('migrations/2026_10_15_005740_replace_saved_palettes_with_color_variants.php');
        $migration->up();
        $this->assertSame(['variant' => 'default'], Setting::read('appearance'));
        Livewire::test(AppearanceSettings::class)->assertDontSee('Prueba colores')->set('variant', 'amber')->call('activate')->assertHasNoErrors();
        $migration->up();
        $this->assertSame(['variant' => 'amber'], Setting::read('appearance'));
        $this->assertSame($site, Setting::read('site'));
    }

    public function test_only_administrators_can_change_colors_and_social_settings(): void
    {
        $component = Livewire::test(AppearanceSettings::class)->set('variant', 'blue');
        $before = Setting::read('appearance');
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);
        $this->actingAs($editor)->get('/admin/ajustes/colores')->assertForbidden();
        $this->get('/admin/ajustes/identidad')->assertForbidden();
        $component->call('activate')->assertForbidden();
        $this->assertSame($before, Setting::read('appearance'));
    }
}
