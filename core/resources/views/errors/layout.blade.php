@php
    $status = isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
@endphp
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>@yield('title') · Error {{ $status }}</title>
<style>
:root{color-scheme:light dark;--bg:#f7f7fb;--ink:#24213a;--muted:#5f5a78;--accent:#8a3a62;--line:#e2dfee}
@media (prefers-color-scheme:dark){:root{--bg:#16141f;--ink:#efeaf7;--muted:#b4adc8;--accent:#e58fb6;--line:#2f2b40}}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:var(--bg);color:var(--ink);font:16px/1.7 system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:560px}small{font:600 12px ui-monospace,monospace;letter-spacing:.08em;text-transform:uppercase;color:var(--accent)}
h1{font-size:clamp(28px,6vw,40px);line-height:1.15;margin:10px 0 14px}p{color:var(--muted);margin:0 0 26px}
a{display:inline-block;padding:11px 20px;border:2px solid var(--accent);border-radius:14px;color:var(--accent);font-weight:700;text-decoration:none}a:hover,a:focus-visible{background:var(--accent);color:var(--bg)}
</style>
</head><body><main><small>Error {{ $status }}</small><h1>@yield('title')</h1><p>@yield('message')</p><a href="{{ url('/') }}">Volver a la portada</a></main></body></html>
