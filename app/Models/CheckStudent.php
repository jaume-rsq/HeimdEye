<?php

namespace App\Models;

use App\Enums\CheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckStudent extends Model
{
    protected $table = 'check_alumne';

    protected $fillable = [
        'check_id', 'alumne_id', 'estat_actual', 'estat_professor', 'comentari_professor',
        'modificat_per', 'data_correccio', 'data_primer_assoliment', 'data_ultim_assoliment', 'data_darrera_execucio',
    ];

    protected function casts(): array
    {
        return [
            'estat_actual' => CheckStatus::class,
            'estat_professor' => 'boolean',
            'data_correccio' => 'datetime',
            'data_primer_assoliment' => 'datetime',
            'data_ultim_assoliment' => 'datetime',
            'data_darrera_execucio' => 'datetime',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'alumne_id');
    }

    public function teacherUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modificat_per');
    }

    /** L'estat del professor, si n'hi ha, preval sobre el resultat automàtic. */
    public function isPassing(): bool
    {
        return $this->estat_professor ?? $this->estat_actual === CheckStatus::Pass;
    }
}
