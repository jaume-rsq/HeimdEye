<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Enums\Role;
use App\Enums\SecretType;
use App\Filament\Resources\Secrets\Pages\ManageSecrets;
use App\Filament\Resources\Variables\Pages\ManageVariables;
use App\Models\AcademicYear;
use App\Models\Secret;
use App\Models\User;
use App\Models\Variable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Variables i secrets comuns (globals i de curs). */
class CommonInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
    }

    public function test_common_variables_list_only_global_and_course_ones(): void
    {
        Livewire::test(ManageVariables::class)
            ->assertOk()
            ->assertSee('ip_zabbix')
            ->assertDontSee('xarxa_lan'); // és del mòdul
    }

    public function test_a_course_variable_is_unique_within_its_course(): void
    {
        $year = AcademicYear::where('es_actual', true)->firstOrFail();

        Livewire::test(ManageVariables::class)
            ->callAction('create', ['nivell' => Level::Course->value, 'curs_id' => $year->id, 'clau' => 'ip_zabbix', 'valor' => '198.51.100.250'])
            ->assertHasNoActionErrors();

        $variable = Variable::where('nivell', Level::Course)->where('clau', 'ip_zabbix')->firstOrFail();
        $this->assertSame($year->id, $variable->curs_id);

        Livewire::test(ManageVariables::class)
            ->callAction('create', ['nivell' => Level::Course->value, 'curs_id' => $year->id, 'clau' => 'ip_zabbix', 'valor' => 'x'])
            ->assertHasActionErrors(['clau']);

        Livewire::test(ManageVariables::class)
            ->callAction('create', ['nivell' => Level::Global->value, 'curs_id' => $year->id, 'clau' => 'nom_nou', 'valor' => 'x'])
            ->assertHasNoActionErrors();
        $this->assertNull(Variable::where('clau', 'nom_nou')->value('curs_id'));
    }

    public function test_common_secrets_are_write_only(): void
    {
        $secret = Secret::where('clau', 'api_zabbix')->firstOrFail();

        Livewire::test(ManageSecrets::class)
            ->callAction(TestAction::make('edit')->table($secret), ['valor' => 'contrasenya-nova'])
            ->assertHasNoActionErrors();
        $this->assertSame('contrasenya-nova', $secret->fresh()->valor);

        Livewire::test(ManageSecrets::class)
            ->mountAction(TestAction::make('edit')->table($secret))
            ->assertActionDataSet(['valor' => null])
            ->setActionData(['nom_usuari' => 'altre'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertSame('contrasenya-nova', $secret->fresh()->valor);
        $this->assertSame('altre', $secret->fresh()->nom_usuari);
    }

    public function test_common_secrets_are_global_or_of_a_course_and_unique_per_level(): void
    {
        $year = AcademicYear::where('es_actual', true)->firstOrFail();

        Livewire::test(ManageSecrets::class)
            ->callAction('create', ['nivell' => Level::Course->value, 'curs_id' => $year->id, 'clau' => 'token_curs', 'tipus' => SecretType::Value->value, 'valor' => 'abc'])
            ->assertHasNoActionErrors();
        $secret = Secret::where('clau', 'token_curs')->firstOrFail();
        $this->assertSame(Level::Course, $secret->nivell);
        $this->assertSame($year->id, $secret->curs_id);
        $this->assertNull($secret->modul_id);

        Livewire::test(ManageSecrets::class)
            ->callAction('create', ['nivell' => Level::Global->value, 'clau' => 'jaume', 'tipus' => SecretType::Value->value])
            ->assertHasActionErrors(['clau']);
    }

    public function test_the_list_says_where_a_secret_gives_access(): void
    {
        Secret::create(['nivell' => Level::Global, 'clau' => 'sense_us', 'tipus' => SecretType::SshKey, 'nom_usuari' => 'ningu']);

        Livewire::test(ManageSecrets::class)
            ->assertOk()
            ->assertSee('2 tipus d&#039;equip', false)
            ->assertSee('1 mòdul')
            ->assertSee('sense_us');
    }
}
