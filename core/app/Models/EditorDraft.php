<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EditorDraft extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'version' => 'integer'];
    }

    public static function clearFor(string $context, string|int $key): void
    {
        static::where('user_id', auth()->id())->where('context', $context)->where('record_key', (string) $key)->delete();
    }
}
