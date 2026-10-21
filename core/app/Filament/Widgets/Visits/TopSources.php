<?php

namespace App\Filament\Widgets\Visits;

class TopSources extends VisitRanking
{
    protected string $dimension = 'source';

    protected string $heading = 'De dónde llegan';

    protected string $column = 'Origen';

    protected int $limit = 10;

    protected function detail(array $row): ?string
    {
        return $row['value'] === '' ? 'Enlaces guardados, direcciones escritas a mano o sitios que no lo indican' : null;
    }
}
