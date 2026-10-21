<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'tone', 'cover_id'];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_id');
    }
}
