@if(($monetization['enabled'] ?? false) && filled($monetization['slots'][$placement] ?? null))
<aside class="ad-placement" aria-label="Publicidad" data-ad-placement="{{ $placement }}">
    <span class="ad-placement-label">Publicidad</span>
    <ins class="adsbygoogle" style="display:block" data-ad-client="{{ $monetization['publisher_id'] }}" data-ad-slot="{{ $monetization['slots'][$placement] }}" data-ad-format="auto" data-full-width-responsive="true"></ins>
</aside>
@endif
