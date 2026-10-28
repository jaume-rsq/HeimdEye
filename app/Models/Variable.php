<?php

namespace App\Models;

use App\Enums\Level;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variable d'inventari: global, d'un curs o d'un mòdul. El valor pot ser una plantilla ({{x}}).
 * Nomenclatura per prefix: xarxa_ (CIDR), ip_ (adreces), nom_ (noms), num_ (números).
 */
class Variable extends Model
{
    /** Nom de variable (i de secret o grup Ansible): minúscules, números i _ (xarxa_lan, ip_pve...). */
    public const KEY_RULE = '/^[a-z][a-z0-9_]*$/';

    protected $table = 'variables';

    protected $fillable = ['nivell', 'curs_id', 'modul_id', 'clau', 'valor', 'descripcio'];

    protected function casts(): array
    {
        return [
            'nivell' => Level::class,
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
            ->where('nivell', Level::Global)
            ->orWhere(fn (Builder $q) => $q->where('nivell', Level::Course)->where('curs_id', $module->curs_id))
            ->orWhere(fn (Builder $q) => $q->where('nivell', Level::Module)->where('modul_id', $module->id)));
    }
}
