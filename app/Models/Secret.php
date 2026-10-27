<?php

namespace App\Models;

use App\Enums\Level;
use App\Enums\SecretType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Secret global, d'un curs o d'un mòdul, xifrat i de només escriptura: un valor, un usuari i
 * contrasenya o una clau SSH. Els dos darrers serveixen també d'accés als equips.
 * El codi només rep els secrets que esmenta (App\Runner\SecretRefs).
 */
class Secret extends Model
{
    protected $table = 'secrets';

    protected $fillable = ['nivell', 'curs_id', 'modul_id', 'clau', 'tipus', 'nom_usuari', 'valor', 'clau_publica', 'descripcio'];

    protected $hidden = ['valor'];

    protected $attributes = [
        'nivell' => 'modul',
        'tipus' => 'valor',
    ];

    protected function casts(): array
    {
        return [
            'nivell' => Level::class,
            'tipus' => SecretType::class,
            'valor' => 'encrypted',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'modul_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'curs_id');
    }

    /** Mòduls que el tenen d'accés per defecte. */
    public function modules(): HasMany
    {
        return $this->hasMany(Module::class, 'secret_acces_id');
    }

    /** Tipus d'equip que hi entren. */
    public function hostTypes(): HasMany
    {
        return $this->hasMany(HostType::class, 'secret_acces_id');
    }

    /** Els secrets que veu un mòdul: els globals, els del seu curs i els seus. */
    public function scopeVisibleFrom(Builder $query, Module $module): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('nivell', Level::Global)
            ->orWhere(fn (Builder $q) => $q->where('nivell', Level::Course)->where('curs_id', $module->curs_id))
            ->orWhere(fn (Builder $q) => $q->where('nivell', Level::Module)->where('modul_id', $module->id)));
    }

    public function hasValue(): bool
    {
        return filled($this->getAttributes()['valor'] ?? null);
    }

    /** Versions vigents o antigues de checks i accions que l'anomenen a la capçalera "# credencial:". */
    public function headerUses(): int
    {
        $pattern = '%# credencial:%'.$this->clau.'%';

        return CheckVersion::where('codi', 'like', $pattern)->count() + ActionVersion::where('codi', 'like', $pattern)->count();
    }
}
