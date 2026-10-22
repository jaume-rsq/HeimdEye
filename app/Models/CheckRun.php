<?php

namespace App\Models;

use App\Enums\CheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckRun extends Model
{
    protected $table = 'execucions_check';

    public $timestamps = false;

    protected $fillable = [
        'execucio_id', 'check_id', 'versio_check_id', 'alumne_id', 'estat',
        'missatge', 'sortida', 'codi_sortida', 'durada_ms', 'data_execucio',
    ];

    protected function casts(): array
    {
        return [
            'estat' => CheckStatus::class,
            'data_execucio' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RunBatch::class, 'execucio_id');
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function checkVersion(): BelongsTo
    {
        return $this->belongsTo(CheckVersion::class, 'versio_check_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'alumne_id');
    }
}
