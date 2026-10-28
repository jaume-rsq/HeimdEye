<?php

namespace App\Models;

use App\Enums\Level;
use App\Models\Concerns\HasOwnVariables;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasOwnVariables;

    protected $table = 'alumnes';

    protected $fillable = [
        'usuari_id', 'curs_id', 'cicle_id', 'usuari', 'nom_complet',
        'alies', 'num_taula', 'x', 'variables', 'es_actiu',
    ];

    protected function casts(): array
    {
        return [
            'es_actiu' => 'boolean',
        ];
    }

    /** Variables pròpies de l'alumne, les excepcions: [clau => valor]. Tapen totes les altres. */
    protected function variables(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ownVariableValues(),
            set: function (?array $values) {
                $this->setOwnVariableValues($values);

                return [];
            },
        );
    }

    protected function ownVariablesLevel(): Level
    {
        return Level::Student;
    }

    protected function ownVariablesKey(): string
    {
        return 'alumne_id';
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
