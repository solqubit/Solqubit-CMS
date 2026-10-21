<div class="sq-variant-preview" style="@foreach($colors as $token => $hex)--preview-{{ $token }}:{{ $hex }};@endforeach">
    <div class="sq-variant-preview-top"><span>@if($mode === 'light')<x-heroicon-o-sun/> Claro @else<x-heroicon-o-moon/> Oscuro @endif</span><span class="sq-variant-preview-accent">Tu blog</span></div>
    <h3>Ideas para<br><em>compartir.</em></h3>
    <p>Un espacio para lo que quieres contar.</p>
    <div class="sq-variant-preview-card">
        <span class="sq-variant-preview-tag">Una nueva entrada</span>
        <h4>De la idea a la práctica</h4>
        <p>Texto cómodo de leer, con detalles de color que dan personalidad a tu contenido.</p>
        <span class="sq-variant-preview-link">Explorar una idea <span aria-hidden="true">↗</span></span>
        <span class="sq-variant-preview-button">Leer entrada <span aria-hidden="true">→</span></span>
    </div>
    <div class="sq-variant-preview-details"><span>Información</span><span>Una nota</span><span>Destacado</span></div>
</div>
