# Panel ordenado y paquetes separados: plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorganizar el panel de Solqubit CMS en lugares lógicos, corregir sus 17 fallos, añadir las mejoras acordadas y separar cada versión en un ZIP de instalación y otro de actualización con un actualizador robusto.

**Architecture:** Dos Clusters de Filament (`Settings` y `Classification`) reúnen las páginas y recursos existentes sin cambiar su aspecto. La portada pasa a una página de ajustes que edita el registro `pages.kind = home`. El generador produce dos ZIP. El actualizador acepta solo el formato 3 (archivos con su ruta final) y decide las rutas permitidas por exclusión.

**Tech Stack:** Laravel 13, Filament 5.10, Livewire, PHPUnit, MariaDB, Docker local.

**Spec:** `docs/superpowers/specs/2026-10-20-panel-y-paquetes-design.md`. El inventario con las líneas exactas de cada fallo está en `C:\Users\damia\AppData\Local\Claude-Data\claude_pzs8sxrjxfjjc\side-session-notes\local_93fc3575-e380-4f89-b7d7-396eff82fb29\inventario-panel.md`.

## Global Constraints

- Los textos visibles van en español, con saltos de línea LF.
- No se modifican migraciones existentes. Si hace falta un cambio de esquema, va en una migración nueva.
- No se añaden dependencias de Composer ni de npm.
- Se conserva el aspecto visual: las clases de `site/editor/admin.css`, las tarjetas, las pestañas `sq-admin-tabs` y la barra `sq-save-bar`.
- Nada se sube a GitHub: ni push, ni tag, ni release. Commits locales solo si el propietario lo pide. Los pasos «Commit» de este plan son **puntos de control**: deja el árbol en verde y sigue sin hacer commit, salvo que el propietario lo autorice.
- Pruebas PHP dentro del contenedor, desde Git Bash:
  - una prueba: `MSYS_NO_PATHCONV=1 docker exec solqubit-cms-web-1 sh -c 'cd /var/www/core && php -d memory_limit=1G vendor/bin/phpunit --colors=never --filter <Nombre>'`;
  - suite completa: la misma orden sin `--filter` (unas 370 pruebas, unos 4 minutos).
- Estilo: `MSYS_NO_PATHCONV=1 docker exec solqubit-cms-web-1 sh -c 'cd /var/www/core && vendor/bin/pint <archivos> --format agent'`. No uses `--dirty`.
- JS: `node --test core/tests/JavaScript/*.mjs`.
- Toda prueba nueva marca el CMS como instalado, como en el `setUp` de `core/tests/Feature/CmsTest.php`: un archivo temporal en `solqubit.installed_file`, `Setting::write('site', …)`, `Setting::write('theme', 'solqubit')`, un administrador y `Filament::setCurrentPanel(Filament::getPanel('admin'))`.
- Versión **2.0.0**. Sin compatibilidad con paquetes ni instalaciones 1.x.
- El actualizador nunca puede escribir en archivos con punto (`.env*`, `.htaccess`), `storage/`, `index.php` ni en el motor desde la carpeta pública.

## Review Focus

1. **Cambiar la ruta del panel en Ajustes → Seguridad.** Debe redirigir a la página de Seguridad con la ruta nueva, que ahora vive dentro del Cluster. Lo cubre la prueba de la tarea 1.
2. **Un ZIP de actualización con un archivo en una carpeta pública nueva o en una carpeta nueva del motor.** El sitio instalado debe aceptarlo; es el fallo que ya ocurrió en la 1.3.0. Lo cubren las tareas 12 y 13.
3. **Un ZIP con rutas que apuntan a la carpeta del motor cuando esta vive dentro de la carpeta pública** (por ejemplo `site/solqubit-core/.env` o `site/private/x`). Debe rechazarse. Lo cubre la tarea 12.
4. **Un editor que entra en Clasificación o en el Escritorio.** No debe ver Ajustes, Portada ni cifras de otros autores. Lo cubren las tareas 1 y 10.
5. **Recuperar una revisión cuya imagen ya se borró de la Biblioteca.** El formulario no debe fallar: omite el bloque o la portada y avisa. Lo cubre la tarea 6.

---

### Task 1: Clusters y barra lateral nueva

