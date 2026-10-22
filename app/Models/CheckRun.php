<?php

namespace App\Models;

use App\Enums\CheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_batch_id', 'check_id', 'check_version_id', 'student_id', 'status',
        'message', 'output', 'exit_code', 'duration_ms', 'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CheckStatus::class,
            'ran_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RunBatch::class, 'run_batch_id');
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function checkVersion(): BelongsTo
    {
        return $this->belongsTo(CheckVersion::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
