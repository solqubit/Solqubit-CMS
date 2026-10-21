<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\LoginBlock;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\IpUtils;

class AdminAccess
{
    public static function isProtectedRequest(Request $request): bool
    {
        $path = self::path();
        $livewirePath = trim(Livewire::getUriPrefix(), '/');

        return $request->is($path, $path.'/*', 'preview/*', 'livewire/*', $livewirePath.'/*');
    }

    public static function path(): string
    {
        $override = config('solqubit.admin_path_override');
        $file = config('solqubit.admin_access_file');
        $data = $file && is_file($file) ? json_decode(file_get_contents($file), true) : [];
        $path = $override ?: ($data['path'] ?? 'admin');

        return is_string($path) && preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $path) ? $path : 'admin';
    }

    public function changePath(string $path): void
    {
        Validator::make(['path' => $path], ['path' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', Rule::notIn(array_diff(PostPermalinks::RESERVED, ['admin']))]])->validate();
        if ($path !== self::path() && (Page::withTrashed()->where('kind', 'custom')->where('slug', $path)->exists() || DB::table('post_paths')->where('path', $path)->orWhere('path', 'like', $path.'/%')->exists() || in_array($path, array_column(app(PostPermalinks::class)->configured(), 'slug'), true) || is_dir(public_path($path)) || is_file(public_path($path)))) {
            throw ValidationException::withMessages(['path' => 'Esta dirección ya se utiliza. Elige otra para el administrador.']);
        }
        if (config('solqubit.admin_path_override')) {
            throw ValidationException::withMessages(['path' => 'El servidor fija esta dirección mediante SOLQUBIT_ADMIN_PATH.']);
        }
        if (Artisan::call('route:clear') !== 0) {
            throw ValidationException::withMessages(['path' => 'No se pudo actualizar la caché de rutas. No se cambió la dirección.']);
        }
        File::replace(config('solqubit.admin_access_file'), json_encode(['path' => $path], JSON_THROW_ON_ERROR));
    }

    /**
     * Whether administrators must enable two-step verification before using the panel.
     */
    public function requiresAdminTwoFactor(): bool
    {
        if (! is_file(config('solqubit.installed_file'))) {
            return false;
        }

        return (bool) (Setting::read('access', [])['require_admin_two_factor'] ?? false);
    }

    public function blocked(string $ip): bool
    {
        if (! is_file(config('solqubit.installed_file'))) {
            return false;
        }

        return IpUtils::checkIp($ip, Setting::read('access', [])['blocked_ips'] ?? []);
    }

    /**
     * Failed passwords allowed for one login from one IP before a temporary lock.
     */
    public const ACCOUNT_IP_LIMIT = 5;

    /**
     * Failed passwords allowed for one login from any IP before a temporary lock.
     */
    public const ACCOUNT_LIMIT = 10;

    /**
     * Failed passwords allowed from one IP, whatever the login, before a temporary lock.
     */
    public const IP_LIMIT = 30;

    /**
     * Seconds a failed password counts towards a lock.
     */
    public const DECAY_SECONDS = 900;

    public function key(string $email, ?string $ip = null): string
    {
        return 'admin-login:'.hash('sha256', ($ip ?? request()->ip()).'|'.$this->normalizeLogin($email));
    }

    /**
     * Rate limiter key shared by every IP that tries the same account.
     */
    public function accountKey(string $email): string
    {
        return 'admin-login-account:'.hash('sha256', $this->normalizeLogin($email));
    }

    public function ipKey(?string $ip = null): string
    {
        return 'admin-login-ip:'.hash('sha256', $ip ?? request()->ip());
    }

    public function requestKey(?string $ip = null): string
    {
        return 'admin-login-requests:'.hash('sha256', $ip ?? request()->ip());
    }

    public function ensureCanAttempt(string $email): void
    {
        foreach ($this->limits($email) as $key => [$scope, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $this->rememberBlock($key, $scope, $scope === 'account' ? $email : null);
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
                $message = $key === $this->accountKey($email)
                    ? "Esta cuenta acumula demasiados intentos fallidos. Por seguridad, el acceso queda en pausa durante {$minutes} minutos."
                    : "Demasiados intentos. Vuelve a intentarlo en {$minutes} minutos.";
                throw ValidationException::withMessages(['data.email' => $message]);
            }
        }
    }

    public function failed(string $email): void
    {
        foreach ($this->limits($email) as $key => [$scope, $limit]) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $this->rememberBlock($key, $scope, $scope === 'account' ? $email : null);
            }
        }
    }

    public function recordRequestLimit(): void
    {
        if (RateLimiter::tooManyAttempts($this->requestKey(), 5)) {
            $this->rememberBlock($this->requestKey(), 'requests');
        }
    }

    public function succeeded(string $email): void
    {
        RateLimiter::clear($this->key($email));
        RateLimiter::clear($this->accountKey($email));
        LoginBlock::whereKey([$this->key($email), $this->accountKey($email)])->delete();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    private function limits(string $email): array
    {
        return [
            $this->key($email) => ['account', self::ACCOUNT_IP_LIMIT],
            $this->accountKey($email) => ['account', self::ACCOUNT_LIMIT],
            $this->ipKey() => ['ip', self::IP_LIMIT],
        ];
    }

    private function normalizeLogin(string $email): string
    {
        $login = mb_strtolower(trim($email));
        if (! str_contains($login, '@')) {
            $login = User::where('username', $login)->value('email') ?? $login;
        }

        return mb_strtolower($login);
    }

    private function limitFor(LoginBlock $block): int
    {
        return match (true) {
            $block->scope === 'ip' => self::IP_LIMIT,
            $block->scope === 'requests' => 5,
            str_starts_with($block->key, 'admin-login-account:') => self::ACCOUNT_LIMIT,
            default => self::ACCOUNT_IP_LIMIT,
        };
    }

    private function rememberBlock(string $key, string $scope, ?string $email = null): void
    {
        LoginBlock::where('expires_at', '<=', now())->delete();
        LoginBlock::updateOrCreate(['key' => $key], [
            'ip' => request()->ip(), 'scope' => $scope,
            'email' => $email !== null ? mb_substr(mb_strtolower(trim($email)), 0, 320) : null,
            'attempts' => RateLimiter::attempts($key),
            'expires_at' => now()->addSeconds(RateLimiter::availableIn($key)),
        ]);
    }

    public function activeBlocks(): Collection
    {
        LoginBlock::where('expires_at', '<=', now())->delete();

        return LoginBlock::orderByDesc('expires_at')->limit(100)->get()->filter(function (LoginBlock $block): bool {
            if (! RateLimiter::tooManyAttempts($block->key, $this->limitFor($block))) {
                $block->delete();

                return false;
            }

            return true;
        });
    }

    public function unblock(string $key): void
    {
        $block = LoginBlock::find($key);
        if (! $block) {
            return;
        }
        $keys = [$key, $this->requestKey($block->ip)];
        if ($block->scope === 'account' && filled($block->email)) {
            $keys = [...$keys, $this->key($block->email, $block->ip), $this->accountKey($block->email)];
        }
        if ($block->scope === 'ip') {
            $keys = [...$keys, ...LoginBlock::where('ip', $block->ip)->pluck('key')->all()];
        }
        foreach (array_unique($keys) as $limitedKey) {
            RateLimiter::clear($limitedKey);
        }
        LoginBlock::whereIn('key', $keys)->delete();
        AuditEvent::record('access.unblocked', $block->ip, ['scope' => $block->scope]);
    }
}
