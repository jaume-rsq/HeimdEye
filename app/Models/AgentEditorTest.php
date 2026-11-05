<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prova de l'editor (botó Provar) d'una acció o un check d'agent: l'esborrany queda pendent fins
 * que l'agent de la instància el recull. El resultat no compta enlloc: quan han acabat tots els rols
 * d'una prova, es deixa a la memòria cau perquè el reculli la pàgina. Taula `proves_agent`.
 */
class AgentEditorTest extends Model
{
    protected $table = 'proves_agent';

    protected $fillable = [
        'clau', 'tipus', 'alumne_id', 'instancia_id', 'rol_agent', 'estat',
        'payload_agent', 'mascara_agent', 'resultat', 'extra', 'caduca_a', 'recollit_a',
    ];

    protected function casts(): array
    {
        return [
            'payload_agent' => 'array',
            'mascara_agent' => 'array',
            'resultat' => 'array',
            'extra' => 'array',
            'caduca_a' => 'datetime',
            'recollit_a' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'alumne_id');
    }
}
