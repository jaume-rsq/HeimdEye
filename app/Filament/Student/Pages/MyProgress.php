<?php

namespace App\Filament\Student\Pages;

use App\Filament\Pages\StudentTracking;
use App\Models\AcademicYear;
use App\Models\Check;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;

/**
 * El seguiment de l'alumne connectat: la mateixa pantalla que la dels professors, però sempre amb el
 * seu alumne i sense corregir ni editar res. Només pot verificar els checks actius, d'un en un.
 */
class MyProgress extends StudentTracking
{
    /** Temps mínim entre dues verificacions del mateix check per part de l'alumne. */
    public const VERIFY_EVERY = 600;

    protected static ?string $slug = 'seguiment';

    protected static ?string $title = 'El meu seguiment';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    public function mount(): void
    {
        $this->alumne = $this->ownStudent()?->id;
        parent::mount();
    }

    public function updatingAlumne(): void
    {
        abort(403);
    }

    public function readonly(): bool
    {
        return true;
    }

    private function ownStudent(): ?Student
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->student;
    }

    /** Els mòduls en què està matriculat, primer els del curs actual. */
    #[Computed]
    public function modules(): Collection
    {
        $current = AcademicYear::where('es_actual', true)->value('id');

        return ($this->ownStudent()?->modules()->with('academicYear')->get() ?? collect())
            ->sortBy(fn (Module $m) => [$m->curs_id === $current ? 0 : 1, $m->codi, $m->nom])
            ->values();
    }

    #[Computed]
    public function students(): Collection
    {
        return collect([$this->ownStudent()])->filter()->values();
    }

    public function canEdit(Check $check): bool
    {
        return false;
    }

    /**
     * L'alumne només pot verificar un check actiu, d'un en un i per a ell mateix, i el mateix check
     * com a molt un cop cada VERIFY_EVERY segons. El compte és a la memòria cau de Laravel, que caduca
     * sola (cap taula ni camp nou), i no a la sessió: amb un altre navegador no se'l salta.
     */
    public function verify(?int $checkId = null, ?int $taskId = null, ?int $blockId = null): void
    {
        $check = $checkId && ! $taskId && ! $blockId ? $this->visibleCheck(['check' => $checkId]) : null;
        abort_unless($check && $this->canVerify($check), 403);

        if ($minutes = $this->verifyWaitMinutes($check)) {
            Notification::make()->warning()
                ->title('Encara no')
                ->body("Podràs tornar a verificar aquest check d'aquí a {$minutes} min.")
                ->send();

            return;
        }
        RateLimiter::hit($this->verifyKey($check), self::VERIFY_EVERY);

        parent::verify($check->id);
    }

    public function canVerify(Check $check): bool
    {
        return $check->isActiveOn();
    }

    public function verifyWaitMinutes(Check $check): int
    {
        $key = $this->verifyKey($check);

        return RateLimiter::tooManyAttempts($key, 1) ? (int) ceil(RateLimiter::availableIn($key) / 60) : 0;
    }

    private function verifyKey(Check $check): string
    {
        return "verificar-alumne:{$this->ownStudent()?->id}:{$check->id}";
    }

    public function go(int $step): void {}

    public function correctAction(): Action
    {
        return parent::correctAction()->hidden();
    }
}
