<!doctype html>
<html lang="es" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Instalación completada · Solqubit</title><link rel="stylesheet" href="{{ asset('themes/solqubit/editorial-theme.css') }}"><link rel="stylesheet" href="{{ asset('installer.css') }}"></head>
<body class="installation-page"><main class="installer container">
<header class="install-header"><a class="brand" href="{{ route('home') }}">solqubit<span class="brand-dot">.</span></a><span class="status-badge is-ok">✓ Instalación completada</span></header>
<div class="install-intro"><span class="install-eyebrow">YA PUEDES EMPEZAR</span><h1>Tu blog ya está instalado.</h1><p>La base de datos y el administrador ya están configurados. Tu próxima idea empieza en el editor.</p></div>
<section class="install-panel"><h2>Todo en su lugar.</h2><p>El asistente se ha bloqueado para proteger tu configuración. Entra al panel para escribir tu primera entrada o personalizar el sitio.</p><div class="database-check"><a class="button install-primary" href="{{ route('filament.admin.auth.login') }}">Entrar al panel →</a><a class="install-link" href="{{ route('home') }}">Visitar mi blog</a></div></section>
@if(config('solqubit.installer_demo_url'))<section class="install-panel"><h2>Probar la instalación desde cero</h2><p>El entorno local nuevo tiene su propia base de datos y su propio administrador.</p><a class="install-link" href="{{ config('solqubit.installer_demo_url') }}">Abrir asistente de instalación →</a></section>@endif
<footer class="install-footer">Hecho para compartir lo que sabes. <span>Solqubit CMS</span></footer>
</main></body></html>
