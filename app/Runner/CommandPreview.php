<?php

namespace App\Runner;

use App\Enums\Executor;

/**
 * El codi tal com s'executarà per a un alumne, amb les variables HE_* (bash) o les {{ VAR }}
 * simples (Ansible) substituïdes pel seu valor. Els secrets surten emmascarats. Només és per
 * mostrar-ho a l'editor: el runner no l'executa així.
 */
final class CommandPreview
{
    public static function describe(Executor $executor, ExecutionContext $context): string
    {
        $text = match ($executor) {
            Executor::Ansible => self::ansible($context),
            Executor::Ssh => self::ssh($context),
            default => "# bash al servidor HeimdEye\n".self::bash($context->code, [
                ...$context->env,
                'HE_SSH_USER' => $context->sshUser ?? '',
                'HE_SSH_KEY' => $context->sshKey ?? '',
            ]),
        };

        return Masker::mask($text, $context->mask);
    }

    private static function ssh(ExecutionContext $context): string
    {
        $code = self::bash($context->code, $context->env);

        return collect($context->targets)
            ->map(fn (SshTarget $target) => "# ssh {$target->user}@{$target->host}".($target->port ? " -p {$target->port}" : '')." ({$target->name})\n".$code)
            ->implode("\n\n");
    }

    private static function ansible(ExecutionContext $context): string
    {
        $vars = $context->extraVars;
        $playbook = preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
            fn (array $m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0],
            $context->code,
        );
        $header = '# ansible-playbook'.($context->checkMode ? ' --check --diff' : '').' a '.(implode(', ', $context->hosts) ?: 'cap equip');
        foreach ($vars as $name => $value) {
            $header .= "\n# -e {$name}=".(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        return $header."\n".$playbook;
    }

    /** Substitueix $HE_X i ${HE_X}; les que no existeixen es deixen tal qual. */
    private static function bash(string $code, array $env): string
    {
        return preg_replace_callback(
            '/\$(?:\{(HE_[A-Z0-9_]+)\}|(HE_[A-Z0-9_]+))/',
            function (array $m) use ($env) {
                $name = $m[1] !== '' ? $m[1] : $m[2];

                return array_key_exists($name, $env) ? (string) $env[$name] : $m[0];
            },
            $code,
        );
    }
}
