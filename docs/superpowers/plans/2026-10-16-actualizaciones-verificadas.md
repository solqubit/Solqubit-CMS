# Actualizaciones desde GitHub: plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aviso y actualización desde GitHub, origen verificado de todo ZIP, retirada de archivos obsoletos en cualquier salto de versión y un ZIP más ligero.

**Architecture:** Tres servicios nuevos en `core/app/Services/`:
- `ReleaseFiles`: la lista de archivos de la versión y qué se retira;
- `GitHubReleases`: consulta, huella y descarga;
- `PackageOrigin`: decide si un ZIP se acepta.

`ZipUpdater` y `UpdateController` los usan. `build-release.php` genera `core/resources/solqubit/files.json` y aligera el paquete. La interfaz va en la página `SystemStatus` y en un widget del escritorio.

**Tech Stack:** Laravel 13, Filament 5, PHPUnit 12, cliente HTTP de Laravel (Guzzle), Docker para las pruebas de instalación.

**Spec:** `docs/superpowers/specs/2026-10-16-actualizaciones-verificadas-design.md`

## Global Constraints

- Textos visibles en español; archivos con LF.
- No se modifican migraciones existentes; nunca se retira nada de `core/database/migrations/`.
- `core/bootstrap/update-guard.php` no se toca.
- No se añaden dependencias.
- Repositorio por defecto `solqubit/Solqubit-CMS`, validado con `^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$`.
- Límite de paquete: `UpdatePackage::MAX_BYTES` (128 MB).
- Caché de la última versión: 12 h si va bien, 1 h si falla. Consulta de la API: 8 s de espera. Descarga del `.sha256`: 10 s y 1 KB como máximo. Descarga del ZIP: 300 s.
- Las pruebas nunca salen a la red: `Http::preventStrayRequests()` en `Tests\TestCase::setUp()`.
- PHP: tipos explícitos, llaves siempre y PHPDoc con array shapes (reglas de `core/CLAUDE.md`).
- Pruebas PHP: `MSYS_NO_PATHCONV=1 docker exec solqubit-cms-web-1 sh -c 'cd /var/www/core && php -d memory_limit=1G vendor/bin/phpunit --colors=never <filtro>'`.
- Pint: `vendor/bin/pint <archivos> --format agent` dentro del contenedor.

## Review Focus

- GitHub lento o inalcanzable: el escritorio, el menú y Estado del sistema cargan igual; el fallo se guarda 1 h y no se repite la consulta en cada página (Tarea 4).
- `update_repository` con valor raro (`../x`, una URL, vacío): no se hace ninguna petición y GitHub queda desactivado (Tarea 3).
- Tag no semántico (`v1.3.0-beta`, `latest`): no cuenta como versión nueva (Tarea 4).
- El `.sha256` llega mal formado o la «descarga» es una página HTML: se rechaza sin instalar nada (Tareas 3 y 5).
- `files.json` de otra versión (por ejemplo, tras una recuperación) o con rutas no permitidas: se ignora y no se borra nada fuera de `remove` (Tarea 1).

---

### Task 1: Lista de archivos y retirada de obsoletos

**Files:**
- Create: `core/app/Services/ReleaseFiles.php`
- Modify: `core/app/Services/ZipUpdater.php` (`apply()`)
- Modify: `core/app/Http/Controllers/UpdateController.php` (`upload()`: contar `remove` con `obsolete()`)
- Modify: `scripts/build-release.php` (calcular `files` antes del ZIP del motor y escribir `files.json`)
- Test: `core/tests/Feature/ZipUpdateTest.php`

**Interfaces:**
- Produces:
  - `ReleaseFiles::LIST = 'resources/solqubit/files.json'` (relativa a la raíz del motor).
  - `ReleaseFiles::installed(): ?array`, que devuelve `array<string,string>` de ruta a sha256 o `null`. Lee `config('solqubit.update_core', base_path()).'/'.self::LIST`. Devuelve `null` si falta, si el JSON no es válido, si `version` ≠ `config('solqubit.version')`, si alguna ruta no cumple `UpdatePackage::allowedPath()` o si algún hash no es hexadecimal de 64 caracteres.
  - `ReleaseFiles::obsolete(array $manifest): array`, que devuelve `list<string>` ordenada y sin repetidos. Algoritmo:

