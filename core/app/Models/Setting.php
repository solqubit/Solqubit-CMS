<?php

namespace App\Models;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => static::forgetCached($setting));
        static::deleted(fn (Setting $setting) => static::forgetCached($setting));
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        return static::cachedValues()[$key] ?? $default;
    }

    public static function write(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Every setting keyed by name. Loaded once into the cache and memoised for the rest of the request.
     *
     * @return array<string, mixed>
     */
    public static function cachedValues(): array
    {
        return static::cache()->rememberForever(static::cacheKey(), fn (): array => static::query()->pluck('value', 'key')->all());
    }

    /**
     * Drop the cached map now, after the surrounding transaction commits (other requests may have
     * reloaded the old values meanwhile) and if it rolls back (this request may have cached uncommitted values).
     */
    public static function forgetCached(?Setting $setting = null): void
    {
        $forget = fn () => static::cache()->forget(static::cacheKey());
        $forget();
        $connection = $setting?->getConnection() ?? (new static)->getConnection();
        $connection->afterCommit($forget);
        $connection->afterRollBack($forget);
    }

    /**
     * The key includes the CMS version so values written directly by an update's migrations are never served stale.
     */
    private static function cacheKey(): string
    {
        return 'solqubit.settings.'.config('solqubit.version');
    }

    private static function cache(): Repository
    {
        return Cache::memo();
    }
}
