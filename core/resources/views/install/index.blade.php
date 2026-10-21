<!doctype html>
<html lang="es" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Instalar Solqubit</title>
<link rel="stylesheet" href="{{ asset('themes/solqubit/editorial-theme.css') }}"><link rel="stylesheet" href="{{ asset('installer.css') }}">
<script src="{{ asset('installer.js') }}" defer></script>
</head>
<body class="installation-page"><main class="installer container">
<header class="install-header"><a class="brand" href="{{ route('install.index') }}">solqubit<span class="brand-dot">.</span></a><span class="install-version">CMS · {{ config('solqubit.version') }}</span></header>
<div class="install-intro"><span class="install-eyebrow">BIENVENIDO A TU NUEVO BLOG</span><h1>Tu blog empieza aquí.</h1><p>Unos pocos datos y todo estará listo para tu primera entrada.</p></div>
<nav class="install-steps" aria-label="Secciones de la instalación"><a href="#requisitos"><span>01</span> Servidor</a><a href="#conexion"><span>02</span> Base de datos</a><a href="#tu-sitio"><span>03</span> Tu espacio</a><a href="#contenido"><span>04</span> Contenido</a></nav>

<section class="install-panel" id="requisitos" aria-labelledby="requirements-title">
<div class="panel-heading"><div><span class="install-eyebrow">ANTES DE EMPEZAR</span><h2 id="requirements-title">Un vistazo al servidor</h2></div><span class="status-badge {{ $ready ? 'is-ok' : 'is-error' }}"><span aria-hidden="true">{{ $ready ? '✓' : '×' }}</span> {{ $ready ? 'Todo listo' : 'Requiere atención' }}</span></div>
<p>{{ $ready ? 'Tu alojamiento cumple los requisitos para instalar Solqubit.' : 'Resuelve los puntos en rojo y vuelve a comprobar el servidor para continuar.' }}</p>
<details class="requirements" @if(!$ready) open @endif>
<summary><span>{{ count(array_filter($checks, fn ($check) => $check['ok'])) }} de {{ count($checks) }} comprobaciones correctas</span><span class="requirements-toggle">Ver detalle <span aria-hidden="true">⌄</span></span></summary>
<ul class="requirement-list">@foreach($checks as $check)
<li class="requirement-item {{ $check['ok'] ? 'is-ok' : 'is-error' }}"><span class="status-icon" aria-hidden="true">{{ $check['ok'] ? '✓' : '×' }}</span><div><strong>{{ $check['name'] }}</strong><small>{{ $check['detail'] }}</small></div><span class="requirement-state">{{ $check['ok'] ? 'Correcto' : 'Pendiente' }}</span></li>
@endforeach</ul></details>
<a class="install-link" href="{{ route('install.index') }}">↻ Volver a comprobar el servidor</a>
</section>

@if($errors->any())<div class="install-feedback is-error" role="alert" tabindex="-1" id="install-errors"><strong>Revisa estos datos antes de continuar</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="post" action="{{ route('install.store') }}" id="installation-form" data-database-url="{{ route('install.database') }}">
@csrf
<fieldset class="install-panel" id="conexion">
<legend class="sr-only">Conexión a la base de datos</legend>
<div class="panel-heading"><div><span class="install-eyebrow">DONDE VIVIRÁ TU CONTENIDO</span><h2>Base de datos</h2></div><span class="panel-number" aria-hidden="true">02</span></div>
<p>Crea una base vacía y su usuario en el panel de tu alojamiento. Copia aquí sus datos.</p>
<p class="field-help"><strong>Bases compatibles: MySQL 8.0 o superior y MariaDB 10.11 o superior.</strong> Ambas usan esta misma conexión. Este instalador no admite PostgreSQL ni SQLite.</p>
<div class="install-grid database-fields">
<label>Servidor<input name="db_host" value="{{ old('db_host', config('solqubit.installation_database_host')) }}" required maxlength="255" aria-describedby="database-host-help" spellcheck="false" autocapitalize="none"></label>
<label>Puerto<input name="db_port" type="number" value="{{ old('db_port', 3306) }}" required min="1" max="65535"></label>
<label>Nombre de la base<input name="db_database" value="{{ old('db_database', config('solqubit.installation_database_name')) }}" required maxlength="64" placeholder="cuenta_blog" spellcheck="false" autocapitalize="none"></label>
<label>Usuario de la base<input name="db_username" value="{{ old('db_username', config('solqubit.installation_database_user')) }}" required maxlength="80" autocomplete="off" placeholder="cuenta_usuario" spellcheck="false" autocapitalize="none"></label>
<label class="full-width">Contraseña de la base<input type="password" name="db_password" required maxlength="255" autocomplete="off"></label>
</div>
<p class="field-help" id="database-host-help">@if(config('solqubit.installation_database_host') === 'database')En esta prueba local usa <code>database</code>: la base de datos está en otro contenedor. En un alojamiento suele ser <code>localhost</code>.@elseNormalmente el servidor es <code>localhost</code>. Cámbialo solo si tu proveedor te indica otro.@endif</p>
<div class="database-check" hidden><button type="button" class="install-secondary" id="check-database" @disabled(!$ready)>Comprobar conexión</button><span class="field-help">Esta comprobación no crea ni modifica tablas.</span></div>
<div id="database-result" class="install-feedback" role="status" aria-live="polite" hidden></div>
</fieldset>

