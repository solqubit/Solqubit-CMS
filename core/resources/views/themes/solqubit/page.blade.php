@extends('themes.solqubit.layout')
@section('content')
<div class="container managed-page">
@include('themes.solqubit.page-heading')
@if($page->cover)<figure class="managed-page-cover"><img src="{{ $page->cover->url('hero') }}" alt="{{ $page->cover->alt_text }}" {{ $page->cover->imageAttributes('(min-width: 860px) 800px, 100vw') }} loading="lazy">@if($page->cover->caption)<figcaption>{{ $page->cover->caption }}</figcaption>@endif</figure>@endif
@include('themes.solqubit.page-blocks')
@if($page->kind === 'about' && $page->option('show_channel', true) && ($site['youtube_url'] ?? ''))<a class="button about-link" href="{{ $site['youtube_url'] }}" target="_blank" rel="noopener noreferrer">Ver el canal <x-blog-icon name="arrowUp"/></a>@endif
</div>@endsection
