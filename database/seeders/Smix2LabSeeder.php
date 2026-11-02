<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Enums\Level;
use App\Enums\ModuleKind;
use App\Enums\SecretType;
use App\Models\AcademicYear;
use App\Models\Action;
use App\Models\Block;
use App\Models\Check;
use App\Models\Cycle;
use App\Models\Module;
use App\Models\Secret;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo SMIX2: HeimdEye aplicat a un mòdul de serveis de xarxa. Cada alumne fictici té la xarxa
 * d'una petita empresa al laboratori de lab/lab.sh (contenidors LXC): un router Linux amb WAN,
 * LAN i DMZ, un client a la LAN i un servidor a la DMZ. Cada bloc té accions que munten el servei
 * amb una configuració bàsica (la solució del professor) i checks que en comproven el funcionament,
 * sempre que es pot des del punt de vista d'un client.
 *
 * Pla d'adreces (X = 20 + número de taula, com a l'aula):
 * - Gestió 10.255.X.0/24 (eth0 de cada equip): només per a HeimdEye, no s'encamina. Així una acció
 *   que trenca la xarxa de l'alumne no deixa HeimdEye sense accés.
 * - WAN 203.0.113.0/24 (adreces de documentació): el router és .X i HeimdEye .2.
 * - LAN 10.X.1.0/24 (router .1, client .100 reservat per DHCP) i DMZ 10.X.2.0/24 (router .1, servidor .10).
 *
 * Va al curs del tutorial (el de la demo), com un cicle i un mòdul més. DemoSeeder el carrega
 * només si el laboratori està actiu (HEIMDEYE_LAB=true).
 */
class Smix2LabSeeder extends Seeder
{
    public const CYCLE = 'SMX';

    public const MODULE = 'SMX2';

    /** Alumnes ficticis: pioners de les xarxes. lab/lab.sh en crea els equips (1 a 4). */
    private const STUDENTS = [
        [1, 'radia', 'Radia Perlman'],
        [2, 'vint', 'Vint Cerf'],
        [3, 'hedy', 'Hedy Lamarr'],
        [4, 'paul', 'Paul Mockapetris'],
    ];

    public function run(): void
    {
        $year = AcademicYear::where('nom', 'Tutorial')->first() ?? AcademicYear::updateOrCreate(
            ['nom' => 'Tutorial'],
            ['data_inici' => '2026-09-01', 'data_fi' => '2027-08-31', 'es_actual' => ! AcademicYear::where('es_actual', true)->exists()],
        );
        $cycle = Cycle::updateOrCreate(['codi' => self::CYCLE], ['nom' => 'Sistemes microinformàtics i xarxes (demo)']);

        // La clau és una ruta del contenidor de HeimdEye (HEIMDEYE_LAB_KEY): lab/lab.sh la genera a cada instal·lació.
        $access = Secret::firstOrCreate(
            ['nivell' => Level::Global, 'curs_id' => null, 'modul_id' => null, 'clau' => 'ssh_lab'],
            ['tipus' => SecretType::SshKey, 'nom_usuari' => 'heimdeye', 'descripcio' => 'SSH als equips del laboratori de xarxa (lab/lab.sh)'],
        );
        if (! $access->hasValue() && filled($path = config('heimdeye.lab.key'))) {
            $access->update(['valor' => $path]);
        }

        $count = max(1, min(count(self::STUDENTS), (int) config('heimdeye.lab.students', 2)));
        $students = collect(array_slice(self::STUDENTS, 0, $count))->map(fn (array $s) => Student::updateOrCreate(
            ['curs_id' => $year->id, 'usuari' => $s[1]],
            ['cicle_id' => $cycle->id, 'nom_complet' => $s[2], 'alies' => strtok($s[2], ' '), 'num_taula' => $s[0], 'x' => 20 + $s[0], 'es_actiu' => true],
        ));

        $module = Module::updateOrCreate(
            ['curs_id' => $year->id, 'cicle_id' => $cycle->id, 'codi' => self::MODULE],
            [
                'nom' => 'Serveis de xarxa (laboratori)',
                'descripcio' => 'La xarxa d\'una petita empresa, bloc a bloc: encaminament, tallafoc i DHCP. Cada alumne té el seu router, client i servidor de la DMZ.',
                'tipus' => ModuleKind::Free,
                'secret_acces_id' => $access->id,
            ],
        );
        $module->students()->syncWithoutDetaching($students->pluck('id'));
        $module->teachers()->syncWithoutDetaching(User::where('rol', 'admin')->pluck('id'));

        $this->inventory($module);
        $this->network($module);
        $this->firewall($module);
        $this->dhcp($module);
    }

