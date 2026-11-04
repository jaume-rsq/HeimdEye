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
        'execucio_id', 'accio_id', 'versio_accio_id', 'alumne_id', 'instancia_id', 'rol_agent',
        'llancat_per', 'es_simulacio', 'estat', 'ok', 'changed', 'failed', 'parametres', 'sortida',
        'data_inici', 'data_fi', 'caduca_a', 'recollit_a', 'payload_agent', 'mascara_agent',
    ];

    protected function casts(): array
    {
        return [
            'estat' => ActionStatus::class,
            'es_simulacio' => 'boolean',
            'parametres' => 'array',
            'payload_agent' => 'array',
            'mascara_agent' => 'array',
            'data_inici' => 'datetime',
            'data_fi' => 'datetime',
            'caduca_a' => 'datetime',
            'recollit_a' => 'datetime',
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

    public function version(): BelongsTo
    {
        return $this->belongsTo(ActionVersion::class, 'versio_accio_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'alumne_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(AgentInstance::class, 'instancia_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'llancat_per');
    }
}
