@extends('themes.solqubit.layout')
@section('content')
@php($mediaItems = $post->media->keyBy('id'))
@php($authorName = $post->author?->name ?: 'Autor')
@php($authorInitials = \Illuminate\Support\Str::of($authorName)->squish()->explode(' ')->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode(''))
@php($displayCategory = $post->displayCategory())
<div class="container article-shell" data-tone="{{ $displayCategory?->tone ?? 'lilac' }}">
    <nav class="breadcrumbs" aria-label="Ruta de navegación">
        <ol>
            <li><a href="{{ route('home') }}">Blog</a></li>
            @if($displayCategory)
                <li><x-blog-icon name="chevron"/><a href="{{ route('home', ['category' => $displayCategory->slug]) }}">{{ $displayCategory->name }}</a></li>
            @endif
            <li class="breadcrumbs-current"><x-blog-icon name="chevron"/><span aria-current="page">{{ \Illuminate\Support\Str::limit($post->title, 60) }}</span></li>
        </ol>
    </nav>
    <article class="article-entry" aria-labelledby="article-title">
        <header class="article-intro">
            <div class="article-hero-copy">
                <div class="feature-labels">
                    @forelse($post->categories as $category)
                        <a class="pill" href="{{ route('home', ['category' => $category->slug]) }}">{{ $category->name }}</a>
                    @empty
                        <span class="pill"><x-blog-icon name="tag"/>Blog</span>
                    @endforelse
                    @if(filled($post->difficulty))<span class="eyebrow">{{ $post->difficulty }}</span>@endif
                </div>
                <h1 id="article-title">{{ $post->title }}</h1>
                @if(filled($post->excerpt))
                    <p class="article-lead">{{ $post->excerpt }}</p>
                @endif
                <div class="article-byline">
                    <div class="author">
                        @if($post->author?->avatar)
                            <img class="author-avatar" src="{{ $post->author->avatar->url('avatar') }}" alt="" width="40" height="40">
                        @else
                            <span class="author-avatar" aria-hidden="true">{{ $authorInitials }}</span>
                        @endif
                        <div class="author-details">
                            <strong>Por {{ $authorName }}</strong>
                            <div class="article-publication">
                                <time datetime="{{ ($post->published_at ?? $post->created_at)->toIso8601String() }}">{{ ($post->published_at ?? $post->created_at)->timezone($site['timezone'] ?? 'UTC')->translatedFormat('j M Y') }}</time>
                                <span class="article-reading-time"><x-blog-icon name="clock"/>{{ $post->reading_minutes }} min de lectura</span>
                            </div>
                            @if($post->show_updated_at && $post->content_updated_at && $post->content_updated_at->gt($post->published_at ?? $post->created_at))
                                <small>Actualizada el {{ $post->content_updated_at->timezone($site['timezone'] ?? 'UTC')->translatedFormat('j M Y') }}</small>
                            @endif
                        </div>
                    </div>
                    <button class="article-tool" type="button" data-share aria-label="Compartir: copiar enlace de la entrada"><x-blog-icon name="link"/><span>Compartir</span></button>
                </div>
                @if($post->author?->bio)
                    <p class="author-bio">{{ $post->author->bio }}</p>
                @endif
            </div>
            <figure class="article-cover">
                @include('themes.solqubit.cover', ['media' => $post->cover, 'priority' => true])
                @if($post->cover?->caption)
                    <figcaption>{{ $post->cover->caption }}</figcaption>
                @endif
            </figure>
        </header>
        <div class="article-layout">
            <div class="article-body">
                @if(count($toc))
                    <details class="mobile-toc">
                        <summary>En esta entrada</summary>
                        @include('themes.solqubit.toc')
                    </details>
                @endif
                @include('themes.solqubit.ad-slot', ['placement' => 'article_top'])
                @foreach($blocks as $block)
                    @include('themes.solqubit.block')
                @endforeach
                @include('themes.solqubit.ad-slot', ['placement' => 'article_bottom'])
                <div class="article-end">
                    <div class="article-tags">
                        @foreach($post->tags as $tag)
                            <a class="pill" href="{{ route('home', ['q' => $tag->name]) }}"># {{ $tag->name }}</a>
                        @endforeach
                    </div>
                    <button class="text-button" type="button" data-share><x-blog-icon name="link"/>Compartir entrada</button>
                </div>
            </div>
            @if(count($toc))
                <aside class="article-aside" aria-labelledby="toc-title">
                    <div class="toc-title" id="toc-title"><x-blog-icon name="layers"/>En esta entrada</div>
                    @include('themes.solqubit.toc')
                </aside>
            @endif
        </div>
    </article>
    @if($related)
        <section class="article-related" aria-labelledby="siguiente">
            <h2 id="siguiente">Continúa leyendo</h2>
            <a class="next-article" data-tone="{{ $related->displayCategory()?->tone ?? 'lilac' }}" href="{{ $related->url() }}">
                <span class="next-cover">@include('themes.solqubit.cover', ['media' => $related->cover, 'priority' => false])</span>
                <div>
                    <small>{{ $related->displayCategory()?->name }}</small>
                    <strong>{{ $related->title }}</strong>
                </div>
                <x-blog-icon name="arrow"/>
            </a>
        </section>
    @endif
</div>
@endsection
