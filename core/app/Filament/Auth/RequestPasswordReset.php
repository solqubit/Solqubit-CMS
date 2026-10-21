<?php

namespace App\Filament\Auth;

use App\Services\AdminMail;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RequestPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
    public function request(): void
    {
        try {
            if (! app(AdminMail::class)->configure()) {
                Notification::make()->warning()->title('El correo de recuperación aún no está configurado')->body('Contacta con el administrador del sitio para recuperar tu acceso.')->send();

                return;
            }
            parent::request();
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            Log::warning('No se pudo enviar el correo de recuperación.', ['exception' => $error::class]);
            Notification::make()->danger()->title('No se pudo enviar el correo')->body('Inténtalo más tarde o contacta con el administrador.')->send();
        }
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return Notification::make()->success()->title('Solicitud recibida')->body('Si el correo corresponde a una cuenta habilitada, recibirás un enlace para cambiar la contraseña.');
    }

    protected function getFailureNotification(string $status): ?Notification
    {
        return $this->getSentNotification($status);
    }
}
