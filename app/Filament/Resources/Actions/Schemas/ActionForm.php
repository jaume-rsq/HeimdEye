<?php

namespace App\Filament\Resources\Actions\Schemas;

use App\Enums\Executor;
use App\Models\Block;
use App\Models\Check;
use App\Models\Module;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Formulari d'una acció i de la seva darrera versió. En desar, els camps de la versió
 * (VERSION_FIELDS) creen una versió nova si han canviat; les versions no es modifiquen mai.
 */
class ActionForm
{
    public const VERSION_FIELDS = ['codi', 'parametres', 'temps_max_seg', 'checks_verificacio'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Acció')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nom')->required()->maxLength(255),
                        Select::make('modul_id')
                            ->label('Mòdul')
                            ->options(fn () => Module::with('academicYear')->get()->mapWithKeys(fn (Module $m) => [$m->id => "{$m->nom} ({$m->academicYear->nom})"]))
                            ->required()
                            ->live()
                            ->disabledOn('edit'),
                        Textarea::make('descripcio')->label('Descripció')->rows(2)->columnSpanFull(),
                        Select::make('executor')
                            ->options(collect(Executor::cases())->mapWithKeys(fn (Executor $e) => [$e->value => $e->value]))
                            ->default(Executor::Ansible->value)
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->helperText('No es pot canviar un cop creada l\'acció.'),
                        Select::make('bloc_id')
                            ->label('Bloc')
                            ->options(fn (Get $get) => Block::where('modul_id', $get('modul_id'))->orderBy('numero')->get()
                                ->mapWithKeys(fn (Block $b) => [$b->id => "Bloc {$b->numero}: {$b->titol}"]))
                            ->placeholder('Cap'),
                        TextInput::make('posicio')->label('Posició')->numeric()->default(0)->minValue(0),
                    ]),
                Section::make(fn (string $operation) => $operation === 'edit' ? 'Darrera versió' : 'Primera versió')
                    ->description(fn (string $operation) => $operation === 'edit'
                        ? 'Si canvies algun d\'aquests camps, en desar es crea una versió nova.'
                        : null)
                    ->schema([
                        CodeEditor::make('codi')
                            ->label('Codi')
                            ->language(fn (Get $get) => $get('executor') === Executor::Ansible->value ? Language::Yaml : null)
                            ->required()
                            ->helperText('Capçaleres opcionals a dalt de tot: "# hosts: pve, vm_router" (ssh) i "# credencial: Nom" (local).'),
                        Repeater::make('parametres')
                            ->label('Paràmetres')
                            ->table([
                                TableColumn::make('Nom'),
                                TableColumn::make('Etiqueta'),
                                TableColumn::make('Valor per defecte'),
                            ])
                            ->schema([
                                TextInput::make('name')->required()->regex('/^[a-z_][a-z0-9_]*$/'),
                                TextInput::make('label'),
                                TextInput::make('default'),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel('Afegir paràmetre'),
                        TextInput::make('temps_max_seg')
                            ->label('Temps màxim (s)')
                            ->numeric()
                            ->minValue(1)
                            ->default(300)
                            ->required(),
                        Select::make('checks_verificacio')
                            ->label('Checks de verificació')
                            ->helperText('Es llancen després d\'executar l\'acció (no en simular).')
                            ->multiple()
                            ->options(fn (Get $get) => Check::whereHas('task.block', fn ($q) => $q->where('modul_id', $get('modul_id')))
                                ->orderBy('nom')->pluck('nom', 'id')),
                    ]),
            ]);
    }

    /**
     * Normalitza els camps de versió per comparar-los i desar-los.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function versionData(array $data): array
    {
        $version = array_intersect_key($data, array_flip(self::VERSION_FIELDS));

        $version['codi'] = str_replace("\r\n", "\n", (string) ($version['codi'] ?? ''));
        $version['temps_max_seg'] = (int) ($version['temps_max_seg'] ?? 300);
        $version['parametres'] = array_values(array_map(fn (array $p) => [
            'name' => $p['name'],
            'label' => filled($p['label'] ?? null) ? $p['label'] : null,
            'default' => filled($p['default'] ?? null) ? (string) $p['default'] : null,
        ], $version['parametres'] ?? [])) ?: null;
        $ids = array_map('intval', $version['checks_verificacio'] ?? []);
        sort($ids);
        $version['checks_verificacio'] = $ids ?: null;

        return $version;
    }
}