```php
$new = $manifest['files'];
$candidates = array_unique([...array_keys($this->installed() ?? []), ...($manifest['remove'] ?? [])]);
// keep $path when: is_string, UpdatePackage::allowedPath($path), !str_starts_with($path, 'core/database/migrations/'),
// !isset($new[$path]), no key in $new starts with $path.'/', and is_file(app(ZipUpdater::class)->target($path))
```

  - `ReleaseFiles::pruneEmptyDirectories(array $removed): void` recibe `list<string>`. Por cada ruta retirada, sube por las carpetas padre con `rmdir` mientras estén vacías. Se detiene en la raíz permitida: la carpeta del primer nivel tras `core/` o `site/` (`core/app`, `core/vendor`, `site/js`…) y, para `site/vendor/livewire`, en `site/vendor/livewire`.
  - `files.json` tiene la forma `{"version": "X.Y.Z", "files": {"core/...": "<sha256>", ...}}` y no se incluye a sí mismo.

- [ ] **Step 1: Escribir las pruebas que fallan** en `ZipUpdateTest`. Un helper `installedList(array $files, string $version = '1.1.0')` escribe `$this->work.'/core/resources/solqubit/files.json'`.

```php
public function test_obsolete_files_from_skipped_versions_are_removed(): void
// installed list: core/app/Old.php, core/app/Kept.php, core/vendor/pkg/src/Gone.php, site/js/filament/old.js,
//   core/database/migrations/2020_01_01_000000_x.php (all exist on disk)
// package(['core/app/Kept.php' => 'k', 'core/app/New.php' => 'n']) applied via ZipUpdater::apply
// assert Old.php, Gone.php, old.js missing; migration still exists; New.php = 'n';
// assert directory core/vendor/pkg missing and core/vendor still exists;
// assert transaction 'written' has 'core/app/Old.php' => true and rollback/core/app/Old.php exists

public function test_installed_list_for_another_version_or_with_unsafe_paths_is_ignored(): void
// #[TestWith(['1.0.9', 'core/app/Old.php'])] #[TestWith(['1.1.0', 'core/.env'])]
// list (version, [$path, 'core/app/Old.php']); after apply core/app/Old.php still exists (and core/.env if written)

public function test_a_former_file_that_became_a_directory_is_not_removed(): void
// installed list has core/app/Thing (file on disk deleted beforehand, directory not yet present)
// package has core/app/Thing/Inner.php; apply succeeds and Inner.php exists

public function test_preview_counts_obsolete_files_from_the_installed_list(): void
// installed list with 2 obsolete existing files; upload package(); assertSessionHas('cms_update.remove', 2)
```

- [ ] **Step 2: Ejecutar** `--filter 'obsolete|former_file|installed_list'` → FAIL (falta la clase o la retirada).
- [ ] **Step 3: Implementar `ReleaseFiles`.** En `ZipUpdater::apply()`, `$remove = app(ReleaseFiles::class)->obsolete($manifest)` sustituye a `$manifest['remove']` al calcular `$paths`. Tras el bucle de copia y retirada se llama a `pruneEmptyDirectories($remove)`. En `UpdateController::upload()`, `'remove' => count(app(ReleaseFiles::class)->obsolete($manifest))`.
- [ ] **Step 4: Ejecutar `ZipUpdateTest` completo** → PASS (las pruebas antiguas también).
- [ ] **Step 5: Cambiar `build-release.php`.** Mover el cálculo de `$manifest['files']` antes de crear `engine.zip`, escribir `$core/resources/solqubit/files.json` (JSON con `JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES`) y añadir su propia entrada a `$manifest['files']` antes de `ksort`. Comprobar con `node scripts/build-release.mjs` que el ZIP contiene `core/resources/solqubit/files.json` en el motor y en el manifiesto.
- [ ] **Step 6: Pint y commit** con `Retirar archivos obsoletos en cualquier salto de versión`.

### Task 2: Comprobar archivos instalados

**Files:**
- Modify: `core/app/Services/ReleaseFiles.php`
- Modify: `core/app/Http/Controllers/UpdateController.php` (nuevo `verifyFiles()`)
- Modify: `core/routes/web.php` (al lado de `update.upload`: `POST /system-status/files` → `update.files`, `throttle:3,1`)
- Modify: `core/resources/views/filament/pages/system-status.blade.php`
- Test: `core/tests/Feature/ZipUpdateTest.php`

**Interfaces:**
- Produces: `ReleaseFiles::check(): ?array`, con la forma `array{modified: list<string>, missing: list<string>, extra: list<string>, total: int}` o `null` si `installed()` es `null`. `extra` busca solo en `core/app`, `core/config`, `core/routes`, `core/vendor`, `site/js/filament`, `site/css/filament`, `site/fonts/filament` y `site/vendor/livewire`, sin seguir enlaces simbólicos. Las listas van completas; la vista muestra las 20 primeras de cada una.
- El controlador guarda el resultado con `session()->flash('cms_files_check', $result ?? false)` y redirige a `SystemStatus::getUrl()`. La vista añade a la sección «Instalación y compatibilidad» el formulario «Comprobar archivos» y el resultado: «Todos los archivos coinciden con la versión X» o los tres grupos con sus contadores. Si es `false`: «Esta instalación no tiene lista de archivos (instalación de desarrollo).».

- [ ] **Step 1: Prueba que falla** `test_admin_can_check_installed_files_against_the_release_list`. La lista tiene `core/app/A.php` (igual), `core/app/B.php` (contenido distinto) y `core/app/C.php` (no existe); en disco hay además `core/app/Extra.php`. POST `update.files` y `assertSessionHas('cms_files_check')` con `modified=['core/app/B.php']`, `missing=['core/app/C.php']`, `extra=['core/app/Extra.php']` y `total=3`. Un editor recibe 403.
- [ ] **Step 2: Ejecutar** → FAIL.
- [ ] **Step 3: Implementar** `check()`, la ruta, la acción y la vista.
- [ ] **Step 4: Ejecutar** → PASS.
- [ ] **Step 5: Commit** con `Comprobar los archivos instalados desde Estado del sistema`.

### Task 3: Origen verificado contra GitHub

**Files:**
- Create: `core/app/Services/GitHubReleases.php` (en esta tarea, solo `repository()` y `checksum()`)
- Create: `core/app/Services/GitHubUnavailable.php` (`class GitHubUnavailable extends \RuntimeException {}`)
- Create: `core/app/Services/PackageOrigin.php`
- Modify: `core/config/solqubit.php`
- Modify: `core/app/Http/Controllers/UpdateController.php`
- Modify: `core/app/Services/ZipUpdater.php` (`apply()` recibe el origen y `finish()` lo registra)
- Modify: `scripts/build-release.php` y `scripts/build-release.mjs` (anotar el repositorio)
- Modify: `core/tests/TestCase.php` (`setUp()` con `Http::preventStrayRequests()`)
- Modify: `core/resources/views/filament/pages/system-status.blade.php` (etiqueta de origen en el bloque pendiente)
- Test: `core/tests/Feature/PackageOriginTest.php` (nuevo) y `ZipUpdateTest.php`, cuyo `setUp` añade `'solqubit.update_repository' => ''` y `'solqubit.allow_unverified' => true`.

**Interfaces:**
- Config:
  - `'update_repository' => env('SOLQUBIT_UPDATE_REPOSITORY')`, `null` si no está definido;
  - `'allow_unverified' => (bool) env('SOLQUBIT_ALLOW_UNVERIFIED', false)`.
- `GitHubReleases::SOURCE = 'resources/solqubit/source.php'`, relativa a `config('solqubit.update_core', base_path())`.
- `GitHubReleases::repository(): ?string` toma el primer valor disponible:
  1. `config('solqubit.update_repository')` si no es `null`;
  2. `(require SOURCE)['repository']` si el archivo existe;
  3. `'solqubit/Solqubit-CMS'`.

  Devuelve el valor si cumple la expresión de las restricciones globales y `null` si no (también si está vacío).
- `build-release.php` escribe `$core/resources/solqubit/source.php`, con el contenido `<?php return ['repository' => '<repo>'];` generado con `var_export`, y `$manifest['repository']`. Toma el primero de:
  - `getenv('SOLQUBIT_UPDATE_REPOSITORY')`;
  - `getenv('GITHUB_REPOSITORY')`;
  - `git remote get-url origin` (si `git` existe), con `github\.com[:/]([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(\.git)?$`;
  - `''`, con el aviso «Aviso: el paquete no tiene repositorio de actualizaciones…».

  Lo hace antes de calcular `files` (Tarea 1), para que el archivo quede en el manifiesto. `build-release.mjs` calcula el mismo valor en el equipo (variable de entorno o `git remote get-url origin`) y lo pasa con `-e SOLQUBIT_UPDATE_REPOSITORY=...`.
- `GitHubReleases::assetUrl(string $version, string $file): string` devuelve `https://github.com/{repo}/releases/download/v{version}/{file}`.
- `GitHubReleases::checksum(string $version): ?string`:
  - pide `solqubit-{version}.zip.sha256` con 10 s de espera;
  - en 404 devuelve `null`;
  - en error de conexión o en cualquier otro estado que no sea 2xx lanza `GitHubUnavailable`;
  - si el cuerpo pasa de 1024 bytes o no empieza por `[a-f0-9]{64}`, devuelve `null`.
- `PackageOrigin::verify(string $zip, string $version): string` devuelve `'github'` o `'unverified'`, o lanza `\RuntimeException` con uno de estos textos exactos:
  - Sin repositorio y sin permiso: «Este sitio no tiene un repositorio de actualizaciones configurado. Para instalar un ZIP sin verificar, el responsable del servidor debe añadir SOLQUBIT_ALLOW_UNVERIFIED=true al archivo .env.»
  - No coincide, o 404: «Este ZIP no coincide con la versión {v} publicada en github.com/{repo}. Descárgalo de nuevo desde la release oficial.»
  - `GitHubUnavailable`: «No se pudo contactar con GitHub para comprobar el origen del ZIP. Inténtalo más tarde. Si tu hosting bloquea las conexiones externas, el responsable del servidor puede permitir ZIP sin verificar con SOLQUBIT_ALLOW_UNVERIFIED=true en el archivo .env.»
  - Con `allow_unverified`, cualquiera de los tres casos devuelve `'unverified'` en vez de lanzar.
- `ZipUpdater::apply(string $zip, string $resumeToken, int $actor, string $origin = 'unverified'): void` guarda `$state['origin']`. `finish()` crea el `AuditEvent` con `'metadata' => ['from' => $state['from'], 'origin' => $state['origin'] ?? 'unverified']`.
- La sesión `cms_update` lleva la clave `'origin'`.
- Etiquetas en la vista: «Paquete oficial de github.com/{repo}» (color `success`) y «Paquete sin verificar: permitido por la configuración del servidor» (color `danger`).

- [ ] **Step 1: Pruebas que fallan** en `PackageOriginTest`:

```php
test_zip_matching_the_release_checksum_is_official()        // Http::fake([...'.sha256' => hash.'  solqubit-1.1.1.zip']) → 'github'
test_zip_not_matching_the_release_is_rejected()             // other hash → RuntimeException 'no coincide'
test_missing_release_is_rejected()                          // 404 → 'no coincide'
test_unreachable_github_explains_the_server_option()        // Http::fake(fn () => throw new ConnectionException) → 'No se pudo contactar'
test_malformed_checksum_is_treated_as_a_mismatch()          // body '<html>' → 'no coincide'
test_invalid_repository_never_calls_github()                // #[TestWith(['../x'])] #[TestWith(['https://evil.test/a'])] #[TestWith([''])] → 'no tiene un repositorio'; Http::assertNothingSent()
test_repository_comes_from_env_then_package_source_then_default() // update_repository null + source.php 'fork/repo' under update_core → 'fork/repo'; config 'own/x' wins; no file → 'solqubit/Solqubit-CMS'; source.php '' → null
test_server_flag_allows_unverified_packages()              // allow_unverified=true + mismatch → 'unverified'
test_upload_records_the_origin_and_rejects_foreign_zips()  // route update.upload; official → session cms_update.origin 'github'; mismatch → errors, file deleted
```

- [ ] **Step 2: Ejecutar** → FAIL.
- [ ] **Step 3: Implementar** los servicios, la configuración, `TestCase` y el controlador. `upload()` llama a `verify()` después de `inspect()` y borra el ZIP si falla. `apply()` del controlador pasa `$pending['origin']`. Después, la vista.
- [ ] **Step 4: Ejecutar** `PackageOriginTest` y `ZipUpdateTest` → PASS. Añadir a `ZipUpdateTest` una aserción de que `audit_events.metadata` contiene `origin` tras `finish()`.
- [ ] **Step 5: Commit** con `Aceptar solo ZIP publicados en el repositorio configurado`.

### Task 4: Aviso de versión nueva

**Files:**
- Modify: `core/app/Services/GitHubReleases.php`
- Create: `core/app/Filament/Widgets/UpdateAvailable.php`
- Create: `core/resources/views/filament/widgets/update-available.blade.php`
- Modify: `core/app/Filament/Pages/SystemStatus.php` (insignia y datos para la vista)
- Modify: `core/app/Http/Controllers/UpdateController.php` (`check()`)
- Modify: `core/routes/web.php` (`POST /system-status/check` → `update.check`, `throttle:1,1`)
- Modify: `core/resources/views/filament/pages/system-status.blade.php` (sección «Actualizaciones» al principio; se cambia la frase «La descarga desde un servidor remoto queda pendiente; no hay consultas automáticas.»)
- Test: `core/tests/Feature/UpdateNoticeTest.php`

**Interfaces:**
- `GitHubReleases::CACHE_KEY = 'solqubit.latest-release'`.
- `GitHubReleases::latest(bool $refresh = false): array`. Devuelve `array{status: 'ok'|'error'|'disabled', version?: string, url?: string, published_at?: string, notes?: list<string>, message?: string, checked_at: string}`.
  - Sin repositorio devuelve `disabled` y no consulta.
  - Si no, `GET https://api.github.com/repos/{repo}/releases/latest` con `Accept: application/vnd.github+json`, `User-Agent: Solqubit/{versión}` y 8 s de espera.
  - `ok` exige `tag_name` con `^v(\d+\.\d+\.\d+)$`. `notes` son las líneas `- ` de `body` antes del primer `###`, sin el `- `, como mucho 20. Cualquier otro caso devuelve `error` con `message` en español.
  - Se guarda con `Cache::put`: 12 h si es `ok`, 1 h si es `error`.
- `GitHubReleases::cached(): ?array` lee solo la caché.
- `GitHubReleases::newer(?array $release): bool` es cierto si `status === 'ok'` y la versión es mayor que `config('solqubit.version')`.
- Widget `UpdateAvailable`:
  - `$isLazy = true`, `$sort = -20` y `columnSpan` `'full'`;
  - `canView()` exige administrador y `newer(latest())`;
  - texto: «Solqubit {X} está disponible», «Te recomendamos actualizar. Tu versión: {actual}.», las novedades y el botón «Ver la actualización», que enlaza a `SystemStatus::getUrl()`.
