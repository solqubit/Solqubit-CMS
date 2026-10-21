# Editor con vista previa real, portada por secciones y diseño «solo fondo»

Fecha: 20/10/2026. Estado: acordado con el propietario en el chat, que pidió construirlo («dale, veamos cómo queda»); este documento lo pone por escrito. Parte del informe `revision-editor-y-entradas.md` (carpeta de notas de la sesión).

## Por qué

El propietario pidió una revisión honesta del editor de páginas y entradas. Lo que más le molesta:

- **No ve cómo quedará lo que edita**, sobre todo en la portada. La portada tiene elementos fijos que no se pueden mover.
- **El sombreado le desagrada**: las tarjetas «flotan» con una sombra sólida desplazada y un borde grueso.
- Al revisar las maquetas notó que **«pierde contexto»**: el panel, el editor y el blog usan letras distintas, y lo que se escribe no se parece a lo que se publica.

Quiere un editor cómodo y modular, en el que cada ajuste esté donde tiene sentido, pensado para usarlo como blog de verdad.

## Objetivos

1. Un diseño público **«solo fondo»** con sombra suave.
2. **Letras unificadas** en el blog, el editor y el panel.
3. **Vista previa real** de entradas, páginas y portada, con los cambios sin guardar.
4. **Portada por secciones** que se añaden, se ocultan y se ordenan.
5. Un editor de entradas y páginas **ordenado y parecido a la página publicada**.
6. Bloques del editor **definidos en un solo sitio**, más los arreglos pequeños de la revisión.

## Fuera de alcance (más adelante)

- Bloques nuevos: botón o llamada a la acción, galería y cita con autor.
- «Categoría principal» para elegir qué categoría muestra la tarjeta.
- Mover la biografía del autor al final del artículo.
- Formulario de contacto.
- Editar sobre la propia página pública (constructor visual).

## Lo que no puede pasar (antiobjetivos)

- Cambiar el aspecto del panel más allá de la letra: sus colores, tarjetas, pestañas y la organización de la 2.0.0 se conservan (`site/editor/admin.css`). La excepción son los bloques del editor de documentos, que imitan al blog.
- Modificar migraciones existentes. Este trabajo no necesita base de datos nueva; si apareciera una necesidad, iría en una migración nueva.
- Añadir dependencias de Composer o npm. Las letras ya están en `site/themes/solqubit/fonts/`.
- Que una portada existente cambie al actualizar: sin tocar nada, la portada nueva debe verse igual que la actual (con el diseño nuevo).
- Que la vista previa cuente visitas, muestre anuncios o permita navegar fuera de ella.
- Subir nada a GitHub (ni push, ni tag, ni release).

## Coordinación

Los archivos afectados (`PostResource`, `PageResource`, `HomeSettings`, `AdminPanelProvider`, las vistas del tema y las pruebas) tienen cambios sin commit de la sesión «Revisión completa del panel» (versión 2.0.0), pendientes de revisión del propietario. Este trabajo **parte de ese estado** y entra en la misma versión 2.0.0, porque todavía no hay usuarios. Cada cambio se apunta en la sección `## [2.0.0]` de `CHANGELOG.md`. No se hace commit de cambios ajenos.

La sesión «Corregir los 4 detalles» también dejó cambios sin commit que hay que conservar:
- los filtros «Mías» y «Publicadas» de la lista de Entradas, que usan los enlaces del Escritorio;
- la prueba `test_changing_only_tags_keeps_earlier_revision_tags`.

## 1. Diseño «solo fondo» (blog público)

Aplicado en `site/themes/solqubit/editorial-theme.css` y `cms.css`, en modo claro y oscuro y con todas las variantes de color.

**Maquetas aprobadas**, en la carpeta de notas de la sesión, subcarpeta `maquetas/`:
- `solo-fondo.html` y sus capturas `fondo.png` y `fondo-post.png`: el blog completo con el diseño nuevo, con botones para compararlo con el actual.
- `avisos.html`: la variante elegida es la 2, «Solo fondo».
- `portada-editor-v3.html` y `portada2.png`: la página Portada.

La referencia de CSS es `solo-fondo.css`, en la misma carpeta de notas. No hay maqueta de la pantalla dividida del editor ni del menú «Insertar»: siguen la descripción de este documento y el estilo de la maqueta de la portada.

