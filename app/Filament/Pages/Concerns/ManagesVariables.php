<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\Level;
use App\Models\Student;
use App\Models\Variable;
use App\Support\InventoryGrid;
use App\Support\VariableResolver;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Attributes\Computed;
use Throwable;

/**
 * Pestanya Variables de l'Inventari: una fila per variable amb el valor de cada nivell.
 * Una casella s'edita (o es crea) al seu nivell; el valor que mana és el del nivell més concret.
 */
trait ManagesVariables
{
    /** @return array<string, array<string, array<string, Variable>>> [grup => [variable => [nivell => registre]]] */
    #[Computed]
    public function variableMatrix(): array
    {
        $rows = $this->byLevel($this->atLevels(Variable::query())->get());
        $groups = [];
        foreach (InventoryGrid::groups(array_keys($rows)) as $label => $keys) {
            foreach ($keys as $key) {
                $groups[$label][$key] = $rows[$key];
            }
        }

        return $groups;
    }

    public function canEditVariables(): bool
    {
        return (bool) auth()->user()?->can('create', Variable::class);
    }

    protected function variableAt(string $key, Level $level): ?Variable
    {
        return $this->atLevels(Variable::query())->where('nivell', $level)->where('clau', $key)->first();
    }

    public function editVariableAction(): Action
    {
        return Action::make('editVariable')
            ->authorize(fn () => $this->canEditVariables())
            ->modalHeading(fn (array $arguments) => $arguments['clau'].' · '.$this->levelLabel(Level::from($arguments['nivell'])))
            ->modalDescription(fn (array $arguments) => $this->reach(Level::from($arguments['nivell'])))
            ->modalWidth('2xl')
            ->fillForm(fn (array $arguments) => [
                ...($this->variableAt($arguments['clau'], Level::from($arguments['nivell']))?->only(['valor', 'descripcio']) ?? []),
                'alumne_prova' => $this->previewStudents()->keys()->first(),
            ])
            ->schema(fn (array $arguments) => [
                TextInput::make('valor')
                    ->label('Valor o plantilla')
                    ->required()
                    ->live(onBlur: true)
                    ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace'])
                    ->helperText('Pot fer servir altres variables: 172.{'.'{x}}.0.0/22, {'.'{ xarxa_aula | host(3) }}, {'.'{ ip_pve | octet(4) }}.'),
                TextInput::make('descripcio')->label('Descripció'),
                ...$this->previewFields($arguments['clau'], Level::from($arguments['nivell'])),
            ])
            ->action(function (array $data, array $arguments) {
                $level = Level::from($arguments['nivell']);
                Variable::updateOrCreate(
                    [...$this->levelAttributes($level), 'clau' => $arguments['clau']],
                    ['valor' => $data['valor'], 'descripcio' => $data['descripcio'] ?? null],
                );
                unset($this->variableMatrix, $this->grid);
                Notification::make()->success()->title("Desada {$arguments['clau']} ({$this->levelLabel($level)})")->send();
                $this->warnBrokenTemplates($level);
            })
            ->extraModalFooterActions(fn (array $arguments) => $this->variableAt($arguments['clau'], Level::from($arguments['nivell'])) ? [
                Action::make('deleteVariable')
                    ->label('Esborrar d\'aquest nivell')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Si un altre nivell la té, passa a manar aquell valor; si no, el codi que la fa servir donarà error.')
                    ->action(function () use ($arguments) {
                        $level = Level::from($arguments['nivell']);
                        $this->variableAt($arguments['clau'], $level)?->delete();
                        unset($this->variableMatrix, $this->grid);
                        Notification::make()->success()->title("Esborrada {$arguments['clau']} ({$this->levelLabel($level)})")->send();
                        $this->warnBrokenTemplates($level);
                    })
                    ->cancelParentActions(),
            ] : []);
    }

