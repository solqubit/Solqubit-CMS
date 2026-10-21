@php($media = $getRecord())
<div class="sq-media-details">
    @if($media->isImage())<img src="{{ $media->url('hero') }}" alt="{{ $media->alt_text }}" style="max-height:280px;max-width:100%;border-radius:12px;object-fit:contain">@endif
    <p><strong>Dirección del archivo</strong><br><code style="overflow-wrap:anywhere;user-select:all">{{ $media->url() }}</code></p>
    @php($usages = $media->usages())
    <p><strong>Usado en</strong></p>
    @if($usages)
        <ul style="list-style:disc;padding-left:20px">@foreach($usages as $usage)<li>@if($usage['url'])<a class="underline" href="{{ $usage['url'] }}">{{ $usage['label'] }}</a>@else{{ $usage['label'] }}@endif</li>@endforeach</ul>
    @else
        <p>No se usa en ningún lugar. Puedes eliminarlo si ya no lo necesitas.</p>
    @endif
</div>
