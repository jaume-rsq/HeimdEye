<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Avaria intencionada que el professor llança sobre els equips dels alumnes perquè la resolguin.
 * Cada opció és una acció (amb paràmetres) i el check que diu si ja està arreglada.
 */
class Incident extends Model
{
    protected $table = 'incidencies';

    protected $fillable = ['modul_id', 'bloc_id', 'tasca_id', 'nom', 'equip', 'simptoma', 'notes', 'posicio'];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'modul_id');
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'bloc_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'tasca_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(IncidentOption::class, 'incidencia_id')->orderBy('posicio')->orderBy('id');
    }

    public function launches(): HasMany
    {
        return $this->hasMany(IncidentLaunch::class, 'incidencia_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(IncidentStudent::class, 'incidencia_id');
    }
}
