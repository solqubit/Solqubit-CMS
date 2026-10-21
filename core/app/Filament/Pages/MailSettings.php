<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Models\Setting;
use App\Services\AdminMail;
use Filament\Actions\Action;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class MailSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Correo';

    protected static ?string $title = 'Correo del sitio';

    protected static string|\UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'correo';

    protected string $view = 'filament.pages.mail-settings';

    public ?array $data = [];

    #[Locked]
    public ?array $smtpTestResult = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $mail = Setting::read('mail', []);
        unset($mail['password']);
        $this->form->fill([
            'smtp_test_recipient' => auth()->user()?->email,
            'mail' => $mail + ['enabled' => false, 'port' => 587, 'encryption' => 'tls', 'from_name' => Setting::read('site', [])['name'] ?? 'Solqubit'],
        ]);
        $this->rememberData();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Recuperación de contraseña por correo')->description('Los datos SMTP de tu proveedor. El sitio los usa para enviar los enlaces de recuperación de contraseña. Envía una prueba y guarda cuando funcione. La contraseña guardada no se muestra.')->schema([
                Forms\Toggle::make('mail.enabled')->label('Usar esta configuración de correo')->live()->default(false)->columnSpanFull(),
                Forms\TextInput::make('mail.host')->label('Servidor SMTP')->maxLength(255)->regex('/^[a-zA-Z0-9.\-:\[\]]+$/')->required(fn (Get $get): bool => (bool) $get('mail.enabled'))
                    ->helperText('Nombre del servidor, por ejemplo smtp.tuproveedor.com, sin https://.')
                    ->validationMessages(['regex' => 'Escribe el nombre del servidor SMTP sin https:// ni espacios.']),
                Forms\TextInput::make('mail.port')->label('Puerto')->numeric()->minValue(1)->maxValue(65535)->required(fn (Get $get): bool => (bool) $get('mail.enabled')),
                Forms\Select::make('mail.encryption')->label('Conexión segura')->options(['tls' => 'STARTTLS (habitualmente 587)', 'ssl' => 'TLS directo (habitualmente 465)'])->required(),
                Forms\TextInput::make('mail.username')->label('Usuario SMTP')->maxLength(255),
                Forms\TextInput::make('mail.password')->label('Contraseña SMTP')->password()->maxLength(1000)->autocomplete('new-password')->helperText('En blanco conserva la contraseña guardada.'),
                Forms\TextInput::make('mail.from_address')->label('Correo del remitente')->email()->maxLength(255)->required(fn (Get $get): bool => (bool) $get('mail.enabled')),
                Forms\TextInput::make('mail.from_name')->label('Nombre del remitente')->maxLength(100)->required(fn (Get $get): bool => (bool) $get('mail.enabled')),
                Forms\TextInput::make('smtp_test_recipient')->label('Destinatario de la prueba')->default(fn (): ?string => auth()->user()?->email)->dehydrated(false)->columnSpanFull()
                    ->helperText('Se usa solo para esta prueba. No cambia el correo de tu cuenta ni el remitente.'),
                Actions::make([
                    Action::make('testSmtp')->label('Enviar correo de prueba')->icon('heroicon-o-paper-airplane')->color('gray')
                        ->action(fn () => $this->testSmtp((string) ($this->data['smtp_test_recipient'] ?? ''))),
                ])->key('smtp-actions')->columnSpanFull(),
                View::make('filament.smtp-test-result')->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2]),
        ]);
    }

    public function testSmtp(string $recipient): void
    {
        abort_unless(static::canAccess(), 403);
        $this->smtpTestResult = null;
        $validator = Validator::make(['mail' => $this->data['mail'] ?? [], 'recipient' => $recipient], [
            'recipient' => 'required|email|max:254',
            'mail.host' => 'required|string|max:255|regex:/^[a-zA-Z0-9.\-:\[\]]+$/',
            'mail.port' => 'required|integer|between:1,65535', 'mail.encryption' => 'required|in:tls,ssl',
            'mail.username' => 'nullable|string|max:255', 'mail.password' => 'nullable|string|max:1000',
            'mail.from_address' => 'required|email|max:255', 'mail.from_name' => 'required|string|max:100',
        ], ['mail.host.regex' => 'Escribe el nombre del servidor SMTP sin https:// ni espacios.']);
        if ($validator->fails()) {
            Notification::make()->warning()->title('Revisa los datos SMTP y el destinatario')->send();
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn (array $messages, string $key): array => [$key === 'recipient' ? 'data.smtp_test_recipient' : 'data.'.$key => $messages])->all());
        }
        $validated = $validator->validated();
        $key = 'smtp-test:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->smtpTestResult = ['ok' => false, 'message' => 'Ya hiciste tres pruebas. Espera un minuto antes de volver a enviar.'];
            Notification::make()->warning()->title('Espera antes de repetir la prueba')->send();

            return;
        }
        RateLimiter::hit($key, 60);
        try {
            app(AdminMail::class)->sendTest($validated['mail'], $validated['recipient']);
            $this->smtpTestResult = ['ok' => true, 'message' => 'El servidor SMTP aceptó el correo para '.$recipient.'. Revisa la bandeja de entrada y spam. Recuerda guardar la configuración si quieres usarla.'];
            Notification::make()->success()->title('Correo de prueba enviado al servidor')->send();
        } catch (\Throwable $error) {
            logger()->warning('Falló la prueba SMTP.', ['exception' => $error::class]);
            $this->smtpTestResult = ['ok' => false, 'message' => app(AdminMail::class)->testFailureMessage($error)];
            Notification::make()->danger()->title('La prueba SMTP no se completó')->body($this->smtpTestResult['message'])->send();
        }
    }

    public function updatedData(mixed $value, ?string $key = null): void
    {
        if ($key === null || $key === 'mail' || str_starts_with($key, 'mail.') || $key === 'smtp_test_recipient') {
            $this->smtpTestResult = null;
        }
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $mail = $data['mail'];
        $mail['password'] = filled($mail['password'] ?? '') ? Crypt::encryptString($mail['password']) : (Setting::read('mail', [])['password'] ?? null);
        Setting::write('mail', $mail);
        $this->data['mail']['password'] = null;
        Notification::make()->success()->title('Configuración de correo guardada')->send();
        $this->rememberData();
    }
}
