# Actualizaciones desde GitHub, sin archivos obsoletos y con un ZIP más ligero

Fecha: 2026-10-16. Estado: aprobado por el propietario.

## Intención

Solqubit se instala en hosting compartido subiendo un ZIP. Hasta ahora se actualizaba subiendo a mano el ZIP de una versión más nueva en **Administración → Estado del sistema**. El propietario quiere:

1. Que el panel **avise** cuando haya una versión nueva en GitHub, recomiende actualizar y permita hacerlo con un botón.
2. Que se pueda saltar de una versión a cualquier otra más nueva y el resultado quede **idéntico a una instalación limpia**, sin archivos obsoletos de las versiones intermedias. «Debe quedar poderoso».
3. Que el panel solo instale código de un **origen de confianza**, de forma fácil y segura, sin claves ni firmas. A la vez, quien copie el repositorio tiene que poder publicar y usar sus propias versiones, y quien trabaje en local o sin GitHub tiene que tener una salida.
4. Un ZIP más ligero, **solo si no afecta a la instalación**.
5. Que el README explique cómo configurar una copia propia del CMS, tanto en local como con versiones propias.

Todavía no existe ninguna instalación. La release v1.2.0 actual se retira y se vuelve a publicar con estos cambios como primera versión, así que no hace falta compatibilidad con actualizadores anteriores.

## Modelo de confianza (decidido)

**El repositorio de GitHub configurado es la única fuente de confianza.** El panel solo instala un ZIP si es idéntico, byte a byte, al `solqubit-X.Y.Z.zip` publicado en la release `vX.Y.Z` de ese repositorio. Para comprobarlo, compara su SHA-256 con el `solqubit-X.Y.Z.zip.sha256` de la misma release. No hay claves ni firmas: se mantiene la decisión de `CLAUDE.md`.

La única excepción la decide el dueño del servidor: con `SOLQUBIT_ALLOW_UNVERIFIED=true` en el `.env` se aceptan ZIP sin verificar, pensado para desarrollo local o para versiones propias que no se publican. El panel lo marca en rojo. Nunca se puede activar desde el panel.

Qué protege:

- Una cuenta de administrador robada no puede instalar código propio.
- Un ZIP falso que llegue por correo o por otra web se rechaza.
- Una descarga alterada por el camino no pasa la comprobación.

Qué no protege: que alguien tome el control de la cuenta de GitHub que publica las releases. Se mitiga con la verificación en dos pasos y protegiendo los tags `v*`, y se recomienda en el README.

## Objetivos

- Aviso de versión nueva en el panel y actualización con un botón, descargando desde GitHub.
- La subida manual se mantiene, con la misma comprobación de origen.
- Cualquier salto de versión deja exactamente los archivos de la versión nueva en las carpetas que gestiona el paquete.
- Estado del sistema puede comprobar los archivos instalados.
- Una copia del repositorio funciona con sus propias releases sin cambiar nada: el repositorio se anota al generar el ZIP.
- ZIP más ligero sin riesgo para la instalación.

## No objetivos

- Actualizaciones automáticas sin intervención del administrador.
- Repositorios privados de GitHub, que necesitarían un token.
- Firmas asimétricas o claves propias. Se podrán añadir más adelante.
- Verificar el ZIP de la primera instalación.
- Cambiar la plantilla, el editor o el contenido de ejemplo.

## Anti-objetivos

Sería un fracaso, aunque funcione técnicamente:

- Borrar archivos del usuario: medios, `.env`, `storage/`, temas o código añadido fuera de las rutas que gestiona el paquete.
- Dejar el sitio a medias si falla la actualización. Toda escritura y retirada se puede deshacer con la copia de recuperación que ya existe.
- Que el panel quede lento o roto cuando GitHub no responde o el hosting bloquea las conexiones salientes.
- Que una cuenta de administrador robada pueda instalar código arbitrario.
- Un ZIP más ligero que falle en un hosting donde antes funcionaba.

## Restricciones del código actual

