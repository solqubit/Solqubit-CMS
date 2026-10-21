<?php

namespace Tests\Feature;

use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\TagsInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PostEditorLayoutTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-post-editor-'.bin2hex(random_bytes(8));
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

    private function createPost(array $attributes = []): Post
    {
        return Post::create($attributes + ['user_id' => $this->admin->id, 'title' => 'Entrada', 'slug' => 'entrada', 'status' => 'draft', 'content' => []]);
    }

    public function test_primary_action_label_matches_status(): void
    {
        $published = $this->createPost(['status' => 'published', 'published_at' => now()->subDay()]);
        $draft = $this->createPost(['slug' => 'borrador']);

        $this->assertSame('Guardar borrador', PostResource::primaryActionLabel('draft', null, null));
        $this->assertSame('Guardar borrador', PostResource::primaryActionLabel('archived', null, $published));
        $this->assertSame('Programar', PostResource::primaryActionLabel('published', now()->addDay()->toDateTimeString(), $draft));
        $this->assertSame('Actualizar', PostResource::primaryActionLabel('published', $published->published_at->toDateTimeString(), $published));
        $this->assertSame('Publicar', PostResource::primaryActionLabel('published', null, $draft));
        $this->assertSame('Publicar', PostResource::primaryActionLabel('published', null, null));
    }

    public function test_primary_button_knows_the_post_was_published_after_saving(): void
    {
        $draft = $this->createPost();

        Livewire::test(EditPost::class, ['record' => $draft->id])->assertSee('data-was-published="0"', false)
            ->fillForm(['status' => 'published'])->call('save')->assertHasNoFormErrors()
            ->assertSee('data-was-published="1"', false);
    }

    public function test_post_form_has_cover_and_excerpt_in_document_and_no_featured(): void
    {
        $html = Livewire::test(CreatePost::class)
            ->assertFormFieldExists('cover_id')->assertFormFieldExists('excerpt')->assertFormFieldDoesNotExist('featured')
            ->html();

        $document = substr($html, strpos($html, 'sq-writing-document'), strpos($html, 'sq-writing-settings') - strpos($html, 'sq-writing-document'));
        $this->assertStringContainsString('Imagen de cabecera', $document);
        $this->assertStringContainsString('Entradilla', $document);
        $this->assertStringContainsString('Aparece bajo el título y en las tarjetas del blog.', $document);
        $this->assertStringNotContainsString('Tarjeta de la entrada', $html);
        $this->assertStringNotContainsString('Destacar en portada', $html);
    }

    public function test_settings_sections_are_in_order(): void
    {
        $html = Livewire::test(EditPost::class, ['record' => $this->createPost(['status' => 'published', 'published_at' => now()->subDay()])->id])->html();
        $settings = substr($html, strpos($html, 'sq-writing-settings'));

        $this->assertMatchesRegularExpression('/Publicación.*Clasificación.*Lectura.*Dirección de la entrada.*Buscadores/s', $settings);
        $this->assertStringContainsString('Publicar el', $settings);
    }

    public function test_status_options_are_plain(): void
    {
        Livewire::test(CreatePost::class)
            ->assertFormFieldExists('status', fn ($field): bool => $field->getOptions() === ['draft' => 'Borrador', 'published' => 'Publicada', 'archived' => 'Archivada']);
    }

    public function test_toolbar_shows_insert_instead_of_every_block(): void
    {
        $html = Livewire::test(CreatePost::class)->html();

        $this->assertStringContainsString('data-rich-editor-tool="sq_insert"', $html);
        $this->assertStringContainsString('data-rich-editor-tool="sq_image"', $html);
        $this->assertStringContainsString('sq-hidden-tool', $html);
    }

    public function test_tags_are_typed_and_created_on_save(): void
    {
        Tag::create(['name' => 'Astronomía', 'slug' => 'astronomia']);

        Livewire::test(CreatePost::class)
            ->assertFormFieldExists('tags', fn (TagsInput $field): bool => in_array('Astronomía', $field->getSuggestions(), true))
            ->fillForm(['title' => 'Con etiquetas', 'slug' => 'con-etiquetas', 'tags' => ['astronomía', 'Vida en Marte', ' Vida en Marte ']])
            ->call('create')->assertHasNoFormErrors();

        $post = Post::where('slug', 'con-etiquetas')->firstOrFail();
        $this->assertEqualsCanonicalizing(['Astronomía', 'Vida en Marte'], $post->tags()->pluck('name')->all());
        $this->assertSame(2, Tag::count());
        $this->assertDatabaseHas('tags', ['name' => 'Vida en Marte', 'slug' => 'vida-en-marte']);
    }

    public function test_tags_field_shows_and_replaces_saved_names(): void
    {
        $post = $this->createPost();
        $post->tags()->sync([Tag::create(['name' => 'Ciencia', 'slug' => 'ciencia'])->id]);

        Livewire::test(EditPost::class, ['record' => $post->id])->assertSet('data.tags', ['Ciencia'])
            ->fillForm(['tags' => ['Espacio']])->call('save')->assertHasNoFormErrors();

        $this->assertSame(['Espacio'], $post->fresh()->tags()->pluck('name')->all());
    }

    public function test_post_list_has_thumbnail_and_no_featured_column(): void
    {
        $post = $this->createPost();

        Livewire::test(ListPosts::class)->assertTableColumnExists('cover')->assertTableColumnDoesNotExist('featured')
            ->assertSee(PostResource::getUrl('edit', ['record' => $post]), false);
    }
}
