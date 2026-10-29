<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Block;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Bloc 5 (tallafocs): la mateixa política de segmentació LAN-DMZ-WAN amb un router Linux
 * (iptables) o amb un MikroTik. Tots dos tenen l'adreça ip_router i només un pot estar engegat.
 *
 * Els checks obligatoris detecten quin router respon i comproven el mateix amb l'un o l'altre.
 * Els opcionals són específics d'un sistema i, si ara respon l'altre (o cap), donen `omes`
 * (HEIMDEYE_OMES:): no canvien l'estat de l'alumne ni li fan perdre punts. Cada check porta a
 * `descripcio` el text per a l'alumne: què es comprova i com ho pot verificar ell mateix.
 * L'`info` (objectiu i justificació) és a `data/check_info.php`.
 *
 * Aquest seeder també fa de bloc 4 del PDF (tallafocs amb iptables): la tasca 5 en recull els
 * apartats que els checks anteriors no cobrien (regles explícites de la LAN, DMZ → LAN, evidències,
 * desat de regles). Són només per al router Linux: amb un MikroTik donen `omes`. Els checks nous
 * són actius des del 2026-10-05; els anteriors, des del 2026-10-26 (veg. `actiu_des` a `task()`).
 */
class Block05FirewallSeeder extends Seeder
{
    /**
     * Comú als checks del router: ssh amb l'accés del mòdul i detecció del sistema.
     * A RouterOS, ':put' escriu el text; a un Linux, ':put' no existeix (codi 127, sense sortida).
     * Només el codi 255 vol dir que ssh no ha pogut entrar.
     */
    private const ROUTER = <<<'SH'
        # HE_SSH_OPTS: no desa les claus de host (els alumnes reinstal·len les VM).
        opts=(-o BatchMode=yes -o ConnectTimeout=5 $HE_SSH_OPTS)
        [ -n "$HE_SSH_KEY" ] && opts+=(-i "$HE_SSH_KEY")
        r() { ssh "${opts[@]}" "$HE_SSH_USER@$HE_IP_ROUTER" "$@"; }
        so=$(r ':put routeros' 2>/dev/null); rc=$?
        # Només 255 vol dir que ssh no entra: un Linux respon 127 perquè «:put» no hi existeix.
        if [ $rc -eq 255 ]; then sistema=cap
        elif [ "$(echo "$so" | tr -d '\r')" = routeros ]; then sistema=mikrotik
        else sistema=linux; fi
        SH;

    private const REQUIRED = <<<'SH'
        [ $sistema = cap ] && { echo "HeimdEye no pot entrar al router ($HE_IP_ROUTER)"; exit 1; }
        SH;

    private const ONLY_LINUX = <<<'SH'
        [ $sistema = linux ] || { echo "HEIMDEYE_OMES: ara no respon el router Linux"; exit 0; }
        SH;

    private const ONLY_MIKROTIK = <<<'SH'
        [ $sistema = mikrotik ] || { echo "HEIMDEYE_OMES: ara no respon el MikroTik"; exit 0; }
        SH;

    /** Interfícies del router Linux a cada xarxa: aula (ip_router), LAN 172.X.0.0/22 i DMZ 10.X.0.0/22. */
    private const INTERFICIES = <<<'SH'
        addr=$(r ip -4 -o addr show) || { echo "No es poden llegir les adreces del router"; exit 1; }
        ifof() { echo "$addr" | awk -v re="$1" '{ split($4, a, "/"); if (a[1] ~ re) print $2 }' | sort -u | head -1; }
        aula_if=$(ifof "^${HE_IP_ROUTER//./[.]}\$")
        lan_if=$(ifof "^172[.]$HE_X[.][0-3][.]")
        dmz_if=$(ifof "^10[.]$HE_X[.][0-3][.]")
        SH;

