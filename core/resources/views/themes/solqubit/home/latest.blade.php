@if($featured && $posts->total() === 0 && !$search && !$category)
    <p class="home-more-note" data-home-section="{{ $index }}"><x-blog-icon name="clock"/>Pronto habrá más entradas.</p>
@else
    <section id="entradas" class="journal-catalog" aria-labelledby="entries-heading" data-home-section="{{ $index }}">
        <div class="catalog-heading">
            <h2 id="entries-heading">{{ $section['title'] ?: 'Últimas entradas.' }}</h2>
            <span id="results-count">{{ $totalEntries }} {{ $totalEntries === 1 ? 'entrada' : 'entradas' }}</span>
        </div>
        @if($section['show_search'] || $section['show_filter'] || $search || $category)
            <form action="{{ route('home') }}#entradas" method="get" class="index-controls" role="search" aria-label="Buscar en las entradas">
                @if($section['show_search'] || $search)
                    <div class="index-search">
                        <x-blog-icon name="search"/>
                        <label class="sr-only" for="guide-search">Buscar una entrada y pulsar Enter</label>
                        <input id="guide-search" name="q" type="search" placeholder="Buscar una entrada…" value="{{ $search }}" maxlength="160">
                        <button type="submit" class="text-button">Buscar</button>
                    </div>
                @endif
                @if($section['show_filter'] || $category)
                    <div class="index-select">
                        <label class="sr-only" for="guide-category">Filtrar por tema</label>
                        <select id="guide-category" name="category">
                            <option value="">Todos los temas</option>
                            @foreach($categories as $item)
                                <option value="{{ $item->slug }}" @selected($category === $item->slug)>{{ $item->name }}</option>
                            @endforeach
                        </select>
                        <x-blog-icon name="chevron"/>
                    </div>
                @endif
            </form>
        @endif
        <div id="guide-list" class="journal-grid">
            @forelse($posts as $post)
                @include('themes.solqubit.card', ['featuredCard' => false])
            @empty
                <div class="index-empty">
                    <x-blog-icon name="search"/>
                    <h3>{{ $search || $category ? 'No hay entradas con esa búsqueda.' : 'Pronto habrá material nuevo.' }}</h3>
                    <p>{{ $search || $category ? 'Prueba con otra palabra o consulta todos los temas.' : 'Las nuevas entradas aparecerán aquí cuando se publiquen.' }}</p>
                    @if($search || $category)
                        <a class="button" href="{{ route('home') }}">Ver todas las entradas</a>
                    @endif
                </div>
            @endforelse
        </div>
        @include('themes.solqubit.pagination', ['paginator' => $posts, 'anchor' => 'entradas'])
    </section>
@endif
