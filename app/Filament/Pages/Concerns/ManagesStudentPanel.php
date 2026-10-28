<?php

namespace App\Filament\Pages\Concerns;

use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use App\Models\Variable;
use App\Support\StudentInventory;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Panell lateral d'un alumne a l'Inventari: què rep i d'on surt (variables amb la cadena de
 * nivells, equips amb l'accés, inventari Ansible i activitat). Les variables pròpies de l'alumne,
 * les excepcions, s'editen des d'aquí.
 */
trait ManagesStudentPanel
{
    protected function panelStudent(array $arguments): ?Student
    {
        return $this->module?->students()->with('user')->find($arguments['alumne'] ?? null);
    }

    public function canEditStudent(Student $student): bool
    {
        return (bool) auth()->user()?->can('update', $student);
    }

    /** @return array<string, mixed>|null */
    public function studentPanel(int $studentId): ?array
    {
        $student = $this->panelStudent(['alumne' => $studentId]);

        return $student ? ['student' => $student, ...app(StudentInventory::class)->build($student, $this->module)] : null;
    }

    public function studentAction(): Action
    {
        return Action::make('student')
            ->slideOver()
            ->modalWidth('5xl')
            ->modalHeading(fn (array $arguments) => ($s = $this->panelStudent($arguments)) ? sprintf('%02d · %s', $s->num_taula, $s->nom_complet) : 'Alumne')
            ->modalDescription(fn (array $arguments) => ($s = $this->panelStudent($arguments)) ? "{$s->usuari} · ".trim("{$this->module->codi} {$this->module->nom}") : null)
            ->modalContent(fn (array $arguments) => view('filament.partials.inventory-student', ['studentId' => (int) ($arguments['alumne'] ?? 0)]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tancar')
            // S'obre des del panell, a sobre seu.
            ->registerModalActions([$this->editStudentVariableAction()])
            ->extraModalFooterActions(fn (array $arguments) => [
                Action::make('studentSheet')
                    ->label('Fitxa de l\'alumne')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->color('gray')
                    ->url(StudentResource::getUrl('edit', ['record' => $arguments['alumne'] ?? 0, 'modul' => $this->modul])),
            ]);
    }

    /** Afegeix o canvia una variable pròpia d'un alumne (args: alumne i clau; sense clau, se'n tria una de nova). */
    public function editStudentVariableAction(): Action
    {
        return Action::make('editStudentVariable')
            ->overlayParentActions()
            ->authorize(fn (array $arguments) => ($s = $this->panelStudent($arguments)) && $this->canEditStudent($s))
            ->modalHeading(fn (array $arguments) => filled($arguments['clau'] ?? null)
                ? "{$arguments['clau']} · pròpia de l'alumne"
                : 'Nova variable pròpia de l\'alumne')
            ->modalDescription('Només per a aquest alumne: tapa el valor global, del curs i del mòdul amb el mateix nom.')
            ->fillForm(fn (array $arguments) => [
                'clau' => $arguments['clau'] ?? null,
                'valor' => $this->panelStudent($arguments)?->variables[$arguments['clau'] ?? ''] ?? null,
            ])
            ->schema(fn (array $arguments) => [
                TextInput::make('clau')
                    ->label('Variable')
                    ->required()
                    ->regex(Variable::KEY_RULE)
                    ->visible(blank($arguments['clau'] ?? null))
                    ->rules([fn () => $this->notOwnYet($arguments)]),
                TextInput::make('valor')
                    ->label('Valor')
                    ->required()
                    ->live(onBlur: true)
                    ->helperText('Un valor o una plantilla: {{ xarxa_aula | host(5) }}'),
                TextEntry::make('resultat')
                    ->label('Resultat')
                    ->state(fn (Get $get) => filled($key = $arguments['clau'] ?? $get('clau'))
                        ? $this->previewValue($key, 'propia', $arguments['alumne'], (string) $get('valor'))
                        : '—')
                    ->fontFamily('mono'),
            ])
            ->action(function (array $data, array $arguments) {
                $student = $this->panelStudent($arguments);
                $key = $arguments['clau'] ?? $data['clau'];
                $student->update(['variables' => [...($student->variables ?? []), $key => $data['valor']]]);
                $this->afterStudentChange("Desada {$key} per a {$student->usuari}");
            })
            ->extraModalFooterActions(fn (array $arguments) => array_key_exists($arguments['clau'] ?? '', $this->panelStudent($arguments)?->variables ?? []) ? [
                Action::make('deleteStudentVariable')
                    ->label('Treure')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('L\'alumne tornarà a rebre el valor del mòdul, del curs o el global, si n\'hi ha.')
                    ->action(function () use ($arguments) {
                        $student = $this->panelStudent($arguments);
                        $vars = $student->variables ?? [];
                        unset($vars[$arguments['clau']]);
                        $student->update(['variables' => $vars ?: null]);
                        $this->afterStudentChange("Treta {$arguments['clau']} de {$student->usuari}");
                    })
                    ->cancelParentActions('editStudentVariable'),
            ] : []);
    }

    private function notOwnYet(array $arguments): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($arguments) {
            if (array_key_exists($value, $this->panelStudent($arguments)?->variables ?? [])) {
                $fail('L\'alumne ja té aquesta variable pròpia.');
            }
        };
    }

    protected function afterStudentChange(string $message): void
    {
        unset($this->grid);
        Notification::make()->success()->title($message)->send();
    }
}
