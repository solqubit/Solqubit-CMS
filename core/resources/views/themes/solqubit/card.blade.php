@php($tone = $post->displayCategory()?->tone ?? 'lilac')
@php($isFeatured = $featuredCard ?? false)
<article class="{{ $isFeatured ? 'lead-story' : 'journal-card' }}" data-tone="{{ $tone }}">
    <a class="{{ $isFeatured ? 'lead-story-link' : 'journal-card-link' }}" href="{{ $post->url() }}" aria-labelledby="title-{{ $post->id }}">
        <div class="{{ $isFeatured ? 'lead-story-cover' : 'journal-card-cover' }}">
            @include('themes.solqubit.cover', ['media' => $post->cover, 'priority' => $isFeatured])
            <span class="cover-topic"><x-blog-icon name="tag"/>{{ $post->displayCategory()?->name ?? 'Blog' }}</span>
        </div>
        <div class="{{ $isFeatured ? 'lead-story-copy' : 'journal-card-copy' }}">
            @if($isFeatured)
                <span class="section-kicker">Entrada destacada</span>
                <h2 id="title-{{ $post->id }}">{{ $post->title }}</h2>
                <p class="lead-story-description">{{ $post->excerpt }}</p>
                <div class="lead-story-meta">
                    <span><x-blog-icon name="clock"/>{{ $post->reading_minutes }} min de lectura</span>
                    @if(filled($post->difficulty))<span>{{ $post->difficulty }}</span>@endif
                </div>
                <span class="read-button">Leer la entrada <x-blog-icon name="arrow"/></span>
            @else
                <div class="journal-meta">
                    <span>{{ $post->displayCategory()?->name ?? 'Blog' }}</span>
                    <span>{{ $post->reading_minutes }} min de lectura</span>
                </div>
                <h3 id="title-{{ $post->id }}">{{ $post->title }}</h3>
                <p>{{ $post->excerpt }}</p>
                <div class="journal-read"><span>Leer</span><x-blog-icon name="arrowUp"/></div>
            @endif
        </div>
    </a>
</article>
