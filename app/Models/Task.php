<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $table = 'tasques';

    protected $fillable = ['bloc_id', 'titol', 'enunciat', 'posicio'];

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'bloc_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class, 'tasca_id')->orderBy('posicio');
    }
}