    /** Variables del pla d'adreces i els tres equips de cada alumne. */
    private function inventory(Module $module): void
    {
        foreach ([
            'xarxa_wan' => '203.0.113.0/24',
            'ip_wan_gw' => '{{ xarxa_wan | host(1) }}',
            'ip_heimdeye_wan' => '{{ xarxa_wan | host(2) }}',
            'ip_wan' => '203.0.113.{{x}}',
            'xarxa_lan' => '10.{{x}}.1.0/24',
            'ip_router_lan' => '{{ xarxa_lan | host(1) }}',
            'ip_client' => '{{ xarxa_lan | host(100) }}',
            'mac_client' => '00:16:3e:00:01:{{x}}',
            'ip_dhcp_inici' => '{{ xarxa_lan | host(101) }}',
            'ip_dhcp_fi' => '{{ xarxa_lan | host(199) }}',
            'xarxa_dmz' => '10.{{x}}.2.0/24',
            'ip_router_dmz' => '{{ xarxa_dmz | host(1) }}',
            'ip_dmz' => '{{ xarxa_dmz | host(10) }}',
            'nom_zona' => '{{usuari}}.heimdeye.test',
            'ip_gestio_router' => '10.255.{{x}}.1',
            'ip_gestio_client' => '10.255.{{x}}.10',
            'ip_gestio_dmz' => '10.255.{{x}}.20',
        ] as $key => $value) {
            $module->variables()->updateOrCreate(['nivell' => Level::Module, 'clau' => $key], ['valor' => $value]);
        }

        foreach ([
            ['router', 'lab_router', 'router{{x}}', '{{ip_gestio_router}}'],
            ['client', 'lab_client', 'client{{x}}', '{{ip_gestio_client}}'],
            ['dmz', 'lab_dmz', 'dmz{{x}}', '{{ip_gestio_dmz}}'],
        ] as $i => [$name, $group, $host, $address]) {
            $module->hostTypes()->updateOrCreate(
                ['nom' => $name],
                ['grup_ansible' => $group, 'nom_host' => $host, 'adreca' => $address, 'posicio' => $i + 1],
            );
        }
    }

