<?php

namespace App\Filament\Widgets\Visits;

use App\Services\VisitStatistics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Support\Htmlable;

/** A ranked list of one dimension (pages, countries, sources…) with a bar for each value's share of visitors. */
abstract class VisitRanking extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.visit-ranking';

    protected string $dimension = 'path';

    protected string $heading = '';

    protected string $column = '';

    protected int $limit = 8;

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $statistics = app(VisitStatistics::class);
        $range = $statistics->range((int) ($this->pageFilters['range'] ?? 30));
        $rows = $statistics->top($this->dimension, $range['from'], $range['to'], $this->limit);
        $total = max(1, $statistics->totals($range['from'], $range['to'])['visitors']);
        $highest = max([1, ...array_column($rows, 'visitors')]);

        return [
            'heading' => $this->heading,
            'column' => $this->column,
            'note' => $this->note(),
            'rows' => array_map(fn (array $row): array => $row + [
                'share' => (int) round($row['visitors'] / $total * 100),
                'bar' => round($row['visitors'] / $highest * 100, 1),
                'prefix' => $this->prefix($row),
                'detail' => $this->detail($row),
                'url' => $this->url($row),
            ], $rows),
        ];
    }

    /**
     * @param  array{value: string, label: string, views: int, visitors: int}  $row
     */
    protected function prefix(array $row): ?string
    {
        return null;
    }

    /**
     * @param  array{value: string, label: string, views: int, visitors: int}  $row
     */
    protected function detail(array $row): ?string
    {
        return null;
    }

    /**
     * @param  array{value: string, label: string, views: int, visitors: int}  $row
     */
    protected function url(array $row): ?string
    {
        return null;
    }

    protected function note(): string|Htmlable|null
    {
        return null;
    }
}