    /**
     * Anàlisi estàtica de `iptables -S FORWARD`: quines regles ACCEPT (que no siguin només de
     * resposta, ESTABLISHED/RELATED) deixen passar LAN → WAN (lw), LAN → DMZ (ld) i DMZ → LAN (dl).
     * Una regla sense origen o sense destí compta per a tots. No avalua l'ordre de les regles, i si
     * el FORWARD salta a cadenes pròpies surt `omes`: no les segueix.
     */
    private const ANALISI_FORWARD = <<<'SH'
        fw=$(r sudo -n iptables -S FORWARD 2>/dev/null) || { echo "No es pot llegir iptables (sudo -n iptables -S FORWARD)"; exit 1; }
        cad=$(echo "$fw" | awk '$1 == "-A" { for (k = 2; k < NF; k++) if ($k == "-j" && $(k+1) !~ /^(ACCEPT|DROP|REJECT|LOG|RETURN|NFLOG|MARK|TRACE|CT|TCPMSS)$/) print $(k+1) }' | sort -u | paste -sd, -)
        [ -z "$cad" ] || { echo "HEIMDEYE_OMES: el FORWARD salta a cadenes pròpies ($cad) i el check no les segueix"; exit 0; }
        an=$(echo "$fw" | awk -v lan="$lan_if" -v dmz="$dmz_if" -v wan="$aula_if" -v x="$HE_X" '
          BEGIN { re_lan = "^172\\." x "\\."; re_dmz = "^10\\." x "\\." }
          $1 != "-A" { next }
          {
            i_ = o_ = s_ = d_ = st = ""; acc = 0; neg = 0
            for (k = 3; k <= NF; k++) {
              if ($k == "!") { neg = 1; continue }
              if ($k == "-i") i_ = $(k+1)
              else if ($k == "-o") o_ = $(k+1)
              else if ($k == "-s") s_ = $(k+1)
              else if ($k == "-d") d_ = $(k+1)
              else if ($k == "--state" || $k == "--ctstate") st = $(k+1)
              else if ($k == "-j" && $(k+1) == "ACCEPT") acc = 1
            }
            if (!acc || neg) next
            if (st != "" && st !~ /NEW/) next
            lan_o = (lan != "" && i_ == lan) || s_ ~ re_lan
            dmz_o = (dmz != "" && i_ == dmz) || s_ ~ re_dmz
            any_o = (i_ == "" && s_ == "")
            lan_d = (lan != "" && o_ == lan) || d_ ~ re_lan
            dmz_d = (dmz != "" && o_ == dmz) || d_ ~ re_dmz
            wan_d = (wan != "" && o_ == wan) || (o_ == "" && d_ != "" && d_ !~ re_lan && d_ !~ re_dmz)
            any_d = (o_ == "" && d_ == "")
            if (!lw && (lan_o || any_o) && (wan_d || any_d)) { lw = 1; rlw = $0 }
            if (!ld && (lan_o || any_o) && (dmz_d || any_d)) { ld = 1; rld = $0 }
            if (!dl && (dmz_o || any_o) && (lan_d || any_d)) { dl = 1; rdl = $0 }
          }
          END { print "lw|" lw + 0 "|" rlw; print "ld|" ld + 0 "|" rld; print "dl|" dl + 0 "|" rdl }')
        lw=$(echo "$an" | sed -n 's/^lw|\([01]\)|.*/\1/p'); ld=$(echo "$an" | sed -n 's/^ld|\([01]\)|.*/\1/p')
        dl=$(echo "$an" | sed -n 's/^dl|\([01]\)|.*/\1/p'); rdl=$(echo "$an" | sed -n 's/^dl|1|//p')
        SH;

    private const PORT = ['name' => 'PORT', 'label' => 'Port del servei publicat a la DMZ', 'default' => 80];

    public function run(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();

        $block = Block::updateOrCreate(
            ['modul_id' => $module->id, 'numero' => 5],
            [
                'titol' => 'Tallafocs: Linux (iptables) o MikroTik',
                'data_inici' => '2026-10-05',
                'data_fi' => '2026-10-16',
                'posicio' => 5,
            ],
        );

        $this->task($block, 1, 'Router actiu', <<<'MD'
            HeimdEye (192.168.20.12) entra per SSH al router de la vostra xarxa d'aula (darrera adreça
            útil), sigui el Linux o el MikroTik, amb l'usuari `jaume` i la seva clau. Al Linux, `jaume`
            ha de poder fer `sudo -n iptables`.
            MD, [
            [
                'nom' => 'HeimdEye entra al router (Linux o MikroTik)',
                'descripcio' => 'HeimdEye entra al router per SSH com a jaume i reconeix si és un Linux o un MikroTik. Al Linux, també comprova que jaume pot fer `sudo -n iptables -S`. Comprova-ho al router: la clau del professor és a `/home/jaume/.ssh/authorized_keys` (Linux) o a `/user ssh-keys print` (MikroTik).',
                'codi' => self::ROUTER."\n".<<<'SH'
                    case $sistema in
                      cap) echo "HeimdEye no pot entrar al router ($HE_IP_ROUTER)"; exit 1 ;;
                      mikrotik) echo "MikroTik, RouterOS $(r ':put [/system resource get version]' | tr -d '\r')" ;;
                      linux)
                        r sudo -n iptables -S FORWARD >/dev/null 2>&1 || { echo "Router Linux: jaume no pot fer sudo -n iptables"; exit 1; }
                        echo "Router Linux: $(r '. /etc/os-release; echo $PRETTY_NAME')" ;;
                    esac
                    SH,
            ],
        ]);

        $this->task($block, 2, 'Política de segmentació LAN-DMZ-WAN', <<<'MD'
            La mateixa política amb qualsevol dels dos routers: denegació per defecte al forward, filtratge
            amb estat, la LAN pot sortir cap a la DMZ i la WAN, i des de la WAN només s'arriba al servei
            publicat a la DMZ amb DNAT.
            MD, [
            [
                'nom' => 'Política per defecte DROP al forward',
                'punts' => 2,
                'descripcio' => 'Linux: la política de FORWARD és DROP, o la cadena acaba amb `-j DROP` sense condicions (`sudo iptables -S FORWARD`). MikroTik: la darrera regla activa de la cadena forward és un drop sense condicions (`/ip firewall filter print where chain=forward`).',
                'codi' => self::ROUTER."\n".self::REQUIRED."\n".<<<'SH'
                    if [ $sistema = linux ]; then
                      fw=$(r sudo -n iptables -S FORWARD) || { echo "No es pot llegir iptables"; exit 1; }
                      echo "$fw" | grep -qx -- '-P FORWARD DROP' && { echo "Política de FORWARD: DROP"; exit 0; }
                      echo "$fw" | tail -1 | grep -qx -- '-A FORWARD -j DROP' && { echo "FORWARD acaba amb -j DROP"; exit 0; }
                      echo "La política de FORWARD no és DROP"; exit 1
                    fi
                    out=$(r '/ip firewall filter print terse without-paging where chain=forward disabled=no') \
                      || { echo "No es pot llegir el tallafocs del MikroTik"; exit 1; }
                    last=$(echo "$out" | tr -d '\r' | grep -v '^\s*$' | tail -1)
                    echo "$last" | grep -q 'action=drop' || { echo "La darrera regla del forward no és un drop"; exit 1; }
                    echo "$last" | grep -Eq '(src|dst)-address|protocol=|in-interface|out-interface|connection-state' \
                      && { echo "La darrera regla del forward no ho descarta tot: $last"; exit 1; }
                    echo "Forward acaba amb drop"
                    SH,
            ],
            [
                'nom' => 'Filtratge amb estat (established, related)',
                'descripcio' => 'Hi ha una regla al forward que accepta el trànsit de connexions ja establertes o relacionades. Linux: `sudo iptables -S FORWARD | grep ESTABLISHED`. MikroTik: una regla del forward amb `connection-state=established,related` i acció accept o fasttrack-connection.',
                'codi' => self::ROUTER."\n".self::REQUIRED."\n".<<<'SH'
                    if [ $sistema = linux ]; then
                      r sudo -n iptables -S FORWARD | grep -- '-j ACCEPT' | grep -E -- '--(ct)?state ' | grep ESTABLISHED | grep -q RELATED \
                        || { echo "Cap regla de FORWARD accepta ESTABLISHED,RELATED"; exit 1; }
                    else
                      r '/ip firewall filter print terse without-paging where chain=forward disabled=no' | tr -d '\r' \
                        | grep -E 'action=(accept|fasttrack-connection)' | grep 'connection-state=' | grep established | grep -q related \
                        || { echo "Cap regla del forward accepta established,related"; exit 1; }
                    fi
                    echo "Filtratge amb estat al forward ($sistema)"
                    SH,
            ],
            [
                'nom' => 'La LAN surt a la WAN i a la DMZ',
                'executor' => Executor::Ansible,
                'tipus' => CheckKind::Optional,
                'punts' => 2,
                'temps_max_seg' => 90,
                'parametres' => [
                    ['name' => 'IP_DMZ', 'label' => 'Equip de la DMZ', 'default' => '{{ip_dns}}'],
                    ['name' => 'PORT_DMZ', 'label' => 'Port de l\'equip de la DMZ', 'default' => 53],
                ],
                'descripcio' => 'Des de ns2 (Windows Server de la LAN, 172.X.0.11) s\'obre una connexió TCP al DNS central (192.168.20.11:53, a la WAN) i a ns1 (10.X.0.11:53, a la DMZ). Cal tenir ns1 i ns2 del Bloc 6. Comprova-ho a ns2: `Test-NetConnection 192.168.20.11 -Port 53` i `Test-NetConnection 10.X.0.11 -Port 53`.',
                'codi' => <<<'YAML'
                    - hosts: vm_dns_windows
                      gather_facts: false
                      tasks:
                        - name: Connexions des de la LAN
                          ansible.windows.win_shell: |
                            $ProgressPreference = 'SilentlyContinue'
                            $wan = Test-NetConnection -ComputerName '{{ ip_dns_aula }}' -Port 53 -WarningAction SilentlyContinue
                            $dmz = Test-NetConnection -ComputerName '{{ IP_DMZ }}' -Port {{ PORT_DMZ }} -WarningAction SilentlyContinue
                            "$($wan.TcpTestSucceeded);$($dmz.TcpTestSucceeded)"
                          register: r
                          changed_when: false
                          failed_when: false

                        - name: La LAN arriba a la WAN i a la DMZ
                          ansible.builtin.assert:
                            that:
                              - r.rc == 0
                              - (r.stdout | trim).split(';')[0] == 'True'
                              - (r.stdout | trim).split(';')[1] == 'True'
                            fail_msg: "Des de la LAN (ns2): WAN {{ ip_dns_aula }}:53 i DMZ {{ IP_DMZ }}:{{ PORT_DMZ }} -> {{ (r.stdout ~ r.stderr) | trim | truncate(120) }}"
                            success_msg: "La LAN arriba a la WAN ({{ ip_dns_aula }}) i a la DMZ ({{ IP_DMZ }})"
                    YAML,
            ],
            [
                'nom' => 'Servei de la DMZ publicat amb DNAT',
                'punts' => 2,
                'parametres' => [self::PORT],
                'descripcio' => 'Hi ha un DNAT del port 80 de l\'adreça WAN del router cap a un equip de la DMZ (10.X.x.x), i des de la WAN s\'hi arriba. Linux: `sudo iptables -t nat -S PREROUTING`. MikroTik: `/ip firewall nat print where action=dst-nat`. Des d\'un equip de l\'aula: `curl http://<IP del router>`.',
                'codi' => self::ROUTER."\n".self::REQUIRED."\n".<<<'SH'
                    p=$HE_PARAM_PORT
                    if [ $sistema = linux ]; then
                      r sudo -n iptables -t nat -S PREROUTING | grep -Eq -- "--dport $p( |$).*-j DNAT --to-destination 10\.$HE_X\." \
                        || { echo "Cap DNAT del port $p cap a la DMZ (10.$HE_X.0.0)"; exit 1; }
                    else
                      r '/ip firewall nat print terse without-paging where action=dst-nat disabled=no' | tr -d '\r' \
                        | grep -E "dst-port=([0-9,]*,)?$p(,| |$)" | grep -q "to-addresses=10\.$HE_X\." \
                        || { echo "Cap dst-nat del port $p cap a la DMZ (10.$HE_X.0.0)"; exit 1; }
                    fi
                    timeout 4 bash -c "</dev/tcp/$HE_IP_ROUTER/$p" 2>/dev/null \
                      || { echo "Hi ha DNAT, però des de la WAN no s'arriba a $HE_IP_ROUTER:$p"; exit 1; }
                    echo "$HE_IP_ROUTER:$p publicat cap a la DMZ ($sistema)"
                    SH,
            ],
            [
                'nom' => 'Només el port publicat és obert des de la WAN',
                'punts' => 2,
                'parametres' => [
                    self::PORT,
                    ['name' => 'PORTS_TANCATS', 'label' => 'Ports que no han de respondre des de la WAN', 'default' => '21 23 445 3389 8291'],
                ],
                'descripcio' => 'Des de la WAN (HeimdEye) no respon cap d\'aquests ports a l\'adreça del router: 21 (FTP), 23 (Telnet), 445 (SMB), 3389 (RDP) i 8291 (Winbox). El MikroTik en té alguns oberts de fàbrica (`/ip service print`). L\'SSH (22) ha de quedar obert per a HeimdEye; no li obriu tot el trànsit. Comprova-ho des d\'un equip de l\'aula: `nc -zv -w2 <IP del router> 8291` ha de fallar.',
                'codi' => <<<'SH'
                    oberts=""
                    for p in $HE_PARAM_PORTS_TANCATS; do
                      [ "$p" = "$HE_PARAM_PORT" ] && continue
                      timeout 3 bash -c "</dev/tcp/$HE_IP_ROUTER/$p" 2>/dev/null && oberts="$oberts $p"
                    done
                    [ -z "$oberts" ] || { echo "Ports oberts des de la WAN a $HE_IP_ROUTER:$oberts"; exit 1; }
                    echo "Des de la WAN no respon cap de: $HE_PARAM_PORTS_TANCATS"
                    SH,
            ],
        ]);

        $this->task($block, 3, 'Específic del router Linux (opcional)', <<<'MD'
            Només per al router Linux. Si ara feu servir el MikroTik, el check surt com a omès i es manté
            l'estat que tenia.
            MD, [
            [
                'nom' => 'Les regles es recuperen en arrencar',
                'tipus' => CheckKind::Optional,
                'punts' => 2,
                'descripcio' => 'Les regles d\'iptables es carreguen soles en arrencar: netfilter-persistent actiu amb `/etc/iptables/rules.v4` (amb FORWARD DROP), o una unitat systemd, un script if-pre-up o similar que faci `iptables-restore`. Comprova-ho: `systemctl is-enabled netfilter-persistent` i reinicia el router per veure-ho.',
                'codi' => self::ROUTER."\n".self::ONLY_LINUX."\n".<<<'SH'
                    if r systemctl is-enabled --quiet netfilter-persistent 2>/dev/null; then
                      r sudo -n cat /etc/iptables/rules.v4 | grep -q '^:FORWARD DROP' \
                        || { echo "netfilter-persistent actiu, però /etc/iptables/rules.v4 no té FORWARD DROP"; exit 1; }
                      echo "netfilter-persistent recupera rules.v4 (FORWARD DROP)"; exit 0
                    fi
                    on=$(r "grep -rlE 'iptables-restore' /etc/systemd/system /etc/network/if-pre-up.d /etc/networkd-dispatcher /etc/rc.local 2>/dev/null | head -1")
                    [ -n "$on" ] || { echo "Res no recupera les regles en arrencar (netfilter-persistent, unitat systemd, if-pre-up...)"; exit 1; }
                    echo "Les regles es recuperen des de $on"
                    SH,
            ],
        ]);

        $this->task($block, 4, 'Específic del MikroTik (opcional)', <<<'MD'
            Només per al MikroTik. Si ara feu servir el router Linux, els checks del MikroTik surten com a
            omesos i es manté l'estat que tenien. El de les VLANs a Proxmox es comprova sempre.
            MD, [
            [
                'nom' => 'Còpies de la configuració en binari i en text',
                'tipus' => CheckKind::Optional,
                'descripcio' => 'Al MikroTik hi ha almenys una còpia binària (`.backup`, amb `/system backup save`) i una exportació en text (`.rsc`, amb `/export file=...`). Comprova-ho: `/file print`.',
                'codi' => self::ROUTER."\n".self::ONLY_MIKROTIK."\n".<<<'SH'
                    f=$(r '/file print terse without-paging' | tr -d '\r')
                    echo "$f" | grep -q 'name=[^ ]*\.backup' || { echo "No hi ha cap còpia binària (.backup)"; exit 1; }
                    echo "$f" | grep -q 'name=[^ ]*\.rsc' || { echo "No hi ha cap exportació en text (.rsc)"; exit 1; }
                    echo "Hi ha còpies .backup i .rsc"
                    SH,
            ],
            [
                'nom' => 'Llicència trial del CHR',
                'tipus' => CheckKind::Optional,
                'descripcio' => 'El CHR té una llicència diferent de la gratuïta (free), per exemple la trial de 60 dies. Comprova-ho: `/system license print`.',
                'codi' => self::ROUTER."\n".self::ONLY_MIKROTIK."\n".<<<'SH'
                    lvl=$(r ':put [/system license get level]' | tr -d '\r')
                    [ -n "$lvl" ] && [ "$lvl" != free ] || { echo "Llicència del CHR: ${lvl:-desconeguda} (cal la trial)"; exit 1; }
                    echo "Llicència del CHR: $lvl"
                    SH,
            ],
            [
                'nom' => 'RouterOS actualitzat',
                'tipus' => CheckKind::Optional,
                'temps_max_seg' => 45,
                'descripcio' => 'RouterOS és a la darrera versió del seu canal: HeimdEye fa `/system package update check-for-updates` i l\'estat ha de dir «System is already up to date». Cal que el router pugui sortir a Internet.',
                'codi' => self::ROUTER."\n".self::ONLY_MIKROTIK."\n".<<<'SH'
                    r '/system package update check-for-updates once' >/dev/null 2>&1
                    st=$(r ':put [/system package update get status]' | tr -d '\r')
                    v=$(r ':put [/system resource get version]' | tr -d '\r')
                    echo "$st" | grep -qi 'up to date' || { echo "RouterOS $v: $st"; exit 1; }
                    echo "RouterOS $v actualitzat"
                    SH,
            ],
            [
                'nom' => 'VLANs al MikroTik (router-on-a-stick)',
                'tipus' => CheckKind::Optional,
                'punts' => 2,
                'descripcio' => 'El MikroTik té almenys dues interfícies VLAN, cadascuna amb una adreça de la vostra LAN (172.X.x.x) que fa de gateway. Comprova-ho: `/interface vlan print` i `/ip address print`.',
                'codi' => self::ROUTER."\n".self::ONLY_MIKROTIK."\n".<<<'SH'
                    vlans=$(r '/interface vlan print terse without-paging' | tr -d '\r' | grep -o 'name=[^ ]*' | cut -d= -f2)
                    addr=$(r '/ip address print terse without-paging where disabled=no' | tr -d '\r')
                    amb_ip=""
                    for v in $vlans; do
                      echo "$addr" | grep "address=172\.$HE_X\." | grep -q "interface=$v\( \|$\)" && amb_ip="$amb_ip $v"
                    done
                    [ $(echo $amb_ip | wc -w) -ge 2 ] || { echo "Calen dues VLANs amb IP de la LAN (172.$HE_X.x); n'hi ha:${amb_ip:- cap}"; exit 1; }
                    echo "VLANs amb gateway a la LAN:$amb_ip"
                    SH,
            ],
            [
                'nom' => 'Bridge VLAN-aware a Proxmox amb dues VLANs',
                'executor' => Executor::Ssh,
                'tipus' => CheckKind::Optional,
                'punts' => 2,
                'descripcio' => 'Al Proxmox, un bridge té `bridge-vlan-aware yes` i les vNIC de les VM o dels contenidors fan servir almenys dos VLAN tags diferents. Comprova-ho: `grep vlan-aware /etc/network/interfaces` i `grep tag= /etc/pve/qemu-server/*.conf`.',
                'codi' => <<<'SH'
                    # hosts: pve
                    grep -q '^\s*bridge-vlan-aware yes' /etc/network/interfaces || { echo "Cap bridge VLAN-aware a /etc/network/interfaces"; exit 1; }
                    tags=$(sudo -n sh -c 'cat /etc/pve/qemu-server/*.conf /etc/pve/lxc/*.conf 2>/dev/null' | grep -oE 'tag=[0-9]+' | sort -u | cut -d= -f2)
                    [ $(echo $tags | wc -w) -ge 2 ] || { echo "Les vNIC fan servir menys de dues VLANs (tags: ${tags:-cap})"; exit 1; }
                    echo "Bridge VLAN-aware i VLANs $(echo $tags | tr ' ' ',')"
                    SH,
            ],
        ]);

        $this->task($block, 5, 'Tallafocs amb iptables (router Linux)', <<<'MD'
            Només per al router Linux (iptables): si ara feu servir el MikroTik, aquests checks surten com
            a omesos. La política de segmentació de la LAN, la DMZ i la WAN s'ha d'haver escrit a les
            regles del FORWARD, i s'ha de poder demostrar que funciona (comptadors i seguiment de
            connexions) i que les regles es desen i es recuperen.
            MD, [
            [
                'nom' => 'Regles de la LAN cap a la WAN i la DMZ',
                'actiu_des' => '2026-10-05',
                'punts' => 2,
                'temps_max_seg' => 30,
                'descripcio' => 'Al FORWARD hi ha regles ACCEPT que deixen sortir la LAN (172.X.0.0/22) cap a la xarxa de l\'aula i cap a la DMZ (10.X.0.0/22), a més de la regla de les respostes. Comprova-ho: `sudo iptables -S FORWARD`. No segueix cadenes pròpies (si en fas servir, surt com a omès).',
                'codi' => self::ROUTER."\n".self::REQUIRED."\n".self::ONLY_LINUX."\n".self::INTERFICIES."\n".self::ANALISI_FORWARD."\n".<<<'SH'
                    [ "$lw" = 1 ] || { echo "HEIMDEYE_MSG: cap regla ACCEPT del FORWARD deixa sortir la LAN (172.$HE_X.0.0/22) cap a la WAN (interfície ${aula_if:-?})"; exit 1; }
                    [ "$ld" = 1 ] || { echo "HEIMDEYE_MSG: cap regla ACCEPT del FORWARD deixa anar la LAN (172.$HE_X.0.0/22) cap a la DMZ (10.$HE_X.0.0/22)"; exit 1; }
                    echo "HEIMDEYE_MSG: hi ha regles de la LAN cap a la WAN i cap a la DMZ"
                    SH,
            ],
            [
                'nom' => 'La DMZ no té regles obertes cap a la LAN',
                'actiu_des' => '2026-10-05',
                'tipus' => CheckKind::Optional,
                'punts' => 2,
                'temps_max_seg' => 30,
                'descripcio' => 'Cap regla ACCEPT del FORWARD (que no sigui de resposta) deixa iniciar connexions de la DMZ cap a la LAN, i el FORWARD descarta la resta (DROP). Comprova-ho: `sudo iptables -S FORWARD` i, des d\'una VM de la DMZ, un `ping` o `ssh` cap a la LAN ha de fallar. És una anàlisi de les regles: no avalua l\'ordre, i una excepció voluntària (p. ex. la DMZ cap a una base de dades de la LAN) també la marca.',
                'codi' => self::ROUTER."\n".self::ONLY_LINUX."\n".self::INTERFICIES."\n".self::ANALISI_FORWARD."\n".<<<'SH'
                    echo "$fw" | grep -qx -- '-P FORWARD DROP' || echo "$fw" | tail -1 | grep -qx -- '-A FORWARD -j DROP' \
                      || { echo "HEIMDEYE_MSG: el FORWARD no descarta per defecte (política ACCEPT): la DMZ arriba a la LAN"; exit 1; }
                    [ "$dl" = 0 ] || { echo "HEIMDEYE_MSG: regla que obre la DMZ cap a la LAN: $rdl"; exit 1; }
                    echo "HEIMDEYE_MSG: cap regla obre la DMZ cap a la LAN i el FORWARD descarta la resta"
                    SH,
            ],
            [
                'nom' => 'La DMZ no arriba a la LAN (prova des de ns1)',
                'actiu_des' => '2026-10-05',
                'tipus' => CheckKind::Optional,
                'executor' => Executor::Ssh,
                'punts' => 2,
                'temps_max_seg' => 30,
                'parametres' => [['name' => 'PORTS', 'label' => 'Ports de ns2 (LAN) que no han de respondre des de la DMZ', 'default' => '5985 3389 445']],
                'descripcio' => 'Des de ns1 (a la DMZ) no es pot connectar a ns2 (a la LAN, 172.X.0.11) pels ports 5985 (WinRM), 3389 (RDP) ni 445 (SMB). Cal tenir ns1 i ns2 del Bloc 6 engegats: si ns2 està apagat, el check passa igualment. Comprova-ho des de ns1: `nc -zv -w3 172.X.0.11 5985` ha de fallar.',
                'codi' => <<<'SH'
                    # hosts: vm_dns_bind
                    obert=""
                    for p in $HE_PARAM_PORTS; do
                      timeout 4 bash -c "</dev/tcp/$HE_IP_DNSWIN/$p" 2>/dev/null && obert="$obert $p"
                    done
                    [ -z "$obert" ] || { echo "HEIMDEYE_MSG: des de la DMZ (ns1) s'arriba a ns2 ($HE_IP_DNSWIN) pels ports:$obert"; exit 1; }
                    echo "HEIMDEYE_MSG: des de la DMZ (ns1) no s'arriba a ns2 ($HE_IP_DNSWIN): $HE_PARAM_PORTS tancats"
                    SH,
            ],
            [
                'nom' => 'Les regles del forward han tractat trànsit',
                'actiu_des' => '2026-10-05',
                'tipus' => CheckKind::Optional,
                'temps_max_seg' => 30,
                'descripcio' => 'Alguna regla ACCEPT del FORWARD ja ha vist paquets (comptador major que 0): s\'ha provat que la política deixa passar el que toca. Es mostren també els paquets descartats. Comprova-ho: `sudo iptables -L FORWARD -v -n -x`. Els comptadors es posen a zero en reiniciar el router o amb `iptables -Z`.',
                'codi' => self::ROUTER."\n".self::ONLY_LINUX."\n".<<<'SH'
                    out=$(r sudo -n iptables -L FORWARD -n -v -x 2>/dev/null) || { echo "No es pot llegir iptables (sudo -n iptables -L FORWARD)"; exit 1; }
                    acc=$(echo "$out" | awk 'NR > 2 && $3 == "ACCEPT" { s += $1 } END { print s + 0 }')
                    pol=$(echo "$out" | head -1 | sed -n 's/.*policy DROP \([0-9]*\) packets.*/\1/p')
                    reg=$(echo "$out" | awk 'NR > 2 && ($3 == "DROP" || $3 == "REJECT") { s += $1 } END { print s + 0 }')
                    drops=$(( ${pol:-0} + reg ))
                    [ "${acc:-0}" -gt 0 ] || { echo "HEIMDEYE_MSG: cap regla ACCEPT del FORWARD ha vist trànsit (descartats: $drops)"; exit 1; }
                    echo "HEIMDEYE_MSG: paquets acceptats $acc, descartats $drops"
                    SH,
            ],
            [
                'nom' => 'El seguiment de connexions (conntrack) és actiu',
                'actiu_des' => '2026-10-05',
                'tipus' => CheckKind::Optional,
                'descripcio' => 'El kernel fa seguiment de connexions i en té alguna de registrada (és el que fa funcionar ESTABLISHED,RELATED i el NAT). Comprova-ho: `sudo conntrack -L` o `cat /proc/sys/net/netfilter/nf_conntrack_count`.',
                'codi' => self::ROUTER."\n".self::ONLY_LINUX."\n".<<<'SH'
                    n=$(r cat /proc/sys/net/netfilter/nf_conntrack_count 2>/dev/null) \
                      || { echo "HEIMDEYE_MSG: nf_conntrack no està carregat (cap regla amb estat ni NAT?)"; exit 1; }
                    [ "${n:-0}" -gt 0 ] || { echo "HEIMDEYE_MSG: conntrack actiu, però sense connexions registrades"; exit 1; }
                    echo "HEIMDEYE_MSG: $n connexions registrades"
                    SH,
            ],
            [
                'nom' => 'Les regles desades coincideixen amb les actives',
                'actiu_des' => '2026-10-05',
                'tipus' => CheckKind::Optional,
                'temps_max_seg' => 30,
                'descripcio' => 'El fitxer de regles desades (`/etc/iptables/rules.v4`, el que fa servir netfilter-persistent) té les mateixes regles que les actives. Comprova-ho: `sudo iptables-save > /etc/iptables/rules.v4` i compara amb `sudo iptables-save`. Complementa «Les regles es recuperen en arrencar».',
                'codi' => self::ROUTER."\n".self::ONLY_LINUX."\n".<<<'SH'
                    f=/etc/iptables/rules.v4
                    r sudo -n test -f $f || { echo "HEIMDEYE_MSG: no hi ha $f (iptables-save > $f)"; exit 1; }
                    act=$(r sudo -n iptables-save 2>/dev/null | grep '^-A ' | sort) || { echo "No es pot executar iptables-save"; exit 1; }
                    des=$(r sudo -n cat $f | grep '^-A ' | sort)
                    [ "$act" = "$des" ] || {
                      sols=$(comm -23 <(echo "$act") <(echo "$des") | wc -l); nom=$(comm -13 <(echo "$act") <(echo "$des") | wc -l)
                      echo "HEIMDEYE_MSG: regles actives i desades difereixen ($sols només actives, $nom només desades)"; exit 1
                    }
                    echo "HEIMDEYE_MSG: $(echo "$act" | wc -l) regles actives iguals a les desades a $f"
                    SH,
            ],
        ]);

        $this->actions($module, $block);
    }

    /**
     * Com l'acció d'accés a ns1 del Bloc 6, però per a qualsevol VM o contenidor de l'alumne
     * (per defecte, el router Linux): busca l'adreça al Proxmox i hi entra amb pct exec o amb
     * l'agent QEMU. Un MikroTik no hi serveix: no té bash.
     */
    private function actions(Module $module, Block $block): void
    {
        $action = $module->actions()->updateOrCreate(
            ['nom' => 'Accés de jaume a una VM Linux (des del Proxmox)'],
            [
                'bloc_id' => $block->id,
                'descripcio' => 'Busca l\'adreça IP (per defecte, la del router) entre els contenidors i les VM del Proxmox de l\'alumne i hi crea l\'usuari jaume amb la clau de HeimdEye i sudo sense contrasenya. Una VM ha de tenir qemu-guest-agent.',
                'executor' => Executor::Ansible,
                'posicio' => 11,
            ],
        );
        if ($action->versions()->exists()) {
            return;
        }

        $action->versions()->create([
            'versio' => 1,
            'temps_max_seg' => 300,
            'parametres' => [['name' => 'IP', 'label' => 'Adreça de la VM o el contenidor', 'default' => '{{ip_router}}']],
            'codi' => <<<'YAML'
                - hosts: pve
                  become: true
                  gather_facts: false
                  tasks:
                    - name: Buscar {{ IP }} al Proxmox
                      ansible.builtin.shell: |
                        for id in $(pct list 2>/dev/null | awk 'NR>1 && $2=="running" {print $1}'); do
                          pct exec "$id" -- hostname -I 2>/dev/null | tr ' ' '\n' | grep -qxF '{{ IP }}' && { echo "pct $id"; exit 0; }
                        done
                        for id in $(qm list 2>/dev/null | awk 'NR>1 && $3=="running" {print $1}'); do
                          qm guest cmd "$id" network-get-interfaces 2>/dev/null | grep -qF '"{{ IP }}"' && { echo "qm $id"; exit 0; }
                        done
                        echo "Cap contenidor ni VM (amb qemu-guest-agent) té l'adreça {{ IP }}" >&2
                        exit 1
                      args:
                        executable: /bin/bash
                      register: guest
                      changed_when: false
                      check_mode: false

                    - name: Usuari jaume amb clau i sudo a {{ IP }}
                      ansible.builtin.shell: |
                        read -r kind id <<< '{{ guest.stdout }}'
                        read -r -d '' script <<'EOF' || true
                        set -e
                        key='{{ ssh_jaume.pubkey }}'
                        export DEBIAN_FRONTEND=noninteractive
                        if ! id jaume >/dev/null 2>&1; then useradd -m -s /bin/bash jaume; echo "CANVI: usuari jaume"; fi
                        home=$(getent passwd jaume | cut -d: -f6)
                        install -d -m 700 -o jaume -g "$(id -gn jaume)" "$home/.ssh"
                        if ! grep -qxF "$key" "$home/.ssh/authorized_keys" 2>/dev/null; then
                          echo "$key" >> "$home/.ssh/authorized_keys"; echo "CANVI: clau de HeimdEye"
                        fi
                        chown jaume "$home/.ssh/authorized_keys"; chmod 600 "$home/.ssh/authorized_keys"
                        if ! command -v sudo >/dev/null; then apt-get install -y -q sudo >/dev/null; echo "CANVI: sudo"; fi
                        if [ "$(cat /etc/sudoers.d/jaume 2>/dev/null)" != "jaume ALL=(ALL) NOPASSWD:ALL" ]; then
                          echo "jaume ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/jaume; chmod 440 /etc/sudoers.d/jaume; echo "CANVI: sudo sense contrasenya"
                        fi
                        echo "{{ IP }} a punt"
                        EOF
                        if [ "$kind" = pct ]; then
                          pct exec "$id" -- bash -c "$script"
                        else
                          qm guest exec "$id" --timeout 280 -- bash -c "$script" | python3 -c '
                        import json, sys
                        r = json.load(sys.stdin)
                        sys.stdout.write(r.get("out-data", ""))
                        sys.stderr.write(r.get("err-data", ""))
                        sys.exit(r.get("exitcode", 1))'
                        fi
                      args:
                        executable: /bin/bash
                      register: setup
                      changed_when: "'CANVI' in setup.stdout"
                YAML,
        ]);
    }

    /** @param  list<array<string, mixed>>  $checks */
    private function task(Block $block, int $position, string $title, string $statement, array $checks): void
    {
        $task = $block->tasks()->updateOrCreate(
            ['titol' => $title],
            ['enunciat' => $statement, 'posicio' => $position],
        );

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
                    'actiu_des' => $def['actiu_des'] ?? '2026-10-26',
                    'data_limit' => '2026-11-06 23:59:59',
                    'posicio' => $i + 1,
                ],
            );

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
