<?php

namespace Tests\Feature;

use App\Filament\Pages\MonetizationSettings;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\Monetization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MonetizationTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-monetization');
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

    private function createPost(array $attributes = []): Post
    {
        return Post::create($attributes + [
            'title' => 'Una publicación', 'slug' => 'una-publicacion', 'status' => 'published',
            'user_id' => auth()->id() ?? User::factory()->create()->id,
            'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Contenido de la publicación.</p>']]],
        ]);
    }

    private function activeConfiguration(array $overrides = []): array
    {
        return $overrides + [
            'status' => 'active', 'publisher_id' => 'ca-pub-1234567890123456',
            'format' => 'manual', 'privacy_ready' => true, 'home_bottom' => '1234567890',
            'article_top' => '2345678901', 'article_bottom' => '3456789012',
        ];
    }

    public function test_a_new_site_loads_no_advertising_and_has_no_empty_advertising_spaces(): void
    {
        $post = $this->createPost();
        foreach (['/', $post->url()] as $url) {
            $this->get($url)->assertOk()->assertDontSee('google-adsense-account', false)
                ->assertDontSee('monetization.js', false)->assertDontSee('adsbygoogle', false)
                ->assertDontSee('data-ad-placement=', false);
        }
        $this->get('/ads.txt')->assertNotFound();
        $this->assertNull(Setting::read('monetization'));
    }

    public function test_only_active_administrators_can_open_or_save_monetization(): void
    {
        $this->get(MonetizationSettings::getUrl())->assertRedirect();
        $this->admin();
        $this->get(MonetizationSettings::getUrl())->assertOk()->assertSee('Monetización');
        $component = Livewire::test(MonetizationSettings::class);
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $this->get(MonetizationSettings::getUrl())->assertForbidden();
        $component->call('save')->assertForbidden();
        Livewire::test(MonetizationSettings::class)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]));
        Livewire::test(MonetizationSettings::class)->assertForbidden();
        $this->assertNull(Setting::read('monetization'));
    }

    #[TestWith(['pub-1234567890123456'])]
    #[TestWith([' 1234567890123456 '])]
    #[TestWith(['ca-pub-1234567890123456'])]
    public function test_verification_saves_a_normalized_account_and_publishes_only_the_meta_tag_and_ads_txt(string $publisher): void
    {
        $this->admin();
        Livewire::test(MonetizationSettings::class)
            ->fillForm(['status' => 'verification', 'publisher_id' => $publisher])
            ->call('save')->assertHasNoErrors()->assertNotified('Monetización guardada');
        $saved = Setting::read('monetization');
        $this->assertSame('ca-pub-1234567890123456', $saved['publisher_id']);
        $this->assertFalse($saved['privacy_ready']);
        Livewire::test(MonetizationSettings::class)->assertSet('data.publisher_id', 'ca-pub-1234567890123456');
        $this->get('/')->assertOk()->assertSee('name="google-adsense-account" content="ca-pub-1234567890123456"', false)
            ->assertDontSee('monetization.js', false)->assertDontSee('adsbygoogle', false)->assertDontSee('data-ad-placement=', false);
        $this->get('/ads.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertContent("google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0\n");
    }

    public function test_manual_ads_appear_only_in_the_selected_public_content_positions_and_can_be_disabled(): void
    {
        $this->admin();
        $post = $this->createPost();
        $component = Livewire::test(MonetizationSettings::class)
            ->fillForm($this->activeConfiguration(['article_top' => '']))->call('save')->assertHasNoErrors();
        $this->get('/')->assertOk()->assertSee('data-ad-placement="home_bottom"', false)
            ->assertSee('data-ad-slot="1234567890"', false)->assertDontSee('data-ad-placement="article_', false);
        $response = $this->get($post->url())->assertOk()->assertSee('data-ad-placement="article_bottom"', false)
            ->assertSee('data-ad-slot="3456789012"', false)->assertDontSee('data-ad-placement="article_top"', false)
            ->assertDontSee('data-ad-placement="home_bottom"', false)->assertSee('data-format="manual"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'monetization.js'));
        $component->set('data.status', 'off')->call('save')->assertHasNoErrors();
        $saved = Setting::read('monetization');
        $this->assertSame('ca-pub-1234567890123456', $saved['publisher_id']);
        $this->assertSame('3456789012', $saved['article_bottom']);
        $this->assertFalse($saved['privacy_ready']);
        $this->get($post->url())->assertOk()->assertDontSee('google-adsense-account', false)
            ->assertDontSee('monetization.js', false)->assertDontSee('data-ad-placement=', false);
        $this->get('/ads.txt')->assertNotFound();
    }

    public function test_automatic_ads_do_not_need_slots_and_keep_existing_slots_for_later_use(): void
    {
        $this->admin();
        $post = $this->createPost();
        Livewire::test(MonetizationSettings::class)->fillForm($this->activeConfiguration([
            'format' => 'automatic', 'home_bottom' => '', 'article_top' => '', 'article_bottom' => '',
        ]))->call('save')->assertHasNoErrors();
        $this->get($post->url())->assertOk()->assertSee('data-format="automatic"', false)
            ->assertSee('monetization.js', false)->assertDontSee('data-ad-placement=', false);
        app(Monetization::class)->save($this->activeConfiguration());
        Livewire::test(MonetizationSettings::class)->set('data.format', 'automatic')->call('save')->assertHasNoErrors();
        $this->assertSame('2345678901', Setting::read('monetization')['article_top']);
    }

    public function test_ads_are_excluded_from_information_pages_search_filters_empty_content_and_previews(): void
    {
        $this->admin();
        $post = $this->createPost(['title' => 'Publicación 0']);
        $page = Page::factory()->create(['slug' => 'contacto', 'kind' => 'custom', 'published' => true]);
        app(Monetization::class)->save($this->activeConfiguration());
        foreach (['/contacto', '/?q=publicaci', '/?q=0', '/?category=no-existe', '/series'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('monetization.js', false)->assertDontSee('data-ad-placement=', false);
        }
        foreach ([route('posts.preview', $post), route('pages.preview', $page)] as $url) {
            $this->get($url)->assertOk()->assertDontSee('monetization.js', false)
                ->assertDontSee('google-adsense-account', false)->assertDontSee('data-ad-placement=', false);
        }
        $post->update(['content' => []]);
        $this->get($post->url())->assertOk()->assertDontSee('monetization.js', false);
        $post->update(['status' => 'draft']);
        $this->get('/')->assertOk()->assertDontSee('monetization.js', false);
    }

    #[TestWith([['publisher_id' => ''], 'publisher_id'])]
    #[TestWith([['publisher_id' => '<script>alert(1)</script>'], 'publisher_id'])]
    #[TestWith([['publisher_id' => "ca-pub-1234567890123456\nevil.test"], 'publisher_id'])]
    #[TestWith([['home_bottom' => '12345" onload="alert(1)'], 'home_bottom'])]
    #[TestWith([['status' => 'unknown'], 'status'])]
    #[TestWith([['format' => 'custom-script'], 'format'])]
    #[TestWith([['privacy_ready' => false], 'privacy_ready'])]
    #[TestWith([['home_bottom' => '', 'article_top' => '', 'article_bottom' => ''], 'home_bottom'])]
    public function test_invalid_settings_cannot_replace_the_saved_configuration(array $invalid, string $field): void
    {
        $this->admin();
        app(Monetization::class)->save(['status' => 'verification', 'publisher_id' => 'ca-pub-9999999999999999']);
        $saved = Setting::read('monetization');
        Livewire::test(MonetizationSettings::class)->fillForm($this->activeConfiguration($invalid))
            ->call('save')->assertHasErrors(['data.'.$field]);
        $this->assertSame($saved, Setting::read('monetization'));
    }

    #[TestWith([['publisher_id' => '<script>bad</script>']])]
    #[TestWith([['privacy_ready' => false]])]
    #[TestWith([['status' => 'unknown']])]
    #[TestWith([['format' => 'custom-script']])]
    #[TestWith([['article_top' => 'invalid']])]
    public function test_invalid_stored_settings_never_render_advertising(array $invalid): void
    {
        $post = $this->createPost();
        Setting::write('monetization', $this->activeConfiguration($invalid));
        $this->get($post->url())->assertOk()->assertDontSee('google-adsense-account', false)
            ->assertDontSee('monetization.js', false)->assertDontSee('data-ad-placement=', false);
        $this->get('/ads.txt')->assertNotFound();
    }

    public function test_account_or_status_changes_reset_the_activation_confirmation(): void
    {
        $this->admin();
        app(Monetization::class)->save($this->activeConfiguration());
        Livewire::test(MonetizationSettings::class)->set('data.publisher_id', 'ca-pub-9999999999999999')
            ->assertSet('data.privacy_ready', false)->call('save')->assertHasErrors(['data.privacy_ready']);
        $this->assertSame('ca-pub-1234567890123456', Setting::read('monetization')['publisher_id']);
        Livewire::test(MonetizationSettings::class)->set('data.status', 'verification')->assertSet('data.privacy_ready', false);
    }
}
