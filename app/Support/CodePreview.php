<?php

namespace App\Support;

use App\Enums\Executor;
use App\Models\Check;
use App\Models\Student;
use App\Runner\Params;
use InvalidArgumentException;

/**
 * El codi de la versió vigent d'un check amb les dades d'un alumne: les variables ($HE_IP_ROUTER
 * o, a Ansible, {{ ip_router }}) i els paràmetres ($HE_PARAM_NOM o {{ NOM }}) es canvien pel seu
 * valor. Els secrets i les credencials no es canvien mai: es queden com al codi.
 */
class CodePreview
{
    public function __construct(private VariableResolver $resolver) {}

    /** @return array{codi: ?string, avisos: list<string>} */
    public function for(Check $check, Student $student): array
    {
        $version = $check->currentVersion;
        $module = $check->module;
        if ($version === null || $module === null) {
            return ['codi' => null, 'avisos' => ['El check encara no té codi.']];
        }

        $warnings = [];
        $vars = [];
        foreach ($this->resolver->detailed($student, $module) as $key => $var) {
            if ($var['error'] !== null) {
                $warnings[] = $var['error'];

                continue;
            }
            $vars[$key] = $var['valor'];
        }

        foreach ($this->resolver->unresolved($vars) as $name => $missing) {
            $env = 'HE_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
            if (preg_match('/(?<![A-Za-z0-9_])(?:'.preg_quote($name, '/').'|'.preg_quote($env, '/').')(?![A-Za-z0-9_])/', $version->codi)) {
                $warnings[] = "El codi fa servir {$name}, però aquest alumne no té ".implode(', ', $missing).': defineix-la a la fitxa de l\'alumne (Variables pròpies) o a l\'inventari.';
            }
        }

        try {
            $params = Params::values($version->parametres);
        } catch (InvalidArgumentException $e) {
            $warnings[] = $e->getMessage();
            $params = [];
        }
        $params = array_map(fn (string $value) => $this->resolver->render($value, $vars), $params);

        $code = str_replace("\r\n", "\n", $version->codi);
        if ($check->executor === Executor::Ansible) {
            return ['codi' => $this->resolver->render($code, [...$vars, ...$params]), 'avisos' => $warnings];
        }

        $env = $this->resolver->toEnv($vars);
        foreach ($params as $name => $value) {
            $env['HE_PARAM_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name))] = $value;
        }
        // $HE_NOM i ${HE_NOM}; el nom sencer, perquè $HE_IP no tapi $HE_IP_ROUTER.
        $code = preg_replace_callback('/\$\{(HE_[A-Z0-9_]+)\}|\$(HE_[A-Z0-9_]+)/', function (array $m) use ($env) {
            $name = $m[1] !== '' ? $m[1] : $m[2];

            return array_key_exists($name, $env) ? $env[$name] : $m[0];
        }, $code);

        return ['codi' => $code, 'avisos' => $warnings];
    }
}
