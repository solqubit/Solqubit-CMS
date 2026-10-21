# Panel ordenado y paquetes separados de instalación y actualización

Fecha: 20/10/2026. Estado: aprobado por el propietario en el chat; este documento lo pone por escrito.

## Por qué

El propietario siente que los elementos del panel «están regados»: 18 entradas en la barra lateral, 10 páginas de ajustes repartidas en grupos sin un criterio claro, la portada escondida dentro de Páginas y tres listas de clasificación sueltas. Al revisar el funcionamiento aparecieron además 17 fallos (inventario completo en la carpeta de notas de la sesión, `inventario-panel.md`).

Aparte, la actualización por ZIP ya falló una vez en las pruebas de la 1.3.0 ( «El paquete contiene un archivo público no permitido») y el propietario quiere que esa parte sea fiable. También quiere que cada release tenga **un archivo para instalar y otro solo para actualizar**, para manejar cada cosa por separado.

No hay usuarios todavía (0 descargas) y el propietario va a borrar las releases publicadas, así que **no hace falta mantener compatibilidad** con instalaciones ni paquetes anteriores. El propietario confirmó que no tiene ningún sitio real con 1.x: la 2.0.0 se trata como una primera versión y no hay camino de actualización desde 1.x.

## Objetivos

1. Un panel sobrio con lugares lógicos: menos entradas en la barra lateral y un único sitio para los ajustes.
2. Corregir los 17 fallos del inventario.
3. Añadir las mejoras pequeñas acordadas.
4. Dos paquetes por versión: uno de instalación y otro de actualización, cada uno con su huella.
5. Un actualizador que no falle por motivos evitables, probado de punta a punta con una instalación real.

## Fuera de alcance (tareas aparte, después)

- Selector de imágenes con miniaturas.
- Filtro público real por etiqueta.
- «Parte X de N» y anterior o siguiente dentro de una serie.
- Comentarios.
- Más redes sociales, formato de fecha configurable y opciones del RSS.

## Lo que no puede pasar (antiobjetivos)

- Cambiar el aspecto visual del panel: al propietario le gusta. Se reorganiza y se recorta texto, pero se conservan los estilos, las tarjetas y las pestañas actuales (`site/editor/admin.css`).
- Modificar migraciones existentes. Si hace falta un cambio en la base de datos, va en una migración nueva.
- Añadir dependencias de Composer o npm.
- Subir algo a GitHub: ni push, ni tag, ni release. Commits locales solo si el propietario los pide.
- Debilitar la protección del actualizador. Ninguna regla nueva puede permitir escribir en `.env`, `storage/`, `index.php`, `.htaccess` ni en el motor desde la carpeta pública.

## 1. Organización del panel

### Barra lateral nueva (administrador)

```
Escritorio
Estadísticas
Contenido       Entradas · Páginas · Biblioteca · Clasificación
Ajustes         (una sola entrada con submenú propio)
Administración  Usuarios · Actualizaciones
```

Son 9 entradas en lugar de 18.

El editor de contenido ve: Escritorio, Entradas, Biblioteca y Clasificación, con las mismas restricciones de permisos que hoy. Hoy ve Categorías, Etiquetas y Series por separado; ahora las ve dentro de Clasificación.

### Clasificación

Un Cluster de Filament (`App\Filament\Clusters\Classification`) que agrupa CategoryResource, TagResource y SeriesResource. La subnavegación va arriba, como pestañas. La entrada de la barra lateral lleva a Categorías.

### Ajustes

Un Cluster `App\Filament\Clusters\Settings`, solo para administradores, con la subnavegación en grupos:

| Grupo | Página | Viene de |
|---|---|---|
| Sitio | **Identidad** | SiteSettings (nombre, iconos, pie, redes) |
| Sitio | **Colores** | AppearanceSettings (entrada propia del submenú; decisión del constructor: es más simple que una pestaña y no añade nada a la barra lateral) |
| Sitio | **Portada** | Nueva. Edita el registro `pages.kind = home`: bienvenida, texto opcional sobre el listado, listado (título, destacada, entradas por página) y buscadores |
| Sitio | **Menús** | NavigationSettings |
| Publicación | **Direcciones** | Prefijos de URL de las entradas (sale de GeneralSettings) |
| Publicación | **SEO** | SeoSettings, más la imagen por defecto al compartir y la opción de ocultar el sitio a los buscadores |
| Publicación | **Monetización** | MonetizationSettings |
| Sistema | **General** | Zona horaria (sale de GeneralSettings) |
| Sistema | **Correo** | MailSettings |
| Sistema | **Seguridad** | AccessSettings, con el nombre nuevo |

Al entrar en «Ajustes» se abre Identidad.

