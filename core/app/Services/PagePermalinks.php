<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PagePermalinks
{
    public function unavailableReason(string $slug, ?Page $page = null): ?string
    {
        if (in_array($slug, PostPermalinks::reserved(), true) || is_dir(public_path($slug)) || is_file(public_path($slug))) {
            return 'Esta dirección está reservada para una sección del sitio. Elige otro nombre.';
        }
        if (DB::table('post_paths')->where('path', $slug)->exists()) {
            return 'Esta dirección pertenece a una entrada o a uno de sus enlaces anteriores. Elige otro nombre para la página.';
        }
        if (Page::withTrashed()->where('kind', '!=', 'custom')->where('slug', $slug)->exists()) {
            return 'Esta dirección está reservada para una página del sistema. Elige otro nombre.';
        }
        if (DB::table('page_paths')->where('path', $slug)->when($page?->exists, fn ($query) => $query->where('page_id', '!=', $page->id))->exists()) {
            return 'Esta dirección redirige a otra página que la usaba antes. Elige otro nombre.';
        }

        return null;
    }

    /** Why another additional page, published, draft or in the trash, already owns this slug. */
    public function takenReason(string $slug, ?Page $page = null): ?string
    {
        $owner = Page::withTrashed()->where('kind', 'custom')->where('slug', $slug)->when($page?->exists, fn ($query) => $query->whereKeyNot($page->id))->first();
        if (! $owner) {
            return null;
        }

        return $owner->trashed()
            ? 'La página «'.$owner->title.'», que está en la papelera, conserva esta dirección. Elimínala definitivamente o elige otro nombre.'
            : 'La página «'.$owner->title.'» ya usa esta dirección. Elige otro nombre.';
    }

    public function validate(Page $page): void
    {
        if ($page->kind !== 'custom' || ($page->exists && ! $page->isDirty('slug'))) {
            return;
        }
        if ($reason = $this->unavailableReason($page->slug, $page) ?? $this->takenReason($page->slug, $page)) {
            throw ValidationException::withMessages(['slug' => $reason]);
        }
    }

    /** Keeps the previous address of a renamed page so old links redirect to the new one. */
    public function rememberPrevious(Page $page): void
    {
        $previous = $page->getOriginal('slug');
        if ($page->kind !== 'custom' || ! $page->wasChanged('slug') || ! is_string($previous) || $previous === '') {
            return;
        }
        DB::table('page_paths')->where('path', $page->slug)->delete();
        DB::table('page_paths')->upsert([['path' => $previous, 'page_id' => $page->id]], ['path'], ['page_id']);
    }

    public function url(string $slug): string
    {
        return route($this->unavailableReason($slug) ? 'pages.legacy' : 'pages.show', $slug);
    }
}
