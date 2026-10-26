<?php

namespace App\Models;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Concerns\HasFixedExecutor;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Check extends Model
{
    use HasFixedExecutor;

    protected $table = 'checks';

    protected $fillable = [
        'tasca_id', 'nom', 'descripcio', 'executor', 'tipus', 'punts',
        'es_actiu', 'actiu_des', 'actiu_fins', 'data_limit', 'bonus_puntualitat_pct', 'posicio',
    ];

    protected function casts(): array
    {
        return [
            'executor' => Executor::class,
            'tipus' => CheckKind::class,
            'es_actiu' => 'boolean',
            'actiu_des' => 'date',
            'actiu_fins' => 'date',
            'data_limit' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'tasca_id');
    }

    /** Mòdul del check, a través de la tasca i el bloc ($check->module). */
    protected function module(): Attribute
    {
        return Attribute::get(fn () => $this->task?->block?->module);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CheckVersion::class);
    }

    /** La versió vigent és la de número més alt. */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(CheckVersion::class)->ofMany('versio', 'max');
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
     * Si es_actiu és true o false, mana. Si és null, el check està actiu
     * dins de l'interval [actiu_des, actiu_fins] (extrems oberts si són buits).
     */
    public function isActiveOn(?CarbonInterface $day = null): bool
    {
        if ($this->es_actiu !== null) {
            return $this->es_actiu;
        }

        $day = ($day ?? Carbon::today())->toDateString();

        return ($this->actiu_des === null || $this->actiu_des->toDateString() <= $day)
            && ($this->actiu_fins === null || $this->actiu_fins->toDateString() >= $day);
    }

    /** Mateixa regla que isActiveOn(), en SQL. */
    public function scopeActiveOn(Builder $query, ?CarbonInterface $day = null): Builder
    {
        $day = ($day ?? Carbon::today())->toDateString();

        return $query->where(fn (Builder $q) => $q
            ->where('es_actiu', true)
            ->orWhere(fn (Builder $q) => $q
                ->whereNull('es_actiu')
                ->where(fn (Builder $q) => $q->whereNull('actiu_des')->orWhereDate('actiu_des', '<=', $day))
                ->where(fn (Builder $q) => $q->whereNull('actiu_fins')->orWhereDate('actiu_fins', '>=', $day))));
    }

    /** Punts que dona el check si es va assolir per primer cop en aquesta data. */
    public function pointsFor(?CarbonInterface $firstPassedAt): float
    {
        if ($firstPassedAt === null) {
            return 0;
        }

        $onTime = $this->data_limit !== null && $firstPassedAt->lessThanOrEqualTo($this->data_limit);

        return $this->punts * (1 + ($onTime ? $this->bonus_puntualitat_pct / 100 : 0));
    }
}
