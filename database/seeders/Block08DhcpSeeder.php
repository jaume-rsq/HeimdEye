<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Block;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Bloc 8 (blocs 7 i 8 de l'enunciat, DHCP): VLANs al router, DHCP al MikroTik, Kea a ns1 amb
 * els relays del MikroTik i el rol DHCP de Windows Server a ns2. L'estat final és Kea.
 *
 * - Els checks del MikroTik llegeixen la configuració (servidors, pools, xarxes, leases estàtics),
 *   encara que els servidors ja estiguin desactivats: el repte 9 els desactiva i passen igual.
 *   Si ara respon el router Linux, donen `omes`.
 * - Kea es llegeix des del mateix ns1 (ssh, amb `sudo -n python3`): fitxer de configuració i
 *   fitxer de leases. Que Kea hagi donat adreces a les VLAN 101, 102 i 201 prova el relay.
 * - Windows Server es llegeix amb Ansible (WinRM) a ns2.
 *
 * Les reserves es comproven per adreça (la MAC la tria l'alumne). La de la tauleta de recepció
 * (172.X.2.150) cau dins del rang dinàmic: el repte 5 demana validar la taula, i qualsevol
 * reserva ha de ser a .10-.99, de manera que la tauleta hi ha d'anar a parar.
 *
 * Cada check porta a `descripcio` el text per a l'alumne: què es comprova i com ho pot verificar
 * ell mateix. L'`info` (objectiu i justificació) és a `data/check_info.php`.
 */
class Block08DhcpSeeder extends Seeder
{
    /**
     * Comú als checks del router: ssh amb l'accés del mòdul i detecció del sistema.
     * A RouterOS, ':put' escriu el text; a bash, ':' no fa res i no escriu res.
     */
    private const ROUTER = <<<'SH'
        # HE_SSH_OPTS: no desa les claus de host (els alumnes reinstal·len les VM).
        opts=(-n -o BatchMode=yes -o ConnectTimeout=5 $HE_SSH_OPTS)
        [ -n "$HE_SSH_KEY" ] && opts+=(-i "$HE_SSH_KEY")
        r() { ssh "${opts[@]}" "$HE_SSH_USER@$HE_IP_ROUTER" "$@"; }
        so=$(r ':put routeros' 2>/dev/null); rc=$?
        if [ $rc -ne 0 ]; then sistema=cap
        elif [ "$(echo "$so" | tr -d '\r')" = routeros ]; then sistema=mikrotik
        else sistema=linux; fi
        [ $sistema = cap ] && { echo "HeimdEye no pot entrar al router ($HE_IP_ROUTER)"; exit 1; }

        SH;

    private const ONLY_MIKROTIK = <<<'SH'
        [ $sistema = mikrotik ] || { echo "HEIMDEYE_OMES: ara respon el router Linux"; exit 0; }

        SH;

    /** Python comú: VLANs de l'enunciat, rangs d'adreces i validació de reserves. Cal X. */
    private const PY_BASE = <<<'PY'
        VLANS = {101: (f'172.{X}.1', 28800), 102: (f'172.{X}.2', 3600), 201: (f'10.{X}.1', 14400)}


        def done(ok, msg):
            print(msg)
            sys.exit(0 if ok else 1)


        def last(ip):
            return int(ip.rsplit('.', 1)[1])


        def addresses(text):
            """Adreces (enters) d'un rang: 'a - b', 'a-b', CIDR o una sola adreça; separats per comes."""
            out = set()
            for part in str(text).split(','):
                part = part.strip()
                try:
                    if '/' in part:
                        out |= {int(h) for h in ipaddress.ip_network(part, strict=False).hosts()}
                    elif part:
                        a, _, b = part.partition('-')
                        a, b = a.strip(), (b.strip() or a.strip())
                        out |= set(range(int(ipaddress.ip_address(a)), int(ipaddress.ip_address(b)) + 1))
                except ValueError:
                    pass
            return out


        def dynamic(net):
            base = int(ipaddress.ip_address(net + '.0'))
            return set(range(base + 100, base + 201))


        def check_reservations(pairs, what):
            """pairs: [(ip, mac)]. Les de la taula, la tauleta a .10-.99, totes a .10-.99 i MACs úniques."""
            errs = []
            ips = {ip for ip, _ in pairs}
            for ip in (f'172.{X}.1.50', f'172.{X}.1.51', f'172.{X}.1.60', f'10.{X}.1.20'):
                if ip not in ips:
                    errs.append(f'falta {ip}')
            if not any(ip.startswith(f'172.{X}.2.') and 10 <= last(ip) <= 99 for ip in ips):
                errs.append(f'falta la tauleta de recepció a 172.{X}.2.10-99')
            nets = [net + '.' for net, _ in VLANS.values()]
            for ip in sorted(ips):
                if any(ip.startswith(n) for n in nets) and not 10 <= last(ip) <= 99:
                    errs.append(f'{ip} és fora de .10-.99, les adreces de les reserves')
            macs = [m.lower().replace('-', ':') for _, m in pairs if m]
            dup = sorted({m for m in macs if macs.count(m) > 1})
            if dup:
                errs.append('MAC repetida: ' + ', '.join(dup))
            if any(not m for _, m in pairs):
                errs.append('hi ha reserves sense MAC')
            done(not errs, '; '.join(errs) or f'{len(ips)} {what}: les de la taula, totes a .10-.99')

        PY;

    /** Python dels checks locals del MikroTik: les sortides "print terse" arriben a R_*. */
    private const PY_ROS = <<<'PY'
        import ipaddress
        import os
        import re
        import shlex
        import sys

        X = os.environ['HE_X']


        def rows(var):
            out = []
            for line in os.environ.get(var, '').splitlines():
                try:
                    tokens = shlex.split(line)
                except ValueError:
                    tokens = line.split()
                row = {'_flags': ''}
                for t in tokens:
                    k, eq, v = t.partition('=')
                    if eq:
                        row[k] = v
                    elif not t.isdigit():
                        row['_flags'] += t
                if len(row) > 1:
                    out.append(row)
            return out


        def iface(ip):
            """Interfície del router que té aquesta adreça."""
            for a in rows('R_ADDR'):
                if a.get('address', '').split('/')[0] == ip:
                    return a.get('interface')


        def seconds(v):
            """8h, 1d2h, 00:30:00, 1d 08:00:00..."""
            total = 0
            m = re.search(r'(\d+):(\d+):(\d+)', v)
            if m:
                total += int(m[1]) * 3600 + int(m[2]) * 60 + int(m[3])
                v = v[:m.start()]
            units = {'w': 604800, 'd': 86400, 'h': 3600, 'm': 60, 's': 1}
            for n, u in re.findall(r'(\d+)([wdhms])', v):
                total += int(n) * units[u]
            return total

        PY;

    /** Python dels checks de Kea, a ns1 amb sudo (l'entorn no hi arriba: valors per arguments). */
    private const PY_KEA = <<<'PY'
        import csv
        import glob
        import ipaddress
        import json
        import os
        import re
        import sys

        X, DOMAIN, DNS1, DNS2, NTP = sys.argv[1:6]
        CONF = os.environ.get('KEA_CONF', '/etc/kea/kea-dhcp4.conf')
        CODES = {'routers': 3, 'domain-name-servers': 6, 'domain-name': 15, 'ntp-servers': 42}


        def load():
            try:
                with open(CONF, encoding='utf-8') as f:
                    text = f.read()
            except OSError as e:
                done(False, f'No es pot llegir {CONF}: {e.strerror}')
            # Kea admet comentaris #, // i /* */.
            text = re.sub(r'/\*.*?\*/', '', text, flags=re.S)
            text = re.sub(r'^\s*(#|//).*$', '', text, flags=re.M)
            text = re.sub(r'(?<=[,{}\[\]"\d])[ \t]*(#|//)[^"\n]*$', '', text, flags=re.M)
            try:
                return json.loads(text)['Dhcp4']
            except (ValueError, KeyError, TypeError) as e:
                done(False, f'No es pot interpretar {CONF}: {e}')


        def subnets(c):
            out = [(s, {}) for s in c.get('subnet4', [])]
            for n in c.get('shared-networks', []):
                out += [(s, n) for s in n.get('subnet4', [])]
            return out


        def subnet(c, net):
            want = ipaddress.ip_network(net + '.0/24')
            for s, n in subnets(c):
                try:
                    if ipaddress.ip_network(s.get('subnet', ''), strict=False) == want:
                        return s, n
                except ValueError:
                    pass
            return None, None


        def options(c, s, n):
            """Opcions efectives d'una subxarxa (global < shared-network < subxarxa), per codi."""
            out = {}
            for level in (c, n, s):
                for o in level.get('option-data', []):
                    code = o.get('code', CODES.get(o.get('name')))
                    if code:
                        out[int(code)] = [v.strip() for v in str(o.get('data', '')).split(',') if v.strip()]
            return out


        def leases(c):
            files = set(glob.glob(os.environ.get('KEA_LEASES', '/var/lib/kea/kea-leases4.csv') + '*'))
            name = c.get('lease-database', {}).get('name')
            if name:
                files |= set(glob.glob(name + '*'))
            out = []
            for path in files:
                try:
                    with open(path, newline='', encoding='utf-8') as f:
                        out += list(csv.DictReader(f))
                except OSError:
                    pass
            return out

        PY;

    /** Començament dels checks de Windows Server: les VLANs i el servei. */
    private const PS_HEAD = <<<'PS'
        [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
        $x = '{{ x }}'
        $vlans = @(
          @{ id = 101; net = "172.$x.1"; secs = 28800 },
          @{ id = 102; net = "172.$x.2"; secs = 3600 },
          @{ id = 201; net = "10.$x.1"; secs = 14400 }
        )
        $errs = @()
        if ((Get-Service DHCPServer -ErrorAction SilentlyContinue).Status -ne 'Running') {
          'FAIL: El servei DHCP Server no està en marxa (cal el rol DHCP)'
          exit 0
        }

        PS;

    public function run(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();

        $block = Block::updateOrCreate(
            ['modul_id' => $module->id, 'numero' => 8],
            [
                'titol' => 'DHCP: MikroTik, Kea i Windows Server',
                'data_inici' => '2026-10-28',
                'data_fi' => '2026-11-06',
                'posicio' => 8,
            ],
        );

        $this->vlans($block);
        $this->mikrotik($block);
        $this->kea($block);
        $this->windows($block);
    }

    private function vlans(Block $block): void
    {
        $this->task($block, 1, 'VLANs i adreçament', <<<'MD'
            Repte 1. El router té les VLANs de la taula amb el .1 de cada una com a gateway: 100
            (172.X.0.0/24), 101 (172.X.1.0/24), 102 (172.X.2.0/24), 200 (10.X.0.0/24) i 201
            (10.X.1.0/24). A Proxmox, cada targeta de VM té l'etiqueta de la seva VLAN. ns1
            (10.X.0.11) passa a la VLAN 200 i ns2 (172.X.0.11) a la VLAN 100, amb màscara /24.
            MD, [
            [
                'nom' => 'VLANs i gateways al router',
                'punts' => 2,
                'descripcio' => 'El router té les VLAN 100, 101, 102, 200 i 201 i, a cada una, l\'adreça .1 amb màscara /24 (172.X.0.1, 172.X.1.1, 172.X.2.1, 10.X.0.1 i 10.X.1.1). Serveix el MikroTik o el router Linux. Comprova-ho: `/interface vlan print` i `/ip address print` (MikroTik) o `ip -d link show type vlan` i `ip -4 addr` (Linux).',
                'codi' => self::ROUTER.<<<'SH'
                    X=$HE_X
                    if [ $sistema = mikrotik ]; then
                      addr=$(r '/ip address print terse without-paging where disabled=no' | tr -d '\r')
                      ids=$(r '/interface vlan print terse without-paging' | tr -d '\r' | grep -o 'vlan-id=[0-9]*' | cut -d= -f2)
                    else
                      addr=$(r ip -o -4 addr show)
                      ids=$(r ip -d -o link show type vlan | grep -oE '802\.1[Qq] id [0-9]+' | awk '{print $NF}')
                    fi
                    falten=""
                    for a in 172.$X.0.1 172.$X.1.1 172.$X.2.1 10.$X.0.1 10.$X.1.1; do
                      echo "$addr" | grep -Eq "(address=|inet )${a//./\\.}/24( |$)" || falten="$falten $a/24"
                    done
                    for v in 100 101 102 200 201; do
                      echo "$ids" | grep -qx "$v" || falten="$falten VLAN$v"
                    done
                    [ -z "$falten" ] || { echo "Al router ($sistema) falta:$falten"; exit 1; }
                    echo "VLANs 100, 101, 102, 200 i 201 amb el gateway .1 ($sistema)"
                    SH,
            ],
            [
                'nom' => 'ns1 a la VLAN 200 arriba al gateway',
                'executor' => Executor::Ssh,
                'descripcio' => 'ns1 té l\'adreça 10.X.0.11/24 i arriba al seu gateway (10.X.0.1). Comprova-ho a ns1: `ip -4 addr` i `ping -c2 10.X.0.1`.',
                'codi' => <<<'SH'
                    # hosts: vm_dns_bind
                    ip -o -4 addr show | grep -q " 10\.$HE_X\.0\.11/24 " || { echo "ns1 no té l'adreça 10.$HE_X.0.11/24 (VLAN 200)"; exit 1; }
                    ping -c 2 -W 2 "10.$HE_X.0.1" >/dev/null 2>&1 || { echo "ns1 no arriba al gateway 10.$HE_X.0.1"; exit 1; }
                    echo "ns1 (10.$HE_X.0.11/24) arriba al gateway 10.$HE_X.0.1"
                    SH,
            ],
            [
                'nom' => 'ns2 a la VLAN 100 arriba al gateway',
                'executor' => Executor::Ansible,
                'temps_max_seg' => 90,
                'descripcio' => 'ns2 té l\'adreça 172.X.0.11/24 i arriba al seu gateway (172.X.0.1). Comprova-ho a ns2: `Get-NetIPAddress 172.X.0.11` i `Test-Connection 172.X.0.1`.',
                'codi' => <<<'YAML'
                    - hosts: vm_dns_windows
                      gather_facts: false
                      tasks:
                        - name: Adreça i gateway de ns2
                          ansible.windows.win_shell: |
                            $ip = Get-NetIPAddress -IPAddress '{{ ip_dnswin }}' -AddressFamily IPv4 -ErrorAction SilentlyContinue
                            $gw = Test-Connection -ComputerName '172.{{ x }}.0.1' -Count 2 -Quiet
                            "$($ip.PrefixLength);$gw"
                          register: r
                          changed_when: false
                          failed_when: false

                        - name: ns2 a la VLAN 100
                          ansible.builtin.assert:
                            that:
                              - r.rc == 0
                              - (r.stdout | trim) == '24;True'
                            fail_msg: "ns2 ha de tenir {{ ip_dnswin }}/24 i arribar a 172.{{ x }}.0.1 (prefix;gateway: {{ (r.stdout ~ r.stderr) | trim | truncate(100) }})"
                            success_msg: "ns2 ({{ ip_dnswin }}/24) arriba al gateway 172.{{ x }}.0.1"
                    YAML,
            ],
            [
                'nom' => 'Etiquetes VLAN de les VM a Proxmox',
                'executor' => Executor::Ssh,
                'descripcio' => 'Al Proxmox, hi ha targetes de VM amb l\'etiqueta 100 (ns2) i 200 (ns1), i almenys una amb 101, 102 o 201 (un client). Comprova-ho: `grep -h tag= /etc/pve/qemu-server/*.conf`.',
                'codi' => <<<'SH'
                    # hosts: pve
                    tags=$(sudo -n sh -c 'cat /etc/pve/qemu-server/*.conf /etc/pve/lxc/*.conf 2>/dev/null' | grep -E '^net[0-9]+:' | grep -oE 'tag=[0-9]+' | cut -d= -f2 | sort -un)
                    falten=""
                    for t in 100 200; do echo "$tags" | grep -qx "$t" || falten="$falten $t"; done
                    echo "$tags" | grep -qxE '101|102|201' || falten="$falten 101/102/201"
                    [ -z "$falten" ] || { echo "Cap targeta de VM amb l'etiqueta:$falten (n'hi ha: $(echo $tags | tr ' ' ','))"; exit 1; }
                    echo "Etiquetes VLAN a les VM: $(echo $tags | tr ' ' ',')"
                    SH,
            ],
        ]);
    }

    private function mikrotik(Block $block): void
    {
        $this->task($block, 2, 'DHCP al MikroTik', <<<'MD'
            Reptes 2, 3, 5 i 6. El MikroTik té un servidor DHCP a les VLAN 101, 102 i 201 amb el rang
            .100-.200, la durada de la taula (8h, 1h i 4h) i les opcions de l'empresa, i els leases
            estàtics de la taula de reserves, validada abans: totes les reserves van de .10 a .99. Cap
            servidor DHCP a la xarxa de l'aula. Al repte 9 els servidors es desactiven: aquests checks
            llegeixen la configuració i continuen passant.
            MD, [
            [
                'nom' => 'Servidors DHCP del MikroTik: rang i durada',
                'punts' => 2,
                'descripcio' => 'A les VLAN 101, 102 i 201 hi ha un servidor DHCP (actiu o desactivat) amb un pool de .100 a .200 i una durada de 8h, 1h i 4h. Comprova-ho: `/ip dhcp-server print` i `/ip pool print`.',
                'codi' => $this->ros([
                    'R_ADDR' => '/ip address print terse without-paging where disabled=no',
                    'R_SRV' => '/ip dhcp-server print terse without-paging',
                    'R_POOL' => '/ip pool print terse without-paging',
                ], <<<'PY'
                    pools = {p.get('name'): p.get('ranges', '') for p in rows('R_POOL')}
                    errs = []
                    for vid, (net, life) in VLANS.items():
                        i = iface(net + '.1')
                        if not i:
                            errs.append(f'cap interfície amb {net}.1')
                            continue
                        srv = [s for s in rows('R_SRV') if s.get('interface') == i]
                        if not srv:
                            errs.append(f'VLAN {vid}: cap servidor DHCP a {i}')
                            continue
                        s = srv[0]
                        if seconds(s.get('lease-time', '')) != life:
                            errs.append(f"VLAN {vid}: lease-time {s.get('lease-time')} (cal {life // 3600}h)")
                        if addresses(pools.get(s.get('address-pool'), '')) != dynamic(net):
                            errs.append(f"VLAN {vid}: el pool {s.get('address-pool')} no és {net}.100-{net}.200")
                    done(not errs, '; '.join(errs) or 'Servidors DHCP a les VLAN 101, 102 i 201 amb el rang .100-.200 i la durada de la taula')
                    PY),
            ],
            [
                'nom' => 'Opcions de l\'empresa al MikroTik',
                'punts' => 2,
                'descripcio' => 'Les xarxes DHCP de les VLAN 101, 102 i 201 donen el gateway (.1), els DNS 10.X.0.11 i 172.X.0.11, el domini <usuari>.smix2.julioantonio.cat i el servidor NTP 192.168.20.11. Comprova-ho: `/ip dhcp-server network print detail`.',
                'codi' => $this->ros([
                    'R_NET' => '/ip dhcp-server network print terse without-paging',
                    'R_OPT' => '/ip dhcp-server option print terse without-paging',
                ], <<<'PY'
                    domain = os.environ['HE_NOM_DNS'] + '.' + os.environ['HE_NOM_DOMINI']
                    dns = {os.environ['HE_IP_DNS'], os.environ['HE_IP_DNSWIN']}
                    ntp = os.environ['HE_IP_DNS_AULA']
                    ntp_hex = ''.join(f'{int(o):02x}' for o in ntp.split('.'))
                    opt42 = {o.get('name') for o in rows('R_OPT')
                             if o.get('code') == '42' and (ntp in o.get('value', '') or ntp_hex in o.get('value', '').lower())}
                    errs = []
                    for vid, (net, _) in VLANS.items():
                        nw = [n for n in rows('R_NET') if n.get('address') == net + '.0/24']
                        if not nw:
                            errs.append(f'VLAN {vid}: cap xarxa DHCP {net}.0/24')
                            continue
                        n, f = nw[0], []
                        if n.get('gateway', '').split(',')[0] != net + '.1':
                            f.append('gateway')
                        if not dns <= set(n.get('dns-server', '').split(',')):
                            f.append('DNS')
                        if n.get('domain', '').rstrip('.') != domain:
                            f.append('domini')
                        if ntp not in n.get('ntp-server', '').split(',') and not opt42 & set(n.get('dhcp-option', '').split(',')):
                            f.append('NTP')
                        if f:
                            errs.append(f'VLAN {vid}: falta o és incorrecte ' + ', '.join(f))
                    done(not errs, '; '.join(errs) or 'Gateway, DNS, domini i NTP a les VLAN 101, 102 i 201')
                    PY),
            ],
            [
                'nom' => 'Leases estàtics al MikroTik',
                'punts' => 2,
                'descripcio' => 'Hi ha leases estàtics per a 172.X.1.50 (pc-direccio), 172.X.1.60 (pc-suport), 172.X.1.51 (impressora), 10.X.1.20 (servidor-proves) i la tauleta de recepció a la VLAN 102. Totes les reserves han de ser entre .10 i .99, fora del rang dinàmic, i cada MAC només hi pot sortir un cop. Comprova-ho: `/ip dhcp-server lease print where dynamic=no`.',
                'codi' => $this->ros([
                    'R_LEASE' => '/ip dhcp-server lease print terse without-paging where dynamic=no',
                ], <<<'PY'
                    check_reservations([(l.get('address', ''), l.get('mac-address', '')) for l in rows('R_LEASE') if l.get('address')], 'leases estàtics')
                    PY),
            ],
            [
                'nom' => 'Cap servidor DHCP a la xarxa de l\'aula',
                'descripcio' => 'Cap servidor DHCP actiu del MikroTik és a la interfície de la xarxa de l\'aula (la que té l\'adreça WAN del router): repartiria adreces als equips de l\'institut. Comprova-ho: `/ip dhcp-server print` i `/ip address print`.',
                'codi' => $this->ros([
                    'R_ADDR' => '/ip address print terse without-paging where disabled=no',
                    'R_SRV' => '/ip dhcp-server print terse without-paging where disabled=no',
                ], <<<'PY'
                    wan = iface(os.environ['HE_IP_ROUTER'])
                    if not wan:
                        done(False, f"Cap interfície del router té l'adreça {os.environ['HE_IP_ROUTER']}")
                    bad = [s.get('name', '?') for s in rows('R_SRV') if s.get('interface') == wan]
                    done(not bad, f"Servidor DHCP a la xarxa de l'aula ({wan}): " + ', '.join(bad) if bad else f"Cap servidor DHCP actiu a la interfície de l'aula ({wan})")
                    PY),
            ],
        ]);
    }

    private function kea(Block $block): void
    {
        $this->task($block, 3, 'Kea a ns1 amb relay', <<<'MD'
            Reptes 9 a 12 i 16. Kea DHCPv4 a ns1 (10.X.0.11) amb les subxarxes de les VLAN 101, 102 i
            201, el rang, la durada i les opcions de l'empresa, i les reserves. El MikroTik fa de relay
            cap a Kea a les tres VLANs i ja no hi té cap servidor DHCP actiu. Al final, Kea és el
            servidor DHCP de totes les VLANs. HeimdEye llegeix `/etc/kea/kea-dhcp4.conf` i els leases
            des de ns1 (jaume, `sudo -n`).
            MD, [
            [
                'nom' => 'Kea en marxa amb la configuració validada',
                'executor' => Executor::Ssh,
                'descripcio' => 'El servei kea-dhcp4-server està actiu i `kea-dhcp4 -t /etc/kea/kea-dhcp4.conf` no troba errors. Comprova-ho a ns1: `systemctl status kea-dhcp4-server` i `sudo kea-dhcp4 -t /etc/kea/kea-dhcp4.conf`.',
                'codi' => <<<'SH'
                    # hosts: vm_dns_bind
                    systemctl is-active --quiet kea-dhcp4-server || { echo "El servei kea-dhcp4-server no està en marxa"; exit 1; }
                    out=$(sudo -n kea-dhcp4 -t /etc/kea/kea-dhcp4.conf 2>&1) \
                      || { echo "kea-dhcp4 -t troba errors: $(echo "$out" | grep -iE 'error|fail' | tail -1 | cut -c1-200)"; exit 1; }
                    echo "Kea $(kea-dhcp4 -v 2>/dev/null | head -1) en marxa i /etc/kea/kea-dhcp4.conf vàlid"
                    SH,
            ],
            [
                'nom' => 'Subxarxes de Kea: rang i durada',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'descripcio' => 'Kea té les subxarxes 172.X.1.0/24, 172.X.2.0/24 i 10.X.1.0/24 amb el pool .100-.200 i una durada (valid-lifetime) de 28800, 3600 i 14400 segons. Comprova-ho a ns1: `/etc/kea/kea-dhcp4.conf`.',
                'codi' => $this->keaCode(<<<'PY'
                    c = load()
                    errs = []
                    for vid, (net, life) in VLANS.items():
                        s, n = subnet(c, net)
                        if s is None:
                            errs.append(f'falta la subxarxa {net}.0/24 (VLAN {vid})')
                            continue
                        pool = set()
                        for p in s.get('pools', []):
                            pool |= addresses(p.get('pool', ''))
                        if pool != dynamic(net):
                            errs.append(f'VLAN {vid}: el pool no és {net}.100 - {net}.200')
                        lt = s.get('valid-lifetime', n.get('valid-lifetime', c.get('valid-lifetime', 7200)))
                        if int(lt) != life:
                            errs.append(f'VLAN {vid}: valid-lifetime {lt} (cal {life})')
                    done(not errs, '; '.join(errs) or 'Subxarxes de les VLAN 101, 102 i 201 amb el rang .100-.200 i la durada de la taula')
                    PY),
            ],
            [
                'nom' => 'Opcions de l\'empresa a Kea',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'descripcio' => 'Les subxarxes de Kea donen routers (el .1), domain-name-servers (10.X.0.11 i 172.X.0.11), domain-name (<usuari>.smix2.julioantonio.cat) i ntp-servers (192.168.20.11). Es poden posar a cada subxarxa o, les comunes, a nivell global. Comprova-ho a ns1: `option-data` a `/etc/kea/kea-dhcp4.conf`.',
                'codi' => $this->keaCode(<<<'PY'
                    c = load()
                    errs = []
                    for vid, (net, _) in VLANS.items():
                        s, n = subnet(c, net)
                        if s is None:
                            errs.append(f'falta la subxarxa {net}.0/24 (VLAN {vid})')
                            continue
                        o, f = options(c, s, n), []
                        if net + '.1' not in o.get(3, []):
                            f.append('routers')
                        if not {DNS1, DNS2} <= set(o.get(6, [])):
                            f.append('domain-name-servers')
                        if (o.get(15) or [''])[0].rstrip('.') != DOMAIN:
                            f.append('domain-name')
                        if NTP not in o.get(42, []):
                            f.append('ntp-servers')
                        if f:
                            errs.append(f'VLAN {vid}: falta o és incorrecte ' + ', '.join(f))
                    done(not errs, '; '.join(errs) or 'routers, DNS, domini i NTP a les VLAN 101, 102 i 201')
                    PY),
            ],
            [
                'nom' => 'Reserves a Kea',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'descripcio' => 'Kea té reserves (hw-address i ip-address) per a 172.X.1.50, 172.X.1.60, 172.X.1.51, 10.X.1.20 i la tauleta de recepció a la VLAN 102. Totes entre .10 i .99, fora del pool, i cada MAC un sol cop. Comprova-ho a ns1: `reservations` a `/etc/kea/kea-dhcp4.conf`.',
                'codi' => $this->keaCode(<<<'PY'
                    c = load()
                    res = list(c.get('reservations', []))
                    for s, _ in subnets(c):
                        res += s.get('reservations', [])
                    ids = ('hw-address', 'client-id', 'duid', 'circuit-id', 'flex-id')
                    pairs = [(r['ip-address'], next((r[k] for k in ids if r.get(k)), '')) for r in res if r.get('ip-address')]
                    check_reservations(pairs, 'reserves')
                    PY),
            ],
            [
                'nom' => 'Relays del MikroTik cap a Kea',
                'descripcio' => 'Al MikroTik, les VLAN 101, 102 i 201 tenen un relay DHCP actiu que apunta a Kea (10.X.0.11). Comprova-ho: `/ip dhcp-relay print`.',
                'codi' => $this->ros([
                    'R_ADDR' => '/ip address print terse without-paging where disabled=no',
                    'R_RELAY' => '/ip dhcp-relay print terse without-paging where disabled=no',
                ], <<<'PY'
                    kea = os.environ['HE_IP_DNS']
                    errs = []
                    for vid, (net, _) in VLANS.items():
                        i = iface(net + '.1')
                        if not any(r.get('interface') == i and kea in r.get('dhcp-server', '').split(',') for r in rows('R_RELAY')):
                            errs.append(f'VLAN {vid}' + (f' ({i})' if i else ''))
                    done(not errs, f'Falta un relay actiu cap a {kea} a: ' + ', '.join(errs) if errs else f'Relays actius cap a Kea ({kea}) a les VLAN 101, 102 i 201')
                    PY),
            ],
            [
                'nom' => 'El MikroTik ja no reparteix adreces',
                'descripcio' => 'Al MikroTik, cap servidor DHCP actiu a les VLAN 101, 102 i 201: a cada VLAN només hi pot haver un servidor DHCP, i ara és Kea. Comprova-ho: `/ip dhcp-server print` (han de sortir desactivats, amb X).',
                'codi' => $this->ros([
                    'R_ADDR' => '/ip address print terse without-paging where disabled=no',
                    'R_SRV' => '/ip dhcp-server print terse without-paging where disabled=no',
                ], <<<'PY'
                    actius = []
                    for vid, (net, _) in VLANS.items():
                        i = iface(net + '.1')
                        actius += [f"{s.get('name', '?')} (VLAN {vid})" for s in rows('R_SRV') if i and s.get('interface') == i]
                    done(not actius, 'Servidors DHCP actius al MikroTik: ' + ', '.join(actius) if actius else 'Cap servidor DHCP actiu del MikroTik a les VLAN 101, 102 i 201')
                    PY),
            ],
            [
                'nom' => 'Kea dona adreces a les VLAN 101, 102 i 201',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'descripcio' => 'Al fitxer de leases de Kea hi ha adreces donades a clients de les VLAN 101, 102 i 201: el relay funciona. Connecta un client a cada VLAN (o canvia-li l\'etiqueta a Proxmox). Comprova-ho a ns1: `sudo cat /var/lib/kea/kea-leases4.csv`.',
                'codi' => $this->keaCode(<<<'PY'
                    c = load()
                    got = {l.get('address', '') for l in leases(c)}
                    falten = [f'{vid} ({net}.x)' for vid, (net, _) in VLANS.items() if not any(a.startswith(net + '.') for a in got)]
                    done(not falten, 'Kea no ha donat cap adreça a la VLAN ' + ', '.join(falten) if falten else 'Kea ha donat adreces a clients de les VLAN 101, 102 i 201')
                    PY),
            ],
            [
                'nom' => 'Un PC rep de Kea la seva IP reservada',
                'executor' => Executor::Ssh,
                'tipus' => CheckKind::Optional,
                'descripcio' => 'Un dels PC de la taula (pc-direccio o pc-suport) ha rebut de Kea la seva adreça reservada amb la MAC de la reserva: fes-ho amb la MAC real d\'una VM i un release i renew. Comprova-ho a ns1: `grep 172.X.1.50 /var/lib/kea/kea-leases4.csv`.',
                'codi' => $this->keaCode(<<<'PY'
                    c = load()
                    res = list(c.get('reservations', []))
                    for s, _ in subnets(c):
                        res += s.get('reservations', [])
                    mac = {r.get('ip-address'): str(r.get('hw-address', '')).lower().replace('-', ':') for r in res}
                    pcs = [f'172.{X}.1.50', f'172.{X}.1.60']
                    ok = [l['address'] for l in leases(c)
                          if l.get('address') in pcs and mac.get(l['address']) and l.get('hwaddr', '').lower() == mac[l['address']]]
                    done(bool(ok), f'{ok[0]} donada a la MAC de la reserva' if ok else f"Cap lease de {' ni '.join(pcs)} amb la MAC de la reserva")
                    PY),
            ],
        ]);
    }

    private function windows(Block $block): void
    {
        $this->task($block, 4, 'DHCP a Windows Server', <<<'MD'
            Reptes 13 i 14. El rol DHCP de ns2 (172.X.0.11, VLAN 100) té els àmbits de les VLAN 101, 102
            i 201 amb el rang efectiu .100-.200 (amb el rang o amb exclusions), la durada i les opcions
            de l'empresa, i les reserves. Al final, els relays apunten a Kea: aquests checks llegeixen la
            configuració de ns2 i continuen passant.
            MD, [
            [
                'nom' => 'Àmbits a Windows Server: rang i durada',
                'executor' => Executor::Ansible,
                'punts' => 2,
                'temps_max_seg' => 90,
                'descripcio' => 'A ns2, el servei DHCP Server és en marxa i hi ha els àmbits 172.X.1.0, 172.X.2.0 i 10.X.1.0 (/24) amb el rang efectiu .100-.200 (rang menys exclusions) i una durada de 8 h, 1 h i 4 h. Comprova-ho a ns2: `Get-DhcpServerv4Scope` i `Get-DhcpServerv4ExclusionRange`.',
                'codi' => $this->windowsCode('Àmbits de ns2', <<<'PS'
                    function Octet($ip) { [int]$ip.ToString().Split('.')[3] }
                    foreach ($v in $vlans) {
                      $id = "$($v.net).0"
                      try { $s = Get-DhcpServerv4Scope -ScopeId $id -ErrorAction Stop } catch { $s = $null }
                      if (-not $s) { $errs += "falta l'àmbit $id (VLAN $($v.id))"; continue }
                      if ($s.SubnetMask.ToString() -ne '255.255.255.0') { $errs += "${id}: la màscara no és /24" }
                      if ([int]$s.LeaseDuration.TotalSeconds -ne $v.secs) {
                        $errs += "${id}: durada $($s.LeaseDuration) (cal $([TimeSpan]::FromSeconds($v.secs)))"
                      }
                      $ex = @(Get-DhcpServerv4ExclusionRange -ScopeId $id -ErrorAction SilentlyContinue)
                      $a = Octet $s.StartRange
                      $b = Octet $s.EndRange
                      $pool = @(1..254 | Where-Object {
                        $n = $_
                        $n -ge $a -and $n -le $b -and -not ($ex | Where-Object { $n -ge (Octet $_.StartRange) -and $n -le (Octet $_.EndRange) })
                      })
                      if ($pool.Count -ne 101 -or $pool[0] -ne 100 -or $pool[-1] -ne 200) {
                        $errs += "${id}: el rang efectiu no és .100-.200 (" + $pool.Count + " adreces)"
                      }
                    }
                    if ($errs) { 'FAIL: ' + ($errs -join '; ') } else { 'OK: Àmbits de les VLAN 101, 102 i 201 amb el rang efectiu .100-.200 i la durada de la taula' }
                    PS),
            ],
            [
                'nom' => 'Opcions de l\'empresa a Windows Server',
                'executor' => Executor::Ansible,
                'punts' => 2,
                'temps_max_seg' => 90,
                'descripcio' => 'Els àmbits de ns2 donen les opcions 3 (el .1), 6 (10.X.0.11 i 172.X.0.11), 15 (<usuari>.smix2.julioantonio.cat) i 42 (192.168.20.11), a l\'àmbit o, les comunes, al servidor. Comprova-ho a ns2: `Get-DhcpServerv4OptionValue` i `Get-DhcpServerv4OptionValue -ScopeId 172.X.1.0`.',
                'codi' => $this->windowsCode('Opcions de ns2', <<<'PS'
                    $server = @{}
                    foreach ($o in @(Get-DhcpServerv4OptionValue -ErrorAction SilentlyContinue)) { $server[[int]$o.OptionId] = @($o.Value) }
                    foreach ($v in $vlans) {
                      $id = "$($v.net).0"
                      $o = $server.Clone()
                      try {
                        foreach ($s in @(Get-DhcpServerv4OptionValue -ScopeId $id -ErrorAction Stop)) { $o[[int]$s.OptionId] = @($s.Value) }
                      } catch { $errs += "falta l'àmbit $id"; continue }
                      $f = @()
                      if (@($o[3]) -notcontains "$($v.net).1") { $f += 'gateway (3)' }
                      if (-not ((@($o[6]) -contains '{{ ip_dns }}') -and (@($o[6]) -contains '{{ ip_dnswin }}'))) { $f += 'DNS (6)' }
                      if ((@($o[15])[0] -replace '\.$', '') -ne '{{ nom_dns }}.{{ nom_domini }}') { $f += 'domini (15)' }
                      if (@($o[42]) -notcontains '{{ ip_dns_aula }}') { $f += 'NTP (42)' }
                      if ($f) { $errs += "${id}: falta o és incorrecta l'opció " + ($f -join ', ') }
                    }
                    if ($errs) { 'FAIL: ' + ($errs -join '; ') } else { 'OK: Gateway, DNS, domini i NTP als àmbits de les VLAN 101, 102 i 201' }
                    PS),
            ],
            [
                'nom' => 'Reserves a Windows Server',
                'executor' => Executor::Ansible,
                'punts' => 2,
                'temps_max_seg' => 90,
                'descripcio' => 'A ns2 hi ha reserves per a 172.X.1.50, 172.X.1.60, 172.X.1.51, 10.X.1.20 i la tauleta de recepció a l\'àmbit 172.X.2.0. Totes entre .10 i .99 i cada MAC un sol cop. Comprova-ho a ns2: `Get-DhcpServerv4Reservation -ScopeId 172.X.1.0`.',
                'codi' => $this->windowsCode('Reserves de ns2', <<<'PS'
                    $res = @()
                    foreach ($v in $vlans) {
                      try { $res += @(Get-DhcpServerv4Reservation -ScopeId "$($v.net).0" -ErrorAction Stop) } catch { $errs += "falta l'àmbit $($v.net).0" }
                    }
                    $ips = @($res | ForEach-Object { $_.IPAddress.ToString() })
                    foreach ($ip in @("172.$x.1.50", "172.$x.1.51", "172.$x.1.60", "10.$x.1.20")) {
                      if ($ips -notcontains $ip) { $errs += "falta $ip" }
                    }
                    $tauleta = @($ips | Where-Object { $_ -like "172.$x.2.*" -and [int]$_.Split('.')[3] -ge 10 -and [int]$_.Split('.')[3] -le 99 })
                    if (-not $tauleta) { $errs += "falta la tauleta de recepció a 172.$x.2.10-99" }
                    foreach ($ip in $ips) {
                      $n = [int]$ip.Split('.')[3]
                      if ($n -lt 10 -or $n -gt 99) { $errs += "$ip és fora de .10-.99, les adreces de les reserves" }
                    }
                    $dup = @($res | ForEach-Object { $_.ClientId.ToLower() } | Group-Object | Where-Object Count -gt 1)
                    if ($dup) { $errs += 'MAC repetida: ' + ($dup.Name -join ', ') }
                    if ($errs) { 'FAIL: ' + ($errs -join '; ') } else { "OK: $($ips.Count) reserves: les de la taula, totes a .10-.99" }
                    PS),
            ],
        ]);
    }

    /**
     * Check local del MikroTik: entra al router, desa les sortides a R_* i les analitza amb Python.
     *
     * @param  array<string, string>  $outputs  variable => ordre de RouterOS
     */
    private function ros(array $outputs, string $python): string
    {
        $fetch = "set -o pipefail\n";
        foreach ($outputs as $var => $command) {
            $fetch .= "{$var}=\$(r '{$command}' | tr -d '\\r') || { echo \"No es pot llegir {$command}\"; exit 1; }\nexport {$var}\n";
        }

        return self::ROUTER.self::ONLY_MIKROTIK.$fetch."python3 - <<'PY'\n".self::PY_ROS."\n".self::PY_BASE."\n".$python."\nPY\n";
    }

    /** Check ssh de Kea a ns1: Python amb sudo (cal per llegir la configuració i els leases). */
    private function keaCode(string $python): string
    {
        return "# hosts: vm_dns_bind\n"
            ."command -v python3 >/dev/null || { echo \"Falta python3 a ns1\"; exit 1; }\n"
            ."sudo -n python3 - \"\$HE_X\" \"\$HE_NOM_DNS.\$HE_NOM_DOMINI\" \"\$HE_IP_DNS\" \"\$HE_IP_DNSWIN\" \"\$HE_IP_DNS_AULA\" <<'PY'\n"
            .self::PY_KEA."\n".self::PY_BASE."\n".$python."\nPY\n";
    }

    /** Check Ansible a ns2: el PowerShell escriu "OK: ..." o "FAIL: ..." i l'assert ho decideix. */
    private function windowsCode(string $title, string $powershell): string
    {
        $script = implode("\n", array_map(
            fn (string $line) => $line === '' ? '' : str_repeat(' ', 8).$line,
            explode("\n", rtrim(self::PS_HEAD."\n".$powershell)),
        ));

        return <<<YAML
            - hosts: vm_dns_windows
              gather_facts: false
              tasks:
                - name: {$title}
                  ansible.windows.win_shell: |
            {$script}
                  register: r
                  changed_when: false
                  failed_when: false

                - name: {$title}
                  ansible.builtin.assert:
                    that:
                      - r.rc == 0
                      - (r.stdout | trim).startswith('OK:')
                    fail_msg: "{{ ((r.stdout | trim) or (r.stderr | trim)) | regex_replace('^FAIL: ', '') | truncate(300) }}"
                    success_msg: "{{ r.stdout | trim | regex_replace('^OK: ', '') }}"
            YAML;
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
                    'actiu_des' => '2026-10-28',
                    'data_limit' => '2026-11-13 23:59:59',
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
