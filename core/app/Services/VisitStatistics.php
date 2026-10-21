<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Locale;

/**
 * Daily summaries of the raw visits and the figures shown in the panel. Closed days are moved from
 * `visits` into `visit_stats` (one row per day, dimension and value) and their raw rows are deleted.
 */
class VisitStatistics
{
    public const DIMENSIONS = ['path', 'country', 'source', 'device', 'browser', 'os'];

    public const RANGES = [7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 90 días', 365 => 'Últimos 12 meses'];

    public const DEVICES = ['desktop' => 'Ordenador', 'mobile' => 'Móvil', 'tablet' => 'Tableta'];

    private const CACHE_SECONDS = 300;

    private const AGGREGATES = 'COUNT(*) as views, COUNT(DISTINCT visitor) as visitors, COUNT(DISTINCT CASE WHEN is_returning = 1 THEN visitor END) as returning_visitors';

    public function __construct(private VisitTracker $tracker) {}

    /**
     * Summarise every closed day still in the raw table. Several requests may call it at once; only one works.
     */
    public function compact(): void
    {
        $today = $this->tracker->day();
        if (! DB::table('visits')->where('day', '<', $today)->exists()) {
            return;
        }
        $lock = Cache::lock('solqubit-visit-compact', 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $days = DB::table('visits')->where('day', '<', $today)->distinct()->orderBy('day')->limit(31)->pluck('day');
            foreach ($days as $day) {
                $this->compactDay(substr((string) $day, 0, 10));
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{from: string, to: string, previousFrom: string, previousTo: string, days: int}
     */
    public function range(int $days): array
    {
        $days = array_key_exists($days, self::RANGES) ? $days : 30;
        $today = Carbon::parse($this->tracker->day());

        return [
            'from' => $today->copy()->subDays($days - 1)->toDateString(),
            'to' => $today->toDateString(),
            'previousFrom' => $today->copy()->subDays($days * 2 - 1)->toDateString(),
            'previousTo' => $today->copy()->subDays($days)->toDateString(),
            'days' => $days,
        ];
    }

    /**
     * Views, unique visitors and returning visitors of each day in the range, with empty days filled in.
     *
     * @return array<string, array{views: int, visitors: int, returning: int}>
     */
    public function daily(string $from, string $to): array
    {
        return $this->remember('daily.'.$from.'.'.$to, function () use ($from, $to): array {
            $days = [];
            for ($day = Carbon::parse($from); $day->toDateString() <= $to; $day->addDay()) {
                $days[$day->toDateString()] = ['views' => 0, 'visitors' => 0, 'returning' => 0];
            }
            $stored = DB::table('visit_stats')->where('dimension', 'total')->whereBetween('day', [$from, $to])->get(['day', 'views', 'visitors', 'returning_visitors']);
            $live = DB::table('visits')->whereBetween('day', [$from, $to])->groupBy('day')->selectRaw('day, '.self::AGGREGATES)->get();
            foreach ([...$stored, ...$live] as $row) {
                $day = substr((string) $row->day, 0, 10);
                if (isset($days[$day])) {
                    $days[$day]['views'] += (int) $row->views;
                    $days[$day]['visitors'] += (int) $row->visitors;
                    $days[$day]['returning'] += (int) $row->returning_visitors;
                }
            }

            return $days;
        });
    }

    /**
     * @return array{views: int, visitors: int, returning: int}
     */
    public function totals(string $from, string $to): array
    {
        $totals = ['views' => 0, 'visitors' => 0, 'returning' => 0];
        foreach ($this->daily($from, $to) as $day) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $day[$key];
            }
        }

        return $totals;
    }

    /**
     * The values of one dimension with the most visitors in the range. Visitors are counted per day.
     *
     * @return list<array{value: string, label: string, views: int, visitors: int}>
     */
    public function top(string $dimension, string $from, string $to, int $limit = 10): array
    {
        abort_unless(in_array($dimension, self::DIMENSIONS, true), 404);

        return $this->remember('top.'.$dimension.'.'.$from.'.'.$to.'.'.$limit, function () use ($dimension, $from, $to, $limit): array {
            $values = [];
            $stored = DB::table('visit_stats')->where('dimension', $dimension)->whereBetween('day', [$from, $to])
                ->groupBy('value')->selectRaw('value, SUM(views) as views, SUM(visitors) as visitors')->get();
            $live = DB::table('visits')->whereBetween('day', [$from, $to])->groupBy($dimension)
                ->selectRaw($this->column($dimension).' as value, COUNT(*) as views, COUNT(DISTINCT visitor) as visitors')->get();
            foreach ([...$stored, ...$live] as $row) {
                $value = (string) $row->value;
                $values[$value] ??= ['value' => $value, 'views' => 0, 'visitors' => 0];
                $values[$value]['views'] += (int) $row->views;
                $values[$value]['visitors'] += (int) $row->visitors;
            }
            usort($values, fn (array $a, array $b): int => [$b['visitors'], $b['views'], $a['value']] <=> [$a['visitors'], $a['views'], $b['value']]);
            $values = array_slice($values, 0, $limit);
            $labels = $this->labels($dimension, array_column($values, 'value'));

            return array_map(fn (array $row): array => ['value' => $row['value'], 'label' => $labels[$row['value']], 'views' => $row['views'], 'visitors' => $row['visitors']], $values);
        });
    }

    /**
     * Readable names: page titles, countries in Spanish, device types and the unknown values.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function labels(string $dimension, array $values): array
    {
        $titles = $dimension === 'path' ? $this->pageTitles($values) : [];
        $labels = [];
        foreach ($values as $value) {
            $labels[$value] = match (true) {
                $dimension === 'path' => $titles[$value] ?? $value,
                $value === '' && $dimension === 'source' => 'Directo o desconocido',
                $value === '' => 'Desconocido',
                $dimension === 'country' => $this->countryName($value),
                $dimension === 'device' => self::DEVICES[$value] ?? $value,
                default => $value,
            };
        }

        return $labels;
    }

    public function countryName(string $code): string
    {
        $name = class_exists(Locale::class) ? Locale::getDisplayRegion('und-'.$code, 'es') : '';

        return $name !== '' && $name !== $code ? $name : $code;
    }

    private function compactDay(string $day): void
    {
        $rows = [];
        $base = fn (): Builder => DB::table('visits')->where('day', $day);
        $total = $base()->selectRaw(self::AGGREGATES)->first();
        $rows[] = ['day' => $day, 'dimension' => 'total', 'value' => '', 'views' => (int) $total->views, 'visitors' => (int) $total->visitors, 'returning_visitors' => (int) $total->returning_visitors];
        foreach (self::DIMENSIONS as $dimension) {
            foreach ($base()->groupBy($dimension)->selectRaw($this->column($dimension).' as value, '.self::AGGREGATES)->get() as $row) {
                $rows[] = ['day' => $day, 'dimension' => $dimension, 'value' => (string) $row->value, 'views' => (int) $row->views, 'visitors' => (int) $row->visitors, 'returning_visitors' => (int) $row->returning_visitors];
            }
        }
        DB::transaction(function () use ($day, $rows): void {
            DB::table('visit_stats')->where('day', $day)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('visit_stats')->insert($chunk);
            }
            DB::table('visits')->where('day', $day)->delete();
        });
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function pageTitles(array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $titles = [];
        $basePath = rtrim((string) parse_url(url('/'), PHP_URL_PATH), '/');
        foreach (Page::query()->where('published', true)->get() as $page) {
            $path = (string) parse_url($page->url(), PHP_URL_PATH);
            $path = $basePath !== '' && str_starts_with($path, $basePath) ? substr($path, strlen($basePath)) : $path;
            $titles['/'.ltrim(rawurldecode($path), '/')] = $page->title;
        }
        $titles['/'] ??= 'Inicio';
        $posts = DB::table('post_paths')->join('posts', 'posts.id', '=', 'post_paths.post_id')
            ->whereIn('post_paths.path', array_map(fn (string $path): string => ltrim($path, '/'), $paths))
            ->pluck('posts.title', 'post_paths.path');
        foreach ($posts as $path => $title) {
            $titles['/'.$path] = $title;
        }

        return $titles;
    }

    private function column(string $dimension): string
    {
        return DB::getQueryGrammar()->wrap($dimension);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function remember(string $key, callable $callback): mixed
    {
        $this->compact();

        return Cache::remember('solqubit.visits.'.config('solqubit.version').'.'.$key, self::CACHE_SECONDS, $callback);
    }
}
