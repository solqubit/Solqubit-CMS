<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use App\Services\AdminAccess;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = tempnam(sys_get_temp_dir(), 'sq-user');
        config(['solqubit.installed_file' => $this->marker]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    #[TestWith(['autor@example.test'])]
    #[TestWith(['Mi.Autor'])]
    public function test_the_same_account_can_sign_in_using_email_or_username(string $identifier): void
    {
        $user = User::factory()->create(['role' => 'admin', 'username' => 'mi.autor', 'email' => 'autor@example.test', 'password' => 'Password12345']);
        Livewire::test(Login::class)->assertSee('Correo o usuario')->fillForm(['email' => $identifier, 'password' => 'Password12345'])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_username_and_email_share_the_same_lockout(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'username' => 'mi-autor', 'password' => 'Password12345']);
        $access = app(AdminAccess::class);
        $this->assertSame($access->key($user->email), $access->key('MI-AUTOR'));
        RateLimiter::increment($access->key($user->email), 900, 5);
        Livewire::test(Login::class)->fillForm(['email' => 'mi-autor', 'password' => 'Password12345'])
            ->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_an_inactive_account_cannot_sign_in_with_username(): void
    {
        User::factory()->create(['role' => 'admin', 'is_active' => false, 'username' => 'disabled', 'password' => 'Password12345']);
        Livewire::test(Login::class)->fillForm(['email' => 'disabled', 'password' => 'Password12345'])
            ->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_username_is_required_unique_and_separate_from_the_public_name(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'username' => 'ocupado']));
        $form = Livewire::test(CreateUser::class)->fillForm(['name' => 'Nombre público', 'email' => 'editor@example.test', 'password' => 'Password12345']);
        $form->call('create')->assertHasFormErrors(['username' => 'required']);
        $form->fillForm(['username' => 'ocupado'])->call('create')->assertHasFormErrors(['username' => 'unique']);
        $form->fillForm(['username' => 'correo@example.test'])->call('create')->assertHasFormErrors(['username' => 'regex']);
        $form->fillForm(['username' => 'Mi.Editor'])->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('users', ['username' => 'mi.editor', 'name' => 'Nombre público', 'email' => 'editor@example.test']);
    }
}