**Files:**
- Create: `core/app/Filament/Clusters/Settings.php`, `core/app/Filament/Clusters/Classification.php`
- Modify: `core/app/Providers/Filament/AdminPanelProvider.php` (`navigationGroups(['Contenido', 'Administración'])` y `discoverClusters`), todas las clases de `core/app/Filament/Pages/*Settings.php`, `SystemStatus.php` y los recursos `Categories`, `Tags` y `Series`
- Modify: `core/app/Services/AdminSearch.php`, `core/app/Filament/Pages/AccessSettings.php:96`
- Modify tests: `CmsTest.php:108,118,130`, `AdminAccessTest.php:80`, `AdminUsabilityTest.php:75-80`, `EditorialSettingsTest.php:254-255` y todos los que usan `/admin/*-settings` o `/admin/system-status` (búscalos con `grep -rn "admin/[a-z-]*settings\|system-status" core/tests`)
- Test: `core/tests/Feature/AdminNavigationTest.php` (nuevo)

**Interfaces:**
- Produces:
  - `App\Filament\Clusters\Settings`: slug `ajustes`, etiqueta «Ajustes», icono `heroicon-o-cog-6-tooth`, `canAccess()` solo para administradores, `$subNavigationPosition = SubNavigationPosition::Start`.
  - `App\Filament\Clusters\Classification`: slug `clasificacion`, etiqueta «Clasificación», icono `heroicon-o-tag`, grupo «Contenido», subnavegación arriba (`SubNavigationPosition::Top`).
  - Slugs de las páginas de ajustes: `identidad`, `colores`, `portada` (tarea 3), `menus`, `direcciones` (tarea 2), `seo`, `monetizacion`, `general`, `correo`, `seguridad`.
  - Grupos del submenú: «Sitio», «Publicación» y «Sistema».
  - `SystemStatus`: slug `actualizaciones`, etiqueta «Actualizaciones», grupo «Administración».
  - Recursos de Clasificación con slugs `categorias`, `etiquetas` y `series`.

- [ ] **Step 1: Escribe la prueba que falla `AdminNavigationTest`**
  - `test_admin_sidebar_has_nine_entries_in_order`: recoge `Filament::getNavigation()` como administrador y comprueba exactamente `['Escritorio', 'Estadísticas', 'Entradas', 'Páginas', 'Biblioteca', 'Clasificación', 'Ajustes', 'Usuarios', 'Actualizaciones']`.
  - `test_editor_sidebar`: comprueba `['Escritorio', 'Entradas', 'Biblioteca', 'Clasificación']`.
  - `test_settings_subnavigation_groups`: `GET /admin/ajustes/identidad` ve los textos «Sitio», «Publicación», «Sistema», «Colores», «Menús», «Correo» y «Seguridad».
  - `test_settings_entry_redirects_to_identity`: `GET /admin/ajustes` redirige a `/admin/ajustes/identidad`.
  - `test_old_settings_urls_are_gone`: `/admin/site-settings` y `/admin/system-status` dan 404.
  - `test_editor_cannot_open_settings`: `/admin/ajustes/identidad` da 403 para un editor.
  - `test_admin_path_change_redirects_into_cluster`: cambiar `path` a `panel-nuevo` con Livewire en `AccessSettings` redirige a `url('/panel-nuevo/ajustes/seguridad')`.
- [ ] **Step 2:** Ejecuta `--filter AdminNavigationTest`. Debe fallar.
- [ ] **Step 3:** Crea los dos Clusters y asigna `protected static ?string $cluster` a cada página y recurso. Cambia slug, etiqueta y grupo según «Interfaces». La redirección de `AccessSettings` construye la URL con la ruta nueva sin escribir el slug a mano. AdminSearch añade las palabras clave de Estadísticas («visitas estadisticas visitantes paises») y las de Portada («portada inicio bienvenida listado destacada»).
- [ ] **Step 4:** Actualiza las URL de las pruebas existentes y ejecuta la suite completa. Debe estar en verde.
- [ ] **Step 5:** Pint en los archivos tocados. Punto de control.

### Task 2: Direcciones y General, y la zona horaria en todo el panel

**Files:**
- Create: `core/app/Filament/Pages/AddressSettings.php` (prefijos de URL, sale de `GeneralSettings`) y su vista `core/resources/views/filament/pages/address-settings.blade.php`
- Modify: `core/app/Filament/Pages/GeneralSettings.php` (solo zona horaria; etiqueta «General», grupo «Sistema»), `core/resources/views/filament/pages/general-settings.blade.php`, `core/app/Providers/Filament/AdminPanelProvider.php`
- Modify: `core/app/Services/PostPermalinks.php:75`, `core/app/Filament/Resources/Posts/PostResource.php:109` (rutas citadas → enlace a `AddressSettings::getUrl()`)
- Test: `core/tests/Feature/EditorialSettingsTest.php` (amplía)

**Interfaces:**
- Produces: `AddressSettings`, slug `direcciones`, grupo «Publicación», botón «Guardar direcciones»; `GeneralSettings`, slug `general`.

