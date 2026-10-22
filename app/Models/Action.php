<?php

namespace App\Models;

use App\Enums\Executor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Script que el professor executa a mà per canviar els equips dels alumnes. */
class Action extends Model
{
    protected $fillable = [
        'module_id', 'block_id', 'name', 'description', 'executor', 'params',
        'verify_check_ids', 'timeout_s', 'position',
    ];

    protected function casts(): array
    {
        return [
            'executor' => Executor::class,
            'params' => 'array',
            'verify_check_ids' => 'array',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ActionVersion::class);
    }

    /** La versió vigent és la de número més alt. */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(ActionVersion::class)->ofMany('version', 'max');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ActionRun::class);
    }
}
