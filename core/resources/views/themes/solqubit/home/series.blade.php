@if($section['items']->isNotEmpty())
    <section class="journal-catalog home-series" aria-labelledby="home-series-{{ $index }}" data-home-section="{{ $index }}">
        <div class="catalog-heading">
            <h2 id="home-series-{{ $index }}">{{ $section['title'] ?: 'Series' }}</h2>
            @if($seriesPage)
                <a class="text-button" href="{{ $seriesPage->url() }}">Ver todas <x-blog-icon name="arrow"/></a>
            @endif
        </div>
        <div class="series-list">
            @foreach($section['items'] as $item)
                <article class="series-item" data-tone="{{ $item->tone }}">
                    <div class="series-cover">@include('themes.solqubit.cover', ['media' => $item->cover ?? $item->posts->first()?->cover])</div>
                    <div class="series-content">
                        <header class="series-summary">
                            <span class="series-number">{{ $item->posts->count() }} {{ $item->posts->count() === 1 ? 'entrada' : 'entradas' }}</span>
                            <h3>{{ $item->name }}</h3>
                            <p>{{ $item->description }}</p>
                        </header>
                        <div class="series-chapters">
                            @foreach($item->posts as $entry)
                                <a class="chapter" href="{{ $entry->url() }}"><strong>{{ $entry->title }}</strong><x-blog-icon name="arrow"/></a>
                            @endforeach
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif
