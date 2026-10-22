<?php

namespace Database\Seeders;

use App\Enums\Executor;
use App\Models\Block;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Bloc 2 (Proxmox VE i accés remot) amb els checks inicials d'SSH.
 *
 * Contracte dels checks: codi de sortida 0 = assolit; stdout = missatge.
 * Les variables arriben com a entorn HE_* (HE_PVE_IP, HE_ROUTER_IP...). El runner
 * hi afegeix la credencial del mòdul: HE_SSH_USER i HE_SSH_KEY (ruta a la clau).
 */
class Block02ProxmoxSeeder extends Seeder
{
    /** Opcions comunes per als checks locals que fan ssh. */
    private const SSH = <<<'SH'
        opts=(-o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new)
        [ -n "$HE_SSH_KEY" ] && opts+=(-i "$HE_SSH_KEY")
        SH;

    public function run(): void
    {
        $module = Module::where('code', '9991')->firstOrFail();

        $block = Block::updateOrCreate(
            ['module_id' => $module->id, 'number' => 2],
            [
                'title' => 'Proxmox VE, màquines virtuals i accés remot',
                'starts_on' => '2026-09-21',
                'ends_on' => '2026-09-25',
                'position' => 2,
            ],
        );

        $this->task($block, 1, 'Accés SSH als equips', <<<'MD'
            El servidor Proxmox (tercera adreça útil de la teva xarxa d'aula) i el router
            (darrera adreça útil) han d'acceptar connexions SSH de l'usuari `jaume` amb clau.
            MD, [
            [
                'name' => 'SSH al servidor Proxmox',
                'code' => self::SSH."\n".<<<'SH'
                    ssh "${opts[@]}" "$HE_SSH_USER@$HE_PVE_IP" true || exit 1
                    echo "SSH correcte a $HE_PVE_IP"
                    SH,
            ],
            [
                'name' => 'SSH al router',
                'code' => self::SSH."\n".<<<'SH'
                    ssh "${opts[@]}" "$HE_SSH_USER@$HE_ROUTER_IP" true || exit 1
                    echo "SSH correcte a $HE_ROUTER_IP"
                    SH,
            ],
        ]);

        $this->task($block, 2, 'Màquines virtuals al Proxmox', <<<'MD'
            Crea com a mínim una màquina virtual al teu servidor Proxmox.
            MD, [
            [
                'name' => 'Hi ha alguna VM definida',
                'executor' => Executor::Ssh,
                'points' => 2,
                'config' => ['host_type' => 'pve'],
                // qm necessita root: l'usuari de la credencial ha de poder fer sudo -n qm list.
                'code' => <<<'SH'
                    out=$(sudo -n qm list) || { echo "No es pot executar qm list"; exit 1; }
                    vms=$(echo "$out" | tail -n +2)
                    [ -n "$vms" ] || { echo "Cap VM definida"; exit 1; }
                    echo "$(echo "$vms" | wc -l) VM: $(echo "$vms" | awk '{print $1 " " $2}' | paste -sd, -)"
                    SH,
            ],
        ]);
    }

    /** @param  list<array<string, mixed>>  $checks */
    private function task(Block $block, int $position, string $title, string $statement, array $checks): void
    {
        $task = $block->tasks()->updateOrCreate(
            ['title' => $title],
            ['statement' => $statement, 'position' => $position],
        );

        foreach ($checks as $i => $def) {
            $check = $task->checks()->updateOrCreate(
                ['name' => $def['name']],
                [
                    'executor' => $def['executor'] ?? Executor::Local,
                    'points' => $def['points'] ?? 1,
                    'position' => $i + 1,
                ],
            );

            if (! $check->versions()->exists()) {
                $check->versions()->create([
                    'version' => 1,
                    'code' => $def['code'],
                    'config' => $def['config'] ?? null,
                ]);
            }
        }
    }
}