- **Contenedores sin borde.** Tarjetas, entrada destacada, series, cabecera del artículo, índice, «Continúa leyendo», código, salida de terminal, tablas, avisos, listas destacadas, preguntas desplegables, enlaces de consulta, adjuntos, estados vacíos, menú «Más», buscador y anuncios se distinguen solo por su color de fondo.
- **Sombra suave**, difuminada y sin desplazamiento duro, solo en lo que flota: tarjetas del listado, entrada destacada, series, cabecera del artículo, índice, «Continúa leyendo» y botones. Los bloques de lectura quedan planos.
  - Desaparece la sombra «de pegatina» (`0 Npx 0 color`) de todo el tema.
  - Las sombras se definen con variables (`--soft-shadow`, `--soft-shadow-hover`, `--soft-shadow-small`, `--button-soft-shadow`), con valores propios en modo oscuro.
- **Imágenes sin marco**, solo con las esquinas redondeadas.
- **Píldoras, etiquetas, paginación, botón de búsqueda, redes sociales y capítulos de series** con un fondo suave (`--soft`) en lugar del contorno.
- **Campos de búsqueda** con fondo suave. El foco se marca con un anillo del color de acento.
- **Avisos:** sin borde ni barra izquierda, con el icono sin recuadro. Cada tono tiene su icono: información (círculo con i), nota (bombilla) y advertencia (triángulo).
- **Citas** (`blockquote`): sin barra, con fondo suave de acento y comillas grandes.
- **Índice:** la sección activa se marca solo con fondo.
- **Se conservan** las líneas finas que separan filas de tablas, preguntas desplegables y enlaces de consulta, y las de la cabecera y el pie del sitio.
- Se borra el comentario del CSS que exigía conservar los desplazamientos de las sombras.

## 2. Letras unificadas

- **Títulos:** Nunito (`--heading`).
- **Texto e interfaz:** DM Sans (`--sans`).
- **Código:** IBM Plex Mono, que queda **solo** para código: bloques de código, salida de terminal, código en línea y la tecla `/` del buscador.
  - Los rótulos que hoy usan la monoespaciada pasan a DM Sans: «Entrada destacada», el nivel de dificultad, el contador de entradas, el número de entradas de una serie, el tamaño de los adjuntos, el resumen de la paginación, el botón de cerrar diálogos y la cabecera del bloque de código. Se escriben en mayúsculas pequeñas con espaciado (700, 12 px, `letter-spacing: .08em`).
- **Panel:** usa DM Sans en lugar de Inter, con `->font('DM Sans', url: …, provider: LocalFontProvider::class)` en `AdminPanelProvider` y una hoja `@font-face` local que apunte a los archivos del tema. Los títulos de página del panel (h1) usan Nunito. Solo cambia la letra.
- **Editor de documentos:**
  - el título y los encabezados del documento, en Nunito;
  - el texto, en DM Sans;
  - el código, en IBM Plex Mono;
  - las previsualizaciones de bloques (`.sq-block-preview`), igual: dejan `system-ui`.

## 3. Vista previa real

Un único motor que pinta la **plantilla pública de verdad** con los datos del formulario **sin guardar**.

- **Endpoint:** amplía o sustituye `EditorPreviewController`.
  - Recibe el contexto (`post`, `page` o `home`), el registro si existe, el estado del formulario y el modo (claro u oscuro).
  - Devuelve el HTML completo de la página pública.
  - Construye modelos en memoria, sin guardar, con el estado del formulario: un `Post` o una `Page`, con portada, categorías, etiquetas, autor, documento convertido con `ContentDocument::toBlocks`, minutos de lectura, nivel y fechas. Después los pasa por la misma lógica que `BlogController::article()`, `home()` y la de las páginas.
  - Si hace falta, esa lógica se extrae de `BlogController` a un servicio reutilizable, para que la web y la vista previa pinten exactamente lo mismo.