- [ ] **Step 1: Escribe las pruebas que fallan**
  - `test_address_settings_save_prefixes`: guarda `[['label' => 'Tutoriales', 'slug' => 'tutoriales']]` con `default_post_prefix = 'tutoriales'` y comprueba `PostPermalinks::defaultPrefix() === 'tutoriales'`.
  - `test_general_settings_save_timezone`: guarda `America/Mexico_City` y comprueba `Setting::read('site')['timezone']`.
  - `test_panel_dates_use_site_timezone`: con la zona `America/Guayaquil` y una entrada actualizada a las `2026-10-20 15:00 UTC`, la lista de Entradas muestra `20/10/2026 10:00`.
- [ ] **Step 2:** Ejecuta las pruebas. Deben fallar.
- [ ] **Step 3:** Separa los formularios. En `AdminPanelProvider`, `->bootUsing(fn () => FilamentTimezone::set(Setting::read('site', [])['timezone'] ?? 'UTC'))`.
- [ ] **Step 4:** Ejecuta las pruebas. Deben pasar.
- [ ] **Step 5:** Pint. Punto de control.

### Task 3: Portada en Ajustes y Páginas solo con páginas de verdad

**Files:**
- Create: `core/app/Filament/Pages/HomeSettings.php` y `core/resources/views/filament/pages/home-settings.blade.php`
- Modify: `core/app/Filament/Resources/Pages/PageResource.php` (quitar las secciones `intro` y el título de listado propios de home; excluir `kind = home` en `getEloquentQuery` y en la búsqueda; texto del interruptor `published`; validación del slug), `core/app/Filament/Resources/Pages/Pages/EditPage.php` (si el registro es home, redirige a `HomeSettings::getUrl()`), `core/app/Models/Page.php:93` y `core/app/Services/PagePermalinks.php`, `core/app/Services/SeoMetadata.php:95-98`, `core/resources/views/themes/solqubit/home.blade.php:36`, `page-heading.blade.php:1`, `core/resources/views/filament/editor-preview.blade.php:9`, `core/app/Filament/Widgets/SiteGuide.php`
- Create: migración nueva `create_page_paths_table` (`path` string(255) como clave primaria, y `page_id` foránea con borrado en cascada) para el fallo 11
- Modify: `core/app/Services/PagePermalinks.php` (guarda la dirección anterior al cambiar el slug y la tiene en cuenta en la validación), `core/app/Http/Controllers/BlogController.php` (`custom()`: si no hay página con ese slug y está en `page_paths`, redirige con 301 a `$page->url()`)
- Test: `core/tests/Feature/SitePagesTest.php` (amplía)

**Interfaces:**
- Produces: `HomeSettings extends Filament\Pages\Page`, cluster `Settings`, slug `portada`, grupo «Sitio», usa `HasUnsavedDataChangesAlert`. Su formulario tiene `->model($home)` y `->statePath('data')` con:
  - bienvenida: `settings.show_heading`, `settings.heading`, `settings.accent_heading`, `settings.show_intro`, `settings.intro`, `settings.icon`;
  - «Texto sobre el listado (opcional)»: `ContentEditor::make('content')->editorContext('page')`;
  - listado: `settings.catalog_title`, `settings.show_featured`, `settings.per_page`;
  - buscadores: `seo_title`, `seo_description`.
  
  `save(): void` hace `$home->update($data)` y después `EditorDraft::clearFor('page', $home->id)` y `$this->dispatch('editor-saved', context: 'page', recordId: $home->id)`. Acción de cabecera «Ver la portada» con `route('pages.preview', $home)`.

- [ ] **Step 1: Escribe las pruebas que fallan**
  - `test_home_settings_saves_welcome_and_listing`: guarda `settings.heading = 'Hola'` y `settings.per_page = 9`; `GET /` muestra «Hola».
  - `test_pages_list_hides_home`: la tabla de `ListPages` no contiene el registro home.
  - `test_editing_home_redirects_to_settings`: `/admin/pages/{home}/edit` redirige a `/admin/ajustes/portada`.
  - `test_custom_page_can_use_system_slugs`: crear una página adicional con slug `sobre-mi` funciona, y con slug `inicio` también.
  - `test_trashed_page_still_reserves_slug_with_clear_message`: una página en la papelera con slug `contacto` impide crear otra igual, con un mensaje que contiene «papelera».
  - `test_home_title_is_site_name`: `GET /` tiene `<title>Solqubit</title>`, o el `seo_title` de home si existe.
  - `test_listing_defaults_match`: con `settings.catalog_title` vacío, la web y el formulario usan «Últimas entradas.»; con `settings.show_intro` sin definir, la web y el formulario usan el mismo valor.
  - `test_section_toggle_label`: el editor de la página Series muestra «Mostrar esta sección en el sitio».
  - `test_changed_page_slug_redirects`: renombrar `contacto` a `escribeme` hace que `GET /contacto` devuelva 301 a `/escribeme`, y otra página no puede ocupar `contacto` mientras esa redirección exista.