- `SystemStatus::getNavigationBadge(): ?string` devuelve «Nueva versión» si `newer(cached())`, y si no `null`. El color de la insignia es `warning`.
- `UpdateController::check()` llama a `latest(true)` y redirige a `SystemStatus`.

- [ ] **Step 1: Pruebas que fallan** en `UpdateNoticeTest`:

```php
test_newer_release_is_detected_and_cached()          // fake api JSON tag v1.3.0, body "- Uno\n- Dos\n### Instalar\n- no"; latest()['notes']===['Uno','Dos']; 2nd call → Http::assertSentCount(1)
test_failures_are_cached_for_an_hour()               // ConnectionException → status error; travel 59 min → no new request; travel 61 min → new request
test_non_semantic_tags_are_not_updates()             // #[TestWith(['v1.3.0-beta'])] #[TestWith(['latest'])] → newer() false
test_admin_sees_notice_and_badge_editor_does_not()   // admin GET /admin shows 'Solqubit 1.3.0 está disponible' via Livewire::test(UpdateAvailable::class); SystemStatus::getNavigationBadge() === 'Nueva versión'; editor: UpdateAvailable::canView() false
test_check_button_refreshes_the_cache()              // POST update.check → new request sent, redirect
test_navigation_badge_never_calls_github()           // empty cache; getNavigationBadge() null; Http::assertNothingSent()
```

- [ ] **Step 2: Ejecutar** → FAIL.
- [ ] **Step 3: Implementar** el servicio, el widget, la insignia, la ruta y las vistas. La sección «Actualizaciones» muestra:
  - versión instalada y última publicada, y «Última consulta: d/m/Y H:i»;
  - si hay una nueva: las novedades, el enlace «Ver en GitHub» y el formulario de la Tarea 5. En esta tarea, el botón apunta a una ruta que se crea en la Tarea 5, así que se envuelve en `@if(Route::has('update.download'))`;
  - si hay `error`: el mensaje y «Puedes subir el ZIP manualmente más abajo.»;
  - si está `disabled`: «Este sitio no tiene un repositorio de actualizaciones configurado.»
- [ ] **Step 4: Ejecutar** → PASS.
- [ ] **Step 5: Commit** con `Avisar de nuevas versiones publicadas en GitHub`.

### Task 5: Descargar y validar desde GitHub

**Files:**
- Modify: `core/app/Services/GitHubReleases.php` (`download()`)
- Modify: `core/app/Http/Controllers/UpdateController.php` (`download()`; la preparación de la sesión, compartida con `upload()`, pasa a un método privado `prepare(string $id, string $path, Request $request, string $origin): RedirectResponse`)
- Modify: `core/routes/web.php` (`POST /system-status/download` → `update.download`, `throttle:3,1`)
- Modify: `core/resources/views/filament/pages/system-status.blade.php` (quitar el `@if(Route::has(...))`)
- Test: `core/tests/Feature/UpdateNoticeTest.php`

**Interfaces:**
- `GitHubReleases::download(string $version, string $target): void`:
  - hace `GET assetUrl($version, "solqubit-{$version}.zip")` con `withOptions(['stream' => true])`, 300 s de espera y 10 s para conectar;
  - copia el cuerpo a `$target` por bloques de 1 MB;
  - lanza `\RuntimeException('La descarga supera el tamaño máximo de 128 MB.')` si `Content-Length` o los bytes leídos pasan de `UpdatePackage::MAX_BYTES`, y borra `$target`;
  - en error de red o estado que no sea 2xx, lanza `GitHubUnavailable`.
- `UpdateController::download(Request $request, GitHubReleases $releases, UpdatePackage $packages, ZipUpdater $updater)`:
  - exige administrador;
  - valida `version` con `^\d+\.\d+\.\d+$`, que sea igual a `latest()['version']` y que `newer()` sea cierto; si no, error «Busca actualizaciones de nuevo antes de descargar.»;
  - pide `checksum()` antes de descargar; si es `null`, error «La release no publica la huella del ZIP.»;
  - descarga, compara con `hash_equals` y, si no coincide, borra el archivo y da error «El ZIP descargado no coincide con su huella publicada.»;
  - después `inspect()` y `prepare(..., 'github')`;
  - todos los errores salen como `ValidationException` sobre la clave `package`.

