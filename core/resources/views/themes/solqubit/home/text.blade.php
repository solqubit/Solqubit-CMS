@if(count($section['content']))
    @php($mediaItems = $page->media->keyBy('id'))
    <div class="article-body page-blocks" data-home-section="{{ $index }}">
        @foreach($section['content'] as $blockIndex => $block)
            {{-- The first text keeps the anchors the home had before it was split into sections. --}}
            @php($block['anchor'] = ($index === $firstTextIndex ? 'seccion-' : 'seccion-'.$index.'-').$blockIndex)
            @include('themes.solqubit.block', ['preview' => $preview ?? false])
        @endforeach
    </div>
@endif
