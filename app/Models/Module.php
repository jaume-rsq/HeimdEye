<?php

namespace App\Models;

use App\Enums\ModuleKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Module extends Model
{
    protected $table = 'moduls';

    protected $fillable = [
        'curs_id', 'cicle_id', 'codi', 'nom', 'descripcio', 'tipus',
        'combina', 'credencial_id',
    ];

    protected function casts(): array
    {
        return [
            'tipus' => ModuleKind::class,
            'combina' => 'array',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'curs_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class, 'cicle_id');
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class, 'credencial_id');
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'modul_professor', 'modul_id', 'usuari_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'modul_alumne', 'modul_id', 'alumne_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'modul_id')->orderBy('posicio');
    }

    public function hostTypes(): HasMany
    {
        return $this->hasMany(HostType::class, 'modul_id')->orderBy('posicio')->orderBy('id');
    }

    /** Variables pròpies del mòdul (les globals i les del curs es veuen amb Variable::visibleFrom). */
    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class, 'modul_id');
    }

    public function secrets(): HasMany
    {
        return $this->hasMany(Secret::class, 'modul_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class, 'modul_id')->orderBy('posicio');
    }

    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, Block::class, 'modul_id', 'bloc_id');
    }
}
