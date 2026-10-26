<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Role;
use App\Enums\VariableScope;
use App\Filament\Resources\Credentials\Pages\ManageCredentials;
use App\Filament\Resources\Variables\Pages\ManageVariables;
use App\Models\AcademicYear;
use App\Models\Credential;
use App\Models\User;
use App\Models\Variable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Variables comunes (globals i de curs) i credencials. */
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
            ->callAction('create', ['abast' => VariableScope::Course->value, 'curs_id' => $year->id, 'clau' => 'ip_zabbix', 'valor' => '198.51.100.250'])
            ->assertHasNoActionErrors();

        $variable = Variable::where('abast', VariableScope::Course)->where('clau', 'ip_zabbix')->firstOrFail();
        $this->assertSame($year->id, $variable->curs_id);

        Livewire::test(ManageVariables::class)
            ->callAction('create', ['abast' => VariableScope::Course->value, 'curs_id' => $year->id, 'clau' => 'ip_zabbix', 'valor' => 'x'])
            ->assertHasActionErrors(['clau']);

        Livewire::test(ManageVariables::class)
            ->callAction('create', ['abast' => VariableScope::Global->value, 'curs_id' => $year->id, 'clau' => 'nom_nou', 'valor' => 'x'])
            ->assertHasNoActionErrors();
        $this->assertNull(Variable::where('clau', 'nom_nou')->value('curs_id'));
    }

    public function test_credential_secrets_are_write_only(): void
    {
        $credential = Credential::where('nom', 'API Zabbix')->firstOrFail();

        Livewire::test(ManageCredentials::class)
            ->callAction(TestAction::make('edit')->table($credential), ['secret' => 'contrasenya-nova'])
            ->assertHasNoActionErrors();
        $this->assertSame('contrasenya-nova', $credential->fresh()->secret);

        Livewire::test(ManageCredentials::class)
            ->mountAction(TestAction::make('edit')->table($credential))
            ->assertActionDataSet(['secret' => null])
            ->setActionData(['nom_usuari' => 'altre'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertSame('contrasenya-nova', $credential->fresh()->secret);
        $this->assertSame('altre', $credential->fresh()->nom_usuari);
    }

    public function test_the_list_says_where_a_credential_is_used(): void
    {
        Credential::create(['nom' => 'Sense ús', 'tipus' => CredentialType::Ssh, 'nom_usuari' => 'ningu']);

        Livewire::test(ManageCredentials::class)
            ->assertOk()
            ->assertSee('2 tipus d&#039;equip', false)
            ->assertSee('1 mòdul')
            ->assertSee('enlloc');
    }
}
