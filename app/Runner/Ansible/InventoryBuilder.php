<?php

namespace App\Runner\Ansible;

use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\GlobalVar;
use App\Models\HostType;
use App\Models\Module;
use App\Models\Student;
use App\Runner\TempFiles;
use App\Support\VariableResolver;
use Illuminate\Support\Collection;

/**
 * Genera l'inventari Ansible d'una execució, amb els mateixos noms de host i de grup
 * que l'inventari de l'aula: un host per alumne i tipus d'equip (pve01-alumne01, router01...),
 * un grup per tipus (pve, vm_router...), un grup per alumne (taula01...) dins d'"alumnes",
 * i els equips globals (zbx).
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
     * @param  Collection<int, HostType>  $hostTypes  tipus per alumne seleccionats (els globals s'hi afegeixen sempre)
     * @return array<string, mixed>
     */
    public function build(Module $module, iterable $students, Collection $hostTypes): array
    {
        $this->hostMap = [];
        $globals = GlobalVar::query()->pluck('valor', 'clau')->all();
        $children = [];
        $studentGroups = [];

        foreach ($module->hostTypes->filter->isGlobal() as $type) {
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
        if ($pubkey = config('heimdeye.runner.ssh_pubkey_path')) {
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

        return [...$hostVars, ...$this->credentialVars($type->credential ?? $type->module->credential)];
    }

    /** @return array<string, string> */
    private function credentialVars(?Credential $credential): array
    {
        if ($credential === null) {
            return [];
        }

        $vars = ['ansible_user' => $credential->nom_usuari];
        if (in_array($credential->tipus, [CredentialType::Winrm, CredentialType::Password], true)) {
            if ($credential->secret) {
                $vars['ansible_password'] = $credential->secret;
                $this->mask[] = $credential->secret;
            }
        } elseif ($credential->secret) {
            $vars['ansible_ssh_private_key_file'] = $this->files->write("key-{$credential->id}", rtrim($credential->secret)."\n");
        } elseif ($path = config('heimdeye.runner.ssh_key_path')) {
            $vars['ansible_ssh_private_key_file'] = $path;
        }

        return $vars;
    }
}
