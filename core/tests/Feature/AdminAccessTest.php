<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Auth\Profile;
use App\Filament\Auth\RequestPasswordReset;
use App\Filament\Pages\AccessSettings;
use App\Filament\Pages\MailSettings;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminAccess;
use App\Services\AdminMail;
use App\Services\PostPermalinks;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $accessFile;

    private string $marker;

    protected function setUp(): void
    {
        $this->accessFile = tempnam(sys_get_temp_dir(), 'sq-access');
        file_put_contents($this->accessFile, json_encode(['path' => str_contains($this->name(), 'custom_path') ? 'control-laboratorio' : 'admin']));
        $_ENV['SOLQUBIT_ADMIN_ACCESS_FILE'] = $_SERVER['SOLQUBIT_ADMIN_ACCESS_FILE'] = $this->accessFile;
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-access-installed');
        config(['solqubit.installed_file' => $this->marker, 'mail.default' => 'log']);
        Setting::write('site', ['name' => 'Solqubit']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->accessFile);
        @unlink($this->marker);
        unset($_ENV['SOLQUBIT_ADMIN_ACCESS_FILE'], $_SERVER['SOLQUBIT_ADMIN_ACCESS_FILE']);
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'password' => 'Password12345']);
    }

    public function test_custom_path_moves_login_panel_and_editor_endpoints_without_an_old_alias(): void
    {
        $this->get('/control-laboratorio/login')->assertOk();
        $this->get('/admin/login')->assertNotFound();
        $this->get('/admin')->assertNotFound();
        $this->assertSame(url('/control-laboratorio/editor-preview'), route('editor.preview'));
        $this->assertSame(url('/control-laboratorio/editor-draft'), route('editor.draft'));
        $this->get('/')->assertDontSee('control-laboratorio');
        $this->actingAs($this->admin())->get('/control-laboratorio/ajustes/seguridad')->assertOk()->assertSee('Seguridad');
        $this->get('/')->assertSee(url('/control-laboratorio'));
        $this->expectException(ValidationException::class);
        app(PostPermalinks::class)->configure([['label' => 'Reservado', 'slug' => 'control-laboratorio']], '');
    }

    public function test_admin_can_change_the_path_and_editor_cannot_access_security_settings(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(AccessSettings::class)->fillForm(['path' => 'mi-panel'])->call('save')->assertHasNoFormErrors()->assertRedirect(url('/mi-panel/ajustes/seguridad'));
        $this->assertSame('mi-panel', json_decode(file_get_contents($this->accessFile), true)['path']);
        $this->actingAs(User::factory()->create(['role' => 'editor']))->get('/admin/ajustes/seguridad')->assertForbidden();
        $this->get('/')->assertDontSee('>Administración</a>', false);
    }

    public function test_reserved_or_occupied_paths_and_self_blocking_are_rejected(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(AccessSettings::class)->fillForm(['path' => 'media'])->call('save')->assertHasFormErrors(['path']);
        app(PostPermalinks::class)->configure([['label' => 'Blog', 'slug' => 'blog']], '');
        Livewire::test(AccessSettings::class)->fillForm(['path' => 'blog'])->call('save')->assertHasFormErrors(['path']);
        Livewire::test(AccessSettings::class)->fillForm(['blocked_ips' => '127.0.0.1'])->call('save')->assertHasFormErrors(['blocked_ips']);
        $this->assertSame('admin', AdminAccess::path());
    }

    public function test_admin_path_cannot_replace_a_custom_page(): void
    {
        Page::factory()->create(['slug' => 'contacto']);
        $this->actingAs($this->admin());
        Livewire::test(AccessSettings::class)->fillForm(['path' => 'contacto'])->call('save')->assertHasFormErrors(['path']);
        $this->assertSame('admin', AdminAccess::path());
    }

    public function test_ip_block_applies_to_admin_and_livewire_without_blocking_public_content(): void
    {
        Setting::write('access', ['blocked_ips' => ['127.0.0.1']]);
        $this->get('/admin/login')->assertForbidden();
        $this->get('/admin/password-reset/request')->assertForbidden();
        $this->postJson('/livewire/update', [])->assertForbidden();
        $this->postJson(Livewire::getUpdateUri(), [])->assertForbidden();
        $this->postJson(route('livewire.upload-file'), [])->assertForbidden();
        $this->get('/')->assertOk();
    }

    public function test_failed_logins_have_a_fifteen_minute_lock_and_success_is_allowed_after_expiry(): void
    {
        $user = $this->admin();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Incorrect123'])->call('authenticate')->assertHasFormErrors(['email']);
        }
        $this->travel(61)->seconds();
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Password12345'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
        $this->travel(16)->minutes();
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Password12345'])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_ip_limit_blocks_attempts_even_when_the_email_changes(): void
    {
        $user = $this->admin();
        RateLimiter::increment(app(AdminAccess::class)->ipKey(), 900, 30);
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Password12345'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_smtp_password_is_encrypted_and_never_hydrated_back_into_the_form(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(MailSettings::class)->fillForm(['mail' => [
            'enabled' => true, 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls',
            'username' => 'sender', 'password' => 'PrivateSmtpPassword123', 'from_address' => 'blog@example.test', 'from_name' => 'Blog',
        ]])->call('save')->assertHasNoFormErrors()->assertSet('data.mail.password', null);
        $this->assertStringNotContainsString('PrivateSmtpPassword123', DB::table('settings')->where('key', 'mail')->value('value'));
        $this->assertSame('PrivateSmtpPassword123', Crypt::decryptString(Setting::read('mail')['password']));
        Livewire::test(MailSettings::class)->assertSet('data.mail.password', null)->call('save')->assertHasNoFormErrors();
        $this->assertTrue(app(AdminMail::class)->configure());
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertTrue(config('mail.mailers.smtp.require_tls'));
    }

    public function test_password_reset_is_available_and_uses_a_non_disclosing_response(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $user = $this->admin();
        $this->get('/admin/login')->assertOk()->assertSee('password-reset/request');
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $user->email])->call('request')->assertNotified('Solicitud recibida');
        Notification::assertSentTo($user, ResetPassword::class, fn (ResetPassword $notification): bool => str_contains($notification->url, '/admin/password-reset/reset'));
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'inexistente@example.test'])->call('request')->assertNotified('Solicitud recibida');
        Notification::assertCount(1);
    }

    public function test_two_factor_secrets_are_private_and_password_alone_does_not_log_in(): void
    {
        $user = $this->admin();
        $provider = AppAuthentication::make()->recoverable();
        $secret = $provider->generateSecret();
        $user->saveAppAuthenticationSecret($secret);
        $codes = $provider->generateRecoveryCodes();
        $provider->saveRecoveryCodes($user, $codes);
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('app_authentication_secret'));
        $this->assertArrayNotHasKey('app_authentication_secret', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $user->fresh()->toArray());
        $login = Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Password12345'])->call('authenticate');
        $this->assertGuest();
        $login->assertNotSet('userUndertakingMultiFactorAuthentication', null);
        $login->set('data.multiFactor.app.code', $provider->getCurrentCode($user))->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($provider->verifyRecoveryCode($codes[0], $user));
        $this->assertFalse($provider->verifyRecoveryCode($codes[0], $user->fresh()));
        Livewire::test(Profile::class)->assertSee('Foto del autor');
    }
}
