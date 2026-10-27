<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Block;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Bloc 6 (DNS): primari Bind a la DMZ (ns1), secundari Windows a la LAN (ns2), delegats des
 * del DNS central de l'aula, i hardening opcional del tallafocs.
 *
 * Amb el hardening, només el central (ip_dns_aula) arriba al port 53 de la DMZ, i HeimdEye no
 * és el central. Per això les consultes a ns1 es fan des del mateix ns1 (ssh), les de la LAN
 * des de ns2 (Ansible, WinRM) i les de fora a través del central. La zona de cada alumne és
 * <nom_dns>.<nom_domini>. Cada check porta a `descripcio` el text per a l'alumne: què es
 * comprova i com ho pot verificar ell mateix. L'`info` (objectiu i justificació) és a
 * `data/check_info.php`.
 */
class Block06DnsSeeder extends Seeder
{
    /** Comú als checks ssh que pregunten a ns1 des de dins. */
    private const NS1 = <<<'SH'
        # hosts: vm_dns_bind
        zona="$HE_NOM_DNS.$HE_NOM_DOMINI"
        command -v dig >/dev/null || { echo "Falta dig a ns1 (paquet bind9-dnsutils)"; exit 1; }
        SH;

    /** Configuració efectiva de Bind en una línia, i bloc() per llegir-ne una opció amb les ACL expandides. */
    private const CONF = <<<'SH'
        conf=$(named-checkconf -p 2>/dev/null) || conf=$(sudo -n named-checkconf -p 2>/dev/null) \
          || { echo "No es pot llegir la configuració (named-checkconf -p)"; exit 1; }
        conf=$(echo "$conf" | tr -s '\n\t' '  ')
        # Primer bloc "clau { ... }" i les ACL amb nom que hi apareguin, expandides un nivell.
        bloc() {
          local b t
          b=$(echo "$conf" | grep -o "$1 *{[^}]*}" | head -1)
          for t in $(echo "$b" | sed "s/^$1//" | tr -d '{};!'); do
            b="$b $(echo "$conf" | grep -o "acl \"\?$t\"\? *{[^}]*}" | head -1)"
          done
          echo "$b"
        }
        SH;

    /** Opcions comunes per als checks locals que fan ssh. */
    private const SSH = <<<'SH'
        # HE_SSH_OPTS: no desa les claus de host (els alumnes reinstal·len les VM).
        opts=(-o BatchMode=yes -o ConnectTimeout=5 $HE_SSH_OPTS)
        [ -n "$HE_SSH_KEY" ] && opts+=(-i "$HE_SSH_KEY")
        SH;

    private const NS_PARAMS = [
        ['name' => 'NS1', 'label' => 'Nom de ns1 dins la zona', 'default' => 'ns1'],
        ['name' => 'NS2', 'label' => 'Nom de ns2 dins la zona', 'default' => 'ns2'],
    ];

    public function run(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();

        $block = Block::updateOrCreate(
            ['modul_id' => $module->id, 'numero' => 6],
            [
                'titol' => 'DNS: servei de resolució de noms',
                'data_inici' => '2026-10-19',
                'data_fi' => '2026-10-23',
                'posicio' => 6,
            ],
        );

        $this->task($block, 1, 'Accés de HeimdEye als servidors DNS', <<<'MD'
            HeimdEye (192.168.20.12) ha de poder entrar a ns1 (10.X.0.11) per SSH amb l'usuari `jaume` i
            la seva clau, i a ns2 (172.X.0.11) per WinRM amb l'usuari `jaume`. Sense aquest accés, la resta
            de checks del bloc no es poden comprovar. Les instruccions són a l'enunciat del bloc.
            MD, [
            [
                'nom' => 'SSH a ns1',
                'executor' => Executor::Local,
                'descripcio' => 'HeimdEye entra a ns1 (10.X.0.11) per SSH com a jaume amb clau. Comprova-ho a ns1: `/home/jaume/.ssh/authorized_keys` conté la clau del professor i `sudo -l -U jaume` mostra `NOPASSWD: ALL`. Ho pot preparar el professor des del vostre Proxmox.',
                'codi' => self::SSH."\n".<<<'SH'
                    ssh "${opts[@]}" "$HE_SSH_USER@$HE_IP_DNS" true || { echo "No es pot entrar per SSH a ns1 ($HE_IP_DNS)"; exit 1; }
                    echo "SSH correcte a ns1 ($HE_IP_DNS)"
                    SH,
            ],
            [
                'nom' => 'WinRM a ns2',
                'executor' => Executor::Ansible,
                'descripcio' => 'HeimdEye entra a ns2 per WinRM (port 5985) com a jaume. Comprova-ho des d\'un altre Windows: `Test-WSMan 172.X.0.11 -Authentication Negotiate -Credential jaume`.',
                'temps_max_seg' => 60,
                'codi' => <<<'YAML'
                    - hosts: vm_dns_windows
                      gather_facts: false
                      tasks:
                        - name: WinRM a ns2
                          ansible.windows.win_ping:
                    YAML,
            ],
        ]);

        $this->task($block, 2, 'Zona pròpia a ns1', <<<'MD'
            Bind a ns1 com a servidor autoritatiu de la zona `<usuari>.smix2.julioantonio.cat`, amb SOA, NS,
            A, CNAME i MX, i una configuració que passi `named-checkconf` i `named-checkzone`.
            MD, [
            [
                'nom' => 'Bind en marxa a ns1',
                'descripcio' => 'El servei de Bind (named o bind9) està actiu a ns1. Comprova-ho: `systemctl is-active named`.',
                'codi' => self::NS1."\n".<<<'SH'
                    for s in named bind9; do
                      systemctl is-active --quiet "$s" && { echo "Bind actiu ($s)"; exit 0; }
                    done
                    echo "Bind no està en marxa"; exit 1
                    SH,
            ],
            [
                'nom' => 'Configuració i zona vàlides',
                'descripcio' => 'La configuració és correcta i la zona es carrega. Comprova-ho: `named-checkconf -z` ha de dir `zone <usuari>.smix2.julioantonio.cat/IN: loaded serial ...` i acabar sense errors.',
                'codi' => self::NS1."\n".<<<'SH'
                    out=$(named-checkconf -z 2>&1) || out=$(sudo -n named-checkconf -z 2>&1) \
                      || { echo "named-checkconf -z falla: $(echo "$out" | grep -v 'loaded serial' | head -1)"; exit 1; }
                    echo "$out" | grep -q "^zone $zona/IN: loaded serial" || { echo "La zona $zona no es carrega"; exit 1; }
                    echo "Configuració correcta, $(echo "$out" | grep "^zone $zona/IN")"
                    SH,
            ],
            [
                'nom' => 'ns1 és autoritatiu de la zona',
                'punts' => 2,
                'descripcio' => 'ns1 respon el SOA de la zona amb el flag aa (resposta autoritativa). Comprova-ho a ns1: `dig @10.X.0.11 <usuari>.smix2.julioantonio.cat SOA +norecurse` ha de donar `status: NOERROR` i `flags: qr aa`.',
                'codi' => self::NS1."\n".<<<'SH'
                    out=$(dig @"$HE_IP_DNS" "$zona" SOA +norecurse +time=2 +tries=1) || { echo "ns1 no respon a $HE_IP_DNS"; exit 1; }
                    echo "$out" | grep -q 'status: NOERROR' || { echo "ns1 respon $(echo "$out" | grep -o 'status: [A-Z]*')"; exit 1; }
                    echo "$out" | grep -q 'flags:[^;]* aa' || { echo "Resposta sense aa: ns1 no és autoritatiu de $zona"; exit 1; }
                    echo "Autoritatiu de $zona, serial $(dig @"$HE_IP_DNS" "$zona" SOA +short | awk '{print $3}')"
                    SH,
            ],
            [
                'nom' => 'Registres NS i A de ns1 i ns2',
                'punts' => 2,
                'parametres' => self::NS_PARAMS,
                'descripcio' => 'La zona té un registre NS que apunta a ns1, i els registres A ns1 = 10.X.0.11 i ns2 = 172.X.0.11. Comprova-ho a ns1: `dig @10.X.0.11 <zona> NS +short`, `dig @10.X.0.11 ns1.<zona> +short` i `dig @10.X.0.11 ns2.<zona> +short`.',
                'codi' => self::NS1."\n".<<<'SH'
                    q() { dig @"$HE_IP_DNS" +norecurse +short "$@"; }
                    ns=$(q "$zona" NS)
                    echo "$ns" | grep -qx "$HE_PARAM_NS1.$zona." || { echo "Falta NS $HE_PARAM_NS1.$zona. (hi ha: $(echo $ns))"; exit 1; }
                    a1=$(q "$HE_PARAM_NS1.$zona" A); [ "$a1" = "$HE_IP_DNS" ] || { echo "$HE_PARAM_NS1.$zona hauria de ser $HE_IP_DNS (és '$a1')"; exit 1; }
                    a2=$(q "$HE_PARAM_NS2.$zona" A); [ "$a2" = "$HE_IP_DNSWIN" ] || { echo "$HE_PARAM_NS2.$zona hauria de ser $HE_IP_DNSWIN (és '$a2')"; exit 1; }
                    echo "NS $HE_PARAM_NS1, A $HE_PARAM_NS1=$a1 i $HE_PARAM_NS2=$a2"
                    SH,
            ],
            [
                'nom' => 'Àlies (CNAME)',
                'parametres' => [['name' => 'ALIES', 'label' => 'Nom que ha de ser un CNAME', 'default' => 'www']],
                'descripcio' => '`www.<zona>` és un àlies (CNAME), per exemple del servidor web de la DMZ. Comprova-ho a ns1: `dig @10.X.0.11 www.<zona> CNAME +short` ha de tornar el nom real.',
                'codi' => self::NS1."\n".<<<'SH'
                    c=$(dig @"$HE_IP_DNS" +norecurse +short "$HE_PARAM_ALIES.$zona" CNAME)
                    [ -n "$c" ] || { echo "$HE_PARAM_ALIES.$zona no és un CNAME"; exit 1; }
                    echo "$HE_PARAM_ALIES.$zona -> $c"
                    SH,
            ],
            [
                'nom' => 'Correu (MX)',
                'descripcio' => 'La zona té un MX, i el servidor de correu té registre A i no és un CNAME. Comprova-ho a ns1: `dig @10.X.0.11 <zona> MX +short` i després `dig @10.X.0.11 <nom del MX>` (ha de sortir un A, no un CNAME).',
                'codi' => self::NS1."\n".<<<'SH'
                    q() { dig @"$HE_IP_DNS" +norecurse +short "$@"; }
                    mx=$(q "$zona" MX | sort -n | head -1 | awk '{print $2}')
                    [ -n "$mx" ] || { echo "La zona no té cap MX"; exit 1; }
                    [ -z "$(q "$mx" CNAME)" ] || { echo "El MX $mx apunta a un CNAME"; exit 1; }
                    [ -n "$(q "$mx" A)" ] || { echo "$mx no té registre A"; exit 1; }
                    echo "MX $mx ($(q "$mx" A))"
                    SH,
            ],
        ]);

        $this->task($block, 3, 'Delegació des de smix2', <<<'MD'
            El DNS central (192.168.20.11) delega la vostra zona a ns1 (i després també a ns2) amb els
            registres NS i glue. El central ja té la delegació: el que heu de fer és que ns1 respongui al
            central.
            MD, [
            [
                'nom' => 'El central delega la zona a ns1 amb glue',
                'executor' => Executor::Local,
                'punts' => 2,
                'parametres' => [self::NS_PARAMS[0]],
                'descripcio' => 'El central respon a la consulta de la zona amb una referència cap a ns1 i la seva IP (glue). Comprova-ho: `dig @192.168.20.11 <zona> NS +norecurse` ha de mostrar el NS a AUTHORITY i l\'A de ns1 a ADDITIONAL.',
                'codi' => <<<'SH'
                    zona="$HE_NOM_DNS.$HE_NOM_DOMINI"; z=${zona//./\\.}; n1="$HE_PARAM_NS1\.$z"
                    out=$(dig @"$HE_IP_DNS_AULA" "$zona" NS +norecurse +noall +authority +additional +time=3 +tries=1) \
                      || { echo "El central ($HE_IP_DNS_AULA) no respon"; exit 1; }
                    echo "$out" | grep -Eq "^$z\.\s.*\sNS\s+$n1\.$" || { echo "El central no delega $zona a $HE_PARAM_NS1.$zona"; exit 1; }
                    echo "$out" | grep -Eq "^$n1\.\s.*\sA\s+${HE_IP_DNS//./\\.}$" || { echo "Falta el glue $HE_PARAM_NS1.$zona -> $HE_IP_DNS"; exit 1; }
                    echo "Delegació a $HE_PARAM_NS1.$zona amb glue $HE_IP_DNS"
                    SH,
            ],
            [
                'nom' => 'El central resol la zona fins a ns1',
                'executor' => Executor::Local,
                'punts' => 2,
                'descripcio' => 'Una consulta al central per un nom inventat de la zona arriba fins al vostre servidor: la resposta és NXDOMAIN amb el SOA de la vostra zona, no el de smix2. Si surt el SOA de smix2, el central no arriba a ns1 (tallafocs, Bind aturat o allow-query). Comprova-ho: `dig @192.168.20.11 noexisteix.<zona>`.',
                'codi' => <<<'SH'
                    zona="$HE_NOM_DNS.$HE_NOM_DOMINI"; z=${zona//./\\.}
                    # Nom aleatori: no pot ser a la cau del central.
                    nom="heimdeye-$RANDOM$RANDOM.$zona"
                    out=$(dig @"$HE_IP_DNS_AULA" "$nom" A +time=5 +tries=1) || { echo "El central no respon"; exit 1; }
                    st=$(echo "$out" | grep -o 'status: [A-Z]*' | cut -d' ' -f2)
                    [ "$st" = NXDOMAIN ] || { echo "El central respon $st per $nom (no arriba a ns1?)"; exit 1; }
                    echo "$out" | grep -Eq "^$z\.\s.*\sSOA\s" || { echo "NXDOMAIN amb el SOA del pare: el central no arriba a $zona"; exit 1; }
                    echo "El central arriba a $zona (NXDOMAIN amb el SOA de la zona)"
                    SH,
            ],
        ]);

        $this->task($block, 4, 'Recursivitat per a la LAN', <<<'MD'
            ns1 fa de servidor recursiu només per a la LAN (allow-recursion), i reenvia les consultes
            externes al central (forwarders).
            MD, [
            [
                'nom' => 'ns1 resol noms externs per a la LAN',
                'executor' => Executor::Ansible,
                'punts' => 2,
                'temps_max_seg' => 60,
                'parametres' => [['name' => 'NOM_EXTERN', 'label' => 'Nom extern a resoldre', 'default' => 'www.gencat.cat']],
                'descripcio' => 'Des de ns2 (a la LAN), ns1 resol un nom d\'Internet. Comprova-ho a ns2: `Resolve-DnsName www.gencat.cat -Server 10.X.0.11 -DnsOnly`.',
                'codi' => <<<'YAML'
                    - hosts: vm_dns_windows
                      gather_facts: false
                      tasks:
                        - name: Consulta recursiva a ns1 des de la LAN
                          ansible.windows.win_shell: |
                            $r = Resolve-DnsName -Name '{{ NOM_EXTERN }}' -Type A -Server '{{ ip_dns }}' -DnsOnly -ErrorAction Stop
                            ($r | Where-Object Type -eq 'A' | Select-Object -First 1).IPAddress
                          register: r
                          changed_when: false
                          failed_when: false

                        - name: ns1 fa de recursiu per a la LAN
                          ansible.builtin.assert:
                            that:
                              - r.rc == 0
                              - r.stdout | trim | length > 0
                            fail_msg: "ns1 ({{ ip_dns }}) no resol {{ NOM_EXTERN }} per a la LAN: {{ r.stderr | default('') | trim | truncate(150) }}"
                            success_msg: "ns1 resol {{ NOM_EXTERN }} per a la LAN: {{ r.stdout | trim }}"
                    YAML,
            ],
            [
                'nom' => 'Recursió limitada a la LAN',
                'descripcio' => 'A la configuració, allow-recursion inclou la vostra LAN (172.X.0.0/24) i no és any ni localnets (que inclouria la DMZ). Es poden fer servir ACL amb nom. Comprova-ho a ns1: `named-checkconf -p | grep -A3 allow-recursion`.',
                'codi' => self::NS1."\n".self::CONF."\n".<<<'SH'
                    rec=$(bloc allow-recursion)
                    [ -n "$rec" ] || { echo "No hi ha allow-recursion (per defecte inclou la DMZ)"; exit 1; }
                    echo "$rec" | grep -Eqw 'any|localnets' && { echo "allow-recursion massa obert: $rec"; exit 1; }
                    echo "$rec" | grep -q "172\.$HE_X\." || { echo "allow-recursion no inclou la LAN 172.$HE_X.0.0"; exit 1; }
                    echo "Recursió limitada: $rec"
                    SH,
            ],
            [
                'nom' => 'Forwarders cap al central',
                'descripcio' => 'Els forwarders de ns1 apunten al central (192.168.20.11). Comprova-ho a ns1: `named-checkconf -p | grep -A3 forwarders`.',
                'codi' => self::NS1."\n".self::CONF."\n".<<<'SH'
                    fwd=$(bloc forwarders)
                    echo "$fwd" | grep -qF "$HE_IP_DNS_AULA" || { echo "Els forwarders no apunten al central $HE_IP_DNS_AULA"; exit 1; }
                    echo "Forwarders: $fwd"
                    SH,
            ],
        ]);

        $this->task($block, 5, 'Secundari a ns2', <<<'MD'
            ns2 (Windows Server a la LAN) té una còpia secundària de la zona amb ns1 com a mestre. ns1 només
            deixa fer la transferència a ns2 i l'avisa amb NOTIFY quan canvia la zona.
            MD, [
            [
                'nom' => 'Zona secundària a ns2 amb ns1 com a mestre',
                'executor' => Executor::Ansible,
                'punts' => 2,
                'temps_max_seg' => 60,
                'descripcio' => 'A ns2, la zona és de tipus secundari i el seu mestre és ns1 (10.X.0.11). Comprova-ho a ns2: `Get-DnsServerZone <zona> | Format-List ZoneType, MasterServers`.',
                'codi' => <<<'YAML'
                    - hosts: vm_dns_windows
                      gather_facts: false
                      tasks:
                        - name: Llegir la zona a ns2
                          ansible.windows.win_shell: |
                            $z = Get-DnsServerZone -Name '{{ nom_dns }}.{{ nom_domini }}' -ErrorAction Stop
                            "$($z.ZoneType);$($z.MasterServers.IPAddressToString -join ',')"
                          register: r
                          changed_when: false
                          failed_when: false

                        - name: Secundària amb ns1 com a mestre
                          ansible.builtin.assert:
                            that:
                              - r.rc == 0
                              - (r.stdout | trim).split(';')[0] == 'Secondary'
                              - ip_dns in (r.stdout | trim).split(';')[1].split(',')
                            fail_msg: "ns2 no té {{ nom_dns }}.{{ nom_domini }} com a secundària de {{ ip_dns }}: {{ (r.stdout ~ r.stderr) | trim | truncate(150) }}"
                            success_msg: "Zona secundària, mestre {{ ip_dns }}"
                    YAML,
            ],
            [
                'nom' => 'ns2 té el mateix serial que ns1',
                'punts' => 2,
                'parametres' => [self::NS_PARAMS[1]],
                'descripcio' => 'La zona té el NS de ns2, ns2 respon com a autoritatiu (aa) i el serial del SOA és el mateix a tots dos. Si canvieu la zona a ns1 i no augmenteu el serial, o el NOTIFY o l\'AXFR no arriben, fallarà. Comprova-ho a ns1: `dig @10.X.0.11 <zona> SOA +short` i `dig @172.X.0.11 <zona> SOA +short`.',
                'codi' => self::NS1."\n".<<<'SH'
                    dig @"$HE_IP_DNS" +norecurse +short "$zona" NS | grep -qx "$HE_PARAM_NS2.$zona." \
                      || { echo "Falta NS $HE_PARAM_NS2.$zona. a la zona"; exit 1; }
                    s1=$(dig @"$HE_IP_DNS" +norecurse +short "$zona" SOA | awk '{print $3}')
                    out=$(dig @"$HE_IP_DNSWIN" "$zona" SOA +norecurse +time=2 +tries=1) || { echo "ns2 ($HE_IP_DNSWIN) no respon des de ns1"; exit 1; }
                    echo "$out" | grep -q 'flags:[^;]* aa' || { echo "ns2 no és autoritatiu de $zona"; exit 1; }
                    s2=$(echo "$out" | awk '$4 == "SOA" {print $7}')
                    [ "$s1" = "$s2" ] || { echo "Serial diferent: ns1 $s1, ns2 $s2"; exit 1; }
                    echo "ns1 i ns2 amb el serial $s1"
                    SH,
            ],
            [
                'nom' => 'Transferència i NOTIFY només cap a ns2',
                'descripcio' => 'allow-transfer i also-notify inclouen ns2 (172.X.0.11), allow-transfer no és any ni localnets, i ns1 denega l\'AXFR a qualsevol altre equip (també a ell mateix). Comprova-ho a ns1: `dig @10.X.0.11 <zona> AXFR` ha de donar `Transfer failed`.',
                'codi' => self::NS1."\n".self::CONF."\n".<<<'SH'
                    tr=$(bloc allow-transfer)
                    echo "$tr" | grep -qF "$HE_IP_DNSWIN" || { echo "allow-transfer no inclou ns2 ($HE_IP_DNSWIN)"; exit 1; }
                    echo "$tr" | grep -Eqw 'any|localnets' && { echo "allow-transfer massa obert: $tr"; exit 1; }
                    bloc also-notify | grep -qF "$HE_IP_DNSWIN" || { echo "also-notify no inclou ns2"; exit 1; }
                    # Des de ns1 mateix (no és ns2) l'AXFR s'ha de denegar.
                    dig @"$HE_IP_DNS" "$zona" AXFR +time=2 +tries=1 | grep -q "SOA" \
                      && { echo "ns1 deixa fer l'AXFR a equips que no són ns2"; exit 1; }
                    echo "AXFR i NOTIFY només cap a $HE_IP_DNSWIN"
                    SH,
            ],
            [
                'nom' => 'El central delega també a ns2',
                'executor' => Executor::Local,
                'parametres' => [self::NS_PARAMS[1]],
                'descripcio' => 'La delegació del central inclou ns2 amb el seu glue. Comprova-ho: `dig @192.168.20.11 <zona> NS +norecurse` ha de mostrar ns1 i ns2 a AUTHORITY i les dues IP a ADDITIONAL.',
                'codi' => <<<'SH'
                    zona="$HE_NOM_DNS.$HE_NOM_DOMINI"; z=${zona//./\\.}; n2="$HE_PARAM_NS2\.$z"
                    out=$(dig @"$HE_IP_DNS_AULA" "$zona" NS +norecurse +noall +authority +additional +time=3 +tries=1) \
                      || { echo "El central no respon"; exit 1; }
                    echo "$out" | grep -Eq "^$z\.\s.*\sNS\s+$n2\.$" || { echo "La delegació no inclou $HE_PARAM_NS2.$zona"; exit 1; }
                    echo "$out" | grep -Eq "^$n2\.\s.*\sA\s+${HE_IP_DNSWIN//./\\.}$" || { echo "Falta el glue $HE_PARAM_NS2.$zona -> $HE_IP_DNSWIN"; exit 1; }
                    echo "Delegació amb $HE_PARAM_NS2.$zona i glue $HE_IP_DNSWIN"
                    SH,
            ],
        ]);

        $this->task($block, 6, 'Hardening del tallafocs (opcional)', <<<'MD'
            Opcional. Política DROP al forward del router i només els ports necessaris per al DNS, amb
            l'origen més restrictiu possible. Si no el feu, deixeu el tallafocs obert (accept) perquè la
            resta de checks funcionin. Si el feu, recordeu deixar passar l'accés de HeimdEye
            (192.168.20.12): SSH a ns1 i al router, i WinRM (TCP 5985) a ns2.
            MD, [
            [
                'nom' => 'El DNS de la DMZ no és accessible des de fora',
                'executor' => Executor::Local,
                'tipus' => CheckKind::Optional,
                'punts' => 2,
                'descripcio' => 'ns1 funciona, però HeimdEye (192.168.20.12), que no és el central, no arriba al seu port 53 ni per UDP ni per TCP. Comprova-ho des d\'un equip de l\'aula que no sigui el central: `dig @10.X.0.11 <zona> SOA +time=2` ha de donar `timed out`.',
                'codi' => self::SSH."\n".<<<'SH'
                    zona="$HE_NOM_DNS.$HE_NOM_DOMINI"
                    # Primer, que ns1 funcioni: si està aturat, no respondre no vol dir res.
                    viu=$(ssh "${opts[@]}" "$HE_SSH_USER@$HE_IP_DNS" "dig @$HE_IP_DNS +short +norecurse $zona SOA") || true
                    [ -n "$viu" ] || { echo "No es pot comprovar: ns1 no respon ni des de dins"; exit 1; }
                    for p in +notcp +tcp; do
                      dig @"$HE_IP_DNS" "$zona" SOA $p +time=2 +tries=1 >/dev/null 2>&1 \
                        && { echo "HeimdEye (no és el central) arriba al port 53 de ns1 ($p)"; exit 1; }
                    done
                    echo "Port 53 de ns1 tancat per a HeimdEye (UDP i TCP)"
                    SH,
            ],
            [
                'nom' => 'Política DROP al forward del router',
                'executor' => Executor::Local,
                'tipus' => CheckKind::Optional,
                'descripcio' => 'La darrera regla activa de la cadena forward del MikroTik és un drop sense condicions. Comprova-ho al router: `/ip firewall filter print where chain=forward`.',
                'codi' => self::SSH."\n".<<<'SH'
                    out=$(ssh "${opts[@]}" "$HE_SSH_USER@$HE_IP_ROUTER" '/ip firewall filter print terse without-paging where chain=forward disabled=no') \
                      || { echo "No es pot llegir el tallafocs del router"; exit 1; }
                    last=$(echo "$out" | grep -v '^\s*$' | tail -1)
                    echo "$last" | grep -q 'action=drop' || { echo "La darrera regla del forward no és un drop"; exit 1; }
                    echo "$last" | grep -Eq '(src|dst)-address|protocol=|in-interface|out-interface|connection-state' \
                      && { echo "La darrera regla del forward no ho descarta tot: $last"; exit 1; }
                    echo "Forward acaba amb drop"
                    SH,
            ],
        ]);

        $this->actions($module, $block);
    }

    /**
     * Acció per preparar ns1 sense que l'alumne hi hagi de fer res: des del Proxmox de l'alumne,
     * busca el contenidor o la VM amb l'adreça de ns1 i hi entra amb pct exec o amb l'agent QEMU.
     */
    private function actions(Module $module, Block $block): void
    {
        $action = $module->actions()->updateOrCreate(
            ['nom' => 'Accés de jaume a ns1 (des del Proxmox)'],
            [
                'bloc_id' => $block->id,
                'descripcio' => 'Busca ns1 (ip_dns) entre els contenidors i les VM del Proxmox de l\'alumne i hi crea l\'usuari jaume amb la clau de HeimdEye, sudo sense contrasenya i dig. Una VM ha de tenir qemu-guest-agent.',
                'executor' => Executor::Ansible,
                'posicio' => 10,
            ],
        );
        if ($action->versions()->exists()) {
            return;
        }

        $action->versions()->create(['versio' => 1, 'temps_max_seg' => 300, 'codi' => <<<'YAML'
            - hosts: pve
              become: true
              gather_facts: false
              tasks:
                - name: Buscar ns1 ({{ ip_dns }}) al Proxmox
                  ansible.builtin.shell: |
                    for id in $(pct list 2>/dev/null | awk 'NR>1 && $2=="running" {print $1}'); do
                      pct exec "$id" -- hostname -I 2>/dev/null | tr ' ' '\n' | grep -qxF '{{ ip_dns }}' && { echo "pct $id"; exit 0; }
                    done
                    for id in $(qm list 2>/dev/null | awk 'NR>1 && $3=="running" {print $1}'); do
                      qm guest cmd "$id" network-get-interfaces 2>/dev/null | grep -qF '"{{ ip_dns }}"' && { echo "qm $id"; exit 0; }
                    done
                    echo "Cap contenidor ni VM (amb qemu-guest-agent) té l'adreça {{ ip_dns }}" >&2
                    exit 1
                  args:
                    executable: /bin/bash
                  register: guest
                  changed_when: false
                  check_mode: false

                - name: Usuari jaume amb clau, sudo i dig a ns1
                  ansible.builtin.shell: |
                    read -r kind id <<< '{{ guest.stdout }}'
                    read -r -d '' script <<'EOF' || true
                    set -e
                    key='{{ lookup('file', heimdeye_ssh_pubkey_file) }}'
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
                    if ! command -v dig >/dev/null; then apt-get install -y -q bind9-dnsutils >/dev/null; echo "CANVI: bind9-dnsutils"; fi
                    echo "ns1 a punt"
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
            YAML]);
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
                    'executor' => $def['executor'] ?? Executor::Ssh,
                    'tipus' => $def['tipus'] ?? CheckKind::Required,
                    'punts' => $def['punts'] ?? 1,
                    'actiu_des' => '2026-10-26',
                    'data_limit' => '2026-10-30 23:59:59',
                    'posicio' => $i + 1,
                ],
            );

            if (! $check->versions()->exists()) {
                $check->versions()->create([
                    'versio' => 1,
                    'codi' => $def['codi'],
                    'parametres' => $def['parametres'] ?? null,
                    'temps_max_seg' => $def['temps_max_seg'] ?? 15,
                ]);
            }
        }
    }
}