- **Mismos permisos que hoy:** editor o administrador activos; las páginas y la portada, solo administradores. Se validan los mismos campos, y los archivos de la biblioteca solo se muestran si el usuario puede verlos (`MediaAccess`).
- **Sin efectos.** Sin contador de visitas, sin anuncios (los huecos de publicidad no se pintan), con `noindex` y `Cache-Control: private, no-store`.
- **Los enlaces de la vista previa no navegan.** Se ignoran los clics o se abren en una pestaña nueva; el constructor elige.
- **En el panel**, la vista previa se muestra en un `iframe` con `srcdoc` y `sandbox` sin scripts. Barra con «Escritorio | Móvil» y «Claro | Oscuro», más un estado («Actualizando…», «Cambios sin guardar»).
  - La página se pinta al ancho elegido (unos 1280 px en escritorio y 390 px en móvil) y se escala para caber en la columna.
  - Se actualiza mientras se escribe, con una espera de unos 400 ms.
- **Entradas y páginas:**
  - El botón «Vista previa» va **en la cabecera del editor**. Sustituye a «Ver versión guardada» y al botón «Vista previa» de la barra del documento.
  - Al abrirla, la pantalla se divide: el documento a la izquierda y la vista previa a la derecha; la columna de ajustes se oculta mientras tanto.
  - Al cerrarla vuelve la columna de ajustes. En pantallas estrechas, la vista previa ocupa la pantalla con un botón para volver.
  - Ya no hace falta enlazar a `posts.preview` desde el editor ni desde la lista. Las rutas `posts.preview` y `pages.preview` se mantienen para quien las use.
- **Portada:** la vista previa está siempre visible a la derecha (ver punto 4).

## 4. Portada por secciones

Ajustes → Portada (`HomeSettings`) pasa a ser:

- **A la izquierda**, la lista de secciones con un `Builder` de Filament. Se arrastran para ordenarlas, se pliegan y se añaden desde un menú.
  - Cada sección tiene un interruptor «Mostrar en la portada», para ocultarla sin borrarla.
  - Plegada, su cabecera dice el tipo y un resumen («Astronomía · 3 entradas»).
- **A la derecha**, la vista previa real de la portada (punto 3).
- **Abajo**, «Cómo aparece en buscadores» (título y descripción SEO, como ahora) y la barra de guardar actual.

### Tipos de sección

| Tipo | Opciones | Notas |
|---|---|---|
| **Bienvenida** | Primera línea, segunda línea de color, mensaje, icono. «Mostrar título» y «Mostrar mensaje», como hoy | La de hoy. Si se oculta el título, la página conserva un `h1` solo para lectores de pantalla |
| **Entrada destacada** | Qué entrada (selector de entradas publicadas o programadas); «Si no eliges ninguna, mostrar la más reciente» | Sustituye a «Destacar en portada» del editor de entradas |
| **Últimas entradas** | Título, entradas por página, mostrar buscador, mostrar filtro por tema | Como máximo una por portada, porque lleva las páginas numeradas. Excluye la entrada destacada si se muestra |
| **Entradas de un tema** | Categoría, título (por defecto, el nombre de la categoría), cuántas (3, 6 o 9), enlace «Ver todas» a `/?category=…` | Puede haber varias |
| **Series** | Título y cuántas (3 o 6), con enlace a la página Series si está publicada | Usa las tarjetas de series actuales |
| **Texto libre** | Un documento con el editor de contenido | Sustituye a «Texto sobre el listado». Puede haber varias |
| **Anuncio** | Ninguna | Pinta el hueco `home_bottom` donde se coloque. Solo se ofrece si la monetización está activada; si no, no se pinta. En Monetización, la etiqueta del hueco pasa a «Portada · donde coloques la sección Anuncio» |

### Reglas

- **Almacenamiento:** en `pages.settings['sections']` del registro `kind = home`, como una lista de `{type, visible, …opciones}`. No hay migración.
- **Conversión.** Si una portada no tiene `sections`, se construyen a partir de los ajustes actuales, en este orden:
  1. Bienvenida, con `heading`, `accent_heading`, `intro`, `icon`, `show_heading` y `show_intro`.
  2. Texto libre, si `content` no está vacío.
  3. Entrada destacada, con la entrada marcada como `featured` y visible si `show_featured`.
  4. Últimas entradas, con `catalog_title` y `per_page`; buscador y filtro activados.
  5. Anuncio, si la monetización está activada.

  El resultado tiene que verse igual que la portada actual. Lo mismo vale para una instalación nueva: `WelcomeSample` y el instalador crean la portada con estas secciones.
