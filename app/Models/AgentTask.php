<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Feina d'un check que s'executa per agent: queda pendent fins que l'agent de la instància la
 * recull (long poll) i l'executa. Quan arriba el resultat, es desa un CheckRun normal i la feina
 * es marca com a feta. Taula `feines_agent`.
 */
class AgentTask extends Model
{
    protected $table = 'feines_agent';

    protected $fillable = [
        'execucio_id', 'check_id', 'versio_check_id', 'alumne_id', 'instancia_id',
        'rol_agent', 'estat', 'payload_agent', 'mascara_agent', 'caduca_a', 'recollit_a',
    ];

    protected function casts(): array
    {
        return [
            'payload_agent' => 'array',
            'mascara_agent' => 'array',
            'caduca_a' => 'datetime',
            'recollit_a' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RunBatch::class, 'execucio_id');
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class, 'check_id');
    }

    public function checkVersion(): BelongsTo
    {
        return $this->belongsTo(CheckVersion::class, 'versio_check_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'alumne_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(AgentInstance::class, 'instancia_id');
    }
}