- `ZipUpdater::apply()` se ejecuta con el código instalado y `finish()` con el nuevo, tras recargar. `UpdateController::upload()` también corre con el código instalado.
- `core/bootstrap/update-guard.php` no está en `UpdatePackage::allowedPath()` y los paquetes no lo actualizan. La lógica nueva va en `app/Services/`.
- El instalador borra `site/.solqubit-release.json` al terminar (`Installer.php`).
- Nunca se borra ni se modifica nada en `core/database/migrations/`.
- Textos visibles en español, LF.
- Hosting compartido: `max_execution_time` corto, a veces sin conexiones salientes. Las consultas a la API de GitHub sin token están limitadas a 60 por hora por IP, y la IP suele compartirse con otros sitios.

## Pieza 1: actualizaciones sin archivos obsoletos

### Lista de archivos dentro de cada versión

`build-release.php` genera `core/resources/solqubit/files.json` con la versión y, para cada archivo del paquete salvo él mismo, su ruta (`core/...` o `site/...`) y su SHA-256. Es lo mismo que `files` del manifiesto. Al ir dentro del código (`core/resources/` es una ruta permitida), el sitio siempre sabe qué archivos tiene su versión.

Las instalaciones de desarrollo (Docker, sin ZIP) no tienen este archivo y funcionan igual: solo usan `remove` del paquete.

### Qué se retira al actualizar

Archivos a retirar = (lista de la versión instalada − archivos del paquete nuevo) ∪ `remove` del paquete nuevo.

Cada ruta, además:

- cumple `allowedPath()`;
- no está en `core/database/migrations/`;
- no está entre los archivos del paquete nuevo;
- no es carpeta padre de ningún archivo del paquete nuevo;
- existe en disco como archivo.

Si la lista instalada falta o no es válida (JSON roto, otra versión, rutas no permitidas), se ignora y se usa solo `remove`. `remove` se sigue calculando como ahora, contra la release anterior.

La pantalla de confirmación muestra el número real de archivos a retirar.

### Carpetas vacías

Tras retirar, se borran las carpetas que hayan quedado vacías, subiendo hasta la raíz de su ruta permitida sin borrar nunca esa raíz (por ejemplo, `core/vendor/`). La recuperación ya recrea carpetas al restaurar.

### Recuperación

No cambia: cada archivo retirado se copia antes en `rollback/` y se restaura si algo falla durante la copia.

### Versión mínima

`min_version` sigue siendo el único límite y queda en 1.2.0. El mensaje de rechazo dice qué versión instalar antes.

### Comprobar la instalación

En Estado del sistema, el botón «Comprobar archivos» compara el disco con `files.json`. Muestra cuántos archivos hay modificados, cuántos faltan y cuántos sobran, con las primeras 20 rutas de cada grupo.

- Los sobrantes solo se buscan en carpetas que el paquete gestiona por completo: `core/app/`, `core/config/`, `core/routes/`, `core/vendor/`, `site/js/filament/`, `site/css/filament/`, `site/fonts/filament/` y `site/vendor/livewire/`. Los temas quedan fuera, porque alguien puede añadir uno propio.
- Solo informa: no repara ni borra nada.
- Sin `files.json`, dice que esta instalación no tiene lista de archivos.

## Pieza 2: origen verificado

### Configuración (solo en el servidor)

**El repositorio se anota solo al generar el ZIP**, para que quien haga un fork no tenga que editar nada:

1. `build-release.php` decide el repositorio, en este orden:
   - la variable `SOLQUBIT_UPDATE_REPOSITORY`, si existe;
   - `GITHUB_REPOSITORY`, que GitHub Actions define en cada fork;
   - el remoto `origin` del clon (`git remote get-url origin`, si apunta a github.com). `build-release.mjs` lo calcula en el equipo y lo pasa al contenedor;
   - si no hay ninguno, queda vacío y avisa.
