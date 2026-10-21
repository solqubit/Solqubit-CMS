# Solqubit CMS

Un CMS de blog en español, sencillo y cuidado, que se instala en cualquier hosting con PHP **subiendo un ZIP**: sin SSH, sin Composer y sin Node. Funciona en cPanel, CloudPanel, HestiaCP y en cualquier servidor con Apache, LiteSpeed o Nginx y MySQL/MariaDB.

<!-- Añade aquí capturas: docs/capturas/portada.png, entrada.png y editor.png -->

## Qué incluye

- **Editor visual por bloques:** texto, imágenes, YouTube, vídeo propio, código, salida de terminal, tablas, avisos, desplegables y enlaces. Menú `/`, vista previa en claro y oscuro, borradores guardados automáticamente y publicación programada.
- **Diseño listo para usar:** modo claro y oscuro, varias variantes de color, tipografías propias (sin Google Fonts), accesible y adaptado a móvil.
- **SEO de serie:** URLs limpias y configurables, datos estructurados, sitemap, RSS, Open Graph y redirecciones al cambiar una dirección.
- **Biblioteca de medios privada:** las imágenes se reducen a WebP automáticamente y los archivos solo son públicos cuando aparecen en contenido publicado.
- **Seguro por defecto:** permisos por autor, bloqueo ante intentos fallidos, verificación en dos pasos opcional, CSP y HSTS.
- **Actualizaciones desde el panel:** descargas la versión nueva desde GitHub o subes su ZIP de actualización, y se comprueba archivo por archivo antes de aplicarlo, con recuperación si algo falla.
- Series, categorías, etiquetas, páginas, menús, redes sociales y anuncios de AdSense opcionales.

## Descargar

Cada versión publica dos archivos en la [última versión publicada](../../releases/latest):

- **`solqubit-X.Y.Z-instalacion.zip`**: para instalar en un sitio nuevo.
- **`solqubit-X.Y.Z-actualizacion.zip`**: solo para actualizar desde el panel, sin descomprimirlo.

> No descargues "Source code (zip)": es el código fuente sin dependencias y no se puede instalar directamente.

Cada ZIP incluye su suma SHA-256 (`.sha256`) y una atestación de procedencia de GitHub. Para comprobar que se generó en este repositorio:

```bash
gh attestation verify solqubit-X.Y.Z-instalacion.zip --repo solqubit/Solqubit-CMS
```

## Instalar en 5 pasos

1. Crea el dominio con **PHP 8.3 o 8.4** y una base de datos **MySQL 8+ o MariaDB 10.11+** vacía.
2. Sube el ZIP de instalación a la carpeta del dominio y extráelo allí (incluido el archivo oculto `.htaccess`).
3. Abre el dominio en el navegador y pulsa **Preparar y continuar**.
4. Escribe los datos de la base de datos, el nombre del blog y tu cuenta de administrador.
5. Entra en `/admin` y empieza a escribir.

Instrucciones detalladas para cada panel y solución de problemas: [docs/INSTALL.md](docs/INSTALL.md).

## Actualizar

Cuando hay una versión nueva en GitHub, el panel avisa a los administradores. En **Actualizaciones**, pulsa **Descargar y validar** y luego **Aplicar actualización**, o sube a mano `solqubit-X.Y.Z-actualizacion.zip` sin descomprimirlo. Solo se instalan paquetes idénticos a los publicados en el repositorio del sitio. Se conservan el contenido, los usuarios, los ajustes y los archivos, y puedes saltarte versiones sin que queden archivos obsoletos. Más detalles en [docs/UPDATE.md](docs/UPDATE.md).

## Documentación

- [Instalación](docs/INSTALL.md)
- [Actualizaciones](docs/UPDATE.md)
- [Guía de uso del editor y las páginas](docs/USO.md)
- [Plantilla y extensiones](docs/THEMES.md)

## Desarrollo

El repositorio contiene el código fuente: `core/` es el motor (Laravel 13 + Filament 5) y `site/` la carpeta pública.

### Con Docker (recomendado)

```bash
node scripts/dev-setup.mjs
docker compose up -d --build
node scripts/install-local.mjs
```

`dev-setup.mjs` crea las credenciales locales y la configuración del motor; `install-local.mjs` completa el asistente con un administrador de prueba (usuario `admin`; la contraseña queda en `test-artifacts/local-access.json`). También puedes instalar a mano en <http://127.0.0.1:8088>: el servidor de base de datos es `database`, y la base y el usuario son `solqubit`. La contraseña está en `.env`.

