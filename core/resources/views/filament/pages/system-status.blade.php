<x-filament-panels::page>
    @php
        $releases = app(\App\Services\GitHubReleases::class);
        $passedChecks = collect($checks)->where('ok', true)->count();
        $allPassed = $passedChecks === count($checks);
        $timezone = \Filament\Support\Facades\FilamentTimezone::get();
        $newer = $release['status'] === 'ok' && $releases->newer($release);
        $pending = $pendingUpdate && $pendingUpdate['expires'] > time();
        $state = $pending ? 'ready' : ($newer ? 'available' : ($release['status'] === 'ok' ? 'current' : 'unknown'));
    @endphp
    @if($errors->any())<div class="sq-access-result is-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

    <section class="sq-update-status" data-state="{{ $state }}" aria-label="Estado de la versión">
        <div class="sq-update-versions">
            <div><span>Instalada</span><strong>{{ config('solqubit.version') }}</strong></div>
            @if($release['status'] === 'ok')
                <x-heroicon-o-arrow-right aria-hidden="true"/>
                <div><span>Última publicada</span><strong>{{ $release['version'] }}</strong></div>
            @endif
        </div>
        <div class="sq-update-summary">
            <p class="sq-update-headline">
                @if($pending)La actualización a la {{ $pendingUpdate['version'] }} está validada y lista para aplicar.
                @elseif($newer)Solqubit {{ $release['version'] }} está disponible.
                @elseif($release['status'] === 'ok')Tu sitio está al día.
                @elseif($release['status'] === 'disabled')Este sitio no busca versiones nuevas en GitHub.
                @else No se pudo saber si hay una versión nueva.
                @endif
            </p>
            <p>
                PHP {{ PHP_VERSION }}
                @if(isset($release['checked_at']) && $release['status'] !== 'disabled') · Última consulta: {{ \Carbon\Carbon::parse($release['checked_at'])->timezone($timezone)->format('d/m/Y H:i') }}, se repite cada 12 horas.@endif
                @if($lastUpdate) · Última actualización: {{ $lastUpdate['version'] }}, el {{ \Carbon\Carbon::parse($lastUpdate['at'])->timezone($timezone)->format('d/m/Y H:i') }}.@endif
            </p>
        </div>
        <form method="post" action="{{ route('update.check') }}">@csrf
            <x-filament::button type="submit" color="gray" icon="heroicon-o-arrow-path" :disabled="$release['status'] === 'disabled'">Buscar actualizaciones</x-filament::button>
        </form>
    </section>

    @if($pending)
        <x-filament::section :heading="'Lista para aplicar: Solqubit '.$pendingUpdate['version']" icon="heroicon-o-check-circle" icon-color="success">
            <div class="sq-system-notes">
                <div class="sq-access-result is-success" role="status"><x-heroicon-o-check-circle/><span>Integridad, rutas y compatibilidad verificadas. Versión {{ config('solqubit.version') }} → {{ $pendingUpdate['version'] }}.</span></div>
                @if(($pendingUpdate['origin'] ?? 'unverified') === 'github')
                    <p><x-filament::badge color="success" icon="heroicon-o-shield-check">Paquete oficial de github.com/{{ $releases->repository() }}</x-filament::badge></p>
                @else
                    <p><x-filament::badge color="danger" icon="heroicon-o-exclamation-triangle">Paquete sin verificar: permitido por la configuración del servidor</x-filament::badge></p>
                @endif
                <p>{{ $pendingUpdate['files'] }} archivos del sistema · {{ $pendingUpdate['remove'] }} archivos obsoletos para retirar.</p>
                @if($pendingUpdate['changes'])<ul>@foreach($pendingUpdate['changes'] as $change)<li>{{ $change }}</li>@endforeach</ul>@endif
                <form method="post" action="{{ route('update.apply') }}">@csrf
                    <p>El sitio entrará brevemente en mantenimiento. Si algo falla durante la copia, se recupera el código anterior. Los cambios que hayas hecho a mano en los archivos del código se reemplazan.</p>
                    <x-filament::button type="submit" icon="heroicon-o-arrow-path">Aplicar actualización {{ $pendingUpdate['version'] }}</x-filament::button>
                </form>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Cómo actualizar" description="Elige una de las dos formas. Las dos validan el paquete y te muestran los cambios antes de aplicar nada.">
        <div class="sq-update-ways">
            <article class="sq-update-way" @if($release['status'] === 'ok') data-recommended @endif>
                <header>
                    <span class="sq-update-way-icon"><x-heroicon-o-cloud-arrow-down/></span>
                    <div>
                        <h3>Actualizar desde GitHub</h3>
                        <span class="sq-update-way-tag">Recomendado · sin subir archivos</span>
                    </div>
                </header>
                @if($release['status'] === 'disabled')
                    <p>Este sitio no tiene un repositorio de actualizaciones configurado. Usa la opción de subir el ZIP.</p>
                @else
                    <p>El servidor descarga por ti solo el paquete de actualización (<code>{{ $newer ? $releases::updateAsset($release['version']) : 'solqubit-X.Y.Z-actualizacion.zip' }}</code>) de <a href="https://github.com/{{ $releases->repository() }}/releases" target="_blank" rel="noopener">github.com/{{ $releases->repository() }}</a> y comprueba su huella.</p>
                    @if($release['status'] === 'error')
                        <div class="sq-access-result is-error" role="status"><span>{{ $release['message'] }} Puedes subir el ZIP manualmente con la otra opción.</span></div>
                    @elseif($newer)
                        @if($release['notes'])
                            <div><p><strong>Novedades de la {{ $release['version'] }}</strong> · <a href="{{ $release['url'] }}" target="_blank" rel="noopener">ver en GitHub</a></p>
                            <ul>@foreach($release['notes'] as $note)<li>{{ $note }}</li>@endforeach</ul></div>
                        @endif
                        <form method="post" action="{{ route('update.download') }}">@csrf
                            <input type="hidden" name="version" value="{{ $release['version'] }}">
                            <x-filament::button type="submit" icon="heroicon-o-arrow-down-tray">Descargar y validar {{ $release['version'] }}</x-filament::button>
                        </form>
                    @else
                        <p class="sq-update-way-idle"><x-heroicon-o-check-circle/>No hay nada que descargar: ya tienes la última versión.</p>
                    @endif
                @endif
            </article>

            <article class="sq-update-way">
                <header>
                    <span class="sq-update-way-icon"><x-heroicon-o-arrow-up-tray/></span>
                    <div>
                        <h3>Subir el ZIP de actualización</h3>
                        <span class="sq-update-way-tag">Si el servidor no llega a GitHub</span>
                    </div>
                </header>
                <p>Descarga <code>solqubit-X.Y.Z-actualizacion.zip</code> de la release y súbelo sin descomprimir. El ZIP de instalación no sirve aquí. Solo se aceptan paquetes idénticos a los publicados en el repositorio del sitio.</p>
                <form method="post" action="{{ route('update.upload') }}" enctype="multipart/form-data" class="sq-update-form">
                    @csrf
                    <input id="update-package" type="file" name="package" accept=".zip,application/zip" aria-label="ZIP de actualización (.zip)" required>
                    <p>Máximo del CMS: 128 MB. Este servidor permite {{ $uploadLimit }} por archivo y {{ $postLimit }} por solicitud. Si el ZIP supera ese límite, amplíalo desde tu hosting.</p>
                    <x-filament::button type="submit" color="gray" icon="heroicon-o-shield-check">Validar ZIP</x-filament::button>
                </form>
            </article>
        </div>

        <div class="sq-update-keeps">
            <div><strong>Se actualiza</strong><p>El código del CMS, sus dependencias, los recursos de la plantilla y la estructura de la base de datos.</p></div>
            <div><strong>Se conserva</strong><p>Entradas, páginas, usuarios, imágenes, menús, ajustes, la configuración privada y la dirección del panel.</p></div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Requisitos del servidor" :description="$passedChecks.' de '.count($checks).' requisitos correctos.'.($allPassed ? '' : ' Revisa los pendientes con tu proveedor de hosting.')" collapsible :collapsed="$allPassed">
        <ul class="sq-system-checks">
            @foreach($checks as $check)
                <li>
                    <span class="sq-system-check-icon" data-ok="{{ $check['ok'] ? 'yes' : 'no' }}">@if($check['ok'])<x-heroicon-o-check-circle/>@else<x-heroicon-o-exclamation-circle/>@endif</span>
                    <div><strong>{{ $check['name'] }}</strong><p>{{ $check['detail'] }}</p></div>
                    <x-filament::badge :color="$check['ok'] ? 'success' : 'danger'">{{ $check['ok'] ? 'Correcto' : 'Revisar' }}</x-filament::badge>
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <x-filament::section heading="Comprobar los archivos" description="Compara los archivos del CMS con la lista de la versión instalada. Solo informa: no cambia nada." collapsible :collapsed="! session()->has('cms_files_check')">
        <div class="sq-system-notes">
            <form method="post" action="{{ route('update.files') }}">@csrf
                <x-filament::button type="submit" color="gray" icon="heroicon-o-document-magnifying-glass">Comprobar archivos</x-filament::button>
            </form>
            @if(session()->has('cms_files_check'))
                @php($filesCheck = session('cms_files_check'))
                @if($filesCheck === false)
                    <div class="sq-access-result is-error" role="status"><span>Esta instalación no tiene lista de archivos (instalación de desarrollo).</span></div>
                @elseif(! $filesCheck['modified'] && ! $filesCheck['missing'] && ! $filesCheck['extra'])
                    <div class="sq-access-result is-success" role="status"><x-heroicon-o-check-circle/><span>Los {{ $filesCheck['total'] }} archivos coinciden con la versión {{ config('solqubit.version') }}.</span></div>
                @else
                    <div class="sq-access-result is-error" role="status"><span>{{ count($filesCheck['modified']) }} modificados · {{ count($filesCheck['missing']) }} faltan · {{ count($filesCheck['extra']) }} sobran, de {{ $filesCheck['total'] }} archivos de la versión {{ config('solqubit.version') }}. Al actualizar se reemplazan los modificados y los que faltan, y se retiran los que sobran si una versión anterior los incluía.</span></div>
                    @foreach(['modified' => 'Modificados', 'missing' => 'Faltan', 'extra' => 'Sobran'] as $group => $label)
                        @if($filesCheck[$group])
                            <p><strong>{{ $label }}</strong>@if(count($filesCheck[$group]) > 20) (primeros 20)@endif</p>
                            <ul>@foreach(array_slice($filesCheck[$group], 0, 20) as $path)<li><code style="overflow-wrap: anywhere">{{ $path }}</code></li>@endforeach</ul>
                        @endif
                    @endforeach
                @endif
            @endif
        </div>
    </x-filament::section>

    <x-filament::section heading="Ubicación de los archivos" description="Rutas de esta instalación, visibles solo para administradores." collapsible collapsed>
        <div class="sq-system-notes">
            <p><strong>Motor privado del CMS</strong><br><code style="overflow-wrap: anywhere">{{ base_path() }}</code></p>
            <p><strong>Directorio público del sitio</strong><br><code style="overflow-wrap: anywhere">{{ public_path() }}</code></p>
            <p>El motor guarda el código, la configuración y los archivos privados del blog. No lo borres ni lo muevas: el sitio usa esta ruta para funcionar.</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
