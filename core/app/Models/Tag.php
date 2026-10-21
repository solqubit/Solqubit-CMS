<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

    /** A unique identifier made from the name: "linux", then "linux-2", "linux-3"… */
    public static function uniqueSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'etiqueta';
        $slug = $base;
        for ($number = 2; static::where('slug', $slug)->when($ignore, fn ($query) => $query->whereKeyNot($ignore))->exists(); $number++) {
            $slug = $base.'-'.$number;
        }

        return $slug;
    }

    /**
     * Typed tag names without extra spaces, blanks or repeats (whatever their capitals), in the order given.
     *
     * @param  array<int, mixed>  $names
     * @return array<int, string>
     */
    public static function cleanNames(array $names): array
    {
        $clean = [];
        foreach ($names as $name) {
            $name = Str::limit(Str::squish((string) $name), 255, '');
            if ($name !== '') {
                $clean[mb_strtolower($name)] ??= $name;
            }
        }

        return array_values($clean);
    }

    /** The tag with this name, whatever its capitals. */
    public static function findByName(string $name): ?self
    {
        return static::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }
}
