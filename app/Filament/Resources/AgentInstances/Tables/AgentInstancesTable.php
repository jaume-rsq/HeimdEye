<?php

namespace App\Filament\Resources\AgentInstances\Tables;

use App\Enums\Executor;
use App\Filament\Pages\AgentConsolePage;
use App\Models\AcademicYear;
use App\Models\AgentInstance;
use App\Models\Check;
use App\Models\Group;
use App\Models\Module;
use App\Models\Student;
use App\Runner\CheckRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Instàncies efímeres del curs actual amb l'alumne propietari. Com que es donen de baixa soles,
 * no cal filtrar: es veuen totes les del curs. Des de cada fila s'obre la consola o s'hi llança
 * un check d'agent d'aquell alumne.
 */
class AgentInstancesTable
{
    public static function configure(Table $table): Table
    {
        $currentYear = AcademicYear::where('es_actual', true)->value('id');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('student')
                ->when($currentYear, fn (Builder $q) => $q
                    ->whereHas('student', fn (Builder $s) => $s->where('curs_id', $currentYear))))
            ->defaultSort('darrer_batec', 'desc')
            // Curs actual fix; el grup i el mòdul, com a les altres llistes.
            ->filters([
                SelectFilter::make('grup')
                    ->label('Grup')
                    ->options(fn () => Group::where('curs_id', $currentYear)->orderBy('codi')->get()->mapWithKeys(fn (Group $g) => [$g->id => $g->label()]))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn (Builder $q, $id) => $q->whereHas('student', fn (Builder $s) => $s->where('grup_id', $id)))),
                SelectFilter::make('modul')
                    ->label('Mòdul')
                    ->options(fn () => Module::where('curs_id', $currentYear)->orderBy('codi')->get()->mapWithKeys(fn (Module $m) => [$m->id => $m->label()]))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn (Builder $q, $id) => $q->whereHas('student.modules', fn (Builder $m) => $m->whereKey($id)))),
            ])
            ->poll('10s')
            ->emptyStateHeading('Cap instància registrada')
            ->emptyStateDescription('Les instàncies apareixen aquí quan un alumne hi instal·la i engega l\'agent.')
            ->emptyStateIcon(Heroicon::OutlinedServerStack)
            ->columns([
                TextColumn::make('hostname')
                    ->label('Instància')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('student.nom_complet')
                    ->label('Alumne')
                    ->formatStateUsing(fn (AgentInstance $record) => $record->student
                        ? sprintf('%02d · %s', $record->student->num_taula, $record->student->nom_complet)
                        : '—')
                    ->searchable(['nom_complet', 'usuari']),
                TextColumn::make('estat')
                    ->label('Estat')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'activa' ? 'Activa' : 'Inactiva')
                    ->color(fn (string $state) => $state === 'activa' ? 'success' : 'gray'),
                TextColumn::make('ip_publica')
                    ->label('IP pública')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('sistema')
                    ->label('Sistema')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('versio_agent')
                    ->label('Agent')
                    ->prefix('v')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('darrer_batec')
                    ->label('Darrer batec')
                    ->since()
                    ->dateTimeTooltip('d/m/y H:i:s')
                    ->placeholder('mai')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('consola')
                    ->label('Obrir consola')
                    ->icon(Heroicon::OutlinedCommandLine)
                    ->color('primary')
                    ->disabled(fn (AgentInstance $record) => ! $record->isActive())
                    ->tooltip(fn (AgentInstance $record) => $record->isActive()
                        ? null
                        : 'La instància no està activa ara mateix.')
                    ->action(function (AgentInstance $record, Action $action) {
                        $session = AgentConsolePage::openFor($record->loadMissing('student'));
                        $action->redirect(AgentConsolePage::getUrl(['instancia' => $session->instancia_id]));
                    }),
                Action::make('check')
                    ->label('Llançar check')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('warning')
                    ->modalHeading(fn (AgentInstance $record) => 'Llançar un check d\'agent a '.$record->hostname)
                    ->modalDescription(fn (AgentInstance $record) => 'S\'encua per a '.($record->student?->nom_complet ?? 'l\'alumne').'. L\'agent el recull i n\'executa la versió vigent; el resultat compta com el de la nit.')
                    ->modalSubmitActionLabel('Llançar')
                    ->disabled(fn (AgentInstance $record) => self::agentChecks($record)->isEmpty())
                    ->tooltip(fn (AgentInstance $record) => self::agentChecks($record)->isEmpty()
                        ? 'L\'alumne no té cap check d\'agent actiu.'
                        : null)
                    ->schema(fn (AgentInstance $record) => [
                        Select::make('check')
                            ->label('Check')
                            ->options(self::agentChecks($record)
                                ->mapWithKeys(fn (Check $check) => [$check->id => $check->positionLabel().' · '.$check->nom])
                                ->all())
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data, AgentInstance $record, Action $action) {
                        $check = Check::with(['currentVersion', 'task.block.module'])->find($data['check']);
                        $student = $record->student;
                        if ($check === null || $student === null) {
                            Notification::make()->danger()->title('No s\'ha trobat el check o l\'alumne')->send();
                            $action->halt();
                        }

                        try {
                            app(CheckRunner::class)->launch($check, Student::whereKey($student->id)->get(), auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }

                        Notification::make()
                            ->success()
                            ->title('Check a la cua')
                            ->body("{$check->nom} per a {$student->nom_complet}. El resultat surt a l'historial del check quan l'agent l'executa.")
                            ->send();
                    }),
            ]);
    }

    /**
     * Checks d'agent actius de l'alumne de la instància: els de l'executor agent, actius avui,
     * dels mòduls als quals l'alumne està inscrit.
     *
     * @return Collection<int, Check>
     */
    private static function agentChecks(AgentInstance $instance): Collection
    {
        $moduleIds = $instance->student?->modules()->pluck('modul_alumne.modul_id')->all() ?? [];
        if ($moduleIds === []) {
            return collect();
        }

        return Check::query()
            ->where('executor', Executor::Agent)
            ->activeOn()
            ->whereHas('task.block', fn (Builder $q) => $q->whereIn('modul_id', $moduleIds))
            ->with('task.block.module')
            ->orderByPosition()
            ->get();
    }
}
