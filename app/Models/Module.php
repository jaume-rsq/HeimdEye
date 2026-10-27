<?php

namespace App\Models;

use App\Enums\ModuleKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;

class Module extends Model
{
    protected $table = 'moduls';

    protected $fillable = [
        'curs_id', 'cicle_id', 'codi', 'nom', 'descripcio', 'tipus',
        'combina', 'secret_acces_id',
    ];

    protected function casts(): array
    {
        return [
            'tipus' => ModuleKind::class,
            'combina' => 'array',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'curs_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class, 'cicle_id');
    }

    /** Accés per defecte als equips (un secret d'usuari i contrasenya o de clau SSH). */
    public function access(): BelongsTo
    {
        return $this->belongsTo(Secret::class, 'secret_acces_id');
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'modul_professor', 'modul_id', 'usuari_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'modul_alumne', 'modul_id', 'alumne_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'modul_id')->orderBy('posicio');
    }

    /** Tipus d'equip propis del mòdul (els que veu, amb els globals i els del curs, a visibleHostTypes). */
    public function hostTypes(): HasMany
    {
        return $this->hasMany(HostType::class, 'modul_id')->orderBy('posicio')->orderBy('id');
    }

    /**
     * Tipus d'equip que veu el mòdul: un de mòdul tapa un de curs o global amb el mateix nom.
     *
     * @return Collection<int, HostType>
     */
    public function visibleHostTypes(): Collection
    {
        return HostType::query()->visibleFrom($this)->with(['access'])->get()
            ->sortBy(fn (HostType $type) => $type->nivell->rank())
            ->keyBy('nom')
            ->sortBy([['posicio', 'asc'], ['id', 'asc']])
            ->each(fn (HostType $type) => $type->modul_id === $this->id && $type->setRelation('module', $this))
            ->values();
    }

    /** Variables pròpies del mòdul (les globals i les del curs es veuen amb Variable::visibleFrom). */
    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class, 'modul_id');
    }

    /** Secrets propis del mòdul (els que veu, amb els globals i els del curs, a visibleSecrets). */
    public function secrets(): HasMany
    {
        return $this->hasMany(Secret::class, 'modul_id');
    }

    /**
     * Secrets que veu el mòdul, per clau: un de mòdul tapa un de curs, que tapa un de global.
     *
     * @return Collection<string, Secret>
     */
    public function visibleSecrets(): Collection
    {
        return Secret::query()->visibleFrom($this)->get()
            ->sortBy(fn (Secret $secret) => $secret->nivell->rank())
            ->keyBy('clau');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class, 'modul_id')->orderBy('posicio');
    }

    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, Block::class, 'modul_id', 'bloc_id');
    }
}
