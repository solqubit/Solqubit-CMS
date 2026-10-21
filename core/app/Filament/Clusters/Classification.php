<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

/** Categories, tags and series, the three ways of grouping posts. */
class Classification extends Cluster
{
    protected static ?string $slug = 'clasificacion';

    protected static ?string $navigationLabel = 'Clasificación';

    protected static ?string $clusterBreadcrumb = 'Clasificación';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'Contenido';

    protected static ?int $navigationSort = 4;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
