# Solqubit CMS: notas para el asistente

CMS de blog en español (Laravel 13 + Filament 5) que se instala en hosting compartido (cPanel, CloudPanel, HestiaCP) subiendo un ZIP, sin SSH ni Composer. Repositorio: https://github.com/solqubit/Solqubit-CMS. Licencia MIT.

## Estructura

- `core/`: motor privado (app, vistas, migraciones, pruebas). Sus reglas de código están en `core/CLAUDE.md` (Laravel Boost).
- `site/`: carpeta pública (`index.php`, `.htaccess`, CSS/JS del tema, tipografías, recursos de Filament).
- `scripts/`: entorno local, `build-release.php` (genera el ZIP) y pruebas de instalación y actualización en contenedores.
- `docs/`: guías para usuarios (INSTALL, UPDATE, USO, THEMES).
- `.github/workflows/`: `ci.yml` (pruebas en cada push o PR) y `release.yml` (al subir un tag `vX.Y.Z` prueba, genera el ZIP con atestación y publica la release).

## Entorno local (Docker)

- `docker compose up -d` y luego <http://127.0.0.1:8088> (panel en `/admin`). Contenedor `solqubit-cms-web-1` y base MariaDB `solqubit-cms-database-1`.
- Para apagarlo, `docker compose stop`. Nunca uses `down -v`, porque borra la base de datos.
- Las credenciales del administrador local están en `test-artifacts/local-access.json` (ignorado por Git). No las repitas en el chat.

## Comprobaciones

- PHP: `MSYS_NO_PATHCONV=1 docker exec solqubit-cms-web-1 sh -c 'cd /var/www/core && php -d memory_limit=1G vendor/bin/phpunit --colors=never'` (unas 320 pruebas; tarda unos 4 minutos porque la carpeta montada es lenta).
- Estilo: `vendor/bin/pint <archivos> --format agent` dentro del contenedor. No uses `--dirty`.
- JS: `node --test core/tests/JavaScript/*.mjs`.
- Paquete: `node scripts/build-release.mjs` (o `php scripts/build-release.php` con PHP local). Para probar una instalación limpia: `scripts/prepare-release-test.mjs`, `compose.release-test.yaml` y `scripts/test-root-install.mjs`, con las variables `RELEASE_QA_*` y una base de datos `solqubit_releaseqaN` nueva; bórrala al terminar.

## Decisiones tomadas

- El instalador es libre, sin token: lo decidió el propietario.
- No hay firmas ni claves. Los paquetes se verifican archivo por archivo con SHA-256 (manifiesto `.solqubit-release.json`), y su origen con el `.sha256` de la release del repositorio en el que confía el sitio. Ese repositorio se anota al generar el ZIP (`SOLQUBIT_UPDATE_REPOSITORY`, `GITHUB_REPOSITORY` o el remoto `origin`) en `core/resources/solqubit/source.php`. La única excepción es `SOLQUBIT_ALLOW_UNVERIFIED=true` en el `.env` del servidor (activado en Docker). Ver `core/app/Services/PackageOrigin.php`.
- Cada paquete lleva `core/resources/solqubit/files.json`; el actualizador retira lo que la versión instalada tenía y la nueva no (`core/app/Services/ReleaseFiles.php`), así que se puede saltar versiones.
- El motor se guarda en `private/` (HestiaCP), dentro del dominio protegido con `.htaccess` (Apache/LiteSpeed) o fuera de la carpeta pública (Nginx). Ver `core/app/Services/HostingLayout.php` y `scripts/root-installer.php.template`.
- La base de datos es MySQL/MariaDB (no SQLite) porque es lo que ofrecen los paneles.
- Hay una única plantilla pública (`solqubit`), que se personaliza con variantes de color.
- No modifiques migraciones existentes: el actualizador las rechaza.
- Los textos visibles van en español, con saltos de línea LF.

## Publicar una versión

Sube `'version'` en `core/config/solqubit.php` y añade la sección `## [X.Y.Z]` en `CHANGELOG.md`; sus líneas `- ` son las notas de la release y la lista de cambios del panel. Después haz commit, `git tag vX.Y.Z` y push del tag.
