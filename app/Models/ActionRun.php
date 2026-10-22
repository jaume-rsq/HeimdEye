<?php

namespace App\Models;

use App\Enums\ActionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_batch_id', 'action_id', 'action_version_id', 'student_id', 'triggered_by',
        'check_mode', 'status', 'ok', 'changed', 'failed', 'params', 'output',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ActionStatus::class,
            'check_mode' => 'boolean',
            'params' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RunBatch::class, 'run_batch_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
