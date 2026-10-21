<?php

namespace App\Filament\Widgets\Visits;

use App\Services\CountryDatabase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class TopCountries extends VisitRanking
{
    protected string $dimension = 'country';

    protected string $heading = 'Países';

    protected string $column = 'País';

    protected function prefix(array $row): ?string
    {
        return $row['value'] !== '' ? $row['value'] : '?';
    }

    protected function note(): string|Htmlable|null
    {
        if (! app(CountryDatabase::class)->installed()) {
            return 'Para saber el país de cada visita, pulsa «Activar países» arriba. Si tu sitio usa Cloudflare, ya se detecta sin hacer nada.';
        }

        return new HtmlString('Países según <a href="'.e(CountryDatabase::ATTRIBUTION_URL).'" target="_blank" rel="noopener">IP Geolocation by DB-IP</a> (licencia CC BY 4.0).');
    }
}
