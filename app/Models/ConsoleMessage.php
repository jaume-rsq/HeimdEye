<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un missatge d'una sessió de consola: l'entrada que escriu el professor o la sortida que torna
 * l'agent. Fa alhora de cua (l'agent recull l'entrada no consumida, el professor llegeix la
 * sortida nova) i d'auditoria (queda tot, en ordre). Taula `missatges_consola`.
 */
class ConsoleMessage extends Model
{
    protected $table = 'missatges_consola';

    protected $fillable = ['sessio_id', 'direccio', 'contingut', 'consumit'];

    protected function casts(): array
    {
        return [
            'consumit' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConsoleSession::class, 'sessio_id');
    }
}
