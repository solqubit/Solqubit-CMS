<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\HomeSettings;
use App\Filament\Resources\Posts\PostResource;
use Filament\Widgets\Widget;

class SiteGuide extends Widget
{
    protected string $view = 'filament.widgets.site-guide';

    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $links = [
            ['title' => 'Escribir una entrada', 'description' => 'Publica tus ideas con texto, imágenes, videos y archivos.', 'url' => PostResource::getUrl('create'), 'icon' => 'heroicon-o-pencil-square', 'tone' => 'violet'],
            ['title' => 'Mis entradas', 'description' => 'Revisa borradores, publicaciones y entradas programadas.', 'url' => PostResource::getUrl('index', auth()->user()?->isAdmin() ? [] : ['tableFilters' => ['mine' => ['isActive' => 1]]]), 'icon' => 'heroicon-o-document-text', 'tone' => 'blue'],
        ];
        if (auth()->user()?->isAdmin()) {
            $links[] = ['title' => 'Portada del blog', 'description' => 'Cambia la bienvenida y la forma de mostrar las entradas.', 'url' => HomeSettings::getUrl(), 'icon' => 'heroicon-o-home', 'tone' => 'peach'];
        }

        return ['links' => $links];
    }
}
