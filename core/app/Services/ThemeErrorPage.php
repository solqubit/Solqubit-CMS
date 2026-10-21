<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ThemeErrorPage
{
    /**
     * @var array<int, array{title: string, message: string}>
     */
    public const MESSAGES = [
        400 => ['title' => 'No pudimos entender esta dirección', 'message' => 'La dirección contiene datos que no son válidos. Revisa el enlace o vuelve a la portada.'],
        403 => ['title' => 'Esta página no está disponible', 'message' => 'No tienes permiso para ver este contenido.'],
        404 => ['title' => 'No encontramos esta página', 'message' => 'Puede que la dirección haya cambiado o que la entrada ya no exista. Prueba a buscarla o mira lo más reciente.'],
        410 => ['title' => 'Esta página ya no existe', 'message' => 'El contenido se retiró de forma permanente. Mira lo más reciente del blog.'],
    ];

    public function __construct(private ThemeRegistry $themes, private SiteLayout $layout) {}

    /**
     * Render a public error with the site's theme, or return null to fall back to the plain error view.
     */
    public function render(Request $request, int $status): ?Response
    {
        if (! isset(self::MESSAGES[$status]) || $request->expectsJson() || ! $request->isMethod('GET')
            || AdminAccess::isProtectedRequest($request) || $request->is('install*', 'media/*')
            || ! is_file(config('solqubit.installed_file'))) {
            return null;
        }
        try {
            $layout = $this->layout->data();
            $siteName = (string) ($layout['site']['name'] ?? 'Blog');
            $recent = Post::published()->select(['id', 'title', 'slug', 'url_prefix', 'excerpt', 'cover_id', 'series_id', 'reading_minutes', 'difficulty', 'published_at'])
                ->with(['cover', 'categories'])->latest('published_at')->orderByDesc('id')->limit(3)->get();
            $html = view($this->themes->view('error'), $layout + [
                'status' => $status, 'error' => self::MESSAGES[$status], 'recent' => $recent,
                'seo' => [
                    'title' => self::MESSAGES[$status]['title'].' — '.$siteName, 'description' => self::MESSAGES[$status]['message'],
                    'robots' => 'noindex, follow', 'canonical' => null, 'type' => 'website', 'site_name' => $siteName,
                    'image' => null, 'image_alt' => '', 'published' => null, 'modified' => null, 'verification' => [], 'structured_data' => null,
                ],
            ])->render();
        } catch (\Throwable $error) {
            report($error);

            return null;
        }

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
