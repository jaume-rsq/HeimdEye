<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Enums\Role;
use App\Enums\SecretType;
use App\Filament\Pages\InventoryOverview;
use App\Models\AcademicYear;
use App\Models\Module;
use App\Models\Secret;
use App\Models\Student;
use App\Models\User;
use App\Models\Variable;
use App\Support\VariableResolver;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Pestanyes Variables i Secrets de l'Inventari: tots els nivells que veu un mòdul, editables al seu nivell. */
class InventoryLevelsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
    }

    private function page(?int $module = null, string $tab = 'variables')
    {
        return Livewire::test(InventoryOverview::class, ['modul' => $module ?? $this->module->id, 'pestanya' => $tab]);
    }

    private function edit(string $key, Level $level): TestAction
    {
        return TestAction::make('editVariable')->arguments(['clau' => $key, 'nivell' => $level->value]);
    }

    public function test_the_variables_tab_shows_every_level_the_module_sees(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Mòdul 9991')
            ->assertSee('ip_zabbix')
            ->assertSee('192.168.20.12')
            ->assertSee('xarxa_lan');

        // Sense mòdul, només els comuns.
        $this->page(0)
            ->assertOk()
            ->assertSee('ip_zabbix')
            ->assertDontSee('xarxa_lan')
            ->assertDontSee('Mòdul 9991');
    }

    public function test_a_cell_overrides_a_common_value_only_for_its_module(): void
    {
        $this->page()
            ->callAction($this->edit('ip_zabbix', Level::Module), ['valor' => '198.51.100.250'])
            ->assertHasNoActionErrors();

        $variable = Variable::where('clau', 'ip_zabbix')->where('nivell', Level::Module)->sole();
        $this->assertSame($this->module->id, $variable->modul_id);
        $this->assertSame('192.168.20.12', Variable::where('clau', 'ip_zabbix')->where('nivell', Level::Global)->value('valor'));

        $student = $this->module->students()->firstOrFail();
        $this->assertSame('198.51.100.250', app(VariableResolver::class)->resolve($student, $this->module)['ip_zabbix']);

        // Esborrar-la torna al valor global.
        $this->page()
            ->callAction([$this->edit('ip_zabbix', Level::Module), TestAction::make('deleteVariable')])
            ->assertHasNoActionErrors();
        $this->assertFalse(Variable::where('clau', 'ip_zabbix')->where('nivell', Level::Module)->exists());
    }

    public function test_the_edit_previews_the_value_for_a_student_without_saving(): void
    {
        $page = $this->page()->mountAction($this->edit('ip_pve', Level::Module));
        $student = Student::where('usuari', 'alumne01')->firstOrFail();
        $this->assertEquals($student->id, $page->get('mountedActions.0.data.alumne_prova'));

        $preview = fn (Level $level, string $value) => $page->instance()->previewValue('ip_pve', $level, $student->id, $value);
        $this->assertSame('198.51.100.20', $preview(Level::Module, '{{ xarxa_aula | host(4) }}'));
        $this->assertStringStartsWith('No s\'ha pogut calcular', $preview(Level::Module, '{{ xarxa_aula | host(99) }}'));
        $this->assertSame('198.51.100.19 (mana el valor del mòdul, no aquest)', $preview(Level::Global, '1.2.3.4'));

        $this->assertSame('{{ xarxa_aula | host(3) }}', $this->module->variables()->where('clau', 'ip_pve')->value('valor'));
    }

    public function test_saving_a_broken_template_warns_with_the_affected_students(): void
    {
        $this->page()
            ->callAction($this->edit('ip_router', Level::Module), ['valor' => '{{ inexistent }}'])
            ->assertHasNoActionErrors()
            ->assertNotified('Hi ha plantilles que no es poden calcular');
    }

    public function test_a_new_variable_is_unique_within_its_level(): void
    {
        $year = AcademicYear::where('es_actual', true)->firstOrFail();

        $this->page()
            ->callAction('createVariable', ['nivell' => Level::Course->value, 'clau' => 'ip_zabbix', 'valor' => '198.51.100.250'])
            ->assertHasNoActionErrors();
        $this->assertSame($year->id, Variable::where('nivell', Level::Course)->where('clau', 'ip_zabbix')->sole()->curs_id);

        $this->page()
            ->callAction('createVariable', ['nivell' => Level::Course->value, 'clau' => 'ip_zabbix', 'valor' => 'x'])
            ->assertHasActionErrors(['clau']);

        $this->page()
            ->callAction('createVariable', ['nivell' => Level::Module->value, 'clau' => 'nom_prova', 'valor' => 'T{{num_taula}}'])
            ->assertHasNoActionErrors();
        $this->assertSame($this->module->id, Variable::where('clau', 'nom_prova')->sole()->modul_id);
    }

    public function test_secrets_are_write_only(): void
    {
        $secret = Secret::where('clau', 'api_zabbix')->firstOrFail();
        $edit = fn () => TestAction::make('editSecret')->arguments(['clau' => 'api_zabbix', 'nivell' => Level::Global->value]);

        $this->page(tab: 'secrets')
            ->assertDontSee('contrasenya-nova')
            ->callAction($edit(), ['valor' => 'contrasenya-nova'])
            ->assertHasNoActionErrors();
        $this->assertSame('contrasenya-nova', $secret->fresh()->valor);

        $this->page(tab: 'secrets')
            ->mountAction($edit())
            ->assertActionDataSet(['valor' => null])
            ->setActionData(['nom_usuari' => 'altre'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertSame('contrasenya-nova', $secret->fresh()->valor);
        $this->assertSame('altre', $secret->fresh()->nom_usuari);
    }

    public function test_a_new_secret_goes_to_its_level_and_is_unique_there(): void
    {
        $year = AcademicYear::where('es_actual', true)->firstOrFail();

        $this->page(tab: 'secrets')
            ->callAction('createSecret', ['nivell' => Level::Course->value, 'clau' => 'token_curs', 'tipus' => SecretType::Value->value, 'valor' => 'abc'])
            ->assertHasNoActionErrors();
        $secret = Secret::where('clau', 'token_curs')->sole();
        $this->assertSame(Level::Course, $secret->nivell);
        $this->assertSame($year->id, $secret->curs_id);
        $this->assertSame('abc', $secret->valor);

        $this->page(tab: 'secrets')
            ->callAction('createSecret', ['nivell' => Level::Global->value, 'clau' => 'winrm_windows', 'tipus' => SecretType::Value->value])
            ->assertHasActionErrors(['clau']);
    }

    public function test_the_secrets_tab_says_where_a_secret_gives_access(): void
    {
        $this->page(tab: 'secrets')
            ->assertOk()
            ->assertSee('2 tipus d&#039;equip', false)
            ->assertSee('1 mòdul')
            ->assertSee('jaume_password');
    }

    public function test_the_old_screens_redirect_to_the_tabs(): void
    {
        $this->get('/admin/variables')->assertRedirect('/admin/inventari?pestanya=variables&modul=0');
        $this->get('/admin/secrets')->assertRedirect('/admin/inventari?pestanya=secrets&modul=0');
    }
}