- [ ] **Step 2:** Ejecuta las pruebas. Deben fallar.
- [ ] **Step 3:** Implementa. La validación del slug consulta solo `kind = 'custom'` (con papelera incluida). Las páginas del sistema dejan de ocupar su slug muerto.
- [ ] **Step 4:** Ejecuta las pruebas y después la suite completa. Deben pasar.
- [ ] **Step 5:** Abre `/admin/ajustes/portada` en el navegador. Comprueba que el editor de contenido carga, guarda y no deja borradores huérfanos. Pint. Punto de control.

### Task 4: Identidad con un solo guardado, avisos de cambios sin guardar y textos

**Files:**
- Modify: `core/app/Filament/Pages/SiteSettings.php` (quitar la acción `saveSocialLinks` y su texto, y decir dónde salen los iconos: encabezado, pie y menú móvil), todas las páginas de ajustes con formulario (`use HasUnsavedDataChangesAlert`), las vistas `core/resources/views/filament/pages/*.blade.php` (quitar los recuadros `sq-settings-guide` salvo Monetización y Actualizaciones), separar `site-settings.blade.php` en dos vistas (Identidad y Menús), `PostResource.php:130,144`, `PageResource.php:116` y `core/resources/views/mail/smtp-test.blade.php:5` (rutas citadas → enlaces)
- Modify: `PageResource` (en Sobre mí, si `SocialLinks` no tiene YouTube, la ayuda de `settings.show_channel` lo avisa con un enlace a `SiteSettings::getUrl()`)
- Test: `core/tests/Feature/SiteIdentityAndPermalinksTest.php`, `AdminUsabilityTest.php`

- [ ] **Step 1: Escribe las pruebas que fallan**
  - `test_identity_single_save_persists_social_links_and_name`.
  - `test_identity_has_no_separate_social_save`: no aparece «Guardar redes sociales».
  - `test_settings_pages_warn_about_unsaved_changes`: cada clase del Cluster con formulario usa el trait `HasUnsavedDataChangesAlert` (compruébalo con `class_uses_recursive`).
  - `test_help_texts_do_not_cite_menu_paths`: busca en `app/` y `resources/views/` las cadenas «Administración →», «Sitio web →» y «Contenido →». No debe haber ninguna.
  - `test_about_channel_toggle_warns_without_youtube`.
- [ ] **Step 2:** Ejecuta las pruebas. Deben fallar.
- [ ] **Step 3:** Implementa.
- [ ] **Step 4:** Ejecuta las pruebas. Deben pasar.
- [ ] **Step 5:** Pint. Punto de control.

### Task 5: SEO recortado, imagen por defecto al compartir y ocultar a los buscadores

**Files:**
- Modify: `core/app/Filament/Pages/SeoSettings.php` y su vista (campos, enlaces y un único bloque plegado «Qué hace el CMS por ti»), `core/app/Services/SeoMetadata.php` (`settings()` añade `default_image_id: ?int` y `noindex: bool`), `core/app/Http/Controllers/SeoController.php` (`robots`), el layout del tema (`meta robots`), `core/app/Models/Media.php` (`isReferenced` cuenta `default_image_id`, igual que SiteIdentity)
- Test: `core/tests/Feature/SeoTest.php`

- [ ] **Step 1: Escribe las pruebas que fallan**
  - `test_default_share_image_used_when_page_has_no_cover`: `GET /` contiene `og:image` con la URL de la imagen elegida.
  - `test_noindex_setting`: con `noindex = true`, `GET /` contiene `<meta name="robots" content="noindex` y `/robots.txt` contiene `Disallow: /`; con `noindex = false`, ninguna de las dos cosas.
  - `test_default_share_image_cannot_be_deleted_while_in_use`.
- [ ] **Step 2:** Ejecuta las pruebas. Deben fallar.
- [ ] **Step 3:** Implementa. Etiquetas de los campos: «Imagen al compartir (por defecto)» y «Pedir a los buscadores que no indexen el sitio».
- [ ] **Step 4:** Ejecuta las pruebas. Deben pasar.
- [ ] **Step 5:** Pint. Punto de control.

### Task 6: Entradas, los fallos

**Files:**
- Modify:
  - `core/app/Filament/Resources/Posts/PostResource.php`: `getEloquentQuery` y la búsqueda filtran para editores; acción «Eliminar definitivamente» por registro y masiva; vista previa oculta en la papelera.
  - `core/app/Filament/Resources/Posts/Pages/EditPost.php`: «Recuperar revisión» rellena el formulario sin guardar.
  - `core/app/Models/Post.php`: `restoreRevision` → `revisionFormData(PostRevision $revision): array`, que devuelve los datos del formulario sin guardar e incluye `tags` (ids). También guarda como máximo 20 revisiones por entrada y borra las más antiguas al crear una nueva.
  - `PostRevision` guarda `tag_ids` en el snapshot.