    private function network(Module $module): void
    {
        $block = $this->block($module, 1, 'Xarxa i encaminament');

        $this->task($block, 1, 'HeimdEye entra als equips', <<<'MD'
            Cada alumne té tres equips: el **router** (WAN, LAN i DMZ), un **client** a la LAN i un **servidor**
            a la DMZ. HeimdEye hi entra per una xarxa de gestió a part (`eth0` de cada equip), que no forma part
            de la pràctica: així, si l'alumne trenca la seva xarxa, HeimdEye encara pot veure què passa.

            Aquest check ja passa per a tothom: el laboratori es crea amb l'usuari `heimdeye` i la seva clau.
            MD, [
            [
                'nom' => 'HeimdEye entra als tres equips',
                'executor' => Executor::Ssh,
                'descripcio' => 'HeimdEye entra per SSH al router, al client i al servidor de la DMZ.',
                'info' => $this->info(
                    'comprovar l\'accés abans de res: sense accés, tots els altres checks donarien error.',
                    'és el primer pas de qualsevol bloc a l\'aula: un usuari per a HeimdEye amb la seva clau i `sudo` sense contrasenya.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_router:lab_client:lab_dmz
                    echo "HEIMDEYE_MSG: dins de $(hostname)"
                    SH,
            ],
        ]);

        $this->task($block, 2, 'Adreces, encaminament i NAT', <<<'MD'
            Configura la xarxa de l'empresa (X és el teu número de xarxa):

            | Equip | Interfície | Adreça | Porta d'enllaç |
            | --- | --- | --- | --- |
            | Router | `eth1` (WAN) | 203.0.113.X/24 | 203.0.113.1 |
            | Router | `eth2` (LAN) | 10.X.1.1/24 | |
            | Router | `eth3` (DMZ) | 10.X.2.1/24 | |
            | Client | `eth1` | 10.X.1.100/24 | 10.X.1.1 |
            | Servidor DMZ | `eth1` | 10.X.2.10/24 | 10.X.2.1 |

            El router ha de reenviar paquets entre interfícies i fer **NAT** (*masquerade*) del que surt per la
            WAN: a fora, les adreces privades de la LAN i la DMZ no tenen camí de tornada.

            Fes servir **netplan** per a les adreces (un fitxer a `/etc/netplan/`), un fitxer a `/etc/sysctl.d/`
            per al reenviament i **nftables** per al NAT.
            MD, [
            [
                'nom' => 'Router: adreces a la WAN, la LAN i la DMZ',
                'executor' => Executor::Ssh,
                'descripcio' => 'El router té 203.0.113.X a `eth1`, 10.X.1.1 a `eth2` i 10.X.2.1 a `eth3`. Comprova-ho: `ip -br addr`.',
                'info' => $this->info(
                    'comprovar que cada interfície del router és a la seva xarxa.',
                    'un router té una adreça a cada xarxa que uneix, i aquesta adreça és la porta d\'enllaç dels equips d\'aquella xarxa.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_router
                    falten=""
                    for parell in "eth1 $HE_IP_WAN" "eth2 $HE_IP_ROUTER_LAN" "eth3 $HE_IP_ROUTER_DMZ"; do
                        set -- $parell
                        ip -4 -o addr show dev "$1" 2>/dev/null | grep -qF " $2/" || falten="$falten $1=$2"
                    done
                    [ -z "$falten" ] || { echo "HEIMDEYE_MSG: falten adreces:$falten"; exit 1; }
                    echo "HEIMDEYE_MSG: eth1=$HE_IP_WAN eth2=$HE_IP_ROUTER_LAN eth3=$HE_IP_ROUTER_DMZ"
                    SH,
            ],
            [
                'nom' => 'Router: reenviament IPv4 actiu',
                'executor' => Executor::Ssh,
                'descripcio' => 'El router reenvia paquets entre interfícies. Comprova-ho: `sysctl net.ipv4.ip_forward` ha de valer 1.',
                'info' => $this->info(
                    'comprovar que el Linux fa de router.',
                    'per defecte Linux descarta els paquets que no són per a ell; sense reenviament, la LAN i la DMZ no surten enlloc.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_router
                    [ "$(cat /proc/sys/net/ipv4/ip_forward)" = 1 ] || { echo "HEIMDEYE_MSG: net.ipv4.ip_forward val 0"; exit 1; }
                    echo "HEIMDEYE_MSG: net.ipv4.ip_forward = 1"
                    SH,
            ],
            [
                'nom' => 'El client surt a la WAN',
                'executor' => Executor::Ssh,
                'descripcio' => 'Des del client es fa ping a HeimdEye, que és a la WAN (203.0.113.2). Comprova-ho des del client: `ping 203.0.113.2`.',
                'info' => $this->info(
                    'comprovar de punta a punta que la LAN surt a fora: adreça i porta d\'enllaç del client, reenviament i NAT del router.',
                    'HeimdEye no té camí de tornada cap a 10.X.1.0/24: la resposta només arriba si el router fa NAT. Un sol ping prova tota la cadena, com faria un usuari.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_client
                    ping -c1 -W2 "$HE_IP_HEIMDEYE_WAN" >/dev/null 2>&1 || { echo "HEIMDEYE_MSG: el client no arriba a $HE_IP_HEIMDEYE_WAN (adreça, porta d'enllaç, reenviament o NAT)"; exit 1; }
                    echo "HEIMDEYE_MSG: el client arriba a la WAN"
                    SH,
            ],
            [
                'nom' => 'El servidor de la DMZ surt a la WAN',
                'executor' => Executor::Ssh,
                'descripcio' => 'Des del servidor de la DMZ es fa ping a HeimdEye (203.0.113.2). Comprova-ho des del servidor: `ping 203.0.113.2`.',
                'info' => $this->info(
                    'comprovar el mateix per a la DMZ.',
                    'els servidors de la DMZ també han de sortir a fora (actualitzacions, DNS); més endavant el tallafoc decidirà què pot entrar.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_dmz
                    ping -c1 -W2 "$HE_IP_HEIMDEYE_WAN" >/dev/null 2>&1 || { echo "HEIMDEYE_MSG: el servidor no arriba a $HE_IP_HEIMDEYE_WAN"; exit 1; }
                    echo "HEIMDEYE_MSG: la DMZ arriba a la WAN"
                    SH,
            ],
            [
                'nom' => 'Router: NAT de sortida per la WAN',
                'executor' => Executor::Ssh,
                'tipus' => CheckKind::Optional,
                'descripcio' => 'Hi ha una regla `masquerade` de nftables per a la sortida per `eth1`. Comprova-ho: `sudo nft list ruleset`.',
                'info' => $this->info(
                    'llegir la configuració que explica el resultat del check del client.',
                    'quan el ping del client falla, aquest check diu si el problema és el NAT o una altra cosa.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_router
                    sudo -n nft list ruleset 2>/dev/null | grep -Eq 'oifname "?eth1"? .*masquerade' || { echo "HEIMDEYE_MSG: no hi ha cap masquerade per eth1"; exit 1; }
                    echo "HEIMDEYE_MSG: masquerade per eth1"
                    SH,
            ],
        ]);

        $router = $this->checkIds($block, ['Router: adreces a la WAN, la LAN i la DMZ', 'Router: reenviament IPv4 actiu']);
        $wan = $this->checkIds($block, ['El client surt a la WAN', 'El servidor de la DMZ surt a la WAN', 'Router: NAT de sortida per la WAN']);

        $this->action($module, $block, 1, 'Router: adreces i reenviament', <<<'YAML'
            - hosts: lab_router
              become: true
              gather_facts: false
              tasks:
                - name: Adreces de la WAN, la LAN i la DMZ
                  ansible.builtin.copy:
                    dest: /etc/netplan/60-lab.yaml
                    mode: "0600"
                    content: |
                      network:
                        version: 2
                        ethernets:
                          eth1:
                            addresses: [{{ ip_wan }}/24]
                            routes:
                              - to: default
                                via: {{ ip_wan_gw }}
                          eth2:
                            addresses: [{{ ip_router_lan }}/24]
                          eth3:
                            addresses: [{{ ip_router_dmz }}/24]
                  register: netplan

                - name: Aplica la xarxa
                  ansible.builtin.command: netplan apply
                  when: netplan.changed

                - name: Reenviament IPv4 permanent
                  ansible.builtin.copy:
                    dest: /etc/sysctl.d/60-lab.conf
                    content: "net.ipv4.ip_forward = 1\n"
                  register: sysctl

                - name: Activa el reenviament
                  ansible.builtin.command: sysctl -p /etc/sysctl.d/60-lab.conf
                  when: sysctl.changed
            YAML, 'Escriu la configuració de netplan del router (WAN, LAN i DMZ) i activa el reenviament IPv4.', $router);

        $this->action($module, $block, 2, 'Router: NAT de sortida', self::NFTABLES.<<<'YAML'
                - name: Masquerade del que surt per la WAN
                  ansible.builtin.copy:
                    dest: /etc/nftables.d/10-nat.nft
                    content: |
                      table ip nat {
                        chain postrouting {
                          type nat hook postrouting priority srcnat; policy accept;
                          oifname "eth1" masquerade
                        }
                      }
                  register: regles
            YAML.self::NFTABLES_APPLY, 'Afegeix el NAT (masquerade) per a la sortida per la WAN. Cada bloc desa les seves regles en un fitxer de /etc/nftables.d/.', $wan);

        $this->action($module, $block, 3, 'Client i DMZ: adreces', <<<'YAML'
            - hosts: lab_client
              become: true
              gather_facts: false
              tasks:
                - name: Adreça fixa i porta d'enllaç del client
                  ansible.builtin.copy:
                    dest: /etc/netplan/60-lab.yaml
                    mode: "0600"
                    content: |
                      network:
                        version: 2
                        ethernets:
                          eth1:
                            addresses: [{{ ip_client }}/24]
                            routes:
                              - to: default
                                via: {{ ip_router_lan }}
                  register: netplan

                - name: Aplica la xarxa
                  ansible.builtin.command: netplan apply
                  when: netplan.changed

            - hosts: lab_dmz
              become: true
              gather_facts: false
              tasks:
                - name: Adreça fixa i porta d'enllaç del servidor
                  ansible.builtin.copy:
                    dest: /etc/netplan/60-lab.yaml
                    mode: "0600"
                    content: |
                      network:
                        version: 2
                        ethernets:
                          eth1:
                            addresses: [{{ ip_dmz }}/24]
                            routes:
                              - to: default
                                via: {{ ip_router_dmz }}
                  register: netplan

                - name: Aplica la xarxa
                  ansible.builtin.command: netplan apply
                  when: netplan.changed
            YAML, 'Dona adreça fixa i porta d\'enllaç al client de la LAN i al servidor de la DMZ.', $wan);
    }

    /** Comú a les accions de nftables: un fitxer principal que inclou el de cada bloc. */
    private const NFTABLES = <<<'YAML'
        - hosts: lab_router
          become: true
          gather_facts: false
          tasks:
            - name: Fitxer principal de nftables, que inclou els de cada bloc
              ansible.builtin.copy:
                dest: /etc/nftables.conf
                content: |
                  #!/usr/sbin/nft -f
                  flush ruleset
                  include "/etc/nftables.d/*.nft"
              register: principal

            - name: Carpeta de les regles
              ansible.builtin.file:
                path: /etc/nftables.d
                state: directory

        YAML;

    private const NFTABLES_APPLY = <<<'YAML'

            - name: nftables en marxa en arrencar
              ansible.builtin.systemd_service:
                name: nftables
                enabled: true
                state: started

            - name: Carrega les regles
              ansible.builtin.systemd_service:
                name: nftables
                state: restarted
              when: principal.changed or regles.changed
        YAML;

    private function firewall(Module $module): void
    {
        $block = $this->block($module, 2, 'Tallafoc');

        $this->task($block, 1, 'Tallafoc del router amb nftables', <<<'MD'
            El router protegeix la LAN i la DMZ. Amb **nftables**, i la política per defecte de **descartar**:

            - **Entrada al router:** només SSH per la xarxa de gestió (`eth0`), tot el que ve de la LAN (el router
              li dona serveis: DHCP, proxy) i el ping. Res més des de la WAN ni des de la DMZ.
            - **Reenviament:** la LAN pot anar a la WAN i a la DMZ; la DMZ pot anar a la WAN però **no a la LAN**
              (si algú entra en un servidor de la DMZ, no ha d'arribar als equips de l'empresa); des de la WAN
              només es pot arribar a la DMZ als ports web (80, 443) i DNS (53).
            - Les respostes de les connexions acceptades sempre passen (*established, related*).

            Desa les regles a `/etc/nftables.d/` i fes que `/etc/nftables.conf` les inclogui, per no perdre el NAT.
            MD, [
            [
                'nom' => 'Router: el reenviament descarta per defecte',
                'executor' => Executor::Ssh,
                'descripcio' => 'La cadena de reenviament (*hook forward*) té `policy drop`. Comprova-ho: `sudo nft list ruleset`.',
                'info' => $this->info(
                    'comprovar el principi bàsic del tallafoc: el que no està permès, està prohibit.',
                    'amb la política d\'acceptar, una regla oblidada deixa passar el trànsit; amb la de descartar, una regla oblidada només talla un servei, i es nota de seguida.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_router
                    sudo -n nft list ruleset 2>/dev/null | grep -Eq 'hook forward .*policy drop' || { echo "HEIMDEYE_MSG: cap cadena forward amb policy drop"; exit 1; }
                    echo "HEIMDEYE_MSG: forward amb policy drop"
                    SH,
            ],
            [
                'nom' => 'La LAN arriba a la DMZ',
                'executor' => Executor::Ssh,
                'descripcio' => 'Des del client es fa ping al servidor de la DMZ (10.X.2.10). Comprova-ho des del client: `ping 10.X.2.10`.',
                'info' => $this->info(
                    'comprovar que el tallafoc no talla el que ha de passar.',
                    'un tallafoc que ho talla tot és tan dolent com un que no talla res: els treballadors han de poder fer servir els servidors de l\'empresa.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_client
                    ping -c1 -W2 "$HE_IP_DMZ" >/dev/null 2>&1 || { echo "HEIMDEYE_MSG: el client no arriba a $HE_IP_DMZ"; exit 1; }
                    echo "HEIMDEYE_MSG: el client arriba a la DMZ"
                    SH,
            ],
            [
                'nom' => 'La DMZ no arriba a la LAN',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'descripcio' => 'Des del servidor de la DMZ, el client de la LAN **no** respon, però la WAN sí. Comprova-ho des del servidor: `ping 10.X.1.100` no ha de respondre.',
                'info' => $this->info(
                    'veure un check que passa quan alguna cosa falla: el tallafoc ha de tallar.',
                    'primer es comprova que la DMZ surt a la WAN; si no, que el client no respongui no voldria dir res (potser no hi ha encaminament). És la raó de ser de la DMZ: un servidor compromès no ha d\'arribar a la LAN.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_dmz
                    ping -c1 -W2 "$HE_IP_HEIMDEYE_WAN" >/dev/null 2>&1 || { echo "HEIMDEYE_MSG: la DMZ no surt a la WAN: primer, el bloc 1"; exit 1; }
                    ping -c1 -W2 "$HE_IP_CLIENT" >/dev/null 2>&1 && { echo "HEIMDEYE_MSG: la DMZ arriba al client $HE_IP_CLIENT"; exit 1; }
                    echo "HEIMDEYE_MSG: la DMZ surt a la WAN però no arriba a la LAN"
                    SH,
            ],
            [
                'nom' => 'Router tancat des de la WAN',
                'executor' => Executor::Local,
                'descripcio' => 'Des de la WAN, el router respon al ping però no té l\'SSH obert. HeimdEye ho prova des de 203.0.113.2.',
                'info' => $this->info(
                    'mirar el router des de fora, com un atacant d\'Internet.',
                    'el servidor SSH del router escolta a totes les interfícies: només el tallafoc evita que s\'hi pugui provar contrasenyes des de fora. El ping demostra que la WAN està ben configurada.',
                ),
                'codi' => <<<'SH'
                    ping -c1 -W2 "$HE_IP_WAN" >/dev/null 2>&1 || { echo "HEIMDEYE_MSG: el router no respon a $HE_IP_WAN: primer, el bloc 1"; exit 1; }
                    nc -z -w2 "$HE_IP_WAN" 22 2>/dev/null && { echo "HEIMDEYE_MSG: l'SSH del router és obert a la WAN"; exit 1; }
                    echo "HEIMDEYE_MSG: el router respon al ping i té l'SSH tancat a la WAN"
                    SH,
            ],
        ]);

        $this->action($module, $block, 4, 'Router: tallafoc', self::NFTABLES.<<<'YAML'
                - name: Regles de filtratge
                  ansible.builtin.copy:
                    dest: /etc/nftables.d/20-filtre.nft
                    content: |
                      table inet filtre {
                        chain input {
                          type filter hook input priority filter; policy drop;
                          ct state established,related accept
                          iifname "lo" accept
                          iifname "eth0" tcp dport 22 accept comment "gestió: HeimdEye"
                          iifname "eth2" accept comment "la LAN fa servir el router"
                          icmp type echo-request accept
                        }
                        chain forward {
                          type filter hook forward priority filter; policy drop;
                          ct state established,related accept
                          iifname "eth2" oifname { "eth1", "eth3" } accept
                          iifname "eth3" oifname "eth1" accept
                          iifname "eth1" oifname "eth3" tcp dport { 80, 443 } accept
                          iifname "eth1" oifname "eth3" meta l4proto { tcp, udp } th dport 53 accept
                        }
                      }
                  register: regles
            YAML.self::NFTABLES_APPLY, 'Tallafoc del router amb política de descartar: la LAN surt a tot arreu, la DMZ només a la WAN i des de la WAN només s\'entra a la DMZ pels ports web i DNS.', $this->checkIds($block));
    }

    private function dhcp(Module $module): void
    {
        $block = $this->block($module, 3, 'DHCP a la LAN');

        $this->task($block, 1, 'Servidor DHCP Kea al router', <<<'MD'
            Els equips de la LAN han de rebre la configuració de xarxa per **DHCP**. Instal·la **Kea** al router
            (`kea-dhcp4-server`), escoltant només a la LAN (`eth2`):

            - Rang dinàmic: 10.X.1.101 a 10.X.1.199.
            - Porta d'enllaç: el router (10.X.1.1). DNS: el servidor de la DMZ (10.X.2.10). Domini: `usuari.heimdeye.test`.
            - **Reserva** per al client: per la seva MAC, sempre 10.X.1.100, fora del rang dinàmic.

            Després, canvia el client a DHCP. Ha de continuar tenint la mateixa adreça.
            MD, [
            [
                'nom' => 'Kea en marxa al router',
                'executor' => Executor::Ssh,
                'descripcio' => 'El servei `kea-dhcp4-server` està en marxa al router. Comprova-ho: `systemctl status kea-dhcp4-server`.',
                'info' => $this->info(
                    'comprovar que el servidor DHCP funciona.',
                    'Kea és el servidor DHCP actual de l\'ISC (el clàssic isc-dhcp-server ja no es manté).',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_router
                    systemctl is-active --quiet kea-dhcp4-server || { echo "HEIMDEYE_MSG: kea-dhcp4-server no està en marxa"; exit 1; }
                    echo "HEIMDEYE_MSG: kea-dhcp4-server en marxa"
                    SH,
            ],
            [
                'nom' => 'El client té adreça per DHCP',
                'executor' => Executor::Ssh,
                'descripcio' => 'L\'adreça d\'`eth1` del client és dinàmica (DHCP). Comprova-ho des del client: `ip addr show eth1` diu `dynamic`.',
                'info' => $this->info(
                    'comprovar el resultat des del client, no la configuració del servidor.',
                    'el servidor pot semblar ben configurat i no donar adreces (interfície equivocada, rang fora de la xarxa): només el client ho demostra.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_client
                    adreca=$(ip -4 -o addr show dev eth1 2>/dev/null | grep dynamic | awk '{print $4}' | head -1)
                    [ -n "$adreca" ] || { echo "HEIMDEYE_MSG: eth1 no té cap adreça per DHCP"; exit 1; }
                    echo "HEIMDEYE_MSG: eth1 té $adreca per DHCP"
                    SH,
            ],
            [
                'nom' => 'El client rep la seva reserva',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'descripcio' => 'El client rep per DHCP la reserva 10.X.1.100. Comprova-ho des del client: `ip addr show eth1`.',
                'info' => $this->info(
                    'comprovar una reserva per MAC.',
                    'els equips que altres han de trobar (impressores, servidors) necessiten sempre la mateixa adreça; amb una reserva es gestiona tot des del servidor DHCP.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_client
                    ip -4 -o addr show dev eth1 2>/dev/null | grep dynamic | grep -qF " $HE_IP_CLIENT/" \
                      || { echo "HEIMDEYE_MSG: el client no té $HE_IP_CLIENT per DHCP: $(ip -4 -o addr show dev eth1 | awk '{print $4}' | head -1)"; exit 1; }
                    echo "HEIMDEYE_MSG: el client té la reserva $HE_IP_CLIENT"
                    SH,
            ],
            [
                'nom' => 'El client rep la porta d\'enllaç',
                'executor' => Executor::Ssh,
                'descripcio' => 'La ruta per defecte del client és via 10.X.1.1, apresa per DHCP. Comprova-ho des del client: `ip route`.',
                'info' => $this->info(
                    'comprovar les opcions del DHCP, no només l\'adreça.',
                    'amb adreça però sense porta d\'enllaç, el client només veu la seva xarxa: és un error habitual en configurar un servidor DHCP.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_client
                    ip route show default 2>/dev/null | grep -q "via $HE_IP_ROUTER_LAN .*proto dhcp" \
                      || { echo "HEIMDEYE_MSG: la ruta per defecte no és via $HE_IP_ROUTER_LAN per DHCP"; exit 1; }
                    echo "HEIMDEYE_MSG: porta d'enllaç $HE_IP_ROUTER_LAN per DHCP"
                    SH,
            ],
            [
                'nom' => 'El router ha concedit l\'adreça del client',
                'executor' => Executor::Ssh,
                'tipus' => CheckKind::Optional,
                'descripcio' => 'El fitxer de concessions de Kea té la del client. Comprova-ho al router: `sudo cat /var/lib/kea/kea-leases4.csv`.',
                'info' => $this->info(
                    'veure el mateix des del servidor.',
                    'quan un client no rep adreça, les concessions del servidor diuen si la petició ha arribat.',
                ),
                'codi' => <<<'SH'
                    # hosts: lab_router
                    sudo -n grep -qF "$HE_IP_CLIENT," /var/lib/kea/kea-leases4.csv 2>/dev/null || { echo "HEIMDEYE_MSG: Kea no ha concedit $HE_IP_CLIENT"; exit 1; }
                    echo "HEIMDEYE_MSG: concessió de $HE_IP_CLIENT a Kea"
                    SH,
            ],
        ]);

        $this->action($module, $block, 5, 'Router: servidor DHCP Kea', <<<'YAML'
            - hosts: lab_router
              become: true
              gather_facts: false
              tasks:
                - name: Configuració de Kea per a la LAN, amb la reserva del client
                  ansible.builtin.copy:
                    dest: /etc/kea/kea-dhcp4.conf
                    content: |
                      {
                        "Dhcp4": {
                          "interfaces-config": { "interfaces": [ "eth2" ] },
                          "lease-database": { "type": "memfile", "persist": true, "name": "/var/lib/kea/kea-leases4.csv" },
                          "valid-lifetime": 3600,
                          "subnet4": [
                            {
                              "id": 1,
                              "subnet": "{{ xarxa_lan }}",
                              "pools": [ { "pool": "{{ ip_dhcp_inici }} - {{ ip_dhcp_fi }}" } ],
                              "option-data": [
                                { "name": "routers", "data": "{{ ip_router_lan }}" },
                                { "name": "domain-name-servers", "data": "{{ ip_dmz }}" },
                                { "name": "domain-name", "data": "{{ nom_zona }}" }
                              ],
                              "reservations": [
                                { "hw-address": "{{ mac_client }}", "ip-address": "{{ ip_client }}", "hostname": "client" }
                              ]
                            }
                          ]
                        }
                      }
                  register: kea

                - name: Kea en marxa en arrencar
                  ansible.builtin.systemd_service:
                    name: kea-dhcp4-server
                    enabled: true
                    state: started

                - name: Recarrega la configuració
                  ansible.builtin.systemd_service:
                    name: kea-dhcp4-server
                    state: restarted
                  when: kea.changed
            YAML, 'Instal·la la configuració de Kea per a la LAN (rang, porta d\'enllaç, DNS i la reserva del client) i l\'engega.', $this->checkIds($block, ['Kea en marxa al router']));

        $this->action($module, $block, 6, 'Client: adreça per DHCP', <<<'YAML'
            - hosts: lab_client
              become: true
              gather_facts: false
              tasks:
                - name: eth1 per DHCP, identificat per la MAC (la de la reserva)
                  ansible.builtin.copy:
                    dest: /etc/netplan/60-lab.yaml
                    mode: "0600"
                    content: |
                      network:
                        version: 2
                        ethernets:
                          eth1:
                            dhcp4: true
                            dhcp-identifier: mac
                  register: netplan

                - name: Aplica la xarxa
                  ansible.builtin.command: netplan apply
                  when: netplan.changed
            YAML, 'Canvia el client a DHCP. Amb la reserva de Kea, continua tenint la mateixa adreça.', $this->checkIds($block));
    }

    private function block(Module $module, int $number, string $title): Block
    {
        return Block::updateOrCreate(
            ['modul_id' => $module->id, 'numero' => $number],
            ['titol' => $title, 'data_inici' => '2026-09-01', 'data_fi' => '2027-08-31', 'posicio' => $number],
        );
    }

    /** @param  list<array<string, mixed>>  $checks */
    private function task(Block $block, int $position, string $title, string $statement, array $checks): void
    {
        $task = $block->tasks()->updateOrCreate(['titol' => $title], ['enunciat' => $statement, 'posicio' => $position]);

        foreach ($checks as $i => $def) {
            $check = $task->checks()->updateOrCreate(
                ['nom' => $def['nom']],
                [
                    'descripcio' => $def['descripcio'],
                    'info' => $def['info'],
                    'executor' => $def['executor'],
                    'tipus' => $def['tipus'] ?? CheckKind::Required,
                    'punts' => $def['punts'] ?? 1,
                    'es_actiu' => true,
                    'posicio' => $i + 1,
                ],
            );
            if (! $check->versions()->exists()) {
                $check->versions()->create([
                    'versio' => 1,
                    'codi' => $def['codi'],
                    'parametres' => null,
                    'temps_max_seg' => 15,
                ]);
            }
        }
    }

    /**
     * Ids dels checks del bloc, tots o els que es diuen així.
     *
     * @param  list<string>|null  $names
     * @return list<int>
     */
    private function checkIds(Block $block, ?array $names = null): array
    {
        return Check::whereIn('tasca_id', $block->tasks()->pluck('id'))
            ->when($names, fn ($q) => $q->whereIn('nom', $names))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /** @param  list<int>  $verification  checks que es llancen en acabar */
    private function action(Module $module, Block $block, int $position, string $name, string $code, string $description, array $verification): void
    {
        $action = Action::updateOrCreate(
            ['modul_id' => $module->id, 'nom' => $name],
            ['bloc_id' => $block->id, 'descripcio' => $description, 'executor' => Executor::Ansible, 'posicio' => $position],
        );
        if (! $action->versions()->exists()) {
            $action->versions()->create([
                'versio' => 1,
                'codi' => $code,
                'parametres' => null,
                'checks_verificacio' => $verification ?: null,
                'temps_max_seg' => 120,
            ]);
        }
    }

    private function info(string $objective, string $why): string
    {
        return "**Objectiu del test:** {$objective}\n\n**Justificació:** {$why}\n";
    }
}
