<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $table = 'alumnes';

    protected $fillable = [
        'usuari_id', 'curs_id', 'cicle_id', 'usuari', 'nom_complet',
        'alies', 'num_taula', 'x', 'variables', 'es_actiu',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'es_actiu' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuari_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'curs_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class, 'cicle_id');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'modul_alumne', 'alumne_id', 'modul_id');
    }

    public function checkStates(): HasMany
    {
        return $this->hasMany(CheckStudent::class, 'alumne_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CheckRun::class, 'alumne_id');
    }

    /** Nom que surt al leaderboard públic: l'àlies, o la taula si no en té. */
    public function publicName(): string
    {
        return $this->alies ?? sprintf('Taula %02d', $this->num_taula);
    }
}
