<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Models\Action;
use App\Models\Block;
use App\Models\Check;
use App\Models\Student;
use App\Runner\Ansible\Playbook;
use App\Runner\CheckRunner;
use App\Runner\CodeValidator;
use App\Runner\Params;
use Database\Seeders\Block05FirewallSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Block05FirewallSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Block05FirewallSeeder::class);
    }

    public function test_block_5_has_its_tasks_and_checks(): void
    {
        $this->seed(Block05FirewallSeeder::class);
        $block = Block::where('numero', 5)->sole();

        $this->assertSame(5, $block->tasks()->count());
        $checks = $this->checks();
        $this->assertCount(18, $checks);
        $this->assertSame(10, $checks->where('tipus', CheckKind::Required)->sum('punts'));
        $this->assertSame(18, $checks->where('tipus', CheckKind::Optional)->sum('punts'));
        $this->assertTrue($checks->every(fn (Check $c) => filled($c->descripcio) && filled($c->info) && $c->versions()->count() === 1));
    }

    public function test_block_5_code_passes_the_save_rules(): void
    {
        $validator = app(CodeValidator::class);
        $module = Block::where('numero', 5)->sole()->module;

        foreach ($this->checks() as $check) {
            $version = $check->currentVersion;
            $validator->validate($module, $check->executor, $version->codi);
            Params::values($version->parametres);
            $this->assertSyntax($check->executor, $version->codi, $check->nom);
        }

        $action = Action::where('nom', 'like', 'Accés de jaume a una VM Linux%')->sole();
        $validator->validate($module, $action->executor, $action->currentVersion->codi);
        Params::values($action->currentVersion->parametres);
        $this->assertSyntax($action->executor, $action->currentVersion->codi, $action->nom);
    }

    public function test_system_specific_checks_are_skipped_when_their_router_does_not_answer(): void
    {
        // Als alumnes de prova, el router és a una adreça de documentació que no respon.
        $check = $this->checks()->firstWhere('nom', 'Les regles es recuperen en arrencar');

        $result = app(CheckRunner::class)->run($check, Student::where('usuari', 'alumne01')->sole());

        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertSame('ara no respon el router Linux', $result->message);
    }

    public function test_iptables_checks_are_active_from_october_5_without_moving_the_others(): void
    {
        $nous = ['Regles de la LAN cap a la WAN i la DMZ', 'La DMZ no té regles obertes cap a la LAN', 'La DMZ no arriba a la LAN (prova des de ns1)',
            'Les regles del forward han tractat trànsit', 'El seguiment de connexions (conntrack) és actiu', 'Les regles desades coincideixen amb les actives'];

        foreach ($this->checks() as $check) {
            $expected = in_array($check->nom, $nous, true) ? '2026-10-05' : '2026-10-26';
            $this->assertSame($expected, substr((string) $check->actiu_des, 0, 10), $check->nom);
            $this->assertSame('2026-11-06', substr((string) $check->data_limit, 0, 10), $check->nom);
        }
    }

    public function test_forward_analysis_finds_the_flows_that_the_rules_open(): void
    {
        $ifaces = (new \ReflectionClassConstant(Block05FirewallSeeder::class, 'INTERFICIES'))->getValue();
        $analisi = (new \ReflectionClassConstant(Block05FirewallSeeder::class, 'ANALISI_FORWARD'))->getValue();
        $addr = "2: eth0    inet 192.168.20.14/28 brd 192.168.20.15 scope global eth0\\       valid_lft forever\n"
            ."3: eth1    inet 172.21.0.1/22 brd 172.21.3.255 scope global eth1\\       valid_lft forever\n"
            .'4: eth2    inet 10.21.0.1/22 brd 10.21.3.255 scope global eth2\\       valid_lft forever';
        $resposta = '-A FORWARD -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT';

        // lw = LAN -> WAN, ld = LAN -> DMZ, dl = DMZ -> LAN.
        $cases = [
            'correcte' => ["-P FORWARD DROP\n{$resposta}\n-A FORWARD -i eth1 -o eth0 -j ACCEPT\n-A FORWARD -i eth1 -o eth2 -j ACCEPT\n-A FORWARD -i eth2 -o eth1 -j DROP", '1 1 0'],
            'DMZ oberta' => ["-P FORWARD DROP\n{$resposta}\n-A FORWARD -i eth1 -j ACCEPT\n-A FORWARD -i eth2 -j ACCEPT", '1 1 1'],
            'sense LAN a DMZ' => ["-P FORWARD DROP\n{$resposta}\n-A FORWARD -i eth1 -o eth0 -j ACCEPT", '1 0 0'],
            'per adreces' => ["-P FORWARD DROP\n{$resposta}\n-A FORWARD -s 172.21.0.0/22 -d 10.21.0.0/22 -j ACCEPT\n-A FORWARD -s 172.21.0.0/22 -d 192.168.20.0/24 -j ACCEPT\n-A FORWARD -s 10.21.0.0/22 -d 172.21.0.0/22 -m conntrack --ctstate ESTABLISHED -j ACCEPT", '1 1 0'],
            'sense condicions' => ["-P FORWARD DROP\n-A FORWARD -j ACCEPT", '1 1 1'],
            'cadena pròpia' => ["-P FORWARD DROP\n-N LAN\n-A FORWARD -j LAN\n-A LAN -i eth1 -j ACCEPT", 'HEIMDEYE_OMES'],
        ];

        foreach ($cases as $name => [$fw, $expected]) {
            $script = "r() { case \"\$*\" in 'ip -4 -o addr show') printf '%s\\n' \"\$ADDR\" ;; 'sudo -n iptables -S FORWARD') printf '%s\\n' \"\$FW\" ;; esac; }\n"
                ."{$ifaces}\n{$analisi}\necho \"\$lw \$ld \$dl\"";
            $process = new Process(['bash', '-c', $script]);
            $process->setEnv(['HE_X' => '21', 'HE_IP_ROUTER' => '192.168.20.14', 'ADDR' => $addr, 'FW' => $fw])->run();

            $this->assertStringContainsString($expected, trim($process->getOutput()), $name);
        }
    }

    public function test_router_detection_tells_linux_from_no_connection(): void
    {
        $detect = (new \ReflectionClassConstant(Block05FirewallSeeder::class, 'ROUTER'))->getValue();
        $cases = [
            // ssh entra a un Linux: ":put" no hi existeix.
            'echo "bash: :put: command not found" >&2; return 127' => 'linux',
            'echo routeros' => 'mikrotik',
            'return 255' => 'cap',
        ];

        foreach ($cases as $ssh => $expected) {
            $process = new Process(['bash', '-c', "ssh() { {$ssh}; }\n{$detect}\necho \$sistema"]);
            $process->setEnv(['HE_SSH_USER' => 'jaume', 'HE_IP_ROUTER' => '192.0.2.1', 'HE_SSH_KEY' => ''])->run();
            $this->assertSame($expected, trim($process->getOutput()), $ssh);
        }
    }

    /** @return Collection<int, Check> */
    private function checks()
    {
        return Check::whereIn('tasca_id', Block::where('numero', 5)->sole()->tasks()->pluck('id'))->get();
    }

    private function assertSyntax(Executor $executor, string $code, string $name): void
    {
        if ($executor === Executor::Ansible) {
            $this->assertNotEmpty(Playbook::parse($code)->hostGroups(), $name);

            return;
        }

        $process = new Process(['bash', '-n']);
        $process->setInput($code)->run();
        $this->assertTrue($process->isSuccessful(), "{$name}: {$process->getErrorOutput()}");
    }
}
