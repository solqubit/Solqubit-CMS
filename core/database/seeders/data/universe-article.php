<?php

return function (int $image, int $file, int $video): array {
    $block = fn (string $type, array $data): array => compact('type', 'data');
    $heading = fn (string $text): array => $block('heading', ['level' => 'h2', 'text' => $text]);
    $paragraph = fn (string $html): array => $block('paragraph', ['html' => $html]);

    return [
        $paragraph('<p>Hay preguntas que hacen que una noche cualquiera parezca inmensa. Miramos una estrella, imaginamos los planetas que podrían rodearla y nos preguntamos si alguien, en algún lugar, también estará mirando hacia arriba.</p><p><strong>¿Estamos solos?</strong> La ciencia todavía no tiene una respuesta. Pero la búsqueda ya transforma nuestra manera de entender la Tierra y el lugar que ocupa en el universo.</p>'),
        $block('notice', ['tone' => 'info', 'title' => 'Una pregunta abierta', 'text' => 'No hay una detección confirmada de vida fuera de la Tierra. Esta entrada distingue los indicios científicos de las ideas de ficción. Es un artículo de muestra que puedes editar por completo.']),
        $heading('Lo que buscamos cuando buscamos vida'),
        $paragraph('<p>La astrobiología reúne distintas disciplinas para estudiar el origen, la evolución y la posible distribución de la vida. Nuestra referencia sigue siendo la biología terrestre, pero buscar vida no equivale a buscar una copia de nosotros.</p><p>El agua líquida, las fuentes de energía y una química adecuada ayudan a decidir dónde investigar. También estudiamos organismos que viven en ambientes extremos de nuestro planeta: amplían nuestra idea de lo posible, aunque no demuestran que haya habitantes en otros mundos. <a href="https://astrobiology.nasa.gov/about/">Fuente: NASA Astrobiology</a>.</p>'),
        $block('list', ['title' => 'Tres preguntas para explorar un mundo', 'ordered' => false, 'items' => [
            ['text' => '¿Existe un medio donde puedan ocurrir reacciones químicas, como agua líquida?'],
            ['text' => '¿Hay energía disponible para sostener procesos biológicos?'],
            ['text' => '¿Las condiciones podrían mantenerse durante suficiente tiempo?'],
        ]]),
        $heading('Un mapa de posibilidades'),
        $paragraph('<p>Un lugar potencialmente habitable no tiene por qué parecerse a una postal de la Tierra. Bajo el hielo de algunas lunas podrían existir océanos; en otros planetas buscamos señales de ambientes que fueron diferentes en el pasado.</p>'),
        $block('table', ['columns' => ['Destino', 'Por qué interesa', 'Qué falta saber'], 'rows' => [
            ['cells' => 'Marte | Ambientes antiguos relacionados con agua | Si alguna vez albergó vida'],
            ['cells' => 'Europa | Evidencias de un océano bajo su hielo | Si reúne condiciones adecuadas para la vida'],
            ['cells' => 'Encélado | Océano interno y material expulsado al espacio | Si sus ingredientes se relacionan con procesos biológicos'],
            ['cells' => 'Exoplanetas | Mundos alrededor de otras estrellas | Cómo interpretar sus atmósferas y posibles señales de vida'],
        ]]),
        $paragraph('<p>La distancia a una estrella es una pista útil, no un veredicto. La atmósfera, el interior y la historia de cada mundo también importan. <a href="https://science.nasa.gov/universe/search-for-life/life-on-other-planets-what-is-life-and-what-does-it-need/">Fuente: NASA, qué necesita la vida</a>.</p>'),
        $block('image', ['media_id' => $image, 'caption' => 'Un mundo posible, imaginado. Ilustración original generada con IA para esta entrada; no representa un descubrimiento ni una imagen captada por una misión.']),
        $heading('Proyecto fin del mundo: imaginar el encuentro'),
        $paragraph('<p><em>Proyecto fin del mundo</em> —<em>Project Hail Mary</em>— lleva esta curiosidad a la ciencia ficción. La historia, basada en la novela de Andy Weir, sigue a Ryland Grace, que despierta lejos de casa con recuerdos incompletos y una misión de la que depende la humanidad. <a href="https://global.penguinrandomhouse.com/announcements/andy-weirs-project-hail-mary-premieres-on-the-big-screen-starring-ryan-gosling/">Referencia: Penguin Random House</a>.</p><p>Sin entrar en sus giros, lo que me interesa como punto de partida es la idea de enfrentarse a lo desconocido mediante preguntas y experimentos. La ficción nos permite imaginar el encuentro; la investigación tiene que justificar cada conclusión con datos.</p><blockquote>Encontrar un lugar donde podría existir vida es abrir una pregunta, no resolverla.</blockquote>'),
        $block('notice', ['tone' => 'warning', 'title' => 'Ficción no significa evidencia', 'text' => 'Los seres y mecanismos de una historia pueden inspirar preguntas, pero no son descubrimientos científicos. Tampoco una molécula aislada o un titular sorprendente bastan para confirmar vida extraterrestre.']),
        $heading('Del asombro a una misión real'),
        $paragraph('<p>Europa Clipper estudia una pregunta concreta: si Europa puede reunir condiciones aptas para la vida. Su objetivo es investigar el hielo, el océano y la composición de esta luna de Júpiter. <strong>No es una misión que prometa encontrar organismos.</strong> <a href="https://science.nasa.gov/mission/europa-clipper/mission-overview/">Objetivos oficiales de la misión</a>.</p>'),
        $block('video', ['url' => 'https://www.youtube.com/watch?v=AOEtSq7XHA8', 'caption' => 'Europa Clipper: presentación oficial de la misión, por NASA. Video externo en inglés; puedes abrirlo también en YouTube.']),
        $block('heading', ['level' => 'h3', 'text' => 'Una pausa para mirar las órbitas']),
        $paragraph('<p>Esta animación local representa dos órbitas circulares imaginarias. Es un recurso visual de ejemplo: tamaños, velocidades y distancias no están a escala, y no reproduce la trayectoria de Europa Clipper.</p>'),
        $block('media_video', ['media_id' => $video, 'caption' => 'Órbitas ilustradas · animación original de 8 segundos, sin sonido. Se reproduce desde la biblioteca del blog.']),
        $heading('Un pequeño experimento con la distancia'),
        $paragraph('<p>Un año luz es una distancia: la que recorre la luz en un año. Si imaginamos una señal enviada desde un mundo a diez años luz, el intercambio no sería una conversación instantánea.</p><p>Puedes copiar este ejemplo de Python. Es un cálculo idealizado: supone distancias fijas y una respuesta inmediata, y no describe un viaje de una nave.</p>'),
        $block('code', ['text' => "distancia_anos_luz = 10\nida = distancia_anos_luz\nida_y_vuelta = ida * 2\nprint(f\"La señal tarda {ida} años en llegar.\")\nprint(f\"La respuesta llegaría tras {ida_y_vuelta} años.\")

# Resultado:
# La señal tarda 10 años en llegar.
# La respuesta llegaría tras 20 años."]),
        $heading('Cómo leer el próximo gran anuncio'),
        $block('list', ['title' => 'Antes de compartir un descubrimiento', 'ordered' => true, 'items' => [
            ['text' => 'Lee la fuente original y qué afirma realmente el equipo.'],
            ['text' => 'Distingue una observación, una interpretación y una confirmación.'],
            ['text' => 'Busca explicaciones alternativas y comprobaciones independientes.'],
        ]]),
        $block('details', ['title' => '¿Una biofirma confirma por sí sola que hay vida?', 'text' => 'No necesariamente. Una señal que podría tener origen biológico también puede admitir explicaciones no biológicas. Es necesario estudiar el contexto, descartar alternativas y reunir evidencias independientes. [NASA explica cómo busca señales de vida](https://science.nasa.gov/exoplanets/can-we-find-life/).']),
        $block('notice', ['tone' => 'note', 'title' => 'Mantener la curiosidad, cuidar las conclusiones', 'text' => 'No saber todavía la respuesta no vuelve inútil la búsqueda. Cada observación puede ayudarnos a formular una pregunta mejor.']),
        $block('divider', []),
        $heading('Tu cuaderno para seguir explorando'),
        $paragraph('<p>Guarda preguntas, anota lo que te sorprende y vuelve a las fuentes. El archivo incluye una pequeña ficha para distinguir observaciones, hipótesis y ficción.</p>'),
        $block('file', ['media_id' => $file, 'label' => 'Cuaderno del universo', 'caption' => 'Notas, preguntas y referencias en un archivo de texto editable.']),
        $block('links', ['items' => [
            ['label' => 'NASA · Qué estudia la astrobiología', 'url' => 'https://astrobiology.nasa.gov/about/'],
            ['label' => 'NASA · La búsqueda de vida fuera de la Tierra', 'url' => 'https://science.nasa.gov/exoplanets/can-we-find-life/'],
            ['label' => 'NASA · Europa Clipper y sus objetivos', 'url' => 'https://science.nasa.gov/mission/europa-clipper/mission-overview/'],
            ['label' => 'Penguin Random House · Project Hail Mary, de Andy Weir', 'url' => 'https://global.penguinrandomhouse.com/announcements/andy-weirs-project-hail-mary-premieres-on-the-big-screen-starring-ryan-gosling/'],
        ]]),
    ];
};
