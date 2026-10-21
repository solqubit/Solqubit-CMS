<x-filament-widgets::widget>
    @if($release)
        <div class="sq-settings-guide" role="status">
            <x-heroicon-o-arrow-up-circle/>
            <div>
                <strong>Solqubit {{ $release['version'] }} está disponible</strong>
                <p>Te recomendamos actualizar. Tu versión: {{ config('solqubit.version') }}.</p>
                @if($release['notes'])<ul>@foreach($release['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul>@endif
                <p><a href="{{ $statusUrl }}">Ver la actualización</a></p>
            </div>
        </div>
    @endif
</x-filament-widgets::widget>
