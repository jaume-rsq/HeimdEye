<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Enums\Role;
use App\Filament\Resources\Modules\Pages\EditModuleInventory;
use App\Models\Module;
use App\Models\Secret;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Equips, variables i secrets d'un mòdul: previsualització de l'esborrany i desament. */
class ModuleInventoryTest extends TestCase
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

    /** Clau del repeater de la variable amb aquest nom. */
    private function itemKey($page, string $repeater, string $field, string $value): string
    {
        foreach ($page->get("data.{$repeater}") as $key => $item) {
            if (($item[$field] ?? null) === $value) {
                return $key;
            }
        }
        $this->fail("No hi ha {$value} a {$repeater}");
    }

    public function test_the_preview_uses_the_unsaved_draft(): void
    {
        $page = Livewire::test(EditModuleInventory::class, ['record' => $this->module->id])
            ->assertOk()
            ->assertSee('pve01-alumne01')
            ->assertSee('198.51.100.19');

        $key = $this->itemKey($page, 'variables', 'clau', 'ip_pve');
        $page->set("data.variables.{$key}.valor", '{{ xarxa_aula | host(4) }}')
            ->assertSee('198.51.100.20')
            ->set("data.variables.{$key}.valor", '{{ xarxa_aula | host(99) }}')
            ->assertSee('No s&#039;ha pogut calcular', false);

        $this->assertSame('{{ xarxa_aula | host(3) }}', $this->module->variables()->where('clau', 'ip_pve')->value('valor'));
    }

    public function test_saving_creates_module_variables_and_keeps_secrets_write_only(): void
    {
        $secret = $this->module->secrets()->create(['clau' => 'token_prova', 'valor' => 'antic']);

        $page = Livewire::test(EditModuleInventory::class, ['record' => $this->module->id])
            ->assertDontSee('antic');

        $variables = $page->get('data.variables');
        $variables['nou'] = ['clau' => 'nom_prova', 'valor' => 'T{{num_taula}}', 'descripcio' => null];
        $page->set('data.variables', $variables)
            ->call('save')
            ->assertHasNoFormErrors();

        $variable = Variable::where('clau', 'nom_prova')->firstOrFail();
        $this->assertSame(Level::Module, $variable->nivell);
        $this->assertSame($this->module->id, $variable->modul_id);
        $this->assertSame('antic', $secret->fresh()->valor);

        $page = Livewire::test(EditModuleInventory::class, ['record' => $this->module->id]);
        $key = $this->itemKey($page, 'secrets', 'clau', $secret->clau);
        $page->set("data.secrets.{$key}.valor", 'nou-valor')->call('save')->assertHasNoFormErrors();
        $this->assertSame('nou-valor', $secret->fresh()->valor);
        $this->assertNotSame('nou-valor', Secret::query()->toBase()->where('id', $secret->id)->value('valor'));
    }

    public function test_saving_a_broken_template_warns_with_the_affected_students(): void
    {
        $page = Livewire::test(EditModuleInventory::class, ['record' => $this->module->id]);
        $key = $this->itemKey($page, 'variables', 'clau', 'ip_router');

        $page->set("data.variables.{$key}.valor", '{{ inexistent }}')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Hi ha plantilles que no es poden calcular');
    }
}
