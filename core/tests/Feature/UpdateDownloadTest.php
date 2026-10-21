<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ZipUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsUpdatePackages;
use Tests\TestCase;

class UpdateDownloadTest extends TestCase
{
    use BuildsUpdatePackages;
    use RefreshDatabase;

    private const RELEASE = 'https://github.com/owner/repo/releases/download/v1.1.1/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareUpdateWorkspace();
        config(['solqubit.update_repository' => 'owner/repo', 'solqubit.allow_unverified' => false]);
    }

    protected function tearDown(): void
    {
        $this->removeUpdateWorkspace();
        parent::tearDown();
    }

    /** @param array<string, string> $headers */
    private function fakeRelease(string $zip, ?string $checksum = null, array $headers = []): void
    {
        Http::fake([
            'https://api.github.com/repos/owner/repo/releases/latest' => Http::response(['tag_name' => 'v1.1.1', 'html_url' => 'https://github.com/owner/repo/releases/tag/v1.1.1', 'published_at' => '2026-10-20T10:00:00Z', 'body' => '- Correcciones']),
            self::RELEASE.'solqubit-1.1.1-actualizacion.zip.sha256' => Http::response(($checksum ?? hash('sha256', $zip)).'  solqubit-1.1.1-actualizacion.zip'),
            self::RELEASE.'solqubit-1.1.1-actualizacion.zip' => Http::response($zip, 200, $headers),
        ]);
    }

    private function storedPackages(): array
    {
        return glob(app(ZipUpdater::class)->root().'/packages/*.zip') ?: [];
    }

    public function test_admin_downloads_validates_and_prepares_the_latest_release(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->fakeRelease(file_get_contents($this->package()));
        $this->post(route('update.download'), ['version' => '1.1.1'])->assertRedirect('/admin/actualizaciones')
            ->assertSessionHas('cms_update.origin', 'github')->assertSessionHas('cms_update.version', '1.1.1');
        $this->assertCount(1, $this->storedPackages());
        $this->assertFileDoesNotExist($this->work.'/core/app/Example.php');
    }

    public function test_download_with_wrong_checksum_is_discarded(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->fakeRelease(file_get_contents($this->package()), str_repeat('a', 64));
        $this->post(route('update.download'), ['version' => '1.1.1'])->assertSessionHasErrors(['package' => 'El ZIP descargado no coincide con su huella publicada.'])->assertSessionMissing('cms_update');
        $this->assertSame([], $this->storedPackages());
    }

    public function test_download_rejects_versions_that_are_not_the_newer_release(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->fakeRelease(file_get_contents($this->package()));
        $this->post(route('update.download'), ['version' => '9.9.9'])->assertSessionHasErrors(['package' => 'Busca actualizaciones de nuevo antes de descargar.']);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '.zip'));
    }

    public function test_oversized_download_is_aborted(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->fakeRelease('zip', null, ['Content-Length' => '134217729']);
        $this->post(route('update.download'), ['version' => '1.1.1'])->assertSessionHasErrors(['package' => 'La descarga supera el tamaño máximo de 128 MB.']);
        $this->assertSame([], $this->storedPackages());
    }

    public function test_unreachable_checksum_suggests_the_manual_upload(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Http::fake([
            'https://api.github.com/repos/owner/repo/releases/latest' => Http::response(['tag_name' => 'v1.1.1', 'html_url' => 'https://github.com/owner/repo/releases/tag/v1.1.1', 'body' => '']),
            self::RELEASE.'*' => Http::response('', 503),
        ]);
        $this->post(route('update.download'), ['version' => '1.1.1'])->assertSessionHasErrors(['package' => 'No se pudo contactar con GitHub. Inténtalo más tarde o sube el ZIP manualmente.']);
    }

    public function test_editors_cannot_download(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $this->post(route('update.download'), ['version' => '1.1.1'])->assertForbidden();
        Http::assertNothingSent();
    }
}