- Test: `core/tests/Feature/EditorialImprovementsTest.php`, `SecurityHardeningTest.php:105` (ajusta la expectativa al nuevo filtro)

**Interfaces:**
- Produces: `Post::revisionFormData(PostRevision $revision): array{data: array<string, mixed>, missing_media: int}`. Quita del contenido y de la portada los ids de archivos que ya no existen y los cuenta en `missing_media`.

- [ ] **Step 1: Escribe las pruebas que fallan**
  - `test_editor_list_shows_own_and_published_only`: el editor no ve el borrador ajeno ni en la tabla ni en la búsqueda global.
  - `test_admin_can_force_delete_trashed_post`: la entrada desaparece de la base de datos. El editor no ve la acción.
  - `test_restore_revision_fills_form_without_saving`: una entrada publicada sigue publicada en la base de datos después de recuperar; el formulario tiene el título antiguo y sus etiquetas.
  - `test_restore_revision_with_deleted_media_warns`: se borra el archivo de la revisión; al recuperar, no hay excepción, `missing_media` vale 1 y aparece una notificación que contiene «ya no existe».
  - `test_revisions_capped_at_twenty`: después de 25 guardados quedan 20 revisiones.
  - `test_preview_hidden_for_trashed_posts`.
- [ ] **Step 2:** Ejecuta las pruebas. Deben fallar.
- [ ] **Step 3:** Implementa.
- [ ] **Step 4:** Ejecuta las pruebas. Deben pasar.
- [ ] **Step 5:** Pint. Punto de control.

### Task 7: Entradas, las mejoras

**Files:**
- Modify: `PostResource.php` (tabla y formulario), las vistas del tema que muestran el nivel (`card.blade.php:16`, `post.blade.php:26`), `core/app/Filament/Resources/Posts/Pages/CreatePost.php` y `EditPost.php` (minutos automáticos y destacada exclusiva)
- Test: `core/tests/Feature/EditorialImprovementsTest.php`

**Interfaces:**
- Produces: `Post::estimatedReadingMinutes(): int`, que devuelve `max(1, (int) ceil(palabras / 200))`. Las palabras salen de `search_text`, o del texto plano del contenido si no hay.

- [ ] **Step 1: Escribe las pruebas que fallan**
  - `test_reading_minutes_auto_when_blank`: con 650 palabras y el campo vacío, se guardan 4.
  - `test_difficulty_optional_hidden_on_site`: con `difficulty = ''`, la tarjeta y la entrada no muestran el nivel.
  - `test_featured_is_exclusive_and_admin_only`: al destacar B, A deja de estar destacada; el editor no ve el interruptor.
  - `test_admin_can_change_author`: el selector existe solo para administradores y guarda `user_id`.
  - `test_tags_can_be_created_inline`.
  - `test_bulk_publish_and_archive`: las dos acciones masivas respetan la policy `update` registro por registro.
  - `test_scheduled_filter_and_new_columns`: el filtro «Programadas» muestra solo las entradas con fecha futura, y la tabla tiene las columnas «Autor» y «Publicada».
- [ ] **Step 2:** Ejecuta las pruebas. Deben fallar.
- [ ] **Step 3:** Implementa:
  - «Minutos de lectura» deja de ser obligatorio y su ayuda dice «En blanco se calcula a partir del texto».
  - «Nivel» tiene la opción `'' => 'Sin nivel'`.
  - Las acciones de cada fila van en un `ActionGroup`.
  - Las columnas son «Autor» (`author.name`) y «Publicada» («Programada para d/m/Y H:i» cuando la fecha es futura).
- [ ] **Step 4:** Ejecuta las pruebas. Deben pasar.
- [ ] **Step 5:** Pint. Punto de control.

### Task 8: Biblioteca

**Files:**
- Modify: `core/app/Models/Media.php` (`isReferenced` deja de recorrer `PostRevision`; añade `usages(): array<int, array{label: string, url: string}>`), `core/app/Filament/Resources/Media/MediaResource.php`, `core/app/Filament/Resources/Media/Pages/CreateMedia.php` (subida múltiple), `EditMedia.php` (vista previa, URL copiable y «Usado en…»)
- Test: `core/tests/Feature/CmsTest.php` o un archivo nuevo, `core/tests/Feature/MediaLibraryTest.php`

