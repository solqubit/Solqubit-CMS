# Contribuir a Solqubit

Gracias por tu interés. Solqubit es un proyecto personal que se mantiene según el tiempo disponible: las correcciones pequeñas y bien probadas tienen más posibilidades de entrar que las funciones grandes. Si quieres proponer algo grande, abre antes un issue para hablarlo.

## Preparar el entorno

Necesitas Docker Desktop y Node.js 18 o superior.

```bash
node scripts/dev-setup.mjs
docker compose up -d --build
node scripts/install-local.mjs
```

El blog queda en <http://127.0.0.1:8088> y el panel en `/admin` (usuario `admin`, contraseña en `test-artifacts/local-access.json`).

## Antes de enviar un pull request

```bash
docker compose exec web php vendor/bin/phpunit
docker compose exec web vendor/bin/pint
node --test core/tests/JavaScript/*.mjs
```

- Añade o actualiza pruebas para cada cambio de comportamiento.
- Los textos visibles van en español neutro.
- Saltos de línea LF y codificación UTF-8 (lo fija `.editorconfig`).
- No modifiques migraciones existentes: el actualizador las rechaza. Crea una nueva.
- Anota el cambio en `CHANGELOG.md`, dentro de `## [Sin publicar]`.
- Las pruebas usan SQLite en memoria; la integración continua también las ejecuta con MariaDB.

## Estructura

| Carpeta | Contenido |
| --- | --- |
| `core/` | Motor Laravel + Filament: código, vistas, migraciones y pruebas |
| `site/` | Carpeta pública: `index.php`, `.htaccess`, estilos, JavaScript y tipografías |
| `scripts/` | Entorno local, compilación del ZIP y pruebas de instalación y actualización |
| `docs/` | Documentación para usuarios |
| `deploy/` | Reglas de ejemplo para Nginx |

## Publicar una versión

Las releases se generan solas con GitHub Actions (`.github/workflows/release.yml`):

1. Sube la versión en `core/config/solqubit.php` (`'version' => '1.3.0'`).
2. En `CHANGELOG.md`, renombra `## [Sin publicar]` a `## [1.3.0] - AAAA-MM-DD` y añade un `## [Sin publicar]` vacío encima.
3. Haz commit y crea el tag:

   ```bash
   git commit -am "Versión 1.3.0"
   git tag v1.3.0
   git push origin main --tags
   ```

El workflow comprueba que el tag coincide con la versión, ejecuta las pruebas, genera `solqubit-1.3.0.zip`, su `.sha256` y el manifiesto, firma la procedencia con una atestación de GitHub y publica la release con las notas del changelog. Si falla, borra el tag (`git push origin :v1.3.0`), corrige y vuelve a crearlo.

En cuanto se publica la release, los sitios instalados ven el aviso en su panel, como mucho 12 horas después. Solo aceptan el ZIP si su `.sha256` está publicado en la release: no la edites a mano ni sustituyas sus archivos. Un fork apunta a sí mismo al publicar con su propio workflow; lo explica el [README](README.md#tu-propia-versión-de-solqubit).

Para generar el ZIP en tu equipo sin publicarlo: `node scripts/build-release.mjs` (con Docker) o `php scripts/build-release.php` (con PHP y Composer instalados). El resultado queda en `release/`.
