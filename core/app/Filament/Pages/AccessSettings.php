<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Models\Setting;
use App\Services\AdminAccess;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

class AccessSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationLabel = 'Seguridad';

    protected static ?string $title = 'Seguridad';

    protected static string|\UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 22;

    protected static ?string $slug = 'seguridad';

    protected string $view = 'filament.pages.access-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill([
            'path' => AdminAccess::path(),
            'blocked_ips' => implode("\n", Setting::read('access', [])['blocked_ips'] ?? []),
            'require_admin_two_factor' => app(AdminAccess::class)->requiresAdminTwoFactor(),
        ]);
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Dirección de administración')->description('Cambia la ruta de todo el panel. Guarda la nueva dirección en tus favoritos.')->schema([
                Forms\TextInput::make('path')->label('Dirección del panel')->prefix(url('/').'/')->required()->maxLength(60)->regex('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/')->live(onBlur: true)->disabled((bool) config('solqubit.admin_path_override'))->dehydrated()
                    ->helperText(fn (Get $get): string => 'Inicio de sesión: '.url('/'.($get('path') ?: 'admin').'/login').'. Usa minúsculas, números y guiones.'),
            ]),
            Section::make('Verificación en dos pasos obligatoria')->schema([
                Forms\Toggle::make('require_admin_two_factor')->label('Exigir verificación en dos pasos a los administradores')
                    ->helperText('Los administradores que aún no la tengan deberán configurarla con una aplicación autenticadora antes de seguir usando el panel. No afecta a los editores. Antes de activarla, guarda tus códigos de recuperación.'),
            ]),
            Section::make('Bloqueos de acceso')->description('5 contraseñas incorrectas por correo e IP, 10 para la misma cuenta desde cualquier IP, o 30 desde una IP, producen un bloqueo temporal de hasta 15 minutos. El límite de 5 solicitudes por minuto añade una pausa breve.')->schema([
                View::make('filament.access-blocks'),
                Forms\Textarea::make('blocked_ips')->label('Bloqueos manuales por IP (opcional)')->rows(3)->maxLength(3000)->helperText('Una IP por línea, por ejemplo 203.0.113.10. Permanecen hasta que las retires y guardes. Afectan al panel y sus vistas previas, no al blog público. No se permite bloquear tu conexión actual.'),
            ]),

        ]);
    }

    public function unblock(string $key): void
    {
        abort_unless(static::canAccess(), 403);
        app(AdminAccess::class)->unblock($key);
        Notification::make()->success()->title('Bloqueo automático retirado')->send();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $ips = array_values(array_unique(preg_split('/\s+/', trim($data['blocked_ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)));
        if (count($ips) > 50 || collect($ips)->contains(fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) === false)) {
            throw ValidationException::withMessages(['data.blocked_ips' => 'Introduce hasta 50 direcciones IP válidas, una por línea.']);
        }
        if ($ips && IpUtils::checkIp(request()->ip(), $ips)) {
            throw ValidationException::withMessages(['data.blocked_ips' => 'Esta lista bloquearía tu conexión actual. Retira esa dirección.']);
        }
        $pathChanged = $data['path'] !== AdminAccess::path();
        if ($pathChanged) {
            try {
                app(AdminAccess::class)->changePath($data['path']);
            } catch (ValidationException $error) {
                throw ValidationException::withMessages(['data.path' => collect($error->errors())->flatten()->first()]);
            }
        }
        Setting::write('access', ['blocked_ips' => $ips, 'require_admin_two_factor' => (bool) ($data['require_admin_two_factor'] ?? false)]);
        Notification::make()->success()->title('Configuración de acceso guardada')->send();
        if ($pathChanged) {
            $this->redirect(url('/'.$data['path'].'/'.Settings::getSlug().'/'.static::getSlug()));
        }
        $this->rememberData();
    }
}