- [ ] **Step 1: Escribe las pruebas que fallan**
  - `test_media_used_only_in_old_revision_can_be_deleted`.
  - `test_media_used_by_trashed_post_cannot_be_deleted_and_says_why`: la notificación menciona «papelera». El modal no se queda abierto en silencio, y la acción termina con `halt()` y una notificación de peligro.
  - `test_multiple_upload_creates_each_file`: subir 3 archivos crea 3 registros `Media`.
  - `test_edit_page_lists_usages`: una imagen usada como portada de una entrada muestra el título de esa entrada en la ficha.
- [ ] **Step 2:** Ejecuta las pruebas. Deben fallar.
- [ ] **Step 3:** Implementa. La subida múltiple usa `FileUpload::multiple()->maxFiles(20)` y `MediaLibrary::upload` para cada archivo, con el texto alternativo vacío.
- [ ] **Step 4:** Ejecuta las pruebas. Deben pasar.
- [ ] **Step 5:** Pint. Punto de control.

### Task 9: Clasificación, Usuarios y Menús

**Files:**
- Modify:
  - Clasificación: `CategoryResource.php` (quitar la nota interna y la imagen de referencia del formulario; el modal de borrado dice el número de entradas), `TagResource.php` (sin la nota; el slug se genera solo, queda oculto y es único con sufijo), `SeriesResource.php` (el modal de borrado dice el número de entradas).
  - Usuarios: `UserResource.php` (en el propio registro, `role` e `is_active` quedan desactivados; columnas «Entradas» y «Dos pasos»), `core/app/Filament/Resources/Users/Pages/EditUser.php` (si cambia su propia contraseña, actualiza el hash de la sesión), `core/app/Models/User.php:85` (clave del error `data.role`, o captura en `EditUser` con una notificación).
  - Menús: `core/app/Services/SiteNavigation.php:192` (el pie empieza vacío), `NavigationSettings.php` (al elegir una página, rellena el texto vacío con su título).
- Test: `core/tests/Feature/AdminUsabilityTest.php`, `UsernameLoginTest.php`, `SitePagesTest.php`

- [ ] **Step 1: Escribe las pruebas que fallan**
  - `test_last_admin_demotion_shows_error_on_role_field`: `assertHasErrors(['data.role'])`.
  - `test_admin_cannot_change_own_role_or_active`: los campos aparecen desactivados en su propio registro.
  - `test_admin_changing_own_password_keeps_session`: después de guardar, una petición a `/admin` sigue dando 200.
  - `test_footer_menu_starts_empty`: sin ajuste guardado del pie, el pie de la web no tiene enlaces de menú.
  - `test_menu_label_autofills_from_page`.
  - `test_category_delete_modal_mentions_post_count`.
  - `test_tag_slug_generated_automatically`.
- [ ] **Step 2:** Ejecuta las pruebas. Deben fallar.
- [ ] **Step 3:** Implementa.
- [ ] **Step 4:** Ejecuta las pruebas. Deben pasar.
- [ ] **Step 5:** Pint. Punto de control.

### Task 10: Escritorio y Estadísticas

**Files:**
- Modify: `core/app/Filament/Widgets/SiteGuide.php` y su vista (Escribir, Mis entradas, Portada solo para administradores, Ver mi sitio), `ContentStats.php` (cifras pulsables, por autor para el editor, y «Archivadas»), `core/app/Filament/Widgets/Visits/VisitTotals.php` y `VisitsSummary.php` (etiqueta del fallo 15)
- Test: `core/tests/Feature/AdminUsabilityTest.php`, `VisitStatisticsTest.php`

- [ ] **Step 1: Escribe las pruebas que fallan**
  - `test_dashboard_guide_cards`: el administrador ve exactamente «Escribir una entrada», «Mis entradas» y «Portada del blog»; el editor ve las dos primeras.
  - `test_content_stats_scoped_to_editor`: el borrador ajeno no se cuenta para el editor.
  - `test_content_stats_link_to_filtered_list`: la URL de «Borradores» contiene `tableFilters[status][value]=draft`.
  - `test_unique_visitors_label_not_misleading`: la cifra suma los días y la etiqueta es «Visitantes diarios (suma)». Si implementas un cálculo real de únicos del periodo, la prueba comprueba ese valor en su lugar.
- [ ] **Step 2:** Ejecuta las pruebas. Deben fallar.
- [ ] **Step 3:** Implementa.
- [ ] **Step 4:** Ejecuta las pruebas. Deben pasar.
- [ ] **Step 5:** Pint. Punto de control.

### Task 11: Página Actualizaciones reorganizada

