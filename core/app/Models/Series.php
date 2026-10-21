<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Series extends Model
{
    protected $table = 'series';

    protected $fillable = ['name', 'slug', 'description', 'tone', 'cover_id'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class)->orderBy('series_order')->orderBy('id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_id');
    }
}
