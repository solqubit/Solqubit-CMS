<x-filament-widgets::widget>
    <section class="sq-workspace" aria-labelledby="sq-workspace-title">
        <div class="sq-workspace-heading">
            <div><span class="sq-eyebrow">Tu espacio de publicación</span><h2 id="sq-workspace-title">¿Qué quieres hacer hoy?</h2><p>Encuentra cada parte de tu blog en un solo lugar.</p></div>
            <a class="sq-outline-link" href="{{ route('home') }}" target="_blank" rel="noopener">Ver mi sitio <x-heroicon-o-arrow-up-right/></a>
        </div>
        <div class="sq-task-grid">
            @foreach($links as $link)
                <a class="sq-task-card" data-tone="{{ $link['tone'] }}" href="{{ $link['url'] }}">
                    <span class="sq-task-icon"><x-dynamic-component :component="$link['icon']"/></span>
                    <div><h3>{{ $link['title'] }}</h3><p>{{ $link['description'] }}</p></div><x-heroicon-o-arrow-up-right class="sq-task-arrow"/>
                </a>
            @endforeach
        </div>
    </section>
</x-filament-widgets::widget>