    public function createVariableAction(): Action
    {
        return Action::make('createVariable')
            ->label('Nova variable')
            ->icon('heroicon-o-plus')
            ->authorize(fn () => $this->canEditVariables())
            ->modalWidth('2xl')
            ->fillForm(fn () => ['nivell' => collect($this->levels())->last()->value])
            ->schema([
                Select::make('nivell')->options(fn () => $this->levelOptions())->required()->live(),
                TextInput::make('clau')
                    ->label('Variable')
                    ->required()
                    ->regex(Variable::KEY_RULE)
                    ->helperText('Prefixos: xarxa_, ip_, nom_, num_.')
                    ->rules([fn (Get $get) => $this->uniqueAtLevel(Variable::class, $get('nivell'))]),
                TextInput::make('valor')->label('Valor o plantilla')->required()->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace']),
                TextInput::make('descripcio')->label('Descripció'),
            ])
            ->action(function (array $data) {
                $level = Level::from($data['nivell']);
                Variable::create([...$this->levelAttributes($level), 'clau' => $data['clau'], 'valor' => $data['valor'], 'descripcio' => $data['descripcio'] ?? null]);
                unset($this->variableMatrix, $this->grid);
                Notification::make()->success()->title("Creada {$data['clau']} ({$this->levelLabel($level)})")->send();
                $this->warnBrokenTemplates($level);
            });
    }

    /** Un nom només pot sortir una vegada a cada nivell. */
    protected function uniqueAtLevel(string $model, ?string $level, string $column = 'clau'): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model, $level, $column) {
            $level = Level::tryFrom((string) $level);
            if ($level && $this->atLevels($model::query())->where('nivell', $level)->where($column, $value)->exists()) {
                $fail('Ja n\'hi ha un amb aquest nom en aquest nivell: edita\'l des de la seva casella.');
            }
        };
    }

    /** Alumnes per previsualitzar un valor (només amb un mòdul triat). */
    protected function previewStudents()
    {
        return $this->module?->students()->where('es_actiu', true)->orderBy('num_taula')->get()
            ->mapWithKeys(fn (Student $s) => [$s->id => sprintf('%02d · %s', $s->num_taula, $s->nom_complet)]) ?? collect();
    }

    /** Com queda la variable per a un alumne amb el valor del formulari, sense desar-lo. */
    protected function previewFields(string $key, Level $level): array
    {
        if ($this->module === null) {
            return [];
        }

        return [
            Select::make('alumne_prova')
                ->label('Previsualitzar per a')
                ->options(fn () => $this->previewStudents()->all())
                ->dehydrated(false)
                ->live(),
            TextEntry::make('resultat')
                ->label('Resultat')
                ->state(fn (Get $get) => $this->previewValue($key, $level, $get('alumne_prova'), (string) $get('valor')))
                ->fontFamily('mono'),
        ];
    }

    /** El valor que rebria l'alumne si la variable tingués aquest valor en aquest nivell (o com a pròpia: 'propia'). */
    public function previewValue(string $key, Level|string $level, mixed $studentId, string $value): string
    {
        $level = $level instanceof Level ? $level->value : $level;
        $student = $this->module ? Student::with('user')->find($studentId) : null;
        if ($student === null) {
            return '—';
        }
        try {
            $var = app(VariableResolver::class)->detailed($student, $this->module, [$level => [$key => $value]])[$key] ?? null;
        } catch (Throwable $e) {
            return 'No s\'ha pogut calcular: '.$e->getMessage();
        }
        if ($var === null) {
            return '—';
        }
        if ($var['error'] !== null) {
            return 'No s\'ha pogut calcular: '.$var['error'];
        }
        $origins = ['global' => 'global', 'curs' => 'del curs', 'modul' => 'del mòdul', 'alumne' => 'camp de l\'alumne', 'propia' => 'propi de l\'alumne'];

        return $var['valor'].($var['origen'] !== $level ? " (mana el valor {$origins[$var['origen']]}, no aquest)" : '');
    }
}
