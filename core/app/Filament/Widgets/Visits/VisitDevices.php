<?php

namespace App\Filament\Widgets\Visits;

use App\Services\VisitStatistics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Share of visitors on computers, phones and tablets. */
class VisitDevices extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Dispositivos';

    protected ?string $maxHeight = '240px';

    private const COLORS = ['desktop' => '#6950ae', 'mobile' => '#326b99', 'tablet' => '#93603a', '' => '#a8a3b8'];

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $statistics = app(VisitStatistics::class);
        $range = $statistics->range((int) ($this->pageFilters['range'] ?? 30));
        $rows = $statistics->top('device', $range['from'], $range['to'], 5);

        return [
            'datasets' => [[
                'label' => 'Visitantes',
                'data' => array_column($rows, 'visitors'),
                'backgroundColor' => array_map(fn (array $row): string => self::COLORS[$row['value']] ?? self::COLORS[''], $rows),
                'borderWidth' => 0,
            ]],
            'labels' => array_column($rows, 'label'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'cutout' => '68%',
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]]],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
        ];
    }
}
