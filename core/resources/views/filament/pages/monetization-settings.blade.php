<x-filament-panels::page>
    <div class="sq-settings-guide"><x-heroicon-o-banknotes/><div><strong>Prepara la monetización a tu ritmo</strong><p>Estado guardado: <strong>{{ $savedStatus }}</strong>. La instalación empieza sin publicidad. Puedes completar los datos sin activar anuncios.</p></div></div>
    <form wire:submit="save" class="sq-settings-form">
        {{ $this->form }}
        <x-filament::section heading="Verificación y privacidad" icon="heroicon-o-information-circle" collapsible collapsed>
            <div class="sq-monetization-help">
                <p><strong>Para conectar:</strong> guarda tu identificador con el estado «Preparar verificación». Luego añade tu dominio en AdSense y elige la verificación por etiqueta meta o ads.txt. Google necesita acceder a tu sitio publicado.</p>
                <p><strong>ads.txt automático:</strong> se genera en <code>{{ route('ads.txt') }}</code> cuando preparas la verificación o activas los anuncios. No necesitas subir ni editar un archivo.
                    @if($adsTxtAvailable)<x-filament::link href="{{ route('ads.txt') }}" target="_blank" rel="noopener">Ver ads.txt guardado</x-filament::link>@endif
                </p>
                <p><strong>Antes de activar:</strong> adapta tu política de privacidad y configura el consentimiento en AdSense → Privacidad y mensajes. Para anuncios personalizados a visitantes del EEE, Reino Unido y Suiza, Google exige una CMP certificada. El mensaje de Google puede configurarse desde AdSense.</p>
                <p><strong>Al elegir ubicaciones del blog:</strong> se necesitan los identificadores de bloques Display adaptables. Si utilizas anuncios automáticos, actívalos también en Google. La disponibilidad de anuncios depende de AdSense y de los bloqueadores del visitante.</p>
                <div class="sq-monetization-links">
                    <x-filament::link href="https://support.google.com/adsense/answer/7584263?hl=es" target="_blank" rel="noopener noreferrer">Conectar con AdSense ↗</x-filament::link>
                    <x-filament::link href="https://support.google.com/adsense/answer/13554116?hl=es" target="_blank" rel="noopener noreferrer">Consentimiento de visitantes ↗</x-filament::link>
                    <x-filament::link href="{{ \App\Filament\Resources\Pages\PageResource::getUrl() }}">Editar mis páginas</x-filament::link>
                </div>
            </div>
        </x-filament::section>
        <div class="sq-save-bar"><span>Preparar la cuenta no activa los anuncios.</span><div><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save" icon="heroicon-o-check">Guardar monetización</x-filament::button></div></div>
    </form>
</x-filament-panels::page>
