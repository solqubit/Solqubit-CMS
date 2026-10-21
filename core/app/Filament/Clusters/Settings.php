<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

/** Every site setting in one place, grouped as Sitio, Publicación and Sistema. Only administrators may enter. */
class Settings extends Cluster
{
    protected static ?string $slug = 'ajustes';

    protected static ?string $navigationLabel = 'Ajustes';

    protected static ?string $clusterBreadcrumb = 'Ajustes';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 0;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
