<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminAccess;
use App\Services\PostPermalinks;
use App\Services\UpdatePackage;
use App\Services\ZipUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\BuildsUpdatePackages;
use Tests\TestCase;

class ZipUpdateTest extends TestCase
{
    use BuildsUpdatePackages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareUpdateWorkspace();
    }

    protected function tearDown(): void
    {
        $this->removeUpdateWorkspace();
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        return $user;
    }

    public function test_accepts_new_public_folder_and_new_core_folder(): void
    {
        $admin = $this->admin();
        $package = $this->package(['site/nueva/prueba.js' => 'console.log(1)', 'core/app/Nueva/Clase.php' => '<?php // nueva', 'core/bootstrap/app.php' => '<?php // app']);
        $this->upload($package)->assertSessionHasNoErrors()->assertSessionHas('cms_update.version', '1.1.1');
        app(ZipUpdater::class)->apply(app(ZipUpdater::class)->root().'/packages/'.session('cms_update.id').'.zip', str_repeat('a', 64), $admin->id);
        $this->assertSame('console.log(1)', file_get_contents($this->work.'/site/nueva/prueba.js'));
        $this->assertSame('<?php // nueva', file_get_contents($this->work.'/core/app/Nueva/Clase.php'));
    }

    public function test_rejects_new_public_folder_named_like_a_site_address(): void
    {
        $admin = $this->admin();
        Page::create(['title' => 'Quiénes somos', 'slug' => 'quienes-somos']);
        $post = Post::create(['user_id' => $admin->id, 'title' => 'Vieja', 'slug' => 'vieja', 'content' => [], 'status' => 'draft']);
        DB::table('post_paths')->insert(['path' => 'antes/vieja', 'post_id' => $post->id]);
        DB::table('page_paths')->insert(['path' => 'contacto-viejo', 'page_id' => Page::firstOrFail()->id]);
        app(PostPermalinks::class)->configure([['label' => 'Guías', 'slug' => 'guias']], '');
        foreach ([AdminAccess::path(), 'quienes-somos', 'vieja', 'antes', 'contacto-viejo', 'guias'] as $folder) {
            $message = null;
            try {
                app(ZipUpdater::class)->apply($this->package(['site/'.$folder.'/x.js' => 'x']), str_repeat('a', 64), $admin->id);
            } catch (\RuntimeException $error) {
                $message = $error->getMessage();
            }
            $this->assertStringContainsString('site/'.$folder, (string) $message);
            $this->assertStringContainsString('dirección del sitio', (string) $message);
            $this->assertDirectoryDoesNotExist($this->work.'/site/'.$folder);
        }
    }

    #[TestWith(['core/.env'])]
    #[TestWith(['core/.htaccess'])]
    #[TestWith(['core/.env.example'])]
    #[TestWith(['core/storage/x'])]
    #[TestWith(['core/bootstrap/cache/config.php'])]
    #[TestWith(['core/bootstrap/public-path.php'])]
    #[TestWith(['core/bootstrap/update-guard.php'])]
    #[TestWith(['core/database/database.sqlite'])]
    #[TestWith(['site/index.php'])]
    #[TestWith(['site/.htaccess'])]
    #[TestWith(['site/.solqubit-path.php'])]
    #[TestWith(['site/install-core.php'])]
    #[TestWith(['site/js/.hidden/x.js'])]
    #[TestWith(['otra/x.php'])]
    public function test_rejects_protected_paths(string $path): void
    {
        $this->assertFalse(UpdatePackage::allowedPath($path));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('archivos no permitidos');
        app(UpdatePackage::class)->inspect($this->package([$path => 'unsafe']), '1.1.0');
    }

    public function test_rejects_site_path_inside_engine(): void
    {
        $admin = $this->admin();
        File::ensureDirectoryExists($this->work.'/site/solqubit-core/app');
        config(['solqubit.update_core' => $this->work.'/site/solqubit-core']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('motor');
        app(ZipUpdater::class)->apply($this->package(['site/solqubit-core/app/x.php' => 'bad']), str_repeat('a', 64), $admin->id);
    }

    public function test_install_zip_gets_clear_message(): void
    {
        $this->admin();
        $path = $this->work.'/instalacion.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('install-core.php', '<?php __halt_compiler();');
        $zip->addFromString('index.php', '<?php');
        $zip->close();
        $this->upload($path)->assertSessionHasErrors(['package' => 'Este es el ZIP de instalación. Para actualizar, usa el archivo solqubit-X.Y.Z-actualizacion.zip de la misma versión.']);
    }

    #[TestWith([1])]
    #[TestWith([2])]
    public function test_rejects_format_2_and_1(int $format): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('formato');
        app(UpdatePackage::class)->inspect($this->package(overrides: ['format' => $format]), '1.1.0');
    }

    #[TestWith([['time' => 30, 'memory' => 512 * 1024 ** 2, 'can_extend_time' => false], 'Pide a tu hosting al menos 60 segundos'])]
    #[TestWith([['time' => 30, 'memory' => 64 * 1024 ** 2, 'can_extend_time' => true], 'al menos 128 MB'])]
    public function test_low_time_or_memory_blocks_validation(array $limits, string $message): void
    {
        config(['solqubit.update_limits' => $limits]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        app(UpdatePackage::class)->inspect($this->package(), '1.1.0');
    }

    public function test_enough_time_and_memory_pass(): void
    {
        config(['solqubit.update_limits' => ['time' => 30, 'memory' => -1, 'can_extend_time' => true]]);
        $this->assertSame('1.1.1', app(UpdatePackage::class)->inspect($this->package(), '1.1.0')['version']);
    }

    private function upload(string $path)
    {
        return $this->post(route('update.upload'), ['package' => new UploadedFile($path, 'update.zip', 'application/zip', null, true)]);
    }

    public function test_admin_can_preview_a_zip_without_changing_files(): void
    {
        $this->admin();
        $this->upload($this->package())->assertRedirect('/admin/actualizaciones')->assertSessionHas('cms_update.version', '1.1.1');
        $this->assertFileDoesNotExist($this->work.'/core/app/Example.php');
        $this->get('/admin/actualizaciones')->assertOk()->assertSee('Aplicar actualización 1.1.1');
    }

    public function test_altered_files_are_rejected_by_their_hash(): void
    {
        $this->admin();
        $path = $this->package();
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString('core/app/Example.php', '<?php // bad');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        app(UpdatePackage::class)->inspect($path, '1.1.0');
    }

    #[TestWith(['1.1.0', '1.1.0'])]
    #[TestWith(['1.0.9', '1.0.0'])]
    #[TestWith(['2.0.0', '1.9.0'])]
    public function test_same_older_or_incompatible_versions_are_rejected(string $version, string $minimum): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('La versión debe ser más reciente');
        app(UpdatePackage::class)->inspect($this->package(overrides: ['version' => $version, 'min_version' => $minimum]), '1.1.0');
    }

    #[TestWith(['extra'])]
    #[TestWith(['symlink'])]
    #[TestWith(['duplicate'])]
    public function test_unlisted_files_symlinks_and_case_collisions_are_rejected(string $case): void
    {
        $this->admin();
        $path = $this->package();
        $zip = new \ZipArchive;
        $zip->open($path);
        if ($case === 'extra') {
            $zip->addFromString('unknown.txt', 'outside manifest');
        } elseif ($case === 'symlink') {
            $zip->setExternalAttributesName('core/app/Example.php', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        } else {
            $zip->addFromString('core/app/example.php', '<?php // new');
        }
        $zip->close();
        $this->upload($path)->assertSessionHasErrors('package')->assertSessionMissing('cms_update');
    }

    public function test_only_admins_with_a_validated_package_can_apply_updates(): void
    {
        $this->post(route('update.apply'))->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $this->post(route('update.upload'))->assertForbidden();
        $this->post(route('update.apply'))->assertForbidden();
        $this->admin();
        $this->post(route('update.apply'))->assertSessionHasErrors('package');
        $this->assertFileDoesNotExist(app(ZipUpdater::class)->root().'/transaction.json');
    }

    public function test_package_changed_after_preview_is_rejected(): void
    {
        $this->admin();
        $this->upload($this->package());
        $id = session('cms_update.id');
        file_put_contents(app(ZipUpdater::class)->root().'/packages/'.$id.'.zip', 'changed');
        $this->post(route('update.apply'))->assertSessionHasErrors('package')->assertSessionMissing('cms_update');
        $this->assertFileDoesNotExist(app(ZipUpdater::class)->root().'/transaction.json');
    }

    public function test_update_and_finalization_preserve_settings_users_and_private_files(): void
    {
        $admin = $this->admin();
        $updater = app(ZipUpdater::class);
        Setting::write('site', ['name' => 'Mi contenido']);
        file_put_contents($this->work.'/core/.env', 'secret');
        file_put_contents($this->work.'/core/storage/app/photo.png', 'photo');
        file_put_contents($this->work.'/core/app/Example.php', 'old');
        file_put_contents($this->work.'/site/editor/old.js', 'old asset');
        $package = $this->package(['core/app/Example.php' => 'new', 'site/editor/new.js' => 'asset'], ['remove' => ['site/editor/old.js']]);
        File::ensureDirectoryExists($updater->root().'/packages');
        $uploaded = $updater->root().'/packages/upload.zip';
        copy($package, $uploaded);
        $updater->apply($uploaded, str_repeat('a', 64), $admin->id);
        $this->assertSame('new', file_get_contents($this->work.'/core/app/Example.php'));
        $this->assertFileDoesNotExist($this->work.'/site/editor/old.js');
        $this->assertSame('migrating', json_decode(file_get_contents($updater->root().'/transaction.json'), true)['phase']);
        config(['solqubit.version' => '1.1.1']);
        Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('view:clear')->andReturn(0);
        $updater->finish();
        $this->assertSame('1.1.1', Setting::read('cms_version'));
        $this->assertSame(['name' => 'Mi contenido'], Setting::read('site'));
        $this->assertSame('secret', file_get_contents($this->work.'/core/.env'));
        $this->assertSame('photo', file_get_contents($this->work.'/core/storage/app/photo.png'));
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'email' => $admin->email]);
        $this->assertFileDoesNotExist($updater->root().'/transaction.json');
        $this->assertDirectoryDoesNotExist($updater->root().'/rollback');
        $this->assertDirectoryDoesNotExist($updater->root().'/stage');
        $this->assertDirectoryDoesNotExist($updater->root().'/packages');
    }

    public function test_interrupted_copy_recovers_previous_code_before_boot(): void
    {
        require_once base_path('bootstrap/update-guard.php');
        $updater = app(ZipUpdater::class);
        File::ensureDirectoryExists($updater->root().'/rollback/core/app');
        file_put_contents($updater->root().'/rollback/core/app/Example.php', 'original');
        file_put_contents($this->work.'/core/app/Example.php', 'partial update');
        file_put_contents($this->work.'/core/app/Added.php', 'new file');
        solqubit_update_write($updater->root().'/transaction.json', ['phase' => 'copying', 'written' => ['core/app/Example.php' => true, 'core/app/Added.php' => false]]);
        $result = solqubit_update_guard($this->work.'/core', $this->work.'/site');
        $this->assertFalse($result['finish']);
        $this->assertSame('original', file_get_contents($this->work.'/core/app/Example.php'));
        $this->assertFileDoesNotExist($this->work.'/core/app/Added.php');
        $this->assertFileDoesNotExist($updater->root().'/transaction.json');
    }

    public function test_failed_migration_stays_pending_and_does_not_report_success(): void
    {
        $admin = $this->admin();
        $updater = app(ZipUpdater::class);
        $updater->apply($this->package(), str_repeat('a', 64), $admin->id);
        config(['solqubit.version' => '1.1.1']);
        Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(1);
        try {
            $updater->finish();
            $this->fail('A failed migration must not finish the update.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('pendiente', $error->getMessage());
        }
        $this->assertSame('failed', json_decode(file_get_contents($updater->root().'/transaction.json'), true)['phase']);
        $this->assertNull(Setting::read('cms_version'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_migrations_cannot_be_changed(): void
    {
        $admin = $this->admin();
        File::ensureDirectoryExists($this->work.'/core/database/migrations');
        file_put_contents($this->work.'/core/database/migrations/old.php', 'original');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('modifica una migración existente');
        app(ZipUpdater::class)->apply($this->package(['core/database/migrations/old.php' => 'changed']), str_repeat('a', 64), $admin->id);
    }

    public function test_target_symlinks_cannot_escape_the_installation(): void
    {
        $admin = $this->admin();
        symlink($this->work.'/public.pem', $this->work.'/core/app/Example.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('enlaces simbólicos');
        app(ZipUpdater::class)->apply($this->package(), str_repeat('a', 64), $admin->id);
    }

    public function test_recovery_token_is_bound_to_the_transaction_and_expires(): void
    {
        require_once base_path('bootstrap/update-guard.php');
        $token = str_repeat('a', 64);
        $state = ['resume_hash' => hash('sha256', $token), 'resume_expires' => time() + 60];
        $this->assertTrue(solqubit_update_can_resume($state, $token));
        $this->assertFalse(solqubit_update_can_resume($state, str_repeat('b', 64)));
        $this->assertFalse(solqubit_update_can_resume($state, []));
        $state['resume_expires'] = time() - 1;
        $this->assertFalse(solqubit_update_can_resume($state, $token));
    }

    /** @param array<string, string> $files Installed files and their contents. */
    private function installedList(array $files, string $version = '1.1.0'): void
    {
        foreach ($files as $relative => $content) {
            File::ensureDirectoryExists(dirname($this->work.'/'.$relative));
            file_put_contents($this->work.'/'.$relative, $content);
        }
        File::ensureDirectoryExists($this->work.'/core/resources/solqubit');
        file_put_contents($this->work.'/core/resources/solqubit/files.json', json_encode(['version' => $version, 'files' => array_map(fn (string $content): string => hash('sha256', $content), $files)], JSON_THROW_ON_ERROR));
    }

    public function test_obsolete_files_from_skipped_versions_are_removed(): void
    {
        $admin = $this->admin();
        $migration = 'core/database/migrations/2020_01_01_000000_x.php';
        $this->installedList(['core/app/Old.php' => 'old', 'core/app/Kept.php' => 'kept', 'core/vendor/pkg/src/Gone.php' => 'gone', 'site/js/filament/old.js' => 'old', $migration => 'migration']);
        app(ZipUpdater::class)->apply($this->package(['core/app/Kept.php' => 'k', 'core/app/New.php' => 'n']), str_repeat('a', 64), $admin->id);
        $this->assertFileDoesNotExist($this->work.'/core/app/Old.php');
        $this->assertFileDoesNotExist($this->work.'/core/vendor/pkg/src/Gone.php');
        $this->assertFileDoesNotExist($this->work.'/site/js/filament/old.js');
        $this->assertFileExists($this->work.'/'.$migration);
        $this->assertSame('n', file_get_contents($this->work.'/core/app/New.php'));
        $this->assertDirectoryDoesNotExist($this->work.'/core/vendor/pkg');
        $this->assertDirectoryExists($this->work.'/core/vendor');
        $root = app(ZipUpdater::class)->root();
        $this->assertTrue(json_decode(file_get_contents($root.'/transaction.json'), true)['written']['core/app/Old.php']);
        $this->assertSame('old', file_get_contents($root.'/rollback/core/app/Old.php'));
    }

    #[TestWith(['1.0.9', 'core/app/Other.php'])]
    #[TestWith(['1.1.0', 'core/.env'])]
    public function test_installed_list_for_another_version_or_with_unsafe_paths_is_ignored(string $version, string $path): void
    {
        $admin = $this->admin();
        $this->installedList([$path => 'user', 'core/app/Old.php' => 'old'], $version);
        app(ZipUpdater::class)->apply($this->package(), str_repeat('a', 64), $admin->id);
        $this->assertFileExists($this->work.'/core/app/Old.php');
        $this->assertFileExists($this->work.'/'.$path);
    }

    public function test_a_former_file_can_become_a_directory(): void
    {
        $admin = $this->admin();
        $this->installedList(['core/app/Thing' => 'file']);
        app(ZipUpdater::class)->apply($this->package(['core/app/Thing/Inner.php' => 'inner']), str_repeat('a', 64), $admin->id);
        $this->assertSame('inner', file_get_contents($this->work.'/core/app/Thing/Inner.php'));
    }

    public function test_an_obsolete_path_that_is_now_a_directory_is_left_alone(): void
    {
        $admin = $this->admin();
        $this->installedList(['core/app/Folder/Inner.php' => 'inner']);
        app(ZipUpdater::class)->apply($this->package(['core/app/Folder/Inner.php' => 'new'], ['remove' => ['core/app/Folder']]), str_repeat('a', 64), $admin->id);
        $this->assertSame('new', file_get_contents($this->work.'/core/app/Folder/Inner.php'));
    }

    public function test_admin_can_check_installed_files_against_the_release_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $this->post(route('update.files'))->assertForbidden();
        $this->admin();
        $this->installedList(['core/app/A.php' => 'a', 'core/app/B.php' => 'b', 'core/app/C.php' => 'c']);
        file_put_contents($this->work.'/core/app/B.php', 'changed');
        unlink($this->work.'/core/app/C.php');
        file_put_contents($this->work.'/core/app/Extra.php', 'extra');
        $this->post(route('update.files'))->assertRedirect('/admin/actualizaciones')
            ->assertSessionHas('cms_files_check', ['modified' => ['core/app/B.php'], 'missing' => ['core/app/C.php'], 'extra' => ['core/app/Extra.php'], 'total' => 3]);
    }

    public function test_installation_without_a_file_list_says_so(): void
    {
        $this->admin();
        $this->post(route('update.files'))->assertSessionHas('cms_files_check', false);
        $this->followRedirects($this->post(route('update.files')))->assertSee('Esta instalación no tiene lista de archivos');
    }

    public function test_upload_records_the_origin_and_rejects_foreign_zips(): void
    {
        $this->admin();
        config(['solqubit.update_repository' => 'owner/repo', 'solqubit.allow_unverified' => false]);
        $official = $this->package();
        $url = 'https://github.com/owner/repo/releases/download/v1.1.1/solqubit-1.1.1-actualizacion.zip.sha256';
        Http::fake([$url => Http::response(hash_file('sha256', $official).'  solqubit-1.1.1-actualizacion.zip'), 'https://api.github.com/*' => Http::response([], 404)]);
        copy($official, $official.'.copy.zip');
        $this->upload($official)->assertSessionHas('cms_update.origin', 'github');
        $this->followRedirects($this->upload($official.'.copy.zip'))->assertSee('Paquete oficial de github.com/owner/repo');
        $this->upload($this->package(['core/app/Example.php' => '<?php // other']))->assertSessionHasErrors(['package' => 'Este ZIP no coincide con la versión 1.1.1 publicada en github.com/owner/repo. Descárgalo de nuevo desde la release oficial.']);
        $this->assertCount(2, glob(app(ZipUpdater::class)->root().'/packages/*.zip'));
    }

    public function test_finished_update_records_its_origin(): void
    {
        $admin = $this->admin();
        $updater = app(ZipUpdater::class);
        $updater->apply($this->package(), str_repeat('a', 64), $admin->id, 'github');
        config(['solqubit.version' => '1.1.1']);
        Artisan::shouldReceive('call')->andReturn(0);
        $updater->finish();
        $this->assertSame(['from' => '1.1.0', 'origin' => 'github'], AuditEvent::where('action', 'system.updated')->sole()->metadata);
    }

    public function test_preview_counts_obsolete_files_from_the_installed_list(): void
    {
        $this->admin();
        $this->installedList(['core/app/Old.php' => 'old', 'site/editor/old.js' => 'old', 'core/app/Example.php' => 'example']);
        $this->upload($this->package())->assertSessionHas('cms_update.remove', 2);
    }

    public function test_rejected_file_is_named_in_the_message(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('site/.htaccess');
        app(UpdatePackage::class)->inspect($this->package(['site/.htaccess' => 'Deny']), '1.1.0');
    }
}
