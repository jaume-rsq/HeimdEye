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
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * El seguiment de l'alumne connectat, de només lectura: la mateixa pantalla que la dels professors,
 * però sempre amb el seu alumne i sense verificar, corregir ni editar res.
 */
class MyProgress extends StudentTracking
{
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

    public function verify(?int $checkId = null, ?int $taskId = null, ?int $blockId = null): void
    {
        abort(403);
    }

    public function go(int $step): void {}

    public function correctAction(): Action
    {
        return parent::correctAction()->hidden();
    }
}
