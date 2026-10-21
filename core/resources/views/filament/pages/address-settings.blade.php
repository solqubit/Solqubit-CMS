<x-filament-panels::page>
    <form wire:submit="save" class="sq-settings-form">
        {{ $this->form }}
        <div class="sq-save-bar"><span>Las entradas existentes conservan su dirección.</span><div><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">Guardar direcciones</x-filament::button></div></div>
    </form>
</x-filament-panels::page>
