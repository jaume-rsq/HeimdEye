<?php

namespace App\Models;

use App\Enums\VariableScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variable d'inventari: global, d'un curs o d'un mòdul. El valor pot ser una plantilla ({{x}}).
 * Nomenclatura per prefix: xarxa_ (CIDR), ip_ (adreces), nom_ (noms), num_ (números).
 */
class Variable extends Model
{
    protected $table = 'variables';

    protected $fillable = ['abast', 'curs_id', 'modul_id', 'clau', 'valor', 'descripcio'];

    protected function casts(): array
    {
        return [
            'abast' => VariableScope::class,
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'curs_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'modul_id');
    }

    /** Les variables que veu un mòdul: les globals, les del seu curs i les seves. */
    public function scopeVisibleFrom(Builder $query, Module $module): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('abast', VariableScope::Global)
            ->orWhere(fn (Builder $q) => $q->where('abast', VariableScope::Course)->where('curs_id', $module->curs_id))
            ->orWhere(fn (Builder $q) => $q->where('abast', VariableScope::Module)->where('modul_id', $module->id)));
    }
}
