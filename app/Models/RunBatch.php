<?php

namespace App\Models;

use App\Enums\RunOrigin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class RunBatch extends Model
{
    protected $table = 'execucions';

    protected $fillable = ['modul_id', 'origen', 'llancat_per', 'data_inici', 'data_fi', 'totals'];

    protected function casts(): array
    {
        return [
            'origen' => RunOrigin::class,
            'data_inici' => 'datetime',
            'data_fi' => 'datetime',
            'totals' => 'array',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'modul_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'llancat_per');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CheckRun::class, 'execucio_id');
    }

    public function actionRuns(): HasMany
    {
        return $this->hasMany(ActionRun::class, 'execucio_id');
    }

    /**
     * Recompte per estat; tanca el lot quan ja hi ha tantes execucions com s'esperaven.
     * Amb la fila bloquejada, perquè els treballs de la cua acaben alhora.
     */
    public function refreshTotals(): void
    {
        DB::transaction(function () {
            $batch = static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $runs = $batch->origen === RunOrigin::Action ? $batch->actionRuns() : $batch->runs();
            $counts = $runs->toBase()->selectRaw('estat, count(*) as n')->groupBy('estat')->pluck('n', 'estat');
            $totals = [...array_intersect_key($batch->totals ?? [], array_flip(['expected', 'es_simulacio'])), ...$counts->map(fn ($n) => (int) $n)->all()];

            $batch->totals = $totals;
            if ($batch->data_fi === null && $counts->sum() >= ($totals['expected'] ?? PHP_INT_MAX)) {
                $batch->data_fi = now();
            }
            $batch->save();
            $this->setRawAttributes($batch->getAttributes(), true);
        });
    }
}
