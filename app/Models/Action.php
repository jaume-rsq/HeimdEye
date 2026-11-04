<?php

namespace App\Models;

use App\Enums\Executor;
use App\Models\Concerns\HasFixedExecutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Script que el professor executa a mà per canviar els equips dels alumnes. */
class Action extends Model
{
    use HasFixedExecutor;

    protected $table = 'accions';

    protected $fillable = ['modul_id', 'bloc_id', 'nom', 'descripcio', 'executor', 'posicio', 'caducitat_agent_seg'];

    protected function casts(): array
    {
        return [
            'executor' => Executor::class,
        ];
    }

    /** Segons que la feina d'agent espera que la reculli abans de caducar (config per defecte si null). */
    public function agentExpirySeconds(): int
    {
        return $this->caducitat_agent_seg ?? (int) config('heimdeye.agent.expiry_seconds', 900);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'modul_id');
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'bloc_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ActionVersion::class, 'accio_id');
    }

    /** La versió vigent és la de número més alt. */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(ActionVersion::class, 'accio_id')->ofMany('versio', 'max');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ActionRun::class, 'accio_id');
    }

    /**
     * Posició dins del mòdul, com als checks: número del bloc i posició de l'acció (2.3). Sense
     * bloc, només la posició. És l'ordre en què el professor les llança. Cal tenir carregat block.
     */
    public function positionLabel(): string
    {
        return $this->block ? "{$this->block->numero}.{$this->posicio}" : "–.{$this->posicio}";
    }

    /** Ordena com positionLabel(): mòdul, bloc (les que no en tenen, al final) i acció. */
    public function scopeOrderByPosition(Builder $query, string $direction = 'asc'): Builder
    {
        return $query
            ->orderByBlock($direction)
            ->orderBy('accions.posicio', $direction)
            ->orderBy('accions.id', $direction);
    }

    /** Només per bloc (mòdul i bloc): l'ordre dels grups de la llista d'accions. */
    public function scopeOrderByBlock(Builder $query, string $direction = 'asc'): Builder
    {
        return $query
            ->orderBy('accions.modul_id', $direction)
            ->orderByRaw('case when accions.bloc_id is null then 1 else 0 end '.($direction === 'desc' ? 'desc' : 'asc'))
            ->orderBy(self::blockColumn('blocs.posicio'), $direction)
            ->orderBy(self::blockColumn('blocs.numero'), $direction)
            ->orderBy('accions.bloc_id', $direction);
    }

    /** Subconsulta amb una columna del bloc de l'acció. */
    private static function blockColumn(string $column): Builder
    {
        return Block::query()->whereColumn('blocs.id', 'accions.bloc_id')->select($column)->limit(1);
    }
}
