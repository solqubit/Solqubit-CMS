<x-filament-panels::page>
    <x-filament::section heading="Verificación en dos pasos">
        <p>Protege tu cuenta con una aplicación autenticadora desde <a class="font-semibold underline" href="{{ filament()->getProfileUrl() }}">Mi perfil</a>. Guarda los códigos de recuperación para poder entrar si pierdes el dispositivo.</p>
    </x-filament::section>
    <form wire:submit="save" class="sq-settings-form">
        {{ $this->form }}
        <div class="sq-save-bar"><span>El correo de recuperación se configura en <a class="underline" href="{{ \App\Filament\Pages\MailSettings::getUrl() }}">Correo</a>.</span><div><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">Guardar seguridad</x-filament::button></div></div>
    </form>
</x-filament-panels::page>
