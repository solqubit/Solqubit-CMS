<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SystemStatus;
use App\Services\GitHubReleases;
use Filament\Widgets\Widget;

/** Tells administrators that a newer release is published on GitHub. Lazy, so the dashboard never waits for GitHub. */
class UpdateAvailable extends Widget
{
    protected string $view = 'filament.widgets.update-available';

    protected static ?int $sort = -20;

    protected static bool $isLazy = true;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    protected function getViewData(): array
    {
        $releases = app(GitHubReleases::class);
        $release = $releases->latest();

        return ['release' => $releases->newer($release) ? $release : null, 'statusUrl' => SystemStatus::getUrl()];
    }
}
