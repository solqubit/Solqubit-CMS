@php($data = $block['data'])
@switch($block['type'])
    @case('heading')
        @if($data['level'] === 'h3')
            <h3 id="{{ $block['anchor'] }}">{{ $data['text'] }}</h3>
        @else
            <h2 id="{{ $block['anchor'] }}">{{ $data['text'] }}</h2>
        @endif
        @break
    @case('paragraph')
        <div class="prose-block">{!! app(\App\Services\ContentSchema::class)->sanitize($data['html']) !!}</div>
        @break
    @case('code')
    @case('output')
        <div class="code-block">
            <button class="copy-button" type="button" data-copy-code aria-label="Copiar código"><x-blog-icon name="copy"/><span>Copiar</span></button>
            <pre tabindex="0" role="region" aria-label="Código"><code>{{ $data['text'] }}</code></pre>
        </div>
        @break
    @case('image')
        @php($media = $mediaItems->get($data['media_id']))
        @if($media)
            @php($imageAlt = (string) ($media->alt_text ?? ''))
            @php($imageSrcset = $media->srcset())
            @php($imageCaption = (string) ($data['caption'] ?? $media->caption ?? ''))
            <figure class="article-figure">
                <button class="article-image-trigger" type="button" data-image-src="{{ $media->url() }}" data-image-alt="{{ $imageAlt }}" data-image-caption="{{ $imageCaption }}" aria-label="{{ filled($imageAlt) ? 'Ampliar imagen: '.$imageAlt : 'Ampliar imagen' }}">
                    <img src="{{ $media->url('hero') }}"@if($imageSrcset !== '') srcset="{{ $imageSrcset }}" sizes="(max-width: 760px) calc(100vw - 40px), 800px"@endif alt="{{ $imageAlt }}"@if($media->width) width="{{ $media->width }}" height="{{ $media->height }}"@endif loading="lazy" decoding="async">
                    <span aria-hidden="true"><x-blog-icon name="search"/>Ampliar</span>
                </button>
                @if(filled($imageCaption))
                    <figcaption>{{ $imageCaption }}</figcaption>
                @endif
            </figure>
        @endif
        @break
    @case('video')
        @include('themes.solqubit.video', ['url' => $data['url'], 'caption' => $data['caption'] ?? 'Video de apoyo'])
        @break
    @case('media_video')
        @php($media = $mediaItems->get($data['media_id']))
        @if($media)
            <figure class="article-video">
                <video controls preload="metadata" aria-label="{{ $data['caption'] ?? $media->original_name }}"><source src="{{ $media->url() }}" type="{{ $media->mime_type }}">Tu navegador no permite reproducir este video. <a href="{{ $media->url() }}?download=1">Descárgalo aquí.</a></video>
                @php($videoCaption = (string) (($data['caption'] ?? '') ?: ($media->caption ?? '')))
                @if(filled($videoCaption))
                    <figcaption>{{ $videoCaption }}</figcaption>
                @endif
            </figure>
        @endif
        @break
    @case('file')
        @php($media = $mediaItems->get($data['media_id']))
        @if($media)
            <div class="attachment">
                <span class="attachment-icon"><x-blog-icon name="file"/></span>
                <div>
                    <strong>{{ ($data['label'] ?? '') ?: $media->original_name }}</strong>
                    <small>{{ $media->original_name }} · {{ number_format($media->size/1024,1) }} KB</small>
                    @if($data['caption'] ?? '')
                        <p>{{ $data['caption'] }}</p>
                    @endif
                </div>
                <a class="attachment-download" href="{{ $media->url() }}?download=1"><x-blog-icon name="download"/><span>Descargar</span><span class="sr-only"> {{ ($data['label'] ?? '') ?: $media->original_name }}</span></a>
            </div>
        @endif
        @break
    @case('table')
        <div class="table-wrap" tabindex="0" role="region" aria-label="Tabla">
            <table class="article-table">
                <thead>
                    <tr>@foreach($data['columns'] as $column)<th scope="col">{{ $column }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @foreach($data['rows'] as $row)
                        <tr>@foreach(array_slice(array_pad(explode('|', $row['cells']), count($data['columns']), ''), 0, count($data['columns'])) as $cell)<td>{!! app(\App\Services\ContentSchema::class)->inline(trim($cell)) !!}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @break
    @case('notice')
        <aside class="article-notice notice-{{ $data['tone'] === 'note' ? 'success' : $data['tone'] }}">
            <x-blog-icon :name="match($data['tone']) { 'note' => 'bulb', 'warning' => 'warning', default => 'info' }"/>
            <div>
                @if($data['title'] ?? '')
                    <strong>{{ $data['title'] }}</strong>
                @endif
                <p>{!! app(\App\Services\ContentSchema::class)->inline($data['text']) !!}</p>
            </div>
        </aside>
        @break
    @case('list')
        <div class="article-summary">
            @if($data['title'] ?? '')
                <p class="article-summary-title">{{ $data['title'] }}</p>
            @endif
            @if($data['ordered'] ?? false)<ol>@else<ul>@endif
                @foreach($data['items'] as $item)
                    <li>{!! app(\App\Services\ContentSchema::class)->inline($item['text']) !!}</li>
                @endforeach
            @if($data['ordered'] ?? false)</ol>@else</ul>@endif
        </div>
        @break
    @case('details')
        <div class="article-faq">
            <details>
                <summary>{{ $data['title'] }}<x-blog-icon name="chevron"/></summary>
                <p>{!! app(\App\Services\ContentSchema::class)->inline($data['text']) !!}</p>
            </details>
        </div>
        @break
    @case('links')
        <ul class="article-references">
            @foreach($data['items'] as $item)
                <li><a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer"><span><strong>{{ $item['label'] }}</strong><span class="sr-only"> (abre en otra pestaña)</span></span><x-blog-icon name="arrowUp"/></a></li>
            @endforeach
        </ul>
        @break
    @case('divider')
        <hr>
        @break
    @default
        @if($preview)
            <p class="article-notice notice-warning">Bloque no compatible con este tema: {{ $block['type'] }}</p>
        @endif
@endswitch
