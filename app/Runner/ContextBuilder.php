<?php

namespace App\Runner;

use App\Enums\Executor;
use App\Enums\SecretType;
use App\Models\HostType;
use App\Models\Module;
use App\Models\Secret;
use App\Models\Student;
use App\Runner\Ansible\InventoryBuilder;
use App\Support\VariableResolver;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Prepara el context d'execució d'un script (check o acció) per a un alumne: variables,
 * paràmetres calculats per a l'alumne, secrets que esmenta, accés i equips de destinació
 * (capçalera "# hosts:", o el "hosts:" dels plays) i, per a Ansible,
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
        $validator->assertNoLegacyHeaders($code);
        $validator->assertNoLeakedSecrets($module, $executor, $code);
        $types = $validator->hostTypes($module, $executor, $code);
        if ($types !== null && $onlyTypes !== null) {
            $types = $types->whereIn('nom', $onlyTypes)->values();
            if ($types->isEmpty()) {
                throw new InvalidArgumentException('Cap dels tipus d\'equip triats no és dels que fa servir el codi');
            }
        }

        $vars = $this->resolver->resolve($student, $module);
        $this->assertNoUnresolvedVariables($vars, $code);
        $params = $this->renderParams($params, $vars);

        $secrets = $this->secretsFor($module, $code, $executor);
        $env = $this->resolver->toEnv($vars);
        foreach ($params as $name => $value) {
            $env[$this->envName('HE_PARAM_', $name)] = $value;
        }
        $extraVars = $params;
        foreach ($secrets as $secret) {
            $keyFile = $secret->tipus === SecretType::SshKey ? SecretRefs::keyFile($secret, $this->files) : null;
            $secretEnv = SecretRefs::env($secret, $keyFile);
            $env = [...$env, ...array_intersect_key($secretEnv, array_flip(SecretRefs::envNamesIn($code, $secret)))];
            if ($executor === Executor::Ansible) {
                $extraVars[$secret->clau] = SecretRefs::ansibleValue($secret, $keyFile);
            }
            $this->mask[] = $secret->tipus === SecretType::SshKey ? '' : (string) $secret->valor;
        }

        // Executor local: l'accés del mòdul. Només en local, perquè l'executor ssh exporta
        // l'entorn a l'equip de l'alumne.
        $access = $executor === Executor::Local ? $module->access : null;
        $sshUser = $sshKey = null;
        if ($access?->tipus === SecretType::Login) {
            $env['HE_CRED_USER'] = (string) $access->nom_usuari;
            $env['HE_CRED_PASSWORD'] = (string) $access->valor;
            $this->mask[] = (string) $access->valor;
        } elseif ($access !== null) {
            // Sense clau desada, l'script local no té HE_SSH_KEY (i potser no fa ssh).
            $sshUser = $access->nom_usuari;
            $sshKey = SecretRefs::keyFile($access, $this->files);
        }
        if ($executor === Executor::Local) {
            // Opcions per als ssh que facin els scripts locals: veure SshOptions.
            $env['HE_SSH_OPTS'] = implode(' ', SshOptions::HOST_KEYS);
        }

        $targets = $executor === Executor::Ssh
            ? $this->targets($module, $vars, $types)
            : [];

        $inventory = null;
        $hosts = [];
        if ($executor === Executor::Ansible) {
            foreach ($types ?? [] as $type) {
                if (! $type->isUnique()) {
                    $this->assertHostResolved($type, $vars);
                }
            }
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
            extraVars: $extraVars,
            mask: array_values(array_unique(array_filter($this->mask))),
            checkMode: $checkMode,
            files: $this->files,
        );
    }

    /**
     * Secrets que veu el mòdul i que el codi esmenta (no se n'entrega cap altre): amb bash, per
     * la variable d'entorn; amb Ansible, també pel nom.
     *
     * @return Collection<string, Secret>
     */
    public function secretsFor(Module $module, string $code, Executor $executor): Collection
    {
        $secrets = $module->visibleSecrets()->filter(fn (Secret $secret) => $executor === Executor::Ansible
            ? SecretRefs::inAnsible($code, $secret)
            : SecretRefs::inBash($code, $secret));
        $empty = $secrets->filter(fn (Secret $secret) => $secret->tipus !== SecretType::SshKey && ! $secret->hasValue());
        if ($empty->isNotEmpty()) {
            throw new InvalidArgumentException('El secret '.$empty->keys()->implode(', ')." no té valor: omple'l a Inventari → Secrets (o a la pestanya Secrets del mòdul)");
        }

        return $secrets;
    }

    /**
     * Atura l'execució si el codi fa servir (pel nom o com a $HE_NOM) una variable que per a
     * aquest alumne no s'ha pogut calcular: si no, el codi rebria la plantilla tal qual.
     *
     * @param  array<string, string>  $vars
     *
     * @throws InvalidArgumentException
     */
    private function assertNoUnresolvedVariables(array $vars, string $code): void
    {
        foreach ($this->resolver->unresolved($vars) as $name => $missing) {
            $env = $this->envName('HE_', $name);
            if (preg_match('/(?<![A-Za-z0-9_])(?:'.preg_quote($name, '/').'|'.preg_quote($env, '/').')(?![A-Za-z0-9_])/', $code)) {
                throw new InvalidArgumentException(sprintf(
                    'El codi fa servir %s, però aquest alumne no té %s: defineix-la a la fitxa de l\'alumne (Variables pròpies) o a l\'inventari.',
                    $name,
                    implode(', ', $missing),
                ));
            }
        }
    }

    /**
     * L'adreça d'un equip pot fer servir una variable que tampoc s'ha pogut calcular
     * (adreca {{ip_router}}, ip_router sense xarxa_aula): seria una adreça amb claus.
     *
     * @param  array<string, string>  $vars
     *
     * @throws InvalidArgumentException
     */
    public function assertHostResolved(HostType $type, array $vars): void
    {
        $address = $this->resolver->render($type->nom_host.' '.$type->adreca, $vars);
        if ($missing = $this->resolver->missing($address, $vars)) {
            throw new InvalidArgumentException("L'adreça de l'equip {$type->nom} depèn de ".implode(', ', $missing).", que aquest alumne no té: defineix-la a la fitxa de l'alumne (Variables pròpies) o a l'inventari.");
        }
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
    private function targets(Module $module, array $vars, Collection $types): array
    {
        return $types->map(function (HostType $type) use ($module, $vars) {
            $connection = $type->variables_connexio ?? [];
            if (($connection['ansible_connection'] ?? 'ssh') !== 'ssh') {
                throw new InvalidArgumentException("L'equip {$type->nom} es connecta per {$connection['ansible_connection']}, no per SSH");
            }
            $access = $type->access ?? $module->access;
            if ($access?->tipus === SecretType::Login) {
                throw new InvalidArgumentException("L'accés {$access->clau} és d'usuari i contrasenya, no una clau SSH");
            }

            if ($missing = $this->resolver->missing($type->nom_host.' '.$type->adreca, $vars)) {
                throw new InvalidArgumentException('Falta la variable '.implode(', ', $missing)." per a l'equip {$type->nom}");
            }
            $this->assertHostResolved($type, $vars);

            return new SshTarget(
                name: $this->resolver->render($type->nom_host, $vars),
                host: $this->resolver->render($type->adreca, $vars),
                user: $connection['ansible_user'] ?? $access?->nom_usuari,
                key: $access === null ? null : $this->keyFor($access),
                port: isset($connection['ansible_port']) ? (int) $connection['ansible_port'] : null,
            );
        })->values()->all();
    }

    /** Fitxer amb la clau privada del secret d'accés. */
    private function keyFor(Secret $secret): string
    {
        return SecretRefs::keyFile($secret, $this->files)
            ?? throw new InvalidArgumentException("El secret {$secret->clau} no té clau privada: posa-la a Inventari → Secrets comuns (o a la pestanya Secrets del mòdul)");
    }

    private function envName(string $prefix, string $name): string
    {
        return $prefix.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
    }
}
