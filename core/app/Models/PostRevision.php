<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['post_id', 'user_id', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