- [ ] **Step 1: Pruebas que fallan**:

```php
test_admin_downloads_validates_and_prepares_the_latest_release()   // fake api v1.1.1, .sha256 = hash(zip), zip = ZipUpdateTest-style unified package bytes → session cms_update.origin 'github', version '1.1.1'
test_download_with_wrong_checksum_is_discarded()                   // sha mismatch → errors, packages dir has no zip
test_download_rejects_versions_that_are_not_the_newer_release()    // version '9.9.9' → errors; Http::assertNotSent(zip url)
test_oversized_download_is_aborted()                               // zip response header Content-Length 134217729 → errors 'tamaño máximo'
test_editors_cannot_download()                                     // 403
```

  El paquete unificado de prueba es el de `ZipUpdateTest::unifiedPackage()`. Se mueve a un trait `Tests\Concerns\BuildsUpdatePackages`, que también usa `ZipUpdateTest`.
- [ ] **Step 2: Ejecutar** → FAIL.
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Ejecutar** `UpdateNoticeTest`, `ZipUpdateTest` y `PackageOriginTest` → PASS.
- [ ] **Step 5: Commit** con `Descargar y validar la última versión desde GitHub`.

### Task 6: ZIP más ligero

**Files:**
- Modify: `scripts/build-release.php`

**Interfaces:** ninguna nueva. Un filtro `releaseExcludes(string $relative): bool` se aplica después de `composer install`, `filament:assets` y `vendor:publish`, y antes de calcular `files` (Tarea 1). Borra del stage lo que excluye.

- [ ] **Step 1:** Generar el paquete actual y anotar el tamaño del ZIP y los bytes descomprimidos (suma de `files`).
- [ ] **Step 2:** Añadir el candidato 1 (`*.map`), generar el paquete y hacer la verificación completa:
  - instalación limpia con `scripts/prepare-release-test.mjs`, `compose.release-test.yaml` y `scripts/test-root-install.mjs` (base `solqubit_releaseqaN` nueva);
  - actualización con `scripts/test-zip-update.mjs`, con `SOLQUBIT_ALLOW_UNVERIFIED=true` en el contenedor de pruebas;
  - en el navegador: entrar al panel, crear y editar una entrada con el editor, subir una imagen y ver el blog, comprobando que la consola no tiene errores.
- [ ] **Step 3:** Repetir con el candidato 2: en `core/vendor/**`, las carpetas `tests`, `Tests`, `docs` y `.github`, y los archivos `*.md` y `*.dist`, salvo `LICENSE*`.
- [ ] **Step 4:** Repetir con el candidato 3: averiguar qué script inyecta Livewire (`csp_safe` en `config/livewire.php` o el valor por defecto) y quitar las demás variantes `livewire*.js` de `site/vendor/livewire/` y de `core/vendor/livewire/livewire/dist/`, conservando `manifest.json`.
- [ ] **Step 5:** Repetir con el candidato 4: `core/vendor/filament/*/dist/**`.
- [ ] **Step 6:** Descartar cualquier candidato que falle y anotar el motivo en un comentario del filtro. Apuntar el tamaño final.
- [ ] **Step 7:** Borrar la base `solqubit_releaseqaN` y el proyecto de pruebas. Commit con `Paquete más ligero sin archivos de desarrollo`.

### Task 7: Documentación y configuración local

**Files:**
- Modify: `README.md` (nueva sección «Tu propia versión de Solqubit»)
- Modify: `docs/UPDATE.md`
- Modify: `CHANGELOG.md` (`[1.2.0]`)
- Modify: `CLAUDE.md` («Decisiones tomadas»)
- Modify: `compose.yaml`, `compose.release-test.yaml` (`SOLQUBIT_ALLOW_UNVERIFIED: "true"` en el servicio web; comprobar cómo se pasa hoy el entorno y seguir ese estilo)
- Modify: `core/.env.example` (`SOLQUBIT_UPDATE_REPOSITORY=` y `SOLQUBIT_ALLOW_UNVERIFIED=false`, comentados)

