<header class="sq-writing-header" x-data="{ settings: window.innerWidth >= 1100 }" x-init="document.body.classList.toggle('sq-settings-hidden', !settings)" x-on:form-validation-error.window="settings = true; document.body.classList.remove('sq-settings-hidden')">
    <a class="sq-writing-back" href="{{ $backUrl }}"><x-filament::icon icon="heroicon-o-arrow-left"/>{{ $backLabel }}</a>
    <button type="button" class="sq-inserter-toggle" aria-controls="sq-block-inserter" aria-expanded="false" data-inserter-toggle><x-filament::icon icon="heroicon-o-plus" class="sq-inserter-plus"/><x-filament::icon icon="heroicon-o-x-mark" class="sq-inserter-close"/><span>Bloques</span></button>
    <div class="sq-writing-heading"><span>Editor</span><h1>{{ $this->getHeading() }}</h1></div>
    <div class="sq-writing-actions">
        <x-filament::button type="button" color="gray" icon="heroicon-o-eye" x-data="{ open: false }" x-on:sq-live-preview.window="open = $event.detail?.open ?? ! open" x-bind:aria-pressed="open" aria-controls="sq-live-preview" x-on:click="window.dispatchEvent(new CustomEvent('sq-live-preview'))">Vista previa</x-filament::button>
        <x-filament::actions :actions="$this->getCachedHeaderActions()" />
        <x-filament::button type="button" color="gray" icon="heroicon-o-adjustments-horizontal" x-bind:aria-expanded="settings" aria-controls="writing-settings" x-on:click="settings = !settings; document.body.classList.toggle('sq-settings-hidden', !settings)">Ajustes</x-filament::button>
        <x-filament::button type="submit" form="form" form-id="form" icon="heroicon-o-check" wire:loading.attr="disabled" wire:target="save,create">
            @if($isPost)
                {{-- Same rule as PostResource::primaryActionLabel(), so the button says what saving will do. --}}
                <span data-was-published="{{ $wasPublished ? '1' : '0' }}" x-data x-text="$wire.data?.status !== 'published' ? 'Guardar borrador' : ($wire.data?.published_at && new Date(String($wire.data.published_at).replace(' ', 'T')) > new Date() ? 'Programar' : ($el.dataset.wasPublished === '1' ? 'Actualizar' : 'Publicar'))">{{ $primaryLabel }}</span>
            @else
                {{ $primaryLabel }}
            @endif
        </x-filament::button>
    </div>
</header>
<section id="sq-live-preview" class="sq-live-preview-panel" aria-label="Vista previa" data-live-preview="{{ $previewContext }}" data-preview-toggle="1" data-endpoint="{{ route('editor.preview') }}" data-record-id="{{ $previewRecordId }}" wire:ignore></section>
<aside id="sq-block-inserter" class="sq-block-inserter" aria-label="Bloques" wire:ignore>
    <label class="sq-inserter-search"><x-filament::icon icon="heroicon-o-magnifying-glass"/><input type="search" aria-label="Buscar un bloque" placeholder="Buscar un bloque…" autocomplete="off" data-inserter-search></label>
    @foreach(\App\Services\EditorBlocks::inserter() as $section => $items)
        <section class="sq-inserter-section" data-inserter-section>
            <h3>{{ $section }}</h3>
            <div class="sq-inserter-grid">
                @foreach($items as $item)
                    <button type="button" class="sq-inserter-tile" data-command="{{ $item['command'] }}" data-search="{{ $item['label'].' '.$item['description'].' '.$section }}" title="{{ $item['description'] }}"><x-filament::icon :icon="$item['icon']"/><span>{{ $item['label'] }}</span></button>
                @endforeach
            </div>
        </section>
    @endforeach
    <p class="sq-inserter-empty" data-inserter-empty hidden>No hay bloques con ese nombre.</p>
</aside>
