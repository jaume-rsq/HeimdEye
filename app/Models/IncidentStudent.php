<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una avaria injectada a un alumne: quina opció, l'execució de l'acció (lot + alumne) i com ha
 * acabat. Es resol sola quan el check de l'opció passa després de la injecció.
 */
class IncidentStudent extends Model
{
    protected $table = 'incidencies_alumne';

    protected $fillable = [
        'incidencia_id', 'llancament_id', 'opcio_id', 'nom_opcio', 'alumne_id', 'execucio_id', 'estat',
        'missatge', 'data_injeccio', 'data_resolucio', 'execucio_check_id', 'tancat_per', 'es_revelada',
        'comentari_professor',
    ];

    protected function casts(): array
    {
        return [
            'estat' => IncidentStatus::class,
            'data_injeccio' => 'datetime',
            'data_resolucio' => 'datetime',
            'es_revelada' => 'boolean',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incidencia_id');
    }

    public function launch(): BelongsTo
    {
        return $this->belongsTo(IncidentLaunch::class, 'llancament_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(IncidentOption::class, 'opcio_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'alumne_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RunBatch::class, 'execucio_id');
    }

    public function resolvingRun(): BelongsTo
    {
        return $this->belongsTo(CheckRun::class, 'execucio_check_id');
    }

    /** Les execucions de l'acció d'avaria per a aquest alumne (una per rol amb l'agent). */
    public function actionRuns(): HasMany
    {
        return $this->hasMany(ActionRun::class, 'execucio_id', 'execucio_id')->where('alumne_id', $this->alumne_id);
    }

    /** @param  Builder<IncidentStudent>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('estat', IncidentStatus::activeValues());
    }

    /** Temps entre la injecció i la resolució, en segons. */
    public function resolutionSeconds(): ?int
    {
        return $this->data_injeccio && $this->data_resolucio
            ? (int) $this->data_injeccio->diffInSeconds($this->data_resolucio)
            : null;
    }
}
