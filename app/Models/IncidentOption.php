<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una de les avaries possibles d'una incidència: acció, paràmetres i check de resolució. */
class IncidentOption extends Model
{
    protected $table = 'opcions_incidencia';

    protected $fillable = ['incidencia_id', 'nom', 'accio_id', 'parametres', 'check_id', 'accio_reparacio_id', 'pes', 'posicio'];

    protected $attributes = ['pes' => 1];

    protected function casts(): array
    {
        return ['parametres' => 'array'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incidencia_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'accio_id');
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class, 'check_id');
    }

    public function repairAction(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'accio_reparacio_id');
    }

    /** @return array<string, string> */
    public function params(): array
    {
        return array_map('strval', array_filter($this->parametres ?? [], fn ($v) => $v !== null && $v !== ''));
    }
}
