<x-filament-panels::page>
    <form wire:submit="save" class="sq-settings-form">
        {{ $this->form }}
        <x-filament::section heading="Qué hace el CMS por ti" description="Se actualiza solo con cada publicación. No necesitas editar código." collapsible collapsed>
            <div class="sq-seo-features">
                @foreach([
                    'Títulos y descripciones' => 'Usan los datos de la entrada o página. Puedes escribir unos propios en la sección Buscadores de su editor.',
                    'URL principales y paginación' => 'Cada contenido indica su dirección principal y cada página del listado conserva su número.',
                    'Datos estructurados' => 'Sitio, rutas de navegación y artículos con autor, fechas e imagen.',
                    'Contenido privado fuera de los buscadores' => 'El panel, las vistas previas, las búsquedas y los filtros usan noindex.',
                    'Sitemap y robots.txt' => 'El mapa incluye el contenido publicado y se divide solo cuando crece.',
                    'Al compartir en redes' => 'Título, descripción y la portada de cada entrada o página, o la imagen por defecto.',
                ] as $title => $description)
                    <div class="sq-seo-feature"><x-heroicon-o-check-circle/><div><strong>{{ $title }}</strong><p>{{ $description }}</p></div></div>
                @endforeach
            </div>
        </x-filament::section>
        <div class="sq-seo-links">
            <x-filament::link href="{{ route('robots') }}" target="_blank" rel="noopener">Ver robots.txt ↗</x-filament::link>
            <x-filament::link href="{{ route('sitemap') }}" target="_blank" rel="noopener">Ver sitemap.xml ↗</x-filament::link>
            <x-filament::link href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer">Google Search Console ↗</x-filament::link>
            <x-filament::link href="https://www.bing.com/webmasters/" target="_blank" rel="noopener noreferrer">Bing Webmaster Tools ↗</x-filament::link>
        </div>
        <div class="sq-save-bar"><span>Los códigos de verificación son opcionales.</span><div><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save" icon="heroicon-o-check">Guardar ajustes SEO</x-filament::button></div></div>
    </form>
</x-filament-panels::page>
