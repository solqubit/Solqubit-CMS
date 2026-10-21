# Instalar Solqubit CMS

## Requisitos

- PHP 8.3 o 8.4 con las extensiones `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pdo_mysql`, `session`, `tokenizer`, `xml`, `intl`, `zip`, `gd` (con WebP) y `bcmath`. Recomendada: `exif`, para respetar la orientación de las fotos de cámara.
- MySQL 8.0+ o MariaDB 10.11+, con una base vacía y un usuario con todos los permisos sobre ella.
- Apache 2.4 o LiteSpeed con `.htaccess`, o Nginx con PHP-FPM.
- `memory_limit` de 128 MB o más (recomendado 256 MB). Para subir actualizaciones e imágenes grandes: `upload_max_filesize=32M` y `post_max_size=40M`.

Cada versión publica dos ZIP: `solqubit-X.Y.Z-instalacion.zip`, para instalar en un sitio nuevo, y `solqubit-X.Y.Z-actualizacion.zip`, solo para actualizar desde el panel. Para instalar usa el primero. Incluye todas las dependencias: no necesitas Composer, Node ni acceso SSH.

## Pasos comunes a todos los paneles

1. Crea el dominio o subdominio y activa PHP 8.3 o 8.4.
2. Crea una base de datos vacía y un usuario con todos los permisos sobre ella.
3. Borra la página de bienvenida que crea el panel (`index.html`) de la carpeta del dominio.
4. Descarga `solqubit-X.Y.Z-instalacion.zip` desde la página de Releases del proyecto en GitHub (no el de actualización ni el "Source code"), súbelo a la carpeta del dominio y extráelo allí. Comprueba que se extrajo `.htaccess` (activa "mostrar archivos ocultos" en el administrador de archivos).
5. Abre el dominio en el navegador. Se abrirá `install-core.php`: pulsa **Preparar y continuar**.
6. Introduce los datos de la base de datos, el nombre del blog, la zona horaria y tu cuenta de administrador. Elige el contenido inicial y pulsa **Instalar mi blog**.
7. Entra en `/admin` con tu usuario o correo. Activa el certificado SSL del panel y, si quieres, la redirección a HTTPS.

### Dónde se guarda el motor

El asistente guarda el código interno, la configuración, los archivos subidos y los registros en una carpeta que no se puede abrir desde el navegador, y elige la ubicación automáticamente:

| Situación | Ubicación |
| --- | --- |
| Existe una carpeta `private` junto a la del dominio (HestiaCP) | `private/solqubit-core` |
| Apache o LiteSpeed respetan el `.htaccess` (cPanel) | `solqubit-core`, dentro de la carpeta del dominio, protegida y comprobada |
| Nginx sirve los archivos sin `.htaccess` (CloudPanel) | `solqubit-core-<carpeta>`, junto a la carpeta del dominio |

Consulta la ruta exacta en **Actualizaciones → Ubicación de los archivos**. Conserva `.htaccess` y `.solqubit-path.php`. Si el motor está dentro del dominio, conserva también `solqubit-core`.

El asistente se bloquea al terminar y borra los archivos de preparación. No sobrescribe un motor existente ni instala sobre una base que ya contiene tablas.

## cPanel (Apache o LiteSpeed)

- **PHP:** en *MultiPHP Manager* o *Select PHP Version*, elige 8.3 o 8.4. En *Extensions* marca `intl`, `zip`, `gd`, `bcmath`, `fileinfo` y `exif`. En *Options* sube `memory_limit` a 256M, `upload_max_filesize` a 32M y `post_max_size` a 40M.
- **Base de datos:** en *Bases de datos MySQL* crea la base y el usuario, y añade el usuario a la base con **todos los privilegios**. Usa los nombres completos con el prefijo de la cuenta (por ejemplo, `cuenta_blog`). El servidor es `localhost`.
- **Archivos:** sube el ZIP de instalación a `public_html` o a la carpeta del dominio adicional y usa *Extraer*.
- Si al abrir el dominio aparece un error 500, tu proveedor no permite la línea `Options` en `.htaccess`: bórrala y recarga.

## CloudPanel (Nginx + PHP-FPM)

