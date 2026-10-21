<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Pages\AccessSettings;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Series\Pages\EditSeries;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Series;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Services\AdminAccess;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-hardening');
        config(['solqubit.installed_file' => $this->marker]);
        Storage::fake('media');
        Setting::write('site', ['name' => 'Solqubit']);
        Setting::write('theme', 'solqubit');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create(['role' => 'admin', 'password' => 'Password12345']);
        $this->editor = User::factory()->create(['role' => 'editor', 'password' => 'Password12345']);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function createPost(User $author, array $extra = []): Post
    {
        return Post::create($extra + [
            'user_id' => $author->id, 'title' => 'Entrada '.Str::random(6), 'slug' => 'entrada-'.Str::lower(Str::random(8)),
            'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Texto.</p>']]], 'status' => 'draft',
        ]);
    }

    private function media(User $owner, string $name): Media
    {
        return Media::create(['uuid' => (string) Str::uuid(), 'path' => 'files/'.Str::random(10).'.png', 'original_name' => $name, 'mime_type' => 'image/png', 'size' => 10, 'user_id' => $owner->id]);
    }

    public function test_editors_cannot_download_private_files_of_other_authors(): void
    {
        $own = $this->media($this->editor, 'mia.png');
        $other = $this->media($this->admin, 'ajena.png');
        foreach ([$own, $other] as $media) {
            Storage::disk('media')->put($media->path, 'png');
        }
        $this->actingAs($this->editor);
        $this->get($own->url())->assertOk();
        $this->get($other->url())->assertNotFound();
        $this->actingAs($this->admin);
        $this->get($other->url())->assertOk();
    }

    public function test_editors_manage_only_their_own_posts_while_admins_manage_all(): void
    {
        $own = $this->createPost($this->editor);
        $otherDraft = $this->createPost($this->admin);
        $otherPublished = $this->createPost($this->admin, ['status' => 'published', 'published_at' => now()->subMinute()]);

        foreach (['update', 'delete', 'restore', 'view'] as $ability) {
            $this->assertTrue($this->editor->can($ability, $own), $ability);
            $this->assertFalse($this->editor->can($ability, $otherDraft), $ability);
            $this->assertTrue($this->admin->can($ability, $own), $ability);
        }
        $this->assertTrue($this->editor->can('view', $otherPublished));
        $this->assertFalse($this->editor->can('update', $otherPublished));

        $this->actingAs($this->editor);
        $this->get('/admin/posts/'.$own->id.'/edit')->assertOk();
        $this->get('/admin/posts/'.$otherPublished->id.'/edit')->assertForbidden();
        $this->get(route('posts.preview', $otherDraft))->assertForbidden();
        Livewire::test(ListPosts::class)
            ->assertActionVisible(TestAction::make('edit')->table($own))
            ->assertActionHidden(TestAction::make('edit')->table($otherPublished))
            ->assertCanNotSeeTableRecords([$otherDraft])
            ->assertActionVisible(TestAction::make('duplicate')->table($otherPublished))
            ->selectTableRecords([$own->id, $otherPublished->id])
            ->callAction(TestAction::make('delete')->table()->bulk());
        $this->assertSoftDeleted($own);
        $this->assertNotSoftDeleted($otherPublished);
    }

    public function test_editors_may_create_and_rename_taxonomy_but_only_admins_delete_it(): void
    {
        $category = Category::create(['name' => 'Linux', 'slug' => 'linux']);
        $tag = Tag::create(['name' => 'Bash', 'slug' => 'bash']);
        $series = Series::create(['name' => 'Curso', 'slug' => 'curso']);
        foreach ([$category, $tag, $series] as $term) {
            $this->assertTrue($this->editor->can('create', $term::class));
            $this->assertTrue($this->editor->can('update', $term));
            $this->assertFalse($this->editor->can('delete', $term));
            $this->assertTrue($this->admin->can('delete', $term));
        }
    }

    public function test_editors_cannot_change_other_authors_entries_in_a_series(): void
    {
        $series = Series::create(['name' => 'Curso', 'slug' => 'curso']);
        $theirs = $this->createPost($this->admin, ['series_id' => $series->id, 'series_order' => 1]);
        $mine = $this->createPost($this->editor);
        $this->actingAs($this->editor);

        Livewire::test(EditSeries::class, ['record' => $series->id])->fillForm(['entries' => [['post_id' => $mine->id]]])->call('save')->assertHasFormErrors(['entries']);
        $this->assertSame($series->id, $theirs->fresh()->series_id);

        Livewire::test(EditSeries::class, ['record' => $series->id])->fillForm(['entries' => [['post_id' => $theirs->id], ['post_id' => $mine->id]]])->call('save')->assertHasNoFormErrors();
        $this->assertSame($series->id, $mine->fresh()->series_id);
    }

    public function test_editors_only_see_and_attach_their_own_or_public_media(): void
    {
        $own = $this->media($this->editor, 'propia.png');
        $private = $this->media($this->admin, 'privada.png');
        $public = $this->media($this->admin, 'publica.png');
        $this->createPost($this->admin, ['status' => 'published', 'published_at' => now()->subMinute(), 'cover_id' => $public->id]);

        $this->assertTrue($this->editor->can('update', $own));
        $this->assertFalse($this->editor->can('update', $public));
        $this->assertFalse($this->editor->can('delete', $private));
        $this->assertTrue($this->editor->can('view', $public));
        $this->assertFalse($this->editor->can('view', $private));

        $this->actingAs($this->editor);
        Livewire::test(ListMedia::class)->assertCanSeeTableRecords([$own, $public])->assertCanNotSeeTableRecords([$private]);
        $this->get('/admin/media/'.$public->id.'/edit')->assertForbidden();

        $post = $this->createPost($this->editor);
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['cover_id' => $private->id])->call('save')->assertHasFormErrors(['cover_id']);
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['cover_id' => $public->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($public->id, $post->fresh()->cover_id);

        $this->actingAs($this->admin);
        Livewire::test(ListMedia::class)->assertCanSeeTableRecords([$own, $public, $private]);
    }

    public function test_one_account_is_locked_after_ten_failures_from_any_ip(): void
    {
        $access = app(AdminAccess::class);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            request()->server->set('REMOTE_ADDR', '203.0.113.'.($attempt + 1));
            $access->failed($this->admin->email);
        }
        request()->server->set('REMOTE_ADDR', '198.51.100.20');
        try {
            $access->ensureCanAttempt(mb_strtoupper($this->admin->email));
            $this->fail('The account should be locked.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Esta cuenta acumula demasiados intentos fallidos', $error->errors()['data.email'][0]);
        }
        $access->ensureCanAttempt('otra@example.com');
        $this->assertCount(1, $access->activeBlocks()->where('key', $access->accountKey($this->admin->email)));

        $this->travel(16)->minutes();
        $access->ensureCanAttempt($this->admin->email);
        $this->assertTrue(true);
    }

    public function test_account_lock_applies_to_the_login_form_and_can_be_removed_by_an_admin(): void
    {
        $access = app(AdminAccess::class);
        RateLimiter::increment($access->accountKey($this->admin->email), AdminAccess::DECAY_SECONDS, AdminAccess::ACCOUNT_LIMIT);
        Livewire::test(Login::class)->fillForm(['email' => $this->admin->email, 'password' => 'Password12345'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();

        $access->unblock($access->accountKey($this->admin->email));
        Livewire::test(Login::class)->fillForm(['email' => $this->admin->email, 'password' => 'Password12345'])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_forwarded_client_ip_is_only_trusted_from_configured_proxies(): void
    {
        Setting::write('access', ['blocked_ips' => ['198.51.100.7']]);
        $this->get('/admin/login', ['X-Forwarded-For' => '198.51.100.7'])->assertOk();

        config(['trustedproxy.proxies' => '127.0.0.1']);
        $this->get('/admin/login', ['X-Forwarded-For' => '198.51.100.7'])->assertForbidden();
    }

    public function test_admins_can_be_required_to_set_up_two_step_verification(): void
    {
        $this->actingAs($this->admin);
        $this->get('/admin')->assertOk();
        Livewire::test(AccessSettings::class)->assertSet('data.require_admin_two_factor', false)
            ->fillForm(['require_admin_two_factor' => true])->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Setting::read('access')['require_admin_two_factor']);
        $this->assertSame([], Setting::read('access')['blocked_ips']);

        $this->get('/admin')->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
        $this->get(Filament::getSetUpRequiredMultiFactorAuthenticationUrl())->assertOk();

        $this->flushSession();
        $this->actingAs($this->editor);
        $this->get('/admin')->assertOk();

        $this->flushSession();
        $this->admin->saveAppAuthenticationSecret(AppAuthentication::make()->generateSecret());
        $this->actingAs($this->admin->fresh());
        $this->get('/admin')->assertOk();
    }

    public function test_public_pages_send_a_strict_policy_that_allows_only_the_theme_inline_script(): void
    {
        $response = $this->get('/')->assertOk();
        $policy = $response->headers->get('Content-Security-Policy');
        preg_match_all('~<script(?![^>]*\bsrc=)(?![^>]*application/ld\+json)[^>]*>(.*?)</script>~s', $response->getContent(), $scripts);
        $this->assertNotEmpty($scripts[1]);
        foreach ($scripts[1] as $script) {
            $this->assertStringContainsString("'sha256-".base64_encode(hash('sha256', $script, true))."'", $policy);
        }
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString('frame-src \'self\' https://www.youtube-nocookie.com', $policy);
        $this->assertStringContainsString("font-src 'self' data:", $policy);
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-(inline|eval)'/", $policy);
        $this->assertStringNotContainsString('googlesyndication', $policy);
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));

        Setting::write('monetization', ['status' => 'active', 'publisher_id' => 'ca-pub-1234567890123456', 'format' => 'automatic', 'privacy_ready' => true]);
        $policy = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://pagead2.googlesyndication.com', $policy);
        $this->assertStringContainsString('https://googleads.g.doubleclick.net', $policy);

        $this->get('/admin/login')->assertHeader('Content-Security-Policy', SecurityHeaders::MINIMAL_POLICY);
    }

    public function test_hsts_and_secure_session_cookies_follow_https_requests(): void
    {
        $plain = $this->get('http://localhost/admin/login');
        $this->assertFalse($plain->headers->has('Strict-Transport-Security'));
        $this->assertFalse(collect($plain->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === config('session.cookie'))->isSecure());

        config(['session.secure' => null]);
        $secure = $this->get('https://localhost/admin/login');
        $secure->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->assertTrue(collect($secure->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === config('session.cookie'))->isSecure());
    }

    public function test_json_not_found_errors_do_not_reveal_model_names_in_production(): void
    {
        config(['app.debug' => false]);
        $response = $this->getJson('/media/'.Str::uuid())->assertNotFound();
        $response->assertExactJson(['message' => 'No encontramos lo que buscas.']);
        $this->assertStringNotContainsString('App\\Models', $response->getContent());
    }
}
