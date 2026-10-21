<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteSettings;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\ContentSchema;
use App\Services\MediaLibrary;
use App\Services\ThemeRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CmsTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-test-'.bin2hex(random_bytes(8));
        file_put_contents($this->marker, '{}');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Solqubit', 'description' => 'Material de apoyo', 'timezone' => 'America/Guayaquil', 'about' => '<p>Sobre el blog.</p>']);
        Setting::write('theme', 'solqubit');
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function createPost(array $extra = []): Post
    {
        return Post::create($extra + ['user_id' => $this->admin->id, 'title' => 'Mi entrada Linux', 'slug' => 'linux-'.bin2hex(random_bytes(4)), 'content' => [['type' => 'heading', 'data' => ['text' => 'Primeros pasos', 'level' => 'h2']], ['type' => 'paragraph', 'data' => ['html' => '<p>Contenido real de prueba.</p>']]], 'status' => 'published', 'published_at' => now()->subMinute()]);
    }

    public function test_public_pages_render_real_published_content_and_search(): void
    {
        $post = $this->createPost(['featured' => true]);
        $this->createPost(['title' => 'Otra publicación para el listado']);
        $home = Page::where('kind', 'home')->firstOrFail();
        $home->update(['settings' => array_replace($home->settings ?? [], ['catalog_title' => 'Mis publicaciones'])]);
        $this->get('/')->assertOk()->assertSee('Mi entrada Linux')->assertSee('Mis publicaciones');
        $this->get($post->url())->assertOk()->assertSee('Contenido real de prueba.')->assertSee('Primeros pasos');
        $this->get('/?q=Linux')->assertOk()->assertSee('Mi entrada Linux');
        $this->get('/?q=Inexistente')->assertOk()->assertSee('No hay entradas');
        $this->get('/series')->assertOk();
        $this->get('/acerca')->assertOk();
        $this->get('/sitemap.xml')->assertOk()->assertSee($post->slug);
        $this->get('/feed.xml')->assertOk()->assertSee($post->title);
    }

    public function test_drafts_scheduled_archived_and_deleted_posts_are_private(): void
    {
        foreach ([['status' => 'draft'], ['published_at' => now()->addDay()], ['status' => 'archived']] as $attributes) {
            $post = $this->createPost($attributes);
            $this->get($post->url())->assertNotFound();
            $this->get('/sitemap.xml')->assertDontSee($post->slug);
        }
        $post = $this->createPost();
        $post->delete();
        $this->get($post->url())->assertNotFound();
        $this->get('/preview/'.$post->id)->assertRedirect('/admin/login');
    }

    public function test_editor_can_create_edit_preview_and_restore_an_entry(): void
    {
        $this->actingAs($this->admin);
        $form = ['title' => 'Creada en el editor', 'slug' => 'desde-editor', 'excerpt' => 'Resumen', 'status' => 'draft', 'reading_minutes' => 5, 'difficulty' => 'Básico', 'series_order' => 0, 'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Primera versión</p>']]]];
        Livewire::test(CreatePost::class)->fillForm($form)->call('create')->assertHasNoFormErrors();
        $post = Post::where('slug', 'desde-editor')->firstOrFail();
        $this->assertSame($this->admin->id, $post->user_id);
        $this->get('/preview/'.$post->id)->assertOk()->assertSee('Primera versión');
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['title' => 'Título editado', 'status' => 'published'])->call('save')->assertHasNoFormErrors();
        $post->refresh();
        $this->assertSame('Título editado', $post->title);
        $this->get($post->url())->assertOk();
        $old = $post->revisions()->reorder('id')->first();
        Livewire::test(EditPost::class, ['record' => $post->id])->callAction('revision', data: ['revision_id' => $old->id])
            ->assertSet('data.title', 'Creada en el editor')->call('save')->assertHasNoFormErrors();
        $this->assertSame('published', $post->fresh()->status);
        $this->assertSame('Creada en el editor', $post->fresh()->title);
        $this->assertGreaterThanOrEqual(3, $post->revisions()->count());
    }

    public function test_every_admin_resource_and_settings_page_renders(): void
    {
        $post = $this->createPost();
        $this->actingAs($this->admin);
        foreach (['/admin', '/admin/posts', '/admin/posts/create', '/admin/posts/'.$post->id.'/edit', '/admin/media', '/admin/media/create', '/admin/clasificacion/categorias', '/admin/clasificacion/categorias/create', '/admin/clasificacion/etiquetas', '/admin/clasificacion/etiquetas/create', '/admin/clasificacion/series', '/admin/clasificacion/series/create', '/admin/users', '/admin/users/create', '/admin/ajustes/identidad', '/admin/ajustes/general', '/admin/ajustes/correo', '/admin/ajustes/seguridad', '/admin/ajustes/seo', '/admin/ajustes/monetizacion', '/admin/ajustes/colores', '/admin/actualizaciones', '/admin/profile', '/admin/pages', '/admin/pages/create', '/admin/ajustes/menus'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_editor_cannot_manage_users_or_theme_and_disabled_users_cannot_access(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get('/admin/posts')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/ajustes/identidad')->assertForbidden();
        $this->get('/admin/downloads/export')->assertForbidden();
        $this->get('/admin/downloads/backups/20261014-190459-pw6dgyvobx.zip')->assertNotFound();
        $editor->update(['is_active' => false]);
        $this->actingAs($editor->fresh())->get('/admin/posts')->assertForbidden();
    }

    public function test_dashboard_links_to_daily_tasks_for_admins(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertOk()
            ->assertSee('Portada del blog')->assertSee(url('/admin/ajustes/portada'))
            ->assertSee('Escribir una entrada')->assertSee(url('/admin/posts/create'));
    }

    public function test_editor_dashboard_only_links_to_content_tasks(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get('/admin')->assertOk()
            ->assertSee('Escribir una entrada')->assertSee('Mis entradas')
            ->assertDontSee('Portada del blog')->assertDontSee('Identidad del sitio')
            ->assertDontSee('Páginas del sitio')->assertDontSee('Menú de navegación');
    }

    public function test_last_admin_cannot_be_disabled(): void
    {
        $this->expectException(ValidationException::class);
        $this->admin->update(['is_active' => false]);
    }

    public function test_rich_text_sanitizes_scripts_event_handlers_and_unsafe_urls(): void
    {
        $html = app(ContentSchema::class)->sanitize('<p onclick="evil()">Hola <strong>mundo</strong><script>evil()</script><iframe src="https://example.com"></iframe><a href="javascript:evil()">click</a><img src=x onerror=evil()></p>');
        $this->assertStringContainsString('<strong>mundo</strong>', $html);
        foreach (['onclick', '<script', '<iframe', 'javascript:', 'onerror', '<img'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html);
        }
        $this->assertNull(app(ContentSchema::class)->youtubeId('https://youtube.com.evil.test/watch?v=FsWac47RSNc'));
        $this->assertSame('FsWac47RSNc', app(ContentSchema::class)->youtubeId('https://www.youtube.com/watch?v=FsWac47RSNc'));
        $post = $this->createPost(['title' => '</title><script>evil()</script>', 'seo_description' => '" onmouseover="evil()']);
        $this->get($post->url())->assertOk()->assertDontSee('<script>evil()</script>', false)->assertSee('&lt;script&gt;evil()&lt;/script&gt;', false);
    }

    public function test_media_upload_and_draft_privacy_then_public_delivery(): void
    {
        Storage::fake('media');
        $this->actingAs($this->admin);
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('portada.png', 80, 60));
        $post = $this->createPost(['cover_id' => $media->id, 'status' => 'draft']);
        $this->get('/media/'.$media->uuid)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        auth()->logout();
        $this->get('/media/'.$media->uuid)->assertNotFound();
        $post->update(['status' => 'published']);
        $this->get('/media/'.$media->uuid)->assertOk();
        $this->get('/media/'.$media->uuid.'?download=1')->assertOk()->assertDownload('portada.png');
        $post->update(['status' => 'draft']);
        $this->get('/media/'.$media->uuid)->assertNotFound();
    }

    public function test_media_used_only_in_old_revision_can_be_deleted(): void
    {
        Storage::fake('media');
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('archivo.png'));
        $post = $this->createPost(['cover_id' => $media->id]);
        $this->assertTrue($media->isReferenced());
        $post->update(['cover_id' => null]);
        $this->assertFalse($media->fresh()->isReferenced());
        $this->assertTrue($media->fresh()->delete());
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_media_used_by_trashed_post_cannot_be_deleted(): void
    {
        Storage::fake('media');
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('archivo.png'));
        $this->createPost(['cover_id' => $media->id])->delete();
        $this->assertTrue($media->isReferenced());
        $this->expectException(ValidationException::class);
        $media->delete();
    }

    public function test_executable_and_svg_uploads_are_rejected(): void
    {
        Storage::fake('media');
        foreach (['script.php' => '<?php echo "bad";', 'image.svg' => '<svg onload="evil()"></svg>', 'fake.png' => '<?php echo "bad";'] as $name => $content) {
            try {
                app(MediaLibrary::class)->upload(UploadedFile::fake()->createWithContent($name, $content));
                $this->fail('Unsafe upload accepted: '.$name);
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('upload', $error->errors());
            }
        }
        $this->assertSame(0, Media::count());
    }

    public function test_the_single_template_preserves_content_and_rejects_other_themes(): void
    {
        $post = $this->createPost()->fresh();
        $before = $post->getRawOriginal();
        $this->actingAs($this->admin);
        Setting::write('theme', 'editorial');
        Livewire::test(SiteSettings::class)->fillForm(['name' => 'Solqubit', 'description' => 'Material', 'timezone' => 'America/Guayaquil'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('solqubit', app(ThemeRegistry::class)->active()['id']);
        $this->assertSame($before, $post->fresh()->getRawOriginal());
        $this->get('/')->assertOk()->assertSee('themes/solqubit/editorial-theme.css')->assertDontSee('themes/editorial/theme.css');
        $this->assertSame(['solqubit'], array_keys(app(ThemeRegistry::class)->all()));
    }

    public function test_installer_is_locked_and_private_files_are_not_routes(): void
    {
        $this->get('/install')->assertOk()->assertSee('Tu blog ya está instalado.');
        $this->post('/install', [])->assertNotFound();
        foreach (['/.env', '/core/.env', '/storage/app/install-token'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_future_content_schema_is_not_silently_overwritten(): void
    {
        $this->expectException(ValidationException::class);
        $this->createPost(['content_version' => 99]);
    }

    public function test_system_page_offers_zip_updates_without_backup_actions(): void
    {
        $this->actingAs($this->admin)->get('/admin/actualizaciones')->assertOk()
            ->assertSee('Actualizaciones')->assertSee('Requisitos del servidor')
            ->assertSee('Subir el ZIP de actualización')->assertSee('Validar ZIP')
            ->assertSee('Buscar actualizaciones')->assertDontSee('servidor remoto queda pendiente')->assertDontSee('Crear copia de seguridad');
        $this->get('/admin/downloads/backups/old.zip')->assertNotFound();
        $commands = Artisan::all();
        $this->assertArrayNotHasKey('solqubit:backup', $commands);
        $this->assertArrayNotHasKey('solqubit:restore', $commands);
    }

    public function test_content_export_requires_admin_and_contains_real_blocks(): void
    {
        $post = $this->createPost();
        $this->get('/admin/downloads/export')->assertRedirect('/admin/login');
        $response = $this->actingAs($this->admin)->get('/admin/downloads/export')->assertOk();
        $data = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('solqubit-content', $data['format']);
        $this->assertSame($post->content, $data['posts'][0]['content']);
        $this->assertArrayNotHasKey('users', $data);
    }
}
