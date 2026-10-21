<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\GitHubReleases;
use App\Services\Requirements;
use Filament\Pages\Page;

class SystemStatus extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server';

    protected static ?string $navigationLabel = 'Actualizaciones';

    protected static ?string $title = 'Actualizaciones';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'actualizaciones';

    protected string $view = 'filament.pages.system-status';

    /** Reads only the cached answer, so drawing the menu never waits for GitHub. */
    public static function getNavigationBadge(): ?string
    {
        $releases = app(GitHubReleases::class);

        return $releases->newer($releases->cached()) ? 'Nueva versión' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    protected function getViewData(): array
    {
        return ['checks' => app(Requirements::class)->check(), 'pendingUpdate' => session('cms_update'), 'lastUpdate' => Setting::read('last_update'), 'uploadLimit' => ini_get('upload_max_filesize'), 'postLimit' => ini_get('post_max_size'), 'release' => app(GitHubReleases::class)->latest()];
    }
}
