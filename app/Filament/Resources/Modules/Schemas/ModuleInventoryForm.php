<?php

namespace App\Filament\Resources\Modules\Schemas;

use App\Enums\HostScope;
use App\Enums\Level;
use App\Enums\SecretType;
use App\Filament\Resources\Secrets\Schemas\SecretFields;
use App\Models\Module;
use App\Models\Student;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Inventari d'un mòdul: tipus d'equip, variables i secrets, amb una previsualització
 * per a un alumne que es calcula amb el formulari tal com està, sense desar.
 */
class ModuleInventoryForm
{
    /** Nom de variable: minúscules, números i _ (xarxa_lan, ip_pve...). */
    public const KEY_RULE = '/^[a-z][a-z0-9_]*$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('inventari')
                    ->persistTabInQueryString('pestanya')
                    ->tabs([
                        Tab::make('Tipus d\'equip')
                            ->schema([
                                Select::make('secret_acces_id')
                                    ->label('Accés per defecte del mòdul')
                                    ->options(fn (?Module $record) => SecretFields::accessOptions($record))
                                    ->helperText('Un secret d\'usuari i contrasenya o de clau SSH. El fan servir els equips que no en tenen cap.'),
                                Repeater::make('hostTypes')
                                    ->label('Tipus d\'equip')
                                    ->relationship()
                                    ->orderColumn('posicio')
                                    ->collapsible()
                                    ->collapsed()
                                    ->itemLabel(fn (array $state) => trim(($state['nom'] ?? '').' · '.($state['grup_ansible'] ?? '').' → '.($state['adreca'] ?? ''), ' ·→'))
                                    ->columns(3)
                                    ->addActionLabel('Afegir tipus d\'equip')
                                    ->schema([
                                        TextInput::make('nom')->required()->live(onBlur: true)->helperText('pve, router, dns...'),
                                        TextInput::make('grup_ansible')->label('Grup Ansible')->required()->regex(self::KEY_RULE),
                                        Select::make('abast')
                                            ->options([HostScope::Student->value => 'Un per alumne', HostScope::Global->value => 'Un per a tot el mòdul'])
                                            ->default(HostScope::Student->value)
                                            ->live()
                                            ->required(),
                                        TextInput::make('nom_host')->label('Nom de host')->required()->live(onBlur: true)->helperText('Plantilla: pve{{num_taula}}-{{usuari}}'),
                                        TextInput::make('adreca')->label('Adreça')->required()->live(onBlur: true)->helperText('Plantilla: {{ip_pve}}'),
                                        Select::make('secret_acces_id')
                                            ->label('Accés')
                                            ->options(fn ($livewire) => SecretFields::accessOptions($livewire->getRecord()))
                                            ->placeholder('El del mòdul'),
                                        KeyValue::make('variables_connexio')
                                            ->label('Variables de connexió')
                                            ->keyLabel('Variable Ansible')
                                            ->valueLabel('Valor')
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make('Variables')
                            ->schema([
                                Repeater::make('variables')
                                    ->hiddenLabel()
                                    ->relationship()
                                    ->table([
                                        TableColumn::make('Variable'),
                                        TableColumn::make('Valor o plantilla'),
                                        TableColumn::make('Descripció'),
                                    ])
                                    ->schema([
                                        TextInput::make('clau')->required()->regex(self::KEY_RULE)->distinct()->live(onBlur: true),
                                        TextInput::make('valor')->required()->live(onBlur: true),
                                        TextInput::make('descripcio'),
                                    ])
                                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => [...$data, 'nivell' => Level::Module->value])
                                    ->defaultItems(0)
                                    ->addActionLabel('Afegir variable'),
                                View::make('filament.variable-help'),
                            ]),
                        Tab::make('Secrets')
                            ->schema([
                                Repeater::make('secrets')
                                    ->hiddenLabel()
                                    ->relationship()
                                    ->collapsible()
                                    ->itemLabel(fn (array $state) => trim(($state['clau'] ?? '').' · '.(SecretType::tryFrom((string) ($state['tipus'] ?? ''))?->label() ?? ''), ' ·'))
                                    ->columns(3)
                                    ->schema(array_map(
                                        fn ($field) => $field->getName() === 'clau' ? $field->distinct() : $field,
                                        SecretFields::make(),
                                    ))
                                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => [...$data, 'nivell' => Level::Module->value])
                                    ->table([
                                        TableColumn::make('Clau'),
                                        TableColumn::make('Valor'),
                                        TableColumn::make('Descripció'),
                                    ])
                                    ->schema([
                                        TextInput::make('clau')->required()->regex(self::KEY_RULE)->distinct(),
                                        TextInput::make('valor')
                                            ->password()
                                            ->revealable()
                                            ->placeholder('Deixa-ho buit per no canviar-lo')
                                            ->dehydrated(fn (?string $state) => filled($state))
                                            // En la consulta no es mostra el valor (visitants de la demo).
                                            ->hiddenOn('view'),
                                        TextInput::make('descripcio'),
                                    ])
                                    ->defaultItems(0)
                                    ->addActionLabel('Afegir secret'),
                                View::make('filament.secret-help'),
                            ]),
                    ]),
                Section::make('Previsualització')
                    ->description('Com queda per a un alumne amb el que hi ha ara al formulari, encara que no ho hagis desat.')
                    ->schema([
                        Select::make('alumne_prova')
                            ->label('Alumne')
                            ->options(fn (?Module $record) => $record?->students()->where('es_actiu', true)->orderBy('num_taula')->get()
                                ->mapWithKeys(fn (Student $s) => [$s->id => sprintf('%02d · %s', $s->num_taula, $s->nom_complet)]) ?? [])
                            ->dehydrated(false)
                            ->live(),
                        View::make('filament.module-preview'),
                    ]),
            ]);
    }
}
