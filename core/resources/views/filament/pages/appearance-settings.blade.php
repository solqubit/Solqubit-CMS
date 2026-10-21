<x-filament-panels::page>
    <form wire:submit="activate" class="sq-settings-form sq-color-variants">
        <fieldset class="sq-variant-choices" aria-describedby="variant-help">
            <legend>Elige tu variante</legend>
            <p id="variant-help">Selecciona una para ver la muestra. Se publicará cuando pulses «Aplicar variante».</p>
            <div class="sq-variant-grid">
                @foreach($variants as $id => $option)
                    <label class="sq-variant-option" wire:key="color-variant-{{ $id }}" title="{{ $option['description'] }}">
                        <input type="radio" name="color-variant" value="{{ $id }}" wire:model.live="variant">
                        <span class="sq-variant-option-body">
                            <span class="sq-variant-option-heading"><strong>{{ $option['name'] }}</strong>@if($id === $activeVariant)<span class="sq-variant-status">En uso</span>@endif<span class="sq-variant-check" aria-hidden="true"><x-heroicon-m-check/></span></span>
                            <span class="sq-variant-samples" aria-hidden="true">
                                @foreach($option['colors'] as $colors)
                                    <span style="background:{{ $colors['bg'] }};color:{{ $colors['accent'] }};border-color:{{ $colors['line'] }}"><b>Aa</b><i style="background:{{ $colors['button-bg'] }}"></i></span>
                                @endforeach
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        @error('variant')<p class="sq-variant-error" role="alert">{{ $message }}</p>@enderror
        @php($selected = $variants[$variant] ?? $variants['default'])
        <section class="sq-variant-preview-section" aria-labelledby="variant-preview-heading" aria-live="polite">
            <div class="sq-variant-preview-heading"><h2 id="variant-preview-heading">Así se ve {{ $selected['name'] }}</h2><span>{{ $selected['description'] }}</span></div>
            <div class="sq-variant-preview-grid">
                @foreach($selected['colors'] as $mode => $colors)
                    @include('filament.color-variant-preview', ['colors' => $colors, 'mode' => $mode])
                @endforeach
            </div>
        </section>
        <div class="sq-save-bar"><span>En uso: <strong>{{ $variants[$activeVariant]['name'] }}</strong>. Puedes volver a Lavanda cuando quieras.</span><div>
            <x-filament::button type="submit" :disabled="$variant === $activeVariant" wire:loading.attr="disabled" icon="heroicon-o-check">Aplicar variante</x-filament::button>
        </div></div>
    </form>
</x-filament-panels::page>
