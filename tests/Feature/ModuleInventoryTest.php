<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Modules\Pages\EditModuleInventory;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Tipus d'equip d'un mòdul: previsualització de l'esborrany i desament. */
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
            ->assertSee('198.51.100.19')
            ->assertSee('Inventari → Variables');

        $key = $this->itemKey($page, 'hostTypes', 'nom', 'pve');
        $page->set("data.hostTypes.{$key}.adreca", '{{ xarxa_aula | host(4) }}')
            ->assertSee('198.51.100.20')
            ->set("data.hostTypes.{$key}.adreca", '{{ xarxa_aula | host(99) }}')
            ->assertSee('No s&#039;ha pogut calcular', false);

        $this->assertSame('{{ip_pve}}', $this->module->hostTypes()->where('nom', 'pve')->value('adreca'));
    }

    public function test_saving_a_broken_template_warns_with_the_affected_students(): void
    {
        $page = Livewire::test(EditModuleInventory::class, ['record' => $this->module->id]);
        $key = $this->itemKey($page, 'hostTypes', 'nom', 'router');

        $page->set("data.hostTypes.{$key}.adreca", '{{ inexistent }}')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Hi ha plantilles que no es poden calcular');
    }
}
