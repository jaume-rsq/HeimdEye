<?php

namespace App\Runner;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Jobs\RunEditorTest;
use App\Models\AgentEditorTest;
use App\Models\AgentInstance;
use App\Models\Module;
use App\Models\Student;
use App\Support\VariableResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Proves de l'editor (botó Provar) amb l'executor agent. L'esborrany no es pot executar des del
 * servidor: queda pendent a `proves_agent`, un per rol de "# hosts:", fins que l'agent de la
 * instància el recull. Quan han tornat tots els rols, el resultat es deixa a la memòria cau de la
 * prova i la pàgina de l'editor el recull com qualsevol altra prova. No es desa cap execució.
 */
class AgentEditorTests
{
    public function __construct(private VariableResolver $resolver) {}

    /**
     * Rols de "# hosts:" que l'alumne no té en cap instància activa.
     *
     * @return list<string>
     */
    public static function missingRoles(Student $student, string $code): array
    {
        $roles = CodeHeaders::parse($code)->hosts;
        $active = AgentInstance::active()->where('alumne_id', $student->id)->whereIn('hostname', $roles)->pluck('hostname')->all();

        return array_values(array_diff($roles, $active));
    }

    /**
     * Alumnes que no tenen cap instància activa per a algun rol del codi, amb els rols que els falten.
     *
     * @param  Collection<int, Student>  $students
     * @return Collection<int, string> "nom (rols)" de cada alumne sense instància
     */
    public static function studentsWithoutInstance(Collection $students, string $code): Collection
    {
        return $students
            ->map(fn (Student $student) => ($missing = self::missingRoles($student, $code))
                ? "{$student->nom_complet} (".implode(', ', $missing).')'
                : null)
            ->filter()
            ->values();
    }

    /**
     * Deixa la prova pendent per a l'agent. Retorna la fila de resultat si no es pot provar
     * (l'alumne no té la instància activa, o li falta una variable), o null si ha quedat a la cua.
     *
     * @param  'accio'|'check'  $tipus
     * @param  array<string, string>  $params
     * @return array<string, ?string>|null
     */
    public function enqueue(string $key, string $tipus, Module $module, Student $student, string $code, int $timeout, array $params = []): ?array
    {
        $roles = CodeHeaders::parse($code)->hosts;
        if ($missing = self::missingRoles($student, $code)) {
            return self::row('error', 'danger', 'L\'alumne no té cap instància activa amb el rol '.implode(', ', $missing),
                'Cal que l\'agent hi estigui en marxa (Inventari → Instàncies). Rols del codi: '.implode(', ', $roles).'.');
        }

        $builder = new ContextBuilder($this->resolver);
        try {
            $context = $builder->build($module, $student, Executor::Agent, $code, $timeout, $params);
        } catch (InvalidArgumentException $e) {
            return self::row('error', 'danger', $e->getMessage());
        } finally {
            $builder->cleanup();
        }

        $given = array_intersect_key($context->extraVars, $params);
        $extra = [
            'parametres' => $given ? collect($given)->map(fn ($v, $k) => "{$k}={$v}")->implode(' ') : null,
            'comanda' => CommandPreview::describe(Executor::Agent, $context),
        ];
        foreach ($roles as $rol) {
            AgentEditorTest::create([
                'clau' => $key,
                'tipus' => $tipus,
                'alumne_id' => $student->id,
                'rol_agent' => $rol,
                'estat' => 'pendent',
                'payload_agent' => ['code' => $context->code, 'env' => $context->env, 'timeout' => $timeout],
                'mascara_agent' => $context->mask ?: null,
                'extra' => $extra,
                'caduca_a' => now()->addSeconds((int) config('heimdeye.agent.test_expiry_seconds', 120)),
            ]);
        }

        return null;
    }

    /** Desa el resultat que torna l'agent i, si era l'últim rol de la prova, el publica a la pàgina. */
    public function record(AgentEditorTest $test, int $exit, string $output, bool $timedOut, int $durationMs = 0): void
    {
        $mask = $test->mascara_agent ?? [];
        if ($test->tipus === 'check') {
            $result = AgentResult::check($exit, $output, $timedOut, $durationMs)->masked($mask);
            $row = self::row($result->status->value, $result->status->getColor(), $result->message, $result->output);
        } else {
            $status = AgentResult::action($exit, $output, $timedOut);
            $row = self::row($status->value, $status->getColor(), Masker::mask(AgentResult::message($output), $mask), AgentResult::maskedOutput($output, $mask));
        }

        $test->update(['estat' => 'feta', 'resultat' => $row]);
        $this->publish($test->clau);
    }

    /**
     * Tanca com a "no disponible" les proves que ningú no ha recollit a temps i, si se'n dona una
     * instància, les que aquella instància tenia en curs.
     */
    public function expire(?int $instanceId = null): int
    {
        $query = $instanceId === null
            ? AgentEditorTest::query()->where('estat', 'pendent')->whereNotNull('caduca_a')->where('caduca_a', '<=', now())
            : AgentEditorTest::query()->where('instancia_id', $instanceId)->where('estat', 'enviada');
        $message = $instanceId === null
            ? 'Cap instància no ha recollit la prova a temps'
            : 'La instància ha deixat de respondre abans de tornar el resultat';

        $tests = $query->get();
        foreach ($tests as $test) {
            $status = $test->tipus === 'check' ? CheckStatus::Unavailable : ActionStatus::Caducada;
            $test->update(['estat' => 'caducada', 'resultat' => self::row($status->value, $status->getColor(), $message)]);
            $this->publish($test->clau);
        }

        return $tests->count();
    }

    /** Quan ja no queda cap rol pendent de la prova, en deixa el resultat a la memòria cau. */
    private function publish(string $key): void
    {
        $tests = AgentEditorTest::where('clau', $key)->orderBy('id')->get();
        if ($tests->isEmpty() || $tests->contains(fn (AgentEditorTest $t) => in_array($t->estat, ['pendent', 'enviada'], true))) {
            return;
        }

        $results = $tests->map(fn (AgentEditorTest $t) => [...($t->resultat ?? []), 'rol' => $t->rol_agent]);
        $ok = ['ok', 'changed', 'pass', 'omes'];
        $main = $results->first(fn (array $r) => ! in_array($r['estat'] ?? null, $ok, true)) ?? $results->first();
        $several = $results->count() > 1;

        Cache::put(RunEditorTest::cacheKey($key), [
            'estat' => $main['estat'] ?? 'error',
            'color' => $main['color'] ?? RunEditorTest::hex('danger'),
            'resum' => $several
                ? $results->map(fn (array $r) => "{$r['rol']}: ".($r['resum'] ?? $r['estat']))->implode(' · ')
                : ($main['resum'] ?? null),
            'parametres' => $tests->first()->extra['parametres'] ?? null,
            'comanda' => $tests->first()->extra['comanda'] ?? null,
            'sortida' => $several
                ? $results->map(fn (array $r) => "== {$r['rol']} ==\n".($r['sortida'] ?? ''))->implode("\n\n")
                : ($main['sortida'] ?? null),
            'hora' => now()->format('H:i:s'),
        ], now()->addHours(2));

        AgentEditorTest::where('clau', $key)->delete();
    }

    /** @return array<string, ?string> fila de resultat de la prova */
    private static function row(string $estat, string $color, ?string $resum, ?string $sortida = null): array
    {
        return [
            'estat' => $estat,
            'color' => RunEditorTest::hex($color),
            'resum' => $resum,
            'parametres' => null,
            'comanda' => null,
            'sortida' => $sortida,
        ];
    }
}
