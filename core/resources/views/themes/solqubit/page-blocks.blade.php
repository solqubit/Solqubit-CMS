@if(count($page->content ?? []))
@php($mediaItems = $page->media->keyBy('id'))
<div class="article-body page-blocks">
@foreach($page->content as $index => $block)
@php($block['anchor'] = 'seccion-'.$index)
@include('themes.solqubit.block', ['preview' => $preview ?? false])
@endforeach
</div>
@endif
