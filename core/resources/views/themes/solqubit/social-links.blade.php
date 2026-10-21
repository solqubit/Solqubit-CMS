@php($socialLinks = app(\App\Services\SocialLinks::class)->configured($site))
@if(count($socialLinks))
<nav class="site-socials{{ filled($socialsClass ?? null) ? ' '.$socialsClass : '' }}" aria-label="{{ $socialsLabel ?? 'Redes de '.$site['name'] }}">
    @foreach($socialLinks as $social)
        @php($networkLabel = \App\Services\SocialLinks::NETWORKS[$social['network']])
        <a data-network="{{ $social['network'] }}" href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $site['name'] }} en {{ $networkLabel }}" title="{{ $networkLabel }} · {{ $site['name'] }}"><x-social-icon :network="$social['network']"/></a>
    @endforeach
</nav>
@endif
