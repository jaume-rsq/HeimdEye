<?php

namespace App\Models\Concerns;

use App\Enums\Level;
use App\Models\Variable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Variables pròpies d'un registre (alumne o tipus d'equip), desades a la taula de variables.
 * Es llegeixen i s'escriuen com un array [clau => valor]; en desar el model, la taula queda igual
 * que l'array (s'afegeixen, es canvien i es treuen files).
 */
trait HasOwnVariables
{
    /** @var array<string, string>|null valors per desar quan es desi el model */
    private ?array $pendingVariables = null;

    abstract protected function ownVariablesLevel(): Level;

    abstract protected function ownVariablesKey(): string;

    /** Sense esdeveniments de model: els seeders els desactiven. */
    public function save(array $options = []): bool
    {
        $saved = parent::save($options);
        if ($saved) {
            $this->syncOwnVariables();
        }

        return $saved;
    }

    public function ownVariables(): HasMany
    {
        return $this->hasMany(Variable::class, $this->ownVariablesKey())->orderBy('id');
    }

    /** @return array<string, string> */
    protected function ownVariableValues(): array
    {
        return $this->pendingVariables ?? $this->ownVariables->pluck('valor', 'clau')->all();
    }

    protected function setOwnVariableValues(?array $values): void
    {
        $this->pendingVariables = [];
        foreach ($values ?? [] as $key => $value) {
            if (filled($key)) {
                $this->pendingVariables[(string) $key] = match (true) {
                    is_bool($value) => $value ? 'true' : 'false',
                    default => (string) $value,
                };
            }
        }
    }

    private function syncOwnVariables(): void
    {
        if ($this->pendingVariables === null) {
            return;
        }
        $values = $this->pendingVariables;
        $this->pendingVariables = null;

        $this->ownVariables()->whereNotIn('clau', array_keys($values) ?: [''])->delete();
        foreach ($values as $key => $value) {
            $this->ownVariables()->updateOrCreate(['clau' => $key], ['nivell' => $this->ownVariablesLevel(), 'valor' => $value]);
        }
        $this->unsetRelation('ownVariables');
    }
}
