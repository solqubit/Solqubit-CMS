<?php

namespace Tests\Feature;

use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\PostResource;
use App\Forms\Editor\CodeBlock;
use App\Models\Media;
use App\Models\User;
use App\Services\ContentDocument;
use App\Services\ContentSchema;
use App\Services\EditorBlocks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditorBlocksTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-blocks-'.bin2hex(random_bytes(8));
        file_put_contents($this->marker, '{}');
        config(['solqubit.installed_file' => $this->marker]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> */
    private function samples(int $imageId, int $videoId, int $fileId): array
    {
        return [
            'code' => ['text' => 'ls -la'],
            'image' => ['media_id' => $imageId, 'caption' => 'Pie'],
            'video' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'caption' => 'Video'],
            'media_video' => ['media_id' => $videoId, 'caption' => 'Clip'],
            'file' => ['media_id' => $fileId, 'label' => 'Descarga'],
            'table' => ['columns' => ['A', 'B'], 'rows' => [['cells' => '1 | 2']]],
            'notice' => ['title' => 'Ojo', 'text' => 'Texto', 'tone' => 'warning'],
            'list' => ['title' => 'Lista', 'ordered' => false, 'items' => [['text' => 'Uno']]],
            'details' => ['title' => '¿Pregunta?', 'text' => 'Respuesta'],
            'links' => ['items' => [['label' => 'Laravel', 'url' => 'https://laravel.com']]],
        ];
    }

    public function test_every_block_declares_its_definition(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $media = fn (string $name, string $mime): Media => Media::create(['uuid' => (string) str()->uuid(), 'path' => $name, 'original_name' => $name, 'mime_type' => $mime, 'size' => 2048, 'user_id' => $user->id, 'width' => $mime === 'image/png' ? 10 : null, 'height' => $mime === 'image/png' ? 10 : null]);
        $image = $media('foto.png', 'image/png');
        $video = $media('clip.mp4', 'video/mp4');
        $file = $media('guia.pdf', 'application/pdf');
        $samples = $this->samples($image->id, $video->id, $file->id);

        $this->assertSame(array_keys($samples), array_keys(EditorBlocks::all()));
        foreach (EditorBlocks::all() as $type => $block) {
            $this->assertSame($type, $block::TYPE);
            $this->assertNotSame('', $block::label(), $type);
            $this->assertStringStartsWith('heroicon-', $block::icon(), $type);
            $this->assertNotSame('', $block::description(), $type);
            $this->assertNotEmpty($block::schema(), $type);
            $this->assertNotEmpty($block::rules(), $type);

            $validated = app(ContentSchema::class)->validate([['type' => $type, 'data' => $samples[$type]]])[0];
            $validated['anchor'] = 'muestra';
            $html = view('themes.solqubit.block', ['block' => $validated, 'mediaItems' => Media::all()->keyBy('id'), 'preview' => true])->render();
            $this->assertStringNotContainsString('Bloque no compatible', $html, $type);
            $this->assertNotSame('', trim($html), $type);
        }
    }

    public function test_code_block_only_has_its_code(): void
    {
        $names = array_map(fn ($component): string => $component->getName(), CodeBlock::schema());

        $this->assertSame(['text'], $names);
        $this->assertSame(['text' => ''], CodeBlock::defaults());
        $block = app(ContentSchema::class)->validate([['type' => 'code', 'data' => ['text' => 'ls -la', 'label' => 'Antigua etiqueta', 'language' => 'bash']]])[0];
        $html = view('themes.solqubit.block', ['block' => $block + ['anchor' => 'x'], 'mediaItems' => collect(), 'preview' => false])->render();
        $this->assertStringContainsString('data-copy-code', $html);
        $this->assertStringContainsString('ls -la', $html);
        $this->assertStringNotContainsString('Antigua etiqueta', $html);
        $this->assertStringNotContainsString('code-top', $html);
        $this->assertSame('ls', app(ContentSchema::class)->validate([['type' => 'code', 'data' => ['text' => 'ls', 'language' => 'bash']]])[0]['data']['text']);
    }

    public function test_terminal_output_becomes_a_code_block(): void
    {
        $stored = [['type' => 'output', 'data' => ['label' => 'Resultado', 'text' => 'total 0']]];

        $this->assertSame([['type' => 'code', 'data' => ['text' => 'total 0']]], app(ContentSchema::class)->validate($stored));
        $this->assertSame('code', app(ContentDocument::class)->toDocument($stored)['content'][0]['attrs']['id']);
        $html = view('themes.solqubit.block', ['block' => $stored[0] + ['anchor' => 'x'], 'mediaItems' => collect(), 'preview' => false])->render();
        $this->assertStringContainsString('code-block', $html);
        $this->assertStringNotContainsString('terminal-output', $html);
    }

    public function test_block_panel_groups_blocks_by_section(): void
    {
        $sections = EditorBlocks::inserter();

        $this->assertSame(['Texto', 'Multimedia', 'Destacados', 'Diseño'], array_keys($sections));
        $this->assertSame(['paragraph', 'h2', 'h3', 'bulletList', 'orderedList', 'blockquote'], array_column($sections['Texto'], 'command'));
        $this->assertSame(['sq_image', 'sq_video', 'sq_media_video', 'sq_file'], array_column($sections['Multimedia'], 'command'));
        $this->assertSame(['sq_notice', 'sq_list', 'sq_details', 'sq_links', 'sq_table', 'sq_code'], array_column($sections['Destacados'], 'command'));
        $this->assertSame(['horizontalRule'], array_column($sections['Diseño'], 'command'));
        foreach ($sections as $items) {
            foreach ($items as $item) {
                $this->assertNotSame('', $item['label']);
                $this->assertStringStartsWith('heroicon-', $item['icon']);
            }
        }
    }

    public function test_editor_shows_the_block_panel(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $this->get(PostResource::getUrl('create'))->assertOk()
            ->assertSee('id="sq-block-inserter"', false)->assertSee('data-command="sq_notice"', false)->assertSee('data-command="paragraph"', false)
            ->assertSeeInOrder(['Texto', 'Multimedia', 'Destacados', 'Diseño'])->assertSee('editor/block-inserter.js', false)
            ->assertDontSee('Salida de terminal');
    }

    public function test_editor_exposes_block_commands(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $html = Livewire::test(CreatePost::class)->html();

        $this->assertSame(1, preg_match('/data-commands="([^"]*)"/', $html, $match));
        $commands = json_decode(base64_decode($match[1]), true);
        $this->assertContains(['Lista destacada', 'Resumen informativo', 'sq_list'], $commands);
        $this->assertContains(['Párrafo', 'Texto normal', 'paragraph'], $commands);
    }
}
