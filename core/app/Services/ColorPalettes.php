<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ColorPalettes
{
    public function defaults(): array
    {
        return [
            'light' => ['heading-primary' => '#292a3e', 'section-heading' => '#72549e', 'accent' => '#6652b3', 'accent-soft' => '#efebfa', 'ink' => '#2b2542', 'muted' => '#69617d', 'button-bg' => '#6754b6', 'button-ink' => '#ffffff', 'button-shadow' => '#4b397c', 'bg' => '#f7f7fb', 'surface' => '#fffdfc', 'line' => '#d9d0e8', 'info' => '#315f91', 'note' => '#72549e', 'warm' => '#6652b3'],
            'dark' => ['heading-primary' => '#d2c4ee', 'section-heading' => '#c5b2ee', 'accent' => '#c5b2ee', 'accent-soft' => '#302b43', 'ink' => '#e5e9f0', 'muted' => '#b4bed0', 'button-bg' => '#e8b89d', 'button-ink' => '#2b2022', 'button-shadow' => '#624c46', 'bg' => '#15171e', 'surface' => '#222937', 'line' => '#424f66', 'info' => '#a3c7ed', 'note' => '#e2adcc', 'warm' => '#e8b89d'],
        ];
    }

    public function all(): array
    {
        $variants = [
            'default' => ['name' => 'Lavanda', 'description' => 'La combinación original, suave y creativa.', 'colors' => []],
            'blue' => ['name' => 'Azul', 'description' => 'Azules serenos con un aire tecnológico.', 'colors' => [
                'light' => ['heading-primary' => '#252c36', 'section-heading' => '#3663a2', 'accent' => '#3662a1', 'accent-soft' => '#e7eef7', 'button-bg' => '#3764a4', 'button-shadow' => '#264571'],
                'dark' => ['heading-primary' => '#b9cce8', 'section-heading' => '#a4bde1', 'accent' => '#a4bde1', 'accent-soft' => '#272f3a', 'button-bg' => '#adc4e4', 'button-shadow' => '#2c5185'],
            ]],
            'rose' => ['name' => 'Rosa', 'description' => 'Rosa empolvado con un toque editorial.', 'colors' => [
                'light' => ['heading-primary' => '#39262e', 'section-heading' => '#a1406a', 'accent' => '#a04069', 'accent-soft' => '#f7eaef', 'button-bg' => '#a3416b', 'button-shadow' => '#712d4a'],
                'dark' => ['heading-primary' => '#e6c1d1', 'section-heading' => '#dfaec3', 'accent' => '#dfaec3', 'accent-soft' => '#3d2931', 'button-bg' => '#e2b6c9', 'button-shadow' => '#853558'],
            ]],
            'cyan' => ['name' => 'Cian', 'description' => 'Un azul fresco con matices de hielo.', 'colors' => [
                'light' => ['heading-primary' => '#212e30', 'section-heading' => '#2a6b78', 'accent' => '#2a6a77', 'accent-soft' => '#dff0f4', 'button-bg' => '#2b6c79', 'button-shadow' => '#1d4b54'],
                'dark' => ['heading-primary' => '#a0d3dd', 'section-heading' => '#83c6d3', 'accent' => '#83c6d3', 'accent-soft' => '#223134', 'button-bg' => '#8fccd8', 'button-shadow' => '#235863'],
            ]],
            'coral' => ['name' => 'Coral', 'description' => 'Un coral suave, cálido y expresivo.', 'colors' => [
                'light' => ['heading-primary' => '#362825', 'section-heading' => '#9c4b37', 'accent' => '#9a4a36', 'accent-soft' => '#f7ebe7', 'button-bg' => '#9d4c37', 'button-shadow' => '#6d3426'],
                'dark' => ['heading-primary' => '#e7c3ba', 'section-heading' => '#dfb1a5', 'accent' => '#dfb1a5', 'accent-soft' => '#3a2b27', 'button-bg' => '#e3b9ae', 'button-shadow' => '#803e2d'],
            ]],
            'plum' => ['name' => 'Ciruela', 'description' => 'Morados suaves con más profundidad.', 'colors' => [
                'light' => ['heading-primary' => '#342739', 'section-heading' => '#824f93', 'accent' => '#804e91', 'accent-soft' => '#f2ebf4', 'button-bg' => '#835094', 'button-shadow' => '#5a3766'],
                'dark' => ['heading-primary' => '#d8c4df', 'section-heading' => '#cdb3d6', 'accent' => '#cdb3d6', 'accent-soft' => '#38293d', 'button-bg' => '#d2bada', 'button-shadow' => '#6a4178'],
            ]],
            'graphite' => ['name' => 'Grafito', 'description' => 'Acentos discretos en gris azulado.', 'colors' => [
                'light' => ['heading-primary' => '#292c30', 'section-heading' => '#5e636e', 'accent' => '#5d626d', 'accent-soft' => '#ecedef', 'button-bg' => '#5e646f', 'button-shadow' => '#41454c'],
                'dark' => ['heading-primary' => '#c8cbd0', 'section-heading' => '#b8bcc3', 'accent' => '#b8bcc3', 'accent-soft' => '#2c2e33', 'button-bg' => '#bfc2c8', 'button-shadow' => '#4d515a'],
            ]],
            'amber' => ['name' => 'Ámbar', 'description' => 'Tonos cálidos, entre miel y arena.', 'colors' => [
                'light' => ['heading-primary' => '#312b22', 'section-heading' => '#825b29', 'accent' => '#805a28', 'accent-soft' => '#f5ece0', 'button-bg' => '#835c29', 'button-shadow' => '#5a3f1c'],
                'dark' => ['heading-primary' => '#e2c6a3', 'section-heading' => '#d9b687', 'accent' => '#d9b687', 'accent-soft' => '#352d23', 'button-bg' => '#ddbd93', 'button-shadow' => '#6a4b22'],
            ]],
        ];

        foreach ($variants as &$variant) {
            $variant['colors'] = array_replace_recursive($this->defaults(), $variant['colors']);
        }

        return $variants;
    }

    public function activeId(): string
    {
        $id = Setting::read('appearance', [])['variant'] ?? 'default';

        return is_string($id) && array_key_exists($id, $this->all()) ? $id : 'default';
    }

    public function activate(string $id): void
    {
        Validator::make(['variant' => $id], ['variant' => ['required', Rule::in(array_keys($this->all()))]], [
            'variant.in' => 'Elige una de las variantes disponibles.',
            'variant.required' => 'Selecciona una variante de color.',
        ])->validate();

        Setting::write('appearance', ['variant' => $id]);
    }

    public function contrast(string $first, string $second): float
    {
        $luminance = function (string $color): float {
            $rgb = array_map(function (string $part): float {
                $value = hexdec($part) / 255;

                return $value <= .04045 ? $value / 12.92 : (($value + .055) / 1.055) ** 2.4;
            }, str_split(substr($color, 1), 2));

            return $rgb[0] * .2126 + $rgb[1] * .7152 + $rgb[2] * .0722;
        };
        $a = $luminance($first);
        $b = $luminance($second);

        return (max($a, $b) + .05) / (min($a, $b) + .05);
    }

    public function css(): string
    {
        $variant = $this->all()[$this->activeId()]['colors'];
        $css = '';
        foreach ($this->defaults() as $mode => $defaults) {
            $tokens = array_diff_assoc($variant[$mode], $defaults);
            if (! $tokens) {
                continue;
            }
            $selector = 'html[data-theme='.$mode.']';
            $css .= $selector.'{';
            foreach ($tokens as $key => $value) {
                $css .= '--'.$key.':'.$value.';';
            }
            $css .= '}';
            $css .= $selector.' .journal-intro h1 em{color:var(--button-bg)}';
            $css .= $selector.' :is(.article-related h2,.about-copy h2){color:var(--section-heading)}';
        }

        return $css;
    }
}
