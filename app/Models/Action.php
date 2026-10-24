<?php

namespace App\Models;

use App\Enums\Executor;
use App\Models\Concerns\HasFixedExecutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Script que el professor executa a mà per canviar els equips dels alumnes. */
class Action extends Model
{
    use HasFixedExecutor;

    protected $table = 'accions';

    protected $fillable = ['modul_id', 'bloc_id', 'nom', 'descripcio', 'executor', 'posicio'];

    protected function casts(): array
    {
        return [
            'executor' => Executor::class,
        ];
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
}