2. Lo escribe en `core/resources/solqubit/source.php` (`<?php return ['repository' => 'usuario/repo'];`) dentro del paquete, y también en el manifiesto como dato informativo.
3. El sitio instalado decide el repositorio en este orden:
   - `SOLQUBIT_UPDATE_REPOSITORY` en su `.env`, que gana siempre;
   - si no, `source.php` de su versión instalada;
   - si tampoco existe, como en la instalación de desarrollo, `solqubit/Solqubit-CMS`.

   Se valida con `^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$`. Vacío o no válido desactiva GitHub.
4. `allow_unverified` ← `env('SOLQUBIT_ALLOW_UNVERIFIED', false)`.

Un sitio confía en el repositorio que traía el ZIP con el que se instaló. Las actualizaciones se aceptan solo si vienen de ese repositorio, y cada versión verificada vuelve a anotar el suyo. La primera instalación es el único momento de confianza inicial, como en cualquier software que se descarga.

### Comprobación

Un servicio comprueba el origen de un ZIP ya validado (integridad, rutas y compatibilidad):

1. Si hay repositorio, descarga `https://github.com/{repositorio}/releases/download/v{versión}/solqubit-{versión}.zip.sha256`.
   - La versión sale del manifiesto ya validado.
   - Solo HTTPS, redirecciones permitidas (GitHub redirige a su CDN), 10 s de espera y respuesta de 1 KB como máximo.
   - Toma los primeros 64 caracteres hexadecimales y los compara con el SHA-256 del ZIP usando `hash_equals`. Si coinciden, el origen es **«github»**.
2. Si no coinciden, no existe la release (404) o GitHub no responde: con `allow_unverified`, el origen es **«sin verificar»**; sin él, se rechaza.
3. El mensaje de rechazo distingue «el ZIP no coincide con la release oficial» de «no se pudo contactar con GitHub», y explica la salida en cada caso.

El origen se guarda en la sesión pendiente (`cms_update`), en el estado de la transacción y en `metadata` del `AuditEvent` `system.updated`.

## Pieza 3: aviso y «Actualizar ahora»

### Consultar la última versión

- Llama a `GET https://api.github.com/repos/{repositorio}/releases/latest` con `Accept: application/vnd.github+json`, `User-Agent: Solqubit/{versión}` y 8 s de espera.
- Lee `tag_name` (debe ser `vX.Y.Z`), `html_url`, `published_at` y las líneas `- ` de `body` hasta el primer encabezado `###`. Esas líneas son las novedades.
- Guarda el resultado en caché 12 horas. Los fallos se guardan 1 hora, para no insistir contra un hosting sin salida ni gastar el límite de la API.
- El botón «Buscar actualizaciones» fuerza una nueva consulta, como mucho una vez por minuto.
- Sin repositorio configurado no se consulta nada.
- Las pruebas nunca salen a la red: `Http::fake` y `Http::preventStrayRequests()`.

### Dónde se ve el aviso

- **Escritorio:** un widget solo para administradores, que solo aparece si hay una versión más nueva. Dice «Solqubit X.Y.Z está disponible», recomienda actualizar, lista las novedades y enlaza a Estado del sistema. Es diferido (lazy), para no frenar el escritorio si GitHub tarda.
- **Menú:** «Estado del sistema» lleva una insignia «Nueva versión» cuando la hay. Solo lee la caché; nunca consulta GitHub al pintar el menú.

### Estado del sistema

Una sección nueva, «Actualizaciones», al principio de la página:

- versión instalada, última versión publicada y fecha de la última consulta;
- si hay una nueva: las novedades, un enlace a la release en GitHub y el botón **«Descargar y validar X.Y.Z»**;
- si no se pudo consultar, la explicación y el recordatorio de que se puede subir el ZIP a mano;
- el botón «Buscar actualizaciones».

### Descargar y validar

Es una petición POST, solo para administradores y limitada en frecuencia:

1. Comprueba que la versión elegida es la última consultada y que es más nueva que la instalada.
2. Descarga el `.sha256` y luego `solqubit-X.Y.Z.zip`, desde direcciones fijas del repositorio, en streaming a `updates/packages/{id}.zip`. Límite de 128 MB comprobado durante y después de la descarga, y 300 s de espera.
3. Compara la huella. Si no coincide, borra el archivo y rechaza.
4. Pasa la misma validación que un ZIP subido (`UpdatePackage::inspect`) y deja la actualización pendiente en sesión con origen «github».
5. Desde ahí, el flujo es el de siempre: confirmación con la lista de cambios y «Aplicar actualización».

