<?php

namespace Tests\Feature;

use App\Filament\Resources\Media\Pages\CreateMedia;
use App\Filament\Resources\Media\Pages\EditMedia;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Media;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\MediaLibrary;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-media-library');
        config(['solqubit.installed_file' => $this->marker]);
        Setting::write('site', ['name' => 'Solqubit', 'timezone' => 'UTC']);
        Storage::fake('media');
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function createPost(array $attributes = []): Post
    {
        return Post::create($attributes + ['user_id' => $this->admin->id, 'title' => 'Entrada con imagen', 'slug' => 'con-imagen', 'content' => [], 'status' => 'published', 'published_at' => now()->subMinute()]);
    }

    public function test_media_used_by_trashed_post_cannot_be_deleted_and_says_why(): void
    {
        $image = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('portada.png'));
        $this->createPost(['cover_id' => $image->id])->delete();
        Livewire::test(ListMedia::class)->callAction(TestAction::make('delete')->table($image))
            ->assertNotified();
        $this->assertDatabaseHas('media', ['id' => $image->id]);
        $this->assertStringContainsString('papelera', collect($image->usages())->pluck('label')->implode(' '));
    }

    public function test_multiple_upload_creates_each_file(): void
    {
        Livewire::test(CreateMedia::class)->fillForm([
            'upload' => [UploadedFile::fake()->image('uno.png'), UploadedFile::fake()->image('dos.png'), UploadedFile::fake()->create('tres.pdf', 10, 'application/pdf')],
        ])->call('create')->assertHasNoFormErrors();
        $this->assertSame(3, Media::count());
        $this->assertEqualsCanonicalizing(['uno.png', 'dos.png', 'tres.pdf'], Media::pluck('original_name')->all());
    }

    public function test_multiple_upload_failing_midway_leaves_no_files(): void
    {
        Livewire::test(CreateMedia::class)->fillForm([
            'upload' => [UploadedFile::fake()->image('uno.png'), UploadedFile::fake()->create('rota.png', 10, 'image/png')],
        ])->call('create')->assertHasErrors(['data.upload']);
        $this->assertSame(0, Media::count());
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_edit_page_lists_usages(): void
    {
        $image = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('portada.png'));
        $this->createPost(['cover_id' => $image->id]);
        Livewire::test(EditMedia::class, ['record' => $image->id])->assertSee('Usado en')->assertSee('Entrada con imagen')->assertSee($image->url());
        $unused = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('libre.png'));
        Livewire::test(EditMedia::class, ['record' => $unused->id])->assertSee('No se usa en ningún lugar');
    }

    public function test_usages_hide_what_an_editor_cannot_open(): void
    {
        $other = User::factory()->create(['role' => 'editor']);
        $editor = User::factory()->create(['role' => 'editor']);
        $image = app(MediaLibrary::class)->upload(UploadedFile::fake()->image('compartida.png'));
        $image->forceFill(['user_id' => $editor->id])->save();
        Post::create(['user_id' => $other->id, 'title' => 'Borrador secreto de Ana', 'slug' => 'secreto', 'content' => [], 'status' => 'draft', 'cover_id' => $image->id]);
        Setting::write('seo', ['default_image_id' => $image->id]);
        $this->actingAs($editor);
        Livewire::test(EditMedia::class, ['record' => $image->id])->assertDontSee('Borrador secreto de Ana')->assertSee('Una entrada de otro autor')
            ->assertSee('Ajustes del sitio')->assertDontSee('/admin/ajustes/seo');
        $this->actingAs($this->admin);
        Livewire::test(EditMedia::class, ['record' => $image->id])->assertSee('Borrador secreto de Ana');
    }
}
