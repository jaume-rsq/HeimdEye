<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Block extends Model
{
    protected $table = 'blocs';

    protected $fillable = ['modul_id', 'numero', 'titol', 'data_inici', 'data_fi', 'posicio'];

    protected function casts(): array
    {
        return [
            'data_inici' => 'date',
            'data_fi' => 'date',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'modul_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'bloc_id')->orderBy('posicio');
    }
}
