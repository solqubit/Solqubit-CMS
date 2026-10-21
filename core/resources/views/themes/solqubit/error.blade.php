@extends('themes.solqubit.layout')
@section('content')
<div class="container error-page">
    <section class="inner-intro" aria-labelledby="error-heading">
        <span class="error-code">Error {{ $status }}</span>
        <h1 id="error-heading">{{ $error['title'] }}</h1>
        <p>{{ $error['message'] }}</p>
    </section>
    <form class="error-search" action="{{ route('home') }}" method="get" role="search" aria-label="Buscar en el blog">
        <label class="sr-only" for="error-search-input">Buscar entradas</label>
        <input id="error-search-input" name="q" type="search" placeholder="Buscar una entrada…" maxlength="160" autocomplete="off">
        <button type="submit" class="button">Buscar</button>
    </form>
    <p class="error-actions"><a class="button button-secondary" href="{{ route('home') }}">Volver a la portada</a></p>
    @if($recent->isNotEmpty())
        <section class="journal-catalog" aria-labelledby="recent-heading">
            <div class="catalog-heading"><h2 id="recent-heading">Lo más reciente</h2></div>
            <div class="journal-grid">
                @foreach($recent as $post)
                    @include('themes.solqubit.card', ['featuredCard' => false])
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
