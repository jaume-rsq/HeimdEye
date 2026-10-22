<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckVersion extends Model
{
    const UPDATED_AT = null;

    protected $table = 'versions_check';

    protected $fillable = ['check_id', 'versio', 'codi', 'configuracio', 'creat_per'];

    protected function casts(): array
    {
        return [
            'configuracio' => 'array',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creat_per');
    }
}
