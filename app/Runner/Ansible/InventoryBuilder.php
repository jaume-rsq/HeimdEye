<?php

namespace App\Runner\Ansible;

use App\Enums\SecretType;
use App\Models\HostType;
use App\Models\Module;
use App\Models\Secret;
use App\Models\Student;
use App\Runner\SecretRefs;
use App\Runner\TempFiles;
use App\Support\VariableResolver;
use Illuminate\Support\Collection;

/**
 * Genera l'inventari Ansible d'una execució, amb els mateixos noms de host i de grup
 * que l'inventari de l'aula: un host per alumne i tipus d'equip (pve01-alumne01, router01...),
 * un grup per tipus (pve, vm_router...), un grup per alumne (taula01...) dins d'"alumnes",
 * i els equips globals (zbx). Només hi entren els tipus d'equip que demana el codi.
 */
class InventoryBuilder
{
    /** @var array<string, ?int> host => id de l'alumne (null si és global) */
    private array $hostMap = [];

    /** @var list<string> */
    private array $mask = [];

    public function __construct(
        private VariableResolver $resolver,
        private TempFiles $files,
    ) {}

    /**
     * @param  iterable<Student>  $students
     * @param  Collection<int, HostType>  $hostTypes  tipus d'equip on va el playbook (globals inclosos, si n'hi ha)
     * @return array<string, mixed>
     */
    public function build(Module $module, iterable $students, Collection $hostTypes): array
    {
        $this->hostMap = [];
        $globals = $this->resolver->common($module);
        $children = [];
        $studentGroups = [];

        foreach ($hostTypes->filter->isGlobal() as $type) {
            $name = $this->resolver->render($type->nom_host, $globals);
            $children[$type->grup_ansible]['hosts'][$name] = $this->hostVars($type, $globals);
            $this->hostMap[$name] = null;
        }

        foreach ($students as $student) {
            $vars = $this->resolver->resolve($student, $module);
            $group = $this->resolver->render((string) config('heimdeye.ansible.student_group'), $vars);
            $studentGroups[$group] = (object) [];
            $children[$group] = ['hosts' => [], 'vars' => array_diff_assoc($vars, $globals)];

            foreach ($hostTypes->reject->isGlobal() as $type) {
                $name = $this->resolver->render($type->nom_host, $vars);
                $children[$type->grup_ansible]['hosts'][$name] = $this->hostVars($type, $vars);
                $children[$group]['hosts'][$name] = (object) [];
                $this->hostMap[$name] = $student->id;
            }
        }

        $children['alumnes'] = ['children' => $studentGroups ?: (object) []];

        $vars = $globals;
        // Compatibilitat amb el codi d'abans dels secrets: la clau pública de l'accés del mòdul.
        if ($module->access?->tipus === SecretType::SshKey && ($pubkey = SecretRefs::pubkeyFile($module->access, $this->files))) {
            $vars['heimdeye_ssh_pubkey_file'] = $pubkey;
        }

        return ['all' => ['vars' => $vars ?: (object) [], 'children' => $children]];
    }

    /** @return array<string, ?int> */
    public function hostMap(): array
    {
        return $this->hostMap;
    }

    /** @return list<string> contrasenyes de les credencials que han entrat a l'inventari */
    public function mask(): array
    {
        return array_values(array_unique($this->mask));
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, mixed>
     */
    private function hostVars(HostType $type, array $vars): array
    {
        $hostVars = ['ansible_host' => $this->resolver->render($type->adreca, $vars)];
        foreach ($type->variables_connexio ?? [] as $key => $value) {
            $hostVars[$key] = is_string($value) ? $this->resolver->render($value, $vars) : $value;
        }

        return [...$hostVars, ...$this->accessVars($type->access ?? $type->module->access)];
    }

    /** @return array<string, string> */
    private function accessVars(?Secret $access): array
    {
        if ($access === null) {
            return [];
        }

        $vars = ['ansible_user' => (string) $access->nom_usuari];
        if ($access->tipus === SecretType::Login) {
            if ($access->hasValue()) {
                $vars['ansible_password'] = (string) $access->valor;
                $this->mask[] = (string) $access->valor;
            }
        } elseif ($keyFile = SecretRefs::keyFile($access, $this->files)) {
            $vars['ansible_ssh_private_key_file'] = $keyFile;
        }

        return $vars;
    }
}
