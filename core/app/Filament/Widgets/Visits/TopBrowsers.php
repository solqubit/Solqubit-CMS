<?php

namespace App\Filament\Widgets\Visits;

class TopBrowsers extends VisitRanking
{
    protected string $dimension = 'browser';

    protected string $heading = 'Navegadores';

    protected string $column = 'Navegador';
}
