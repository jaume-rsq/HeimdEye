<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Block;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Bloc 8 (DNS) com a bloc de prova: tasques i checks d'exemple.
 *
 * Contracte dels checks: codi de sortida 0 = assolit; stdout = missatge.
 * Les variables arriben com a entorn HE_* (HE_NS1, HE_SUBDOMAIN...).
 */
class Block08DnsSeeder extends Seeder
{
    private const STARTS = '2026-11-02';

    private const DEADLINE = '2026-11-06 23:59:59';

    public function run(): void
    {
        $module = Module::where('name', 'SMIX2 Infraestructura')->firstOrFail();

        $block = Block::updateOrCreate(
            ['module_id' => $module->id, 'number' => 8],
            [
                'title' => 'DNS: cau, zona pròpia, secundari i transferències',
                'starts_on' => self::STARTS,
                'ends_on' => '2026-11-06',
                'position' => 8,
            ],
        );

        $this->task($block, 1, 'ns1: servidor DNS primari a la DMZ', <<<'MD'
            Instal·la Bind a ns1 (10.X.0.11) com a servidor **autoritatiu** del teu subdomini
            `<usuari>.smix2.julioantonio.cat`.
            MD, [
            [
                'name' => 'ns1 respon a ping',
                'points' => 1,
                'code' => <<<'SH'
                    ping -c 2 -W 2 "$HE_NS1" > /dev/null || exit 1
                    echo "ns1 ($HE_NS1) respon"
                    SH,
            ],
            [
                'name' => 'ns1 respon el SOA del subdomini amb autoritat',
                'points' => 3,
                'code' => <<<'SH'
                    out=$(dig +norec +time=3 +tries=1 @"$HE_NS1" SOA "$HE_SUBDOMAIN") || exit 1
                    echo "$out" | grep -q 'flags:.* aa' || { echo "Resposta sense autoritat"; exit 1; }
                    echo "SOA: $(dig +short +norec @"$HE_NS1" SOA "$HE_SUBDOMAIN")"
                    SH,
            ],
            [
                'name' => 'Bind actiu i zona vàlida (SSH)',
                'executor' => Executor::Ssh,
                'points' => 2,
                'config' => ['host' => '{{ns1}}'],
                'code' => <<<'SH'
                    systemctl is-active --quiet named || { echo "Bind aturat"; exit 1; }
                    named-checkzone "$HE_SUBDOMAIN" "/etc/bind/db.$HE_SUBDOMAIN"
                    SH,
            ],
        ]);

        $this->task($block, 2, 'Registres de la zona', <<<'MD'
            Afegeix a la zona un registre `www` i un registre `MX` amb el seu servidor de correu.
            MD, [
            [
                'name' => 'Existeix www',
                'points' => 1,
                'code' => <<<'SH'
                    ip=$(dig +short @"$HE_NS1" "www.$HE_SUBDOMAIN")
                    [ -n "$ip" ] || exit 1
                    echo "www -> $ip"
                    SH,
            ],
            [
                'name' => 'Existeix MX',
                'points' => 1,
                'code' => <<<'SH'
                    mx=$(dig +short @"$HE_NS1" MX "$HE_SUBDOMAIN")
                    [ -n "$mx" ] || exit 1
                    echo "MX: $mx"
                    SH,
            ],
        ]);

        $this->task($block, 3, 'Delegació des del servidor central', <<<'MD'
            Informa ns1 a phpIPAM perquè el central delegui el teu subdomini.
            MD, [
            [
                'name' => 'El central delega el subdomini a ns1',
                'points' => 2,
                'code' => <<<'SH'
                    dig +norec @"$HE_CENTRAL_IP" NS "$HE_SUBDOMAIN" | grep -qi "ns1\.$HE_SUBDOMAIN" || exit 1
                    echo "Delegació correcta"
                    SH,
            ],
        ]);

        $this->task($block, 4, 'ns2 secundari a Windows Server', <<<'MD'
            Configura ns2 (172.X.0.11) com a secundari de la zona. Recorda obrir al tallafocs
            la transferència (AXFR) i el NOTIFY entre la DMZ i la LAN.
            MD, [
            [
                'name' => 'ns2 té la zona amb el mateix serial que ns1',
                'points' => 3,
                'code' => <<<'SH'
                    s1=$(dig +short +norec @"$HE_NS1" SOA "$HE_SUBDOMAIN" | awk '{print $3}')
                    s2=$(dig +short +norec @"$HE_NS2" SOA "$HE_SUBDOMAIN" | awk '{print $3}')
                    [ -n "$s1" ] && [ "$s1" = "$s2" ] || { echo "serial ns1=$s1 ns2=$s2"; exit 1; }
                    echo "serial $s1"
                    SH,
            ],
            [
                // Desactivat fins que WinRM estigui configurat a ns2.
                'name' => 'Servei DNS actiu a Windows (Ansible)',
                'executor' => Executor::Ansible,
                'kind' => CheckKind::Optional,
                'points' => 1,
                'active' => false,
                'config' => ['host' => '{{ns2}}'],
                'code' => <<<'YAML'
                    - hosts: all
                      gather_facts: false
                      tasks:
                        - ansible.windows.win_service_info:
                            name: DNS
                          register: dns
                        - ansible.builtin.assert:
                            that: dns.services[0].state == 'started'
                    YAML,
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
                    'kind' => $def['kind'] ?? CheckKind::Required,
                    'points' => $def['points'],
                    'active' => $def['active'] ?? null,
                    'active_from' => self::STARTS,
                    'deadline' => self::DEADLINE,
                    'punctuality_bonus_pct' => 20,
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
