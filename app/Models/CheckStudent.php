<?php

namespace App\Models;

use App\Enums\CheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckStudent extends Model
{
    protected $table = 'check_student';

    protected $fillable = [
        'check_id', 'student_id', 'current_status', 'teacher_status', 'teacher_comment',
        'teacher_updated_by', 'first_passed_at', 'last_passed_at', 'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'current_status' => CheckStatus::class,
            'teacher_status' => 'boolean',
            'first_passed_at' => 'datetime',
            'last_passed_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacherUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_updated_by');
    }

    /** L'estat del professor, si n'hi ha, preval sobre el resultat automàtic. */
    public function isPassing(): bool
    {
        return $this->teacher_status ?? $this->current_status === CheckStatus::Pass;
    }
}
