<?php

namespace App\Runner;

use App\Enums\CredentialType;
use App\Enums\Executor;
use App\Models\Credential;
use App\Models\HostType;
use App\Models\Module;
use App\Models\Student;
use App\Runner\Ansible\InventoryBuilder;
use App\Support\VariableResolver;
use InvalidArgumentException;

/**
 * Prepara el context d'execució d'un script (check o acció) per a un alumne: variables,
 * paràmetres, secrets referenciats, credencial i equips de destinació (capçaleres
 * "# credencial:" i "# hosts:") i, per a Ansible, l'inventari.
 * Els fitxers temporals que crea s'esborren amb cleanup().
 */
class ContextBuilder
{
    public readonly TempFiles $files;

    /** @var list<string> */
    private array $mask = [];

    public function __construct(private VariableResolver $resolver)
    {
        $this->files = new TempFiles;
    }

    /**
     * @param  array<string, string>  $params  valors dels paràmetres (ja validats)
     * @param  list<string>|null  $onlyTypes  noms dels tipus d'equip a què es limita l'execució
     *
     * @throws InvalidArgumentException si falta una variable, un equip o una credencial
     */
    public function build(
        Module $module,
        Student $student,
        Executor $executor,
        string $code,
        int $timeout,
        array $params = [],
        bool $checkMode = false,
        ?array $onlyTypes = null,
    ): ExecutionContext {
        $this->mask = [];
        $headers = CodeHeaders::parse($code);
        $override = $headers->credential === null ? null : $this->credentialNamed($headers->credential);
        $vars = $this->resolver->resolve($student, $module);

        $secrets = $this->secretsFor($module, $code);
        $env = $this->resolver->toEnv($vars);
        foreach ($params as $name => $value) {
            $env[$this->envName('HE_PARAM_', $name)] = (string) $value;
        }
        foreach ($secrets as $name => $value) {
            $env[$this->envName('HE_SECRET_', $name)] = $value;
            $this->mask[] = $value;
        }

        // Executor local: la credencial de la capçalera o la del mòdul. Només en local, perquè
        // l'executor ssh exporta l'entorn a l'equip de l'alumne.
        $credential = $executor === Executor::Local ? ($override ?? $module->credential) : null;
        $sshUser = $sshKey = null;
        if ($this->isPassword($credential)) {
            $env['HE_CRED_USER'] = $credential->nom_usuari;
            $env['HE_CRED_PASSWORD'] = (string) $credential->secret;
            $this->mask[] = (string) $credential->secret;
        } elseif ($credential !== null) {
            $sshUser = $credential->nom_usuari;
            $sshKey = $this->keyFor($credential);
        }

        $targets = $executor === Executor::Ssh
            ? $this->targets($module, $vars, $headers, $override, $onlyTypes)
            : [];

        $inventory = null;
        if ($executor === Executor::Ansible) {
            $builder = new InventoryBuilder($this->resolver, $this->files);
            $inventory = $builder->build($module, [$student], $module->hostTypes);
            $this->mask = [...$this->mask, ...$builder->mask()];
        }

        return new ExecutionContext(
            code: $code,
            env: $env,
            timeout: $timeout,
            sshUser: $sshUser,
            sshKey: $sshKey,
            targets: $targets,
            inventory: $inventory,
            extraVars: [...$params, ...$secrets],
            mask: array_values(array_unique(array_filter($this->mask))),
            checkMode: $checkMode,
            files: $this->files,
        );
    }

    /**
     * Secrets del mòdul que el codi esmenta pel nom (no se n'entrega cap altre).
     *
     * @return array<string, string>
     */
    public function secretsFor(Module $module, string $code): array
    {
        return $module->secrets
            ->filter(fn ($secret) => $secret->valor !== null && str_contains(strtolower($code), strtolower($secret->clau)))
            ->mapWithKeys(fn ($secret) => [$secret->clau => $secret->valor])
            ->all();
    }

    public function cleanup(): void
    {
        $this->files->cleanup();
    }

    /**
     * Equips de l'alumne dels grups de la capçalera "# hosts:" (grup Ansible o nom del tipus d'equip).
     *
     * @param  array<string, string>  $vars
     * @param  list<string>|null  $onlyTypes
     * @return list<SshTarget>
     */
    private function targets(Module $module, array $vars, CodeHeaders $headers, ?Credential $override, ?array $onlyTypes): array
    {
        if ($headers->hosts === []) {
            throw new InvalidArgumentException('Falta la capçalera "# hosts:" amb els equips on executar el codi');
        }

        $matches = fn (HostType $type, string $group) => $type->grup_ansible === $group || $type->nom === $group;
        $types = $module->hostTypes->filter(fn (HostType $type) => collect($headers->hosts)->contains(fn ($group) => $matches($type, $group)));
        $unknown = array_filter($headers->hosts, fn ($group) => ! $types->contains(fn (HostType $type) => $matches($type, $group)));
        if ($unknown) {
            throw new InvalidArgumentException('No existeix el grup d\'equips '.implode(', ', $unknown));
        }
        if ($onlyTypes !== null) {
            $types = $types->whereIn('nom', $onlyTypes);
        }

        return $types->map(function (HostType $type) use ($module, $vars, $override) {
            $connection = $type->variables_connexio ?? [];
            if (($connection['ansible_connection'] ?? 'ssh') !== 'ssh') {
                throw new InvalidArgumentException("L'equip {$type->nom} es connecta per {$connection['ansible_connection']}, no per SSH");
            }
            $credential = $override ?? $type->credential ?? $module->credential;
            if ($this->isPassword($credential)) {
                throw new InvalidArgumentException("La credencial {$credential->nom} no és una credencial SSH");
            }

            if ($missing = $this->resolver->missing($type->nom_host.' '.$type->adreca, $vars)) {
                throw new InvalidArgumentException('Falta la variable '.implode(', ', $missing)." per a l'equip {$type->nom}");
            }

            return new SshTarget(
                name: $this->resolver->render($type->nom_host, $vars),
                host: $this->resolver->render($type->adreca, $vars),
                user: $connection['ansible_user'] ?? $credential?->nom_usuari,
                key: $credential === null ? config('heimdeye.runner.ssh_key_path') : $this->keyFor($credential),
                port: isset($connection['ansible_port']) ? (int) $connection['ansible_port'] : null,
            );
        })->values()->all();
    }

    private function credentialNamed(string $name): Credential
    {
        return Credential::where('nom', $name)->first()
            ?? throw new InvalidArgumentException("No existeix la credencial {$name}");
    }

    private function isPassword(?Credential $credential): bool
    {
        return in_array($credential?->tipus, [CredentialType::Winrm, CredentialType::Password], true);
    }

    /** Fitxer amb la clau privada desada a la credencial o, si no en té, la clau configurada. */
    private function keyFor(Credential $credential): ?string
    {
        if (! $credential->secret) {
            return config('heimdeye.runner.ssh_key_path');
        }

        return $this->files->write("key-{$credential->id}", rtrim($credential->secret)."\n");
    }

    private function envName(string $prefix, string $name): string
    {
        return $prefix.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
    }
}