Contenido que fija el diseño:
- **README:**
  1. Tu repositorio se anota solo: las releases generadas por el `release.yml` de un fork apuntan al fork; las generadas en local, al `origin` del clon. Se puede forzar con `SOLQUBIT_UPDATE_REPOSITORY` al generar el ZIP o en el `.env` del servidor, y ahí gana siempre.
  2. Publicar versiones (subir `version`, añadir la sección en el CHANGELOG, `git tag vX.Y.Z` y push; `release.yml` genera el ZIP y su `.sha256`).
  3. Desarrollo local con Docker (`SOLQUBIT_ALLOW_UNVERIFIED` ya activado).
  4. Versiones propias sin GitHub (`node scripts/build-release.mjs` y `SOLQUBIT_ALLOW_UNVERIFIED=true` en el `.env` de ese servidor, con su riesgo).
  5. Seguridad: verificación en dos pasos en GitHub y una regla que proteja los tags `v*`.
- **`docs/UPDATE.md`:** el aviso, «Descargar y validar», la subida manual, los tres mensajes de rechazo y qué hacer con cada uno, y «Comprobar archivos».
- **`CHANGELOG.md`:** tres líneas `- ` nuevas en `[1.2.0]`: aviso y actualización desde GitHub; solo se instalan ZIP publicados en el repositorio; saltar versiones sin archivos obsoletos y comprobación de archivos. Si la Tarea 6 lo consigue, una cuarta línea sobre el paquete más ligero.
- **`CLAUDE.md`:** sustituir «No hay firmas ni claves…» por el modelo: los ZIP se verifican archivo por archivo con SHA-256 y su origen, contra el `.sha256` de la release del repositorio configurado; la excepción es `SOLQUBIT_ALLOW_UNVERIFIED` en el `.env`; no hay claves ni firmas.

- [ ] **Step 1:** Escribir los cambios.
- [ ] **Step 2:** `docker compose up -d` y comprobar que el contenedor ve `config('solqubit.allow_unverified') === true`.
- [ ] **Step 3:** Commit con `Documentar versiones propias y el nuevo flujo de actualización`.

### Task 8: Verificación completa

- [ ] **Step 1:** La suite PHP completa → todo en verde (unas 320 pruebas más las nuevas).
- [ ] **Step 2:** `node --test core/tests/JavaScript/*.mjs` → PASS.
- [ ] **Step 3:** `vendor/bin/pint --test` en el contenedor → sin cambios.
- [ ] **Step 4:** En el navegador, en <http://127.0.0.1:8088/admin>: Estado del sistema muestra la sección «Actualizaciones»; «Comprobar archivos» responde; el escritorio carga. Con la red real puede aparecer «estás al día» o un error de consulta controlado.
- [ ] **Step 5:** Revisión final de todo el trabajo con un revisor independiente.

### Task 9: Volver a publicar v1.2.0

- [ ] **Step 1:** `git push origin main`.
- [ ] **Step 2:** Retirar la release v1.2.0 en GitHub y el tag remoto (`git push origin :refs/tags/v1.2.0`), y el tag local (`git tag -d v1.2.0`). Borrar la release necesita la web o un token: lo hace el propietario en GitHub → Releases → v1.2.0 → Delete.
- [ ] **Step 3:** `git tag -a v1.2.0 -m "Solqubit CMS 1.2.0"` y `git push origin v1.2.0`.
- [ ] **Step 4:** Seguir el workflow «Publicar versión» hasta `success`. Comprobar que la release tiene `solqubit-1.2.0.zip`, `.sha256` y el manifiesto, y que el manifiesto incluye `core/resources/solqubit/files.json`.
