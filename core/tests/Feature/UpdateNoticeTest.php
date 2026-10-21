<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemStatus;
use App\Filament\Widgets\UpdateAvailable;
use App\Models\User;
use App\Services\GitHubReleases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UpdateNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const LATEST_URL = 'https://api.github.com/repos/owner/repo/releases/latest';

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-test-'.bin2hex(random_bytes(8));
        file_put_contents($this->marker, '{}');
        config(['solqubit.installed_file' => $this->marker, 'solqubit.version' => '1.2.0', 'solqubit.update_repository' => 'owner/repo']);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function fakeLatest(string $tag = 'v1.3.0'): void
    {
        Http::fake([self::LATEST_URL => Http::response(['tag_name' => $tag, 'html_url' => 'https://github.com/owner/repo/releases/tag/'.$tag, 'published_at' => '2026-10-20T10:00:00Z', 'body' => "- Uno\n- **Dos**\n\n### Instalar\n\n- no"])]);
    }

    public function test_newer_release_is_detected_and_cached(): void
    {
        $this->fakeLatest();
        $releases = app(GitHubReleases::class);
        $latest = $releases->latest();
        $this->assertSame('ok', $latest['status']);
        $this->assertSame('1.3.0', $latest['version']);
        $this->assertSame(['Uno', 'Dos'], $latest['notes']);
        $this->assertTrue($releases->newer($latest));
        $releases->latest();
        Http::assertSentCount(1);
    }

    public function test_failures_are_cached_for_an_hour(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts): never {
            $attempts++;
            throw new ConnectionException('timeout');
        });
        $releases = app(GitHubReleases::class);
        $this->assertSame('error', $releases->latest()['status']);
        $this->travel(59)->minutes();
        $releases->latest();
        $this->assertSame(1, $attempts);
        $this->travel(2)->minutes();
        $releases->latest();
        $this->assertSame(2, $attempts);
    }

    #[TestWith(['v1.3.0-beta'])]
    #[TestWith(['latest'])]
    #[TestWith(['v1.2.0'])]
    public function test_tags_that_are_not_newer_versions_are_not_updates(string $tag): void
    {
        $this->fakeLatest($tag);
        $releases = app(GitHubReleases::class);
        $this->assertFalse($releases->newer($releases->latest()));
    }

    public function test_admin_sees_notice_and_badge_editor_does_not(): void
    {
        $this->fakeLatest();
        app(GitHubReleases::class)->latest();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertTrue(UpdateAvailable::canView());
        Livewire::withoutLazyLoading()->test(UpdateAvailable::class)->assertSee('Solqubit 1.3.0 está disponible')->assertSee('Uno');
        $this->assertSame('Nueva versión', SystemStatus::getNavigationBadge());
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $this->assertFalse(UpdateAvailable::canView());
    }

    public function test_notice_stays_hidden_when_the_site_is_up_to_date(): void
    {
        $this->fakeLatest('v1.2.0');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::withoutLazyLoading()->test(UpdateAvailable::class)->assertDontSee('está disponible');
    }

    public function test_dashboard_does_not_wait_for_github(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin')->assertOk();
        Http::assertNothingSent();
    }

    public function test_check_button_refreshes_the_cache(): void
    {
        $this->fakeLatest();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        app(GitHubReleases::class)->latest();
        $this->post(route('update.check'))->assertRedirect('/admin/actualizaciones');
        Http::assertSentCount(2);
        $this->get('/admin/actualizaciones')->assertSee('Solqubit 1.3.0')->assertSee('Buscar actualizaciones')
            ->assertSee('solqubit-1.3.0-actualizacion.zip')->assertSee('Descargar y validar 1.3.0')->assertDontSee('instalacion.zip');
    }

    public function test_editors_cannot_check_for_updates(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $this->post(route('update.check'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_navigation_badge_never_calls_github(): void
    {
        Http::fake();
        $this->assertNull(SystemStatus::getNavigationBadge());
        Http::assertNothingSent();
    }

    public function test_status_page_loads_when_github_fails_or_is_disabled(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->get('/admin/actualizaciones')->assertOk()->assertSee('No se pudo contactar con GitHub')->assertSee('Puedes subir el ZIP manualmente');
        config(['solqubit.update_repository' => '']);
        $this->get('/admin/actualizaciones')->assertOk()->assertSee('Este sitio no tiene un repositorio de actualizaciones configurado');
    }

    public function test_without_repository_nothing_is_requested(): void
    {
        Http::fake();
        config(['solqubit.update_repository' => '']);
        $this->assertSame('disabled', app(GitHubReleases::class)->latest()['status']);
        Http::assertNothingSent();
    }
}
