<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'is_active', 'avatar_id', 'bio'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    protected $attributes = ['role' => 'editor', 'is_active' => true];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && in_array($this->role, ['admin', 'editor'], true);
    }

    public function isAdmin(): bool
    {
        return $this->is_active && $this->role === 'admin';
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            $user->email = mb_strtolower(trim($user->email));
            if ($user->username !== null) {
                $user->username = strtolower(trim($user->username));
                Validator::make(['username' => $user->username], [
                    'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9_.-]*$/', Rule::unique('users')->ignore($user->getKey())],
                ])->validate();
            }
            if ($user->avatar_id && ! Media::find($user->avatar_id)?->isImage()) {
                throw ValidationException::withMessages(['avatar_id' => 'Selecciona una imagen de la biblioteca.']);
            }
            if (mb_strlen($user->bio ?? '') > 500) {
                throw ValidationException::withMessages(['bio' => 'La biografía admite hasta 500 caracteres.']);
            }
            if ($user->exists && $user->getOriginal('role') === 'admin' && $user->getOriginal('is_active') && (! $user->is_active || $user->role !== 'admin')) {
                if (! static::whereKeyNot($user->id)->where('role', 'admin')->where('is_active', true)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['role' => 'Debe quedar al menos un administrador activo.']);
                }
            }
        });
    }
}
