<?php

namespace App\Models;

use App\Enums\HostScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostType extends Model
{
    protected $fillable = [
        'module_id', 'name', 'ansible_group', 'scope', 'host_name', 'address',
        'connection_vars', 'credential_id', 'position',
    ];

    protected function casts(): array
    {
        return [
            'scope' => HostScope::class,
            'connection_vars' => 'array',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function isGlobal(): bool
    {
        return $this->scope === HostScope::Global;
    }
}
