<?php

namespace App\Services;

use App\Mail\SmtpTestMessage;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

class AdminMail
{
    public function configure(): bool
    {
        $settings = Setting::read('mail', []);
        if (! ($settings['enabled'] ?? false)) {
            return ! in_array(config('mail.default'), ['log', 'array'], true);
        }
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => $this->smtpConfiguration($settings, $this->savedPassword($settings)),
            'mail.from.address' => $settings['from_address'], 'mail.from.name' => $settings['from_name'],
        ]);
        Mail::purge('smtp');

        return true;
    }

    public function sendTest(array $settings, string $recipient): void
    {
        $password = filled($settings['password'] ?? null) ? $settings['password'] : $this->savedPassword(Setting::read('mail', []));
        $mailer = Mail::build($this->smtpConfiguration($settings, $password));
        $sent = $mailer->to($recipient)->send(new SmtpTestMessage($settings['from_address'], $settings['from_name']));
        if (! $sent) {
            throw new \RuntimeException('El envío fue cancelado antes de llegar al servidor.');
        }
    }

    private function savedPassword(array $settings): ?string
    {
        return filled($settings['password'] ?? null) ? Crypt::decryptString($settings['password']) : null;
    }

    private function smtpConfiguration(array $settings, ?string $password): array
    {
        return [
            'transport' => 'smtp', 'url' => null,
            'host' => $settings['host'], 'port' => (int) $settings['port'],
            'scheme' => ($settings['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'username' => $settings['username'] ?? null, 'password' => $password,
            'timeout' => 15, 'require_tls' => true,
            'local_domain' => config('mail.mailers.smtp.local_domain'),
        ];
    }

    public function testFailureMessage(\Throwable $error): string
    {
        $message = strtolower($error->getMessage());

        return match (true) {
            (bool) preg_match('/535|authentication|authenticate|credentials/', $message) => 'El servidor rechazó el usuario o la contraseña. Comprueba las credenciales SMTP; tu proveedor podría requerir una contraseña de aplicación.',
            (bool) preg_match('/certificate|starttls|ssl|tls/', $message) => 'No se pudo establecer una conexión segura. Comprueba el puerto, el tipo de conexión y el certificado del servidor.',
            (bool) preg_match('/getaddrinfo|resolve|php_network/', $message) => 'No se encontró el servidor SMTP. Comprueba su nombre.',
            (bool) preg_match('/connect|timed out|timeout/', $message) => 'No se pudo conectar al servidor SMTP. Comprueba el servidor, el puerto y si tu alojamiento permite esa conexión.',
            default => 'No se pudo enviar el correo. Revisa los datos SMTP, el remitente y el destinatario.',
        };
    }
}
