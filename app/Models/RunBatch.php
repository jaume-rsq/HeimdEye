<?php

namespace App\Models;

use App\Enums\RunOrigin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RunBatch extends Model
{
    protected $fillable = ['module_id', 'origin', 'triggered_by', 'started_at', 'finished_at', 'totals'];

    protected function casts(): array
    {
        return [
            'origin' => RunOrigin::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'totals' => 'array',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CheckRun::class);
    }

    /** Recompte per estat; tanca el lot quan ja hi ha tantes execucions com s'esperaven. */
    public function refreshTotals(): void
    {
        $counts = $this->runs()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $totals = ['expected' => $this->totals['expected'] ?? null, ...$counts->map(fn ($n) => (int) $n)->all()];

        $this->totals = $totals;
        if ($this->finished_at === null && $counts->sum() >= ($totals['expected'] ?? PHP_INT_MAX)) {
            $this->finished_at = now();
        }
        $this->save();
    }
}
