@extends('themes.solqubit.layout')
@section('content')
@php($firstTextIndex = collect($sections)->search(fn (array $section): bool => $section['type'] === 'text'))
@php($hasVisibleHeading = collect($sections)->contains(fn (array $section): bool => $section['type'] === 'welcome' && $section['show_heading']))
<div class="container journal-home">
    @unless($hasVisibleHeading)
        <h1 class="sr-only" id="home-heading">{{ $page->title }}</h1>
    @endunless
    @foreach($sections as $index => $section)
        @include('themes.solqubit.home.'.$section['type'], ['section' => $section, 'index' => $index])
    @endforeach
</div>
@endsection
