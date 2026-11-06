<?php

namespace App\Filament\Actions;

use App\Enums\Executor;
use App\Filament\Forms\RowPicker;
use App\Models\HostType;
use App\Models\Module;
use App\Models\Student;
use App\Runner\HostsRewriter;
use App\Support\Library;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Formulari de «Provar» a la biblioteca de checks i accions: com que la biblioteca no té alumnes
 * ni equips, es tria un mòdul real que fa de context (alumnes, variables, equips i secrets), els
 * alumnes i, si es vol, els equips on provar-ho en lloc dels del codi.
 */
final class LibraryTest
{
    /**
     * @param  list<array<string, mixed>>  $declared  paràmetres declarats (només les accions en demanen)
     * @return list<mixed>
     */
    public static function schema(Executor $executor, array $declared = []): array
    {
        return array_values(array_filter([
            Select::make('context')
                ->label('Mòdul de context')
                ->helperText('Dona els alumnes, les variables, els equips i els secrets de la prova.')
                ->options(fn () => Library::targetModules())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('alumnes', [])),
            RowPicker::make('alumnes')
                ->label('Alumnes')
                ->required()
                ->headers(['Taula', 'Usuari', 'Nom', 'X'])
                ->rows(fn (Get $get) => self::students($get('context'))),
            self::hostsField($executor),
            $declared === [] ? null : Fieldset::make('Paràmetres')
                ->schema(array_map(fn (array $p) => TextInput::make("parametres.{$p['name']}")
                    ->label($p['label'] ?? $p['name'])
                    ->helperText(filled($p['default'] ?? null) ? "Per defecte: {$p['default']}" : 'Sense valor per defecte')
                    ->required(blank($p['default'] ?? null)), $declared)),
        ]));
    }

    /** @return array<string, mixed> */
    public static function fill(array $declared = []): array
    {
        return [
            'context' => array_key_first(Library::targetModules()),
            'alumnes' => [],
            'equips' => [],
            'parametres' => collect($declared)->mapWithKeys(fn (array $p) => [$p['name'] => (string) ($p['default'] ?? '')])->all(),
        ];
    }

    /** El codi amb els equips triats en lloc dels seus (si no se n'ha triat cap, tal com és). */
    public static function code(Executor $executor, string $code, array $data): string
    {
        return HostsRewriter::rewrite($executor, $code, array_values($data['equips'] ?? []));
    }

    public static function contextModule(array $data): Module
    {
        return Module::regular()->findOrFail($data['context'] ?? null);
    }

    /** Equips on provar-ho: grups del mòdul de context (ssh i Ansible) o rols d'agent. En local, cap. */
    private static function hostsField(Executor $executor): mixed
    {
        return match ($executor) {
            Executor::Ssh, Executor::Ansible => Select::make('equips')
                ->label('Equips on provar-ho')
                ->helperText('Buit = els del codi.')
                ->multiple()
                ->options(fn (Get $get) => ($module = Module::find($get('context')))
                    ? $module->visibleHostTypes()->mapWithKeys(fn (HostType $t) => [$t->grup_ansible => "{$t->nom} ({$t->grup_ansible})"])->all()
                    : []),
            Executor::Agent => TagsInput::make('equips')
                ->label('Rols on provar-ho')
                ->helperText('Hostname de les instàncies (web, bd...). Buit = els del codi.')
                ->placeholder('web'),
            default => null,
        };
    }

    /** @return array<int, list<string>> */
    private static function students(mixed $moduleId): array
    {
        $module = $moduleId ? Module::find($moduleId) : null;

        return $module === null ? [] : $module->students()->where('es_actiu', true)->orderBy('num_taula')->get()
            ->mapWithKeys(fn (Student $s) => [$s->id => [sprintf('%02d', $s->num_taula), $s->usuari, $s->nom_complet, (string) $s->x]])
            ->all();
    }
}
