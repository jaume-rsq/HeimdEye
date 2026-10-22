<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Secret extends Model
{
    protected $table = 'secrets';

    protected $fillable = ['modul_id', 'clau', 'valor', 'descripcio'];

    protected $hidden = ['valor'];

    protected function casts(): array
    {
        return [
            'valor' => 'encrypted',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'modul_id');
    }
}