- **Sitio:** *Add Site → Create a PHP Site*. Elige la aplicación **Generic** (no *Laravel*: el ZIP ya trae su propia carpeta pública) y PHP 8.3 o 8.4. La carpeta del dominio será `/home/<usuario>/htdocs/<dominio>`.
- **Base de datos:** en la pestaña *Databases* del sitio crea la base y el usuario. Usa `127.0.0.1` como servidor. Si escribes `localhost`, el asistente probará `127.0.0.1` automáticamente.
- **Archivos:** sube el ZIP de instalación con el *File Manager* o por SFTP con el usuario del sitio, y extráelo en `htdocs/<dominio>`.
- **PHP:** en *Settings → PHP Settings* ajusta `memory_limit` y los límites de subida.
- No hace falta editar el Vhost. Nginx no lee `.htaccess`, así que el asistente guarda el motor fuera de la carpeta pública. El Vhost por defecto ya contiene `try_files $uri $uri/ /index.php?$args;`, que es lo único necesario.

## HestiaCP

- **Dominio:** *Web → Add Web Domain*. Sirve tanto la plantilla por defecto (Nginx + Apache) como la de solo Nginx + PHP-FPM. En *Advanced options* elige la plantilla PHP-FPM 8.3 o 8.4.
- **Base de datos:** *DB → Add Database*. Hestia añade el prefijo de la cuenta al nombre y al usuario: usa los nombres completos. El servidor es `localhost`.
- **Archivos:** en el *File Manager*, entra en `web/<dominio>/public_html`, borra `index.html` y `robots.txt` si existen, sube el ZIP de instalación y extráelo.
- El motor se guarda automáticamente en `web/<dominio>/private/solqubit-core`, que Hestia ya protege y que `open_basedir` permite.
- **SSL:** edita el dominio y activa *Enable SSL* y *Use Lets Encrypt*.

## Otros paneles y servidores propios

Con Apache o LiteSpeed basta el `.htaccess` incluido. Con Nginx, usa un bloque de servidor que envíe a `index.php` todo lo que no sea un archivo. Las reglas recomendadas están en `nginx-locations.conf`. Si el motor queda dentro del dominio, añade también estas dos reglas:

```nginx
location = /solqubit-core { return 404; }
location ^~ /solqubit-core/ { return 404; }
```

Si instalas en una subcarpeta, adapta las rutas a esa ubicación.

## Cloudflare y proxies

- Usa el modo SSL **Full (strict)** o **Full**. Con *Flexible*, el sitio recibe peticiones HTTP y genera enlaces HTTP.
- Si hay un proxy inverso delante del servidor, añade `SOLQUBIT_TRUSTED_PROXIES` en el `.env` del motor. Así los bloqueos por intentos fallidos usan la IP real del visitante.

## Problemas frecuentes

- **Error 500 nada más extraer:** quita la línea `Options -Indexes -MultiViews` del `.htaccess` (algunos proveedores no la permiten) y revisa que la versión de PHP sea 8.3 o superior.
- **"No se pudo conectar" con la base de datos:** revisa los prefijos de cPanel y Hestia y que el usuario tenga todos los privilegios sobre la base. Prueba `127.0.0.1` como servidor.
- **Las imágenes no se reducen:** activa la extensión `gd` con soporte WebP (es el valor por defecto en los tres paneles).
- **No puedo subir una actualización:** sube `upload_max_filesize`, `post_max_size` y, en Nginx, `client_max_body_size` a 40M o más.

## Actualizar un sitio existente

Abre **Actualizaciones** en el panel y pulsa **Descargar y validar**, o sube `solqubit-X.Y.Z-actualizacion.zip` en **Subir el ZIP de actualización** sin descomprimirlo. No extraigas el ZIP de instalación encima de un sitio instalado. Se conservan el contenido, la configuración y la ubicación del motor. Consulta `UPDATE.md` para conocer el proceso y cómo recuperarte si se interrumpe.

## Desarrollo desde el código fuente

El repositorio conserva `core/` y `site/` como directorios separados para el desarrollo. Para generar tus propios ZIP, consulta el apartado *Desarrollo* del [README](../README.md).
