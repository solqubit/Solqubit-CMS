<div>
    <p class="sq-access-help">Puedes probar antes de guardar. El resultado confirma la aceptación del mensaje por el servidor; comprueba también que llegue a tu buzón.</p>
    <p wire:loading wire:target="callMountedAction,testSmtp" class="sq-access-help" role="status">Comprobando la conexión y enviando el correo…</p>
    @if($this->smtpTestResult)
        <div class="sq-access-result {{ $this->smtpTestResult['ok'] ? 'is-success' : 'is-error' }}" role="status">
            <x-filament::icon :icon="$this->smtpTestResult['ok'] ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-circle'" />
            <span>{{ $this->smtpTestResult['message'] }}</span>
        </div>
    @endif
</div>
