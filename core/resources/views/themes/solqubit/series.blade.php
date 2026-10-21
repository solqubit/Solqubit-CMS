@extends('themes.solqubit.layout')
@section('content')
<div class="container series-page">
    @include('themes.solqubit.page-heading')
    @include('themes.solqubit.page-blocks')
    <section class="series-list" id="series-list" aria-label="Series de entradas">
        @forelse($series as $item)
            <article class="series-item" data-tone="{{ $item->tone }}">
                <div class="series-cover">@include('themes.solqubit.cover', ['media' => $item->cover ?? $item->posts->first()?->cover])</div>
                <div class="series-content">
                    <header class="series-summary">
                        <span class="series-number">{{ $item->posts->count() }} {{ $item->posts->count() === 1 ? 'entrada' : 'entradas' }}</span>
                        <h2>{{ $item->name }}</h2>
                        <p>{{ $item->description }}</p>
                    </header>
                    <div class="series-chapters">
                        @foreach($item->posts as $entry)
                            <a class="chapter" href="{{ $entry->url() }}"><strong>{{ $entry->title }}</strong><x-blog-icon name="arrow"/></a>
                        @endforeach
                    </div>
                </div>
            </article>
        @empty
            <p class="index-empty">Las series publicadas aparecerán aquí.</p>
        @endforelse
    </section>
    @include('themes.solqubit.pagination', ['paginator' => $series, 'anchor' => 'series-list'])
</div>
@endsection
