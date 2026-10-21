<x-filament-panels::page>
    <form wire:submit="save" class="sq-settings-form">
        {{ $this->form }}
        <div class="sq-save-bar"><span>El título de bienvenida se cambia en <a class="underline" href="{{ \App\Filament\Pages\HomeSettings::getUrl() }}">Portada</a>.</span><div><x-filament::link href="{{ route('home') }}" target="_blank" rel="noopener">Ver mi sitio</x-filament::link><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">Guardar cambios</x-filament::button></div></div>
    </form>
</x-filament-panels::page>