<fieldset class="install-panel" id="tu-sitio">
<legend class="sr-only">Tu sitio y su administrador</legend>
<div class="panel-heading"><div><span class="install-eyebrow">HAZLO TUYO</span><h2>Tu espacio en la web</h2></div><span class="panel-number" aria-hidden="true">03</span></div>
<div class="install-grid">
<label>Nombre del blog<input name="site_name" value="{{ old('site_name', 'Solqubit') }}" required maxlength="100"></label>
<label>Zona horaria<select name="timezone">@foreach(DateTimeZone::listIdentifiers() as $zone)<option @selected(old('timezone', 'America/Guayaquil') === $zone)>{{ $zone }}</option>@endforeach</select></label>
</div>
<div class="install-divider"></div><h3>Tu cuenta de administrador</h3><p class="field-help">Podrás entrar con tu usuario o correo y la misma contraseña. El correo se usa para recuperar el acceso.</p>
<div class="install-grid">
<label>Tu nombre<input name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name"></label>
<label>Usuario para iniciar sesión<input name="username" value="{{ old('username') }}" required minlength="3" maxlength="40" pattern="[a-zA-Z0-9][a-zA-Z0-9_.\-]{2,39}" autocomplete="username" autocapitalize="none" spellcheck="false"><span class="field-help">Letras sin tildes, números, punto, guion o guion bajo.</span></label>
<label class="full-width">Correo electrónico<input name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email"></label>
<label>Contraseña<input name="password" type="password" required minlength="12" autocomplete="new-password" aria-describedby="password-checks"></label>
<label>Repetir contraseña<input name="password_confirmation" type="password" required autocomplete="new-password" aria-describedby="password-match"></label>
</div>
<ul class="password-checks" id="password-checks" aria-label="Requisitos de contraseña"><li data-password-rule="length"><span aria-hidden="true">○</span> Al menos 12 caracteres</li><li data-password-rule="letters"><span aria-hidden="true">○</span> Letras y números</li><li id="password-match" data-password-rule="match"><span aria-hidden="true">○</span> Las contraseñas coinciden</li></ul>
</fieldset>

<fieldset class="install-panel" id="contenido">
<legend class="sr-only">Contenido inicial</legend>
<div class="panel-heading"><div><span class="install-eyebrow">ELIGE TU PUNTO DE PARTIDA</span><h2>¿Cómo quieres empezar?</h2></div><span class="panel-number" aria-hidden="true">04</span></div>
<p>Elige una opción. Podrás crear y eliminar entradas desde el panel en cualquier momento.</p>
<div class="starter-options">
<label class="starter-option"><input type="radio" name="starter_content" value="sample" @checked(old('starter_content', 'sample') === 'sample') required aria-describedby="sample-description">
<span class="starter-card">
<span class="starter-preview preview-sample" aria-hidden="true"><span class="mini-article"><span class="mini-cover">✦ <span>&lt;/&gt;</span></span><span class="mini-title"></span><span class="mini-line"></span><span class="mini-line short"></span><span class="mini-code">&gt;_ Tu próxima idea</span></span><span class="preview-label">Explora el editor</span></span>
<span class="starter-card-body"><span class="starter-title"><strong>Una entrada de muestra y tus páginas</strong><span class="choice-mark" aria-hidden="true">✓</span></span><span id="sample-description" class="starter-description">Una entrada sobre la vida fuera de la Tierra con los 14 tipos de bloque, imágenes, videos y descarga. Incluye Sobre mí, Contacto, Privacidad y Uso del contenido con textos de ejemplo y menús ordenados. Revisa los textos legales antes de publicar tu sitio.</span><span class="starter-tags"><span>Entrada completa</span><span>Páginas editables</span><span>Encabezado y pie</span></span></span>
</span></label>
<label class="starter-option"><input type="radio" name="starter_content" value="empty" @checked(old('starter_content') === 'empty') required aria-describedby="empty-description">
<span class="starter-card">
<span class="starter-preview preview-empty" aria-hidden="true"><span class="mini-blank"><span class="blank-plus">+</span><span class="mini-title"></span><span class="mini-line"></span></span><span class="preview-label">Empieza a tu manera</span></span>
<span class="starter-card-body"><span class="starter-title"><strong>Un blog completamente vacío</strong><span class="choice-mark" aria-hidden="true">✓</span></span><span id="empty-description" class="starter-description">Tu blog listo para tu primera publicación. Sin entradas de ejemplo, categorías, etiquetas ni series.</span><span class="starter-tags"><span>Todo por crear</span><span>Sin ejemplos</span></span></span>
</span></label>
</div>
<p class="starter-note"><span aria-hidden="true">☀</span> El tema claro viene seleccionado. Puedes cambiarlo a oscuro cuando quieras.</p>
</fieldset>
<div class="install-submit"><div><strong>Todo listo para comenzar.</strong><p>La instalación configurará tu blog y creará tu cuenta.</p></div><button class="button install-primary" type="submit" @disabled(!$ready)>Instalar mi blog <span aria-hidden="true">→</span></button></div>
@if(!$ready)<p class="install-feedback is-error">Resuelve los requisitos pendientes del servidor para habilitar la instalación.</p>@endif
</form>
<footer class="install-footer">Hecho para compartir lo que sabes. <span>Solqubit CMS</span></footer>
</main></body></html>
