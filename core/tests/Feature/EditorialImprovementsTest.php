<?php

namespace Tests\Feature;

use App\Filament\Auth\Profile;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Category;
use App\Models\EditorDraft;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContentDocument;
use App\Services\EditorBlocks;
use App\Services\MediaLibrary;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EditorialImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-editorial');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Solqubit', 'timezone' => 'UTC']);
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
        return Post::create($attributes + ['title' => 'Mi entrada', 'slug' => 'mi-entrada', 'content' => [], 'status' => 'published', 'user_id' => auth()->id() ?? User::factory()->create()->id]);
    }

    private function document(string $text = 'Texto privado sin publicar'): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    public function test_duplicate_action_preserves_content_and_organization_without_publishing(): void
    {
        $admin = $this->admin();
        $category = Category::create(['name' => 'Linux', 'slug' => 'linux']);
        $tag = Tag::create(['name' => 'Terminal', 'slug' => 'terminal']);
        $original = $this->createPost(['category_ids' => [$category->id], 'featured' => true, 'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Texto original</p>']]]]);
        $original->tags()->attach($tag);
        Livewire::test(EditPost::class, ['record' => $original->id])->callAction('duplicate');
        $copy = Post::whereKeyNot($original->id)->sole();
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertFalse($copy->featured);
        $this->assertSame($admin->id, $copy->user_id);
        $this->assertSame($original->content, $copy->content);
        $this->assertSame([$category->id], $copy->category_ids);
        $this->assertSame([$tag->id], $copy->tags->modelKeys());
        $this->assertNotSame($original->url(), $copy->url());
        $this->assertSame('published', $original->fresh()->status);
        auth()->logout();
        $this->get($copy->url())->assertNotFound();
        $this->get($original->url())->assertOk()->assertDontSee('data-save=', false)->assertDontSee('Guardar entrada');
    }

    public function test_author_profile_and_optional_update_date_are_public_without_changing_ownership(): void
    {
        $admin = $this->admin();
        Storage::fake('media');
        $photo = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('autor.png'));
        Livewire::test(Profile::class)->fillForm(['avatar_id' => $photo->id, 'bio' => 'Escribo sobre Linux. <script>alert(1)</script>'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($photo->id, $admin->fresh()->avatar_id);
        $post = $this->createPost(['show_updated_at' => true]);
        $this->get($post->url())->assertOk()->assertSee('Escribo sobre Linux.')->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Actualizada el')->assertSee('variant=avatar');
        $this->travel(1)->days();
        $post->update(['featured' => true]);
        $this->assertNull($post->fresh()->content_updated_at);
        $post->update(['title' => 'Texto actualizado']);
        $this->get($post->url())->assertSee('Actualizada el');
        $post->update(['show_updated_at' => false]);
        $this->get($post->url())->assertDontSee('Actualizada el');
        auth()->logout();
        $this->get($photo->url('avatar'))->assertOk();
        $post->update(['status' => 'draft']);
        $this->get($photo->url('avatar'))->assertNotFound();
    }

    public function test_images_are_reduced_and_cached_without_modifying_the_original_or_bypassing_privacy(): void
    {
        Storage::fake('media');
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('portada.jpg', 1600, 1000));
        $original = Storage::disk('media')->get($media->path);
        $post = $this->createPost(['cover_id' => $media->id]);
        $this->get('/')->assertOk()->assertSee('variant=card');
        $response = $this->get($media->url('card'))->assertOk();
        $this->assertContains($response->headers->get('Content-Type'), ['image/webp', 'image/jpeg']);
        $dimensions = getimagesize($response->baseResponse->getFile()->getPathname());
        $this->assertSame(720, $dimensions[0]);
        $this->assertSame(450, $dimensions[1]);
        $this->assertLessThan(strlen($original), $response->baseResponse->getFile()->getSize());
        $this->assertSame($original, Storage::disk('media')->get($media->path));
        $this->get($media->url('card'), ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
        $this->get($media->url('card').'&download=1')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get($media->url().'?variant[]=card')->assertStatus(400);
        $post->update(['status' => 'draft']);
        $this->get($media->url('card'))->assertNotFound();
    }

    public function test_camera_photos_keep_their_orientation_in_reduced_images(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('La extensión exif no está disponible.');
        }
        Storage::fake('media');
        $photo = UploadedFile::fake()->image('camara.jpg', 1600, 800);
        $bytes = file_get_contents($photo->getPathname());
        // EXIF orientation 6: the camera was turned, so the photo must be rotated 90° clockwise.
        $exif = "Exif\0\0MM\0\x2A\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01\0\x06\0\0\0\0\0\0";
        $bytes = substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2);
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->createWithContent('camara.jpg', $bytes));
        $this->createPost(['cover_id' => $media->id]);
        $response = $this->get($media->url('card'))->assertOk();
        if (function_exists('imagewebp')) {
            $response->assertHeader('Content-Type', 'image/webp');
        }
        $dimensions = getimagesize($response->baseResponse->getFile()->getPathname());
        $this->assertSame([720, 1440], [$dimensions[0], $dimensions[1]]);
    }

    public function test_autosaved_work_is_private_encrypted_and_never_publishes_or_overwrites_another_tab(): void
    {
        $admin = $this->admin();
        $post = $this->createPost();
        $url = route('editor.draft', ['context' => 'post', 'record_id' => $post->id]);
        $state = $this->getJson($url)->assertOk()->json();
        $payload = ['context' => 'post', 'record_id' => $post->id, 'version' => 0, 'base_revision' => $state['base_revision'], 'document' => $this->document(), 'fields' => ['title' => 'Privado', 'status' => 'published', 'user_id' => 1234]];
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('version', 1);
        $this->assertSame('Mi entrada', $post->fresh()->title);
        $this->assertSame([], $post->fresh()->content);
        $this->assertStringNotContainsString('Texto privado', DB::table('editor_drafts')->value('payload'));
        $restored = $this->getJson($url)->assertOk()->assertJsonPath('version', 1)->json('draft.payload');
        $this->assertSame('Privado', $restored['fields']['title']);
        $this->assertArrayNotHasKey('user_id', $restored['fields']);
        $this->assertArrayNotHasKey('status', $restored['fields']);
        $this->assertStringContainsString('Texto privado', json_encode($restored));
        $this->putJson($url, $payload)->assertStatus(409);
        $this->deleteJson($url, array_replace($payload, ['version' => 0]))->assertStatus(409);
        $this->actingAs(User::factory()->create(['role' => 'editor']))->getJson($url)->assertJsonPath('draft', null);
        $this->actingAs($admin);
        $post->update(['title' => 'Modificado en otra pestaña']);
        $this->putJson($url, array_replace($payload, ['version' => 1]))->assertStatus(409);
        $this->getJson($url)->assertJsonPath('draft.outdated', true);
        Livewire::test(EditPost::class, ['record' => $post->id])->call('save')->assertHasNoFormErrors()->assertDispatched('editor-saved', context: 'post', recordId: $post->id);
        $this->assertSame(0, EditorDraft::count());
        auth()->logout();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_page_autosave_requires_admin_and_draft_files_remain_private(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $url = route('editor.draft', ['context' => 'page']);
        $this->getJson($url)->assertForbidden();
        $admin = $this->admin();
        Storage::fake('media');
        $media = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('privada.png'));
        $document = app(ContentDocument::class)->toDocument([['type' => 'image', 'data' => ['media_id' => $media->id]]]);
        $this->putJson($url, ['context' => 'page', 'document' => $document, 'fields' => ['title' => 'Borrador'], 'version' => 0])->assertOk();
        $this->assertFalse($media->isPublic());
        $this->assertTrue($media->isReferenced());
        $this->deleteJson($url, ['context' => 'page', 'version' => 1])->assertOk();
        $this->assertFalse($media->isReferenced());
        $this->assertSame(0, Page::where('title', 'Borrador')->count());
        $page = Page::factory()->create();
        Livewire::test(EditPage::class, ['record' => $page->id])->call('save')->assertHasNoFormErrors()->assertDispatched('editor-saved', context: 'page', recordId: $page->id);
    }

    public function test_long_drafts_keep_the_full_document_and_reject_oversized_payloads(): void
    {
        $this->admin();
        $text = str_repeat('Texto de trabajo. ', 4500);
        $payload = ['context' => 'post', 'version' => 0, 'document' => $this->document($text), 'fields' => []];
        $url = route('editor.draft', ['context' => 'post']);
        $this->putJson($url, $payload)->assertOk();
        $this->assertGreaterThan(65535, strlen(EditorDraft::sole()->getRawOriginal('payload')));
        $this->getJson($url)->assertJsonPath('draft.payload.document.content.0.content.0.text', trim($text));
        $this->putJson($url, array_replace($payload, ['version' => 1, 'document' => $this->document(str_repeat('x', 2000001))]))->assertStatus(413);
    }

    public function test_toolbar_exposes_paragraph_and_all_slash_blocks_and_notices_keep_their_tone(): void
    {
        $this->admin();
        $post = $this->createPost();
        $editor = Livewire::test(EditPost::class, ['record' => $post->id]);
        $editor->assertSee('data-rich-editor-tool="sq_paragraph"', false)->assertSee('data-draft-url=', false);
        foreach (array_keys(EditorBlocks::all()) as $block) {
            $editor->assertSee('data-rich-editor-tool="sq_'.$block.'"', false);
        }
        $editor->mountAction(TestAction::make('customBlock')->schemaComponent('content')->arguments(['id' => 'notice', 'mode' => 'insert', 'editorSelection' => ['type' => 'text', 'anchor' => 1, 'head' => 1]]))
            ->assertMountedActionModalSee('Color y tipo de aviso')->assertMountedActionModalSee('Azul · Información')->assertMountedActionModalSee('Lavanda · Nota')->assertMountedActionModalSee('Ámbar · Advertencia')
            ->unmountAction();
        $document = app(ContentDocument::class)->toDocument([['type' => 'notice', 'data' => ['tone' => 'warning', 'text' => 'Cuidado con este paso.']]]);
        $editor->fillForm(['content' => $document])->call('save')->assertHasNoFormErrors();
        $this->get($post->url())->assertSee('notice-warning')->assertSee('Cuidado con este paso.');
    }

    public function test_editor_list_shows_own_and_published_only(): void
    {
        $other = User::factory()->create(['role' => 'editor']);
        $hidden = Post::create(['title' => 'Borrador ajeno secreto', 'slug' => 'borrador-ajeno', 'content' => [], 'status' => 'draft', 'user_id' => $other->id]);
        $visible = Post::create(['title' => 'Publicada ajena', 'slug' => 'publicada-ajena', 'content' => [], 'status' => 'published', 'published_at' => now()->subMinute(), 'user_id' => $other->id]);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor);
        $own = $this->createPost(['status' => 'draft', 'user_id' => $editor->id]);
        Livewire::test(ListPosts::class)->assertCanSeeTableRecords([$own, $visible])->assertCanNotSeeTableRecords([$hidden]);
        $this->assertSame(0, PostResource::getGlobalSearchEloquentQuery()->whereKey($hidden->id)->count());
    }

    public function test_admin_can_force_delete_trashed_post(): void
    {
        $this->admin();
        $post = $this->createPost();
        $post->delete();
        Livewire::test(ListPosts::class)->filterTable('trashed', false)
            ->assertActionVisible(TestAction::make('forceDelete')->table($post))
            ->callAction(TestAction::make('forceDelete')->table($post))->assertHasNoErrors();
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor);
        $mine = $this->createPost(['slug' => 'mia', 'user_id' => $editor->id]);
        $mine->delete();
        Livewire::test(ListPosts::class)->filterTable('trashed', false)->assertActionHidden(TestAction::make('forceDelete')->table($mine));
    }

    public function test_restore_revision_fills_form_without_saving(): void
    {
        $this->admin();
        $tag = Tag::create(['name' => 'Antigua', 'slug' => 'antigua']);
        $post = $this->createPost(['title' => 'Título antiguo', 'published_at' => now()->subDay()]);
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['tags' => ['Antigua']])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['title' => 'Título nuevo', 'tags' => []])->call('save')->assertHasNoFormErrors();
        $old = $post->revisions()->get()[1];
        Livewire::test(EditPost::class, ['record' => $post->id])
            ->callAction('revision', data: ['revision_id' => $old->id])->assertHasNoErrors()
            ->assertSet('data.title', 'Título antiguo')->assertSet('data.tags', ['Antigua'])->assertSet('data.status', 'published');
        $this->assertSame('published', $post->fresh()->status);
        $this->assertSame('Título nuevo', $post->fresh()->title);
    }

    public function test_changing_only_tags_keeps_earlier_revision_tags(): void
    {
        $this->admin();
        $first = Tag::create(['name' => 'Primera', 'slug' => 'primera']);
        $second = Tag::create(['name' => 'Segunda', 'slug' => 'segunda']);
        $post = $this->createPost(['published_at' => now()->subDay()]);
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['title' => 'Con etiqueta', 'tags' => ['Primera']])->call('save')->assertHasNoFormErrors();
        $tagged = $post->revisions()->firstOrFail();
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['tags' => ['Segunda']])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$first->id], $tagged->fresh()->snapshot['tag_ids']);
        $this->assertSame([$second->id], $post->revisions()->firstOrFail()->snapshot['tag_ids']);
        $count = $post->revisions()->count();
        Livewire::test(EditPost::class, ['record' => $post->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($count, $post->revisions()->count());
    }

    public function test_restore_revision_with_deleted_media_warns(): void
    {
        Storage::fake('media');
        $this->admin();
        $image = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('vieja.png'));
        $post = $this->createPost(['cover_id' => $image->id, 'content' => [['type' => 'image', 'data' => ['media_id' => $image->id]], ['type' => 'paragraph', 'data' => ['html' => '<p>Texto</p>']]]]);
        $old = $post->revisions()->firstOrFail();
        $post->update(['cover_id' => null, 'content' => [['type' => 'paragraph', 'data' => ['html' => '<p>Texto</p>']]]]);
        $this->assertTrue($image->fresh()->delete());
        $restored = $post->fresh()->revisionFormData($old);
        $this->assertSame(2, $restored['missing_media']);
        $this->assertNull($restored['data']['cover_id']);
        $this->assertCount(1, $restored['data']['content']);
        Livewire::test(EditPost::class, ['record' => $post->id])
            ->callAction('revision', data: ['revision_id' => $old->id])->assertHasNoErrors()->assertNotified('Revisión cargada en el editor');
    }

    public function test_revisions_capped_at_twenty(): void
    {
        $this->admin();
        $post = $this->createPost();
        foreach (range(1, 25) as $number) {
            $post->update(['title' => 'Versión '.$number]);
        }
        $this->assertSame(20, $post->revisions()->count());
        $this->assertSame('Versión 25', $post->revisions()->first()->snapshot['title']);
    }

    public function test_preview_hidden_for_trashed_posts(): void
    {
        $this->admin();
        $post = $this->createPost();
        $post->delete();
        Livewire::test(ListPosts::class)->filterTable('trashed', false)->assertActionHidden(TestAction::make('preview')->table($post));
    }

    public function test_reading_minutes_auto_when_blank(): void
    {
        $this->admin();
        $text = trim(str_repeat('palabra ', 650));
        Livewire::test(CreatePost::class)->fillForm([
            'title' => 'Larga', 'slug' => 'larga', 'reading_minutes' => null,
            'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]],
        ])->call('create')->assertHasNoFormErrors();
        $this->assertSame(4, Post::where('slug', 'larga')->value('reading_minutes'));
        $post = Post::where('slug', 'larga')->firstOrFail();
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['reading_minutes' => 9])->call('save')->assertHasNoFormErrors();
        $this->assertSame(9, $post->fresh()->reading_minutes);
    }

    public function test_difficulty_optional_hidden_on_site(): void
    {
        $this->admin();
        $post = $this->createPost(['difficulty' => 'Avanzado', 'published_at' => now()->subMinute()]);
        $this->get($post->url())->assertSee('Avanzado');
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['difficulty' => '__none'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('', $post->fresh()->difficulty);
        $this->get($post->url())->assertOk()->assertDontSee('Avanzado')->assertDontSee('<span class="eyebrow"></span>', false);
        $this->get('/')->assertOk();
    }

    public function test_featured_is_chosen_from_the_home_and_stays_exclusive(): void
    {
        $this->admin();
        $first = $this->createPost(['featured' => true]);
        $second = $this->createPost(['slug' => 'segunda']);
        Livewire::test(EditPost::class, ['record' => $second->id])->assertFormFieldDoesNotExist('featured');
        $second->update(['featured' => true]);
        $this->assertTrue($second->fresh()->featured);
        $this->assertFalse($first->fresh()->featured);
    }

    public function test_admin_can_change_author(): void
    {
        $this->admin();
        $author = User::factory()->create(['role' => 'editor', 'name' => 'Otra autora']);
        $post = $this->createPost();
        Livewire::test(EditPost::class, ['record' => $post->id])->fillForm(['user_id' => $author->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($author->id, $post->fresh()->user_id);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor);
        $own = $this->createPost(['slug' => 'de-editor', 'user_id' => $editor->id]);
        Livewire::test(EditPost::class, ['record' => $own->id])->assertFormFieldHidden('user_id');
    }

    public function test_bulk_publish_and_archive(): void
    {
        $this->admin();
        $draft = $this->createPost(['status' => 'draft', 'published_at' => null]);
        $other = $this->createPost(['slug' => 'otra', 'status' => 'published', 'published_at' => now()->subDay()]);
        Livewire::test(ListPosts::class)->selectTableRecords([$draft->id])->callAction(TestAction::make('publish')->table()->bulk())->assertHasNoErrors();
        $this->assertSame('published', $draft->fresh()->status);
        $this->assertNotNull($draft->fresh()->published_at);
        Livewire::test(ListPosts::class)->selectTableRecords([$draft->id, $other->id])->callAction(TestAction::make('archive')->table()->bulk())->assertHasNoErrors();
        $this->assertSame('archived', $draft->fresh()->status);
        $this->assertSame('archived', $other->fresh()->status);
        $editor = User::factory()->create(['role' => 'editor']);
        $foreign = $this->createPost(['slug' => 'ajena', 'status' => 'published', 'published_at' => now()->subDay()]);
        $this->actingAs($editor);
        Livewire::test(ListPosts::class)->selectTableRecords([$foreign->id])->callAction(TestAction::make('archive')->table()->bulk());
        $this->assertSame('published', $foreign->fresh()->status);
    }

    public function test_scheduled_filter_and_new_columns(): void
    {
        $this->admin();
        $scheduled = $this->createPost(['slug' => 'futura', 'published_at' => now()->addDay()]);
        $published = $this->createPost(['slug' => 'pasada', 'published_at' => now()->subDay()]);
        Livewire::test(ListPosts::class)->assertTableColumnExists('author.name')->assertTableColumnExists('published_at')
            ->filterTable('scheduled')->assertCanSeeTableRecords([$scheduled])->assertCanNotSeeTableRecords([$published])
            ->assertSee('Programada para');
    }

    public function test_restoring_an_old_featured_revision_does_not_change_the_featured_post(): void
    {
        $this->admin();
        $post = $this->createPost(['featured' => true]);
        $old = $post->revisions()->firstOrFail();
        $other = $this->createPost(['slug' => 'nueva-destacada', 'featured' => true]);
        $this->assertFalse($post->fresh()->featured);
        $this->assertArrayNotHasKey('featured', $post->fresh()->revisionFormData($old)['data']);
        Livewire::test(EditPost::class, ['record' => $post->id])->callAction('revision', data: ['revision_id' => $old->id])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($other->fresh()->featured);
        $this->assertFalse($post->fresh()->featured);
    }
}
