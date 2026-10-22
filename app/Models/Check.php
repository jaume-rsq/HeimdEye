<?php

namespace App\Models;

use App\Enums\CheckKind;
use App\Enums\Executor;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Check extends Model
{
    protected $fillable = [
        'task_id', 'name', 'description', 'executor', 'kind', 'points',
        'active', 'active_from', 'active_to', 'deadline', 'punctuality_bonus_pct',
        'timeout_s', 'credential_id', 'position',
    ];

    protected function casts(): array
    {
        return [
            'executor' => Executor::class,
            'kind' => CheckKind::class,
            'active' => 'boolean',
            'active_from' => 'date',
            'active_to' => 'date',
            'deadline' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CheckVersion::class);
    }

    /** La versió vigent és la de número més alt. */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(CheckVersion::class)->ofMany('version', 'max');
    }

    public function states(): HasMany
    {
        return $this->hasMany(CheckStudent::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CheckRun::class);
    }

    /**
     * Si active és true o false, mana. Si és null, el check està actiu
     * dins de l'interval [active_from, active_to] (extrems oberts si són buits).
     */
    public function isActiveOn(?CarbonInterface $day = null): bool
    {
        if ($this->active !== null) {
            return $this->active;
        }

        $day = ($day ?? Carbon::today())->toDateString();

        return ($this->active_from === null || $this->active_from->toDateString() <= $day)
            && ($this->active_to === null || $this->active_to->toDateString() >= $day);
    }

    /** Mateixa regla que isActiveOn(), en SQL. */
    public function scopeActiveOn(Builder $query, ?CarbonInterface $day = null): Builder
    {
        $day = ($day ?? Carbon::today())->toDateString();

        return $query->where(fn (Builder $q) => $q
            ->where('active', true)
            ->orWhere(fn (Builder $q) => $q
                ->whereNull('active')
                ->where(fn (Builder $q) => $q->whereNull('active_from')->orWhereDate('active_from', '<=', $day))
                ->where(fn (Builder $q) => $q->whereNull('active_to')->orWhereDate('active_to', '>=', $day))));
    }

    /** Punts que dona el check si es va assolir per primer cop en aquesta data. */
    public function pointsFor(?CarbonInterface $firstPassedAt): float
    {
        if ($firstPassedAt === null) {
            return 0;
        }

        $onTime = $this->deadline !== null && $firstPassedAt->lessThanOrEqualTo($this->deadline);

        return $this->points * (1 + ($onTime ? $this->punctuality_bonus_pct / 100 : 0));
    }
}
