<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PostPermalinks
{
    public const RESERVED = ['admin', 'install', 'preview', 'media', 'pagina', 'series', 'acerca', 'up', 'api', 'livewire', 'storage', 'themes', 'editor', 'css', 'js', 'fonts', 'build', 'vendor', 'solqubit-core'];

    public static function reserved(): array
    {
        return array_unique([...self::RESERVED, AdminAccess::path()]);
    }

    private function settings(): array
    {
        return request()->attributes->get('solqubit.permalinks') ?? tap(Setting::read('permalinks', ['default_prefix' => '', 'prefixes' => []]), function (array $settings): void {
            request()->attributes->set('solqubit.permalinks', $settings);
        });
    }

    public function defaultPrefix(): string
    {
        return $this->settings()['default_prefix'] ?? '';
    }

    public function configured(): array
    {
        return $this->settings()['prefixes'] ?? [];
    }

    public function options(?Post $post = null): array
    {
        $options = ['' => 'Sin prefijo'];
        foreach ($this->configured() as $prefix) {
            $options[$prefix['slug']] = $prefix['label'].' · /'.$prefix['slug'].'/';
        }
        if ($post && ! array_key_exists($post->url_prefix, $options)) {
            $options[$post->url_prefix] = '/'.$post->url_prefix.'/ · Actual, retirado de la lista';
        }

        return $options;
    }

    public function path(string $slug, string $prefix): string
    {
        return ($prefix !== '' ? $prefix.'/' : '').$slug;
    }

    public function url(string $slug, ?string $prefix = null): string
    {
        return route('posts.show', ['path' => $this->path($slug, $prefix ?? $this->defaultPrefix())]);
    }

    public function validatePost(Post $post): void
    {
        Validator::make($post->only('slug', 'url_prefix'), [
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'url_prefix' => ['present', 'string', 'max:60', 'regex:/^(?:[a-z0-9]+(?:-[a-z0-9]+)*)?$/', Rule::notIn(self::reserved())],
        ])->validate();
        if ($post->url_prefix === '' && in_array($post->slug, self::reserved(), true)) {
            throw ValidationException::withMessages(['slug' => 'Esta dirección está reservada para una sección del sitio. Elige otro nombre.']);
        }
        if ($post->url_prefix === '' && (! $post->exists || $post->isDirty(['slug', 'url_prefix'])) && Page::withTrashed()->where('kind', 'custom')->where('slug', $post->slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'Esta dirección pertenece a una página. Elige otro nombre o un prefijo para la entrada.']);
        }
        if (! array_key_exists($post->url_prefix, $this->options()) && (! $post->exists || $post->url_prefix !== $post->getRawOriginal('url_prefix'))) {
            throw ValidationException::withMessages(['url_prefix' => 'Elige Sin prefijo o uno de los prefijos configurados en Ajustes · Direcciones.']);
        }
        if (DB::table('post_paths')->where('path', $this->path($post->slug, $post->url_prefix))->when($post->exists, fn ($query) => $query->where('post_id', '!=', $post->id))->exists()) {
            throw ValidationException::withMessages(['slug' => 'Esta dirección pertenece a otra entrada o a uno de sus enlaces anteriores. Elige otro nombre.']);
        }
    }

    public function remember(Post $post): void
    {
        $path = $this->path($post->slug, $post->url_prefix);
        DB::table('post_paths')->insertOrIgnore(['path' => $path, 'post_id' => $post->id]);
        if ((int) DB::table('post_paths')->where('path', $path)->value('post_id') !== $post->id) {
            throw ValidationException::withMessages(['slug' => 'Esta dirección ya pertenece a otra entrada. Elige otro nombre.']);
        }
    }

    public function configure(array $prefixes, string $defaultPrefix): void
    {
        Validator::make(['prefixes' => $prefixes, 'default_prefix' => $defaultPrefix], [
            'prefixes' => ['array', 'max:50'],
            'prefixes.*.label' => ['required', 'string', 'max:60'],
            'prefixes.*.slug' => ['required', 'string', 'max:60', 'distinct', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(self::reserved())],
            'default_prefix' => ['present', 'string', Rule::in(['', ...array_column($prefixes, 'slug')])],
        ], [
            'prefixes.*.slug.regex' => 'Usa minúsculas, números o guiones, sin barras.',
            'prefixes.*.slug.not_in' => 'Esta dirección está reservada para otra sección del sitio.',
            'prefixes.*.slug.distinct' => 'Este prefijo ya está en la lista.',
            'default_prefix.in' => 'Elige un prefijo de la lista o Sin prefijo como valor predeterminado.',
        ])->validate();
        Setting::write('permalinks', ['default_prefix' => $defaultPrefix, 'prefixes' => array_values(array_map(fn (array $prefix): array => array_intersect_key($prefix, array_flip(['label', 'slug'])), $prefixes))]);
        request()->attributes->remove('solqubit.permalinks');
    }

    public static function routePattern(bool $allowPrefix = true): string
    {
        $reserved = implode('|', array_map(fn (string $path): string => preg_quote($path, '~'), self::reserved()));

        return '(?!(?:'.$reserved.')(?:/|$))'.($allowPrefix ? '(?:[a-z0-9]+(?:-[a-z0-9]+)*/)?' : '').'[a-z0-9]+(?:-[a-z0-9]+)*';
    }
}
