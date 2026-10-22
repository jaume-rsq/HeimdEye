<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionVersion extends Model
{
    const UPDATED_AT = null;

    protected $table = 'versions_accio';

    protected $fillable = ['accio_id', 'versio', 'codi', 'configuracio', 'creat_per'];

    protected function casts(): array
    {
        return [
            'configuracio' => 'array',
        ];
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'accio_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creat_per');
    }
}
