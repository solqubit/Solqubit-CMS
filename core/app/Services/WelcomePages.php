<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class WelcomePages
{
    public function create(): void
    {
        DB::transaction(function (): void {
            $site = Setting::read('site', []);
            if (($site['description'] ?? '') === 'El material de apoyo para tus tutoriales de YouTube.') {
                $site['description'] = 'Ideas, historias y conocimiento para compartir.';
                if (($site['footer_description'] ?? '') === 'El material de apoyo para tus tutoriales de YouTube.') {
                    $site['footer_description'] = $site['description'];
                }
                Setting::write('site', $site);
            }
            $navigation = app(SiteNavigation::class);
            if (Setting::read('navigation_footer') === null) {
                $navigation->save([], 'footer');
            }
            $home = Page::where('kind', 'home')->firstOrFail();
            if ($home->option('heading') === 'Tecnología,' && $home->content === []) {
                $home->update(['settings' => array_replace($home->settings ?? [], [
                    'heading' => 'Ideas que nos llevan', 'accent_heading' => 'más lejos.',
                    'intro' => 'Un espacio para explorar, aprender y compartir lo que nos despierta curiosidad. Cada historia empieza con una buena pregunta.',
                    'catalog_title' => 'Últimas historias.',
                ])]);
            }

            $about = Page::where('kind', 'about')->firstOrFail();
            $aboutText = trim(strip_tags(implode(' ', array_map(fn (array $block): string => $block['data']['html'] ?? '', $about->content ?? []))));
            if ($about->content === [] || (count($about->content ?? []) === 1 && $aboutText === 'Bienvenido a mi blog.')) {
                $about->update([
                    'settings' => array_replace($about->settings ?? [], ['heading' => 'Hola, este es mi rincón.', 'show_heading' => true, 'show_intro' => true, 'intro' => 'Curiosidad, ideas y ganas de compartir.', 'show_channel' => false]),
                    'content' => [
                        $this->notice('Una presentación para hacer tuya', 'Este texto es un ejemplo. Cambia la historia, el nombre y los temas por los tuyos desde Páginas → Sobre mí.'),
                        $this->heading('Una pregunta lleva a otra'),
                        $this->paragraph('Me gusta entender cómo funcionan las cosas y compartir lo que voy descubriendo. Este blog nace de esa curiosidad: un lugar para ordenar ideas, documentar proyectos y conversar sobre ciencia y tecnología.'),
                        $this->heading('Qué encontrarás aquí'),
                        ['type' => 'list', 'data' => ['ordered' => false, 'items' => [['text' => 'Artículos con contexto y fuentes para seguir investigando.'], ['text' => 'Experimentos y proyectos explicados con claridad.'], ['text' => 'Lecturas que conectan la tecnología con nuestra vida cotidiana.']]]],
                        $this->paragraph('No pretendo tener todas las respuestas. Me interesa aprender, corregir lo que haga falta y compartir el proceso. Si algo te despierta una nueva pregunta, este espacio ya habrá cumplido su propósito.'),
                    ],
                ]);
            }

            $definitions = [
                'contacto' => ['Contacto', 'Hablemos.', 'Una idea, una corrección o una propuesta: este es el lugar.', [
                    $this->notice('Datos de contacto de ejemplo', 'Sustituye el correo de muestra por uno que revises. Esta página no envía mensajes ni incluye un formulario.'),
                    $this->heading('Una conversación empieza aquí'),
                    $this->paragraph('Puedes escribir para proponer un tema, compartir una fuente o comentar una colaboración. Si has encontrado un error, incluye el enlace de la entrada y el detalle que debería revisar.'),
                    $this->paragraph('<strong>Correo de ejemplo:</strong> <a href="mailto:hola@example.com">hola@example.com</a>'),
                    $this->heading('Qué incluir en tu mensaje'),
                    ['type' => 'list', 'data' => ['ordered' => false, 'items' => [['text' => 'Un asunto que resuma tu consulta.'], ['text' => 'El enlace de la página, si escribes sobre un artículo.'], ['text' => 'Solo la información necesaria; nunca contraseñas ni datos sensibles.']]]],
                ]],
                'privacidad' => ['Privacidad', 'Privacidad y almacenamiento del navegador', 'Un ejemplo de cómo explicar el uso de los datos con claridad.', [
                    $this->notice('Borrador de ejemplo · requiere adaptación', 'Este texto no es una política legal terminada. Completa los campos entre corchetes y verifica los servicios, plazos y obligaciones aplicables antes de abrir el sitio al público.', 'warning'),
                    $this->heading('Quién es responsable'),
                    $this->paragraph('Responsable: <strong>[nombre de la persona o entidad]</strong>. Contacto: <strong>[correo de privacidad]</strong>. Domicilio y demás datos exigibles: <strong>[completar según la legislación aplicable]</strong>.'),
                    $this->heading('Qué información se utiliza'),
                    $this->paragraph('Para atender un mensaje se utiliza la información que la persona facilita. El alojamiento y las funciones de seguridad pueden registrar datos técnicos, como la dirección IP, la fecha y los intentos de acceso. Describe aquí qué registros mantienes, su finalidad, la base legal y el plazo de conservación: <strong>[completar]</strong>.'),
                    $this->heading('Cookies y preferencias'),
                    $this->paragraph('Este CMS utiliza cookies de sesión y de protección de formularios. La elección de tema claro u oscuro se conserva en el almacenamiento local del navegador. Las estadísticas de visitas propias del blog guardan ahí solo la fecha de tu primera visita, sin ningún identificador, y no comparten datos con terceros. No son lo mismo que las cookies publicitarias. Enumera aquí las cookies y servicios realmente activos en tu instalación, con sus finalidades y duración.'),
                    $this->heading('Contenido de otros servicios'),
                    $this->paragraph('Las entradas pueden incorporar videos de YouTube mediante su modo de privacidad mejorada. Al cargar contenido externo puede haber comunicación con ese proveedor. Revisa qué servicios has incluido, qué datos reciben y si necesitas consentimiento previo. Añade también el proveedor de alojamiento, correo y analítica, si corresponde.'),
                    $this->heading('Conservación, destinatarios y derechos'),
                    $this->paragraph('Plazos de conservación: <strong>[indicar por finalidad]</strong>. Proveedores y transferencias: <strong>[completar]</strong>. Para ejercer tus derechos, escribe a <strong>[correo de privacidad]</strong>. Explica los derechos aplicables, cómo retirar el consentimiento cuando sea la base utilizada y cómo reclamar ante la autoridad competente.'),
                    $this->paragraph('Última revisión: <strong>[fecha]</strong>. Actualiza esta página cuando cambien los datos tratados o los servicios utilizados.'),
                ]],
                'uso-del-contenido' => ['Uso del contenido', 'Uso del contenido y referencias', 'Una base editable para explicar cómo compartir y utilizar tus publicaciones.', [
                    $this->notice('Condiciones de ejemplo', 'Define aquí tus condiciones reales. Este ejemplo no sustituye el aviso legal o las condiciones específicas que pudieran corresponder a tu actividad.', 'warning'),
                    $this->heading('Compartir y reutilizar'),
                    $this->paragraph('Puedes compartir enlaces a las entradas. Para reproducir textos, ilustraciones o archivos, consulta la licencia o autorización indicada en cada caso. Condiciones del contenido original de este sitio: <strong>[define los usos permitidos y el contacto para solicitar permiso]</strong>.'),
                    $this->heading('Fuentes y materiales de terceros'),
                    $this->paragraph('Los libros, películas, videos y otros materiales citados pertenecen a sus respectivos titulares. Enlazar o comentar una obra no implica afiliación, patrocinio ni autorización para redistribuirla. La licencia del código del CMS y los permisos de los contenidos del blog son cuestiones independientes.'),
                    $this->heading('Información y correcciones'),
                    $this->paragraph('Las publicaciones tienen finalidad informativa. Verifica las fuentes y las condiciones de tu entorno antes de aplicar una explicación técnica. Si detectas un error o un problema con un contenido, comunica el enlace y el motivo a <strong>[correo de contacto]</strong> para que pueda revisarse.'),
                    $this->heading('Identificación del sitio'),
                    $this->paragraph('Titular y datos de contacto: <strong>[completar]</strong>. Si tu país o actividad requiere información adicional, inclúyela aquí o crea un Aviso legal específico.'),
                ]],
            ];

            foreach ($definitions as $slug => [$title, $heading, $intro, $content]) {
                if (Page::withTrashed()->where('slug', $slug)->exists()) {
                    continue;
                }
                $page = Page::create(['slug' => $slug, 'title' => $title, 'published' => true, 'settings' => ['heading' => $heading, 'show_heading' => true, 'intro' => $intro, 'show_intro' => true], 'content' => $content]);
                $navigation->setPage($page, true, 'footer');
                if ($slug === 'contacto') {
                    $navigation->setPage($page, true, 'header');
                }
            }
        });
    }

    private function heading(string $text): array
    {
        return ['type' => 'heading', 'data' => ['level' => 'h2', 'text' => $text]];
    }

    private function paragraph(string $html): array
    {
        return ['type' => 'paragraph', 'data' => ['html' => '<p>'.$html.'</p>']];
    }

    private function notice(string $title, string $text, string $tone = 'info'): array
    {
        return ['type' => 'notice', 'data' => compact('title', 'text', 'tone')];
    }
}
