<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sessió de consola interactiva d'una instància (model pull). El professor l'obre des de la
 * llista d'instàncies; queda pendent fins que l'agent la recull i obre un bash persistent. A
 * partir d'aquí l'entrada i la sortida viatgen com a missatges (taula missatges_consola).
 * Taula `sessions_consola_agent`.
 */
class ConsoleSession extends Model
{
    protected $table = 'sessions_consola_agent';

    protected $fillable = [
        'alumne_id', 'instancia_id', 'rol_agent', 'obert_per', 'estat',
        'caduca_a', 'darrera_activitat', 'data_inici', 'data_fi',
    ];

    protected function casts(): array
    {
        return [
            'caduca_a' => 'datetime',
            'darrera_activitat' => 'datetime',
            'data_inici' => 'datetime',
            'data_fi' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'alumne_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(AgentInstance::class, 'instancia_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'obert_per');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConsoleMessage::class, 'sessio_id');
    }

    public function isOpen(): bool
    {
        return $this->estat !== 'tancada';
    }
}
