# Seguridad

## Versiones con soporte

Solo la última versión publicada recibe correcciones de seguridad. Actualiza desde **Administración → Estado del sistema** antes de informar de un fallo.

## Cómo avisar de una vulnerabilidad

**No abras un issue público.** Usa el aviso privado de GitHub: pestaña **Security → Report a vulnerability** de este repositorio.

Incluye, si puedes:

- La versión de Solqubit, PHP, base de datos y servidor web (o panel).
- Los pasos para reproducirlo y qué puede conseguir un atacante.
- Si hace falta una cuenta (administrador, editor) o no.

Es un proyecto personal: intentaré responder en unos días y publicar la corrección en una versión nueva antes de divulgar los detalles. Si el fallo procede de una dependencia (Laravel, Filament, Livewire…), avísalo también a su proyecto.

## Fuera de alcance

- Configuraciones del servidor ajenas al CMS (por ejemplo, permisos 777 o una versión de PHP sin soporte).
- Ataques que requieren que un administrador instale código o una plantilla de origen desconocido.
- Falta de cabeceras en páginas que no genera Solqubit.
