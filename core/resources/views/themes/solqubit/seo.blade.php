<title>{{ $seo['title'] }}</title>
<meta name="description" content="{{ $seo['description'] }}">
<meta name="robots" content="{{ $seo['robots'] }}">
@if(! ($preview ?? false) && $seo['canonical'])<link rel="canonical" href="{{ $seo['canonical'] }}">@endif
<meta property="og:title" content="{{ $seo['title'] }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:type" content="{{ $seo['type'] }}">
@if($seo['canonical'])<meta property="og:url" content="{{ $seo['canonical'] }}">@endif
<meta property="og:site_name" content="{{ $seo['site_name'] }}">
@if($seo['locale'] ?? null)<meta property="og:locale" content="{{ $seo['locale'] }}">@endif
<meta name="twitter:card" content="{{ $seo['image'] ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $seo['title'] }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
@if($seo['image'])
<meta property="og:image" content="{{ $seo['image'] }}"><meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
@if(($seo['image_width'] ?? null) && ($seo['image_height'] ?? null))<meta property="og:image:width" content="{{ $seo['image_width'] }}"><meta property="og:image:height" content="{{ $seo['image_height'] }}">@endif
<meta name="twitter:image" content="{{ $seo['image'] }}"><meta name="twitter:image:alt" content="{{ $seo['image_alt'] }}">
@endif
@if($seo['published'])<meta property="article:published_time" content="{{ $seo['published'] }}">@endif
@if($seo['modified'])<meta property="article:modified_time" content="{{ $seo['modified'] }}">@endif
@if($seo['verification']['google_verification'] ?? '')<meta name="google-site-verification" content="{{ $seo['verification']['google_verification'] }}">@endif
@if($seo['verification']['bing_verification'] ?? '')<meta name="msvalidate.01" content="{{ $seo['verification']['bing_verification'] }}">@endif
@if($seo['structured_data'])<script type="application/ld+json">{!! $seo['structured_data'] !!}</script>@endif
