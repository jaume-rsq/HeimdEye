<?php

namespace App\Models;

use App\Enums\Level;
use App\Models\Concerns\HasOwnVariables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tipus d'equip (pve, router, zbx...): global, d'un curs o d'un mòdul, com les variables.
 * Si és únic, genera un sol equip per a tothom (el servidor Zabbix); si no, un per alumne.
 */
class HostType extends Model
{
    use HasOwnVariables;

    protected $table = 'tipus_equip';

    protected $fillable = [
        'nivell', 'curs_id', 'modul_id', 'nom', 'grup_ansible', 'es_unic', 'nom_host', 'adreca',
        'variables_connexio', 'secret_acces_id', 'posicio',
    ];

    protected $attributes = [
        'nivell' => 'modul',
        'es_unic' => false,
    ];

    protected function casts(): array
    {
        return [
            'nivell' => Level::class,
            'es_unic' => 'boolean',
        ];
    }

    /**
     * Variables Ansible de l'equip (ansible_connection, ansible_port...): [clau => valor].
     * Poden ser plantilles; es desen com a text.
     */
    protected function variablesConnexio(): Attribute
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
        return Level::HostType;
    }

    protected function ownVariablesKey(): string
    {
        return 'tipus_equip_id';
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'modul_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'curs_id');
    }

    /** Accés a l'equip; si no en té, el del mòdul que l'executa. */
    public function access(): BelongsTo
    {
        return $this->belongsTo(Secret::class, 'secret_acces_id');
    }

    /** Un sol equip per a tothom, no un per alumne. */
    public function isUnique(): bool
    {
        return (bool) $this->es_unic;
    }

    /** Els tipus d'equip que veu un mòdul: els globals, els del seu curs i els seus. */
    public function scopeVisibleFrom(Builder $query, Module $module): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('nivell', Level::Global)
            ->orWhere(fn (Builder $q) => $q->where('nivell', Level::Course)->where('curs_id', $module->curs_id))
            ->orWhere(fn (Builder $q) => $q->where('nivell', Level::Module)->where('modul_id', $module->id)));
    }
}
