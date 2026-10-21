<?php

namespace Tests\Feature;

use App\Services\GitHubReleases;
use App\Services\PackageOrigin;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PackageOriginTest extends TestCase
{
    private const CHECKSUM_URL = 'https://github.com/owner/repo/releases/download/v1.3.0/solqubit-1.3.0-actualizacion.zip.sha256';

    private string $work;

    private string $zip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir().'/sq-origin-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->work);
        $this->zip = $this->work.'/package.zip';
        file_put_contents($this->zip, 'zip bytes');
        config(['solqubit.update_core' => $this->work, 'solqubit.update_repository' => 'owner/repo', 'solqubit.allow_unverified' => false]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        parent::tearDown();
    }

    private function verify(): string
    {
        return app(PackageOrigin::class)->verify($this->zip, '1.3.0');
    }

    public function test_zip_matching_the_release_checksum_is_official(): void
    {
        Http::fake([self::CHECKSUM_URL => Http::response(hash_file('sha256', $this->zip).'  solqubit-1.3.0-actualizacion.zip'."\n")]);
        $this->assertSame('github', $this->verify());
    }

    public function test_zip_not_matching_the_release_is_rejected(): void
    {
        Http::fake([self::CHECKSUM_URL => Http::response(str_repeat('a', 64).'  solqubit-1.3.0-actualizacion.zip')]);
        $this->expectExceptionMessage('Este ZIP no coincide con la versión 1.3.0 publicada en github.com/owner/repo.');
        $this->verify();
    }

    public function test_missing_release_is_rejected(): void
    {
        Http::fake([self::CHECKSUM_URL => Http::response('Not Found', 404)]);
        $this->expectExceptionMessage('no coincide con la versión 1.3.0');
        $this->verify();
    }

    public function test_unreachable_github_explains_the_server_option(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->expectExceptionMessage('No se pudo contactar con GitHub para comprobar el origen del ZIP.');
        $this->verify();
    }

    public function test_malformed_checksum_is_treated_as_a_mismatch(): void
    {
        Http::fake([self::CHECKSUM_URL => Http::response('<html>'.str_repeat('x', 2000).'</html>')]);
        $this->expectExceptionMessage('no coincide con la versión 1.3.0');
        $this->verify();
    }

    #[TestWith(['../x'])]
    #[TestWith(['https://evil.test/a'])]
    #[TestWith([''])]
    public function test_invalid_repository_never_calls_github(string $repository): void
    {
        Http::fake();
        config(['solqubit.update_repository' => $repository]);
        try {
            $this->verify();
            $this->fail('The package should have been rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('no tiene un repositorio de actualizaciones configurado', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_repository_comes_from_env_then_package_source_then_default(): void
    {
        $releases = app(GitHubReleases::class);
        config(['solqubit.update_repository' => null]);
        $this->assertSame('solqubit/Solqubit-CMS', $releases->repository());
        File::ensureDirectoryExists($this->work.'/resources/solqubit');
        file_put_contents($this->work.'/'.GitHubReleases::SOURCE, "<?php return ['repository' => 'fork/repo'];");
        $this->assertSame('fork/repo', $releases->repository());
        config(['solqubit.update_repository' => 'own/site']);
        $this->assertSame('own/site', $releases->repository());
        config(['solqubit.update_repository' => null]);
        file_put_contents($this->work.'/'.GitHubReleases::SOURCE, "<?php return ['repository' => ''];");
        $this->assertNull($releases->repository());
    }

    public function test_server_flag_allows_unverified_packages(): void
    {
        Http::fake([self::CHECKSUM_URL => Http::response(str_repeat('a', 64))]);
        config(['solqubit.allow_unverified' => true]);
        $this->assertSame('unverified', $this->verify());
    }
}
