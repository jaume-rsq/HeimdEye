<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $fillable = ['block_id', 'title', 'statement', 'position'];

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class)->orderBy('position');
    }
}
