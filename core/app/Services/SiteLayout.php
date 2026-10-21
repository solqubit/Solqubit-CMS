<?php

namespace App\Services;

use App\Models\Setting;

class SiteLayout
{
    public function __construct(private ThemeRegistry $themes) {}

    /**
     * Data shared by every public page: identity, theme styles, menus and advertising.
     *
     * @return array{site: array<string, mixed>, theme: array<string, mixed>, styles: list<string>, navigation: array<int, mixed>, footerNavigation: array<int, mixed>, monetization: ?array<string, mixed>}
     */
    public function data(bool $advertisingEligible = false, bool $preview = false): array
    {
        $menus = app(SiteNavigation::class)->menus(['header', 'footer']);

        return [
            'site' => Setting::read('site', ['name' => 'Solqubit', 'description' => 'Ideas, historias y conocimiento para compartir.']),
            'theme' => $this->themes->active(), 'styles' => $this->themes->styles(),
            'navigation' => $menus['header'],
            'footerNavigation' => $menus['footer'],
            'monetization' => app(Monetization::class)->forPage($advertisingEligible, $preview),
        ];
    }
}
