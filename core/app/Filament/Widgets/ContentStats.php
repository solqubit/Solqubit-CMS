<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Media;
use App\Models\Post;
use App\Services\MediaAccess;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/** Post and file counts. Editors see their own; every figure opens its filtered list. */
class ContentStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $user = auth()->user();
        $posts = fn (): Builder => Post::query()->when(! $user?->isAdmin(), fn (Builder $query) => $query->where('user_id', $user?->getKey()));
        $list = fn (array $filters): string => PostResource::getUrl('index', ['tableFilters' => $user?->isAdmin() ? $filters : ['mine' => ['isActive' => 1], ...$filters]]);

        return [
            Stat::make('Entradas publicadas', $posts()->published()->count())->url($list(['published' => ['isActive' => 1]])),
            Stat::make('Borradores', $posts()->where('status', 'draft')->count())->url($list(['status' => ['value' => 'draft']])),
            Stat::make('Programadas', $posts()->where('status', 'published')->where('published_at', '>', now())->count())->url($list(['scheduled' => ['isActive' => 1]])),
            Stat::make('Archivadas', $posts()->where('status', 'archived')->count())->url($list(['status' => ['value' => 'archived']])),
            Stat::make('Archivos', $user?->isAdmin() ? Media::count() : app(MediaAccess::class)->visibleTo(Media::query(), $user)->where('user_id', $user?->getKey())->count())->url(MediaResource::getUrl()),
        ];
    }
}
