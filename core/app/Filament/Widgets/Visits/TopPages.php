<?php

namespace App\Filament\Widgets\Visits;

class TopPages extends VisitRanking
{
    protected string $dimension = 'path';

    protected string $heading = 'Páginas más vistas';

    protected string $column = 'Página';

    protected int $limit = 10;

    protected function detail(array $row): ?string
    {
        return $row['label'] !== $row['value'] ? $row['value'] : null;
    }

    protected function url(array $row): ?string
    {
        return url($row['value']);
    }
}