- **Al buscar, filtrar** (`?q=`, `?category=`) **o pasar de página** (`?page=2`), se omiten las secciones que muestran entradas elegidas: Entrada destacada, Entradas de un tema y Series. Se siguen pintando la Bienvenida, el Texto libre, Últimas entradas (con los resultados) y Anuncio, que conserva las reglas actuales: no aparece al buscar o filtrar. Es el comportamiento de hoy. Si no hay una sección Últimas entradas visible, los resultados se muestran igualmente con su formato, en el lugar donde iría. *(Decisión del constructor: así una portada sin tocar se ve igual en todas sus páginas.)*
- **Entrada destacada.** El campo `posts.featured` deja de editarse desde el panel. Se quitan el interruptor del editor, la columna «Destacada» de la lista y su lógica de exclusividad en el modelo, si nada más la usa. La columna de la base de datos se queda.
- **Archivos de la biblioteca:** los que aparecen en los documentos de Texto libre se suman a la relación `media` de la portada (`Page::saved`), igual que los del contenido de las demás páginas. Así siguen funcionando `MediaAccess`, «Usado en…» y la protección contra el borrado. El campo `content` de la portada deja de usarse para pintarla, pero se conserva para la conversión.
- **Validación del lado del servidor:** tipos conocidos, opciones con su rango, como máximo una «Últimas entradas», categorías y entradas existentes, y el documento de Texto libre validado con `ContentSchema` y `MediaAccess`.
- La plantilla `home.blade.php` recorre las secciones e incluye una vista parcial por tipo (`themes/solqubit/home/{tipo}.blade.php` o equivalente). Se actualiza `docs/THEMES.md`.

## 5. Editor de entradas y páginas

### En el documento (columna izquierda)

- **Imagen de cabecera arriba del título.** Un selector de la biblioteca con miniatura, el mismo `mediaSelect` actual, presentado como cabecera del documento. Cuando no hay imagen, se ve un espacio discreto «Añadir imagen de cabecera».
  - **En las entradas** es `cover_id`.
  - **En «Sobre mí» y las páginas adicionales** es su imagen principal.
- **Entradilla bajo el título**, en lugar del «Resumen para el listado»: un campo sin borde, con la letra y el tamaño de la entradilla publicada.
  - Ayuda: «Aparece bajo el título y en las tarjetas del blog.»
  - Mismo campo `excerpt` y mismo límite.
  - Solo en entradas. Las páginas conservan su «Descripción» con «Mostrar descripción».

### Barra del documento

- **Visibles:** Párrafo, H2, H3, negrita, cursiva, subrayado, tachado, código, enlace, listas, cita, separador, deshacer y rehacer.
- **«Imagen»** y un botón **«Insertar»** que abre un menú con los mismos elementos y el mismo buscador que «/».
  - Desaparecen la fila de 11 botones de colores y el botón «Vista previa» de la barra, que pasa a la cabecera.
  - «Enfoque» se queda.

### Publicar

- **Estado:** Borrador, Publicada o Archivada. «Publicada» con fecha futura equivale a programada; no hay un estado nuevo.
- **La fecha** solo se muestra con «Publicada», con la etiqueta «Publicar el» y la ayuda «En blanco, se publica al guardar. Una fecha futura la programa.»
- **El botón principal de la cabecera** cambia según el estado del formulario: «Guardar borrador», «Publicar», «Programar» o «Actualizar» (si ya estaba publicada). Es el mismo botón de guardar con otra etiqueta.
- **El aviso del autoguardado** sale de la descripción de la sección y pasa a la barra inferior del documento, junto al contador de palabras.

### Columna de ajustes (derecha), en este orden

1. **Publicación:** autor (solo administradores), estado y fecha.
2. **Clasificación:** categorías, etiquetas, serie y posición en la serie.
3. **Lectura:** minutos de lectura, nivel y «Mostrar la última actualización», que viene de Publicación.
4. **Dirección:** prefijo y parte final de la URL.
5. **Buscadores:** título y descripción.

- Las secciones 2 a 5 empiezan plegadas.
- «Destacar en portada» y la sección «Tarjeta de la entrada» desaparecen.

### Páginas

*(Decisión del constructor, tras la revisión: el modelo de datos no cambia.)* Hoy hay dos datos con sentido propio:
- `title`, el nombre de la página, que usan el panel, el buscador del panel, el título SEO por defecto y la etiqueta inicial de los menús;
- `settings.heading`, el título visible, que si está vacío usa el nombre.

