<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Instància efímera d'un alumne (p. ex. una VM d'AWS Learner Lab) amb l'agent instal·lat.
 *
 * S'autoregistra en arrencar l'agent i es marca inactiva si deixa d'enviar batecs (cada long
 * poll compta com a batec). El hostname la identifica i fa de grup de la capçalera "# hosts:".
 */
class AgentInstance extends Model
{
    protected $table = 'instancies_agent';

    protected $fillable = [
        'alumne_id', 'hostname', 'ip_publica', 'ip_privada', 'sistema',
        'versio_agent', 'estat', 'darrer_batec', 'registrat_a',
    ];

    protected function casts(): array
    {
        return [
            'darrer_batec' => 'datetime',
            'registrat_a' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'alumne_id');
    }

    public function isActive(): bool
    {
        return $this->estat === 'activa';
    }

    /** @param  Builder<AgentInstance>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('estat', 'activa');
    }
}
