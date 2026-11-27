<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Action;
use App\Models\Block;
use App\Models\Incident;
use App\Models\IncidentStudent;
use App\Models\Module;
use App\Models\Task;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Bloc 12, pas 15: la sessió d'incidències de correu (dues hores). La bateria de la guia del
 * professor: 30 avaries, 5 categories amb 6 variants.
 *
 * Una incidència de HeimdEye per categoria, amb un nom que no la delata («Cas 1» a «Cas 5», en
 * l'ordre d'injecció: SRV, DNS, RTR, PVE i SEG) i sis opcions, una per variant. Cada opció té el
 * seu símptoma (el tiquet que llegeix l'alumne, mai la causa), l'acció d'avaria de la categoria amb
 * el paràmetre AVARIA, l'acció de reparació i un check de resolució propi, de tipus «incidència»:
 * no puntua, no surt als recomptes ni corre a la nit. L'alumne el pot verificar cada 2 minuts.
 *
 * Per on entra cada codi (perquè una avaria de xarxa no tapi les altres):
 *  - PVE, DNS, SRV i SEG: ssh al Proxmox de l'alumne (grup pve; jaume amb «sudo -n»). A l'LXC mail
 *    s'hi entra amb «pct exec» i a ns1 amb «qm guest exec» (cal qemu-guest-agent a ns1; la VM es
 *    troba per l'adreça 10.X.0.11, i l'LXC pel nom «mail»).
 *  - DNS-6: des de HeimdEye, preguntant al DNS central un nom nou de la zona (és l'únic check que
 *    depèn de la xarxa: si el central no contesta, surt omès).
 *  - RTR: al MikroTik, per l'adreça d'aula, amb l'accés del mòdul.
 * Si un check no es pot avaluar (LXC aturat, ns1 sense agent, router que no respon) surt omès:
 * no canvia l'estat de la incidència.
 *
 * Abans d'injectar, tots els entorns han d'estar com diu la guia (zona amb les línies dels passos
 * 3, 10 i 12, regles del router amb els comentaris «DNAT SMTP» i «F1» a «F6», ns2 apagat, LXC mail
 * amb onboot 1, 2048 MB i 512 MB de swap) i els 30 checks han de passar.
 *
 * Les accions guarden el valor original a /root/.heimdeye-b12/ (al Proxmox, a l'LXC o a ns1) i la
 * reparació el torna a posar. Si una acció no troba el que ha de canviar, falla i la incidència
 * queda en error.
 *
 * Cal haver executat abans Block12CorreuSeeder. No llança cap incidència. Es pot tornar a executar
 * mentre no s'hagi llançat: esborra les incidències, les accions i els checks d'aquesta tasca i els
 * torna a crear.
 *
 * S'executa amb: php artisan db:seed --class=Block12IncidentsSeeder --force
 */
class Block12IncidentsSeeder extends Seeder
{
    public const TASK = 'Pas 15 · Incidències';

    public const MINUTES = 2;

    /** Categories en l'ordre d'injecció: de dins cap enfora, i la de seguretat al final. */
    private const CATEGORIES = ['SRV', 'DNS', 'RTR', 'PVE', 'SEG'];

    /** Comú a tot el codi. */
    private const BASE = <<<'SH'
        Z="$HE_NOM_DNS.$HE_NOM_DOMINI"      # zona de l'alumne
        X="$HE_X"
        msg() { echo "HEIMDEYE_MSG: $*"; }
        fail() { msg "$@"; exit 1; }
        omit() { echo "HEIMDEYE_OMES: $*"; exit 0; }
        SH;

    /** Al Proxmox: troba l'LXC mail, la VM del MikroTik i la de ns1, i hi executa codi. */
    private const PVE = <<<'SH'
        find_ct() { sudo -n pct list 2>/dev/null | awk '$NF ~ /^mail($|\.)/ { print $1; exit }'; }
        running() { sudo -n pct status "$1" 2>/dev/null | grep -q running; }
        find_router() { sudo -n qm list 2>/dev/null | awk '$2 ~ /[Mm]ikro/ && $3 == "running" { print $1; exit }'; }
        find_ns1() {
          local id
          for id in $(sudo -n qm list 2>/dev/null | awk '$3 == "running" { print $1 }'); do
            sudo -n qm guest cmd "$id" network-get-interfaces 2>/dev/null \
              | grep -qE "\"ip-address\" *: *\"10\.$X\.0\.11\"" && { echo "$id"; return 0; }
          done
          return 1
        }
        # Variables que arriben a dins de l'LXC i de ns1.
        vars() {
          printf 'Z=%q X=%q N=%q HEIP=%q CENTRAL=%q ZENTYAL=%q IPROUTER=%q\n' \
            "$Z" "$X" "${N:-}" "$HE_IP_HEIMDEYE" "$HE_IP_DNS_AULA" "$HE_IP_ZENTYAL" "$HE_IP_ROUTER"
        }
        # El codi que arriba per l'entrada, amb les variables i les funcions comunes.
        wrap() {
          vars
          echo 'export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"'
          echo 'msg() { echo "HEIMDEYE_MSG: $*"; }; fail() { msg "$@"; exit 1; }; omit() { echo "HEIMDEYE_OMES: $*"; exit 0; }'
          echo '{'
          cat
          echo '} </dev/null'
        }
        # Dins de l'LXC mail, com a root.
        ct() { wrap | sudo -n pct exec "$CT" -- bash -s; }
        # Dins de ns1, com a root, amb l'agent de QEMU. Surt amb 125 si no s'ha pogut executar.
        guest() {
          local out
          out=$(wrap | sudo -n qm guest exec "$NS1" --pass-stdin 1 --timeout 40 -- /bin/bash -s) || return 125
          perl -MJSON::PP -e '
            my $d = eval { decode_json(do { local $/; <STDIN> }) };
            exit 125 unless $d && defined $d->{exitcode};
            for my $s ($d->{"out-data"} // "", $d->{"err-data"} // "") {
              # Segons la versió, el text arriba en caràcters o ja en bytes UTF-8: sempre surt en UTF-8.
              my $b = $s;
              utf8::encode($s) unless utf8::downgrade($b, 1) && utf8::decode($b);
              print $s;
            }
            exit $d->{exitcode};' <<<"$out"
        }
        SH;

    private const NEED_CT = <<<'SH'
        CT=$(find_ct)
        [ -n "$CT" ] || omit "no trobo l'LXC mail al Proxmox"
        SH;

    private const NEED_RUNNING = <<<'SH'
        running "$CT" || omit "l'LXC mail està aturat: ara no es pot comprovar"
        SH;

    private const NEED_NS1 = <<<'SH'
        NS1=$(find_ns1) || omit "no trobo la VM de ns1 (10.X.0.11) amb l'agent de QEMU en marxa"
        SH;

    /** Accés al MikroTik per l'adreça d'aula. */
    private const ROUTER = <<<'SH'
        opts=(-n -o BatchMode=yes -o ConnectTimeout=5 $HE_SSH_OPTS)
        [ -n "$HE_SSH_KEY" ] && opts+=(-i "$HE_SSH_KEY")
        r() { ssh "${opts[@]}" "$HE_SSH_USER@$HE_IP_ROUTER" "$@"; }
        # Nombre d'elements d'un «print count-only»; falla si el router no respon un número.
        count() { local v; v=$(r "$1" | tr -d '\r[:space:]'); [[ $v =~ ^[0-9]+$ ]] || return 1; echo "$v"; }
        so=$(r ':put routeros' 2>/dev/null); rc=$?
        SH;

    private const CHECK_ROUTER = <<<'SH'
        [ $rc -eq 255 ] && omit "HeimdEye no pot entrar al router ($HE_IP_ROUTER)"
        [ "$(echo "$so" | tr -d '\r')" = routeros ] || omit "ara no respon el MikroTik"
        SH;

    public function run(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();
        $block = Block::where('modul_id', $module->id)->where('numero', 12)->first()
            ?? throw new RuntimeException('No existeix el bloc 12: executa primer Block12CorreuSeeder');
        $module->variables()->where('clau', 'ip_mail')->exists()
            ?: throw new RuntimeException('No existeix la variable ip_mail: executa primer Block12CorreuSeeder');

        $task = $block->tasks()->firstOrCreate(
            ['titol' => self::TASK],
            ['enunciat' => self::statement(), 'posicio' => 15],
        );
        $incidents = Incident::where('bloc_id', $block->id)->where('tasca_id', $task->id);
        if (IncidentStudent::whereIn('incidencia_id', (clone $incidents)->select('id'))->exists()) {
            throw new RuntimeException('Aquestes incidències ja s\'han llançat: tornar a crear-les n\'esborraria l\'historial. Esborra abans les assignacions (Incidències → Seguiment).');
        }
        $task->update(['enunciat' => self::statement(), 'posicio' => 15]);

        // Es comença de zero: les incidències, les accions i els checks d'aquesta tasca.
        $incidents->delete();
        Action::where('bloc_id', $block->id)->where('tasca_id', $task->id)->delete();
        $task->checks()->delete();

        $position = 0;
        foreach (self::CATEGORIES as $c => $category) {
            [$what, $notes] = self::categories()[$category];
            $router = $category === 'RTR';
            $executor = $router ? Executor::Local : Executor::Ssh;
            $inject = $this->action($module, $block, $task, "Correu {$category}: avaria", "Injecta una avaria de la categoria {$category} ({$what}). Paràmetre AVARIA = 1 a 6.", $executor, self::actionCode($category, true), true, 2 * $c + 1);
            $repair = $this->action($module, $block, $task, "Correu {$category}: reparar", "Desfà l'avaria {$category} (AVARIA = 1 a 6). Per a tu: no compta com a resolució.", $executor, self::actionCode($category, false), false, 2 * $c + 2);

            $incident = Incident::create([
                'modul_id' => $module->id, 'bloc_id' => $block->id, 'tasca_id' => $task->id,
                'nom' => 'Cas '.($c + 1),
                'simptoma' => 'Hi ha una avaria al correu de la teva empresa. Esbrina la causa i corregeix-la.',
                'minuts_verificacio' => self::MINUTES,
                'notes' => "Categoria {$category}: {$what}. {$notes}",
                'posicio' => $c + 1,
            ]);

            foreach (self::variants()[$category] as $i => [$ticket, $cause, $passes, $code]) {
                $n = $i + 1;
                $check = $task->checks()->create([
                    'nom' => "Incidència {$category}-{$n}: {$passes}",
                    'descripcio' => "Passa quan {$passes}.",
                    'executor' => str_starts_with($code, '# hosts:') ? Executor::Ssh : Executor::Local,
                    'tipus' => CheckKind::Incident,
                    'posicio' => ++$position,
                ]);
                $check->versions()->create(['versio' => 1, 'codi' => $code, 'temps_max_seg' => 90]);

                $incident->options()->create([
                    'nom' => "{$category}-{$n} · {$cause}",
                    'simptoma' => $ticket,
                    'accio_id' => $inject->id,
                    'parametres' => ['AVARIA' => (string) $n],
                    'check_id' => $check->id,
                    'accio_reparacio_id' => $repair->id,
                    'posicio' => $n,
                ]);
            }
        }
    }

    private static function statement(): string
    {
        return <<<'MD'
            Sessió d'incidències (dues hores). Rebràs uns tiquets amb el que diria un usuari: mai la
            causa. Per cada tiquet, anota **el símptoma**, **les comprovacions que has fet i en quin
            ordre**, **la causa** i **la correcció**. HeimdEye diu si està resolta; el document diu si
            l'has entesa. Una incidència resolta reiniciant-ho tot o restaurant una còpia, sense causa,
            no val.
            MD;
    }

    /** @return array<string, array{0: string, 1: string}> categoria => què és i notes per al professor */
    private static function categories(): array
    {
        return [
            'SRV' => ['servei de correu (LXC mail)', 'S\'injecta la primera.'],
            'DNS' => ['noms (ns1)', 'S\'injecta la segona. ns2 ha d\'estar apagat.'],
            'RTR' => ['encaminament, tallafocs i NAT (MikroTik)', 'S\'injecta la tercera.'],
            'PVE' => ['Proxmox i màquines', 'S\'injecta l\'última de les obligatòries: pot tapar les altres. PVE-5 es nota amb càrrega (uns quants correus alhora).'],
            'SEG' => ['seguretat (LXC mail i ns1)', 'Només per a qui hagi acabat les altres quatre. Amb SEG-1 l\'aula pot enviar a través del servidor: revisa el consum del relay.'],
        ];
    }

    /**
     * Les 30 variants: tiquet (el que llegeix l'alumne), causa, quan passa el check i codi del check.
     *
     * @return array<string, list<array{0: string, 1: string, 2: string, 3: string}>>
     */
    private static function variants(): array
    {
        return [
            'SRV' => [
                ['Qui ens escriu rep l\'error Relay access denied.', 'la zona falta a mydestination', 'mydestination inclou la zona', self::lxc(<<<'SH'
                    for d in $(postconf -xh mydestination | tr ',' ' '); do
                      [ "${d,,}" = "${Z,,}" ] && { msg "mydestination inclou $Z"; exit 0; }
                    done
                    fail "mydestination no inclou $Z"
                    SH)],
                ['El registre diu lliurat, però la bústia no mostra res nou.', 'lliurament en mbox', 'home_mailbox és Maildir/', self::lxc(<<<'SH'
                    v=$(postconf -h home_mailbox)
                    [ "$v" = "Maildir/" ] || fail "home_mailbox val '${v:-res}'"
                    msg "home_mailbox = Maildir/"
                    SH)],
                ['Els correus a direcció tornen al remitent.', 'propietari erroni de la Maildir', 'tota la Maildir és de direccio', self::lxc(<<<'SH'
                    h=$(getent passwd direccio | cut -d: -f6)
                    [ -n "$h" ] && [ -d "$h/Maildir" ] || fail "no trobo la Maildir de direccio"
                    bad=$(find "$h/Maildir" ! -user direccio -print -quit)
                    [ -z "$bad" ] || fail "$bad no és de direccio"
                    msg "tota la Maildir és de direccio"
                    SH)],
                ['Thunderbird no pot enviar.', 'sòcol d\'autenticació amb propietari erroni', 'el sòcol private/auth és de postfix', self::lxc(<<<'SH'
                    s=/var/spool/postfix/private/auth
                    [ -S "$s" ] || fail "no hi ha el sòcol $s (Dovecot en marxa?)"
                    o=$(stat -c %U "$s")
                    [ "$o" = postfix ] || fail "el sòcol $s és de $o"
                    msg "el sòcol private/auth és de postfix"
                    SH)],
                ['Els correus al centre no arriben.', 'transport del relay al port 25', 'el transport del relay apunta al 587', self::lxc(<<<'SH'
                    maps=$(postconf -h transport_maps | sed 's/,/ /g')
                    t=""
                    if [ -n "$maps" ]; then
                      for k in iesjulioantonio.cat .iesjulioantonio.cat .cat '*'; do
                        t=$(postmap -q "$k" $maps 2>/dev/null | head -1) && [ -n "$t" ] && break
                      done
                    fi
                    [ -n "$t" ] || t=$(postconf -h relayhost)
                    grep -qi smtp2go <<<"$t" && grep -qE ':587$' <<<"$t" || fail "el correu cap al centre va a '${t:-res}'"
                    msg "transport cap al centre: $t"
                    SH)],
                ['El webmail no em deixa entrar.', 'port IMAP erroni a Roundcube', 'imap_host de Roundcube acaba en :993', self::lxc(<<<'SH'
                    f=/etc/roundcube/config.inc.php
                    [ -r "$f" ] || fail "no trobo $f"
                    l=$(grep -E "^[[:space:]]*\\\$config\[.imap_host.\]" "$f" | tail -1)
                    grep -qE ":993['\"]" <<<"$l" || fail "imap_host: ${l:-no hi és}"
                    msg "imap_host acaba en :993"
                    SH)],
            ],
            'DNS' => [
                ['L\'altra empresa diu que els correus ens tornen.', 'MX cap a un nom que no existeix', 'l\'MX de la zona és mx.<zona>', self::ns1(<<<'SH'
                    r=$(dig +short +time=3 +tries=1 MX "$Z" @127.0.0.1)
                    [ -n "$r" ] || fail "$Z no té MX"
                    bad=$(grep -viE "^[0-9]+ mx\.$Z\.$" <<<"$r")
                    [ -z "$bad" ] || fail "MX: $bad"
                    msg "MX de $Z: $r"
                    SH)],
                ['Els correus del company es queden a la seva cua.', 'mx apunta a l\'adreça de la DMZ', 'mx resol a l\'adreça d\'aula del router', self::ns1(<<<'SH'
                    a=$(dig +short +time=3 +tries=1 A "mx.$Z" @127.0.0.1)
                    [ "$a" = "$IPROUTER" ] || fail "mx.$Z resol a '${a:-res}'"
                    msg "mx.$Z resol a $a"
                    SH)],
                ['Thunderbird diu que no troba el servidor o que no respon.', 'mail amb una adreça errònia', 'mail resol a 10.X.0.25', self::ns1(<<<'SH'
                    a=$(dig +short +time=3 +tries=1 A "mail.$Z" @127.0.0.1)
                    [ "$a" = "10.$X.0.25" ] || fail "mail.$Z resol a '${a:-res}'"
                    msg "mail.$Z resol a $a"
                    SH)],
                ['L\'adreça del webmail no existeix.', 'CNAME webmail esborrat', 'webmail resol a 10.X.0.25', self::ns1(<<<'SH'
                    a=$(dig +short +time=3 +tries=1 A "webmail.$Z" @127.0.0.1 | tail -1)
                    [ "$a" = "10.$X.0.25" ] || fail "webmail.$Z resol a '${a:-res}'"
                    msg "webmail.$Z resol a $a"
                    SH)],
                ['El nostre domini no respon.', 'error a la zona i servei reiniciat', 'la zona es carrega i respon el SOA', self::ns1(<<<'SH'
                    r=$(dig +short +norecurse +time=3 +tries=1 SOA "$Z" @127.0.0.1)
                    [ -n "$r" ] || fail "ns1 no respon el SOA de $Z (zona sense carregar?)"
                    msg "SOA: $r"
                    SH)],
                ['Des de la DMZ el domini va, però des de l\'aula no.', 'allow-query limitat a la DMZ', 'el DNS central obté resposta de ns1', self::BASE."\n".self::CENTRAL."\n"],
            ],
            'RTR' => [
                ['No ens arriba res dels companys.', 'DNAT del 25 desactivat', 'el primer DNAT actiu del 25 apunta a 10.X.0.25', self::routerCheck(self::DNAT_25)],
                ['Els companys no aconsegueixen connectar amb el nostre servidor.', 'DNAT del 25 cap al servidor web', 'el DNAT del 25 apunta a 10.X.0.25', self::routerCheck(self::DNAT_25)],
                ['Rebem correu, però els nostres no surten cap als companys.', 'regla F2 desactivada', 'la regla F2 està activa i abans del drop final', self::routerCheck(self::FORWARD.<<<'SH'

                    allowed "src-address=10\.$HE_X\.0\.25(/32)?" '' 25 \
                      || fail "cap accept actiu de 10.$HE_X.0.25 cap al 25 abans del drop final"
                    msg "10.$HE_X.0.25 pot sortir cap al 25"
                    SH)],
                ['A l\'oficina ningú no pot llegir ni enviar correu.', 'origen erroni a la regla F3', 'F3 té l\'origen de la VLAN 101', self::routerCheck(self::FORWARD.<<<'SH'

                    allowed "src-address=172\.$HE_X\.1\.0/24" "dst-address=10\.$HE_X\.0\.25(/32)?" 993 \
                      || fail "cap accept actiu de 172.$HE_X.1.0/24 cap a 10.$HE_X.0.25:993 abans del drop final"
                    msg "la VLAN 101 arriba al correu"
                    SH)],
                ['Només ns1 arriba al servidor de correu.', 'ruta estàtica cap al servidor amb una passarel·la que no existeix', 'no hi ha cap ruta pròpia cap a 10.X.0.25/32', self::routerCheck(<<<'SH'
                    n=$(count "/ip route print count-only where dst-address=10.$HE_X.0.25/32 disabled=no") || omit "no puc llegir les rutes"
                    [ "$n" = 0 ] || fail "hi ha $n ruta(es) cap a 10.$HE_X.0.25/32"
                    msg "cap ruta pròpia cap a 10.$HE_X.0.25/32"
                    SH)],
                ['Una auditoria diu que els convidats arriben al servidor de correu.', 'regla accept afegida per als convidats', 'no hi ha cap accept dels convidats cap al correu', self::routerCheck(<<<'SH'
                    n=$(count "/ip firewall filter print count-only where chain=forward action=accept src-address=172.$HE_X.2.0/24 dst-address=10.$HE_X.0.25 disabled=no") || omit "no puc llegir el tallafocs"
                    [ "$n" = 0 ] || fail "hi ha $n regla(es) accept de 172.$HE_X.2.0/24 cap a 10.$HE_X.0.25"
                    msg "cap accept dels convidats cap a 10.$HE_X.0.25"
                    SH)],
            ],
            'PVE' => [
                ['Ningú no pot entrar al correu, tampoc des de ns1.', 'VLAN 100 a la interfície de l\'LXC', 'net0 de l\'LXC té vmbr2 i tag=200', self::proxmox(self::NEED_CT."\n".<<<'SH'
                    net0=$(sudo -n pct config "$CT" | sed -n 's/^net0: //p')
                    [[ ",$net0," == *,tag=200,* && ",$net0," == *,bridge=vmbr2,* ]] || fail "net0: $net0"
                    msg "net0 a vmbr2 amb tag=200"
                    SH)],
                ['Des de ns1 el servidor respon, però des de fora no.', 'passarel·la errònia a l\'LXC', 'la passarel·la de l\'LXC és el router (10.X.0.1)', self::proxmox(self::NEED_CT."\n".<<<'SH'
                    net0=$(sudo -n pct config "$CT" | sed -n 's/^net0: //p')
                    [[ ",$net0," == *,gw=10.$X.0.1,* ]] || fail "net0: $net0"
                    msg "passarel·la 10.$X.0.1"
                    SH)],
                ['Els correus als companys es queden encallats.', 'DNS de l\'LXC canviat al Zentyal', 'l\'LXC resol els noms de les zones smix2', self::lxc(<<<'SH'
                    if resolvectl query --cache=no -t MX "$Z" >/dev/null 2>&1 || [ -n "$(dig +short +time=3 +tries=1 MX "$Z" 2>/dev/null | grep -v '^;')" ]; then
                      msg "l'LXC resol el MX de $Z"; exit 0
                    fi
                    # La zona pròpia pot estar avariada (categoria DNS): llavors es mira a qui pregunta l'LXC.
                    ns=" $(cat /run/systemd/resolve/resolv.conf /etc/resolv.conf 2>/dev/null | awk '$1 == "nameserver" && $2 != "127.0.0.53" { print $2 }' | sort -u | tr '\n' ' ')"
                    [[ "$ns " != *" $ZENTYAL "* && ( "$ns " == *" 10.$X.0.11 "* || "$ns " == *" $CENTRAL "* ) ]] \
                      || fail "l'LXC no resol el MX de $Z (pregunta a:$ns)"
                    msg "l'LXC pregunta a:$ns"
                    SH)],
                ['Després de reiniciar el Proxmox el correu no ha tornat.', 'LXC aturat i sense arrencada automàtica', 'l\'LXC està en marxa i té onboot 1', self::proxmox(self::NEED_CT."\n".<<<'SH'
                    running "$CT" || fail "l'LXC mail està aturat"
                    sudo -n pct config "$CT" | grep -qx 'onboot: 1' || fail "l'LXC mail no arrenca sol (onboot)"
                    msg "l'LXC mail en marxa i amb onboot 1"
                    SH)],
                ['Hi ha serveis del servidor de correu que cauen sols.', 'memòria de l\'LXC reduïda a 128 MB i sense swap', 'l\'LXC té 1024 MB de memòria o més', self::proxmox(self::NEED_CT."\n".<<<'SH'
                    m=$(sudo -n pct config "$CT" | sed -n 's/^memory: //p')
                    [ "${m:-0}" -ge 1024 ] || fail "l'LXC mail té $m MB de memòria"
                    msg "l'LXC mail té $m MB de memòria"
                    SH)],
                ['Tota la DMZ ha desaparegut: web, DNS i correu.', 'interfície DMZ del router desconnectada', 'la targeta net2 del router està connectada', self::proxmox(<<<'SH'
                    VR=$(find_router)
                    [ -n "$VR" ] || omit "no trobo cap VM MikroTik en marxa"
                    net2=$(sudo -n qm config "$VR" | sed -n 's/^net2: //p')
                    [ -n "$net2" ] || omit "la VM $VR no té net2"
                    [[ ",$net2," != *,link_down=1,* ]] || fail "net2 del router desconnectada"
                    msg "net2 del router connectada"
                    SH)],
            ],
            'SEG' => [
                ['Algú envia correu a través nostre.', 'mynetworks amb una adreça de l\'aula', 'mynetworks només té adreces del mateix servidor', self::lxc(<<<'SH'
                    for n in $(postconf -xh mynetworks | tr ',' ' '); do
                      case "$n" in 127.*|'[::1]/128'|'[::1]'|'::1/128'|'::1'|'[::ffff:127.'*|"10.$X.0.25"|"10.$X.0.25/32") ;; *) fail "mynetworks inclou $n" ;; esac
                    done
                    msg "mynetworks només local"
                    SH)],
                ['El company diu que ja no signem els correus.', 'Rspamd no pot llegir la clau DKIM', '_rspamd llegeix la clau DKIM i no és pública', self::lxc(self::DKIM_KEY."\n".<<<'SH'
                    [ -e "$key" ] || fail "no trobo la clau privada del selector mail ($key)"
                    runuser -u _rspamd -- test -r "$key" || fail "_rspamd no pot llegir $key"
                    p=$(stat -c %a "$key")
                    (( (8#$p & 4) == 0 )) || fail "$key la pot llegir tothom ($p)"
                    msg "_rspamd llegeix la clau i no és pública"
                    SH)],
                ['El company marca els nostres correus: falla SPF.', 'l\'SPF autoritza una altra IP', 'l\'SPF autoritza 10.X.0.25', self::ns1(self::SPF)],
                ['Ha entrat un correu que es feia passar per direcció.', 'el rebuig DMARC només marca', 'l\'acció DMARC és rebutjar', self::lxc(<<<'SH'
                    sed 's/#.*//' /etc/rspamd/local.d/dmarc.conf 2>/dev/null \
                      | grep -Eq '(^|[{;[:space:]])reject[[:space:]]*=[[:space:]]*"?reject"?[[:space:]]*(;|}|$)' \
                      || fail "dmarc.conf no rebutja (reject = \"reject\")"
                    msg "DMARC rebutja"
                    SH)],
                ['Una auditoria diu que es pot entrar sense xifrar.', 'IMAP 143 obert i contrasenya en clar', 'el 143 està tancat i no s\'accepta la contrasenya en clar', self::lxc(<<<'SH'
                    ss -Hlnt | awk '{ print $4 }' | grep -qE ':143$' && fail "el port 143 escolta"
                    v=$(doveconf -h auth_allow_cleartext)
                    [ "$v" = no ] || fail "auth_allow_cleartext = ${v:-res}"
                    msg "143 tancat i auth_allow_cleartext = no"
                    SH)],
                ['El client de correu avisa d\'un certificat estrany.', 'Dovecot amb un certificat d\'un altre nom', 'el 993 presenta un certificat de mail.<zona>', self::lxc(<<<'SH'
                    crt=$(echo | timeout 10 openssl s_client -connect 127.0.0.1:993 -servername "mail.$Z" 2>/dev/null | openssl x509 2>/dev/null)
                    [ -n "$crt" ] || fail "el 993 no presenta cap certificat"
                    openssl x509 -noout -checkhost "mail.$Z" <<<"$crt" | grep -q 'does match' || fail "el certificat del 993 no és de mail.$Z"
                    msg "el 993 presenta el certificat de mail.$Z"
                    SH)],
            ],
        ];
    }

    // ── Codi dels checks ─────────────────────────────────────────────────────────────────────

    /**
     * El DNS central ha d'obtenir resposta de ns1. El nom és nou cada vegada perquè no el tingui a la
     * memòria cau. Si el central no contesta a temps (ns1 inabastable), no es pot avaluar.
     */
    private const CENTRAL = <<<'SH'
        r=$(dig +time=8 +tries=1 A "prova-$RANDOM$RANDOM.$Z" @"$HE_IP_DNS_AULA" 2>/dev/null)
        st=$(sed -n 's/.*status: \([A-Z]*\),.*/\1/p' <<<"$r" | head -1)
        case "$st" in
          NOERROR|NXDOMAIN) msg "el DNS central obté resposta de ns1 per a $Z" ;;
          '') omit "el DNS central ($HE_IP_DNS_AULA) no ha contestat" ;;
          *) fail "el DNS central no obté resposta per a $Z ($st)" ;;
        esac
        SH;

    private const SPF = <<<'SH'
        spf=$(dig +short +time=3 +tries=1 TXT "$Z" @127.0.0.1 | sed 's/" "//g; s/"//g' | grep -i '^v=spf1')
        [ -n "$spf" ] || fail "$Z no té cap registre SPF"
        [ "$(wc -l <<<"$spf")" -eq 1 ] || fail "$Z té més d'un registre SPF"
        SPF="$spf" ZONE="$Z" TARGET="10.$X.0.25" python3 - <<'PY'
        import ipaddress, os, subprocess, sys

        spf, zone, target = os.environ['SPF'], os.environ['ZONE'], ipaddress.ip_address(os.environ['TARGET'])


        def dig(kind, name):
            r = subprocess.run(['dig', '+short', '+time=3', '+tries=1', kind, name, '@127.0.0.1'], capture_output=True, text=True)
            return r.stdout.split('\n')


        def addresses(name):
            out = []
            for line in dig('A', name):
                try:
                    out.append(ipaddress.ip_address(line.strip()))
                except ValueError:
                    pass
            return out


        def authorizes(mech):
            if mech.startswith('ip4:'):
                try:
                    return target in ipaddress.ip_network(mech[4:], strict=False)
                except ValueError:
                    return False
            head, _, arg = mech.partition(':')
            head, arg = head.partition('/')[0], arg.partition('/')[0]
            if head == 'a':
                return target in addresses(arg or zone)
            if head == 'mx':
                return any(target in addresses(l.split()[-1]) for l in dig('MX', arg or zone) if l.strip())
            return False


        if not any(authorizes(m.lstrip('+')) for m in spf.split()[1:] if m[0] not in '-~?'):
            print('HEIMDEYE_MSG: l\'SPF no autoritza %s: %s' % (target, spf))
            sys.exit(1)
        print('HEIMDEYE_MSG: SPF: %s' % spf)
        PY
        SH;

    private const DNAT_25 = <<<'SH'
        out=$(r '/ip firewall nat print terse without-paging where chain=dstnat disabled=no') || omit "no puc llegir el NAT"
        first=$(tr -d '\r' <<<"$out" | grep -E ' dst-port=([0-9,-]*,)?25(,| |$)' | head -1)
        [ -n "$first" ] || fail "no hi ha cap DNAT actiu del port 25"
        grep -Eq " to-addresses=10\.$HE_X\.0\.25( |$)" <<<"$first" || fail "el DNAT del 25 no apunta a 10.$HE_X.0.25"
        msg "el DNAT del 25 apunta a 10.$HE_X.0.25"
        SH;

    /** Les regles actives de forward fins al primer drop que ho descarta tot. */
    private const FORWARD = <<<'SH'
        out=$(r '/ip firewall filter print terse without-paging where chain=forward disabled=no') || omit "no puc llegir el tallafocs"
        out=$(tr -d '\r' <<<"$out")
        grep -q 'chain=forward' <<<"$out" || omit "no puc llegir el tallafocs"
        rules=$(awk '/action=drop/ && !/connection-state=|src-address|dst-address|protocol=|dst-port=|in-interface|out-interface/ { exit } { print }' <<<"$out")
        # allowed <origen> <destinació o buit> <port>: hi ha un accept per a això abans del drop?
        allowed() {
          local l
          while IFS= read -r l; do
            grep -q 'action=accept' <<<"$l" || continue
            grep -Eq " $1( |$)" <<<"$l" || continue
            [ -z "$2" ] || grep -Eq " $2( |$)" <<<"$l" || continue
            ! grep -q ' dst-port=' <<<"$l" || grep -Eq " dst-port=([0-9,-]*,)?$3(,| |$)" <<<"$l" || continue
            return 0
          done <<<"$rules"
          return 1
        }
        SH;

    /** La clau privada DKIM del selector «mail», on digui la configuració de Rspamd. */
    private const DKIM_KEY = <<<'SH'
        path=$(sed -n 's/^[[:space:]]*path[[:space:]]*=[[:space:]]*"\(.*\)";.*/\1/p' /etc/rspamd/local.d/dkim_signing.conf 2>/dev/null | head -1)
        [ -n "$path" ] || path='/var/lib/rspamd/dkim/$domain.$selector.key'
        key=$(sed "s/\$domain/$Z/g; s/\$selector/mail/g; s/\${domain}/$Z/g; s/\${selector}/mail/g" <<<"$path")
        SH;

    /** Check que s'executa al Proxmox. */
    private static function proxmox(string $body): string
    {
        return "# hosts: pve\n".self::BASE."\n".self::PVE."\n".$body."\n";
    }

    /** Check que s'executa dins de l'LXC mail. */
    private static function lxc(string $body): string
    {
        return self::proxmox(self::NEED_CT."\n".self::NEED_RUNNING."\nct <<'LXC'\n".$body."\nLXC");
    }

    /** Check que s'executa dins de ns1; si l'agent no respon, omès. */
    private static function ns1(string $body): string
    {
        return self::proxmox(self::NEED_NS1."\nguest <<'NS1'\n".$body."\nNS1\n".<<<'SH'
            rc=$?
            [ $rc -eq 125 ] && omit "l'agent de QEMU de ns1 no ha respost"
            exit $rc
            SH);
    }

    private static function routerCheck(string $body): string
    {
        return self::BASE."\n".self::ROUTER."\n".self::CHECK_ROUTER."\n".$body."\n";
    }

    // ── Accions ──────────────────────────────────────────────────────────────────────────────

    /** Codi de l'acció d'avaria ($inject) o de reparació d'una categoria; AVARIA = variant. */
    private static function actionCode(string $category, bool $inject): string
    {
        if ($category === 'RTR') {
            return self::routerAction($inject);
        }

        $cases = self::cases($category, $inject);
        $body = match ($category) {
            'PVE' => "CT=\$(find_ct)\n[ -n \"\$CT\" ] || fail \"no trobo l'LXC mail\"\n".$cases,
            'DNS' => self::inNs1($cases),
            'SRV' => self::inLxc($cases),
            // SEG-3 va a ns1; la resta, a l'LXC mail.
            'SEG' => "if [ \"\$N\" = 3 ]; then\n".self::inNs1(self::spfCase($inject))."\nelse\n".self::inLxc(self::DKIM_KEY."\n".$cases)."\nfi",
        };
        $done = $inject ? 'injectada' : 'desfeta';

        return "# hosts: pve\n".self::BASE."\n".<<<'SH'
            N="${HE_PARAM_AVARIA:-0}"
            case "$N" in 1|2|3|4|5|6) ;; *) fail "AVARIA ha de ser un número de l'1 al 6" ;; esac
            SH."\n".self::PVE."\n".$body."\n".<<<SH
            rc=\$?
            [ \$rc -ne 125 ] || fail "l'agent de QEMU de ns1 no ha respost"
            [ \$rc -eq 0 ] || exit \$rc
            msg "{$category}-\$N {$done}"
            SH."\n";
    }

    private static function inLxc(string $body): string
    {
        return "CT=\$(find_ct)\n[ -n \"\$CT\" ] || fail \"no trobo l'LXC mail\"\nrunning \"\$CT\" || fail \"l'LXC mail està aturat\"\nct <<'LXC'\n".self::LXC_FUNCS."\n".$body."\nLXC";
    }

    private static function inNs1(string $body): string
    {
        return "NS1=\$(find_ns1) || fail \"no trobo la VM de ns1 amb l'agent de QEMU en marxa\"\nguest <<'NS1'\n".self::NS1_FUNCS."\n".$body."\nNS1";
    }

    /** Dins de l'LXC mail: còpies de l'original i canvis que han de canviar alguna cosa. */
    private const LXC_FUNCS = <<<'SH'
        D=/root/.heimdeye-b12
        mkdir -p "$D" && chmod 700 "$D"
        saved() { [ -e "$D/$1" ] || fail "no tinc guardat l'original ($D/$1): no s'ha injectat o ja està desfeta"; }
        # edit <nom de la còpia> <fitxer> <expressió de sed>: guarda l'original i exigeix un canvi.
        edit() {
          [ -f "$2" ] || fail "no existeix $2"
          cp -p "$2" "$D/$1.nou" && sed -i -E "$3" "$2" || fail "no puc canviar $2"
          if cmp -s "$2" "$D/$1.nou"; then rm -f "$D/$1.nou"; fail "$2 no té el que cal canviar (entorn diferent o ja injectada)"; fi
          mv "$D/$1.nou" "$D/$1"
        }
        # undo <nom de la còpia> <fitxer>
        undo() { saved "$1"; cp -p "$D/$1" "$2" && rm -f "$D/$1"; }
        restart() { systemctl restart "$1" || fail "no puc reiniciar $1 (systemctl status $1)"; }
        reload() { systemctl reload "$1" || fail "no puc recarregar $1"; }
        SH;

    private const NS1_FUNCS = <<<'SH'
        D=/root/.heimdeye-b12
        mkdir -p "$D" && chmod 700 "$D"
        F="/etc/bind/zones/db.$Z"
        C=/etc/bind/named.conf.local
        saved() { [ -e "$D/$1" ] || fail "no tinc guardat l'original ($D/$1): no s'ha injectat o ja està desfeta"; }
        edit() {
          [ -f "$2" ] || fail "no existeix $2"
          cp -p "$2" "$D/$1.nou" && sed -i -E "$3" "$2" || fail "no puc canviar $2"
          if cmp -s "$2" "$D/$1.nou"; then rm -f "$D/$1.nou"; fail "$2 no té el que cal canviar (entorn diferent o ja injectada)"; fi
          mv "$D/$1.nou" "$D/$1"
        }
        # Restaurar amb cp -p no canvia la data: sense touch, named diria «up-to-date».
        undo() { saved "$1"; cp -p "$D/$1" "$2" && touch "$2" && rm -f "$D/$1"; }
        apply() { named-checkzone "$Z" "$F" >/dev/null || fail "la zona no passa named-checkzone"; rndc reload "$Z" >/dev/null || fail "rndc reload ha fallat"; }
        SH;

    private static function spfCase(bool $inject): string
    {
        return $inject
            ? 'edit seg3.zona "$F" "s/\"v=spf1[^\"]*\"/\"v=spf1 ip4:10.$X.0.21 -all\"/"'."\n".'apply'
            : 'undo seg3.zona "$F"'."\n".'apply';
    }

    /** El «case» de la variant, que s'executa on toqui (Proxmox, LXC o ns1). */
    private static function cases(string $category, bool $inject): string
    {
        $all = [
            'PVE' => [<<<'SH'
                D=/root/.heimdeye-b12
                P() { sudo -n pct "$@"; }
                cfg() { P config "$CT" | sed -n "s/^$1: //p"; }
                keep() { printf '%s' "$2" | sudo -n tee "$D/$1" >/dev/null; }
                reboot_ct() { ! running "$CT" || P reboot "$CT"; }
                sudo -n mkdir -p "$D"
                # net0 <expressió de sed>
                net0() {
                  local o n
                  running "$CT" || fail "l'LXC mail està aturat"
                  o=$(cfg net0); n=$(sed -E "$1" <<<"$o")
                  [ "$n" != "$o" ] || fail "net0 no té el que cal canviar: $o"
                  keep pve.net0 "$o"
                  P set "$CT" --net0 "$n" && reboot_ct
                }
                case "$N" in
                  1) net0 's#tag=200#tag=100#' ;;
                  2) net0 "s#gw=[0-9.]+#gw=10.$X.0.254#" ;;
                  3) running "$CT" || fail "l'LXC mail està aturat"
                     o=$(cfg nameserver); [ "$o" != "$HE_IP_ZENTYAL" ] || fail "el DNS de l'LXC ja és el Zentyal"
                     keep pve.nameserver "$o"
                     P set "$CT" --nameserver "$HE_IP_ZENTYAL" && reboot_ct ;;
                  4) P set "$CT" --onboot 0 && { ! running "$CT" || P stop "$CT"; } ;;
                  5) running "$CT" || fail "l'LXC mail està aturat"
                     m=$(cfg memory); s=$(cfg swap); [ "$m" != 128 ] || fail "l'LXC ja té 128 MB"
                     keep pve.memoria "$m ${s:-512}"
                     P set "$CT" --memory 128 --swap 0 && reboot_ct ;;
                  6) VR=$(find_router); [ -n "$VR" ] || fail "no trobo cap VM MikroTik en marxa"
                     o=$(sudo -n qm config "$VR" | sed -n 's/^net2: //p')
                     [ -n "$o" ] && [[ ",$o," != *,link_down=1,* ]] || fail "net2 no hi és o ja està desconnectada: $o"
                     keep pve.net2 "$o"
                     sudo -n qm set "$VR" --net2 "$(sed 's/,link_down=0//' <<<"$o"),link_down=1" ;;
                esac
                SH, <<<'SH'
                D=/root/.heimdeye-b12
                P() { sudo -n pct "$@"; }
                saved() { sudo -n test -e "$D/$1" || fail "no tinc guardat l'original ($D/$1): no s'ha injectat o ja està desfeta"; }
                old() { sudo -n cat "$D/$1"; }
                done_() { sudo -n rm -f "$D/$1"; }
                reboot_ct() { ! running "$CT" || P reboot "$CT"; }
                case "$N" in
                  1|2) saved pve.net0; o=$(old pve.net0)
                       P set "$CT" --net0 "$o" && reboot_ct && done_ pve.net0 ;;
                  3) saved pve.nameserver; o=$(old pve.nameserver)
                     if [ -n "$o" ]; then P set "$CT" --nameserver "$o"; else P set "$CT" --delete nameserver; fi \
                       && reboot_ct && done_ pve.nameserver ;;
                  4) P set "$CT" --onboot 1 && { running "$CT" || P start "$CT"; } ;;
                  5) saved pve.memoria; o=$(old pve.memoria)
                     read -r m s <<<"$o"
                     P set "$CT" --memory "$m" --swap "$s" && reboot_ct && done_ pve.memoria ;;
                  6) saved pve.net2; o=$(old pve.net2)
                     VR=$(find_router); [ -n "$VR" ] || fail "no trobo cap VM MikroTik en marxa"
                     sudo -n qm set "$VR" --net2 "$o" && done_ pve.net2 ;;
                esac
                SH],
            'DNS' => [<<<'SH'
                A='([[:space:]]+[0-9]+)?([[:space:]]+IN)?[[:space:]]+A[[:space:]]+)[0-9.]+'
                case "$N" in
                  1) edit dns.zona "$F" 's/([[:space:]]MX[[:space:]]+[0-9]+[[:space:]]+)[^[:space:];]+/\1mx-antic/'; apply ;;
                  2) edit dns.zona "$F" "s/^(mx$A/\110.$X.0.25/"; apply ;;
                  3) edit dns.zona "$F" "s/^(mail$A/\110.$X.0.52/"; apply ;;
                  4) edit dns.zona "$F" '/^webmail[[:space:]]/d'; apply ;;
                  5) ! grep -q "10\.$X\.0\.300" "$F" || fail "ja està injectada"
                     cp -p "$F" "$D/dns.zona" || fail "no puc copiar la zona"
                     [ -z "$(tail -c1 "$F")" ] || echo >> "$F"
                     echo "intranet IN A 10.$X.0.300" >> "$F"
                     systemctl restart named || fail "no puc reiniciar named" ;;
                  6) ! grep -qF "allow-query { 10.$X.0.0/22; localhost; };" "$C" || fail "ja està injectada"
                     edit dns.conf "$C" "/zone \"$Z\"/a allow-query { 10.$X.0.0/22; localhost; };"
                     named-checkconf >/dev/null || { cp -p "$D/dns.conf" "$C"; fail "la configuració no passa named-checkconf: no s'ha canviat"; }
                     rndc reconfig >/dev/null || fail "rndc reconfig ha fallat" ;;
                esac
                SH, <<<'SH'
                case "$N" in
                  1|2|3|4) undo dns.zona "$F"; apply ;;
                  5) undo dns.zona "$F"; systemctl restart named || fail "no puc reiniciar named" ;;
                  6) undo dns.conf "$C"; rndc reconfig >/dev/null || fail "rndc reconfig ha fallat" ;;
                esac
                SH],
            'SRV' => [<<<'SH'
                T=/etc/postfix/transport
                L=/etc/dovecot/local.conf
                W=/etc/roundcube/config.inc.php
                case "$N" in
                  1) postconf -xh mydestination | tr ', ' '\n\n' | grep -qixF "$Z" || fail "mydestination ja no inclou $Z"
                     postconf -h mydestination > "$D/srv1.mydestination"
                     postconf -e 'mydestination = $myhostname, localhost'; reload postfix ;;
                  2) v=$(postconf -h home_mailbox); [ -n "$v" ] || fail "home_mailbox ja és buit"
                     echo "$v" > "$D/srv2.home_mailbox"
                     postconf -e 'home_mailbox ='; reload postfix ;;
                  3) [ -d /home/direccio/Maildir ] || fail "no existeix /home/direccio/Maildir"
                     chown -R root:root /home/direccio/Maildir ;;
                  4) edit srv4.local.conf "$L" 's/user = postfix/user = dovecot/; s/group = postfix/group = dovecot/'; restart dovecot ;;
                  5) edit srv5.transport "$T" 's/\]:587/]:25/'; postmap "$T"; reload postfix ;;
                  6) edit srv6.roundcube "$W" "/imap_host/ s/:993'/:994'/" ;;
                esac
                SH, <<<'SH'
                T=/etc/postfix/transport
                case "$N" in
                  1) saved srv1.mydestination; postconf -e "mydestination = $(cat "$D/srv1.mydestination")"; reload postfix; rm -f "$D/srv1.mydestination" ;;
                  2) saved srv2.home_mailbox; postconf -e "home_mailbox = $(cat "$D/srv2.home_mailbox")"; reload postfix; rm -f "$D/srv2.home_mailbox" ;;
                  3) chown -R direccio: /home/direccio/Maildir ;;
                  4) undo srv4.local.conf /etc/dovecot/local.conf; restart dovecot ;;
                  5) undo srv5.transport "$T"; postmap "$T"; reload postfix ;;
                  6) undo srv6.roundcube /etc/roundcube/config.inc.php ;;
                esac
                SH],
            'SEG' => [<<<'SH'
                L=/etc/dovecot/local.conf
                case "$N" in
                  1) v=$(postconf -h mynetworks); [[ "$v" != *"$HEIP"* ]] || fail "mynetworks ja inclou $HEIP"
                     echo "$v" > "$D/seg1.mynetworks"
                     postconf -e "mynetworks = 127.0.0.0/8 [::1]/128 $HEIP/32"; reload postfix ;;
                  2) [ -f "$key" ] || fail "no trobo la clau DKIM ($key)"
                     o=$(stat -c '%U:%G %a' "$key"); [ "$o" != "root:root 600" ] || fail "ja està injectada"
                     echo "$o" > "$D/seg2.clau"
                     chown root:root "$key" && chmod 600 "$key" || fail "no puc canviar $key"; restart rspamd ;;
                  4) edit seg4.dmarc /etc/rspamd/local.d/dmarc.conf 's/reject = "reject";/reject = "add_header";/'; restart rspamd ;;
                  5) edit seg5.local.conf "$L" 's/^auth_allow_cleartext = no/auth_allow_cleartext = yes/; s/^ssl = required/ssl = yes/; /inet_listener imap \{/,/\}/ s/port = 0/port = 143/'; restart dovecot ;;
                  6) grep -q '^ssl_server_cert_file = ' "$L" || fail "$L no té ssl_server_cert_file"
                     openssl req -x509 -newkey rsa:2048 -nodes -days 30 -keyout /etc/ssl/private/antic.key \
                       -out /etc/ssl/certs/antic.pem -subj "/CN=correu-antic.test" 2>/dev/null || fail "no puc crear el certificat"
                     edit seg6.local.conf "$L" 's#^ssl_server_cert_file = .*#ssl_server_cert_file = /etc/ssl/certs/antic.pem#; s#^ssl_server_key_file = .*#ssl_server_key_file = /etc/ssl/private/antic.key#'
                     restart dovecot ;;
                esac
                SH, <<<'SH'
                L=/etc/dovecot/local.conf
                case "$N" in
                  1) saved seg1.mynetworks; postconf -e "mynetworks = $(cat "$D/seg1.mynetworks")"; reload postfix; rm -f "$D/seg1.mynetworks" ;;
                  2) saved seg2.clau
                     read -r owner mode < "$D/seg2.clau"
                     chown "$owner" "$key" && chmod "$mode" "$key" || fail "no puc canviar $key"
                     rm -f "$D/seg2.clau"; restart rspamd ;;
                  4) undo seg4.dmarc /etc/rspamd/local.d/dmarc.conf; restart rspamd ;;
                  5) undo seg5.local.conf "$L"; restart dovecot ;;
                  6) undo seg6.local.conf "$L"; rm -f /etc/ssl/private/antic.key /etc/ssl/certs/antic.pem; restart dovecot ;;
                esac
                SH],
        ];

        return $all[$category][$inject ? 0 : 1];
    }

    /** Acció del MikroTik: comprova que hi ha el que ha de canviar i que el canvi hi és. */
    private static function routerAction(bool $inject): string
    {
        $cases = $inject ? <<<'SH'
            nat='comment="DNAT SMTP"'
            case "$N" in
              1) need "/ip firewall nat print count-only where $nat disabled=no"
                 r "/ip firewall nat disable [find where $nat]"
                 none "/ip firewall nat print count-only where $nat disabled=no"; flush ;;
              2) need "/ip firewall nat print count-only where $nat to-addresses=10.$X.0.25"
                 r "/ip firewall nat set [find where $nat] to-addresses=10.$X.0.21"
                 need "/ip firewall nat print count-only where $nat to-addresses=10.$X.0.21"; flush ;;
              3) need '/ip firewall filter print count-only where comment~"^F2 correu" disabled=no'
                 r '/ip firewall filter disable [find where comment~"^F2 correu"]'
                 none '/ip firewall filter print count-only where comment~"^F2 correu" disabled=no' ;;
              4) f3='comment~"^F3 usuaris -> correu"'
                 need "/ip firewall filter print count-only where $f3 src-address=172.$X.1.0/24"
                 r "/ip firewall filter set [find where $f3] src-address=172.$X.10.0/24"
                 need "/ip firewall filter print count-only where $f3 src-address=172.$X.10.0/24" ;;
              5) none "/ip route print count-only where dst-address=10.$X.0.25/32"
                 r "/ip route add dst-address=10.$X.0.25/32 gateway=10.$X.0.99 comment=manteniment"
                 need "/ip route print count-only where dst-address=10.$X.0.25/32" ;;
              6) w='comment="manteniment convidats"'
                 none "/ip firewall filter print count-only where $w"
                 need '/ip firewall filter print count-only where comment~"^F1 aula -> correu"'
                 r "/ip firewall filter add chain=forward action=accept connection-state=new src-address=172.$X.2.0/24 dst-address=10.$X.0.25 $w place-before=[find where comment~\"^F1 aula -> correu\"]"
                 need "/ip firewall filter print count-only where $w" ;;
            esac
            SH : <<<'SH'
            nat='comment="DNAT SMTP"'
            case "$N" in
              1) r "/ip firewall nat enable [find where $nat]"
                 need "/ip firewall nat print count-only where $nat disabled=no"; flush ;;
              2) r "/ip firewall nat set [find where $nat] to-addresses=10.$X.0.25"
                 need "/ip firewall nat print count-only where $nat to-addresses=10.$X.0.25"; flush ;;
              3) r '/ip firewall filter enable [find where comment~"^F2 correu"]'
                 need '/ip firewall filter print count-only where comment~"^F2 correu" disabled=no' ;;
              4) r "/ip firewall filter set [find where comment~\"^F3 usuaris -> correu\"] src-address=172.$X.1.0/24"
                 need "/ip firewall filter print count-only where comment~\"^F3 usuaris -> correu\" src-address=172.$X.1.0/24" ;;
              5) r '/ip route remove [find where comment=manteniment]'
                 none "/ip route print count-only where dst-address=10.$X.0.25/32 comment=manteniment" ;;
              6) r '/ip firewall filter remove [find where comment="manteniment convidats"]'
                 none '/ip firewall filter print count-only where comment="manteniment convidats"' ;;
            esac
            SH;
        $done = $inject ? 'injectada' : 'desfeta';

        return self::BASE."\n".<<<'SH'
            N="${HE_PARAM_AVARIA:-0}"
            case "$N" in 1|2|3|4|5|6) ;; *) fail "AVARIA ha de ser un número de l'1 al 6" ;; esac
            SH."\n".self::ROUTER."\n".<<<'SH'
            [ $rc -eq 255 ] && fail "HeimdEye no pot entrar al router ($HE_IP_ROUTER)"
            [ "$(echo "$so" | tr -d '\r')" = routeros ] || fail "aquestes avaries són només per al MikroTik"
            # need: n'hi ha d'haver algun; none: no n'hi ha d'haver cap.
            need() { local n; n=$(count "$1") || fail "el router no respon bé a: $1"; [ "$n" -gt 0 ] || fail "el router no té el que s'esperava: $1"; }
            none() { local n; n=$(count "$1") || fail "el router no respon bé a: $1"; [ "$n" -eq 0 ] || fail "el router ja té (o encara té): $1"; }
            # Una connexió oberta al 25 seguiria passant amb el NAT d'abans.
            flush() { r '/ip firewall connection remove [find where dst-address~":25\$"]' >/dev/null 2>&1 || true; }
            SH."\n".$cases."\n".<<<SH
            msg "RTR-\$N {$done}"
            SH."\n";
    }

    private function action(Module $module, Block $block, Task $task, string $name, string $description, Executor $executor, string $code, bool $incident, int $position): Action
    {
        $action = $module->actions()->create([
            'nom' => $name, 'bloc_id' => $block->id, 'tasca_id' => $task->id, 'descripcio' => $description,
            'executor' => $executor, 'es_incidencia' => $incident, 'posicio' => $position,
        ]);
        $action->versions()->create([
            'versio' => 1, 'temps_max_seg' => 180, 'codi' => $code,
            'parametres' => [['name' => 'AVARIA', 'label' => 'Variant (1 a 6)', 'default' => '0']],
        ]);

        return $action;
    }
}
