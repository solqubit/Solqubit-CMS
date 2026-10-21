<?php

namespace App\Filament\Widgets\Visits;

class TopSystems extends VisitRanking
{
    protected string $dimension = 'os';

    protected string $heading = 'Sistemas operativos';

    protected string $column = 'Sistema';
}
