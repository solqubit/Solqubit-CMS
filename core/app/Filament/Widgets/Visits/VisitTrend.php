<?php

namespace App\Filament\Widgets\Visits;

use App\Services\VisitStatistics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

/** Views and unique visitors over time: per day, or per week for the twelve-month range. */
class VisitTrend extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function getHeading(): string
    {
        return $this->weekly() ? 'Visitas por semana' : 'Visitas por día';
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $statistics = app(VisitStatistics::class);
        $range = $statistics->range((int) ($this->pageFilters['range'] ?? 30));
        $points = [];
        foreach ($statistics->daily($range['from'], $range['to']) as $day => $values) {
            $date = Carbon::parse($day)->locale('es');
            $key = $this->weekly() ? $date->copy()->startOfWeek()->toDateString() : $day;
            $points[$key] ??= ['label' => $this->weekly() ? 'Semana del '.$date->copy()->startOfWeek()->isoFormat('D MMM') : $date->isoFormat('ddd D MMM'), 'views' => 0, 'visitors' => 0];
            $points[$key]['views'] += $values['views'];
            $points[$key]['visitors'] += $values['visitors'];
        }

        return [
            'datasets' => [
                ['label' => 'Visitantes únicos', 'data' => array_column($points, 'visitors'), 'borderColor' => '#6950ae', 'backgroundColor' => 'rgba(105, 80, 174, 0.14)', 'fill' => true, 'tension' => 0.35, 'pointRadius' => 0, 'pointHoverRadius' => 4, 'borderWidth' => 2],
                ['label' => 'Páginas vistas', 'data' => array_column($points, 'views'), 'borderColor' => '#326b99', 'backgroundColor' => 'rgba(50, 107, 153, 0.06)', 'fill' => true, 'tension' => 0.35, 'pointRadius' => 0, 'pointHoverRadius' => 4, 'borderWidth' => 2],
            ],
            'labels' => array_column($points, 'label'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]]],
            'scales' => [
                'x' => ['grid' => ['display' => false], 'ticks' => ['maxTicksLimit' => 8, 'maxRotation' => 0]],
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }

    private function weekly(): bool
    {
        return (int) ($this->pageFilters['range'] ?? 30) > 90;
    }
}
