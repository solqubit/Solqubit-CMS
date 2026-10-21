@if($paginator->hasPages())
@php($anchor = $anchor ?? 'entradas')
@php($start = max(1, $paginator->currentPage() - 2))
@php($end = min($paginator->lastPage(), $paginator->currentPage() + 2))
<nav class="pagination" aria-label="Paginación">
<span class="pagination-summary">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
<div class="pagination-controls">
@if($paginator->onFirstPage())<span class="pagination-disabled" aria-disabled="true">← Anterior</span>@else<a rel="prev" href="{{ $paginator->previousPageUrl() }}#{{ $anchor }}">← Anterior</a>@endif
@if($start > 1)<a href="{{ $paginator->url(1) }}#{{ $anchor }}" aria-label="Ir a la página 1">1</a>@if($start > 2)<span aria-hidden="true">…</span>@endif @endif
@foreach($paginator->getUrlRange($start, $end) as $number => $url)
@if($number === $paginator->currentPage())<span class="pagination-current" aria-current="page" aria-label="Página {{ $number }}">{{ $number }}</span>@else<a href="{{ $url }}#{{ $anchor }}" aria-label="Ir a la página {{ $number }}">{{ $number }}</a>@endif
@endforeach
@if($end < $paginator->lastPage())@if($end < $paginator->lastPage() - 1)<span aria-hidden="true">…</span>@endif<a href="{{ $paginator->url($paginator->lastPage()) }}#{{ $anchor }}" aria-label="Ir a la página {{ $paginator->lastPage() }}">{{ $paginator->lastPage() }}</a>@endif
@if($paginator->hasMorePages())<a rel="next" href="{{ $paginator->nextPageUrl() }}#{{ $anchor }}">Siguiente →</a>@else<span class="pagination-disabled" aria-disabled="true">Siguiente →</span>@endif
</div></nav>
@endif
