<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Statistics;
use App\Filament\Widgets\Visits\VisitTotals;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The last 30 days of visits on the dashboard, linking to the full statistics. Lazy, so it never slows the dashboard. */
class VisitsSummary extends VisitTotals
{
    protected static bool $isDiscovered = true;

    protected static ?int $sort = -5;

    protected ?string $heading = 'Visitas de los últimos 30 días';

    protected ?string $description = 'Pulsa una cifra para ver las estadísticas completas.';

    protected function rangeDays(): int
    {
        return 30;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        return array_map(fn (Stat $stat): Stat => $stat->url(Statistics::getUrl()), array_slice(parent::getStats(), 0, 3));
    }
}
