<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\ContentSchema;
use App\Services\InstallationDatabase;
use App\Services\Installer;
use App\Services\Requirements;
use App\Services\WelcomeSample;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstallationExperienceTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/solqubit-install-test-'.bin2hex(random_bytes(8));
        config(['solqubit.installed_file' => $this->marker, 'solqubit.installer_demo_url' => null, 'solqubit.installation_database_host' => 'localhost']);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    public function test_new_site_opens_database_and_admin_configuration_in_light_mode(): void
    {
        $this->get('/')->assertRedirect('/install');
        $this->get('/install')->assertOk()->assertSee('data-theme="light"', false)
            ->assertSee('name="db_host"', false)->assertSee('name="db_password"', false)
            ->assertSee('name="email"', false)->assertSee('name="password_confirmation"', false)
            ->assertSee('name="db_host" value="localhost"', false)
            ->assertDontSee('name="install_token"', false)->assertDontSee('name="site_url"', false)
            ->assertSee('Un blog completamente vacío')->assertSee('Una entrada de muestra')
            ->assertSee('type="radio" name="starter_content" value="sample"', false)
            ->assertSee('type="radio" name="starter_content" value="empty"', false);
    }

    public function test_missing_requirements_are_identified_and_installation_is_disabled(): void
    {
        $this->mock(Requirements::class)->shouldReceive('check')->once()->andReturn([
            ['name' => 'PHP', 'ok' => true, 'detail' => '8.4'],
            ['name' => 'Extensión intl', 'ok' => false, 'detail' => 'Actívala en tu alojamiento'],
        ]);
        $this->get('/install')->assertOk()->assertSee('1 de 2 comprobaciones correctas')
            ->assertSee('Requiere atención')->assertSee('Actívala en tu alojamiento')
            ->assertSee('requirement-item is-error', false)->assertSee('requirement-item is-ok', false)
            ->assertSee('type="submit" disabled', false);
    }

    public function test_database_check_reports_success_without_creating_site_content(): void
    {
        $this->mock(InstallationDatabase::class)->shouldReceive('check')->once()
            ->withArgs(fn (array $data): bool => $data['db_database'] === 'new_blog' && ! array_key_exists('email', $data))
            ->andReturn(['resuming' => false]);
        $this->postJson('/install/check-database', $this->validInput())->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Conexión correcta. El servidor es compatible y la base está vacía.']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('posts', 0);
        $this->assertFileDoesNotExist($this->marker);
    }

    public function test_database_check_explains_a_pending_installation(): void
    {
        $this->mock(InstallationDatabase::class)->shouldReceive('check')->once()->andReturn(['resuming' => true]);
        $this->postJson('/install/check-database', $this->validInput())->assertOk()
            ->assertJson(['message' => 'Conexión correcta. Puedes reanudar la instalación con el mismo administrador.']);
    }

    public function test_database_check_validates_fields_before_connecting(): void
    {
        $this->mock(InstallationDatabase::class)->shouldNotReceive('check');
        $this->postJson('/install/check-database', ['db_host' => 'host;dbname=elsewhere', 'db_port' => 70000])
            ->assertUnprocessable()->assertJsonValidationErrors(['db_host', 'db_port', 'db_database', 'db_username', 'db_password']);
    }

    public function test_database_failure_is_reported_without_returning_the_password(): void
    {
        $this->mock(InstallationDatabase::class)->shouldReceive('check')->once()
            ->andThrow(ValidationException::withMessages(['db_host' => 'No se pudo conectar.']));
        $this->postJson('/install/check-database', $this->validInput())->assertUnprocessable()
            ->assertJsonValidationErrors(['db_host'])->assertDontSee('private-db-value');
    }

    public function test_database_check_cannot_run_after_installation(): void
    {
        file_put_contents($this->marker, '{}');
        $this->mock(InstallationDatabase::class)->shouldNotReceive('check');
        $this->postJson('/install/check-database', $this->validInput())->assertNotFound();
    }

    public function test_installed_site_refuses_another_installation_even_with_valid_new_admin_details(): void
    {
        file_put_contents($this->marker, '{}');
        $this->mock(Installer::class)->shouldNotReceive('install');
        $this->get('/install')->assertOk()->assertDontSee('name="db_password"', false);
        $this->post('/install', $this->validInput())->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $this->assertFileExists($this->marker);
    }

    public function test_default_seeding_does_not_create_a_demo_account_or_content(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('posts', 0);
    }

    private function validInput(string $content = 'empty'): array
    {
        return ['site_name' => 'Mi blog', 'timezone' => 'America/Guayaquil', 'db_host' => 'database', 'db_port' => 3306, 'db_database' => 'new_blog', 'db_username' => 'blog', 'db_password' => 'private-db-value', 'name' => 'Autor', 'username' => 'mi-autor', 'email' => 'author@example.test', 'password' => 'PrivatePassword123', 'password_confirmation' => 'PrivatePassword123', 'starter_content' => $content];
    }

    public static function initialContentChoices(): array
    {
        return [['empty'], ['sample']];
    }

    #[DataProvider('initialContentChoices')]
    public function test_installer_passes_the_selected_initial_content_to_the_installer(string $choice): void
    {
        $this->mock(Installer::class)->shouldReceive('install')->once()->withArgs(fn (array $data): bool => $data['starter_content'] === $choice);
        $this->post('/install', $this->validInput($choice))->assertRedirect('/admin/login');
    }

    public function test_docker_host_is_only_a_local_hint(): void
    {
        config(['solqubit.installation_database_host' => 'database']);
        $this->get('/install')->assertOk()->assertSee('name="db_host" value="database"', false)
            ->assertSee('En esta prueba local usa');
    }

    public static function installationLocations(): array
    {
        return [
            ['https://example.test', ''],
            ['http://127.0.0.1:8090', ''],
            ['https://example.test', '/blog'],
        ];
    }

    #[DataProvider('installationLocations')]
    public function test_installation_uses_the_current_address_and_ignores_a_submitted_url(string $origin, string $basePath): void
    {
        $this->mock(Installer::class)->shouldReceive('install')->once()->withArgs(
            fn (array $data): bool => $data['site_url'] === $origin.$basePath && ! array_key_exists('install_token', $data)
        );
        $input = $this->validInput() + ['site_url' => 'https://unrelated.invalid', 'install_token' => 'unnecessary'];
        $this->withServerVariables(['SCRIPT_NAME' => $basePath.'/index.php', 'SCRIPT_FILENAME' => '/var/www/site'.$basePath.'/index.php'])
            ->post($origin.$basePath.'/install', $input)->assertRedirect($origin.$basePath.'/admin/login');
    }

    public function test_installer_rejects_unknown_initial_content_without_flashing_secrets(): void
    {
        $this->mock(Installer::class)->shouldNotReceive('install');
        $this->from('/install')->post('/install', $this->validInput('old-demos'))->assertRedirect('/install')
            ->assertSessionHasErrors('starter_content')->assertSessionMissing('_old_input.db_password')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_single_sample_contains_every_block_and_serves_its_actual_media(): void
    {
        Storage::fake('media');
        $this->freezeTime();
        $admin = User::factory()->create(['role' => 'admin']);
        $post = app(WelcomeSample::class)->create($admin);
        $this->assertDatabaseCount('posts', 1);
        foreach (['categories', 'tags', 'series'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertEqualsCanonicalizing(ContentSchema::TYPES, array_values(array_unique(array_column($post->content, 'type'))));
        $this->assertDatabaseCount('media', 3);
        foreach ($post->media as $media) {
            Storage::disk('media')->assertExists($media->path);
        }
        file_put_contents($this->marker, '{}');
        Setting::write('site', ['name' => 'Mi blog', 'description' => 'Mi material', 'timezone' => 'UTC']);
        $this->get($post->url())->assertOk()->assertSee('¿Hay vida más allá de la Tierra?')
            ->assertSee('<video controls', false)->assertSee('data-copy-code', false)->assertSee('Descargar');
        $file = $post->media()->where('mime_type', 'text/plain')->firstOrFail();
        $this->get('/media/'.$file->uuid.'?download=1')->assertOk()->assertDownload('cuaderno-del-universo.txt');
        $video = $post->media()->where('mime_type', 'video/mp4')->firstOrFail();
        $this->get('/media/'.$video->uuid)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->get('/')->assertOk()->assertSee('data-theme="light"', false)->assertSee('class="theme-toggle"', false)->assertSee('Modo oscuro');
    }

    public function test_sample_command_preserves_new_or_edited_content(): void
    {
        Storage::fake('media');
        $admin = User::factory()->create(['role' => 'admin']);
        $post = Post::create(['user_id' => $admin->id, 'title' => 'Mi contenido', 'slug' => 'mi-contenido', 'content' => []]);
        $this->artisan('solqubit:sample', ['--replace-bundled' => true])->assertFailed();
        $this->assertDatabaseCount('posts', 1);
        $this->assertSame('Mi contenido', $post->fresh()->title);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_sample_replaces_only_the_original_bundled_examples(): void
    {
        Storage::fake('media');
        User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->seed(DemoSeeder::class);
        $this->assertDatabaseCount('posts', 7);
        $this->artisan('solqubit:sample', ['--replace-bundled' => true])->assertSuccessful();
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseHas('posts', ['slug' => 'entrada-de-muestra']);
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('tags', 0);
        $this->assertDatabaseCount('series', 0);
        $this->assertDatabaseCount('media', 3);
    }
}
