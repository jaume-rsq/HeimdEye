<?php

namespace App\Console\Commands;

use App\Models\Action;
use App\Runner\ActionRunner;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RunActionCommand extends Command
{
    protected $signature = 'heimdeye:action
        {action : Id o nom de l\'acció}
        {--student=* : Usuari o número de taula de l\'alumne (per defecte, tots els matriculats)}
        {--host=* : Tipus d\'equip (per defecte, tots)}
        {--param=* : Paràmetre clau=valor}
        {--simulate : Simula amb --check --diff, sense canviar res}
        {--queue : Envia l\'acció a la cua}';

    protected $description = 'Executa o simula una acció sobre els equips dels alumnes';

    public function handle(ActionRunner $runner): int
    {
        $action = Action::with('module')->whereKey(ctype_digit($this->argument('action')) ? $this->argument('action') : null)
            ->orWhere('nom', $this->argument('action'))
            ->first();
        if ($action === null) {
            $this->error('No existeix l\'acció');

            return self::FAILURE;
        }

        $students = $action->module->students()
            ->where('es_actiu', true)
            ->when($this->option('student'), fn ($q, $keys) => $q->where(fn ($q) => $q
                ->whereIn('usuari', $keys)
                ->orWhereIn('num_taula', array_filter($keys, 'ctype_digit'))))
            ->orderBy('num_taula')
            ->get();

        $params = [];
        foreach ($this->option('param') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[$key] = $value;
        }

        try {
            $batch = $runner->launch($action, $students, $this->option('host'), $params, (bool) $this->option('simulate'), sync: ! $this->option('queue'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            $this->info("Acció a la cua per a {$students->count()} alumnes");

            return self::SUCCESS;
        }

        $this->table(
            ['Alumne', 'Estat', 'ok', 'changed', 'failed', 'Resum'],
            $batch->actionRuns()->with('student')->get()->map(fn ($run) => [
                $run->student->usuari,
                $run->estat->value,
                $run->ok,
                $run->changed,
                $run->failed,
                strtok((string) $run->sortida, "\n"),
            ]),
        );

        return self::SUCCESS;
    }
}