El blog queda en <http://127.0.0.1:8088> y el panel en `/admin`. Para apagarlo, usa `docker compose stop`; no uses `down -v`, porque borra la base de datos.

### Pruebas

```bash
docker compose exec web php vendor/bin/phpunit
docker compose exec web vendor/bin/pint --test
node --test core/tests/JavaScript/*.mjs
```

### Generar los paquetes

Con Docker:

```bash
node scripts/build-release.mjs
```

Sin Docker, con PHP 8.3+ (extensiones `intl`, `zip`, `gd`, `bcmath`, `pdo_mysql`, `mbstring`, `xml`, `curl`) y Composer instalados:

```bash
cd core && composer install && cd ..
php scripts/build-release.php
```

Los dos ZIP (instalación y actualización) quedan en `release/`. Las versiones publicadas se generan automáticamente con GitHub Actions al crear un tag: lee [CONTRIBUTING.md](CONTRIBUTING.md#publicar-una-versión).

## Tu propia versión de Solqubit

Puedes hacer un fork, cambiar lo que quieras y distribuir tus propias versiones. Los sitios instalados con tus paquetes se actualizarán desde **tu** repositorio, no desde el oficial.

### Cómo sabe un sitio de dónde actualizarse

Al generar el ZIP, el script anota el repositorio en `core/resources/solqubit/source.php` y en el manifiesto. Lo elige en este orden:

1. La variable `SOLQUBIT_UPDATE_REPOSITORY` (por ejemplo, `tu-usuario/tu-repo`), si la defines.
2. `GITHUB_REPOSITORY`, que GitHub Actions define en cada repositorio. **En un fork no tienes que configurar nada:** sus releases apuntan al fork.
3. El remoto `origin` de tu clon (`git remote get-url origin`), si apunta a GitHub.

Si no encuentra ninguno, el paquete queda sin repositorio y lo avisa al generarlo.

En un sitio ya instalado, `SOLQUBIT_UPDATE_REPOSITORY` en el `.env` del motor privado tiene prioridad sobre lo anotado en el paquete.

### Publicar tus versiones en GitHub

1. Haz un fork y activa GitHub Actions en él.
2. Sube `'version'` en `core/config/solqubit.php` y añade la sección `## [X.Y.Z]` en `CHANGELOG.md`. Sus líneas `- ` son las novedades que verá el panel.
3. Haz commit, crea el tag y súbelo: `git tag vX.Y.Z` y `git push origin vX.Y.Z`.
4. El workflow `release.yml` ejecuta las pruebas y publica la release con `solqubit-X.Y.Z-instalacion.zip`, `solqubit-X.Y.Z-actualizacion.zip`, sus huellas `.sha256` y `solqubit-X.Y.Z-manifest.json`.

Los sitios instalados con tus paquetes verán el aviso y aceptarán solo esos ZIP. Tu repositorio debe ser público: el panel consulta GitHub sin token.

### Trabajar en local

El entorno de Docker ya permite instalar ZIP sin verificar, para que puedas probar tus paquetes antes de publicarlos:

```bash
node scripts/build-release.mjs
```

Sube el ZIP de actualización de `release/` en **Actualizaciones** de tu sitio local. El panel lo marcará como **Paquete sin verificar**.

### Versiones propias sin GitHub

Si generas tus versiones en tu equipo y las subes a tu hosting sin publicarlas, el panel no puede comprobar su origen. Añade al `.env` del motor privado de ese sitio:

```
SOLQUBIT_ALLOW_UNVERIFIED=true
```

Así acepta tus ZIP, aunque sigue comprobando la integridad de cada archivo. Ten en cuenta que entonces cualquier administrador del panel puede instalar un ZIP cualquiera: activa esta opción solo en servidores que controles.

### Seguridad del repositorio

Tu cuenta de GitHub pasa a ser la fuente de confianza de tus sitios. Activa la verificación en dos pasos y, en **Settings → Rules**, protege los tags `v*` para que solo tú puedas publicar versiones.

## Contribuir

Las propuestas y correcciones son bienvenidas. Es un proyecto personal que se mantiene según el tiempo disponible, así que no hay soporte garantizado. Lee [CONTRIBUTING.md](CONTRIBUTING.md) antes de abrir un pull request y [SECURITY.md](SECURITY.md) para avisar de un fallo de seguridad.

## Licencia

[MIT](LICENSE). Laravel, Filament y las demás dependencias conservan sus propias licencias. Las tipografías incluidas usan la licencia SIL Open Font License (`site/themes/solqubit/fonts/OFL.txt`).
