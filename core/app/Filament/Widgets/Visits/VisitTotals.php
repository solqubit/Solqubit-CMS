<?php

namespace App\Filament\Widgets\Visits;

use App\Services\VisitStatistics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Headline figures of the chosen period, each compared with the period before it. */
class VisitTotals extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    protected function rangeDays(): int
    {
        return (int) ($this->pageFilters['range'] ?? 30);
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $statistics = app(VisitStatistics::class);
        $range = $statistics->range($this->rangeDays());
        $daily = $statistics->daily($range['from'], $range['to']);
        $current = $statistics->totals($range['from'], $range['to']);
        $previous = $statistics->totals($range['previousFrom'], $range['previousTo']);
        $comparison = $range['days'] === 365 ? 'frente a los 12 meses anteriores' : 'frente a los '.$range['days'].' días anteriores';
        $returningShare = $current['visitors'] > 0 ? round($current['returning'] / $current['visitors'] * 100) : 0;
        $pagesPerVisitor = $current['visitors'] > 0 ? $current['views'] / $current['visitors'] : 0;

        return [
            $this->compared(Stat::make('Visitas únicas', $this->number($current['visitors'])), $current['visitors'], $previous['visitors'], $comparison, 'Cada persona cuenta una vez por día. ')
                ->chart(array_column($daily, 'visitors')),
            $this->compared(Stat::make('Páginas vistas', $this->number($current['views'])), $current['views'], $previous['views'], $comparison)
                ->chart(array_column($daily, 'views')),
            Stat::make('Visitantes que vuelven', $returningShare.' %')
                ->description($this->number($current['returning']).' de '.$this->number($current['visitors']).' ya habían venido otro día')
                ->chart(array_column($daily, 'returning'))->color('info'),
            Stat::make('Páginas por visitante', number_format($pagesPerVisitor, 1, ',', '.'))
                ->description('Promedio de cada visitante en un día'),
        ];
    }

    protected function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function compared(Stat $stat, int $current, int $previous, string $comparison, string $note = ''): Stat
    {
        if ($previous === 0) {
            return $stat->description($note.($current > 0 ? 'Sin datos del periodo anterior' : 'Todavía no hay visitas'))->color('primary');
        }
        $change = ($current - $previous) / $previous * 100;
        $rising = $change >= 0;

        return $stat->description($note.($rising ? '+' : '−').number_format(abs($change), 0, ',', '.').' % '.$comparison)
            ->descriptionIcon($rising ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($rising ? 'success' : 'danger');
    }
}
