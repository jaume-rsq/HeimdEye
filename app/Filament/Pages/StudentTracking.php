<?php

namespace App\Filament\Pages;

use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\Check;
use App\Models\CheckStudent;
use App\Models\Module;
use App\Models\Student;
use App\Models\Task;
use App\Models\User;
use App\Runner\CheckRunner;
use App\Support\Leaderboard;
use App\Support\StudentProgress;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Seguiment d'un alumne: una targeta per mòdul i, del mòdul triat, cada check agrupat per bloc i
 * tasca amb l'estat, la darrera execució i els canvis d'estat. Des d'aquí es verifica ara i es
 * corregeix l'estat a mà. Un professor només hi veu els mòduls que imparteix; un administrador, tots.
 */
class StudentTracking extends Page
{
    protected static ?string $slug = 'seguiment';

    protected static ?string $title = 'Seguiment';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = -9;

    protected string $view = 'filament.pages.student-tracking';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url]
    public ?int $alumne = null;

    #[Url]
    public ?int $modul = null;

    #[Url]
    public ?int $bloc = null;

    #[Url]
    public ?int $tasca = null;

    #[Url]
    public ?string $estat = null;

    #[Url]
    public bool $obligatoris = false;

    public function mount(): void
    {
        $modules = $this->modules();
        if ($this->modul !== null && ! $modules->contains('id', $this->modul)) {
            $this->modul = null;
        }
        // Una tasca a la URL porta el seu mòdul.
        if ($this->tasca && ($task = Task::with('block')->find($this->tasca)) && $modules->contains('id', $task->block->modul_id)) {
            $this->modul ??= $task->block->modul_id;
        }
        $students = $this->students();
        if ($this->alumne === null || ! $students->contains('id', $this->alumne)) {
            $this->alumne = $students->first()?->id;
        }
        $this->ensureModule();
    }

    public function updatedAlumne(): void
    {
        $this->ensureModule();
    }

    public function updatedModul(): void
    {
        $this->bloc = null;
        $this->tasca = null;
    }

    public function updatedBloc(): void
    {
        $this->tasca = null;
    }

    /** El mòdul triat ha de ser un dels de l'alumne; si no, el primer que tingui. */
    private function ensureModule(): void
    {
        $ids = $this->studentModules()->pluck('id');
        if ($this->modul === null || ! $ids->contains($this->modul)) {
            $this->modul = $ids->first();
            $this->bloc = null;
            $this->tasca = null;
        }
    }

    /**
     * Mòduls que pot veure l'usuari: tots per a un administrador, els que imparteix per a un professor.
     * Primer els del curs actual.
     *
     * @return Collection<int, Module>
     */
    #[Computed]
    public function modules(): Collection
    {
        /** @var User $user */
        $user = Auth::user();
        $current = AcademicYear::where('es_actual', true)->value('id');
        $query = $user->isAdmin() ? Module::query() : $user->modules();

        return $query->with('academicYear')->get()
            ->sortBy(fn (Module $m) => [$m->curs_id === $current ? 0 : 1, $m->codi, $m->nom])
            ->values();
    }

    /** @return Collection<int, Student> alumnes matriculats en algun dels mòduls visibles */
    #[Computed]
    public function students(): Collection
    {
        return Student::query()
            ->whereHas('modules', fn ($q) => $q->whereIn('moduls.id', $this->modules()->modelKeys()))
            ->orderByDesc('es_actiu')->orderBy('num_taula')->orderBy('nom_complet')
            ->get();
    }

    #[Computed]
    public function student(): ?Student
    {
        return $this->alumne ? $this->students()->firstWhere('id', $this->alumne) : null;
    }

    /** @return Collection<int, Module> mòduls visibles en què està matriculat l'alumne */
    #[Computed]
    public function studentModules(): Collection
    {
        $student = $this->student();
        if ($student === null) {
            return collect();
        }
        $enrolled = $student->modules()->pluck('moduls.id');

        return $this->modules()->filter(fn (Module $m) => $enrolled->contains($m->id))->values();
    }

    /** Resum de cada mòdul de l'alumne, tret del leaderboard (posició, punts, obligatoris, blocs). */
    #[Computed]
    public function cards(): array
    {
        return $this->studentModules()->map(function (Module $module) {
            $board = app(Leaderboard::class)->build($module);
            $row = collect($board['rows'])->firstWhere('student.id', $this->alumne);
            $last = $this->student()->runs()
                ->whereIn('check_id', Check::whereIn('tasca_id', $module->tasks()->select('tasques.id'))->select('id'))
                ->max('data_execucio');

            return ['module' => $module, 'board' => $board, 'row' => $row, 'alumnes' => count($board['rows']), 'darrera_execucio' => $last];
        })->all();
    }

    #[Computed]
    public function module(): ?Module
    {
        return $this->modul ? $this->studentModules()->firstWhere('id', $this->modul) : null;
    }

    #[Computed]
    public function progress(): array
    {
        if (! $this->student() || ! $this->module()) {
            return [];
        }

        return app(StudentProgress::class)->build($this->student(), $this->module(), [
            'bloc' => $this->bloc, 'tasca' => $this->tasca, 'estat' => $this->estat, 'obligatoris' => $this->obligatoris,
        ]);
    }

    /** @return Collection<int, Block> */
    #[Computed]
    public function blocks(): Collection
    {
        return $this->module()?->blocks()->with('tasks')->orderBy('numero')->get() ?? collect();
    }

    /** Alumne anterior i següent de la llista, per anar-hi sense perdre el filtre. */
    public function neighbour(int $step): ?int
    {
        $ids = $this->students()->pluck('id')->values();
        $i = $ids->search($this->alumne);

        return $i === false ? null : $ids->get($i + $step);
    }

    public function go(int $step): void
    {
        if ($id = $this->neighbour($step)) {
            $this->alumne = $id;
            $this->ensureModule();
        }
    }

    /** Verifica ara un check, els d'una tasca o els de tot el mòdul, per a aquest alumne. */
    public function verify(?int $checkId = null, ?int $taskId = null): void
    {
        $module = $this->module();
        $student = $this->student();
        if (! $module || ! $student) {
            return;
        }
        $checks = Check::query()
            ->whereIn('tasca_id', $module->tasks()->select('tasques.id'))
            ->when($checkId, fn ($q) => $q->whereKey($checkId))
            ->when($taskId, fn ($q) => $q->where('tasca_id', $taskId))
            ->with('currentVersion')
            ->get();

        $queued = 0;
        $errors = [];
        foreach ($checks as $check) {
            try {
                app(CheckRunner::class)->launch($check, collect([$student]), Auth::user());
                $queued++;
            } catch (InvalidArgumentException $e) {
                $errors[] = "{$check->nom}: {$e->getMessage()}";
            }
        }

        Notification::make()
            ->{$errors ? 'warning' : 'success'}()
            ->title($queued === 1 ? 'Check a la cua' : "{$queued} checks a la cua")
            ->body($errors ? implode("\n", $errors) : 'El resultat surt aquí quan acaba (recarrega la pàgina).')
            ->send();
        unset($this->progress, $this->cards);
    }

    /** Correcció del professor: Automàtic (sense correcció), Assolit o No assolit, amb un comentari. */
    public function correctAction(): Action
    {
        return Action::make('correct')
            ->label('Corregir')
            ->modalHeading(fn (array $arguments) => 'Corregir «'.Check::find($arguments['check'] ?? 0)?->nom.'»')
            ->modalDescription('L\'estat del professor mana sobre el resultat automàtic, també al leaderboard. El comentari el veurà l\'alumne.')
            ->fillForm(function (array $arguments) {
                $state = CheckStudent::where('check_id', $arguments['check'] ?? 0)->where('alumne_id', $this->alumne)->first();

                return [
                    'estat' => match ($state?->estat_professor) {
                        true => 'assolit',
                        false => 'no_assolit',
                        null => 'auto',
                    },
                    'comentari' => $state?->comentari_professor,
                ];
            })
            ->schema([
                Radio::make('estat')
                    ->options(['auto' => 'Automàtic (el resultat de les execucions)', 'assolit' => 'Assolit', 'no_assolit' => 'No assolit'])
                    ->required(),
                Textarea::make('comentari')->label('Comentari per a l\'alumne')->rows(3),
            ])
            ->action(function (array $data, array $arguments) {
                $check = Check::whereIn('tasca_id', $this->module()?->tasks()->select('tasques.id'))->findOrFail($arguments['check'] ?? 0);
                $state = CheckStudent::firstOrNew(['check_id' => $check->id, 'alumne_id' => $this->alumne]);
                $state->estat_professor = match ($data['estat']) {
                    'assolit' => true,
                    'no_assolit' => false,
                    default => null,
                };
                $state->comentari_professor = filled($data['comentari']) ? $data['comentari'] : null;
                $state->modificat_per = Auth::id();
                $state->save();

                Notification::make()->success()->title('Correcció desada')->send();
                unset($this->progress, $this->cards);
            });
    }
}
