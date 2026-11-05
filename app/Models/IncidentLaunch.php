<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cada vegada que es llança una incidència: qui, com s'han triat les opcions i fins quan. */
class IncidentLaunch extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'llancaments_incidencia';

    protected $fillable = ['incidencia_id', 'llancat_per', 'mode', 'quantitat', 'data_limit'];

    protected function casts(): array
    {
        return ['data_limit' => 'datetime'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incidencia_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'llancat_per');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(IncidentStudent::class, 'llancament_id');
    }
}
