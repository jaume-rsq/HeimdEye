<?php

namespace App\Runner;

use App\Enums\CredentialType;
use App\Enums\Executor;
use App\Models\Credential;
use App\Models\HostType;
use App\Models\Module;
use App\Models\Student;
use App\Runner\Ansible\InventoryBuilder;
use App\Runner\Ansible\Playbook;
use App\Support\VariableResolver;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Prepara el context d'execució d'un script (check o acció) per a un alumne: variables,
 * paràmetres calculats per a l'alumne, secrets referenciats, credencial i equips de destinació
 * (capçaleres "# credencial:" i "# hosts:", o el "hosts:" dels plays) i, per a Ansible,
 * l'inventari amb només aquests equips. Abans comprova el codi amb CodeValidator.
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
        $validator = new CodeValidator;
        $validator->assertNoLeakedSecrets($module, $executor, $code);
        $types = $validator->hostTypes($module, $executor, $code);
        if ($types !== null && $onlyTypes !== null) {
            $types = $types->whereIn('nom', $onlyTypes)->values();
            if ($types->isEmpty()) {
                throw new InvalidArgumentException('Cap dels tipus d\'equip triats no és dels que fa servir el codi');
            }
        }

        $headers = CodeHeaders::parse($code);
        $override = $headers->credential === null ? null : $this->credentialNamed($headers->credential);
        $vars = $this->resolver->resolve($student, $module);
        $params = $this->renderParams($params, $vars);

        $secrets = $this->secretsFor($module, $code);
        $env = $this->resolver->toEnv($vars);
        foreach ($params as $name => $value) {
            $env[$this->envName('HE_PARAM_', $name)] = $value;
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
        if ($executor === Executor::Local) {
            // Opcions per als ssh que facin els scripts locals: veure SshOptions.
            $env['HE_SSH_OPTS'] = implode(' ', SshOptions::HOST_KEYS);
        }

        $targets = $executor === Executor::Ssh
            ? $this->targets($vars, $types, $override)
            : [];

        $inventory = null;
        $hosts = [];
        if ($executor === Executor::Ansible) {
            $builder = new InventoryBuilder($this->resolver, $this->files);
            $inventory = $builder->build($module, [$student], $types);
            $hosts = array_keys($builder->hostMap());
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
            hosts: $hosts,
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
            ->filter(fn ($secret) => $secret->valor !== null && Playbook::mentions($code, $secret->clau))
            ->mapWithKeys(fn ($secret) => [$secret->clau => $secret->valor])
            ->all();
    }

    public function cleanup(): void
    {
        $this->files->cleanup();
    }

    /**
     * Valors dels paràmetres calculats amb les variables de l'alumne ({{ip_pve}}...).
     *
     * @param  array<string, string>  $params
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private function renderParams(array $params, array $vars): array
    {
        foreach ($params as $name => $value) {
            if ($missing = $this->resolver->missing((string) $value, $vars)) {
                throw new InvalidArgumentException("El paràmetre {$name} fa servir una variable que no existeix: ".implode(', ', $missing));
            }
            $params[$name] = $this->resolver->render((string) $value, $vars);
        }

        return $params;
    }

    /**
     * Un destí SSH per a cada tipus d'equip de la capçalera "# hosts:".
     *
     * @param  array<string, string>  $vars
     * @param  Collection<int, HostType>  $types
     * @return list<SshTarget>
     */
    private function targets(array $vars, Collection $types, ?Credential $override): array
    {
        return $types->map(function (HostType $type) use ($vars, $override) {
            $connection = $type->variables_connexio ?? [];
            if (($connection['ansible_connection'] ?? 'ssh') !== 'ssh') {
                throw new InvalidArgumentException("L'equip {$type->nom} es connecta per {$connection['ansible_connection']}, no per SSH");
            }
            $credential = $override ?? $type->credential ?? $type->module->credential;
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
