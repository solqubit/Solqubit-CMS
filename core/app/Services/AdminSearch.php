<?php

namespace App\Services;

use App\Filament\Pages;
use App\Filament\Resources;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Illuminate\Support\Str;

class AdminSearch extends DefaultGlobalSearchProvider
{
    /** @var array<class-string, string> */
    private const KEYWORDS = [
        Pages\SiteSettings::class => 'ajustes configuracion marca nombre logo icono favicon identidad descripcion redes sociales youtube pie footer copyright',
        Pages\GeneralSettings::class => 'ajustes configuracion general zona horaria region fecha hora',
        Pages\AddressSettings::class => 'ajustes direcciones prefijos enlaces permanentes url',
        Pages\MailSettings::class => 'correo smtp email servidor remitente prueba recuperacion contraseña',
        Pages\AppearanceSettings::class => 'apariencia diseño tema plantilla paleta colores acento claro oscuro',
        Pages\NavigationSettings::class => 'navegacion menu enlaces encabezado header pie footer ordenar',
        Pages\AccessSettings::class => 'acceso seguridad login contraseña bloqueos baneados ip autenticacion',
        Pages\SystemStatus::class => 'sistema actualizaciones actualizar zip paquete servidor php requisitos version',
        Pages\MonetizationSettings::class => 'monetizacion publicidad anuncios adsense ingresos automaticos ads.txt',
        Pages\SeoSettings::class => 'seo buscadores google search console bing sitemap robots indexacion verificacion',
        Pages\HomeSettings::class => 'portada inicio home bienvenida listado destacada entradas por pagina',
        Pages\Statistics::class => 'estadisticas visitas visitantes paises paginas vistas trafico analitica',
        Resources\Posts\PostResource::class => 'entrada publicaciones blog articulos borradores publicar',
        Resources\Pages\PageResource::class => 'paginas sobre mi series contacto privacidad aviso legal',
        Resources\Media\MediaResource::class => 'biblioteca archivos imagenes fotos videos documentos subir',
        Resources\Categories\CategoryResource::class => 'categorias temas filtros',
        Resources\Series\SeriesResource::class => 'series colecciones ordenar',
        Resources\Tags\TagResource::class => 'etiquetas tags',
        Resources\Users\UserResource::class => 'usuarios autores permisos',
    ];

    public function getResults(string $query): ?GlobalSearchResults
    {
        $query = Str::squish($query);
        $panel = Filament::getCurrentOrDefaultPanel();

        if (! $panel || ! Filament::auth()->user()?->canAccessPanel($panel) || $query === '' || mb_strlen($query) > 160) {
            return null;
        }

        $results = GlobalSearchResults::make();
        $sections = [];
        $words = explode(' ', Str::lower(Str::ascii($query)));

        foreach ([...Filament::getPages(), ...Filament::getResources()] as $section) {
            if (! $section::shouldRegisterNavigation() || ! $section::canAccess()) {
                continue;
            }

            $label = $section::getNavigationLabel();
            $haystack = Str::lower(Str::ascii($label.' '.(self::KEYWORDS[$section] ?? '')));

            if (collect($words)->every(fn (string $word): bool => str_contains($haystack, $word))) {
                $sections[] = new GlobalSearchResult(title: $label, url: $section::getUrl());
            }
        }

        if ($sections !== []) {
            $results->category('Secciones del panel', array_slice($sections, 0, 8));
        }

        foreach (parent::getResults($query)->getCategories() as $category => $records) {
            $results->category($category, $records);
        }

        return $results;
    }
}
