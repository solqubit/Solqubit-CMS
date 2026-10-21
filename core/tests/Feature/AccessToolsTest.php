<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Pages\AccessSettings;
use App\Filament\Pages\MailSettings;
use App\Models\LoginBlock;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminAccess;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class AccessToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-access-tools');
        config(['solqubit.installed_file' => $this->marker]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'Password12345']);
        $this->actingAs($user);

        return $user;
    }

    private function smtp(array $overrides = []): array
    {
        return $overrides + ['enabled' => false, 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'sender', 'password' => 'NewSmtpSecret', 'from_address' => 'blog@example.test', 'from_name' => 'Mi blog'];
    }

    public function test_complete_mail_form_can_be_saved_and_reopened_without_exposing_the_password(): void
    {
        $this->admin();
        Mail::shouldReceive('build')->never();
        $data = ['mail' => $this->smtp(['enabled' => true]), 'smtp_test_recipient' => 'destino@example.test'];

        Livewire::test(MailSettings::class)->set('data', $data)->call('save')
            ->assertHasNoFormErrors()->assertNotified('Configuración de correo guardada')
            ->assertSet('data.mail.password', null);

        $saved = Setting::read('mail');
        $this->assertTrue($saved['enabled']);
        $this->assertSame('smtp.example.test', $saved['host']);
        $this->assertNotSame('NewSmtpSecret', $saved['password']);
        $this->assertSame('NewSmtpSecret', Crypt::decryptString($saved['password']));
        $this->assertArrayNotHasKey('smtp_test_recipient', $saved);

        $reopened = Livewire::test(MailSettings::class)->assertSet('data.mail.host', 'smtp.example.test')
            ->assertSet('data.mail.password', null);
        $data['mail']['password'] = '';
        $data['mail']['from_name'] = 'Mi nuevo nombre';
        $reopened->set('data', $data)->call('save')->assertHasNoFormErrors();
        $this->assertSame('Mi nuevo nombre', Setting::read('mail')['from_name']);
        $this->assertSame($saved['password'], Setting::read('mail')['password']);
    }

    #[TestWith(['data'])]
    #[TestWith(['data.mail'])]
    #[TestWith(['data.mail.host'])]
    #[TestWith(['data.smtp_test_recipient'])]
    public function test_editing_tested_mail_details_clears_the_previous_result(string $path): void
    {
        $this->admin();
        Mail::shouldReceive('build')->once()->andThrow(new TransportException('Connection timed out'));
        $component = Livewire::test(MailSettings::class)->fillForm(['mail' => $this->smtp()])
            ->call('testSmtp', 'destino@example.test')->assertSet('smtpTestResult.ok', false);
        $changed = ['mail' => $this->smtp(['host' => 'nuevo.example.test']), 'smtp_test_recipient' => 'otro@example.test'];

        $component->set($path, data_get(['data' => $changed], $path))
            ->assertHasNoErrors()->assertSet('smtpTestResult', null);
        $this->assertNull(Setting::read('mail'));
    }

    public function test_smtp_test_uses_unsaved_values_and_sends_to_the_chosen_recipient_without_saving_or_enabling_them(): void
    {
        $admin = $this->admin();
        $saved = $this->smtp(['host' => 'original.example.test', 'password' => Crypt::encryptString('OldSmtpSecret')]);
        Setting::write('mail', $saved);
        $originalMailer = config('mail.default');
        $mailer = Mail::build(['transport' => 'array']);
        Mail::shouldReceive('build')->once()->withArgs(fn (array $config): bool => $config['host'] === 'smtp.example.test' && $config['password'] === 'NewSmtpSecret' && $config['require_tls'] === true && $config['scheme'] === 'smtp')->andReturn($mailer);
        Livewire::test(MailSettings::class)->assertSet('data.smtp_test_recipient', $admin->email)->assertSee('Enviar correo de prueba')
            ->set('data', ['mail' => $this->smtp(), 'path' => 'ruta-sin-guardar', 'smtp_test_recipient' => 'destino@example.test'])
            ->callAction(TestAction::make('testSmtp')->schemaComponent('smtp-actions'))->assertHasNoErrors()->assertSet('smtpTestResult.ok', true);
        $message = $mailer->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('destino@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame('blog@example.test', $message->getFrom()[0]->getAddress());
        $this->assertSame('Prueba de correo de Solqubit', $message->getSubject());
        $this->assertStringContainsString('Esta prueba no guarda ni activa', $message->getTextBody());
        $this->assertSame($saved, Setting::read('mail'));
        $this->assertSame($originalMailer, config('mail.default'));
        $this->assertNotSame('ruta-sin-guardar', AdminAccess::path());
    }

    public function test_blank_test_password_reuses_the_saved_secret_and_tls_direct_uses_smtps(): void
    {
        $this->admin();
        Setting::write('mail', $this->smtp(['password' => Crypt::encryptString('StoredSmtpSecret')]));
        $mailer = Mail::build(['transport' => 'array']);
        Mail::shouldReceive('build')->once()->withArgs(fn (array $config): bool => $config['password'] === 'StoredSmtpSecret' && $config['scheme'] === 'smtps' && $config['port'] === 465)->andReturn($mailer);
        Livewire::test(MailSettings::class)->fillForm(['mail' => $this->smtp(['password' => '', 'encryption' => 'ssl', 'port' => 465])])->call('testSmtp', 'destino@example.test')->assertSet('smtpTestResult.ok', true)->assertSet('data.mail.password', '');
        $this->assertCount(1, $mailer->getSymfonyTransport()->messages());
    }

    public function test_smtp_validation_happens_before_connecting_and_does_not_validate_unrelated_panel_settings(): void
    {
        $this->admin();
        Mail::shouldReceive('build')->never();
        Livewire::test(MailSettings::class)->fillForm(['mail' => $this->smtp(['host' => 'https://smtp.example.test', 'port' => 70000])])
            ->call('testSmtp', 'not-an-email')->assertHasErrors(['data.mail.host', 'data.mail.port', 'data.smtp_test_recipient']);
        $this->assertNull(Setting::read('mail'));
    }

    #[TestWith(['535 Authentication failed: PASSWORD_SHOULD_NOT_LEAK', 'usuario o la contraseña'])]
    #[TestWith(['TLS certificate rejected PASSWORD_SHOULD_NOT_LEAK', 'conexión segura'])]
    #[TestWith(['Connection timed out PASSWORD_SHOULD_NOT_LEAK', 'No se pudo conectar'])]
    #[TestWith(['Server rejected sender PASSWORD_SHOULD_NOT_LEAK', 'Revisa los datos SMTP'])]
    public function test_smtp_failures_show_useful_messages_without_exposing_transport_details(string $error, string $hint): void
    {
        $this->admin();
        Mail::shouldReceive('build')->once()->andThrow(new TransportException($error));
        Livewire::test(MailSettings::class)->fillForm(['mail' => $this->smtp()])->call('testSmtp', 'destino@example.test')
            ->assertSet('smtpTestResult.ok', false)->assertSee($hint)->assertDontSee('PASSWORD_SHOULD_NOT_LEAK');
        $this->assertNull(Setting::read('mail'));
    }

    public function test_smtp_test_is_rate_limited_and_cannot_be_run_after_losing_admin_permissions(): void
    {
        $admin = $this->admin();
        Mail::shouldReceive('build')->never();
        RateLimiter::increment('smtp-test:'.$admin->id, 60, 3);
        $component = Livewire::test(MailSettings::class)->fillForm(['mail' => $this->smtp()])->call('testSmtp', 'destino@example.test')->assertSet('smtpTestResult.ok', false)->assertSee('Ya hiciste tres pruebas');
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $component->call('testSmtp', 'destino@example.test')->assertForbidden();
        Livewire::test(MailSettings::class)->assertForbidden();
    }

    public function test_failed_logins_appear_in_the_list_and_admin_unblocking_allows_immediate_login(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['role' => 'admin', 'password' => 'Password12345']);
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'WrongPassword123'])->call('authenticate');
        }
        $block = LoginBlock::sole();
        $this->assertSame('account', $block->scope);
        $this->assertSame(5, $block->attempts);
        $this->assertStringNotContainsString($user->email, DB::table('login_blocks')->value('email'));
        $this->actingAs($user);
        Livewire::test(AccessSettings::class)->assertSee($user->email)->assertSee('127.0.0.1')->assertSee('15 min')->call('unblock', $block->key)->assertSee('No hay bloqueos automáticos activos.');
        $this->assertSame(0, LoginBlock::count());
        auth()->logout();
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Password12345'])->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_ip_wide_blocks_expire_or_can_be_cleared_without_changing_manual_bans(): void
    {
        $this->freezeTime();
        $this->admin();
        Setting::write('access', ['blocked_ips' => ['203.0.113.9']]);
        for ($i = 0; $i < 30; $i++) {
            app(AdminAccess::class)->failed('cuenta'.$i.'@example.test');
        }
        $block = LoginBlock::where('scope', 'ip')->sole();
        Livewire::test(AccessSettings::class)->assertSee('Demasiados fallos desde esta IP')->call('unblock', $block->key);
        $this->assertFalse(RateLimiter::tooManyAttempts(app(AdminAccess::class)->ipKey(), 30));
        $this->assertSame(['203.0.113.9'], Setting::read('access')['blocked_ips']);
        for ($i = 0; $i < 5; $i++) {
            app(AdminAccess::class)->failed('caduca@example.test');
        }
        $this->travel(16)->minutes();
        Livewire::test(AccessSettings::class)->assertSee('No hay bloqueos automáticos activos.')->assertDontSee('caduca@example.test');
        $this->assertSame(0, LoginBlock::count());
    }

    public function test_short_request_pause_is_visible_and_editors_cannot_remove_blocks(): void
    {
        for ($i = 0; $i < 6; $i++) {
            Livewire::test(Login::class)->fillForm(['email' => 'cuenta'.$i.'@example.test', 'password' => 'WrongPassword123'])->call('authenticate');
        }
        $block = LoginBlock::where('scope', 'requests')->sole();
        $this->admin();
        $component = Livewire::test(AccessSettings::class)->assertSee('Demasiadas solicitudes de acceso');
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        $component->call('unblock', $block->key)->assertForbidden();
        $this->assertNotNull($block->fresh());
        $this->assertTrue(RateLimiter::tooManyAttempts($block->key, 5));
    }
}