Colores conserva su botón «Aplicar variante», porque es una acción inmediata y no un formulario. Identidad se guarda con un único botón.

Las estadísticas siguen siendo una entrada propia de la barra lateral, con su ventana de Ajustes. Así su configuración queda junto a sus datos.

### Páginas

Solo contiene páginas de verdad: Sobre mí, Series y las adicionales. Inicio desaparece de la lista y del buscador del panel. Se edita en Ajustes → Portada.

En Series y Sobre mí, el interruptor «Página publicada» pasa a llamarse **«Mostrar esta sección en el sitio»**, con una ayuda que lo explica.

### Administración

- **Usuarios**: igual que ahora, con los arreglos descritos más abajo.
- **Actualizaciones**: es la página SystemStatus con otro nombre. En la cabecera, la versión instalada y la última publicada. Después, en este orden:
  1. Actualizar desde GitHub.
  2. Subir el ZIP de actualización.
  3. Requisitos del servidor (plegado si todos se cumplen).
  4. Comprobación de archivos (plegada).
  5. Ubicación de los archivos (plegada).
  
  Se quitan los párrafos sobre el instalador. El distintivo «Nueva versión» del menú se mantiene.

### Escritorio

- Aviso de versión: igual.
- «¿Qué quieres hacer hoy?»: mismo aspecto, pero solo con **Escribir una entrada**, **Mis entradas**, **Portada** (solo administradores) y el enlace «Ver mi sitio». Se quitan las tarjetas que repiten la barra lateral.
- Visitas de 30 días: igual, con la etiqueta corregida (ver fallo 15).
- Cifras de contenido: cada cifra abre la lista de Entradas con su filtro. Para un editor, las cifras cuentan solo sus propias entradas y sus archivos. Se añade «Archivadas».

### Textos

- Se quita el recuadro de cabecera (`sq-settings-guide`) en las páginas donde repite el título. Se conserva solo donde avisa de algo que no se ve de otro modo: el estado guardado de Monetización y la versión en Actualizaciones.
- SEO deja de ser una página de explicaciones: queda con sus campos, enlaces a robots.txt, sitemap y Search Console, y un único bloque plegado «Qué hace el CMS por ti».
- Las ayudas que citan rutas del menú escritas a mano («Administración → Direcciones y región», «Sitio web → Menús», etc.) pasan a ser **enlaces generados con `::getUrl()`** al lugar correcto. Hay una lista completa en el inventario, incluidos `PostPermalinks.php:75`, `PostResource.php:109,130,144`, `PageResource.php:116`, `mail/smtp-test.blade.php:5` y los documentos `docs/*.md`.
- Las palabras clave de AdminSearch se adaptan a la nueva estructura y se añade Estadísticas («visitas», «estadísticas»).

## 2. Fallos que se corrigen

| # | Fallo | Arreglo esperado |
|---|---|---|
| 1 | Una imagen usada alguna vez no se puede borrar de la Biblioteca, porque cuenta todas las revisiones y las entradas en la papelera (`Media::isReferenced`) | Las revisiones dejan de bloquear el borrado. Se guardan como máximo las 20 revisiones más recientes por entrada. Si al recuperar una revisión falta un archivo borrado, ese bloque o esa portada se omite y se avisa. Las entradas en la papelera sí siguen bloqueando, y el mensaje lo dice |
| 2 | No existe «Eliminar definitivamente» para entradas | Acción por registro y masiva, solo para administradores y solo en la papelera. Mismo texto y modal que en Páginas |
| 3 | «Recuperar revisión» guarda como borrador al momento | Carga la revisión en el formulario sin guardar (estado y fecha actuales intactos) y recupera también las etiquetas. El usuario revisa y pulsa Guardar |
| 4 | Las páginas de ajustes no avisan de cambios sin guardar | Las páginas con formulario del Cluster Ajustes usan `HasUnsavedDataChangesAlert` |
| 5 | Dos botones de guardar que se pisan en Identidad | Un único botón para todo el formulario. Se quita «Guardar redes sociales» |
| 6 | Desactivar o degradar al último administrador falla en silencio | El error llega al campo (`data.role`) con una notificación |
| 7 | Un administrador puede quitarse su propio rol o desactivarse | En su propio registro, «Permisos» y «Permitir iniciar sesión» quedan desactivados, con una ayuda que lo explica |
| 8 | Cambiar la propia contraseña desde Usuarios cierra la sesión | Si el registro es el usuario actual, se actualiza el hash de la sesión, como hace Mi perfil |
| 9 | El editor ve borradores ajenos en Entradas, en el buscador y en las cifras | La lista y el buscador muestran al editor sus entradas más las publicadas de otros (se ajusta la prueba `SecurityHardeningTest.php:105`) |
| 10 | Una página «sobre-mi» o «inicio» da «valor repetido» | La validación de la dirección ignora las páginas del sistema, cuya dirección no se usa. Las páginas en la papelera siguen reservando su dirección, y el mensaje lo explica |
| 11 | Cambiar la dirección de una página rompe el enlace antiguo | Redirección 301 desde la dirección anterior, como con las entradas. Si hace falta una tabla, va en una migración nueva |
| 12 | El título de la portada es «Inicio — Nombre» | La portada usa solo el nombre del sitio, o su título SEO si lo tiene |
| 13 | El menú del pie empieza como copia del encabezado | Empieza vacío |
| 14 | Las fechas del panel salen en UTC | Las columnas de fecha del panel y la página Actualizaciones usan la zona horaria del sitio |
| 15 | «Visitantes únicos» de 30 días suma los únicos de cada día | Se renombra a «Visitantes diarios (suma)», o se calcula de verdad si los datos lo permiten. Decisión del constructor, siempre que la etiqueta no engañe |
| 16 | La vista previa de una entrada en la papelera da 404 | Se oculta la acción en las filas de la papelera |
| 17 | Valores por defecto distintos en el formulario y en la web | Se unifican («Últimas entradas.», mostrar descripción) |

