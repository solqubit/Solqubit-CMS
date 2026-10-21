<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'subject', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public static function record(string $action, ?string $subject = null, array $metadata = []): void
    {
        static::create(['user_id' => auth()->id(), 'action' => $action, 'subject' => $subject, 'metadata' => $metadata]);
    }
}
