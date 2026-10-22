<?php

namespace App\Models;

use App\Enums\HostScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostType extends Model
{
    protected $table = 'tipus_equip';

    protected $fillable = [
        'modul_id', 'nom', 'grup_ansible', 'abast', 'nom_host', 'adreca',
        'variables_connexio', 'credencial_id', 'posicio',
    ];

    protected function casts(): array
    {
        return [
            'abast' => HostScope::class,
            'variables_connexio' => 'array',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'modul_id');
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class, 'credencial_id');
    }

    public function isGlobal(): bool
    {
        return $this->abast === HostScope::Global;
    }
}
