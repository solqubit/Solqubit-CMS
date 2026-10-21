<?php

namespace Tests\Feature;

use App\Http\Controllers\MediaController;
use App\Models\Media;
use App\Models\Post;
use App\Models\Series;
use App\Models\Setting;
use App\Models\User;
use App\Services\MediaLibrary;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SiteSecurityAndPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-security');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Solqubit', 'timezone' => 'America/Guayaquil']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function createPost(array $attributes = []): Post
    {
        return Post::create($attributes + [
            'user_id' => User::factory()->create()->id, 'title' => 'Entrada de prueba',
            'slug' => 'entrada-'.fake()->unique()->numerify('######'), 'status' => 'published',
            'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Contenido completo de la entrada.</p>']]],
        ]);
    }

    #[TestWith(['q[]=linux'])]
    #[TestWith(['category[]=linux'])]
    #[TestWith(['page[]=1'])]
    #[TestWith(['page=-1'])]
    #[TestWith(['page=1.5'])]
    #[TestWith(['page=99999999999999999999'])]
    public function test_invalid_public_filters_return_a_client_error(string $query): void
    {
        $this->get('/?'.$query)->assertStatus(400);
        $this->get('/series?'.$query)->assertStatus(400);
    }

    public function test_search_still_finds_body_text_without_loading_it_into_cards(): void
    {
        $post = $this->createPost();
        $response = $this->get('/?q=Contenido+completo&page=1')->assertOk()->assertSee($post->title);
        $this->assertArrayNotHasKey('content', $response->viewData('posts')->first()->getAttributes());
        $this->get($post->url())->assertOk()->assertSee('Contenido completo de la entrada.');
    }

    public function test_featured_related_series_and_feed_do_not_load_full_documents(): void
    {
        $series = Series::create(['name' => 'Primeros pasos', 'slug' => 'primeros-pasos']);
        $featured = $this->createPost(['featured' => true, 'series_id' => $series->id]);
        $post = $this->createPost(['series_id' => $series->id]);
        $home = $this->get('/')->assertOk()->assertSee($featured->title)->assertSee($post->title);
        $this->assertSame($featured->id, $home->viewData('featured')->id);
        $this->assertArrayNotHasKey('content', $home->viewData('featured')->getAttributes());
        $article = $this->get($post->url())->assertOk();
        $this->assertSame($featured->id, $article->viewData('related')->id);
        $this->assertArrayNotHasKey('content', $article->viewData('related')->getAttributes());
        $response = $this->get('/series')->assertOk()->assertSee($series->name);
        $entries = $response->viewData('series')->first()->posts;
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertArrayNotHasKey('content', $entry->getAttributes());
        }
        $feed = $this->get('/feed.xml')->assertOk()->assertSee($post->title);
        $this->assertArrayNotHasKey('content', $feed->viewData('posts')->first()->getAttributes());
    }

    public function test_forged_host_header_never_reaches_generated_links(): void
    {
        $post = $this->createPost();
        foreach (['/', '/series', $post->url(), '/feed.xml', '/sitemap.xml'] as $url) {
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            $this->get('http://attacker.example'.$path)->assertOk()->assertDontSee('attacker.example', false);
        }
    }

    public function test_public_errors_use_the_site_theme_and_suggest_recent_entries(): void
    {
        $post = $this->createPost(['title' => 'Entrada reciente']);
        $this->get('/no-existe-esta-direccion')->assertNotFound()
            ->assertSee('No encontramos esta página')->assertSee('<header class="site-header">', false)
            ->assertSee('Entrada reciente')->assertSee('href="'.$post->url().'"', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)->assertDontSee('rel="canonical"', false);
        $this->get('/?page=abc')->assertStatus(400)->assertSee('No pudimos entender esta dirección')->assertSee('<header class="site-header">', false);
        $this->getJson('/no-existe-esta-direccion')->assertNotFound()->assertDontSee('site-header');
        $this->get('/admin/no-existe-esta-direccion')->assertNotFound()->assertSee('No encontramos esta página')->assertDontSee('site-header');
    }

    public function test_public_media_revalidates_without_exposing_unpublished_files(): void
    {
        Storage::fake('media');
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('portada.png'));
        $post = $this->createPost(['cover_id' => $media->id]);
        $response = $this->get($media->url())->assertOk()->assertHeader('ETag')->assertHeader('Last-Modified');
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age='.MediaController::PUBLIC_MAX_AGE, $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
        $etag = $response->headers->get('ETag');
        $cached = $this->get($media->url(), ['If-None-Match' => $etag])->assertStatus(304);
        ob_start();
        $cached->baseResponse->sendContent();
        $this->assertSame('', ob_get_clean());
        $this->get($media->url(), ['If-Modified-Since' => $response->headers->get('Last-Modified')])->assertStatus(304);
        $this->get($media->url(), ['If-None-Match' => '"outdated"'])->assertOk();
        $this->get($media->url(), ['Range' => 'bytes=0-9'])->assertStatus(206)->assertHeader('Content-Length', '10');

        $post->update(['status' => 'draft']);
        $this->get($media->url(), ['If-None-Match' => $etag])->assertNotFound();
        $this->get($media->url(), ['If-Modified-Since' => $response->headers->get('Last-Modified')])->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $private = $this->get($media->url(), ['If-None-Match' => $etag])->assertOk();
        $this->assertStringContainsString('no-store', $private->headers->get('Cache-Control'));
        $this->assertNull($private->headers->get('ETag'));
    }

    public function test_profile_password_changes_require_the_same_strength_as_user_creation(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'ExistingPass123']);
        $this->actingAs($user);
        Livewire::test(EditProfile::class)->fillForm([
            'password' => 'Short123', 'passwordConfirmation' => 'Short123', 'currentPassword' => 'ExistingPass123',
        ])->call('save')->assertHasFormErrors(['password']);
        $this->assertTrue(Hash::check('ExistingPass123', $user->fresh()->password));

        Livewire::test(EditProfile::class)->fillForm([
            'password' => 'NewPassword456', 'passwordConfirmation' => 'NewPassword456', 'currentPassword' => 'wrong-password',
        ])->call('save')->assertHasFormErrors(['currentPassword']);
        $this->assertTrue(Hash::check('ExistingPass123', $user->fresh()->password));

        Livewire::test(EditProfile::class)->fillForm([
            'password' => 'NewPassword456', 'passwordConfirmation' => 'NewPassword456', 'currentPassword' => 'ExistingPass123',
        ])->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('NewPassword456', $user->fresh()->password));
    }

    #[TestWith(['alt_text', 256])]
    #[TestWith(['caption', 3001])]
    public function test_invalid_media_metadata_is_rejected_before_storing_the_file(string $field, int $length): void
    {
        Storage::fake('media');
        try {
            app(MediaLibrary::class)->upload(UploadedFile::fake()->image('imagen.png'), [$field => str_repeat('x', $length)]);
            $this->fail('Se aceptó una descripción que excede el límite.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertSame(0, Media::count());
        $this->assertEmpty(Storage::disk('media')->allFiles());
        $valid = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('imagen.png'), [$field => 'Descripción válida']);
        $this->assertSame('Descripción válida', $valid->$field);
        Storage::disk('media')->assertExists($valid->path);
    }

    public function test_html_security_policy_keeps_media_sandboxing_and_admin_privacy(): void
    {
        $policy = "base-uri 'self'; object-src 'none'; frame-ancestors 'self'";
        $public = $this->get('/')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("default-src 'self'", $public->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("object-src 'none'; frame-ancestors 'self'", $public->headers->get('Content-Security-Policy'));
        $login = $this->get('/admin/login')->assertOk()->assertHeader('Content-Security-Policy', $policy)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $login->headers->get('Cache-Control'));
        Storage::fake('media');
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('imagen.png'));
        $this->createPost(['cover_id' => $media->id]);
        $this->get($media->url())->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    #[TestWith([['type' => 'paragraph', 'attrs' => 'invalid']])]
    #[TestWith([['type' => 'text', 'text' => 'Texto', 'marks' => 'invalid']])]
    #[TestWith([['type' => 'text', 'text' => 'Texto', 'marks' => ['invalid']]])]
    #[TestWith([['type' => 'text', 'text' => 'Texto', 'marks' => [['type' => 'link', 'attrs' => ['href' => ['invalid']]]]]])]
    public function test_malformed_editor_nodes_return_validation_errors(array $node): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson(route('editor.preview'), [
            'context' => 'post', 'fields' => [], 'mode' => 'light',
            'document' => ['type' => 'doc', 'content' => [$node]],
        ])->assertUnprocessable()->assertJsonValidationErrors('content');
    }
}
