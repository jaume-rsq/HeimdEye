<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Block;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Bloc 3 (la passarel·la de xarxa): un Linux fent de router entre l'aula, la LAN i la DMZ,
 * amb IP forwarding, NAT de sortida (masquerade/SNAT) i NAT de destinació (DNAT).
 * Cada check porta a `descripcio` el text per a l'alumne. L'`info` (objectiu i justificació)
 * és a `data/check_info.php`.
 *
 * Decisions de disseny:
 *  - Tots els checks són `local`: HeimdEye entra al router (ip_router) per SSH amb l'accés
 *    del mòdul, com al Bloc 5. No es valida cap altra VM (no hi ha tipus d'equip per a les VM
 *    d'alumne de la LAN o la DMZ), així que no es pot provar que un equip de la LAN surti
 *    de debò: es comprova el que el router declara i el que n'arriba des de l'aula.
 *  - La LAN i la DMZ es reconeixen per xarxa (172.X.0.0/22 i 10.X.0.0/22, amb HE_X), no per
 *    una adreça concreta del router.
 *  - Si a ip_router respon un MikroTik, els checks donen `omes` (no canvia l'estat ni els
 *    punts): l'alumne ja pot haver canviat de router. Si no respon cap, fallen.
 *  - Les tasques 1 (xarxes dins Proxmox) i 6 (backup) de l'enunciat no tenen checks: la
 *    primera és documentació i la segona no es valida en aquest bloc.
 *  - L'accés de jaume al router no té acció pròpia en aquest bloc.
 *  - Una versió només corregeix codi: els checks que ja tenen versions no es toquen.
 */
class Block03GatewaySeeder extends Seeder
{
    /**
     * Comú als checks del router: ssh amb l'accés del mòdul i detecció del sistema.
     * A RouterOS, ':put' escriu el text i surt amb 0. A un shell Linux, ':put' no existeix: surt amb
     * 127 i no escriu res a stdout. Només el 255 d'ssh vol dir que no s'ha pogut entrar.
     */
    private const ROUTER = <<<'SH'
        # HE_SSH_OPTS: no desa les claus de host (els alumnes reinstal·len les VM).
        opts=(-o BatchMode=yes -o ConnectTimeout=5 $HE_SSH_OPTS)
        [ -n "$HE_SSH_KEY" ] && opts+=(-i "$HE_SSH_KEY")
        r() { ssh -n "${opts[@]}" "$HE_SSH_USER@$HE_IP_ROUTER" "$@"; }
        so=$(r ':put routeros' 2>/dev/null); rc=$?
        if [ $rc -eq 255 ]; then sistema=cap
        elif [ "$(echo "$so" | tr -d '\r')" = routeros ]; then sistema=mikrotik
        else sistema=linux; fi
        [ $sistema = cap ] && { echo "HeimdEye no pot entrar al router ($HE_IP_ROUTER)"; exit 1; }
        [ $sistema = linux ] || { echo "HEIMDEYE_OMES: ara respon el MikroTik, no el router Linux"; exit 0; }
        SH;

    /** Interfícies del router a cada xarxa: aula (ip_router), LAN 172.X.0.0/22 i DMZ 10.X.0.0/22. */
    private const INTERFICIES = <<<'SH'
        addr=$(r ip -4 -o addr show) || { echo "No es poden llegir les adreces del router"; exit 1; }
        ifof() { echo "$addr" | awk -v re="$1" '{ split($4, a, "/"); if (a[1] ~ re) print $2 }' | sort -u | head -1; }
        aula_if=$(ifof "^${HE_IP_ROUTER//./[.]}\$")
        lan_if=$(ifof "^172[.]$HE_X[.][0-3][.]")
        dmz_if=$(ifof "^10[.]$HE_X[.][0-3][.]")
        SH;

    public function run(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();

        $block = Block::updateOrCreate(
            ['modul_id' => $module->id, 'numero' => 3],
            [
                'titol' => 'La passarel·la de xarxa (Gateway o router)',
                'data_inici' => '2026-09-28',
                'data_fi' => '2026-10-02',
                'posicio' => 3,
            ],
        );

        $this->task($block, 1, 'Un Linux com a router', <<<'MD'
            Una VM Linux amb tres interfícies, una a cada xarxa (l'aula, la teva LAN i la teva DMZ), fa
            de passarel·la entre elles. L'adreça de l'aula és la darrera adreça útil de la teva xarxa
            d'aula, la de la LAN és de `172.X.0.0/22` i la de la DMZ de `10.X.0.0/22` (X = 20 + el
            número de taula). HeimdEye hi entra per SSH amb l'usuari `jaume` i la seva clau, i `jaume`
            ha de poder fer `sudo` sense contrasenya.
            MD, [
            [
                'nom' => 'HeimdEye entra al router Linux',
                'descripcio' => 'HeimdEye entra al router per SSH com a jaume i hi pot fer `sudo` sense contrasenya. Comprova-ho al router: la clau del professor és a `/home/jaume/.ssh/authorized_keys` i `sudo -n true` no et demana contrasenya.',
                'codi' => self::ROUTER."\n".<<<'SH'
                    r sudo -n true >/dev/null 2>&1 || { echo "Router Linux: jaume no pot fer sudo sense contrasenya"; exit 1; }
                    echo "Router Linux: $(r '. /etc/os-release; echo $PRETTY_NAME')"
                    SH,
            ],
            [
                'nom' => 'El router té tres interfícies a les tres xarxes',
                'punts' => 2,
                'temps_max_seg' => 30,
                'descripcio' => 'El router té tres interfícies diferents, cadascuna amb adreça de l\'aula (la teva darrera adreça útil), de la LAN (172.X.0.0/22) i de la DMZ (10.X.0.0/22). Comprova-ho: `ip -br -4 addr`.',
                'codi' => self::ROUTER."\n".self::INTERFICIES."\n".<<<'SH'
                    [ -n "$aula_if" ] || { echo "HEIMDEYE_MSG: cap interfície té l'adreça de l'aula ($HE_IP_ROUTER)"; exit 1; }
                    [ -n "$lan_if" ] || { echo "HEIMDEYE_MSG: cap interfície té adreça de la LAN (172.$HE_X.0.0/22)"; exit 1; }
                    [ -n "$dmz_if" ] || { echo "HEIMDEYE_MSG: cap interfície té adreça de la DMZ (10.$HE_X.0.0/22)"; exit 1; }
                    [ "$(printf '%s\n' "$aula_if" "$lan_if" "$dmz_if" | sort -u | wc -l)" -eq 3 ] \
                      || { echo "HEIMDEYE_MSG: calen tres interfícies diferents (aula $aula_if, LAN $lan_if, DMZ $dmz_if)"; exit 1; }
                    echo "HEIMDEYE_MSG: aula $aula_if, LAN $lan_if, DMZ $dmz_if"
                    SH,
            ],
            [
                'nom' => 'Ruta per defecte cap a l\'aula',
                'temps_max_seg' => 30,
                'descripcio' => 'El router té una ruta per defecte i surt per la interfície de l\'aula. Comprova-ho: `ip route show default`.',
                'codi' => self::ROUTER."\n".self::INTERFICIES."\n".<<<'SH'
                    def=$(r ip -4 route show default | head -1)
                    [ -n "$def" ] || { echo "HEIMDEYE_MSG: el router no té ruta per defecte"; exit 1; }
                    dev=$(echo "$def" | awk '{ for (i = 1; i < NF; i++) if ($i == "dev") print $(i + 1) }')
                    via=$(echo "$def" | awk '{ for (i = 1; i < NF; i++) if ($i == "via") print $(i + 1) }')
                    [ -n "$aula_if" ] || { echo "HEIMDEYE_MSG: cap interfície té l'adreça de l'aula ($HE_IP_ROUTER)"; exit 1; }
                    [ "$dev" = "$aula_if" ] || { echo "HEIMDEYE_MSG: la ruta per defecte surt per $dev, no per la interfície de l'aula ($aula_if)"; exit 1; }
                    echo "HEIMDEYE_MSG: ruta per defecte via ${via:-directa} per $dev"
                    SH,
            ],
        ]);

        $this->task($block, 2, 'IP forwarding i encaminament', <<<'MD'
            Activa l'IP forwarding al kernel del router perquè reenviï paquets entre les seves
            interfícies, i comprova que la LAN i la DMZ s'arriben a través d'ell.
            MD, [
            [
                'nom' => 'IP forwarding actiu',
                'punts' => 2,
                'descripcio' => 'El kernel del router reenvia paquets entre interfícies (`net.ipv4.ip_forward = 1`). Comprova-ho: `cat /proc/sys/net/ipv4/ip_forward` ha de donar 1.',
                'codi' => self::ROUTER."\n".<<<'SH'
                    v=$(r cat /proc/sys/net/ipv4/ip_forward) || { echo "No es pot llegir ip_forward"; exit 1; }
                    [ "$v" = 1 ] || { echo "HEIMDEYE_MSG: net.ipv4.ip_forward val $v: el router no reenvia paquets"; exit 1; }
                    echo "HEIMDEYE_MSG: net.ipv4.ip_forward = 1"
                    SH,
            ],
            [
                'nom' => 'IP forwarding persistent',
                'tipus' => CheckKind::Optional,
                'descripcio' => 'L\'IP forwarding queda configurat perquè sobrevisqui un reinici (`net.ipv4.ip_forward=1` a `/etc/sysctl.conf` o a `/etc/sysctl.d/`). Comprova-ho: `grep -r ip_forward /etc/sysctl.conf /etc/sysctl.d/`.',
                'codi' => self::ROUTER."\n".<<<'SH'
                    r 'cat /etc/sysctl.conf /etc/sysctl.d/*.conf /run/sysctl.d/*.conf /usr/lib/sysctl.d/*.conf 2>/dev/null' \
                      | grep -Eq '^[[:space:]]*net\.ipv4\.ip_forward[[:space:]]*=[[:space:]]*1' \
                      || { echo "HEIMDEYE_MSG: ip_forward=1 no és a cap fitxer sysctl: es perdria en reiniciar"; exit 1; }
                    echo "HEIMDEYE_MSG: ip_forward=1 a la configuració de sysctl"
                    SH,
            ],
            [
                'nom' => 'El router encamina entre la LAN i la DMZ',
                'punts' => 2,
                'temps_max_seg' => 30,
                'descripcio' => 'El router sap encaminar un paquet de la LAN cap a la DMZ i a l\'inrevés, per interfícies diferents. Comprova-ho: `ip route get 10.X.0.11 from 172.X.0.11 iif <interfície LAN>`.',
                'codi' => self::ROUTER."\n".self::INTERFICIES."\n".<<<'SH'
                    [ -n "$lan_if" ] && [ -n "$dmz_if" ] \
                      || { echo "HEIMDEYE_MSG: falta la interfície de la LAN (172.$HE_X.0.0/22) o la de la DMZ (10.$HE_X.0.0/22)"; exit 1; }
                    lan_ip="172.$HE_X.0.11"; dmz_ip="10.$HE_X.0.11"
                    o1=$(r ip route get "$dmz_ip" from "$lan_ip" iif "$lan_if" 2>&1) \
                      || { echo "HEIMDEYE_MSG: la LAN no arriba a la DMZ pel router: $(echo "$o1" | head -1)"; exit 1; }
                    echo "$o1" | grep -Eq "dev $dmz_if( |\$)" \
                      || { echo "HEIMDEYE_MSG: de la LAN a la DMZ el router no surt per $dmz_if"; exit 1; }
                    o2=$(r ip route get "$lan_ip" from "$dmz_ip" iif "$dmz_if" 2>&1) \
                      || { echo "HEIMDEYE_MSG: la DMZ no arriba a la LAN pel router: $(echo "$o2" | head -1)"; exit 1; }
                    echo "$o2" | grep -Eq "dev $lan_if( |\$)" \
                      || { echo "HEIMDEYE_MSG: de la DMZ a la LAN el router no surt per $lan_if"; exit 1; }
                    echo "HEIMDEYE_MSG: LAN ($lan_if) i DMZ ($dmz_if) s'encaminen en tots dos sentits"
                    SH,
            ],
        ]);

        $this->task($block, 3, 'NAT de sortida', <<<'MD'
            Configura NAT de sortida (masquerade o SNAT) perquè els equips de la teva xarxa privada
            surtin cap a l'exterior (la xarxa de l'aula) a través del router, tot i tenir adreces
            privades no encaminables. Pots fer servir iptables o nftables.
            MD, [
            [
                'nom' => 'NAT de sortida (masquerade o SNAT)',
                'punts' => 2,
                'descripcio' => 'El router té una regla de NAT de sortida (MASQUERADE o SNAT) a POSTROUTING. Comprova-ho: `sudo iptables -t nat -S POSTROUTING` o `sudo nft list ruleset`.',
                'codi' => self::ROUTER."\n".<<<'SH'
                    ipt=$(r sudo -n iptables -t nat -S POSTROUTING 2>/dev/null | grep -E -- '-j (MASQUERADE|SNAT)' | head -1)
                    if [ -n "$ipt" ]; then echo "HEIMDEYE_MSG: iptables: $ipt"; exit 0; fi
                    nft=$(r sudo -n nft list ruleset 2>/dev/null | grep -Ei 'masquerade|snat( ip)? to' | head -1 | sed 's/^[[:space:]]*//')
                    if [ -n "$nft" ]; then echo "HEIMDEYE_MSG: nftables: $nft"; exit 0; fi
                    echo "HEIMDEYE_MSG: no hi ha cap regla MASQUERADE o SNAT (ni a iptables ni a nftables)"
                    exit 1
                    SH,
            ],
            [
                'nom' => 'El NAT de sortida ha tractat trànsit',
                'tipus' => CheckKind::Optional,
                'descripcio' => 'La regla de NAT de sortida ja ha traduït paquets (comptador major que 0), és a dir, algun equip de la teva xarxa privada ha sortit de debò. Comprova-ho: `sudo iptables -t nat -L POSTROUTING -n -v`. Només es pot comprovar amb iptables.',
                'codi' => self::ROUTER."\n".<<<'SH'
                    n=$(r sudo -n iptables -t nat -L POSTROUTING -n -v -x 2>/dev/null | awk '$3 == "MASQUERADE" || $3 == "SNAT" { s += $1 } END { print s + 0 }')
                    if [ "${n:-0}" -gt 0 ]; then echo "HEIMDEYE_MSG: el NAT ha traduït $n paquets"; exit 0; fi
                    if r sudo -n iptables -t nat -S POSTROUTING 2>/dev/null | grep -qE -- '-j (MASQUERADE|SNAT)'; then
                      echo "HEIMDEYE_MSG: la regla existeix però no ha traduït cap paquet: surt algun equip de la LAN o la DMZ?"; exit 1
                    fi
                    echo "HEIMDEYE_OMES: no hi ha regla de NAT a iptables (si fas servir nftables, no es pot comprovar)"
                    exit 0
                    SH,
            ],
        ]);

        $this->task($block, 4, 'NAT de destinació (port forwarding)', <<<'MD'
            Configura una regla de DNAT perquè un servei d'una VM de la teva xarxa privada (LAN o DMZ)
            sigui accessible des de fora a través d'un port del router. Comprova que respon des de la
            xarxa de l'aula.
            MD, [
            [
                'nom' => 'Regla DNAT cap a la xarxa privada',
                'punts' => 2,
                'descripcio' => 'El router té una regla de DNAT a PREROUTING cap a una adreça de la LAN (172.X) o de la DMZ (10.X). Comprova-ho: `sudo iptables -t nat -S PREROUTING` o `sudo nft list ruleset`.',
                'codi' => self::ROUTER."\n".<<<'SH'
                    ipt=$(r sudo -n iptables -t nat -S PREROUTING 2>/dev/null | grep -E -- "-j DNAT --to-destination (172[.]$HE_X[.]|10[.]$HE_X[.])" | head -1)
                    if [ -n "$ipt" ]; then echo "HEIMDEYE_MSG: iptables: $ipt"; exit 0; fi
                    nft=$(r sudo -n nft list ruleset 2>/dev/null | grep -E "dnat( ip)? to (172[.]$HE_X[.]|10[.]$HE_X[.])" | head -1 | sed 's/^[[:space:]]*//')
                    if [ -n "$nft" ]; then echo "HEIMDEYE_MSG: nftables: $nft"; exit 0; fi
                    echo "HEIMDEYE_MSG: cap regla DNAT cap a la LAN (172.$HE_X.0.0/22) ni a la DMZ (10.$HE_X.0.0/22)"
                    exit 1
                    SH,
            ],
            [
                'nom' => 'El servei publicat respon des de l\'aula',
                'punts' => 2,
                'temps_max_seg' => 30,
                'descripcio' => 'Des de la xarxa de l\'aula, el port que has publicat amb DNAT al router respon (connexió TCP). Comprova-ho des de l\'estació: `nc -vz <adreça del router a l\'aula> <port>`.',
                'codi' => self::ROUTER."\n".<<<'SH'
                    rules=$( { r sudo -n iptables -t nat -S PREROUTING 2>/dev/null | grep -E -- "-j DNAT --to-destination (172[.]$HE_X[.]|10[.]$HE_X[.])"
                               r sudo -n nft list ruleset 2>/dev/null | grep -E "dnat( ip)? to (172[.]$HE_X[.]|10[.]$HE_X[.])"; } )
                    [ -n "$rules" ] || { echo "HEIMDEYE_MSG: no hi ha cap regla DNAT cap a la LAN o la DMZ"; exit 1; }
                    ports=$(echo "$rules" | grep -oE '(--dport|dport) [0-9]+' | awk '{ print $2 }' | sort -un)
                    [ -n "$ports" ] || { echo "HEIMDEYE_MSG: la regla DNAT no indica el port de destinació (--dport)"; exit 1; }
                    for p in $ports; do
                      timeout 4 bash -c "</dev/tcp/$HE_IP_ROUTER/$p" 2>/dev/null && { echo "HEIMDEYE_MSG: $HE_IP_ROUTER:$p respon"; exit 0; }
                    done
                    echo "HEIMDEYE_MSG: hi ha DNAT del port $(echo $ports | tr ' ' ','), però des de l'aula no s'arriba a $HE_IP_ROUTER (el servei està engegat? el forward deixa passar?)"
                    exit 1
                    SH,
            ],
        ]);
    }

    /** @param  list<array<string, mixed>>  $checks */
    private function task(Block $block, int $position, string $title, string $statement, array $checks): void
    {
        $task = $block->tasks()->updateOrCreate(['titol' => $title], ['enunciat' => $statement, 'posicio' => $position]);

        $info = (require database_path('seeders/data/check_info.php'))[$block->numero] ?? [];

        foreach ($checks as $i => $def) {
            $check = $task->checks()->updateOrCreate(
                ['nom' => $def['nom']],
                [
                    'descripcio' => $def['descripcio'],
                    'info' => $info[$def['nom']] ?? null,
                    'executor' => $def['executor'] ?? Executor::Local,
                    'tipus' => $def['tipus'] ?? CheckKind::Required,
                    'punts' => $def['punts'] ?? 1,
                    'actiu_des' => '2026-09-28',
                    'data_limit' => '2026-11-06 23:59:59',
                    'posicio' => $i + 1,
                ],
            );

            // No es toca el codi d'un check que ja té versions: es corregeix des del panell (versió nova).
            if (! $check->versions()->exists()) {
                $check->versions()->create([
                    'versio' => 1,
                    'codi' => $def['codi'],
                    'parametres' => $def['parametres'] ?? null,
                    'temps_max_seg' => $def['temps_max_seg'] ?? 20,
                ]);
            }
        }
    }
}
