<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Action;
use App\Models\Check;
use App\Models\Module;
use App\Models\Task;
use Illuminate\Console\Command;

/**
 * Exporta el tutorial (curs "Tutorial", TutorialSeeder) a Markdown: la documentació és el mateix
 * contingut que es veu a l'app (enunciats, descripcions, info i codi dels checks i les accions).
 */
class TutorialMarkdown extends Command
{
    protected $signature = 'heimdeye:tutorial {--output=docs/demo-tutorial.md : fitxer on s\'escriu, relatiu a l\'arrel}';

    protected $description = 'Exporta el tutorial de HeimdEye a Markdown';

    public function handle(): int
    {
        $year = AcademicYear::where('nom', 'Tutorial')->first();
        if ($year === null) {
            $this->error('No hi ha el curs Tutorial: php artisan db:seed --class=TutorialSeeder');

            return self::FAILURE;
        }

        $modules = Module::where('curs_id', $year->id)->orderBy('codi')->with([
            'hostTypes', 'variables', 'students', 'actions.currentVersion',
            'blocks.tasks.checks.currentVersion',
        ])->get();

        $md = "# Tutorial de HeimdEye\n\n"
            ."> Generat amb `php artisan heimdeye:tutorial` a partir del curs Tutorial (`TutorialSeeder`).\n"
            ."> És el mateix contingut que es veu a l'app: no l'editis a mà.\n\n"
            ."El tutorial és un curs com qualsevol altre: {$modules->count()} mòduls, amb blocs, tasques, checks i accions, i "
            .$modules->flatMap->students->unique('id')->count().' alumnes ficticis ('
            .$modules->flatMap->students->unique('id')->sortBy('num_taula')->pluck('nom_complet')->implode(', ')."), cadascun amb un servidor.\n";

        foreach ($modules as $module) {
            $md .= "\n## {$module->codi} · {$module->nom}\n\n";
            $md .= '**Equips:** '.$module->hostTypes->map(fn ($t) => "`{$t->nom}` ({$t->nom_host} → {$t->adreca})")->implode(', ')
                .'. **Variables de mòdul:** '.$module->variables->map(fn ($v) => "`{$v->clau} = {$v->valor}`")->implode(', ').".\n";

            foreach ($module->blocks->sortBy('posicio') as $block) {
                $md .= "\n### Bloc {$block->numero}. {$block->titol}\n";
                foreach ($block->tasks as $task) {
                    $md .= $this->task($task);
                }
                foreach ($module->actions->where('bloc_id', $block->id) as $action) {
                    $md .= $this->action($action);
                }
            }
        }

        $path = base_path($this->option('output'));
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, preg_replace("/\n{3,}/", "\n\n", $md));
        $this->line("Escrit {$path}");

        return self::SUCCESS;
    }

    private function task(Task $task): string
    {
        $md = "\n#### Tasca {$task->posicio}. {$task->titol}\n\n".trim((string) $task->enunciat)."\n";
        foreach ($task->checks as $check) {
            $md .= $this->check($check);
        }

        return $md;
    }

    private function check(Check $check): string
    {
        $version = $check->currentVersion;
        $md = "\n**Check: {$check->nom}** · executor `{$check->executor->value}` · {$check->punts} ".($check->punts === 1 ? 'punt' : 'punts')
            .($check->tipus->value === 'opcional' ? ' · opcional' : '')."\n\n"
            .trim((string) $check->descripcio)."\n\n";
        if (filled($check->info)) {
            $md .= trim($check->info)."\n\n";
        }
        foreach ($version?->parametres ?? [] as $param) {
            $md .= "- Paràmetre `{$param['name']}` ({$param['label']}), per defecte `{$param['default']}`\n";
        }

        return $md.$this->code($version?->codi, $check->executor->value);
    }

    private function action(Action $action): string
    {
        $version = $action->currentVersion;
        $md = "\n#### Acció: {$action->nom} · executor `{$action->executor->value}`\n\n".trim((string) $action->descripcio)."\n\n";
        foreach ($version?->parametres ?? [] as $param) {
            $md .= "- Paràmetre `{$param['name']}` ({$param['label']}), per defecte `{$param['default']}`\n";
        }
        if ($ids = $version?->checks_verificacio) {
            $md .= '- Verifica amb: '.Check::whereKey($ids)->orderBy('id')->pluck('nom')->map(fn ($n) => "«{$n}»")->implode(', ')."\n";
        }

        return $md.$this->code($version?->codi, $action->executor->value);
    }

    private function code(?string $code, string $executor): string
    {
        return $code === null ? '' : "\n```".($executor === 'ansible' ? 'yaml' : 'bash')."\n".rtrim($code)."\n```\n";
    }
}
