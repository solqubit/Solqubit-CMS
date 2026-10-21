<?php

namespace App\Services;

use Illuminate\Container\Attributes\Scoped;

/**
 * The public site uses the bundled Solqubit template. Its look is customised from Colores (ColorPalettes),
 * so there is no theme switching: this registry only describes the template and its stylesheets.
 */
#[Scoped]
class ThemeRegistry
{
    private const ID = 'solqubit';

    private const STYLES = ['themes/solqubit/editorial-theme.css', 'themes/solqubit/cms.css'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $themes = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->themes !== null) {
            return $this->themes;
        }
        $themes = [];
        foreach (glob(config('solqubit.themes_path').'/*/theme.json') ?: [] as $file) {
            $theme = json_decode(file_get_contents($file), true);
            $id = basename(dirname($file));
            if (! is_array($theme) || ! preg_match('/^[a-z][a-z0-9-]+$/', $id) || ($theme['id'] ?? '') !== $id) {
                continue;
            }
            $theme['compatible'] = ($theme['cms_major'] ?? null) === 1 && ($theme['content_schema'] ?? null) === config('solqubit.content_schema');
            $themes[$id] = $theme;
        }

        return $this->themes = $themes;
    }

    /**
     * @return array<string, mixed>
     */
    public function active(): array
    {
        return $this->all()[self::ID];
    }

    public function view(string $page): string
    {
        return 'themes.'.self::ID.'.'.$page;
    }

    /**
     * @return list<string>
     */
    public function styles(): array
    {
        return array_map(fn (string $path): string => asset($path).'?v='.config('solqubit.version').'-'.filemtime(public_path($path)), self::STYLES);
    }
}
