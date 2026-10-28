<?php

namespace App\Jobs;

use App\Models\Module;
use App\Models\Student;
use App\Support\ConnectionTester;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Prova de connexió de la vista de conjunt. Va per la cua, com les proves de l'editor, i deixa
 * el resultat a la memòria cau amb la clau $key perquè la pàgina el reculli. No es desa enlloc.
 */
class TestConnection implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  list<int>  $studentIds
     * @param  list<int>  $typeIds
     */
    public function __construct(
        public string $key,
        public int $moduleId,
        public array $studentIds,
        public array $typeIds,
    ) {
        $this->onQueue(config('heimdeye.runner.queue'));
        // Una execució d'Ansible per tipus de connexió (ssh, winrm, httpapi), una darrere l'altra.
        $this->timeout = 3 * ConnectionTester::timeout(max(1, count($studentIds)) * count($typeIds)) + 30;
    }

    public static function cacheKey(string $key): string
    {
        return "heimdeye:connexio:{$key}";
    }

    public function handle(ConnectionTester $tester): void
    {
        $module = Module::findOrFail($this->moduleId);
        $students = Student::whereKey($this->studentIds)->orderBy('num_taula')->get();
        $types = $module->visibleHostTypes()->whereIn('id', $this->typeIds)->values();

        Cache::put(self::cacheKey($this->key), ['resultats' => $tester->test($module, $students, $types)], now()->addHour());
    }

    public function failed(?Throwable $e): void
    {
        Cache::put(self::cacheKey($this->key), ['error' => "No s'ha pogut fer la prova: ".($e?->getMessage() ?: 'error desconegut')], now()->addHour());
    }
}