**Files:**
- Modify: `core/resources/views/filament/pages/system-status.blade.php`, `core/app/Filament/Pages/SystemStatus.php`, `core/routes/web.php:30-34` (rutas bajo `/actualizaciones/...`, con los mismos nombres de ruta)
- Test: `core/tests/Feature/AdminUsabilityTest.php`, `UpdateNoticeTest.php`

- [ ] **Step 1: Escribe las pruebas que fallan**
  - `test_updates_page_order`: en el HTML, «Actualizar desde GitHub» aparece antes que «Subir el ZIP de actualización», y este antes que «Requisitos del servidor».
  - `test_updates_page_mentions_update_zip_name`: muestra `solqubit-X.Y.Z-actualizacion.zip` y no menciona el asistente de instalación.
- [ ] **Step 2:** Ejecuta las pruebas. Deben fallar.
- [ ] **Step 3:** Implementa. Las fechas usan la zona horaria del sitio, gracias a la tarea 2.
- [ ] **Step 4:** Ejecuta las pruebas. Deben pasar.
- [ ] **Step 5:** Pint. Punto de control.

### Task 12: Paquetes separados y actualizador por exclusión

**Files:**
- Modify:
  - `scripts/build-release.php`: genera `solqubit-X.Y.Z-instalacion.zip`, `solqubit-X.Y.Z-actualizacion.zip`, un `.sha256` para cada uno y `solqubit-X.Y.Z-manifest.json`. El ZIP de instalación deja de llevar `.solqubit-release.json`.
  - `core/app/Services/UpdatePackage.php`: solo formato 3, `allowedPath` por exclusión y comprobación de tiempo y memoria.
  - `core/bootstrap/update-guard.php` (`solqubit_update_target`).
  - `core/app/Services/GitHubReleases.php:107,126`, `core/app/Services/PackageOrigin.php`.
  - `core/app/Services/Installer.php:67` (sin `.solqubit-release.json`).
  - `.github/workflows/release.yml`: atestación y subida de los dos ZIP y los dos `.sha256`; no se ejecuta.
  - `scripts/test-root-install.mjs:112,118`, `scripts/prepare-release-test.mjs`.
- Delete: `core/app/Services/UnifiedPackage.php`
- Test: `core/tests/Feature/ZipUpdateTest.php`, `UpdateDownloadTest.php`, `PackageOriginTest.php`

**Interfaces:**
- Produces:
  - Formato 3: el ZIP contiene `.solqubit-update.json` más entradas con su ruta final `core/...` y `site/...`, sin directorios.
  - Manifiesto: `{product: 'solqubit-cms', format: 3, version, min_version, php, extensions, repository, changes, files: {ruta: {size, sha256}}, remove: []}`.
  - `UpdatePackage::allowedPath(string $path): bool` decide por exclusión:
    - **Se rechazan** los segmentos que empiezan por punto, `core/storage/`, `core/bootstrap/cache/`, `core/bootstrap/public-path.php`, `core/bootstrap/hosting-protection.json`, `core/bootstrap/update-guard.php`, `core/database/*.sqlite*`, `core/.env*`, `site/index.php`, `site/.htaccess` y `site/install-core.php`.
    - **Se aceptan** las demás rutas bajo `core/` o `site/`.
    - Se mantienen el rechazo de `..`, de caracteres fuera de `[a-zA-Z0-9_./@+-]` y el límite de 240 caracteres.
  - `solqubit_update_target` rechaza:
    - un destino `site/...` cuya ruta real (resolviendo la carpeta padre existente) quede dentro de la ruta real del motor;
    - un destino `core/...` que quede dentro de `core/storage`.
  - Nombres de assets en `GitHubReleases`: `solqubit-{v}-actualizacion.zip` y `solqubit-{v}-actualizacion.zip.sha256`.
  - Mensajes: si llega un ZIP con `install-core.php` o `.solqubit-release.json`, el error es «Este es el ZIP de instalación. Para actualizar, usa solqubit-X.Y.Z-actualizacion.zip.».
  - Comprobación previa: «El servidor permite solo N s por petición y no se puede ampliar. Pide a tu hosting al menos 60 s.» o «PHP tiene N MB de memoria. Se necesitan al menos 128 MB.».

