@php
    $identityAssets = app(\App\Services\SiteIdentity::class)->assets($this->data ?? []);
@endphp
<div class="sq-setting-preview sq-identity-preview" x-data="{}" aria-label="Vista previa de la identidad">
    <div class="sq-preview-caption"><span>Tu identidad, de un vistazo</span><span>Vista previa · guarda para publicar</span></div>
    <div class="sq-identity-browser">
        <span class="sq-identity-browser-tab"><img src="{{ $identityAssets['faviconUrl'] }}" alt="" width="16" height="16"><span x-text="$wire.data.name || 'Nombre de tu sitio'"></span><span aria-hidden="true">×</span></span>
        <span class="sq-identity-preview-label">Pestaña del navegador</span>
    </div>
    <div class="sq-identity-site">
        <div class="sq-brand-preview">
            @if($identityAssets['brandImage'])
                <img class="sq-identity-image" src="{{ $identityAssets['brandImage']->url() }}" alt="" width="42" height="42" x-show="$wire.data.brand_icon === 'custom'">
            @endif
            @foreach(['cube' => 'Cubo', ...\App\Models\Page::iconOptions()] as $icon => $label)
                @if($icon !== 'none')<span class="sq-preview-mark" x-show="$wire.data.brand_icon === @js($icon)" x-cloak>@if($icon === 'cube')<x-heroicon-o-cube/>@else<x-blog-icon :name="$icon"/>@endif</span>@endif
            @endforeach
            <strong><span x-text="$wire.data.brand_lowercase ? ($wire.data.name || 'Nombre de tu sitio').toLocaleLowerCase() : ($wire.data.name || 'Nombre de tu sitio')"></span><span class="sq-brand-dot" x-show="$wire.data.brand_dot" x-cloak>.</span></strong>
        </div>
        <span class="sq-identity-preview-label">Cabecera del sitio</span>
    </div>
    <div class="sq-identity-description">
        <span class="sq-identity-preview-label">Descripción general · buscadores y RSS</span>
        <p x-text="$wire.data.description || 'Una frase que cuente qué encontrarán tus lectores.'"></p>
    </div>
</div>
