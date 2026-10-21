# Editor con vista previa real, portada por secciones y diseño «solo fondo»: plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el blog adopte el diseño «solo fondo» con letras unificadas y que el editor de entradas, páginas y portada muestre la página real mientras se edita, con la portada organizada por secciones.

**Architecture:** La lógica que pinta las páginas públicas sale de `BlogController` a un servicio (`SiteRenderer`), que usan tanto la web como el nuevo endpoint de vista previa con modelos en memoria. La portada guarda una lista de secciones en `pages.settings['sections']`, gestionada por `HomeSections` (conversión, validación y datos), y la plantilla pinta una vista parcial por tipo. Los bloques del editor pasan a definirse en sus clases de `App\Forms\Editor` y el resto los lee de un registro.

**Tech Stack:** Laravel 13, Filament 5 (Builder, RichEditor/TipTap), Livewire, PHPUnit, Blade, CSS sin compilar, JavaScript en módulos ES (`node --test`).

**Spec:** `docs/superpowers/specs/2026-10-20-editor-portada-y-diseno-design.md`. Las maquetas y `solo-fondo.css` están en la carpeta de notas de la sesión, `C:\Users\damia\AppData\Local\Claude-Data\claude_pzs8sxrjxfjjc\side-session-notes\local_4dab6ef9-f54d-463e-88ee-01fb451d2f63\`.

## Global Constraints

- Textos visibles en español, saltos de línea LF.
- No modificar migraciones existentes; este trabajo no necesita ninguna nueva.
- Sin dependencias nuevas de Composer ni npm. Las letras están en `site/themes/solqubit/fonts/`.
- El panel solo cambia de letra. Sus colores, tarjetas y pestañas se mantienen, salvo los bloques del editor de documentos.
- Una portada sin tocar se ve igual que hoy, con el diseño nuevo.
- La vista previa no cuenta visitas, no muestra anuncios, no navega fuera y responde `Cache-Control: private, no-store` con `noindex`.
- Partir de los cambios sin commit de la 2.0.0 y conservarlos, incluidos los filtros «Mías» y «Publicadas» y `test_changing_only_tags_keeps_earlier_revision_tags`.
- Nada de commits de archivos ajenos. Commits locales solo si el propietario los pide; los pasos «Commit» de este plan se sustituyen por «dejar el árbol listo», salvo indicación.
- Las pruebas nuevas marcan el CMS como instalado con un marcador temporal en `solqubit.installed_file`, como en `CmsTest::setUp`.
- Comandos:
  - una prueba: `MSYS_NO_PATHCONV=1 docker exec solqubit-cms-web-1 sh -c 'cd /var/www/core && php -d memory_limit=1G vendor/bin/phpunit --colors=never --filter <Nombre>'`;
  - Pint: `MSYS_NO_PATHCONV=1 docker exec solqubit-cms-web-1 sh -c 'cd /var/www/core && vendor/bin/pint <archivos> --format agent'`;
  - JS: `node --test core/tests/JavaScript/*.mjs`.

## Review Focus

- **Una portada antigua con `content` y `show_featured = false`** debe convertirse sin la destacada y con el texto encima del listado → prueba en la tarea 6.
- **Una vista previa con un archivo de la biblioteca que el editor no puede ver** (de otro autor) no debe mostrarlo → prueba en la tarea 4.
- **Una portada con dos secciones «Últimas entradas»** enviadas a mano debe rechazarse al guardar → prueba en la tarea 6.
- **Una sección «Entradas de un tema» cuya categoría se borró** no debe romper la portada; se omite → prueba en la tarea 6.
- **Guardar una página con el nombre vacío** debe guardar el título visible como nombre y no fallar por `title` requerido → prueba en la tarea 9.

---

### Task 1: Diseño «solo fondo» y textos del tema público

**Files:**
- Modify: `site/themes/solqubit/editorial-theme.css`, `site/themes/solqubit/cms.css`
- Modify: `core/resources/views/themes/solqubit/block.blade.php` (aviso y video), `card.blade.php`, `home.blade.php` (buscador y ancla), `pagination.blade.php` si usa el ancla
- Modify: `core/resources/views/components/blog-icon.blade.php` (o donde estén los iconos de `x-blog-icon`): añadir `bulb`, `warning` y `tag` si no existen
- Test: `core/tests/Feature/ThemeDesignTest.php` (nuevo)

**Interfaces:**
- Produces: variables CSS `--soft-shadow`, `--soft-shadow-hover`, `--soft-shadow-small` y `--button-soft-shadow` en `:root` y `[data-theme=dark]`; iconos `bulb`, `warning` y `tag` de `x-blog-icon`.

- [ ] **Step 1: Write the failing tests** en `ThemeDesignTest` (setUp con marcador, como `SitePagesTest`):
  - `test_notice_uses_the_icon_of_its_tone`: una entrada con avisos `info`, `note` y `warning`; la respuesta contiene `data-icon="info"`, `data-icon="bulb"` y `data-icon="warning"` dentro de `.article-notice`. Añadir `data-icon` al SVG del componente si no existe.
  - `test_cards_use_neutral_wording`: la portada con dos entradas contiene «Leer la entrada» (destacada) y «Leer» (tarjeta), no contiene «Consultar entrada» ni «herramienta o comando», y contiene `id="entradas"`.
  - `test_media_video_without_caption_has_no_empty_caption`: un bloque `media_video` sin pie no produce `<figcaption></figcaption>` y no lleva `style=` en el `<video>`.
- [ ] **Step 2: Run** `--filter ThemeDesignTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - Copiar las reglas de `solo-fondo.css` al tema sin el prefijo `.sq-fondo`, sustituyendo las declaraciones originales en lugar de sobrescribirlas cuando sea razonable.
  - Quitar todas las sombras `0 Npx 0` y el comentario «Preserve the approved light layout…».
  - Tipografía: la monoespaciada queda solo para código; los rótulos pasan a `var(--sans)`, como en `solo-fondo.css`.
  - Aviso: icono por tono, `info`→`info`, `note`→`bulb`, `warning`→`warning`.
  - Video: `<figcaption>` solo si hay pie; los estilos en línea pasan a `.article-video video` en `cms.css`.
  - Textos y ancla:
    - «Consultar entrada» pasa a «Leer la entrada» en la destacada y a «Leer» en las tarjetas;
    - el marcador del buscador pasa a «Buscar una entrada…»;
    - el icono `terminal` de `.cover-topic` y de la píldora «Blog» del artículo pasa a `tag`;
    - `id="tutoriales"` pasa a `id="entradas"`, junto con `#tutoriales` en los formularios y la paginación.
- [ ] **Step 4: Run** `--filter ThemeDesignTest` y `--filter SitePagesTest`. Expected: PASS. Actualizar cualquier prueba existente que busque los textos o el ancla antiguos.
- [ ] **Step 5:** Revisar en el navegador `/` y una entrada, en claro y oscuro, comparando con `maquetas/fondo.png` y `fondo-post.png`.

### Task 2: Letras unificadas en el panel y aspecto del editor de documentos

**Files:**
- Create: `site/themes/solqubit/fonts/panel.css`, con `@font-face` de DM Sans y Nunito que apuntan a los `.woff2` vecinos (mismos `unicode-range` que el tema)
- Modify: `core/app/Providers/Filament/AdminPanelProvider.php`: `->font('DM Sans', url: asset('themes/solqubit/fonts/panel.css'), provider: LocalFontProvider::class)`
- Modify: `site/editor/admin.css` (h1 de página `.fi-header-heading` en `'Nunito'`), `site/editor/editor.css`, `site/editor/writing.css`
- Test: `core/tests/Feature/AdminUsabilityTest.php` (añadir)

**Interfaces:**
- Produces: las clases `.sq-block-preview` y `.tiptap` del editor con las letras del blog; los bloques del editor sin `border-inline-start`.

- [ ] **Step 1: Write the failing test** `test_panel_uses_the_blog_fonts`: GET `/admin` como administrador contiene `themes/solqubit/fonts/panel.css` y no contiene `fonts/filament/filament/inter`.
- [ ] **Step 2: Run** it. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - En el documento del editor:
    - título del documento y `h2`/`h3` en `'Nunito'`;
    - texto en `'DM Sans'`;
    - `pre`, `code` y los `textarea` de código y salida en `'IBM Plex Mono'`;
    - `.sq-block-preview` deja `system-ui`.
  - Bloques del editor (`div[data-type=customBlock]`, `.sq-inline-surface`, `blockquote`): sin barra izquierda ni borde, con fondo suave, como en el blog.
  - «Lista destacada» (`.sq-inline-block[data-id=list]`): elementos con `gap` de 6 px y la viñeta alineada con la primera línea.
  - El editor carga `panel.css` porque usa las mismas familias dentro del panel.
- [ ] **Step 4: Run** the test. Expected: PASS. Revisar `/admin/posts/{id}/edit` en el navegador.

### Task 3: Bloques definidos en un solo sitio

**Files:**
- Modify: `core/app/Forms/Editor/ContentBlock.php` y las 11 clases de bloque
- Modify: `core/app/Services/EditorBlocks.php`, `core/app/Forms/Components/ContentEditor.php`, `core/app/Services/ContentSchema.php`, `core/app/Filament/Resources/Posts/PostResource.php` (quitar `blocks()`), `site/editor/solqubit-editor.js` (lista `commands`)
- Test: `core/tests/Feature/EditorBlocksTest.php` (nuevo) y `core/tests/JavaScript/editor.test.mjs`

**Interfaces:**
- Produces, en `ContentBlock`, métodos estáticos abstractos o con valor por defecto:
  - `label(): string`;
  - `icon(): string`;
  - `description(): string`, el texto del menú «/»;
  - `schema(): array<Component>`, los campos del modal;
  - `defaults(): ?array`, la configuración al insertar, o `null` si se inserta abriendo el modal;
  - `rules(): array<string, mixed>`, las reglas de validación de `data`.
- Produces: `EditorBlocks::all(): array<string, class-string<ContentBlock>>`, indexado por `TYPE`, en el orden actual de `DEFINITIONS`.
- Produces: `EditorBlocks::commands(): array<int, array{label: string, detail: string, command: string}>`, con las órdenes nativas (Párrafo, H2, H3, Lista, Lista numerada, Cita y Separador) más una por bloque (`sq_<type>`).
- Produces: el atributo `data-commands` (JSON) en el envoltorio `.solqubit-editor`, que lee `solqubit-editor.js`.
- `ContentSchema::validate()` toma las reglas de `EditorBlocks::all()` para los tipos de bloque. `heading`, `paragraph` y `divider` siguen en `ContentSchema`; `table` declara sus reglas en `TableBlock`.

- [ ] **Step 1: Write the failing tests:**
  - `test_every_block_declares_its_definition`: por cada `EditorBlocks::all()`, `label()` e `icon()` no vacíos, `rules()` no vacío y la vista `themes.solqubit.block` pinta un bloque válido de ejemplo sin el aviso «Bloque no compatible».
  - `test_code_block_has_no_language_field`: los nombres de `CodeBlock::schema()` no incluyen `language` y `CodeBlock::defaults()` no tiene `language`. Un bloque guardado con `language` sigue validando.
  - `test_editor_exposes_block_commands`: el HTML de `CreatePost` contiene `data-commands` con «Lista destacada» y `sq_list`.
  - En `editor.test.mjs`, `matchingCommands` filtra una lista recibida por parámetro: `matchingCommands('aviso', commands)` devuelve la orden `sq_notice`.
- [ ] **Step 2: Run** both. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - Mover el formulario de cada bloque desde `PostResource::blocks()` y los valores iniciales desde `ContentEditor::setUp()` a su clase.
  - Pasar las reglas del `match` de `ContentSchema::validate()` a cada clase, manteniendo las comprobaciones extra (imagen, video y YouTube).
  - `EditorBlocks::schema()` desaparece; `ContentBlock::configureEditorAction()` usa `static::schema()`.
  - En JS, `commands` se lee de `wrapper.dataset.commands` y `matchingCommands(query, commands)` recibe la lista.
- [ ] **Step 4: Run** `--filter "EditorBlocksTest|CmsTest|EditorialImprovementsTest"` y las pruebas JS. Expected: PASS.

### Task 4: Motor de vista previa real

**Files:**
- Create: `core/app/Services/SiteRenderer.php`
- Modify: `core/app/Http/Controllers/BlogController.php` (delega en `SiteRenderer`), `core/app/Http/Controllers/EditorPreviewController.php`, `core/resources/views/themes/solqubit/layout.blade.php`
- Test: `core/tests/Feature/LivePreviewTest.php` (nuevo); ajustar `MonetizationTest` y `SecurityHardeningTest` si cambian

**Interfaces:**
- Produces, en `SiteRenderer` (todas devuelven `Illuminate\Contracts\View\View`):
  - `article(Post $post, bool $preview = false, bool $live = false)`;
  - `home(Request $request, Page $page, bool $preview = false, bool $live = false)`;
  - `page(Page $page, bool $preview = false, bool $live = false)`;
  - `series(Request $request, Page $page, bool $preview = false, bool $live = false)`.

  `$live` implica `$preview`: sin anuncios ni contador, y además sin el aviso «Vista previa de la versión guardada». La plantilla recibe `$live` y añade `<meta name="robots" content="noindex">` y `<base target="_blank">`.
- Produces: `POST /editor-preview` (`editor.preview`) con `{context: post|page|home, record_id?: int, state: array, document?: array, mode: light|dark}`. Responde `{html: string}`.
  - `post`: `Post` en memoria con `state` y `content` = `ContentDocument::toBlocks(document)`.
  - `page`: `Page` en memoria a partir del registro con `state`.
  - `home`: la portada con `settings.sections` = `state.sections` (validado por `HomeSections::validate` de la tarea 6; hasta entonces, los ajustes actuales).
- Consumes: `MediaAccess::visibleTo()`. La portada, las categorías y los archivos de los bloques que el usuario no puede ver se descartan antes de pintar.

- [ ] **Step 1: Write the failing tests** en `LivePreviewTest`:
  - `test_post_preview_renders_the_real_article_without_saving`: un editor envía título «Título sin guardar», entradilla y un párrafo; la respuesta 200 contiene `article-intro`, «Título sin guardar» y el párrafo; `Post::count()` no cambia; la cabecera es `private, no-store`; no contiene `data-ad-placement` ni `visitas.js`.
  - `test_preview_hides_media_the_user_cannot_see`: un editor envía `cover_id` de una imagen privada de otro editor; el HTML no contiene la URL de esa imagen.
  - `test_page_and_home_preview_are_admin_only`: un editor recibe 403 con `context=page` y con `context=home`; un administrador recibe 200.
  - `test_public_pages_render_as_before`: `/`, una entrada y `/series` responden 200 con el mismo contenido clave que antes (cubierto también por `SitePagesTest`).
- [ ] **Step 2: Run** `--filter LivePreviewTest`. Expected: FAIL.
- [ ] **Step 3: Implement** `SiteRenderer`, moviendo `page()`, `home()`, `article()` y `seriesPage()` de `BlogController` sin cambiar su comportamiento. `BlogController` conserva las rutas y la validación de filtros. Después, el endpoint nuevo.
- [ ] **Step 4: Run** `--filter "LivePreviewTest|SitePagesTest|MonetizationTest|SecurityHardeningTest|SeoTest|VisitStatisticsTest"`. Expected: PASS.

### Task 5: Vista previa dividida en el editor de entradas y páginas

**Files:**
- Create: `site/editor/live-preview.js` (módulo ES)
- Modify: `site/editor/solqubit-editor.js` (quitar `sq_preview`, `togglePreview` y la sección `.sq-live-preview`), `core/app/Forms/Components/ContentEditor.php` (quitar la herramienta `sq_preview`), `core/resources/views/filament/writing-header.blade.php`, `core/app/Filament/Resources/Posts/Pages/EditPost.php` y `CreatePost.php`, `Pages/EditPage.php` y `CreatePage.php`, `site/editor/writing.css`
- Test: `core/tests/JavaScript/live-preview.test.mjs` (nuevo) y `core/tests/Feature/LivePreviewTest.php`

**Interfaces:**
- Produces, en `live-preview.js`:
  - `export function previewScale(containerWidth: number, deviceWidth: number): number`, que devuelve `min(1, containerWidth / deviceWidth)`;
  - `export const DEVICE_WIDTHS = { desktop: 1280, mobile: 390 }`;
  - `export function createLivePreview({ container, endpoint, context, recordId, readState, readDocument, csrf }): { open(), close(), refresh(), destroy() }`.
- Produces, en la cabecera de escritura: un botón «Vista previa» (`x-on:click` añade la clase `sq-preview-open` a `body`) y el contenedor `#sq-live-preview`.
  - Con `sq-preview-open`: el documento y la vista previa al 50 % cada uno, sin la columna de ajustes.
  - Por debajo de 1100 px: la vista previa ocupa la pantalla y tiene un botón «Volver al documento».
- Barra de la vista previa: «Escritorio | Móvil», «Claro | Oscuro» y un estado. El `iframe` usa `srcdoc` con `sandbox="allow-same-origin"`.
- Se actualiza 400 ms después de un `input` o `change` del formulario o de una actualización del editor.

- [ ] **Step 1: Write the failing tests:**
  - JS: `previewScale(640, 1280) === 0.5`, `previewScale(500, 390) === 1`.
  - PHP `test_editor_header_has_a_single_preview_button`: el HTML de `EditPost` contiene «Vista previa» y `sq-live-preview`, y no contiene «Ver versión guardada» ni `data-rich-editor-tool="sq_preview"`.
- [ ] **Step 2: Run** both. Expected: FAIL.
- [ ] **Step 3: Implement.** `readState` lee `$wire.$get('data')` del componente Livewire y `readDocument` lee el JSON del editor.
- [ ] **Step 4: Run** both. Expected: PASS. Comprobar en el navegador que al abrir la vista previa se ve el artículo real junto al documento y que se actualiza al escribir.

### Task 6: Portada por secciones (datos y plantilla)

**Files:**
- Create: `core/app/Services/HomeSections.php`
- Create: `core/resources/views/themes/solqubit/home/welcome.blade.php`, `featured.blade.php`, `latest.blade.php`, `topic.blade.php`, `series.blade.php`, `text.blade.php` y `ad.blade.php`
- Modify: `core/resources/views/themes/solqubit/home.blade.php`, `core/app/Services/SiteRenderer.php` (`home()`), `core/app/Models/Page.php` (validación de `settings.sections` y `media` de las secciones), `core/app/Services/Monetization.php` (etiqueta de `home_bottom`), `core/app/Models/Post.php` (exclusividad de `featured`, si queda sin uso), `docs/THEMES.md`
- Test: `core/tests/Feature/HomeSectionsTest.php` (nuevo); adaptar `SitePagesTest`, `CmsTest`, `SeoTest` y `EditorialImprovementsTest` donde usen `featured`, `show_featured` o `catalog_title`

**Interfaces:**
- Produces: `HomeSections::TYPES = ['welcome', 'featured', 'latest', 'topic', 'series', 'text', 'ad']`.
- Produces: `HomeSections::fromPage(Page $home): array<int, array{type: string, visible: bool, ...}>`. Devuelve `settings['sections']` si existe y, si no, la conversión del spec. El `post_id` de la destacada es el id de la entrada publicada con `featured = true` más reciente, o `null`.
- Opciones por tipo (claves exactas):
  - `welcome`: `heading`, `accent_heading`, `intro`, `icon`, `show_heading`, `show_intro`;
  - `featured`: `post_id` (?int), `fallback_latest` (bool, por defecto `true`);
  - `latest`: `title` (por defecto «Últimas entradas.»), `per_page` (6, 9, 12, 18 o 24), `show_search`, `show_filter`;
  - `topic`: `category_id`, `title` (?string), `count` (3, 6 o 9);
  - `series`: `title` (?string), `count` (3 o 6);
  - `text`: `content` (documento o bloques);
  - `ad`: sin opciones.
- Produces: `HomeSections::validate(array $sections): array`, que normaliza y lanza `ValidationException` con la clave `sections`. Reglas: tipos conocidos, como máximo un `latest`, rangos, `category_id`/`post_id` existentes y `text.content` validado con `ContentDocument::toBlocks`.
- Produces: `HomeSections::mediaIds(array $sections): array<int>`.
- Produces: `SiteRenderer::home()` pasa a la vista `sections` (las visibles, ya filtradas según búsqueda o página) y, por sección, sus datos: `featured` (?Post), `posts` (paginador de `latest`), `topicPosts[index]` y `series[index]`.
- Reglas de filtrado: con `q`, `category` o `page > 1` se omiten `featured`, `topic` y `series`. La destacada se excluye del listado `latest` cuando se muestra. Un `topic` con la categoría borrada se omite.

- [ ] **Step 1: Write the failing tests** en `HomeSectionsTest`:
  - `test_old_home_settings_convert_to_equivalent_sections`: una portada con `heading`, `intro`, `content` (un párrafo), `show_featured = false`, `catalog_title = 'Mis notas'` y `per_page = 6` devuelve `[welcome, text, featured(visible: false), latest(title: 'Mis notas', per_page: 6), …]`, y `/` contiene el párrafo antes de «Mis notas».
  - `test_home_renders_sections_in_their_order`: `[latest, welcome]` pinta el listado antes del `h1`.
  - `test_hidden_sections_are_not_rendered`.
  - `test_featured_section_uses_chosen_post_or_latest`: con `post_id` muestra esa entrada; con `null` y `fallback_latest` muestra la más reciente; esa entrada no se repite en `latest`.
  - `test_search_and_later_pages_skip_selected_sections`: con `?q=` y con `?page=2` no aparecen la destacada ni el tema; sí la bienvenida y el listado.
  - `test_topic_section_lists_category_posts_and_skips_deleted_category`.
  - `test_saving_two_latest_sections_is_rejected`.
  - `test_text_section_media_is_attached_to_home`: una imagen en un Texto libre aparece en `$home->media` y la Biblioteca no permite borrarla.
  - `test_ad_section_renders_home_bottom_slot`, con la monetización activada.
- [ ] **Step 2: Run** `--filter HomeSectionsTest`. Expected: FAIL.
- [ ] **Step 3: Implement.** `home.blade.php` recorre `$sections` e incluye `themes.solqubit.home.{type}`. Si no hay `latest` visible y hay búsqueda o filtro, añade `latest.blade.php` al final con sus valores por defecto. `WelcomePages` sigue funcionando: escribe en `settings` y la conversión lo recoge.
- [ ] **Step 4: Run** `--filter "HomeSectionsTest|SitePagesTest|CmsTest|SeoTest|EditorialImprovementsTest|MonetizationTest"`. Expected: PASS.

### Task 7: Página Ajustes → Portada con secciones y vista previa

**Files:**
- Modify: `core/app/Filament/Pages/HomeSettings.php`, `core/resources/views/filament/pages/home-settings.blade.php`, `site/editor/admin.css`
- Test: `core/tests/Feature/HomeSettingsTest.php` (nuevo, o añadir a la prueba existente de la portada si la hay)

**Interfaces:**
- Consumes: `HomeSections::fromPage`, `HomeSections::validate` y `createLivePreview` (con `context: 'home'`, `readState` = `{sections, seo_title, seo_description}`).
- Produces: el formulario con `Builder::make('sections')`, con un `Block` por tipo:
  - etiquetas: «Bienvenida», «Entrada destacada», «Últimas entradas», «Entradas de un tema», «Series», «Texto libre» y «Anuncio»;
  - el bloque de «Últimas entradas» lleva `maxItems(1)`; «Anuncio» solo si `Monetization` está activada;
  - cada bloque lleva `Toggle::make('visible')->label('Mostrar en la portada')->default(true)`;
  - las cabeceras plegadas llevan un resumen con `->label(fn (?array $state) => …)`.
- Disposición: las secciones a la izquierda (unos 400 px) y la vista previa a la derecha, siempre visible; debajo, «Cómo aparece en buscadores» y la barra de guardar actual.

- [ ] **Step 1: Write the failing tests:**
  - `test_home_settings_loads_converted_sections`;
  - `test_home_settings_saves_reordered_sections`;
  - `test_home_settings_rejects_invalid_sections` (dos `latest`);
  - `test_ad_section_is_offered_only_with_monetization`.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement.** `save()` guarda `settings['sections'] = HomeSections::validate($data['sections'])` y conserva las claves antiguas de `settings`.
- [ ] **Step 4: Run** them plus `--filter "AdminNavigationTest|SettingsCleanupTest"`. Expected: PASS. Revisar en el navegador frente a `maquetas/portada2.png`.

### Task 8: Editor de entradas reorganizado y lista de Entradas

**Files:**
- Modify: `core/app/Filament/Resources/Posts/PostResource.php`, `Pages/EditPost.php`, `Pages/CreatePost.php`, `core/app/Forms/Components/ContentEditor.php` (barra), `site/editor/solqubit-editor.js` (menú «Insertar»), `site/editor/writing.css`, `core/resources/views/filament/writing-header.blade.php` (etiqueta del botón principal)
- Test: `core/tests/Feature/PostEditorLayoutTest.php` (nuevo); adaptar `EditorialImprovementsTest` (`test_featured_is_exclusive_and_admin_only` desaparece o pasa a la portada)

**Interfaces:**
- Produces: `PostResource::primaryActionLabel(?string $status, mixed $publishedAt, ?Post $record): string`. Devuelve:
  - «Guardar borrador» si el estado es `draft` o `archived`;
  - «Programar» si es `published` con fecha futura;
  - «Actualizar» si es `published` y el registro ya estaba publicado;
  - «Publicar» en el resto de los casos.
- Estado: opciones `['draft' => 'Borrador', 'published' => 'Publicada', 'archived' => 'Archivada']`.
- Fecha: etiqueta «Publicar el», visible solo con `published`, con la ayuda «En blanco, se publica al guardar. Una fecha futura la programa.»
- Documento: la imagen de cabecera (`cover_id`, `mediaSelect`) va encima del título; la «Entradilla» (`excerpt`) va bajo el título, con la ayuda «Aparece bajo el título y en las tarjetas del blog.»
- Ajustes, en este orden:
  1. «Publicación»: `user_id`/autor, `status` y `published_at`;
  2. «Clasificación»: `category_ids`, `tags`, `series_id` y `series_order`;
  3. «Lectura»: `reading_minutes`, `difficulty` y `show_updated_at`;
  4. «Dirección»;
  5. «Buscadores».

  Las secciones 2 a 5 empiezan plegadas. Se quitan `featured` y la sección «Tarjeta de la entrada».
- Barra del editor: los botones de escritura, `sq_image` y `sq_insert` («Insertar», que abre el menú «/» en la posición del cursor) y `sq_focus`. Las demás herramientas `sq_*` siguen registradas para el menú, pero no salen en la barra.
- Lista: `ImageColumn` (o equivalente) con la miniatura `card` de la portada antes del título; sin la columna `featured`; `recordUrl` a editar, como en Páginas.

- [ ] **Step 1: Write the failing tests:**
  - `test_primary_action_label_matches_status` (los cuatro casos);
  - `test_post_form_has_cover_and_excerpt_in_document_and_no_featured`;
  - `test_settings_sections_are_in_order` (comprueba el orden de los títulos de sección en el HTML);
  - `test_toolbar_shows_insert_instead_of_every_block`: contiene `data-rich-editor-tool="sq_insert"` y no `data-rich-editor-tool="sq_notice"`;
  - `test_post_list_has_thumbnail_and_no_featured_column`.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - El botón principal sigue siendo el `submit` de la cabecera; su etiqueta se calcula con `primaryActionLabel` a partir del estado del formulario (Alpine con `$wire.data.status` y `$wire.data.published_at`, con el mismo cálculo en JS).
  - La descripción de «Publicación» deja de hablar del autoguardado; la barra inferior del documento (`shortcut` en `solqubit-editor.js`) dice «/ para insertar · El autoguardado guarda una copia privada; publica o actualiza para aplicar los cambios».
- [ ] **Step 4: Run** `--filter "PostEditorLayoutTest|EditorialImprovementsTest|PostAddressesTest|SecurityHardeningTest|CmsTest"` y las pruebas JS. Expected: PASS.

### Task 9: Editor de páginas con un solo título visible

**Files:**
- Modify: `core/app/Filament/Resources/Pages/PageResource.php`, `Pages/EditPage.php`, `Pages/CreatePage.php`
- Test: `core/tests/Feature/PageEditorLayoutTest.php` (nuevo); adaptar `SitePagesTest` (líneas 71 y 231 usan `settings.heading`)

**Interfaces:**
- Produces: `PageResource::normalizeTitles(array $data): array`.
  - Si `title` está vacío, toma `settings.heading`.
  - Si `settings.heading === title`, deja `settings.heading` en `''`.
  - `settings.show_heading` es lo contrario de `hide_heading`.
- Documento: imagen principal (`cover_id`) arriba en «Sobre mí» y en las adicionales; el campo grande «Título» edita `settings.heading` y usa `title` como marcador.
- Ajustes, en este orden:
  1. «Visibilidad y menú»;
  2. «Encabezado»: «Nombre de la página», con la ayuda «Se usa en los menús, en el panel y en buscadores. En blanco, se usa el título»; «Ocultar el título en la página»; la descripción; el canal de YouTube;
  3. «Dirección»;
  4. «Listado» (solo Series);
  5. «Buscadores».

- [ ] **Step 1: Write the failing tests:**
  - `test_saving_with_empty_name_uses_visible_title`;
  - `test_visible_title_equal_to_name_is_stored_empty`;
  - `test_existing_page_keeps_its_name_and_heading` (Contacto/«Hablemos.» se ve igual en `/contacto` y en el panel);
  - `test_new_page_slug_is_suggested_from_visible_title`.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement** con `mutateFormDataBeforeSave` y `mutateFormDataBeforeCreate` llamando a `normalizeTitles`, y `mutateFormDataBeforeFill` para `hide_heading`.
- [ ] **Step 4: Run** `--filter "PageEditorLayoutTest|SitePagesTest|SiteIdentityAndPermalinksTest"`. Expected: PASS.

### Task 10: Documentación, CHANGELOG y verificación completa

**Files:**
- Modify: `CHANGELOG.md` (líneas en `## [2.0.0]`), `docs/USO.md`, `docs/THEMES.md`

- [ ] **Step 1:** Añadir a `## [2.0.0]` una línea `- ` por cada cambio visible: diseño «solo fondo», letras unificadas, vista previa real, portada por secciones, editor reorganizado y avisos con su icono.
- [ ] **Step 2:** Pint en todos los PHP tocados. Expected: sin errores.
- [ ] **Step 3:** `node --test core/tests/JavaScript/*.mjs`. Expected: todo PASS.
- [ ] **Step 4:** Suite completa (unos 4 minutos, en segundo plano). Expected: 0 fallos.
- [ ] **Step 5:** Revisar en el navegador la portada, una entrada, una página, el editor con la vista previa y Ajustes → Portada, en claro y oscuro y en escritorio y móvil.
