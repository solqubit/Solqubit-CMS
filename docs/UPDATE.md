# Actualizar Solqubit

Cada sitio confía en un repositorio de GitHub: el oficial, `solqubit/Solqubit-CMS`, o el de quien generó tu paquete (consulta [Tu propia versión de Solqubit](../README.md#tu-propia-versión-de-solqubit)). El panel solo instala una versión si su ZIP es idéntico al publicado en una release de ese repositorio. Para comprobarlo, compara su huella SHA-256 con el archivo `.sha256` de la release.

El CMS no incluye copias de seguridad: la base de datos y la biblioteca se conservan como contenido separado del código.

## Los archivos de cada versión

Cada release publica dos ZIP, cada uno con su `.sha256`:

- `solqubit-X.Y.Z-instalacion.zip`: para instalar en un sitio nuevo. Se extrae en la carpeta del dominio (consulta [INSTALL.md](INSTALL.md)).
- `solqubit-X.Y.Z-actualizacion.zip`: solo para actualizar desde el panel. Se sube sin descomprimir.

También incluye `solqubit-X.Y.Z-manifest.json`, con la lista de archivos de la versión.

Si subes en el panel el ZIP de instalación, responde: «Este es el ZIP de instalación. Para actualizar, usa el archivo solqubit-X.Y.Z-actualizacion.zip de la misma versión.»

## Aviso de versión nueva

El panel consulta GitHub cada 12 horas. Si hay una versión más reciente:

- el escritorio muestra a los administradores **«Solqubit X.Y.Z está disponible»** con sus novedades;
- **Actualizaciones** aparece en el menú con la insignia **Nueva versión**.

En **Actualizaciones → Actualizar desde GitHub**, **Buscar actualizaciones** consulta GitHub en ese momento. Como mucho, una vez por minuto.

## La página Actualizaciones

Está en el grupo **Administración** del menú y tiene, en este orden:

1. **Actualizar desde GitHub:** busca y descarga la versión nueva.
2. **Subir el ZIP de actualización:** para subir el ZIP a mano.
3. **Requisitos del servidor:** versión de PHP, extensiones, memoria y límites de subida.
4. **Comprobar los archivos:** compara el código instalado con la lista de tu versión.
5. **Ubicación de los archivos:** las rutas del motor y de la carpeta pública.

## Actualizar desde GitHub (recomendado)

1. En **Actualizaciones → Actualizar desde GitHub**, pulsa **Descargar y validar X.Y.Z**.
2. El servidor descarga el ZIP de actualización de la release y comprueba su huella SHA-256. Después comprueba cada archivo: tamaño, SHA-256, rutas permitidas y compatibilidad con tu versión, PHP y sus extensiones. El sitio todavía no cambia.
3. Revisa los cambios y la etiqueta **Paquete oficial**. Pulsa **Aplicar actualización**. Durante la copia y las migraciones, el sitio muestra un aviso de mantenimiento.
4. Al terminar, el panel muestra la versión nueva y la última actualización completada.

## Subir el ZIP a mano

Sirve cuando el hosting no deja descargar desde el servidor o prefieres hacerlo tú.

1. Descarga `solqubit-X.Y.Z-actualizacion.zip` de la release. No uses el de instalación ni «Source code».
2. En **Actualizaciones → Subir el ZIP de actualización**, selecciónalo y pulsa **Validar ZIP**.
3. Se hacen las mismas comprobaciones, además de comparar el ZIP con la release publicada. Si todo coincide, pulsa **Aplicar actualización** como en el caso anterior.

No lo descomprimas.

## Antes de empezar

Antes de cambiar nada, el panel comprueba que el servidor tiene tiempo y memoria suficientes. Se detiene con un mensaje si:

- el servidor permite menos de 60 segundos por petición (`max_execution_time`) y no se puede ampliar;
- PHP tiene menos de 128 MB de memoria (`memory_limit`).

En los dos casos, el sitio sigue como estaba.

## Si se rechaza un paquete

| Mensaje | Qué significa | Qué hacer |
|---|---|---|
| «Este ZIP no coincide con la versión X publicada en github.com/…» | El archivo no es el de la release: modificado, generado por otra persona o de otro repositorio. | Descárgalo de nuevo desde la release oficial. |
| «Este es el ZIP de instalación…» | Has subido `solqubit-X.Y.Z-instalacion.zip`. | Sube `solqubit-X.Y.Z-actualizacion.zip` de la misma versión. |
| «No se pudo contactar con GitHub…» | El servidor no pudo conectar con GitHub: un corte puntual o un hosting que bloquea las conexiones externas. | Inténtalo más tarde. Si tu hosting las bloquea siempre, consulta la sección siguiente. |
| «Este sitio no tiene un repositorio de actualizaciones configurado…» | El paquete instalado no tenía repositorio. Pasa si se generó sin GitHub. | Configura `SOLQUBIT_UPDATE_REPOSITORY` o permite ZIP sin verificar, como se explica abajo. |
| «El servidor permite solo N segundos por petición…» | `max_execution_time` es menor de 60 segundos y PHP no puede ampliarlo. | Pide a tu hosting al menos 60 segundos y vuelve a intentarlo. |
| «PHP tiene N MB de memoria…» | `memory_limit` es menor de 128 MB. | Súbelo a 128 MB o más (recomendado 256 MB) en el panel de tu hosting. |

También se rechazan la misma versión, las anteriores y las que no se pueden instalar desde la tuya.

### Permitir ZIP sin verificar

Quien gestiona el servidor puede añadir esta línea al archivo `.env` del motor privado:

```
SOLQUBIT_ALLOW_UNVERIFIED=true
```

Con ella, el panel acepta ZIP que no estén publicados en GitHub y los marca en rojo como **Paquete sin verificar**. Sigue comprobando la integridad de cada archivo, pero ya no puede saber quién generó el paquete. Úsalo solo en desarrollo, con versiones propias que no publicas o en un hosting sin conexión a GitHub, y quítalo cuando ya no lo necesites. No se puede activar desde el panel.

## Saltar versiones

Puedes pasar directamente de cualquier versión a la última, siempre que sea igual o posterior a la versión mínima del paquete (1.0.0). Cada versión incluye la lista de sus archivos (`core/resources/solqubit/files.json`). Al actualizar, se retiran los archivos que tu versión tenía y la nueva ya no incluye, aunque te hayas saltado versiones intermedias. El resultado es igual que una instalación limpia.

El paquete puede añadir archivos en carpetas nuevas del motor y de la carpeta pública. Nunca toca archivos que empiezan por punto (`.env`, `.htaccess`), `storage/`, `index.php` ni el motor desde la carpeta pública. Tampoco retira migraciones ni la biblioteca. Las carpetas que quedan vacías se borran.

## Comprobar los archivos instalados

En **Actualizaciones → Comprobar los archivos**, **Comprobar archivos** compara el código con la lista de tu versión. Informa de cuántos archivos están modificados, cuántos faltan y cuántos sobran. Solo informa: no cambia nada. La siguiente actualización deja todo como en la versión nueva.

## Qué cambia y qué permanece

- **Cambian** el código PHP, las dependencias incluidas, las vistas y los recursos de la plantilla. Solo se copian los archivos autorizados por el manifiesto. Los cambios hechos a mano en esos archivos se reemplazan.
- **Permanecen** `.env`, `.htaccess`, `APP_KEY`, las credenciales, la biblioteca, las sesiones, los registros, la ruta del administrador y las referencias a las carpetas privadas.
- **Base de datos:** entradas, páginas, usuarios, menús, colores, correo y ajustes se conservan. Solo se ejecutan migraciones nuevas; nunca `migrate:fresh`, seeders ni contenido de muestra. Un paquete no puede modificar ni eliminar una migración existente.
- **Temporales:** mientras dura la operación se guarda una copia del código anterior y de los archivos retirados. Al completarse se borran junto con el ZIP. Un paquete validado pero no aplicado se borra al subir otro, pasado un día.

## Límites y permisos

El ZIP puede ocupar hasta 128 MB y 768 MB descomprimido. Para subirlo a mano, PHP y el servidor web deben permitir su tamaño real. La sección **Requisitos del servidor** muestra los límites de tu servidor. Para descargarlo desde GitHub, el servidor necesita conexiones HTTPS salientes.

PHP necesita escribir en el motor privado, en los recursos públicos y en `storage/app/updates`, con espacio para extraer el paquete y guardar la recuperación. No uses permisos 777.

## Si algo falla

- **Si la copia se interrumpe,** la siguiente petición recupera el código anterior antes de abrir el CMS.
- **Si una migración falla,** el sitio queda en mantenimiento con la versión nueva: los cambios parciales en MySQL/MariaDB no se deshacen solos.
  - La misma sesión que inició la actualización puede reintentar durante una hora desde la página de recuperación.
  - También puedes corregir la causa y ejecutar `php artisan solqubit:update` en el motor privado.

Los detalles quedan en los registros. No vuelvas a ejecutar el instalador.

## Generar paquetes

Las releases se generan con GitHub Actions al subir un tag `vX.Y.Z` ([CONTRIBUTING.md](../CONTRIBUTING.md#publicar-una-versión)). La lista de cambios que muestra el panel sale de la sección de esa versión en `CHANGELOG.md`.

Para generar los paquetes en tu equipo: `node scripts/build-release.mjs` (con Docker) o `php scripts/build-release.php` (con PHP y Composer). Los dos ZIP quedan en `release/`. Para probar una actualización en local, sube el ZIP de actualización en **Actualizaciones**: el panel lo marca como **Paquete sin verificar**. La versión mínima desde la que se puede actualizar está en `$minimumVersion`, al principio de `scripts/build-release.php`.
