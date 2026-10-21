<?php

namespace Tests\Feature;

use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use App\Services\ContentDocument;
use App\Services\WelcomeSample;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VisualEditorTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-editor');
        config(['solqubit.installed_file' => $this->marker]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function document(string $text = 'Texto nuevo sin publicar'): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Privacidad']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => $text]]],
        ]];
    }

    public function test_existing_content_and_all_media_survive_visual_editing(): void
    {
        Storage::fake('media');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $post = app(WelcomeSample::class)->create($admin);
        $post->update(['content' => [...$post->content, ['type' => 'paragraph', 'data' => ['html' => '<p>Un texto propio con <strong>negritas</strong>.</p>']]]]);
        $original = $post->content;
        $editor = Livewire::test(EditPost::class, ['record' => $post->id]);
        $editor->assertSee('solqubit-editor')->assertSee('sq_image')->assertSee('Vista previa');
        $editor->assertSee('sq-writing-document')->assertSee('sq-writing-settings')->assertSee('form="form"', false);
        $editor->call('save')->assertHasNoFormErrors();
        $saved = $post->fresh()->content;
        foreach ($original as $block) {
            if ($block['type'] !== 'paragraph') {
                $this->assertContainsEquals($block, $saved);
            }
        }
        $this->assertCount(3, $post->fresh()->media);
        $this->get($post->url())->assertOk()->assertSee('<strong>negritas</strong>', false)->assertSee('<video ', false);
        foreach ($post->media as $media) {
            Storage::disk('media')->assertExists($media->path);
            $this->get($media->url())->assertOk();
            if ($media->isVideo()) {
                $this->get($media->url(), ['Range' => 'bytes=0-99'])->assertStatus(206)->assertHeader('Content-Length', '100');
            }
        }
    }

    public function test_pages_save_visual_documents_as_public_content(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(CreatePage::class)->fillForm([
            'title' => 'Privacidad', 'slug' => 'privacidad', 'published' => true, 'in_menu' => true,
            'content' => $this->document(),
        ])->call('create')->assertHasNoFormErrors();
        $page = Page::where('slug', 'privacidad')->firstOrFail();
        $this->assertSame('heading', $page->content[0]['type']);
        $this->assertStringContainsString('<strong>', $page->content[1]['data']['html']);
        $this->get('/privacidad')->assertOk()->assertSee('Texto nuevo sin publicar');
    }

    public function test_slug_suggestions_follow_the_title_until_manually_edited(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(CreatePage::class)
            ->set('data.title', 'Política de privacidad')->assertSet('data.slug', 'politica-de-privacidad')
            ->assertSee(route('pages.show', 'politica-de-privacidad'))
            ->set('data.title', 'Privacidad del sitio')->assertSet('data.slug', 'privacidad-del-sitio')
            ->set('data.slug', 'privacidad')->set('data.title', 'Mi política')->assertSet('data.slug', 'privacidad')
            ->call('create')->assertHasNoFormErrors();
        $page = Page::where('slug', 'privacidad')->firstOrFail();
        Livewire::test(EditPage::class, ['record' => $page->id])->set('data.title', 'Otro nombre')->call('save')->assertHasNoFormErrors();
        $this->assertSame('privacidad', $page->fresh()->slug);
    }

    public function test_live_preview_renders_unsaved_content_without_modifying_the_page(): void
    {
        $page = Page::factory()->create(['title' => 'Original']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->postJson(route('editor.preview'), [
            'context' => 'page', 'record_id' => $page->id, 'fields' => ['title' => 'Nuevo título', 'settings' => []],
            'document' => $this->document(), 'mode' => 'dark',
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('Texto nuevo sin publicar', $response->json('html'));
        $this->assertStringContainsString('data-theme="dark"', $response->json('html'));
        $this->assertSame('Original', $page->fresh()->title);
        $this->assertSame([], $page->fresh()->content);
        $this->assertFalse($page->fresh()->published);
    }

    public function test_preview_authentication_and_page_permissions_are_enforced(): void
    {
        $payload = ['context' => 'page', 'fields' => [], 'document' => $this->document(), 'mode' => 'light'];
        $this->postJson(route('editor.preview'), $payload)->assertUnauthorized();
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->postJson(route('editor.preview'), $payload)->assertForbidden();
        $this->postJson(route('editor.preview'), array_replace($payload, ['context' => 'post']))->assertOk();
        $editor->update(['is_active' => false]);
        $this->postJson(route('editor.preview'), $payload)->assertForbidden();
    }

    public function test_preview_rejects_unknown_blocks_and_unsafe_links(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([
            ['type' => 'customBlock', 'attrs' => ['id' => 'unknown', 'config' => []]],
            ['type' => 'customBlock', 'attrs' => ['id' => 'links', 'config' => ['items' => [['label' => 'X', 'url' => 'javascript:alert(1)']]]]],
            ['type' => 'image', 'attrs' => ['src' => 'https://example.com/private.png']],
            ['type' => 'customBlock', 'attrs' => ['id' => 'notice', 'config' => 'invalid']],
            ['type' => 'customBlock', 'attrs' => ['id' => 'list', 'config' => ['items' => 'invalid']]],
            ['type' => 'customBlock', 'attrs' => ['id' => 'links', 'config' => ['items' => ['invalid']]]],
        ] as $node) {
            $this->postJson(route('editor.preview'), ['context' => 'page', 'fields' => [], 'document' => ['type' => 'doc', 'content' => [$node]], 'mode' => 'light'])->assertUnprocessable();
        }
    }

    public function test_rich_text_is_sanitized_before_storage(): void
    {
        $blocks = app(ContentDocument::class)->toBlocks(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '<script>unsafe</script>', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]],
        ]]);
        $html = $blocks[0]['data']['html'];
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_pasted_document_format_survives_saving_and_reopening(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $converter = app(ContentDocument::class);
        $document = $converter->toDocument([['type' => 'paragraph', 'data' => ['html' => '<h2>Explorar</h2><p>Con <strong>negrita</strong>, <em>cursiva</em> y <a href="https://example.com">enlace</a>.</p><blockquote><p>Una cita.</p></blockquote><ul><li>Uno<ul><li>Un detalle</li></ul></li></ul><ol start="3"><li>Tres</li></ol>']]]);
        Livewire::test(CreatePage::class)->fillForm([
            'title' => 'Documento con formato', 'slug' => 'documento', 'published' => true, 'content' => $document,
        ])->call('create')->assertHasNoFormErrors();
        $page = Page::where('slug', 'documento')->firstOrFail();
        Livewire::test(EditPage::class, ['record' => $page->id])->call('save')->assertHasNoFormErrors();
        $this->get('/documento')->assertOk()->assertSee('<strong>negrita</strong>', false)->assertSee('<em>cursiva</em>', false)
            ->assertSee('<blockquote>', false)->assertSee('<ul>', false)->assertSee('<ol start="3">', false)->assertSee('https://example.com');
        $this->assertSame('heading', $page->fresh()->content[0]['type']);
    }

    public function test_inline_tables_allow_empty_cells_and_round_trip_without_losing_rows(): void
    {
        $converter = app(ContentDocument::class);
        $table = ['type' => 'table', 'data' => ['columns' => ['Destino', ''], 'rows' => [['cells' => 'Europa | Océano'], ['cells' => ' | ']]]];
        $document = $converter->toDocument([$table]);
        $this->assertSame('table', $document['content'][0]['type']);
        $this->assertSame([$table], $converter->toBlocks($document));
        $this->assertSame([], $converter->toBlocks(['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2]],
            ['type' => 'customBlock', 'attrs' => ['id' => 'notice', 'config' => ['title' => '', 'text' => '', 'tone' => 'info']]],
            ['type' => 'customBlock', 'attrs' => ['id' => 'list', 'config' => ['items' => [['text' => '']]]]],
        ]]));
    }

    public function test_toolbar_inserts_a_configured_block_and_allows_uploading_an_image(): void
    {
        Storage::fake('media');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(CreatePage::class)->callAction(
            TestAction::make('customBlock')->schemaComponent('content')->arguments(['id' => 'code', 'mode' => 'insert', 'editorSelection' => ['type' => 'text', 'anchor' => 1, 'head' => 1]]),
            data: ['label' => 'Terminal', 'language' => 'bash', 'text' => 'pwd'],
        )->assertHasNoActionErrors()->assertDispatched('run-rich-editor-commands', function ($name, $parameters): bool {
            return $parameters['commands'][0]['arguments'][0]['attrs']['config']['text'] === 'pwd';
        });
        $component = Livewire::test(CreatePage::class)->callAction(
            TestAction::make('createOption')->schemaComponent('cover_id'),
            data: ['upload' => UploadedFile::fake()->image('nueva-portada.png'), 'alt_text' => 'Una portada'],
        )->assertHasNoActionErrors();
        $media = Media::where('original_name', 'nueva-portada.png')->firstOrFail();
        Storage::disk('media')->assertExists($media->path);
        $component->assertSet('data.cover_id', $media->id);
    }
}
