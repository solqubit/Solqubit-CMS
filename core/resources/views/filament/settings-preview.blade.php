<div class="sq-setting-preview" x-data="{}">
    <div class="sq-preview-caption"><span>Así se verá</span><span>Se actualiza mientras escribes</span></div>
    @if($previewType === 'home')
        <div class="sq-home-preview">
            <h2 x-show="$wire.data.settings.show_heading" x-cloak><span x-text="$wire.data.settings.heading || 'Tu título de bienvenida'"></span><strong x-text="$wire.data.settings.accent_heading || ''"></strong></h2>
            <div class="sq-home-preview-note" x-show="$wire.data.settings.show_intro" x-cloak>
                @foreach(\App\Models\Page::iconOptions() as $icon => $label)
                    @if($icon !== 'none')<span class="sq-preview-mark" x-show="$wire.data.settings.icon === @js($icon)" x-cloak><x-blog-icon :name="$icon"/></span>@endif
                @endforeach
                <p x-text="$wire.data.settings.intro || 'Tu mensaje de bienvenida'"></p>
            </div>
            <p class="sq-preview-empty" x-show="!$wire.data.settings.show_heading && !$wire.data.settings.show_intro" x-cloak>La portada comenzará directamente con tus entradas.</p>
        </div>
    @else
        <div class="sq-brand-preview" @if($previewType === 'footer') x-show="$wire.data.footer_show_brand" x-cloak @endif>
            @if($previewType === 'brand')
                @foreach(['cube' => 'Cubo', ...\App\Models\Page::iconOptions()] as $icon => $label)
                    @if($icon !== 'none')<span class="sq-preview-mark" x-show="$wire.data.brand_icon === @js($icon)" x-cloak>@if($icon === 'cube')<x-heroicon-o-cube/>@else<x-blog-icon :name="$icon"/>@endif</span>@endif
                @endforeach
            @endif
            <strong><span x-text="$wire.data.brand_lowercase ? ($wire.data.name || 'Tu marca').toLocaleLowerCase() : ($wire.data.name || 'Tu marca')"></span><span class="sq-brand-dot" x-show="$wire.data.brand_dot" x-cloak>.</span></strong>
        </div>
        @if($previewType === 'footer')
            <p class="sq-preview-description" x-show="$wire.data.footer_show_description" x-cloak x-text="$wire.data.footer_description || $wire.data.description || ''"></p>
            <div class="sq-copyright-preview" x-text="($wire.data.footer_copyright || '').replaceAll('{year}', @js(now()->format('Y'))).replaceAll('{site}', $wire.data.name || '')"></div>
        @endif
    @endif
</div>
