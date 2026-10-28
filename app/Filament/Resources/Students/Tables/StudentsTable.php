<?php

namespace App\Filament\Resources\Students\Tables;

use App\Models\Module;
use App\Models\Student;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class StudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('num_taula')
            ->columns([
                TextColumn::make('num_taula')->label('Taula')->formatStateUsing(fn ($state) => sprintf('%02d', $state))->sortable(),
                TextColumn::make('usuari')->searchable()->sortable(),
                TextColumn::make('nom_complet')->label('Nom')->searchable()->sortable(),
                TextColumn::make('alies')->label('Àlies')->placeholder('—'),
                TextColumn::make('x')->label('X')->sortable(),
                TextColumn::make('modules.codi')->label('Mòduls')->badge(),
                TextColumn::make('own_variables_count')
                    ->label('Variables pròpies')
                    ->counts('ownVariables')
                    ->formatStateUsing(fn (int $state) => $state ?: '—'),
                IconColumn::make('es_actiu')->label('Actiu')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('es_actiu')->label('Actiu')->default(true),
                // Sense relationship(): Filament hi fa un "select distinct moduls.*" i Postgres no
                // sap comparar la columna json "combina".
                SelectFilter::make('modul')
                    ->label('Mòdul')
                    ->options(fn () => Module::orderBy('nom')->pluck('nom', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $query, $id) => $query->whereHas('modules', fn (Builder $query) => $query->whereKey($id)),
                    )),
                SelectFilter::make('curs_id')->label('Curs')->relationship('academicYear', 'nom'),
            ])
            ->recordActions([
                EditAction::make()->label('Fitxa'),
            ])
            ->toolbarActions([
                BulkAction::make('desactivar')
                    ->label('Marcar inactius')
                    ->icon(Heroicon::OutlinedPauseCircle)
                    ->requiresConfirmation()
                    ->modalDescription('Deixen de sortir a les execucions i al leaderboard. No s\'esborra res.')
                    ->action(fn (Collection $records) => Student::whereKey($records->modelKeys())->update(['es_actiu' => false]))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('activar')
                    ->label('Marcar actius')
                    ->icon(Heroicon::OutlinedPlayCircle)
                    ->action(fn (Collection $records) => Student::whereKey($records->modelKeys())->update(['es_actiu' => true]))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('esborrar')
                    ->label('Esborrar')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->authorize(fn () => auth()->user()?->can('deleteAny', Student::class))
                    ->modalHeading(fn (Collection $records) => 'Esborrar '.$records->count().' '.($records->count() === 1 ? 'alumne' : 'alumnes'))
                    ->modalDescription('S\'esborraran també totes les seves execucions de checks i accions i els seus assoliments. Si només han deixat el curs, millor marca\'ls com a inactius. No es pot desfer.')
                    ->modalSubmitActionLabel('Esborrar')
                    ->schema(fn (Collection $records) => [
                        Text::make(new HtmlString('<ul style="max-height: 14rem; overflow-y: auto; margin: 0; padding-left: 1.2rem; list-style: disc">'
                            .$records->sortBy('num_taula')->map(fn (Student $s) => '<li>'.e(sprintf('%02d', $s->num_taula).' · '.$s->usuari.' · '.$s->nom_complet).'</li>')->implode('')
                            .'</ul>')),
                        TextInput::make('confirmacio')
                            ->label('Escriu «esborrar» per confirmar')
                            ->required()
                            ->in(['esborrar'])
                            ->validationMessages(['in' => 'Escriu exactament «esborrar».'])
                            ->autocomplete(false),
                    ])
                    ->action(function (Collection $records) {
                        $records->each->delete();
                        Notification::make()->success()->title($records->count().' '.($records->count() === 1 ? 'alumne esborrat' : 'alumnes esborrats'))->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
