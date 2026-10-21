<?php

namespace App\Filament\Auth;

use App\Services\AdminAccess;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use SensitiveParameter;

class Login extends \Filament\Auth\Pages\Login
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')->label('Correo o usuario')->required()->maxLength(255)
            ->autocomplete('username')->autofocus()->extraInputAttributes(['autocapitalize' => 'none', 'spellcheck' => 'false']);
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = mb_strtolower(trim($data['email']));

        return [str_contains($login, '@') ? 'email' : 'username' => $login, 'password' => $data['password']];
    }

    public function authenticate(): ?LoginResponse
    {
        $email = is_string($this->data['email'] ?? null) ? $this->data['email'] : '';
        $access = app(AdminAccess::class);
        abort_if($access->blocked(request()->ip()), 403);
        $access->ensureCanAttempt($email);
        $access->recordRequestLimit();
        $response = parent::authenticate();
        if ($response) {
            $access->succeeded($email);
        }

        return $response;
    }

    protected function getRateLimitKey($method, $component = null)
    {
        return $method === 'authenticate' ? app(AdminAccess::class)->requestKey() : parent::getRateLimitKey($method, $component);
    }

    protected function fireFailedEvent(Guard $guard, ?Authenticatable $user, #[SensitiveParameter] array $credentials): void
    {
        parent::fireFailedEvent($guard, $user, $credentials);
        app(AdminAccess::class)->failed((string) ($credentials['email'] ?? $credentials['username'] ?? ''));
    }
}