### Controles que confunden

- «Destacar en portada» pasa a ser exclusiva: marcarla en una entrada la desmarca en las demás, con una ayuda que lo dice. Solo la pueden marcar los administradores.
- «Mostrar enlace a mi canal de YouTube» avisa, con un enlace a Identidad → Redes sociales, cuando no hay un perfil de YouTube.
- Los textos de redes sociales dicen dónde aparecen de verdad: encabezado, pie y menú móvil.
- Al elegir una página en Menús, el texto del enlace se rellena con su nombre si está vacío.
- La zona horaria se aplica también a los selectores de fecha del panel.

## 3. Mejoras que entran

- **Entradas**:
  - columnas «Autor» y «Publicada» (fecha de publicación o «Programada para…»);
  - filtro «Programadas»;
  - acciones masivas «Publicar» y «Archivar»;
  - el administrador puede cambiar el autor;
  - crear etiquetas desde el propio formulario (`createOptionForm`);
  - «Minutos de lectura» se calcula solo si se deja vacío (200 palabras por minuto, como mínimo 1);
  - «Nivel» es opcional, con la opción «Sin nivel», que no se muestra en el sitio;
  - las tres acciones de cada fila se agrupan en un menú.
- **Biblioteca**:
  - subir varios archivos a la vez;
  - en la ficha de un archivo: vista previa de la imagen, la dirección para copiar y «Usado en…» (entradas, páginas, identidad y autores).
- **Clasificación**:
  - al borrar, el aviso dice cuántas entradas se ven afectadas;
  - se quitan del formulario «Nota interna» e «Imagen de referencia» de Categorías, y «Nota interna» de Etiquetas (las columnas de la base de datos se quedan);
  - el identificador de las etiquetas se genera solo y deja de mostrarse.
- **Usuarios**: columna con el número de entradas y otra que indica si tiene activada la verificación en dos pasos.
- **SEO**: imagen por defecto al compartir (`og:image` de la portada y de las páginas sin imagen) y la opción «Pedir a los buscadores que no indexen el sitio» (noindex global y robots.txt que lo refleje).

## 4. Paquetes separados

### Archivos de cada versión

| Archivo | Para qué |
|---|---|
| `solqubit-X.Y.Z-instalacion.zip` y `.sha256` | Instalar en un sitio nuevo. Contenido actual: archivos públicos más `install-core.php` con el motor dentro. Ya no lleva `.solqubit-release.json`, porque el panel no lo usa para instalar |
| `solqubit-X.Y.Z-actualizacion.zip` y `.sha256` | Actualizar desde el panel. Lleva `.solqubit-update.json` (manifiesto con formato 3) más los archivos con su ruta final: `core/...` y `site/...`. No incluye instalador, `index.php`, `.htaccess` ni nada que no se actualice |
| `solqubit-X.Y.Z-manifest.json` | Se publica en la release, igual que ahora: se usa para calcular los archivos obsoletos de la siguiente versión |

- `scripts/build-release.php` genera los dos ZIP en `release/`.
- `.github/workflows/release.yml` publica los cinco archivos y la atestación de los dos ZIP. No se ejecuta: no se sube nada.
- `GitHubReleases` y `PackageOrigin` descargan y comparan **el ZIP de actualización** y su `.sha256`.
- `UpdatePackage` acepta solo el formato 3 y deja de aceptar el formato 1 y el unificado (se borra `UnifiedPackage`). Si alguien sube el ZIP de instalación, el mensaje lo dice claramente: «Este es el ZIP de instalación. Para actualizar, usa solqubit-X.Y.Z-actualizacion.zip».
- El paquete instalado sigue llevando `core/resources/solqubit/files.json` y la lista de archivos obsoletos sigue funcionando igual.