Descargar y aplicar son dos peticiones separadas, para no superar el tiempo máximo de ejecución.

## Pieza 4: ZIP más ligero, sin riesgo

Hoy: 22 MB comprimido y 75 MB descomprimido (60 MB son `core/vendor`). Candidatos, de menor a mayor riesgo:

1. Mapas `*.map` en todo el paquete.
2. Documentación, pruebas y metadatos dentro de `core/vendor` (`tests/`, `docs/`, `.github/`, `*.md`, `*.dist`). Nunca `LICENSE*`.
3. Variantes de Livewire que no se usan (`*.esm.js` y la variante CSP o normal que no use el proyecto), en `site/vendor/livewire/` y en `core/vendor/livewire/livewire/dist/`. `manifest.json` se queda en ambas.
4. JS y CSS de Filament en `core/vendor/filament/*/dist/`, que ya están publicados en `site/`.

Se aplican uno a uno. Un candidato solo se queda si, tras quitarlo:

- pasan todas las pruebas;
- funciona una instalación limpia en contenedor;
- funciona una actualización en contenedor;
- en el navegador funcionan el acceso al panel, crear y editar una entrada con el editor, subir una imagen y ver el blog, sin errores en la consola.

Si falla, se descarta y se apunta el motivo. Se informa del tamaño antes y después.

## Documentación

- **README:** sección «Tu propia versión de Solqubit», que explica:
  - que un fork apunta solo a su propio repositorio al generar sus releases, y cómo forzar otro con `SOLQUBIT_UPDATE_REPOSITORY`;
  - cómo publicar releases con el `release.yml` incluido (subir la versión, el CHANGELOG y el tag);
  - cómo trabajar en local con Docker, donde `SOLQUBIT_ALLOW_UNVERIFIED` ya está activado;
  - cómo generar el ZIP sin GitHub y qué activar en el servidor para instalarlo;
  - las recomendaciones de seguridad: verificación en dos pasos y tags `v*` protegidos.
- **`docs/UPDATE.md`:** el aviso, el botón, la subida manual, los mensajes de rechazo y qué hacer.
- **`CHANGELOG.md`:** las novedades, en la sección `[1.2.0]`.
- **`CLAUDE.md`:** el modelo de confianza en «Decisiones tomadas».

## Pruebas

- **PHPUnit:**
  - cálculo de la retirada, con lista y sin ella;
  - salto de varias versiones con paquetes falsos;
  - exclusiones: migraciones, rutas no permitidas y carpetas padre;
  - limpieza de carpetas vacías;
  - recuperación de lo retirado;
  - comprobación de archivos;
  - origen: coincide, no coincide, 404, GitHub caído, y `allow_unverified` activado o no;
  - consulta de la última versión, caché y fallos;
  - descarga: huella incorrecta, tamaño excesivo, versión no más nueva;
  - el widget y la insignia solo los ven los administradores.
- **Contenedores:** instalación limpia (`test-root-install.mjs`) y actualización (`test-zip-update.mjs`), esta última con `SOLQUBIT_ALLOW_UNVERIFIED=true` porque la versión de prueba no está en GitHub.
- Pint en los archivos tocados y `node --test`.

## Publicación

La release v1.2.0 actual y su tag se retiran, y v1.2.0 se vuelve a publicar desde el commit con estos cambios. Al no haber release anterior, `remove` queda vacío y el workflow no busca manifiesto previo.

## Lo que queda al constructor

- Nombres de clases y métodos. Sugerencia: `ReleaseFiles` (lista y retirada), `PackageOrigin` (origen) y `GitHubReleases` (consulta y descarga).
- El diseño exacto de las secciones y del widget, siguiendo el estilo actual del panel.
- Cómo dividir el trabajo en commits.