Lo que confunde es cómo se presentan. Ejemplo: «Contacto» se muestra en la página como «Hablemos.».

- **En el documento**, el campo grande se llama «Título» y edita **el título visible** (`settings.heading`), con el nombre como marcador si está vacío. Es lo que se ve en la página.
- **En ajustes**, sección «Encabezado»:
  - «Nombre de la página», con la ayuda «Se usa en los menús, en el panel y en buscadores. En blanco, se usa el título»;
  - «Ocultar el título en la página», que es el `show_heading` actual invertido;
  - la descripción con su interruptor;
  - el enlace al canal de YouTube en «Sobre mí».
- **Al guardar:**
  - si el nombre está vacío, se guarda el título como nombre;
  - si el título es igual al nombre, `settings.heading` se guarda vacío.

  No hay conversión y la web no cambia.
- **Al crear una página**, la dirección se sugiere desde el título visible del documento.
- **Menús:** sin cambios. Cada enlace conserva su texto propio y el nombre de la página solo lo rellena al crearlo, como hoy.
- **Orden de los ajustes:** Visibilidad y menú, Encabezado, Dirección, Listado (solo Series), Buscadores.

### Lista de Entradas

- Una miniatura de la portada junto al título.
- Se quita la columna «Destacada».
- Las filas se abren al hacer clic.

## 6. Bloques en un solo sitio y arreglos pequeños

- **Cada clase de `App\Forms\Editor`** declara su tipo, nombre, icono, formulario del modal, valores iniciales al insertarlo y reglas de validación.
  - `EditorBlocks`, `ContentEditor`, `ContentSchema` y el modal de edición leen de ahí.
  - `PostResource::blocks()` desaparece.
  - `ContentDocument` y `block.blade.php` siguen funcionando con los mismos tipos y datos: el formato guardado no cambia.
  - Una prueba comprueba que cada tipo registrado tiene su formulario, sus reglas y su vista.
- **«Lista destacada» en el editor:** elementos con un espaciado normal y la viñeta alineada.
- **Bloque de código:** se quita el campo «Lenguaje» del formulario y de los valores iniciales. Los datos existentes con `language` se siguen aceptando y se ignoran.
- **Video propio:** sin `<figcaption>` cuando no hay pie y sin estilos en línea (pasan a CSS).
- **Restos del blog de tutoriales:**
  - «Consultar entrada» pasa a «Leer la entrada» y «Leer»;
  - el icono de terminal de la etiqueta de tema pasa a un icono neutro de etiqueta;
  - la etiqueta «Blog» se queda cuando no hay categoría;
  - el buscador dice «Buscar una entrada…»;
  - el ancla `#tutoriales` pasa a `#entradas`, con el mismo `id` y la paginación actualizada.
- **Bloques del editor** (`site/editor/editor.css` y `writing.css`): sin la barra de color de la izquierda, con fondo suave como en el blog.

## Pruebas

- **Cada comportamiento nuevo con su prueba,** marcando el CMS como instalado con un marcador temporal en `solqubit.installed_file`, como `CmsTest`:
  - la vista previa de entradas, páginas y portada: permisos, sin guardar nada, sin anuncios ni contador y con archivos ajenos ocultos;
  - la conversión de la portada antigua a secciones (se ve igual);
  - cada tipo de sección y las reglas de búsqueda y paginación;
  - el selector de entrada destacada y la vuelta a la más reciente;
  - el título visible y el nombre de las páginas al guardar;
  - el botón de publicar según el estado;
  - el registro de bloques.
- **Se adaptan las pruebas existentes** que usan `featured`, «Destacar en portada», `catalog_title`, `show_featured` o el texto «Consultar entrada».
- **Al final:** Pint en los archivos tocados, `node --test core/tests/JavaScript/*.mjs` y la suite completa de PHP en verde. Se revisan en el navegador la portada, una entrada y una página, en claro y oscuro y en escritorio y móvil.

## Qué decide el constructor

- Los componentes concretos de Filament y la forma de colocar la vista previa en las páginas de edición. El resultado se tiene que ver como en las maquetas.
- Dónde vive la lógica compartida entre `BlogController` y la vista previa.
- Los valores exactos de las sombras y de los fondos suaves, partiendo de `solo-fondo.css`.
- El orden de las tareas y su división. Commits solo de archivos propios y solo si el propietario los pide.
