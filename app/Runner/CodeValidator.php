<?php

namespace App\Runner;

use App\Enums\Executor;
use App\Models\HostType;
use App\Models\Module;
use App\Runner\Ansible\Playbook;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Comprovacions del codi d'un check o d'una acció, en desar-lo i abans d'executar-lo:
 *
 * - ssh i Ansible han de dir a quins grups d'equips van (capçalera "# hosts:" o el "hosts:"
 *   dels plays), sense "all": l'inventari només porta aquests equips;
 * - cap secret no pot arribar en clar a un equip d'alumne: amb ssh, el codi no en pot esmentar
 *   cap; amb Ansible, només passat per password_hash o en tasques amb delegate_to: localhost.
 */
class CodeValidator
{
    /** @throws InvalidArgumentException amb el primer problema trobat */
    public function validate(Module $module, Executor $executor, string $code): void
    {
        $this->hostTypes($module, $executor, $code);
        $this->assertNoLeakedSecrets($module, $executor, $code);
    }

    /**
     * Tipus d'equip on s'executa el codi (null amb l'executor local, que no en té).
     *
     * @return Collection<int, HostType>|null
     *
     * @throws InvalidArgumentException
     */
    public function hostTypes(Module $module, Executor $executor, string $code): ?Collection
    {
        $groups = match ($executor) {
            Executor::Ssh => CodeHeaders::parse($code)->hosts
                ?: throw new InvalidArgumentException('Falta la capçalera "# hosts:" amb els equips on executar el codi'),
            Executor::Ansible => Playbook::parse($code)->hostGroups(),
            default => null,
        };
        if ($groups === null) {
            return null;
        }
        if (in_array('all', $groups, true)) {
            throw new InvalidArgumentException('No es pot fer servir "all": cal dir els grups d\'equips concrets');
        }

        $matches = fn (HostType $type, string $group) => $type->grup_ansible === $group || $type->nom === $group;
        $unknown = array_filter($groups, fn ($group) => ! $module->hostTypes->contains(fn (HostType $type) => $matches($type, $group)));
        if ($unknown) {
            throw new InvalidArgumentException('No existeix el grup d\'equips '.implode(', ', $unknown));
        }

        return $module->hostTypes
            ->filter(fn (HostType $type) => collect($groups)->contains(fn ($group) => $matches($type, $group)))
            ->values();
    }

    /** @throws InvalidArgumentException */
    public function assertNoLeakedSecrets(Module $module, Executor $executor, string $code): void
    {
        $names = $module->secrets->pluck('clau')->all();
        $leaked = match ($executor) {
            Executor::Ssh => array_values(array_filter($names, fn ($name) => Playbook::mentions($code, $name))),
            Executor::Ansible => Playbook::parse($code)->leakedSecrets($names),
            default => [],
        };
        if ($leaked === []) {
            return;
        }

        throw new InvalidArgumentException(match ($executor) {
            Executor::Ssh => 'El codi esmenta el secret '.implode(', ', $leaked).': amb l\'executor ssh arribaria en clar a l\'equip de l\'alumne',
            default => 'El secret '.implode(', ', $leaked).' arribaria en clar a l\'equip de l\'alumne: fes-lo servir amb password_hash o en una tasca amb delegate_to: localhost',
        });
    }
}
