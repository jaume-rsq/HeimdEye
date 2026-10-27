<?php

namespace App\Models;

use App\Enums\HostScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostType extends Model
{
    protected $table = 'tipus_equip';

    protected $fillable = [
        'modul_id', 'nom', 'grup_ansible', 'abast', 'nom_host', 'adreca',
        'variables_connexio', 'secret_acces_id', 'posicio',
    ];

    protected function casts(): array
    {
        return [
            'abast' => HostScope::class,
            'variables_connexio' => 'array',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'modul_id');
    }

    /** Accés a l'equip; si no en té, el del mòdul. */
    public function access(): BelongsTo
    {
        return $this->belongsTo(Secret::class, 'secret_acces_id');
    }

    public function isGlobal(): bool
    {
        return $this->abast === HostScope::Global;
    }
}
