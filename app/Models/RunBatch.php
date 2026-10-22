<?php

namespace App\Models;

use App\Enums\RunOrigin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** Recompte per estat; tanca el lot quan ja hi ha tantes execucions com s'esperaven. */
    public function refreshTotals(): void
    {
        $runs = $this->origen === RunOrigin::Action ? $this->actionRuns() : $this->runs();
        $counts = $runs->toBase()->selectRaw('estat, count(*) as n')->groupBy('estat')->pluck('n', 'estat');
        $totals = [...array_intersect_key($this->totals ?? [], array_flip(['expected', 'es_simulacio'])), ...$counts->map(fn ($n) => (int) $n)->all()];

        $this->totals = $totals;
        if ($this->data_fi === null && $counts->sum() >= ($totals['expected'] ?? PHP_INT_MAX)) {
            $this->data_fi = now();
        }
        $this->save();
    }
}
