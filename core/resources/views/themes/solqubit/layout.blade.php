@php
    $identityAssets = app(\App\Services\SiteIdentity::class)->assets($site);
@endphp
<!doctype html>
<html lang="es" data-theme="{{ ($live ?? false) ? ($previewMode ?? 'light') : 'light' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#f7f7fb">
@include('themes.solqubit.seo')
@if($live ?? false)
<meta name="robots" content="noindex,nofollow">
<base target="_blank">
@endif
<link rel="alternate" type="application/rss+xml" title="{{ $site['name'] }}" href="{{ route('feed') }}">
<link rel="icon" href="{{ $identityAssets['faviconUrl'] }}">
@if($monetization ?? null)
<meta name="google-adsense-account" content="{{ $monetization['publisher_id'] }}">
@endif
@if($monetization['enabled'] ?? false)
<script src="{{ asset('monetization.js') }}?v={{ filemtime(public_path('monetization.js')) }}" data-solqubit-ads data-publisher="{{ $monetization['publisher_id'] }}" data-format="{{ $monetization['format'] }}" defer></script>
@endif
<link rel="preload" href="{{ asset('themes/solqubit/fonts/nunito-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('themes/solqubit/fonts/dm-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
@foreach($styles as $style)
<link rel="stylesheet" href="{{ $style }}">
@endforeach
<style id="site-palette">{!! app(\App\Services\ColorPalettes::class)->css() !!}</style>
<script>try{var m=localStorage.getItem('solqubit-color-mode');document.documentElement.dataset.theme=m==='dark'||(m!=='light'&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light'}catch(e){}</script>
<script src="{{ asset('blog.js') }}?v={{ filemtime(public_path('blog.js')) }}" defer></script>
@php
    $visitTracking = isset($status) || ($preview ?? false) ? null : app(\App\Services\VisitTracker::class)->settings();
@endphp
@if($visitTracking['enabled'] ?? false)
<script src="{{ asset('js/visitas.js') }}?v={{ filemtime(public_path('js/visitas.js')) }}" data-solqubit-visits data-endpoint="{{ route('visits.store') }}" data-returning="{{ $visitTracking['returning'] ? '1' : '0' }}"{{ auth()->check() ? ' data-ignore' : '' }} defer></script>
@endif
</head>
<body data-site-theme="{{ $theme['id'] }}">
@php
    $brand = $site['name'];
    if ($site['brand_lowercase'] ?? true) $brand = mb_strtolower($brand);
    $brandIcon = $site['brand_icon'] ?? 'cube';
    $copyrightTemplate = array_key_exists('footer_copyright', $site) ? ($site['footer_copyright'] ?? '') : '© {year} {site}';
    $copyright = strtr($copyrightTemplate, ['{year}' => now()->format('Y'), '{site}' => $site['name']]);
@endphp
<a class="skip-link" href="#main">Saltar al contenido</a>
<div class="reading-progress" aria-hidden="true"></div>
<header class="site-header">
    <div class="header-inner container">
        <a class="brand" href="{{ route('home') }}" aria-label="{{ $site['name'] }}, inicio">
            @if($identityAssets['brandImage'])
                <img class="brand-image" src="{{ $identityAssets['brandImage']->url() }}" alt="" width="39" height="39">
            @elseif(!in_array($brandIcon, ['none', 'custom'], true))
                <span class="brand-mark" aria-hidden="true">@if($brandIcon === 'cube')<svg viewBox="0 0 32 32"><path d="M8 11.5 16 7l8 4.5v9L16 25l-8-4.5z"/><path d="m8 11.5 8 5 8-5M16 16.5V25"/><circle cx="23.5" cy="8" r="3"/></svg>@else<x-blog-icon :name="$brandIcon"/>@endif</span>
            @endif
            <span class="brand-name">{{ $brand }}@if($site['brand_dot'] ?? true)<span class="brand-dot">.</span>@endif</span>
        </a>
        <nav class="desktop-nav" aria-label="Navegación principal">
            @foreach(array_slice($navigation, 0, 4) as $link)
                @include('themes.solqubit.menu-link')
            @endforeach
            @if(count($navigation) > 4)
                <details class="nav-more">
                    <summary>Más <x-blog-icon name="chevron"/></summary>
                    <div class="nav-more-links">
                        @foreach(array_slice($navigation, 4) as $link)
                            @include('themes.solqubit.menu-link')
                        @endforeach
                    </div>
                </details>
            @endif
        </nav>
        @include('themes.solqubit.social-links')
        <div class="header-actions">
            <button class="search-trigger" type="button" aria-label="Buscar entradas"><x-blog-icon name="search"/><span class="search-label">Buscar</span><kbd aria-hidden="true">/</kbd></button>
            <button class="theme-toggle" type="button">
                <span class="theme-toggle-label"><span data-label-light>Modo claro<span class="sr-only">. Pulsa para cambiar a modo oscuro</span></span><span data-label-dark>Modo oscuro<span class="sr-only">. Pulsa para cambiar a modo claro</span></span></span>
                <span class="theme-toggle-track" aria-hidden="true"><span class="theme-toggle-thumb"><span data-sun><x-blog-icon name="sun"/></span><span data-moon><x-blog-icon name="moon"/></span></span></span>
            </button>
            <button class="icon-button mobile-menu-toggle" type="button" aria-label="Menú" aria-expanded="false" aria-controls="mobile-nav"><x-blog-icon name="menu"/></button>
        </div>
    </div>
    <div id="mobile-nav" class="mobile-nav" hidden>
        <nav class="mobile-nav-links" aria-label="Navegación móvil">
            @foreach($navigation as $link)
                @include('themes.solqubit.menu-link')
            @endforeach
        </nav>
        @include('themes.solqubit.social-links', ['socialsClass' => 'site-socials-menu'])
    </div>
</header>
@if(($preview ?? false) && ! ($live ?? false))
<div class="preview-banner" role="status">Vista previa de la versión guardada · @if(isset($page)){{ $page->published ? 'Publicada' : 'Borrador' }} · <a href="{{ \App\Filament\Resources\Pages\PageResource::getUrl('edit', ['record'=>$page]) }}">Volver al editor</a>@else{{ $post->status === 'draft' ? 'Borrador' : $post->status }} · <a href="{{ \App\Filament\Resources\Posts\PostResource::getUrl('edit', ['record'=>$post]) }}">Volver al editor</a>@endif</div>
@endif
<main id="main" tabindex="-1">@yield('content')</main>
<footer class="site-footer container">
    <div class="footer-main">
        <div>
            @if($site['footer_show_brand'] ?? true)
                <a class="footer-brand" href="{{ route('home') }}">{{ $brand }}@if($site['brand_dot'] ?? true)<span>.</span>@endif</a>
            @endif
            @if($site['footer_show_description'] ?? true)
                <p>{{ filled($site['footer_description'] ?? '') ? $site['footer_description'] : ($site['description'] ?? '') }}</p>
            @endif
            @if($site['footer_show_socials'] ?? true)
                @include('themes.solqubit.social-links', ['socialsClass' => 'footer-socials', 'socialsLabel' => 'Redes de '.$site['name'].' (pie de página)'])
            @endif
        </div>
        <nav aria-label="Navegación del pie">
            @foreach($footerNavigation as $link)
                @include('themes.solqubit.menu-link')
            @endforeach
        </nav>
    </div>
    <div class="footer-bottom">
        <span>{{ $copyright }}</span>
        <small><a href="{{ route('feed') }}">RSS</a>@if(auth()->user()?->isAdmin()) · <a href="{{ \Filament\Facades\Filament::getPanel('admin')->getUrl() }}">Administración</a>@endif</small>
    </div>
</footer>
<dialog id="search-dialog" class="search-dialog" aria-label="Buscar entradas">
    <form action="{{ route('home') }}" method="get" class="search-form" role="search">
        <x-blog-icon name="search"/>
        <label class="sr-only" for="search-input">Buscar entradas</label>
        <input id="search-input" name="q" type="search" placeholder="Buscar una entrada…" maxlength="160" autocomplete="off">
        <button type="submit" class="text-button">Buscar</button>
        <button type="button" class="close-dialog" aria-label="Cerrar búsqueda (Esc)">Esc</button>
    </form>
    <p class="search-hint">Escribe una palabra o un tema y pulsa Enter.</p>
</dialog>
<dialog id="detail-dialog" class="detail-dialog" aria-label="Imagen ampliada">
    <button class="detail-close icon-button" type="button" aria-label="Cerrar"><x-blog-icon name="close"/></button>
    <div id="detail-content"></div>
</dialog>
<div class="toast" role="status" aria-live="polite"></div>
</body>
</html>
