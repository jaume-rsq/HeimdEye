<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckVersion extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['check_id', 'version', 'code', 'config', 'created_by'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
