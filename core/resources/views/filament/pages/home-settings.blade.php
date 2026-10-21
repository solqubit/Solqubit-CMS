<x-filament-panels::page>
    <form wire:submit="save" class="sq-settings-form">
        {{ $this->form }}
        <div class="sq-save-bar"><span>Los cambios se publican al guardar.</span><div><x-filament::link href="{{ route('home') }}" target="_blank" rel="noopener">Ver mi sitio</x-filament::link><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">Guardar portada</x-filament::button></div></div>
    </form>
</x-filament-panels::page>
