# Plantilla y extensiones

El sitio público usa una única plantilla, **Solqubit ilustrado**: bloques que se distinguen por su color de fondo, sin bordes, con una sombra suave solo en lo que flota (tarjetas, cabecera del artículo, índice y botones), con modo claro y oscuro. Usa Nunito para los títulos, DM Sans para el texto e IBM Plex Mono solo para el código. Su aspecto se personaliza desde **Ajustes → Colores**, que ofrece varias variantes de color. Cambiar de paleta no modifica las entradas, sus bloques ni los archivos.

Solqubit tiene una única plantilla pública: no hay cambio entre temas completos.

## Modificar la plantilla

Las vistas están en `resources/views/themes/solqubit/`, dentro del motor privado, y los estilos en la raíz pública `themes/solqubit/` (`editorial-theme.css` y `cms.css`). Las fuentes se sirven desde `themes/solqubit/fonts/` (licencia SIL OFL), sin depender de Google Fonts.

Las vistas de Inicio, Series y páginas reciben el modelo `page`: los textos y opciones de la portada se administran en **Ajustes → Portada**, y los de Series y las demás páginas, en **Páginas**. La portada recibe además `sections`, la lista de secciones visibles en orden, y pinta cada una con `themes/solqubit/home/{tipo}.blade.php` (`welcome`, `featured`, `latest`, `topic`, `series`, `text` y `ad`). Las secciones de un tema y de series ya traen sus entradas (`posts`) y su categoría o series (`category`, `items`). La vista previa del panel usa estas mismas vistas, con `live` activado. Todas reciben `navigation` y `footerNavigation`, listas de enlaces ya filtradas por publicación y visibilidad, con `label`, `url`, `active` y `new_tab`. Respeta las opciones de cabecera y la paginación configurada: no fijes textos ni menús dentro de la plantilla.

Una actualización sustituye las vistas y los estilos de la plantilla. Si la modificas, guarda tus cambios fuera del sitio para volver a aplicarlos tras actualizar. Las vistas Blade ejecutan PHP: usa solo código de confianza y nunca guardes código dentro del contenido de una entrada.

## Extender contenido

`ContentSchema` valida el formato de bloques y `content_version`. Para añadir un bloque, agrega su esquema, el formulario de administración, su vista pública y pruebas de edición, recuperación y exportación. Los campos que contengan HTML deben pasar por el sanitizador y las URL deben limitarse a protocolos admitidos.

Para relaciones nuevas o funciones adicionales, crea modelos, servicios y migraciones nuevas en el motor. Las tablas del CMS y los formatos de copias deben evolucionar juntos. Mantén pruebas de conservación con archivos y entradas creados por versiones anteriores.

Los recursos y descargas pertenecen a la entrada que los referencia. Las listas son informativas. Las recomendaciones permanecen fuera del artículo y de su índice; conserva estas decisiones al crear otros temas.
