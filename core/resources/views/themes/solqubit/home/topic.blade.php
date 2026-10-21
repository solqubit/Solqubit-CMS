@if($section['posts']->isNotEmpty())
    <section class="journal-catalog home-topic" aria-labelledby="home-topic-{{ $index }}" data-home-section="{{ $index }}">
        <div class="catalog-heading">
            <h2 id="home-topic-{{ $index }}">{{ $section['title'] ?: $section['category']->name }}</h2>
            <a class="text-button" href="{{ route('home', ['category' => $section['category']->slug]) }}#entradas">Ver todas <x-blog-icon name="arrow"/></a>
        </div>
        <div class="journal-grid">
            @foreach($section['posts'] as $post)
                @include('themes.solqubit.card', ['featuredCard' => false])
            @endforeach
        </div>
    </section>
@endif
