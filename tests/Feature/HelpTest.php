<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\Help;
use App\Models\Module;
use App\Models\User;
use App\Support\HelpDocs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Ajuda: pàgines Markdown de docs/ajuda per a professors i alumnes, i menú sense escriptori. */
class HelpTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/heimdeye-ajuda-'.uniqid();
        mkdir($this->dir);
        file_put_contents("{$this->dir}/20-checks.md", "---\ntitol: Checks\nordre: 20\nper: [professors]\nactualitzat: 2026-10-29\n---\n\n# Checks\n\n| a | b |\n| --- | --- |\n| 1 | 2 |\n");
        file_put_contents("{$this->dir}/10-inici.md", "---\ntitol: Inici\nordre: 10\nper: [tots]\nactualitzat: 2026-01-01\n---\n\nHola **tothom**.\n");
        file_put_contents("{$this->dir}/30-alumnes.md", "---\ntitol: Només alumnes\nper: [alumnes]\n---\n\nPer a alumnes.\n");
        $this->app->instance(HelpDocs::class, new HelpDocs($this->dir));
        $this->travelTo('2026-11-02 10:00');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
        parent::tearDown();
    }

    private function teacher(): User
    {
        return User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
    }

    public function test_docs_are_read_from_markdown_files_with_front_matter(): void
    {
        $docs = app(HelpDocs::class)->for('professors');

        $this->assertSame(['inici', 'checks'], $docs->keys()->all());
        $this->assertSame('Checks', $docs['checks']['titol']);
        $this->assertStringContainsString('<table>', $docs['checks']['html']);
        $this->assertStringContainsString('<strong>tothom</strong>', $docs['inici']['html']);
        $this->assertTrue($docs['checks']['recent']);
        $this->assertFalse($docs['inici']['recent']);
        $this->assertSame(['inici', 'alumnes'], app(HelpDocs::class)->for('alumnes')->keys()->all());
    }

    public function test_teachers_see_their_pages_and_the_recent_ones_are_marked(): void
    {
        $this->actingAs($this->teacher());

        $this->get('/admin/ajuda')->assertOk()->assertSee('Hola')->assertSee('Checks')->assertDontSee('Només alumnes');
        Livewire::withQueryParams(['doc' => 'checks'])->test(Help::class)
            ->assertSee('Actualitzada el 29/10/2026')
            ->assertSee('nou');
        $this->assertSame('1', Help::getNavigationBadge());
        Livewire::withQueryParams(['doc' => 'no-existeix'])->test(Help::class)->assertSet('doc', 'inici');
    }

    public function test_students_only_see_the_pages_for_them(): void
    {
        $module = Module::where('codi', '9991')->sole();
        $student = $module->students()->orderBy('num_taula')->firstOrFail();
        $user = User::create(['nom' => 'Alumne', 'email' => 'alumne@example.com', 'password' => 'secret', 'rol' => Role::Student, 'es_actiu' => true]);
        $student->update(['usuari_id' => $user->id]);

        $this->actingAs($user->fresh())->get('/alumne/ajuda?doc=alumnes')
            ->assertOk()
            ->assertSee('Per a alumnes')
            ->assertDontSee('Checks');
    }

    public function test_the_panel_has_no_dashboard_and_starts_at_the_leaderboard(): void
    {
        $this->actingAs($this->teacher());

        $this->get('/admin')->assertRedirect('/admin/leaderboard');
    }

    public function test_the_real_help_pages_parse(): void
    {
        $docs = (new HelpDocs)->for('professors');

        $this->assertTrue($docs->has('novetats'));
        $this->assertTrue($docs->every(fn (array $d) => filled($d['html']) && $d['actualitzat'] !== null));
        $this->assertTrue((new HelpDocs)->for('alumnes')->has('alumnes'));
    }
}
