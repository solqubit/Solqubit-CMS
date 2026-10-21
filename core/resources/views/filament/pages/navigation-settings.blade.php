<x-filament-panels::page>
    <form wire:submit="save" class="sq-settings-form">
        {{ $this->form }}
        <div class="sq-save-bar"><span>Las páginas se crean en <a class="underline" href="{{ \App\Filament\Resources\Pages\PageResource::getUrl() }}">Páginas</a>.</span><div><x-filament::link href="{{ route('home') }}" target="_blank" rel="noopener">Ver mi sitio</x-filament::link><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">Guardar menús</x-filament::button></div></div>
    </form>
</x-filament-panels::page>
