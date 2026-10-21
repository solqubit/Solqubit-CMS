<x-filament-panels::page>
    <form wire:submit="save" class="sq-settings-form">
        {{ $this->form }}
        <div class="sq-save-bar"><span>Se aplica a las fechas del sitio y del panel.</span><div><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">Guardar</x-filament::button></div></div>
    </form>
</x-filament-panels::page>