### Un actualizador que no falle por motivos evitables

El fallo de la 1.3.0 vino de que **el actualizador de la versión instalada decide qué rutas acepta**: una versión nueva no podía añadir archivos en una carpeta pública nueva. Para que no se repita:

1. **Rutas permitidas por exclusión, no por lista cerrada.**
   - `core/`: cualquier ruta salvo los archivos que empiezan por punto (`.env*`, `.htaccess`, que protege el motor dentro del dominio), `storage/`, `bootstrap/cache/`, `bootstrap/public-path.php`, `bootstrap/hosting-protection.json`, `bootstrap/update-guard.php` y `database/*.sqlite*`.
   - `site/`: cualquier ruta salvo `index.php`, `.htaccess`, `install-core.php`, los archivos que empiezan por punto y lo que esté dentro de la carpeta del motor cuando esta vive dentro de la carpeta pública. Esto último se comprueba con la ruta real en `solqubit_update_target`: un destino en `site/` nunca puede quedar dentro de `base_path()`, y un destino en `core/` nunca puede quedar dentro de `storage_path()`.
   - Se mantienen el rechazo de enlaces simbólicos, de `..` y de rutas duplicadas, y los límites de tamaño.
2. **El generador comprueba lo mismo que el sitio.** `build-release.php` usa la misma función `allowedPath` y falla si el paquete de actualización contiene una ruta que un sitio instalado rechazaría. Esto ya existe; se conserva con la regla nueva.
3. **Comprobaciones previas completas antes de tocar nada.** Hoy ya se comprueban la versión, PHP, las extensiones, el espacio libre, los permisos y las migraciones modificadas. Se añade la comprobación del límite de tiempo y memoria, que **bloquea** la validación con un mensaje claro: si `max_execution_time` está entre 1 y 59 s y no se puede ampliar con `set_time_limit`, o si `memory_limit` es menor de 128 MB (decisión del constructor).
4. **Mensajes claros.** Cada error del actualizador dice qué pasó y qué hacer, sin términos internos. La página Actualizaciones muestra el error junto al formulario correspondiente.
5. **Prueba de punta a punta.** `scripts/test-zip-update.mjs` se adapta al nuevo formato. A partir del ZIP de actualización de la versión N genera uno de N+1 que reproduce los casos que ya fallaron o pueden fallar:
   - un archivo en una **carpeta pública nueva** (`site/nueva/prueba.js`);
   - una carpeta nueva en el motor;
   - un archivo retirado;
   - una migración nueva;
   - un cambio de vista.
   
   El script instala N desde el ZIP de instalación en un contenedor limpio (`compose.release-test.yaml`), actualiza desde el panel subiendo el ZIP y comprueba la versión, la migración, el archivo nuevo servido por el servidor web, el archivo retirado y que el sitio y el panel responden. Se ejecuta en las tres distribuciones que ya cubre `test-root-install.mjs` (motor en `private/`, dentro del dominio y fuera de la carpeta pública) cuando el entorno lo permita; como mínimo, en la predeterminada.

### Versión

**2.0.0**, porque cambia el formato de los paquetes y no se puede actualizar desde 1.x (decisión del constructor). `min_version` es 2.0.0. Se añade la sección `## [2.0.0]` a `CHANGELOG.md`. Se generan los paquetes en `release/` para que el propietario los pruebe. No se crea tag ni se publica.

## 5. Documentación

Se actualizan `docs/INSTALL.md`, `docs/UPDATE.md`, `docs/USO.md`, `docs/THEMES.md` y el README con los nombres nuevos de las secciones y los dos archivos de cada versión.

## Qué decide el constructor

- Qué componentes de Filament usar en cada caso (Clusters, `SubNavigationPosition`, acciones, tabs), siempre que el resultado se vea como hoy.
- Las rutas de las páginas nuevas (por ejemplo `/admin/ajustes/identidad`), en español y sin romper las redirecciones internas. `AccessSettings` deja de usar la ruta escrita a mano (fallo F4 del informe de ajustes).
- Dividir el trabajo en tareas y commits locales. Commit solo si el propietario lo pide.
- Pruebas: cada fallo corregido lleva su prueba de regresión, marcando el CMS como instalado como en `CmsTest`. La suite completa, Pint y las pruebas JS en verde. Al final, la prueba de punta a punta del ZIP.
