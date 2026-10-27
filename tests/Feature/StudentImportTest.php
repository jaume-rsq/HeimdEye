<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Students\Pages\ImportStudents;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use App\Support\StudentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** Importació de la llista de l'aula: lectura, pla de canvis i aplicació. */
class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private StudentImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->importer = app(StudentImporter::class);
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
    }

    /** La llista actual del mòdul, amb canvis opcionals, en format CSV. */
    private function csv(?callable $edit = null, string $separator = ','): string
    {
        $rows = $this->module->students()->orderBy('num_taula')->get()->map(fn (Student $s) => [
            'xarxa' => $s->variables['xarxa_aula'], 'taula' => (string) $s->num_taula,
            'alumne' => $s->nom_complet, 'email' => $s->user->email, 'alum' => $s->variables['nom_alum'],
        ])->all();
        if ($edit) {
            $rows = $edit($rows);
        }

        $line = fn (array $cells) => implode($separator, array_map(fn ($c) => str_contains($c, $separator) ? '"'.$c.'"' : $c, $cells));

        return implode("\n", [$line(StudentImporter::COLUMNS), ...array_map(fn ($r) => $line(array_values($r)), $rows)]);
    }

    public function test_parse_accepts_tabs_and_reports_bad_input(): void
    {
        $rows = $this->importer->parse("\xEF\xBB\xBFxarxa\ttaula\talumne\temail\talum\r\n10.0.0.0/24\t3\tProva U\tu@example.com\tu03\r\n\r\n");
        $this->assertSame([['xarxa' => '10.0.0.0/24', 'taula' => '3', 'alumne' => 'Prova U', 'email' => 'u@example.com', 'alum' => 'u03']], $rows);

        $this->expectException(InvalidArgumentException::class);
        $this->importer->parse("xarxa,taula,alumne\n1,2,3");
    }

    public function test_parse_reports_the_line_with_wrong_columns(): void
    {
        $this->expectExceptionMessage('La línia 3');
        $this->importer->parse("xarxa,taula,alumne,email,alum\na,1,b,c@example.com,d\na,2,b");
    }

    public function test_the_same_list_changes_nothing(): void
    {
        $plan = $this->importer->plan($this->module, $this->importer->parse($this->csv()));

        $this->assertSame([], $plan['nous']);
        $this->assertSame([], $plan['canvis']);
        $this->assertSame([], $plan['absents']);
        $this->assertSame($this->module->students()->count(), $plan['iguals']);
    }

    public function test_plan_shows_new_changed_and_missing_students(): void
    {
        $text = $this->csv(function (array $rows) {
            $rows[0]['taula'] = '15';
            array_pop($rows);
            $rows[] = ['xarxa' => '10.9.0.0/24', 'taula' => '16', 'alumne' => 'Nou Alumne', 'email' => 'Nou.Alumne@example.com', 'alum' => 'n16'];

            return $rows;
        });
        $plan = $this->importer->plan($this->module, $this->importer->parse($text));

        $this->assertSame('nou.alumne', $plan['nous'][0]['usuari']);
        $this->assertSame(36, $plan['nous'][0]['x']);
        $this->assertCount(1, $plan['canvis']);
        $this->assertSame(['num_taula', 'x'], array_keys($plan['canvis'][0]['diferencies']));
        $this->assertCount(1, $plan['absents']);
    }

    public function test_apply_creates_updates_and_deactivates_but_never_deletes(): void
    {
        $count = Student::count();
        $last = $this->module->students()->orderByDesc('num_taula')->first();
        $text = $this->csv(function (array $rows) {
            array_pop($rows);
            $rows[] = ['xarxa' => '10.9.0.0/24', 'taula' => '16', 'alumne' => 'Nou Alumne', 'email' => 'nou.alumne@example.com', 'alum' => 'n16'];

            return $rows;
        });

        $result = $this->importer->apply($this->module, $this->importer->parse($text));

        $this->assertSame(['nous' => 1, 'canvis' => 0, 'desactivats' => 1], $result);
        $this->assertSame($count + 1, Student::count());
        $this->assertFalse($last->fresh()->es_actiu);
        $new = Student::where('usuari', 'nou.alumne')->firstOrFail();
        $this->assertTrue($new->modules->contains($this->module));
        $this->assertSame('n16', $new->variables['nom_alum']);
        $this->assertSame(Role::Student, $new->user->rol);

        // Tornar a importar la llista completa el reactiva.
        $plan = $this->importer->plan($this->module, $this->importer->parse($this->csv(fn ($rows) => array_values(array_filter($rows, fn ($r) => $r['alum'] !== 'n16')))));
        $this->assertSame(['es_actiu' => ['no', 'sí']], $plan['canvis'][0]['diferencies']);
    }

    public function test_own_variables_are_kept(): void
    {
        $student = $this->module->students()->orderBy('num_taula')->first();
        $student->update(['variables' => [...$student->variables, 'ip_extra' => '192.0.2.1']]);

        $this->importer->apply($this->module, $this->importer->parse($this->csv()));

        $this->assertSame('192.0.2.1', $student->fresh()->variables['ip_extra']);
    }

    public function test_page_previews_before_importing(): void
    {
        $text = $this->csv(fn ($rows) => [...$rows, ['xarxa' => '10.9.0.0/24', 'taula' => '16', 'alumne' => 'Nou Alumne', 'email' => 'nou.alumne@example.com', 'alum' => 'n16']]);

        Livewire::test(ImportStudents::class)
            ->assertFormSet(['modul_id' => $this->module->id, 'desactivar' => true])
            ->fillForm(['text' => $text])
            ->call('previewImport')
            ->assertSee('Nou Alumne (nou.alumne)')
            ->assertSee('Sense canvis: '.$this->module->students()->count());
        $this->assertFalse(Student::where('usuari', 'nou.alumne')->exists());

        Livewire::test(ImportStudents::class)
            ->fillForm(['text' => "xarxa,taula\n1,2"])
            ->call('previewImport')
            ->assertSee('Falten columnes');

        Livewire::test(ImportStudents::class)
            ->fillForm(['text' => $text])
            ->call('previewImport')
            ->call('import')
            ->assertNotified('Alumnes importats');
        $this->assertTrue(Student::where('usuari', 'nou.alumne')->exists());
    }

    public function test_list_links_to_the_importer(): void
    {
        $this->get('/admin/alumnes')->assertOk()->assertSee('Importar llista');
        $this->get('/admin/alumnes/importar')->assertOk()->assertSee('Arrossega aquí el fitxer CSV');
    }

    public function test_table_zero_is_valid_but_a_table_without_number_is_not(): void
    {
        $row = ['xarxa' => '10.0.0.0/24', 'taula' => '0', 'alumne' => 'Prova Zero', 'email' => 'zero@example.com', 'alum' => 'u00'];
        $this->assertSame(0, $this->importer->attributes($row)['num_taula']);

        $this->expectException(InvalidArgumentException::class);
        $this->importer->attributes([...$row, 'taula' => 'taula']);
    }
}
