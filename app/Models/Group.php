<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grup-classe d'un curs (1r SMIX, 2n SMIX...). Els alumnes en són d'un i es matriculen a mòduls. */
class Group extends Model
{
    protected $table = 'grups';

    protected $fillable = ['curs_id', 'cicle_id', 'codi', 'nom'];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'curs_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class, 'cicle_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'grup_id');
    }

    /** «SMIX2 · 2n SMIX», o només el codi. */
    public function label(): string
    {
        return filled($this->nom) ? "{$this->codi} · {$this->nom}" : $this->codi;
    }

    /** Grups amb algun alumne matriculat en algun d'aquests mòduls. */
    public function scopeWithStudentsIn(Builder $query, array $moduleIds): Builder
    {
        return $query->whereHas('students.modules', fn (Builder $q) => $q->whereIn('moduls.id', $moduleIds));
    }
}
