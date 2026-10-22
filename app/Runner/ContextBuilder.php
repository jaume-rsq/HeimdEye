<?php

namespace App\Runner;

use App\Enums\CredentialType;
use App\Enums\Executor;
use App\Models\Credential;
use App\Models\Module;
use App\Models\Student;
use App\Runner\Ansible\InventoryBuilder;
use App\Support\VariableResolver;
use InvalidArgumentException;

/**
 * Prepara el context d'execució d'un script (check o acció) per a un alumne:
 * variables, equip de destinació, credencial, secrets referenciats i, per a Ansible, l'inventari.
 * Els fitxers temporals que crea s'esborren amb cleanup().
 */
class ContextBuilder
{
    public readonly TempFiles $files;

    public function __construct(private VariableResolver $resolver)
    {
        $this->files = new TempFiles;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $params  paràmetres de l'acció
     *
     * @throws InvalidArgumentException si falta una variable o un tipus d'equip
     */
    public function build(
        Module $module,
        Student $student,
        Executor $executor,
        string $code,
        array $config,
        int $timeout,
        ?Credential $credential = null,
        array $params = [],
        bool $checkMode = false,
    ): ExecutionContext {
        $vars = $this->resolver->resolve($student, $module);
        $credential ??= $module->credential;

        if (isset($config['host_type'])) {
            $type = $module->hostTypes->firstWhere('nom', $config['host_type'])
                ?? throw new InvalidArgumentException("No existeix el tipus d'equip {$config['host_type']}");
            $config['host'] = $this->resolver->render($type->adreca, $vars);
            $credential = $type->credential ?? $credential;
        }
        $config = $this->renderConfig($config, $vars);

        $secrets = $this->secretsFor($module, $code);
        $env = $this->resolver->toEnv($vars);
        foreach ($params as $name => $value) {
            $env[$this->envName('HE_PARAM_', $name)] = (string) $value;
        }
        foreach ($secrets as $name => $value) {
            $env[$this->envName('HE_SECRET_', $name)] = $value;
        }
        $mask = array_values($secrets);

        $sshKey = null;
        if (in_array($credential?->tipus, [CredentialType::Winrm, CredentialType::Password], true) && $credential->secret) {
            $mask[] = $credential->secret;
        } elseif ($credential?->secret) {
            $sshKey = $this->files->write("key-{$credential->id}", rtrim($credential->secret)."\n");
        }

        $inventory = null;
        if ($executor === Executor::Ansible) {
            $builder = new InventoryBuilder($this->resolver, $this->files);
            $inventory = $builder->build($module, [$student], $module->hostTypes);
            $mask = [...$mask, ...$builder->mask()];
        }

        return new ExecutionContext(
            code: $code,
            env: $env,
            config: $config,
            timeout: $timeout,
            sshUser: $credential?->nom_usuari,
            sshKey: $sshKey ?? config('heimdeye.runner.ssh_key_path'),
            inventory: $inventory,
            extraVars: [...$params, ...$secrets],
            mask: $mask,
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

    private function envName(string $prefix, string $name): string
    {
        return $prefix.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
    }

    /**
     * Resol les plantilles {{var}} dels valors de text de la config.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $vars
     * @return array<string, mixed>
     */
    private function renderConfig(array $config, array $vars): array
    {
        foreach ($config as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            if ($missing = $this->resolver->missing($value, $vars)) {
                throw new InvalidArgumentException('Falta la variable '.implode(', ', $missing));
            }
            $config[$key] = $this->resolver->render($value, $vars);
        }

        return $config;
    }
}
