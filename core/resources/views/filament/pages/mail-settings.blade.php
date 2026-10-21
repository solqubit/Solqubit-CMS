<x-filament-panels::page>
    <form wire:submit="save" class="sq-settings-form">
        {{ $this->form }}
        <div class="sq-save-bar"><span>Enviar una prueba no guarda la configuración.</span><div><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">Guardar correo</x-filament::button></div></div>
    </form>
</x-filament-panels::page>
