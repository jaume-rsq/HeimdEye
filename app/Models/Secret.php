<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Secret extends Model
{
    protected $fillable = ['module_id', 'key', 'value', 'description'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
