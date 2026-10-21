<div class="sq-access-blocks" wire:poll.15s.visible>
    @php($blocks = app(\App\Services\AdminAccess::class)->activeBlocks())
    <h3>Bloqueos automáticos activos</h3>
    <p class="sq-access-help">Se actualizan cada 15 segundos y desaparecen al vencer. Se muestran hasta 100 bloqueos. Retirar uno aquí no elimina un bloqueo manual.</p>
    @if($blocks->isEmpty())
        <div class="sq-access-result is-success"><x-filament::icon icon="heroicon-o-check-circle" /><span>No hay bloqueos automáticos activos.</span></div>
    @else
        <div class="sq-access-table-wrap">
            <table class="sq-access-table">
                <caption class="sr-only">Accesos bloqueados temporalmente por exceso de intentos</caption>
                <thead><tr><th scope="col">IP y correo</th><th scope="col">Motivo</th><th scope="col">Se libera en</th><th scope="col">Acción</th></tr></thead>
                <tbody>
                    @foreach($blocks as $block)
                        <tr wire:key="block-{{ $block->key }}">
                            <td><strong>{{ $block->ip }}</strong>@if($block->email)<small>{{ $block->email }}</small>@else<small>Todos los correos desde esta IP</small>@endif</td>
                            <td>{{ match($block->scope) { 'account' => 'Contraseña incorrecta para este correo', 'ip' => 'Demasiados fallos desde esta IP', default => 'Demasiadas solicitudes de acceso' } }}<small>{{ $block->attempts }} {{ $block->scope === 'requests' ? 'solicitudes' : 'intentos fallidos' }}</small></td>
                            <td>{{ max(1, (int) ceil(now()->diffInSeconds($block->expires_at) / 60)) }} min</td>
                            <td><x-filament::button type="button" size="sm" color="gray" wire:click="unblock('{{ $block->key }}')" wire:loading.attr="disabled" aria-label="Desbloquear {{ $block->ip }} {{ $block->email }}">Desbloquear</x-filament::button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
