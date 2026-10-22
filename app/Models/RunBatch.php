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
}