- [ ] **Step 1: Escribe las pruebas que fallan en `ZipUpdateTest`.** Crea un helper que construya un ZIP de formato 3 desde un array de rutas y contenidos.
  - `test_accepts_new_public_folder_and_new_core_folder`: las rutas `site/nueva/prueba.js` y `core/app/Nueva/Clase.php` se aplican.
  - `test_rejects_protected_paths`: un proveedor con `core/.env`, `core/.htaccess`, `core/storage/x`, `site/index.php`, `site/.htaccess`, `site/.solqubit-path.php`, `site/install-core.php` y `core/bootstrap/update-guard.php`; cada una es rechazada.
  - `test_rejects_site_path_inside_engine`: con `solqubit.update_site` apuntando a una carpeta que contiene el motor en `solqubit-core/`, la ruta `site/solqubit-core/app/x.php` es rechazada.
  - `test_install_zip_gets_clear_message`.
  - `test_rejects_format_2_and_1`.
  - `test_low_time_or_memory_blocks_validation`: simula el límite con `config('solqubit.update_limits')` inyectable, por ejemplo `['time' => 30, 'memory' => 64 * 1024 ** 2, 'can_extend_time' => false]`.
  - Adapta las pruebas existentes de `ZipUpdateTest`, `UpdateDownloadTest` y `PackageOriginTest` a los nombres y al formato nuevos.
- [ ] **Step 2:** Ejecuta `--filter 'ZipUpdateTest|UpdateDownloadTest|PackageOriginTest'`. Debe fallar.
- [ ] **Step 3:** Implementa. `ZipUpdater` no cambia su flujo: inspecciona, comprueba permisos, prepara el stage, guarda la copia de recuperación, reemplaza y migra en una petición nueva. El generador sigue fallando si una ruta del paquete no supera `allowedPath`.
- [ ] **Step 4:** Ejecuta las pruebas. Deben pasar. Ejecuta `node scripts/build-release.mjs` y comprueba que en `release/` están los cinco archivos y que el ZIP de actualización no contiene `install-core.php`, `index.php` ni `.htaccess` (`unzip -l`).
- [ ] **Step 5:** Pint. Punto de control.

### Task 13: Prueba de punta a punta del ZIP

**Files:**
- Modify: `scripts/test-zip-update.mjs`
- Test: el propio script

- [ ] **Step 1:** Reescribe la generación del paquete N+1 de `test-zip-update.mjs` a partir de `release/solqubit-{N}-actualizacion.zip`:
  - sube `core/config/solqubit.php` a N+1;
  - añade `site/nueva/prueba.js`, `core/app/Nueva/Prueba.php` y una migración nueva que crea la tabla `qa_update_probe`;
  - cambia una vista;
  - retira un archivo listado en `remove`;
  - recalcula `size` y `sha256` en `.solqubit-update.json`.
  
  Mantén el resto del flujo: login, subir, validar y aplicar desde el panel.
- [ ] **Step 2:** Las comprobaciones finales del script son:
  - `config('solqubit.version') === N+1`;
  - existe la tabla `qa_update_probe`;
  - `GET /nueva/prueba.js` da 200 con el contenido nuevo;
  - el archivo retirado ya no existe;
  - `GET /` y `/admin/login` dan 200;
  - no queda `transaction.json` y la carpeta de paquetes está vacía.
- [ ] **Step 3:** Prepara un entorno limpio y ejecuta la prueba, como dice `CLAUDE.md`:
  - `scripts/prepare-release-test.mjs`, `compose.release-test.yaml` y `scripts/test-root-install.mjs`;
  - variables `RELEASE_QA_*` y una base de datos `solqubit_releaseqaN` nueva.
  
  Instala desde `solqubit-2.0.0-instalacion.zip` y luego `RELEASE_QA_VERSION=2.0.0 node scripts/test-zip-update.mjs`. Debe terminar sin errores. Repítelo con las distribuciones del motor que permita `test-root-install.mjs`. Borra la base de datos y los contenedores de prueba al terminar.

### Task 14: Versión 2.0.0, documentación y verificación final

**Files:**
- Modify: `core/config/solqubit.php` (`'version' => '2.0.0'`), `scripts/build-release.php` (`$minimumVersion = '2.0.0'`), `CHANGELOG.md` (sección `## [2.0.0] - 2026-10-20` con las líneas `- ` de los cambios visibles), `docs/INSTALL.md`, `docs/UPDATE.md`, `docs/USO.md`, `docs/THEMES.md` y `README.md` (secciones y archivos nuevos)

- [ ] **Step 1:** Escribe el CHANGELOG y la documentación. Las líneas `- ` son las notas que verá el panel; como mucho 20.
- [ ] **Step 2:** Ejecuta la suite completa PHP, Pint `--test` sobre los archivos tocados y las pruebas JS. Todo debe estar en verde.
- [ ] **Step 3:** Ejecuta `node scripts/build-release.mjs` para dejar los paquetes 2.0.0 en `release/`.
- [ ] **Step 4:** Repite la tarea 13 con los paquetes finales: instalación limpia y actualización a 2.0.1 simulada.
- [ ] **Step 5:** Recorre el panel en el navegador: barra lateral, cada página de Ajustes, Portada, Clasificación, Escritorio y Actualizaciones, como administrador y como editor. Punto de control final: no hagas commit, push ni tag sin autorización.
