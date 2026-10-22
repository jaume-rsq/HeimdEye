<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionVersion extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['action_id', 'version', 'code', 'config', 'created_by'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
