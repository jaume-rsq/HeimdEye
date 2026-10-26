<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Models\AcademicYear;
use App\Models\Module;
use App\Models\Student;
use App\Support\StudentImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * Importar la llista de l'aula: enganxar-la o arrossegar el fitxer, previsualitzar
 * els canvis i, només si es confirma, aplicar-los.
 */
class ImportStudents extends Page
{
    protected static string $resource = StudentResource::class;

    protected static ?string $title = 'Importar alumnes';

    protected string $view = 'filament.pages.import-students';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Resum de la previsualització, per mostrar-lo (null = encara no s'ha previsualitzat). */
    public ?array $preview = null;

    public ?string $error = null;

    public function mount(): void
    {
        $year = AcademicYear::where('es_actual', true)->first();
        $this->form->fill([
            'modul_id' => Module::query()->when($year, fn ($q) => $q->where('curs_id', $year->id))->orderBy('codi')->value('id'),
            'desactivar' => true,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->schema([
                        Select::make('modul_id')
                            ->label('Mòdul')
                            ->options(fn () => Module::with('academicYear')->orderBy('codi')->get()
                                ->mapWithKeys(fn (Module $m) => [$m->id => trim("{$m->codi} {$m->nom}").' · '.$m->academicYear?->nom]))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetPreview()),
                        View::make('filament.import-dropzone'),
                        Textarea::make('text')
                            ->label('Llista')
                            ->helperText('Capçalera: '.implode(', ', StudentImporter::COLUMNS).'. Separada per comes o tabuladors (es pot enganxar directament del full de càlcul).')
                            ->rows(10)
                            ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: .8rem'])
                            ->required()
                            ->live(debounce: 500)
                            ->afterStateUpdated(fn () => $this->resetPreview()),
                        Toggle::make('desactivar')
                            ->label('Marcar inactius els alumnes del mòdul que no surten a la llista')
                            ->helperText('No s\'esborra mai cap alumne ni cap resultat.'),
                        Actions::make([
                            Action::make('previsualitzar')
                                ->label('Previsualitzar')
                                ->icon(Heroicon::OutlinedEye)
                                ->color('gray')
                                ->action(fn () => $this->previewImport()),
                            Action::make('importar')
                                ->label('Importar')
                                ->icon(Heroicon::OutlinedArrowDownTray)
                                ->color('primary')
                                ->disabled(fn () => $this->preview === null)
                                ->requiresConfirmation()
                                ->modalDescription(fn () => $this->preview ? $this->confirmText() : null)
                                ->action(fn () => $this->import()),
                        ]),
                    ]),
            ]);
    }

    private function resetPreview(): void
    {
        $this->preview = null;
        $this->error = null;
    }

    /** @return array{0: Module, 1: list<array<string, string>>}|null */
    private function input(): ?array
    {
        $data = $this->form->getState();
        $module = Module::find($data['modul_id']);
        try {
            return [$module, app(StudentImporter::class)->parse($data['text'] ?? '')];
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return null;
        }
    }

    public function previewImport(): void
    {
        $this->resetPreview();
        if (! [$module, $rows] = $this->input()) {
            return;
        }

        try {
            $plan = app(StudentImporter::class)->plan($module, $rows);
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $label = fn (array|Student $s) => sprintf('%02d · %s (%s)', $s['num_taula'], $s['nom_complet'], $s['usuari']);
        $this->preview = [
            'nous' => array_map($label, $plan['nous']),
            'canvis' => array_map(fn (array $c) => [
                'alumne' => $label($c['student']),
                'diferencies' => array_map(fn (array $d) => [(string) ($d[0] ?? '—'), (string) $d[1]], $c['diferencies']),
            ], $plan['canvis']),
            'iguals' => $plan['iguals'],
            'absents' => array_map($label, $plan['absents']),
        ];
    }

    private function confirmText(): string
    {
        $parts = [count($this->preview['nous']).' alumnes nous', count($this->preview['canvis']).' amb canvis'];
        if (($this->data['desactivar'] ?? false) && $this->preview['absents']) {
            $parts[] = count($this->preview['absents']).' passaran a inactius';
        }

        return 'S\'aplicaran els canvis de la previsualització: '.implode(', ', $parts).'.';
    }

    public function import(): void
    {
        if (! [$module, $rows] = $this->input()) {
            return;
        }

        $result = app(StudentImporter::class)->apply($module, $rows, (bool) ($this->data['desactivar'] ?? false));
        $this->resetPreview();

        Notification::make()
            ->success()
            ->title('Alumnes importats')
            ->body("{$result['nous']} nous, {$result['canvis']} actualitzats, {$result['desactivats']} marcats inactius.")
            ->send();
    }
}
