<x-filament-widgets::widget>
    <x-filament::section :heading="$heading">
        @if($rows === [])
            <p class="sq-ranking-empty">Todavía no hay visitas en este periodo.</p>
        @else
            <div class="sq-ranking-head" aria-hidden="true"><span>{{ $column }}</span><span>Visitantes</span></div>
            <ol class="sq-ranking">
                @foreach($rows as $row)
                    <li class="sq-ranking-row" style="--sq-share: {{ $row['bar'] }}%">
                        <span class="sq-ranking-label">
                            @if($row['prefix'])<span class="sq-ranking-prefix" aria-hidden="true">{{ $row['prefix'] }}</span>@endif
                            <span class="sq-ranking-text">
                                @if($row['url'])<a href="{{ $row['url'] }}" target="_blank" rel="noopener">{{ $row['label'] }}</a>@else<span>{{ $row['label'] }}</span>@endif
                                @if($row['detail'])<small>{{ $row['detail'] }}</small>@endif
                            </span>
                        </span>
                        <span class="sq-ranking-value" title="{{ number_format($row['views'], 0, ',', '.') }} páginas vistas">
                            <strong>{{ number_format($row['visitors'], 0, ',', '.') }}</strong><small>{{ $row['share'] }} %</small>
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif
        @if($note)<p class="sq-ranking-note">{{ $note }}</p>@endif
    </x-filament::section>
</x-filament-widgets::widget>
