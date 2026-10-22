<?php

namespace App\Models;

use App\Enums\ActionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionRun extends Model
{
    protected $table = 'execucions_accio';

    public $timestamps = false;

    protected $fillable = [
        'execucio_id', 'accio_id', 'versio_accio_id', 'alumne_id', 'llancat_per',
        'es_simulacio', 'estat', 'ok', 'changed', 'failed', 'parametres', 'sortida',
        'data_inici', 'data_fi',
    ];

    protected function casts(): array
    {
        return [
            'estat' => ActionStatus::class,
            'es_simulacio' => 'boolean',
            'parametres' => 'array',
            'data_inici' => 'datetime',
            'data_fi' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RunBatch::class, 'execucio_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'accio_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'alumne_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'llancat_per');
    }
}
