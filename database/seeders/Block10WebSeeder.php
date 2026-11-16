<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Enums\Level;
use App\Models\Block;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Blocs 10 i 11 (units: «Servidors web», 16/11-27/11/2026, 22 h): un sol bloc, el 10, amb una
 * tasca per cada pas del repte que es pot comprovar, en l'ordre de l'enunciat. Els checks es
 * numeren <bloc>.<pas>.<n> (p. ex. «10.7.3 ...») perquè l'alumne i el professor es vegin la
 * progressió d'una ullada.
 *
 * Setmana 1 (passos 1-10): LXC web, noms DNS, Apache amb tres llocs, DNAT, servidor discret,
 * intranet amb ACL i autenticació, catàleg sincronitzat amb lftp, HTTPS autosignat.
 * Setmana 2 (passos 11-17 i 19): Let's Encrypt (DNS-01), portal, publicació pel túnel, EC2 a AWS,
 * desplegament per SFTP, HTTP-01 i fluxos. Els passos 9, 18, 20 i 21 (captura, informe, taula
 * comparativa i sessió d'incidències) es valoren al document i no tenen checks.
 *
 * On corre cada check:
 *  - «ssh» a l'LXC web (grup vm_web, 10.X.0.21): config d'Apache, permisos, servei, MariaDB; les
 *    peticions es fan amb curl a 127.0.0.1 i la capçalera Host, sense dependre del DNAT.
 *  - «local» (HeimdEye, 192.168.20.12): el que es veu des de l'aula (DNS, DNAT, el web per l'adreça
 *    del router) i des d'Internet (nom públic, AWS).
 *  - «agent» amb rol «aws»: l'EC2 de l'alumne (instància registrada amb el hostname «aws»).
 *
 * Els punts es mantenen un cop assolits: els checks de la setmana 1 que deixen de passar quan es
 * passa al portal (pàgines estàtiques, /cataleg/) ho fan «ara no passa» però no perden punts.
 *
 * Crea, si falten, el tipus d'equip «web» (grup vm_web, adreça {{ip_web}}) i la variable de mòdul
 * ip_web = 10.{{x}}.0.21. ESBORRA les tasques i els checks que hi hagi del bloc 10 (amb els
 * resultats dels alumnes) abans de crear-los: s'executa una vegada, abans de començar el bloc.
 *
 * Mòdul 9991, bloc 10. S'executa amb: php artisan db:seed --class=Block10WebSeeder --force
 */
class Block10WebSeeder extends Seeder
{
    /** Noms, funcions i variables comuns a tots els checks. */
    private const BASE = <<<'SH'
        Z="$HE_NOM_DNS.$HE_NOM_DOMINI"           # zona de l'alumne
        PUB="$HE_NOM_DNS.${HE_NOM_DOMINI#*.}"    # nom públic: <usuari>.julioantonio.cat
        AWS="$HE_NOM_DNS-aws.${HE_NOM_DOMINI#*.}" # nom del servidor a AWS
        msg() { echo "HEIMDEYE_MSG: $*"; }
        fail() { msg "$@"; exit 1; }
        SH;

    /** Peticions a l'LXC web des de dins, amb la capçalera Host (no depenen del DNAT ni del DNS). */
    private const WEB = <<<'SH'
        # web <nom> <ruta> [opcions de curl]: GET pel nom (segueix HTTP -> HTTPS, no valida el certificat)
        web() { local h=$1 p=$2; shift 2; curl -sk -L -m 10 --resolve "$h:80:127.0.0.1" --resolve "$h:443:127.0.0.1" "$@" "http://$h$p"; }
        code() { web "$1" "$2" -o /dev/null -w '%{http_code}'; }
        hdr() { curl -sI -m 8 -H "Host: $1" "http://127.0.0.1$2"; }
        # primer document segur del catàleg
        primer() { ls -1A /srv/cataleg 2>/dev/null | grep -E '^[A-Za-z0-9._-]+$' | head -1; }
        SH;

    /**
     * Configuració efectiva d'Apache en un sol text (sense comentaris): els fitxers que carrega, en ordre.
     * (apache2ctl -t -D DUMP_CONFIG no existeix a Ubuntu 24.04.) $SUDO és «sudo -n» a l'LXC i buit a l'EC2.
     */
    private const APCFG = <<<'SH'
        apcfg() { $SUDO apache2ctl -t -D DUMP_INCLUDES 2>/dev/null | awk '{print $NF}' | grep '^/' | while read -r f; do $SUDO cat "$f"; done | grep -vE '^[[:space:]]*#'; }
        SH;

    /** Noms de vhost -> DocumentRoot, llegit dels fitxers actius d'Apache. */
    private const DOCROOT = <<<'SH'
        docroots() {
            sudo -n awk -v n="$1" '
                /<VirtualHost/ { blk = ""; inb = 1 }
                inb { blk = blk "\n" $0 }
                /<\/VirtualHost>/ {
                    if (blk ~ ("ServerName[ \t]+" n "([ \t\n]|$)")) {
                        while (match(blk, /DocumentRoot[ \t]+[^ \t\n]+/)) {
                            s = substr(blk, RSTART, RLENGTH); sub(/DocumentRoot[ \t]+/, "", s); gsub(/"/, "", s); sub(/\/$/, "", s)
                            print s; blk = substr(blk, RSTART + RLENGTH)
                        }
                    }
                    inb = 0
                }' /etc/apache2/sites-enabled/*.conf
        }
        SH;

    public function run(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();

        // Equip nou del bloc: l'LXC web (10.X.0.21), accessible com els altres Linux (usuari jaume).
        $module->variables()->updateOrCreate(['nivell' => Level::Module, 'clau' => 'ip_web'], ['valor' => '10.{{x}}.0.21']);
        $module->hostTypes()->updateOrCreate(['nom' => 'web'], [
            'grup_ansible' => 'vm_web', 'es_unic' => false, 'nom_host' => 'web{{num_taula}}', 'adreca' => '{{ip_web}}',
            'posicio' => ($module->hostTypes()->max('posicio') ?? 0) + 1,
        ]);

        $block = Block::updateOrCreate(
            ['modul_id' => $module->id, 'numero' => 10],
            ['titol' => 'Servidors web', 'data_inici' => '2026-11-16', 'data_fi' => '2026-11-27', 'posicio' => 10],
        );

        // Es comença de zero: tasques, checks (i els seus resultats) del bloc.
        foreach ($block->tasks()->get() as $task) {
            $task->checks()->delete();
        }
        $block->tasks()->delete();

        foreach ($this->tasks() as $position => [$title, $week, $statement, $checks]) {
            $this->task($block, $position + 1, $title, $week, $statement, $checks);
        }
    }

    /**
     * @return list<array{0: string, 1: int, 2: string, 3: list<array<string, mixed>>}> títol, setmana, enunciat, checks
     */
    private function tasks(): array
    {
        return [
            // ───────────────────────── SETMANA 1 ─────────────────────────
            ['Pas 1 · Punt de partida', 1, <<<'MD'
                Abans de res, comprova que la infraestructura dels blocs anteriors funciona: la zona
                `<usuari>.smix2.julioantonio.cat` ha de respondre des de l'aula, l'hora ha d'estar
                sincronitzada i l'última regla de forward ha de ser el DROP. Sense això, els
                certificats i les proves de fluxos no valen.
                MD, [
                $this->c('10.1.1', 'La teva zona respon des de l\'aula',
                    'El DNS de l\'aula (192.168.20.11) retorna els NS de la teva zona. Comprova-ho: `dig NS <usuari>.smix2.julioantonio.cat @192.168.20.11`.',
                    'comprovar que la delegació de la teva zona funciona des de l\'aula.',
                    'tots els certificats i els noms d\'aquest bloc depenen d\'una zona que es resol. Si la zona no respon, qualsevol error posterior s\'hi pot confondre.',
                    self::loc(<<<'SH'
                        ns=$(dig +short +time=3 +tries=1 NS "$Z" @"$HE_IP_DNS_AULA" 2>/dev/null)
                        [ -n "$ns" ] || fail "el DNS de l'aula ($HE_IP_DNS_AULA) no contesta els NS de $Z"
                        msg "NS de $Z: $(echo $ns | tr '\n' ' ')"
                        SH),
                ),
            ]],

            ['Pas 2 · L\'LXC del servidor web', 1, <<<'MD'
                Crea l'LXC web a la DMZ (VLAN 200, `10.X.0.21`, Ubuntu, 2 nuclis, 1 GB de RAM i 8 GB de disc).
                Prepara'l com els servidors dels blocs anteriors: usuari `jaume` amb la clau pública de
                HeimdEye i `sudo` sense contrasenya, i una regla pròpia al tallafocs perquè HeimdEye
                (192.168.20.12) hi pugui entrar. Registra'l a phpIPAM.
                MD, [
                $this->c('10.2.1', 'HeimdEye entra a l\'LXC web',
                    'HeimdEye entra a l\'LXC web (10.X.0.21) per SSH com a jaume i hi té sudo sense contrasenya.',
                    'validar que HeimdEye pot administrar l\'LXC web.',
                    'tots els checks següents d\'aquest bloc miren dins de l\'LXC. Si l\'accés falla (clau, sudo o regla de tallafocs), no se\'n pot comprovar res més.',
                    self::ssh(<<<'SH'
                        sudo -n true || fail "jaume no té sudo sense contrasenya"
                        msg "SSH i sudo correctes a $(hostname)"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.2.2', 'L\'LXC resol noms i surt a Internet',
                    'L\'LXC resol noms (ns1 accepta consultes recursives de la DMZ) i arriba a Internet pel port 80. Comprova-ho: `apt update`.',
                    'comprovar que el servidor pot resoldre noms i actualitzar paquets.',
                    'un servidor que no s\'actualitza és un servidor vulnerable. El flux F5 (sortida cap a Internet) també és el que fa servir Let\'s Encrypt més endavant.',
                    self::ssh(<<<'SH'
                        getent hosts archive.ubuntu.com >/dev/null || fail "l'LXC no resol noms (ns1 accepta consultes recursives de la DMZ?)"
                        curl -sI -m 8 -o /dev/null http://archive.ubuntu.com/ubuntu/ || fail "l'LXC no arriba a Internet pel port 80 (flux F5)"
                        msg "resol noms i surt a Internet"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.2.3', 'Característiques de l\'LXC',
                    'L\'LXC és un Ubuntu amb 2 nuclis, 1 GB de RAM i 8 GB de disc. Comprova-ho: `nproc`, `free -m`, `df -h /`.',
                    'verificar que l\'LXC té els recursos de la taula d\'equips.',
                    'dimensionar bé un servidor (ni de menys ni de més) és part de la feina de sistemes; si va just de RAM, MariaDB i PHP-FPM es mataran entre ells.',
                    self::ssh(<<<'SH'
                        . /etc/os-release; [ "$ID" = ubuntu ] || fail "no és Ubuntu ($ID)"
                        n=$(nproc); [ "$n" -ge 2 ] || fail "té $n nucli (n'hi ha de ser 2)"
                        mem=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo); [ "$mem" -ge 900 ] || fail "té $mem MB de RAM (n'hi ha de ser 1024)"
                        disk=$(df -BG --output=size / | tail -1 | tr -dc 0-9); [ "$disk" -ge 7 ] || fail "té $disk GB de disc (n'hi ha de ser 8)"
                        msg "Ubuntu, $n nuclis, $mem MB, $disk GB"
                        SH), ['executor' => Executor::Ssh],
                ),
            ]],

            ['Pas 3 · Els noms DNS del web', 1, <<<'MD'
                Afegeix a la teva zona els noms `www` (adreça d'aula del router), `intranet` i `web`
                (`10.X.0.21`), puja el número de sèrie i comprova la zona. Els tres noms s'han de
                resoldre des de l'aula a través del DNS màster.
                MD, [
                $this->dns('10.3.1', 'www', 'HE_IP_ROUTER', 'l\'adreça d\'aula del teu router (DNAT)'),
                $this->dns('10.3.2', 'intranet', 'HE_IP_WEB', 'l\'LXC web (10.X.0.21)'),
                $this->dns('10.3.3', 'web', 'HE_IP_WEB', 'l\'LXC web (10.X.0.21)'),
                $this->c('10.3.4', 'ns1 serveix els tres noms',
                    'El teu ns1 (10.X.0.11) respon amb autoritat pels tres noms. Comprova-ho: `dig www.<zona> @10.X.0.11`.',
                    'validar que els noms són a la zona del teu servidor autoritatiu i no només a la memòria cau del màster.',
                    'el màster de l\'aula només pot respondre el que ns1 té a la zona; si falta un registre a la zona, el nom «funciona» fins que la memòria cau caduca i després desapareix.',
                    self::loc(<<<'SH'
                        for n in www intranet web; do
                          out=$(dig +time=3 +tries=1 A "$n.$Z" @"$HE_IP_DNS" 2>/dev/null)
                          grep -q 'flags:.* aa' <<<"$out" || fail "ns1 ($HE_IP_DNS) no respon amb autoritat per $n.$Z"
                          grep -q "^$n\.$Z\.\s.*IN\s*A\s" <<<"$out" || fail "ns1 no té el registre A de $n.$Z"
                        done
                        msg "ns1 respon amb autoritat pels tres noms"
                        SH), ['timeout' => 30],
                ),
            ]],

            ['Pas 4 · Apache i tres llocs virtuals', 1, <<<'MD'
                Instal·la Apache i crea tres llocs virtuals: `www`, `intranet` i un lloc per defecte per a
                `web`, amb el contingut estàtic de la setmana 1 (una pàgina diferent a cada un).
                Demostra, amb `curl` i la capçalera `Host`, com tria Apache el lloc i què passa amb un
                nom que no és de cap lloc virtual.
                MD, [
                $this->c('10.4.1', 'Apache en marxa i habilitat',
                    'El servei apache2 està actiu i s\'arrenca amb el sistema. Comprova-ho: `systemctl status apache2`.',
                    'comprovar que Apache funciona i sobreviu a un reinici.',
                    'un servei que només funciona fins que es reinicia el servidor és una avaria que espera el pròxim reinici.',
                    self::ssh(<<<'SH'
                        systemctl is-active --quiet apache2 || fail "apache2 no està actiu"
                        systemctl is-enabled --quiet apache2 || fail "apache2 no s'arrenca amb el sistema"
                        msg "apache2 actiu i habilitat"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.4.2', 'Tres llocs virtuals amb els seus noms',
                    'Apache té llocs virtuals per www, intranet i web de la teva zona. Comprova-ho: `apache2ctl -S`.',
                    'verificar que els tres llocs existeixen amb el ServerName correcte.',
                    'els llocs virtuals basats en nom són el que permet servir diversos webs amb una sola IP; un ServerName mal escrit fa que el lloc «desaparegui» i caigui al lloc per defecte.',
                    self::ssh(<<<'SH'
                        out=$(sudo -n apache2ctl -S 2>&1)
                        for n in www intranet web; do
                          grep -qF "namevhost $n.$Z" <<<"$out" || fail "falta el lloc virtual $n.$Z (apache2ctl -S)"
                        done
                        msg "llocs virtuals www, intranet i web"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.4.3', 'www serveix la seva pàgina',
                    'www respon 200 i té un enllaç al catàleg. Comprova-ho: `curl -H "Host: www.<zona>" http://10.X.0.21/` des de l\'LXC.',
                    'comprovar que el lloc www serveix el seu contingut.',
                    'és el lloc de cara al públic: si no respon, l\'empresa no té web.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        c=$(code "www.$Z" /); [ "$c" = 200 ] || fail "www.$Z respon $c (n'esperava 200)"
                        web "www.$Z" / | grep -qi cataleg || fail "la pàgina de www no té cap enllaç al catàleg"
                        msg "www respon 200 amb enllaç al catàleg"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.4.4', 'La intranet serveix la seva pàgina',
                    'La intranet respon 200 amb el títol «Intranet del personal» des de la DMZ. Comprova-ho amb `curl` i la capçalera Host.',
                    'comprovar que el lloc intranet existeix i és diferent del públic.',
                    'la intranet i la web pública comparteixen servidor i IP; només el nom les separa.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        c=$(code "intranet.$Z" /); [ "$c" = 200 ] || fail "intranet.$Z respon $c (n'esperava 200 des de la DMZ)"
                        web "intranet.$Z" / | grep -qi 'intranet' || fail "la pàgina de la intranet no diu «Intranet»"
                        msg "intranet respon 200"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.4.5', 'El lloc per defecte atrapa els noms desconeguts',
                    'Un nom que no és de cap lloc virtual i el nom web mostren la mateixa pàgina, diferent de la de www i de la intranet. Comprova-ho: `curl -H "Host: no-existeix.<zona>" ...`.',
                    'verificar que el primer lloc és el per defecte i que no regala el web de l\'empresa.',
                    'si Apache no troba el nom, serveix el primer lloc que va carregar: amb un nom estrany o una IP directa, un atacant veuria el web de l\'empresa (o pitjor, la intranet).',
                    self::ssh(self::WEB."\n".<<<'SH'
                        h() { web "$1" / | md5sum | cut -c1-32; }
                        w=$(h "www.$Z"); i=$(h "intranet.$Z"); d=$(h "web.$Z"); x=$(h "no-existeix.$Z")
                        [ "$x" = "$d" ] || fail "un nom desconegut no cau al lloc web.$Z (és el primer lloc de la configuració?)"
                        [ "$d" != "$w" ] || fail "el lloc per defecte serveix el mateix que www"
                        [ "$d" != "$i" ] || fail "el lloc per defecte serveix el mateix que la intranet"
                        msg "els noms desconeguts cauen al lloc web.$Z"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 40],
                ),
            ]],

            ['Pas 5 · Publicar www amb DNAT i obrir fluxos', 1, <<<'MD'
                Publica `www` amb DNAT a la passarel·la i obre els fluxos F1 a F5. El port 80 del router
                s'usa per a WebFig: administra'l amb WinBox o SSH. Comprova el web des de l'aula, des de
                `pc-ubuntu` i des de `pc-convidat`, i que des d'un equip de l'aula no s'arriba a
                `10.X.0.21` directament.
                MD, [
                $this->c('10.5.1', 'www respon des de l\'aula pel DNAT',
                    'Una petició a l\'adreça d\'aula del teu router amb el nom www arriba al web. Comprova-ho: `curl -H "Host: www.<zona>" http://<adreça del router>/`.',
                    'verificar el DNAT i el flux F1 (aula -> LXC web).',
                    'el DNAT és el que fa visible un servidor privat: sense ell només el veuen els equips de la DMZ.',
                    self::loc(<<<'SH'
                        c=$(curl -sk -L -m 8 -o /dev/null -w '%{http_code}' --resolve "www.$Z:80:$HE_IP_ROUTER" --resolve "www.$Z:443:$HE_IP_ROUTER" "http://www.$Z/")
                        [ "$c" = 200 ] || fail "www.$Z per $HE_IP_ROUTER respon '$c' (DNAT i flux F1)"
                        msg "www.$Z respon 200 pel DNAT de $HE_IP_ROUTER"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.5.2', 'Ports 80 i 443 oberts pel DNAT',
                    'Els ports 80 i 443 del router arriben al web. Comprova-ho: `nc -vz <adreça del router> 80 443`.',
                    'comprovar que s\'han obert HTTP i HTTPS.',
                    'HTTPS s\'ha de poder fer servir des del primer dia: obrir-lo al mateix pas evita descobrir-ho quan ja s\'ha xifrat.',
                    self::loc(<<<'SH'
                        for p in 80 443; do
                          nc -z -w 4 "$HE_IP_ROUTER" "$p" 2>/dev/null || fail "el port $p de $HE_IP_ROUTER no és obert (DNAT o flux F1)"
                        done
                        msg "80 i 443 oberts a $HE_IP_ROUTER"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.5.3', 'Cap accés directe a 10.X.0.21 des de l\'aula',
                    'Des d\'un equip de l\'aula no s\'arriba als ports 80 i 443 de 10.X.0.21 (DROP, flux F7). Comprova-ho: `nc -vz 10.X.0.21 80`.',
                    'verificar que només entra el trànsit que passa pel DNAT.',
                    'si es pot arribar directament a la DMZ des de l\'aula, el DNAT no aporta res i la segmentació no serveix per a res.',
                    self::loc(<<<'SH'
                        for p in 80 443; do
                          nc -z -w 3 "$HE_IP_WEB" "$p" 2>/dev/null && fail "des de l'aula s'arriba directament a $HE_IP_WEB:$p (ha de topar amb el DROP)"
                        done
                        msg "$HE_IP_WEB:80 i :443 no són accessibles directament des de l'aula"
                        SH), ['timeout' => 30],
                ),
            ]],

            ['Pas 6 · Servidor discret i PHP', 1, <<<'MD'
                Fes el servidor discret: redueix la informació que dona, desactiva el llistat de carpetes
                a tot el servidor i els mòduls que no calen, i instal·la PHP amb PHP-FPM. Demostra amb una
                pàgina de prova que el PHP s'executa al servidor i el JavaScript al client, i esborra la
                pàgina en acabar.
                MD, [
                $this->c('10.6.1', 'Apache no revela versió ni sistema',
                    'La capçalera Server diu només «Apache» i les pàgines d\'error no porten versió ni sistema operatiu. Comprova-ho: `curl -I` i una URL que no existeix.',
                    'comprovar ServerTokens Prod i ServerSignature Off.',
                    'dir la versió exacta d\'Apache i el sistema és regalar a l\'atacant la llista d\'exploits que ha de provar.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        s=$(hdr "www.$Z" / | tr -d '\r' | grep -i '^Server:' | head -1)
                        grep -qiE '^Server:[[:space:]]*Apache[[:space:]]*$' <<<"$s" || fail "la capçalera diu «${s:-res}» (ha de ser només «Server: Apache»)"
                        b=$(curl -s -m 8 -H "Host: www.$Z" "http://127.0.0.1/hdy-no-existeix-$$")
                        grep -qiE 'Apache/[0-9]|Ubuntu|Server at' <<<"$b" && fail "les pàgines d'error porten la signatura d'Apache (ServerSignature Off)"
                        msg "Server: Apache, sense signatura a les pàgines d'error"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.6.2', 'PHP no revela la versió',
                    'PHP-FPM té expose_php = Off, perquè no afegeix la capçalera X-Powered-By. Comprova-ho: `grep expose_php /etc/php/*/fpm/php.ini`.',
                    'comprovar que PHP-FPM està instal·lat i que no delata la versió.',
                    'la mateixa raó que amb Apache: cada dada que dona el servidor és una pista per a qui busca vulnerabilitats.',
                    self::ssh(<<<'SH'
                        ls /etc/php/*/fpm/php.ini >/dev/null 2>&1 || fail "PHP-FPM no està instal·lat"
                        for f in /etc/php/*/fpm/php.ini; do
                          grep -qiE '^[[:space:]]*expose_php[[:space:]]*=[[:space:]]*Off' "$f" || fail "$f no té expose_php = Off"
                        done
                        msg "expose_php = Off"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.6.3', 'Llistat de carpetes només a /cataleg/',
                    'Cap carpeta no es llista excepte /srv/cataleg. Comprova-ho: `grep -rn Indexes /etc/apache2`.',
                    'verificar que l\'opció Indexes no és activa enlloc, tret de la carpeta del catàleg.',
                    'un llistat de carpetes ensenya fitxers que ningú no ha publicat a propòsit (còpies, configuracions, proves).',
                    self::ssh(<<<'SH'
                        cfg=$(apcfg); [ -n "$cfg" ] || fail "apache2ctl no pot llegir la configuració"
                        bad=$(awk '
                            /^[ \t]*<(Directory|DirectoryMatch|Location|LocationMatch|Files)[ >]/ { ctx = $0 }
                            /^[ \t]*<\/(Directory|DirectoryMatch|Location|LocationMatch|Files)>/ { ctx = "" }
                            /^[ \t]*Options[ \t]/ && $0 ~ /(^|[ \t+])Indexes/ && ctx !~ /cataleg/ { print ctx " -> " $0 }
                        ' <<<"$cfg")
                        [ -z "$bad" ] || fail "Indexes actiu fora de /cataleg: $(head -1 <<<"$bad" | tr -s ' ')"
                        msg "cap llistat de carpetes fora de /cataleg"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.6.4', 'Només els mòduls necessaris',
                    'Apache no té carregats mòduls que no calen (status, userdir, cgi, dav, info) ni mod_php. Comprova-ho: `apache2ctl -M`.',
                    'verificar que s\'han desactivat els mòduls innecessaris.',
                    'cada mòdul és codi més a la superfície d\'atac; mod_status, per exemple, ensenya què serveix el servidor i a qui.',
                    self::ssh(<<<'SH'
                        mods=$(sudo -n apache2ctl -M 2>/dev/null) || fail "apache2ctl -M ha fallat"
                        m=$(grep -oE '\b(status|userdir|cgi|cgid|dav|dav_fs|dav_lock|info|php[0-9]*)_module' <<<"$mods" | sort -u | tr '\n' ' ')
                        [ -z "$m" ] || fail "mòduls que sobren: $m"
                        msg "cap mòdul innecessari carregat"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.6.5', 'PHP-FPM actiu i connectat a Apache',
                    'PHP-FPM funciona i Apache hi passa les peticions .php (proxy_fcgi). Comprova-ho: `systemctl status php*-fpm` i `apache2ctl -M | grep proxy`.',
                    'verificar que el PHP s\'executa a través de PHP-FPM i no dins d\'Apache.',
                    'separar Apache de l\'intèrpret de PHP permet executar-lo amb el seu propi usuari i reiniciar-los per separat.',
                    self::ssh(<<<'SH'
                        systemctl list-units --type=service --state=active --no-legend 'php*-fpm.service' | grep -q . || fail "PHP-FPM no està actiu"
                        systemctl list-unit-files --no-legend 'php*-fpm.service' | grep -q enabled || fail "PHP-FPM no s'arrenca amb el sistema"
                        sudo -n apache2ctl -M 2>/dev/null | grep -q proxy_fcgi_module || fail "Apache no té el mòdul proxy_fcgi"
                        apcfg | grep -qE 'proxy:(unix|fcgi)' || fail "Apache no passa les peticions .php a PHP-FPM (cap SetHandler proxy:...)"
                        msg "PHP-FPM actiu i enllaçat a Apache"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.6.6', 'No queden pàgines de prova',
                    'No hi ha prova.php, info.php ni fitxers semblants al servidor. Comprova-ho: `find /var/www /srv -name "*.php"`.',
                    'verificar que la pàgina de prova de PHP s\'ha esborrat.',
                    'una pàgina phpinfo oblidada és un dels regals més habituals que es fan a un atacant: configuració, rutes, versions.',
                    self::ssh(<<<'SH'
                        f=$(sudo -n find /var/www /srv -maxdepth 5 -type f \( -iname 'prova*.php' -o -iname 'test*.php' -o -iname 'info.php' -o -iname 'phpinfo.php' -o -iname 'i.php' -o -iname 'probe*.php' \) 2>/dev/null | head -3)
                        [ -z "$f" ] || fail "queden fitxers de prova: $(echo $f)"
                        msg "no hi ha pàgines de prova"
                        SH), ['executor' => Executor::Ssh],
                ),
            ]],

            ['Pas 7 · Protegir la intranet', 1, <<<'MD'
                Protegeix la intranet: `/` només per a la LAN i la DMZ, i `/admin` només des de la VLAN 101
                i amb usuari i contrasenya (autenticació bàsica d'Apache, usuari `admin`). Prova-ho des de
                `pc-ubuntu`, `pc-convidat` i un servidor de la LAN, amb i sense usuari. Troba als registres
                d'accés les teves proves i explica què hi consta.
                MD, [
                $this->c('10.7.1', 'La DMZ no entra a /admin',
                    'Des de l\'LXC (DMZ), /admin/ de la intranet respon 403: l\'administració és només per a la VLAN 101. Comprova-ho amb `curl -I`.',
                    'verificar que l\'ACL per xarxa de /admin funciona.',
                    'segons la matriu d\'accés, un servidor de la DMZ no és personal: l\'ACL ha de tallar abans que es demani cap usuari.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        c=$(code "intranet.$Z" /admin/)
                        [ "$c" = 403 ] || fail "/admin/ des de la DMZ respon $c (ha de ser 403: només la VLAN 101)"
                        msg "/admin/ respon 403 des de la DMZ"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.7.2', 'La intranet és oberta a la DMZ',
                    'Des de l\'LXC (DMZ), la intranet respon 200. Comprova-ho amb `curl -I`.',
                    'verificar que l\'ACL deixa entrar la LAN i la DMZ.',
                    'un control d\'accés massa estricte també és un error: el personal i els servidors han de poder veure la intranet.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        c=$(code "intranet.$Z" /)
                        [ "$c" = 200 ] || fail "la intranet des de la DMZ respon $c (ha de ser 200)"
                        msg "la intranet respon 200 des de la DMZ"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.7.3', 'Autenticació bàsica amb l\'usuari admin',
                    'Hi ha autenticació bàsica amb un fitxer de contrasenyes on existeix l\'usuari admin amb la contrasenya xifrada. Comprova-ho: `htpasswd`, `grep -r AuthUserFile /etc/apache2`.',
                    'verificar que /admin demana usuari i que la contrasenya no s\'ha desat en clar.',
                    'l\'autenticació bàsica només és tan bona com el fitxer que la sustenta: una contrasenya en clar a disc és una contrasenya regalada.',
                    self::ssh(<<<'SH'
                        f=$(sudo -n grep -rhiE '^[[:space:]]*AuthUserFile' /etc/apache2 | awk '{print $2}' | tr -d '"' | head -1)
                        [ -n "$f" ] || fail "no hi ha cap AuthUserFile a la configuració d'Apache"
                        sudo -n grep -rhiE '^[[:space:]]*AuthType[[:space:]]+Basic' /etc/apache2 | grep -q . || fail "no hi ha AuthType Basic"
                        sudo -n grep -rhiE '^[[:space:]]*Require[[:space:]]+(valid-user|user)' /etc/apache2 | grep -q . || fail "no hi ha Require valid-user (o user)"
                        sudo -n grep -qE '^admin:(\$|\{SHA\})' "$f" || fail "a $f no hi ha l'usuari admin amb la contrasenya xifrada"
                        msg "autenticació bàsica amb admin a $f"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.7.4', 'ACL per xarxa: VLAN 101, LAN i DMZ',
                    'La configuració nomena la VLAN 101 (172.X.1.0/24) per a /admin i la LAN i la DMZ per a la intranet. Comprova-ho: `grep -r "Require ip" /etc/apache2`.',
                    'verificar que l\'ACL per xarxa existeix i parla de les xarxes correctes.',
                    'el control per xarxa és el primer filtre: decideix qui arriba a demanar usuari i contrasenya.',
                    self::ssh(<<<'SH'
                        r=$(sudo -n grep -rhiE '^[[:space:]]*Require[[:space:]]' /etc/apache2/sites-enabled /etc/apache2/snippets /etc/apache2/conf-enabled 2>/dev/null)
                        grep -qF "172.$HE_X.1." <<<"$r" || fail "no hi ha cap Require per a la VLAN 101 (172.$HE_X.1.0/24)"
                        grep -qF "172.$HE_X." <<<"$r" && grep -qF "10.$HE_X." <<<"$r" || fail "la intranet no nomena la LAN (172.$HE_X.0.0/22) i la DMZ (10.$HE_X.0.0/22)"
                        msg "ACL per xarxa: VLAN 101, LAN i DMZ"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.7.5', 'Als registres hi ha les teves proves',
                    'El registre d\'accés d\'Apache té peticions rebutjades (401 o 403): les teves proves de l\'ACL i de l\'usuari. Comprova-ho: `grep -E "\" (401|403) " /var/log/apache2/*access*.log`.',
                    'verificar que l\'alumne ha provat l\'ACL i que el registre d\'accés es desa.',
                    'els registres són la memòria del servidor: saber llegir un 401 o un 403 és el primer pas per diagnosticar un accés que falla.',
                    self::ssh(<<<'SH'
                        n=$(sudo -n cat /var/log/apache2/*access*.log 2>/dev/null | grep -cE '" (401|403) ')
                        [ "${n:-0}" -ge 1 ] || fail "als registres d'accés no hi ha cap 401 ni 403 (has provat la intranet des de les altres xarxes?)"
                        msg "$n peticions 401/403 als registres d'accés"
                        SH), ['executor' => Executor::Ssh],
                ),
            ]],

            ['Pas 8 · El catàleg al web', 1, <<<'MD'
                Crea l'usuari `cataleg` i la carpeta `/srv/cataleg`, i sincronitza-la des del FTP anònim del
                Bloc 9 amb `lftp` i un temporitzador de systemd cada 5 minuts. Fes que `/cataleg/` de `www`
                en mostri el llistat i que sigui l'únic lloc on Apache llista una carpeta. Demostra que un
                document nou o esborrat al Windows Server arriba al web, que l'usuari del servei web no
                pot escriure a la carpeta, i què passa al web i al registre de la sincronització si atures
                el FTP. Explica quin risc té esborrar al web tot el que ja no és a l'origen.
                MD, [
                $this->c('10.8.1', 'L\'usuari cataleg no té shell',
                    'Existeix l\'usuari de sistema cataleg i no pot obrir una shell. Comprova-ho: `getent passwd cataleg`.',
                    'verificar que qui sincronitza és un usuari de servei, sense shell.',
                    'un usuari que només ha de copiar fitxers no necessita terminal: si algú en roba les credencials, no pot fer res més.',
                    self::ssh(<<<'SH'
                        l=$(getent passwd cataleg) || fail "no existeix l'usuari cataleg"
                        sh=${l##*:}
                        case $sh in */nologin|*/false) msg "cataleg: shell $sh" ;; *) fail "cataleg té shell ($sh)" ;; esac
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.8.2', '/srv/cataleg és de l\'usuari cataleg',
                    'La carpeta /srv/cataleg és de l\'usuari cataleg i ningú més no hi pot escriure. Comprova-ho: `ls -ld /srv/cataleg`.',
                    'verificar que només el sincronitzador pot modificar la còpia.',
                    'el propietari de la còpia és qui la pot trencar: si és el servei web, un error en una pàgina podria esborrar o canviar documents.',
                    self::ssh(<<<'SH'
                        [ -d /srv/cataleg ] || fail "no existeix /srv/cataleg"
                        o=$(stat -c %U /srv/cataleg); m=$(stat -c %a /srv/cataleg)
                        [ "$o" = cataleg ] || fail "/srv/cataleg és de $o (ha de ser de cataleg)"
                        [ $((8#$m & 2)) -eq 0 ] || fail "/srv/cataleg té permís d'escriptura per a tothom ($m)"
                        msg "/srv/cataleg: propietari cataleg, mode $m"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.8.3', 'El servei web llegeix el catàleg però no el modifica',
                    'L\'usuari www-data pot llegir el catàleg i no hi pot crear ni modificar res. Comprova-ho: `sudo -u www-data touch /srv/cataleg/prova`.',
                    'verificar que el servidor web només té una còpia de lectura (RF2).',
                    'el servidor web és el que s\'exposa a Internet: no ha de poder alterar els documents que publica. Qui el compromet només veu una còpia.',
                    self::ssh(<<<'SH'
                        sudo -n -u www-data test -r /srv/cataleg -a -x /srv/cataleg || fail "www-data no pot llegir /srv/cataleg"
                        sudo -n -u www-data test -w /srv/cataleg && fail "www-data pot escriure a /srv/cataleg"
                        w=$(sudo -n -u www-data find /srv/cataleg -writable 2>/dev/null | head -2 | tr '\n' ' ')
                        [ -z "$w" ] || fail "www-data pot modificar: $w"
                        u=$(sudo -n -u www-data find /srv/cataleg -type f ! -readable 2>/dev/null | head -2 | tr '\n' ' ')
                        [ -z "$u" ] || fail "www-data no pot llegir: $u"
                        msg "www-data llegeix /srv/cataleg i no hi pot escriure"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.8.4', 'L\'script sync-cataleg.sh fa servir lftp i l\'adreça interna',
                    'L\'script /usr/local/sbin/sync-cataleg.sh és executable, fa un lftp mirror i llegeix del FTP per l\'adreça interna (10.X.0.11), no pel nom ftp.<usuari>.smix2…',
                    'comprovar que la sincronització fa servir l\'ordre i l\'origen correctes.',
                    'el nom públic del FTP apunta al router (DNAT) i des de la mateixa DMZ no es pot fer servir (hairpin NAT): la sincronització seria intermitent o no funcionaria.',
                    self::ssh(<<<'SH'
                        s=/usr/local/sbin/sync-cataleg.sh
                        sudo -n test -x "$s" || fail "$s no existeix o no és executable"
                        t=$(sudo -n cat "$s")
                        grep -q lftp <<<"$t" && grep -q mirror <<<"$t" || fail "l'script no fa servir «lftp ... mirror»"
                        grep -qF "$HE_IP_DNS" <<<"$t" || fail "l'script no llegeix del FTP per l'adreça interna $HE_IP_DNS"
                        grep -qiE 'ftp\.[a-z0-9._-]*smix2' <<<"$t" && fail "l'script fa servir el nom ftp.<usuari>.smix2… (apunta al router)"
                        msg "sync-cataleg.sh: lftp mirror des de $HE_IP_DNS"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.8.5', 'Temporitzador cada 5 minuts com a usuari cataleg',
                    'sync-cataleg.timer està actiu, habilitat i es llança cada 5 minuts; el servei s\'executa com a cataleg. Comprova-ho: `systemctl list-timers`, `systemctl cat sync-cataleg.service`.',
                    'verificar que la sincronització és automàtica i amb el mínim privilegi.',
                    'una tasca programada que depèn que algú se\'n recordi acaba no fent-se; i si corre com a root, un error a l\'script pot fer malbé el servidor.',
                    self::ssh(<<<'SH'
                        systemctl is-active --quiet sync-cataleg.timer || fail "sync-cataleg.timer no està actiu"
                        systemctl is-enabled --quiet sync-cataleg.timer || fail "sync-cataleg.timer no s'arrenca amb el sistema"
                        t=$(systemctl cat sync-cataleg.timer 2>/dev/null | grep -E '^(OnCalendar|OnUnitActiveSec|OnUnitInactiveSec)=')
                        grep -Eq '5min|\*/5|0/5|=300|5m' <<<"$t" || fail "el temporitzador no es llança cada 5 minuts (${t:-sense OnCalendar})"
                        u=$(systemctl show sync-cataleg.service -p User --value)
                        [ "$u" = cataleg ] || fail "el servei corre com a '${u:-root}' (ha de ser cataleg)"
                        msg "timer cada 5 min; servei com a cataleg"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.8.6', 'La sincronització s\'ha executat bé fa poc',
                    'L\'última execució del servei ha acabat bé i fa menys de 15 minuts. Comprova-ho: `systemctl status sync-cataleg.service` i `journalctl -u sync-cataleg.service`.',
                    'verificar que la sincronització funciona de veritat, no només que està configurada.',
                    'un temporitzador que falla en silenci és pitjor que no tenir-ne: el web mostraria un catàleg vell sense que ningú ho sàpiga.',
                    self::ssh(<<<'SH'
                        r=$(systemctl show sync-cataleg.service -p Result --value)
                        [ "$r" = success ] || fail "l'última execució del servei és «$r» (journalctl -u sync-cataleg.service)"
                        ts=$(systemctl show sync-cataleg.service -p ExecMainExitTimestamp --value)
                        [ -n "$ts" ] || fail "el servei no s'ha executat mai"
                        age=$(( $(date +%s) - $(date -d "$ts" +%s) ))
                        [ "$age" -le 900 ] || fail "l'última execució va ser fa $((age / 60)) minuts (n'han de ser menys de 15)"
                        msg "última execució correcta fa $((age / 60)) min"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.8.7', 'La còpia coincideix amb el FTP',
                    'La llista de /srv/cataleg és la mateixa que la del FTP anònim del Bloc 9 (10.X.0.11). Comprova-ho: `curl --list-only ftp://10.X.0.11/` i `ls /srv/cataleg`.',
                    'verificar que la còpia reflecteix l\'origen: ni de menys (no sincronitza) ni de més (no esborra el que ja no hi és).',
                    'esborrar el que ja no és a l\'origen manté la còpia fidel, però si l\'origen queda buit per error esborraries tot el catàleg: per això compta el guard de la llista buida.',
                    self::ssh(<<<'SH'
                        ftp=$(curl -sf -m 10 --list-only "ftp://$HE_IP_DNS/" | tr -d '\r' | sort) || fail "no es pot llegir el FTP $HE_IP_DNS des de l'LXC"
                        loc=$(ls -1A /srv/cataleg | sort)
                        [ -n "$ftp" ] || fail "el FTP no té cap document"
                        [ "$ftp" = "$loc" ] || fail "difereix del FTP: $(diff <(echo "$ftp") <(echo "$loc") | grep '^[<>]' | head -3 | tr '\n' ' ')"
                        msg "$(echo "$loc" | wc -l) documents, igual que al FTP"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.8.8', 'La web mostra el catàleg',
                    'www mostra un document del catàleg (a /cataleg/ o, quan hi ha el portal, a /cataleg.php). Comprova-ho amb el navegador.',
                    'verificar el camí complet: Windows Server -> FTP -> LXC -> web.',
                    'és el requisit RF1: un document publicat a la LAN que acaba al navegador d\'un visitant.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        f=$(primer); [ -n "$f" ] || fail "/srv/cataleg és buida (o només té noms amb caràcters especials)"
                        for p in /cataleg/ /cataleg.php; do
                          web "www.$Z" "$p" | grep -qF -- "$f" && { msg "www mostra $f a $p"; exit 0; }
                        done
                        fail "www no mostra $f ni a /cataleg/ ni a /cataleg.php"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.8.9', '/cataleg/ llista la carpeta (només abans del portal)',
                    'A /cataleg/ Apache llista la carpeta (Index of) amb nom, data i mida. Quan s\'instal·la el portal (pas 12) aquesta ruta es retira i aquest check deixarà de passar: els punts es mantenen.',
                    'verificar que el llistat del catàleg és l\'únic llistat del servidor, tal com demana l\'empresa.',
                    'activar Indexes per a una sola carpeta és una decisió conscient; activar-lo a tot arreu és un descuit.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        f=$(primer); [ -n "$f" ] || fail "/srv/cataleg és buida"
                        b=$(web "www.$Z" /cataleg/)
                        grep -qi 'index of' <<<"$b" || fail "/cataleg/ no mostra cap llistat (Index of)"
                        grep -qF -- "$f" <<<"$b" || fail "el llistat de /cataleg/ no té $f"
                        msg "/cataleg/ llista $f i la resta"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
            ]],

            ['Pas 10 · HTTPS amb certificat autosignat', 1, <<<'MD'
                Passa `www` i la intranet a HTTPS amb un certificat autosignat que porti tots dos noms,
                redirigeix el port 80 al 443 i accepta només TLS 1.2 i 1.3. Repeteix la captura del pas 9 i
                compara-les. Comprova que `/cataleg/` continua funcionant per HTTPS. (Opcional: munta una
                CA pròpia, signa el certificat de la intranet i instal·la l'arrel a `pc-ubuntu` i `pc-suport`.)
                MD, [
                $this->c('10.10.1', 'www i la intranet parlen TLS pel 443',
                    'El port 443 de l\'LXC respon TLS per als noms www i intranet. Comprova-ho: `openssl s_client -connect 10.X.0.21:443 -servername www.<zona>`.',
                    'comprovar que HTTPS està activat als dos llocs.',
                    'sense TLS, l\'autenticació bàsica de /admin viatja en clar: era el que es veia a la captura del pas 9.',
                    self::ssh(<<<'SH'
                        for n in www intranet; do
                          echo | openssl s_client -connect 127.0.0.1:443 -servername "$n.$Z" 2>/dev/null | grep -q 'BEGIN CERTIFICATE' || fail "$n.$Z no respon TLS pel 443"
                        done
                        msg "TLS a www i a la intranet"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.10.2', 'El certificat porta els dos noms',
                    'El certificat de www porta www.<zona> (o *.<zona>) i el de la intranet, intranet.<zona> (o *.<zona>), als noms alternatius (SAN). Comprova-ho: `openssl x509 -noout -ext subjectAltName`.',
                    'verificar els SAN: el nom que escriu el navegador ha de ser dins del certificat.',
                    'els navegadors modernes ignoren el CN: si el nom no és als SAN, avisen encara que el certificat sigui «bo».',
                    self::ssh(<<<'SH'
                        for n in www intranet; do
                          san=$(echo | openssl s_client -connect 127.0.0.1:443 -servername "$n.$Z" 2>/dev/null | openssl x509 -noout -ext subjectAltName 2>/dev/null)
                          grep -qF -e "DNS:$n.$Z" -e "DNS:*.$Z" <<<"$san" || fail "el certificat de $n.$Z no porta aquest nom als SAN (${san:-sense SAN})"
                        done
                        msg "SAN correctes per a www i la intranet"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.10.3', 'HTTP redirigeix a HTTPS',
                    'El port 80 de www i de la intranet respon amb una redirecció a https://. Comprova-ho: `curl -I http://www.<zona>`.',
                    'verificar que ningú no es queda a HTTP sense voler.',
                    'si es pot entrar per HTTP, un atacant a la xarxa pot forçar-hi el visitant (o simplement llegir el que hi passa).',
                    self::ssh(self::WEB."\n".<<<'SH'
                        for n in www intranet; do
                          h=$(hdr "$n.$Z" / | tr -d '\r')
                          grep -qE '^HTTP/[0-9.]+ (301|302|307|308)' <<<"$h" || fail "http://$n.$Z no redirigeix (respon: $(head -1 <<<"$h"))"
                          grep -qiE '^Location: https://' <<<"$h" || fail "la redirecció de $n.$Z no va a https://"
                        done
                        msg "HTTP redirigeix a HTTPS"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.10.4', 'Només TLS 1.2 i 1.3',
                    'El servidor accepta TLS 1.2 i 1.3 i rebutja TLS 1.0 i 1.1. Comprova-ho: `openssl s_client -tls1_1 -connect ...`.',
                    'verificar la política de versions de TLS.',
                    'TLS 1.0 i 1.1 tenen debilitats conegudes i ja no els accepta cap navegador; deixar-los activats només serveix per a atacs de degradació.',
                    self::ssh(<<<'SH'
                        t() { echo | openssl s_client -connect 127.0.0.1:443 -servername "www.$Z" -cipher 'ALL:@SECLEVEL=0' "$@" 2>/dev/null | grep -q 'BEGIN CERTIFICATE'; }
                        t -tls1_2 || fail "no accepta TLS 1.2"
                        t -tls1_3 || fail "no accepta TLS 1.3"
                        t -tls1_1 && fail "accepta TLS 1.1 (SSLProtocol)"
                        t -tls1 && fail "accepta TLS 1.0 (SSLProtocol)"
                        msg "només TLS 1.2 i 1.3"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.10.5', 'HTTPS des de l\'aula pel DNAT',
                    'Des d\'un equip de l\'aula, el port 443 de l\'adreça del router serveix el certificat de www. Comprova-ho: `openssl s_client -connect <adreça del router>:443 -servername www.<zona>`.',
                    'verificar que HTTPS també passa pel DNAT i pel flux F1.',
                    'el xifratge s\'ha de provar per tot el camí: un 443 que funciona a l\'LXC però no pel router és un servei que no veu ningú.',
                    self::loc(<<<'SH'
                        echo | openssl s_client -connect "$HE_IP_ROUTER:443" -servername "www.$Z" 2>/dev/null | grep -q 'BEGIN CERTIFICATE' || fail "$HE_IP_ROUTER:443 no serveix cap certificat per a www.$Z"
                        msg "www.$Z serveix TLS per $HE_IP_ROUTER:443"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.10.6', 'Opcional: la intranet amb certificat d\'una CA pròpia',
                    'El certificat de la intranet no és autosignat: l\'ha signat la teva CA. (A la setmana 2 la intranet passa a Let\'s Encrypt i aquest check deixarà de passar; els punts es mantenen.)',
                    'comprovar que s\'ha fet la cadena CA -> certificat de servidor.',
                    'una CA pròpia és com una empresa dóna certificats als seus equips interns: només cal que els clients es fiïn de l\'arrel.',
                    self::ssh(<<<'SH'
                        c=$(echo | openssl s_client -connect 127.0.0.1:443 -servername "intranet.$Z" 2>/dev/null | openssl x509 -noout -subject -issuer 2>/dev/null)
                        sub=$(sed -n 's/^subject=//p' <<<"$c"); iss=$(sed -n 's/^issuer=//p' <<<"$c")
                        [ -n "$iss" ] || fail "no es pot llegir el certificat de la intranet"
                        [ "$sub" != "$iss" ] || fail "el certificat de la intranet és autosignat (subjecte = emissor)"
                        grep -qiE "Let's Encrypt|ISRG" <<<"$iss" && fail "la intranet ja té un certificat de Let's Encrypt, no d'una CA pròpia"
                        msg "emès per: $iss"
                        SH), ['executor' => Executor::Ssh, 'tipus' => CheckKind::Optional, 'timeout' => 30],
                ),
            ]],

            // ───────────────────────── SETMANA 2 ─────────────────────────
            ['Pas 11 · Let\'s Encrypt amb DNS-01', 2, <<<'MD'
                Substitueix l'autosignat per un certificat wildcard de Let's Encrypt amb el repte DNS-01 i
                `acme-dns`, per a `www` i per a la intranet. Emet primer a staging i després en producció,
                comprova la renovació amb `certbot renew --dry-run` i que `curl` no es queixa. Explica el camí
                complet d'una emissió, per què aquí no es podia fer amb HTTP-01 i per què un sol certificat
                serveix per als dos llocs.
                MD, [
                $this->c('10.11.1', 'www té un certificat de Let\'s Encrypt de producció',
                    'El certificat de www l\'ha emès Let\'s Encrypt (no l\'staging). Comprova-ho: `openssl s_client -connect ...:443 | openssl x509 -noout -issuer`.',
                    'verificar que s\'ha passat de staging a producció.',
                    'staging serveix per assajar sense gastar els límits d\'emissió, però el seu certificat no el reconeix cap navegador.',
                    self::ssh(self::le('www')), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.11.2', 'La intranet té un certificat de Let\'s Encrypt de producció',
                    'El certificat de la intranet també l\'ha emès Let\'s Encrypt.',
                    'verificar que els dos llocs fan servir el certificat de producció.',
                    'una intranet amb avís de certificat ensenya el personal a acceptar avisos: el pitjor costum possible.',
                    self::ssh(self::le('intranet')), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.11.3', 'Un sol wildcard per als dos llocs',
                    'www i la intranet serveixen el mateix certificat, que porta *.<zona> als SAN. Comprova-ho: `openssl x509 -noout -fingerprint -ext subjectAltName`.',
                    'verificar que s\'ha demanat un wildcard i no dos certificats.',
                    'un wildcard només es pot validar amb DNS-01: per això aquí s\'ha fet amb acme-dns, i un sol certificat és una sola renovació.',
                    self::ssh(<<<'SH'
                        get() { echo | openssl s_client -connect 127.0.0.1:443 -servername "$1.$Z" 2>/dev/null | openssl x509 -noout -fingerprint -sha256 -ext subjectAltName 2>/dev/null; }
                        a=$(get www); b=$(get intranet)
                        grep -qF "DNS:*.$Z" <<<"$a" || fail "el certificat no porta DNS:*.$Z als SAN"
                        [ "$(grep -i fingerprint <<<"$a")" = "$(grep -i fingerprint <<<"$b")" ] || fail "www i la intranet no serveixen el mateix certificat"
                        msg "un sol certificat wildcard per a www i la intranet"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.11.4', 'curl valida el certificat des de l\'aula',
                    'Des d\'un equip de l\'aula, curl obre https://www.<zona> pel DNAT sense -k i sense queixes. Comprova-ho: `curl -sS https://www.<zona>`.',
                    'verificar que el certificat és de confiança per a un client que no té cap excepció.',
                    'si calen -k o una excepció, el certificat no s\'ha completat: només un client que valida de veritat prova la cadena.',
                    self::loc(<<<'SH'
                        err=$(curl -sS -m 10 -o /dev/null --resolve "www.$Z:443:$HE_IP_ROUTER" "https://www.$Z/" 2>&1) || fail "curl sense -k no valida el certificat de www.$Z: ${err:0:140}"
                        msg "curl valida el certificat de www.$Z"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.11.5', 'Renovació automàtica activa',
                    'El temporitzador de certbot està actiu. Comprova-ho: `systemctl list-timers | grep certbot`.',
                    'verificar que la renovació no depèn de ningú.',
                    'els certificats de Let\'s Encrypt duren 90 dies precisament perquè s\'automatitzin: una renovació manual és una caducitat que arriba un dia qualsevol.',
                    self::ssh(<<<'SH'
                        systemctl is-active --quiet certbot.timer || fail "certbot.timer no està actiu (sudo systemctl enable --now certbot.timer)"
                        msg "certbot.timer actiu"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.11.6', 'La renovació fa servir el repte DNS-01 i l\'script és executable',
                    'La configuració de renovació de certbot fa servir DNS-01 (hook manual o connector) i l\'script del repte té permís d\'execució. Comprova-ho: `ls /etc/letsencrypt/renewal/` i `ls -l` de l\'script.',
                    'verificar que la renovació fallaria o no abans que arribi la data.',
                    'la renovació falla en silenci: el web va bé fins al dia que caduca. Un script sense permís d\'execució és una de les causes més tontes i més habituals.',
                    self::ssh(<<<'SH'
                        f=$(sudo -n ls /etc/letsencrypt/renewal/*.conf 2>/dev/null | head -1)
                        [ -n "$f" ] || fail "no hi ha cap certificat gestionat per certbot (/etc/letsencrypt/renewal)"
                        a=$(sudo -n grep -E '^authenticator' "$f" | head -1 | cut -d= -f2 | tr -d ' ')
                        case $a in
                          manual) h=$(sudo -n grep -E '^manual_auth_hook' "$f" | head -1 | cut -d= -f2- | sed 's/^ *//' | awk '{print $1}')
                                  [ -n "$h" ] || fail "el repte manual no té manual_auth_hook"
                                  sudo -n test -x "$h" || fail "l'script del repte $h no és executable" ;;
                          dns-*) : ;;
                          *) fail "la renovació fa servir «${a:-res}» (ha de ser DNS-01: manual amb hook o un connector dns-…)" ;;
                        esac
                        msg "renovació DNS-01 ($a)"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.11.7', 'El certificat no caduca aviat',
                    'El certificat servit no caduca en els propers 14 dies. Comprova-ho: `openssl x509 -noout -enddate`.',
                    'verificar que el certificat és vigent.',
                    'un certificat caducat és un avís de «web no segur» per a tothom, i sol passar un cap de setmana.',
                    self::ssh(<<<'SH'
                        c=$(echo | openssl s_client -connect 127.0.0.1:443 -servername "www.$Z" 2>/dev/null | openssl x509 -noout -enddate -checkend 1209600 2>/dev/null)
                        grep -q 'will not expire' <<<"$c" || fail "el certificat caduca aviat o ja ha caducat ($(grep notAfter <<<"$c"))"
                        msg "vigent: $(grep notAfter <<<"$c")"
                        SH), ['executor' => Executor::Ssh],
                ),
            ]],

            ['Pas 12 · Instal·lar el portal', 2, <<<'MD'
                Instal·la el portal a partir de `portal-empresa.tar.gz`, sense tocar-ne el codi. Llegeix
                `INSTALACIO.txt` sencer abans de fer res. Instal·la MariaDB i els mòduls de PHP que calguin,
                crea la base de dades i el seu usuari, la configuració i els permisos, i fes que `www`
                serveixi `public` i la intranet `intranet`. Justifica les cinc decisions de la secció
                «Instal·lar-lo bé». Retira la ruta `/cataleg/` de la setmana 1.
                MD, [
                $this->c('10.12.1', 'El portal és a /var/www/app',
                    'Existeixen /var/www/app/public, intranet, lib, config i sql, i la configuració config/config.php. Comprova-ho: `ls /var/www/app`.',
                    'verificar que el paquet s\'ha desplegat al lloc acordat.',
                    'tots els checks següents donen per fet aquest camí: si és un altre, les proves no saben on mirar.',
                    self::ssh(<<<'SH'
                        for d in public intranet lib config sql; do
                          [ -d "/var/www/app/$d" ] || fail "falta /var/www/app/$d"
                        done
                        sudo -n test -f /var/www/app/config/config.php || fail "falta /var/www/app/config/config.php (còpia de config.exemple.php)"
                        msg "portal a /var/www/app amb la configuració"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.12.2', 'Decisió 1: www serveix public/',
                    'L\'arrel de documents del lloc www és /var/www/app/public. Comprova-ho: `grep -r DocumentRoot /etc/apache2/sites-enabled`.',
                    'verificar que a www només s\'hi serveix la carpeta pública.',
                    'si l\'arrel és /var/www/app, la configuració, la biblioteca i l\'esquema es poden demanar pel navegador.',
                    self::ssh(self::DOCROOT."\n".<<<'SH'
                        r=$(docroots "www.$Z" | sort -u)
                        [ -n "$r" ] || fail "no trobo cap DocumentRoot per al lloc www.$Z"
                        [ "$r" = "/var/www/app/public" ] || fail "DocumentRoot de www: $(echo $r) (ha de ser /var/www/app/public)"
                        msg "www serveix /var/www/app/public"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.12.3', 'Decisió 1: la intranet serveix intranet/',
                    'L\'arrel de documents del lloc intranet és /var/www/app/intranet.',
                    'verificar que la intranet serveix la seva carpeta i no la pública ni l\'arrel.',
                    'una intranet servint la carpeta pública, o a l\'inrevés, mostra a un visitant el que només ha de veure el personal.',
                    self::ssh(self::DOCROOT."\n".<<<'SH'
                        r=$(docroots "intranet.$Z" | sort -u)
                        [ -n "$r" ] || fail "no trobo cap DocumentRoot per al lloc intranet.$Z"
                        [ "$r" = "/var/www/app/intranet" ] || fail "DocumentRoot de la intranet: $(echo $r) (ha de ser /var/www/app/intranet)"
                        msg "la intranet serveix /var/www/app/intranet"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.12.4', 'Decisió 2: el servei web llegeix el codi però no el modifica',
                    'L\'usuari www-data pot llegir el codi i config.php, i no pot escriure a res de /var/www/app. Comprova-ho: `sudo -u www-data find /var/www/app -writable`.',
                    'verificar els permisos del codi (mínim privilegi).',
                    'si el servei web pot escriure al seu codi, un forat en una pàgina deixa l\'atacant canviar el web o instal·lar-hi una porta del darrere.',
                    self::ssh(<<<'SH'
                        sudo -n -u www-data test -r /var/www/app/lib/db.php || fail "www-data no pot llegir el codi (/var/www/app/lib/db.php)"
                        sudo -n -u www-data test -r /var/www/app/config/config.php || fail "www-data no pot llegir config.php (el portal no arrencaria)"
                        w=$(sudo -n -u www-data find /var/www/app -writable 2>/dev/null | head -3 | tr '\n' ' ')
                        [ -z "$w" ] || fail "www-data pot modificar: $w"
                        msg "www-data llegeix el codi i no el pot modificar"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.12.5', 'Decisió 5: config.php només el pot llegir qui cal',
                    'config.php no el pot llegir qualsevol usuari del sistema (mode 640 o més estricte) ni tampoc modificar-lo el grup. Comprova-ho: `ls -l /var/www/app/config/config.php`.',
                    'verificar els permisos del fitxer amb la contrasenya de la base de dades.',
                    'qualsevol usuari local que pugui llegir config.php té la contrasenya de la base de dades.',
                    self::ssh(<<<'SH'
                        f=/var/www/app/config/config.php
                        sudo -n test -f "$f" || fail "no existeix $f"
                        m=$(sudo -n stat -c %a "$f")
                        [ $((8#$m & 7)) -eq 0 ] || fail "$f té mode $m: l'ha de poder llegir només el propietari i el grup"
                        [ $((8#$m & 8#020)) -eq 0 ] || fail "$f té mode $m: el grup no hi pot escriure"
                        msg "config.php amb mode $m"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.12.6', 'Decisió 4: MariaDB només escolta a 127.0.0.1',
                    'MariaDB escolta només a 127.0.0.1 (o no escolta per TCP). Comprova-ho: `ss -tlnp | grep 3306`.',
                    'verificar que la base de dades no és accessible des de la xarxa.',
                    'una base de dades exposada és un objectiu directe: intents de contrasenya, exploits del servei, exfiltració. El portal i MariaDB són al mateix equip.',
                    self::ssh(<<<'SH'
                        systemctl is-active --quiet mariadb || fail "mariadb no està actiu"
                        l=$(sudo -n ss -Hltn 2>/dev/null | awk '$4 ~ /:3306$/ {print $4}')
                        [ -n "$l" ] || { msg "MariaDB no escolta per TCP"; exit 0; }
                        bad=$(grep -vE '^(127\.0\.0\.1|\[::1\]):3306$' <<<"$l" | tr '\n' ' ')
                        [ -z "$bad" ] || fail "MariaDB escolta a: $bad (bind-address = 127.0.0.1)"
                        msg "MariaDB només a 127.0.0.1"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.12.7', 'Decisió 3: l\'usuari app només existeix per a localhost',
                    'A MariaDB l\'usuari app només existeix amb l\'origen localhost. Comprova-ho: `SELECT user, host FROM mysql.user`.',
                    'verificar que l\'usuari de la base de dades no es pot fer servir des d\'una altra màquina.',
                    'un usuari amb host «%» pot entrar des de qualsevol adreça: contra això no hi ha tallafocs que ajudi si algun dia s\'obre el 3306.',
                    self::ssh(<<<'SH'
                        h=$(sudo -n mysql -N -B -e "SELECT Host FROM mysql.user WHERE User='app'" 2>&1) || fail "no puc consultar MariaDB: ${h:0:100}"
                        [ -n "$h" ] || fail "no existeix l'usuari app a MariaDB"
                        [ "$h" = localhost ] || fail "l'usuari app existeix per a: $(echo $h) (ha de ser només localhost)"
                        msg "app@localhost és l'únic usuari app"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.12.8', 'Decisió 3: l\'usuari app només fa SELECT, INSERT, UPDATE i DELETE a la base app',
                    'L\'usuari app només té SELECT, INSERT, UPDATE i DELETE sobre app.*, sense GRANT OPTION ni privilegis globals. Comprova-ho: `SHOW GRANTS FOR \'app\'@\'localhost\'`.',
                    'verificar el mínim privilegi a la base de dades.',
                    'si el portal té un forat d\'injecció SQL, l\'atacant tindrà els privilegis de l\'usuari: amb ALL PRIVILEGES o FILE pot llegir fitxers del servidor.',
                    self::ssh(<<<'SH'
                        g=$(sudo -n mysql -N -B -e "SHOW GRANTS FOR 'app'@'localhost'" 2>&1) || fail "no puc consultar els privilegis de app: ${g:0:100}"
                        g=$(grep -v 'GRANT USAGE ON' <<<"$g")
                        [ "$(grep -c . <<<"$g")" -eq 1 ] || fail "app té $(grep -c . <<<"$g") conjunts de privilegis (n'ha de tenir un, sobre la base app)"
                        grep -q 'GRANT OPTION' <<<"$g" && fail "app té GRANT OPTION"
                        p=$(sed -nE 's/^GRANT (.*) ON `app`\.\* TO .*/\1/p' <<<"$g" | tr -d ' ' | tr ',' '\n' | sort | tr '\n' ' ')
                        [ -n "$p" ] || fail "els privilegis de app no són sobre la base app: ${g:0:100}"
                        [ "$p" = "DELETE INSERT SELECT UPDATE " ] || fail "privilegis de app: $p (han de ser DELETE INSERT SELECT UPDATE)"
                        msg "app: SELECT, INSERT, UPDATE i DELETE sobre app.*"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.12.9', 'L\'esquema s\'ha importat',
                    'La base app té les taules documents i descarregues. Comprova-ho: `SHOW TABLES FROM app`.',
                    'verificar que s\'ha carregat schema.sql.',
                    'sense les taules, el portal mostra el missatge d\'error genèric: és el que passa quan es salta un pas de les notes d\'instal·lació.',
                    self::ssh(<<<'SH'
                        t=$(sudo -n mysql -N -B -e "SHOW TABLES FROM app" 2>&1) || fail "no puc consultar la base app: ${t:0:100}"
                        for x in documents descarregues; do
                          grep -qx "$x" <<<"$t" || fail "falta la taula $x a la base app (sql/schema.sql)"
                        done
                        msg "taules documents i descarregues"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.12.10', 'S\'ha retirat /cataleg/',
                    'La ruta /cataleg/ de la setmana 1 ja no llista la carpeta. Comprova-ho amb el navegador.',
                    'verificar que s\'ha tret l\'Alias i l\'Indexes de la setmana 1.',
                    'les descàrregues han de passar pel portal perquè es comptin; un llistat paral·lel és una porta pel costat.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        b=$(web "www.$Z" /cataleg/)
                        grep -qi 'index of' <<<"$b" && fail "/cataleg/ encara llista la carpeta (treu l'Alias i l'Indexes)"
                        msg "/cataleg/ ja no llista la carpeta (codi $(code "www.$Z" /cataleg/))"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
            ]],

            ['Pas 13 · Comprovar la instal·lació', 2, <<<'MD'
                Comprova la instal·lació abans de donar-la per bona. Passa tots els punts de «Com saber que
                està bé», amb una captura o la sortida de l'ordre per a cada un, i corregeix el que falli.
                Si un punt falla, el problema no és del codi: reviseu quina de les cinc decisions heu pres
                malament.
                MD, [
                $this->c('10.13.1', 'La web pública mostra el catàleg del portal',
                    'www/cataleg.php mostra els documents de /srv/cataleg amb un enllaç de descàrrega. Comprova-ho amb el navegador.',
                    'verificar que el portal llegeix el catàleg sincronitzat.',
                    'és el requisit RF1 amb el portal: el mateix catàleg de la setmana 1, ara amb descripcions i estadístiques.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        f=$(primer); [ -n "$f" ] || fail "/srv/cataleg és buida"
                        c=$(code "www.$Z" /cataleg.php); [ "$c" = 200 ] || fail "cataleg.php respon $c (n'esperava 200)"
                        b=$(web "www.$Z" /cataleg.php)
                        grep -qF -- "$f" <<<"$b" || fail "cataleg.php no mostra $f"
                        grep -q 'descarrega.php?f=' <<<"$b" || fail "cataleg.php no té enllaços de descàrrega"
                        msg "cataleg.php mostra $f amb enllaç de descàrrega"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.13.2', 'La intranet mostra catàleg i estadístiques',
                    'La intranet mostra el catàleg i les estadístiques (els 5 més descarregats i les 10 últimes descàrregues). Comprova-ho des de pc-ubuntu.',
                    'verificar el contingut de la intranet.',
                    'la intranet és el que justifica tot l\'ACL: si no mostra res més que la pública, la protecció no serveix per a res.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        f=$(primer); [ -n "$f" ] || fail "/srv/cataleg és buida"
                        c=$(code "intranet.$Z" /); [ "$c" = 200 ] || fail "la intranet respon $c (n'esperava 200)"
                        b=$(web "intranet.$Z" /)
                        grep -qF -- "$f" <<<"$b" || fail "la intranet no mostra $f"
                        grep -qi 'estad' <<<"$b" || fail "la intranet no té la secció d'estadístiques"
                        msg "la intranet mostra el catàleg i les estadístiques"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
                $this->c('10.13.3', 'Una petició amb ../ respon 404',
                    'Una petició de descàrrega amb ../ al nom del fitxer respon 404. Comprova-ho: `curl -I "https://www.<zona>/descarrega.php?f=../config/config.php"`.',
                    'verificar que no es pot sortir de la carpeta del catàleg.',
                    'el path traversal és un dels atacs més clàssics: ../ al nom del fitxer per llegir la configuració o /etc/passwd.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        for q in '../config/config.php' '..%2f..%2fetc%2fpasswd' '/etc/passwd'; do
                          c=$(code "www.$Z" "/descarrega.php?f=$q")
                          [ "$c" = 404 ] || fail "descarrega.php?f=$q respon $c (n'esperava 404)"
                        done
                        msg "les sortides de la carpeta del catàleg responen 404"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 40],
                ),
                $this->c('10.13.4', 'Les rutes internes responen 404',
                    'Ni config, ni lib, ni sql es poden demanar per www ni per la intranet (404). Comprova-ho amb `curl -I` a /config/config.php, /lib/db.php i /sql/schema.sql.',
                    'verificar la decisió 1 (arrel de documents) i la separació de codi i secrets.',
                    'config.php conté la contrasenya de la base de dades: si es pot descarregar, l\'atacant no necessita cap forat.',
                    self::ssh(self::WEB."\n".<<<'SH'
                        for h in "www.$Z" "intranet.$Z"; do
                          for p in /config/config.php /config/config.exemple.php /lib/db.php /lib/cataleg.php /sql/schema.sql; do
                            c=$(code "$h" "$p"); [ "$c" = 404 ] || fail "https://$h$p respon $c (n'esperava 404)"
                          done
                        done
                        c=$(code "www.$Z" /intranet/); [ "$c" = 404 ] || fail "www.$Z/intranet/ respon $c (la web pública no ha de servir la intranet)"
                        msg "config, lib, sql i intranet responen 404"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 60],
                ),
                $this->c('10.13.5', 'No es veu mai codi PHP',
                    'Amb Ctrl+U al navegador es veu HTML, mai codi PHP. Comprova-ho: `curl https://www.<zona>/ | grep "<?php"`.',
                    'verificar que PHP s\'executa al servidor i no es serveix com a text.',
                    'si PHP-FPM falla o el handler no és al lloc, Apache serveix els .php com a text i regala el codi (i les credencials, si en tenen).',
                    self::ssh(self::WEB."\n".<<<'SH'
                        for h in "www.$Z" "intranet.$Z"; do
                          for p in / /cataleg.php; do
                            b=$(web "$h" "$p")
                            grep -qE '<\?(php|=)' <<<"$b" && fail "https://$h$p serveix codi PHP"
                          done
                        done
                        msg "no es serveix codi PHP"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 40],
                ),
                $this->c('10.13.6', 'S\'ha registrat alguna descàrrega',
                    'Hi ha descàrregues registrades a la base de dades: has descarregat un document pel navegador i compta a l\'estadística. Comprova-ho a la intranet.',
                    'verificar el camí complet de les estadístiques: navegador -> Apache -> PHP -> MariaDB.',
                    'és la prova que el portal escriu a la base de dades amb l\'usuari de mínim privilegi, i no només que la llegeix.',
                    self::ssh(<<<'SH'
                        n=$(sudo -n mysql -N -B -e "SELECT COUNT(*) FROM app.descarregues" 2>&1) || fail "no puc consultar app.descarregues: ${n:0:100}"
                        [ "${n:-0}" -ge 1 ] || fail "no hi ha cap descàrrega registrada (descarrega un document pel navegador)"
                        msg "$n descàrregues registrades"
                        SH), ['executor' => Executor::Ssh],
                ),
            ]],

            ['Pas 14 · Publicar pel túnel de Cloudflare', 2, <<<'MD'
                Publica el web a Internet pel túnel: demana al professor la ruta per al teu nom i comprova
                `https://<usuari>.julioantonio.cat` des del mòbil, fora de la xarxa de l'aula. Comprova que
                descarregues un document pel nom públic (i que apareix a l'estadística) i que `/admin`, la
                intranet i `/config` no es serveixen pel nom públic. Fes que els registres d'Apache mostrin
                l'adreça real del visitant i demostra, des de `pc-ubuntu`, que una capçalera falsa no la canvia.
                MD, [
                $this->c('10.14.1', 'El nom públic respon amb un certificat vàlid',
                    'https://<usuari>.julioantonio.cat respon 200 des d\'Internet, sense avisos de certificat. Comprova-ho des del mòbil, amb les dades.',
                    'verificar la publicació pel túnel de Cloudflare.',
                    'un túnel és una connexió de sortida: no cal obrir cap port a Internet i el visitant ve igualment.',
                    self::loc(<<<'SH'
                        r=$(curl -sS -m 15 -o /dev/null -w '%{http_code}' "https://$PUB/" 2>&1) || fail "https://$PUB/ no respon: ${r:0:120}"
                        [ "$r" = 200 ] || fail "https://$PUB/ respon $r (n'esperava 200)"
                        msg "https://$PUB/ respon 200"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.14.2', 'El catàleg es descarrega pel nom públic',
                    'El catàleg és visible i amb enllaços de descàrrega pel nom públic. Comprova-ho: `curl https://<usuari>.julioantonio.cat/cataleg.php`.',
                    'verificar RF1 des d\'Internet: la web pública és el que ha de sortir.',
                    'la web pública del portal s\'ha de poder fer servir des de fora: és el sentit de publicar-la.',
                    self::loc(<<<'SH'
                        b=$(curl -sS -m 15 "https://$PUB/cataleg.php" 2>&1) || fail "https://$PUB/cataleg.php no respon: ${b:0:120}"
                        grep -q 'descarrega.php?f=' <<<"$b" || fail "cataleg.php pel nom públic no té cap enllaç de descàrrega"
                        msg "el catàleg és visible pel nom públic"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.14.3', 'Intranet, administració i configuració no surten pel nom públic',
                    '/admin, /intranet, /config i /sql no es serveixen pel nom públic (ni 200 ni 401).',
                    'verificar que només la web pública viatja pel túnel.',
                    'segons la matriu d\'accés, un visitant d\'Internet només veu la web pública: la intranet no ha de ser a l\'abast ni amb autenticació.',
                    self::loc(<<<'SH'
                        for p in /admin/ /admin /intranet/ /config/config.php /lib/db.php /sql/schema.sql; do
                          c=$(curl -sS -m 15 -o /dev/null -w '%{http_code}' "https://$PUB$p" 2>/dev/null)
                          case $c in 200|401) fail "https://$PUB$p respon $c (no s'ha de servir pel nom públic)" ;; esac
                        done
                        msg "les rutes internes no es serveixen pel nom públic"
                        SH), ['timeout' => 60],
                ),
                $this->c('10.14.4', 'Apache registra l\'adreça real del visitant',
                    'Apache té mod_remoteip amb una capçalera de confiança i uns servidors intermedis de confiança concrets (no tothom). Comprova-ho: `apache2ctl -M | grep remoteip`, `grep -r RemoteIP /etc/apache2`.',
                    'verificar que el registre mostra el visitant i no el túnel.',
                    'sense això, tots els visitants d\'Internet apareixen com l\'adreça del túnel i el registre no serveix per investigar res.',
                    self::ssh(<<<'SH'
                        sudo -n apache2ctl -M 2>/dev/null | grep -q remoteip_module || fail "Apache no té el mòdul remoteip (a2enmod remoteip)"
                        c=$(sudo -n grep -rhiE '^[[:space:]]*RemoteIP' /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/snippets 2>/dev/null)
                        grep -qi 'RemoteIPHeader' <<<"$c" || fail "falta RemoteIPHeader"
                        grep -qiE 'RemoteIP(Trusted|Internal)Proxy' <<<"$c" || fail "falta RemoteIPTrustedProxy (de qui es creu la capçalera)"
                        grep -qE '0\.0\.0\.0/0|::/0' <<<"$c" && fail "RemoteIPTrustedProxy confia en tothom"
                        msg "remoteip amb proxys de confiança concrets"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.14.5', 'Una capçalera falsa no canvia l\'adreça del registre',
                    'Una petició amb CF-Connecting-IP o X-Forwarded-For falsos des d\'un equip no de confiança queda registrada amb la seva adreça real. Comprova-ho amb `curl -H "X-Forwarded-For: 203.0.113.77"` i el registre.',
                    'verificar que només es creu la capçalera d\'un origen de confiança.',
                    'si es creu la capçalera de qualsevol, un atacant escriu al registre l\'adreça que vulgui: no serveix per culpar ningú, ni per bloquejar-lo.',
                    self::ssh(<<<'SH'
                        m="hdy-$$-$RANDOM"
                        curl -sk -m 8 -o /dev/null --resolve "www.$Z:443:127.0.0.1" -H "CF-Connecting-IP: 203.0.113.77" -H "X-Forwarded-For: 203.0.113.77" "https://www.$Z/$m"
                        sleep 1
                        l=$(sudo -n grep -h "$m" /var/log/apache2/*access*.log 2>/dev/null | head -1)
                        [ -n "$l" ] || fail "no trobo la petició de prova als registres d'accés d'Apache"
                        grep -qF '203.0.113.77' <<<"$l" && fail "una capçalera falsa canvia l'adreça del registre (RemoteIPTrustedProxy massa ample)"
                        msg "la capçalera falsa no s'ha creguda: ${l%% *}"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
            ]],

            ['Pas 15 · El servidor a AWS (EC2)', 2, <<<'MD'
                Crea l'EC2 al Learner Lab (`us-east-1`, Ubuntu 24.04, `t3.micro`) amb un grup de seguretat
                que només obri el 22 des de la IP del centre i el 80 i el 443 a tothom, i una adreça
                elàstica. Passa l'adreça al professor. Instal·la-hi Apache, PHP-FPM i MariaDB amb la mateixa
                seguretat del pas 6. Crea l'usuari `deploy`, que només pot fer SFTP, amb una clau generada al
                servidor web de l'aula, i comprova que amb aquesta clau no es pot obrir una terminal. Obre
                al tallafocs el flux F6.

                **Agent a l'EC2:** els checks marcats «a l'EC2» els executa l'agent de HeimdEye que has
                d'instal·lar a la instància, registrada amb el nom `aws` (`--hostname aws`).
                MD, [
                $this->c('10.15.1', 'El nom <usuari>-aws existeix a Internet',
                    'El nom <usuari>-aws.julioantonio.cat resol a una adreça IPv4 (el professor crea el registre A quan li passes l\'adreça elàstica). Comprova-ho: `dig +short <usuari>-aws.julioantonio.cat`.',
                    'verificar que l\'adreça elàstica s\'ha passat i el registre existeix.',
                    'una adreça elàstica no canvia en reiniciar la instància: és el que permet un nom DNS estable.',
                    self::loc(<<<'SH'
                        ip=$(dig +short +time=3 +tries=1 A "$AWS" 2>/dev/null | grep -E '^[0-9.]+$' | tail -1)
                        [ -n "$ip" ] || fail "$AWS no resol a cap adreça IPv4 (ja has passat l'adreça elàstica al professor?)"
                        msg "$AWS -> $ip"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.15.2', 'Ports 80 i 443 oberts a Internet',
                    'Els ports 80 i 443 de l\'EC2 responen des d\'Internet. Comprova-ho: `nc -vz <usuari>-aws.julioantonio.cat 80 443`.',
                    'verificar el grup de seguretat per a la web pública.',
                    'el grup de seguretat és el tallafocs del núvol: sense aquests dos ports, ni el web ni la validació HTTP-01 del pas 17 funcionen.',
                    self::loc(<<<'SH'
                        for p in 80 443; do
                          nc -z -w 5 "$AWS" "$p" 2>/dev/null || fail "$AWS:$p no respon (grup de seguretat o servei)"
                        done
                        msg "$AWS: 80 i 443 oberts"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.15.3', 'El port 3306 no és accessible des d\'Internet',
                    'El port 3306 de l\'EC2 no respon des d\'Internet. Comprova-ho: `nc -vz <usuari>-aws.julioantonio.cat 3306`.',
                    'verificar que la base de dades no s\'exposa mai al núvol.',
                    'una base de dades a Internet és trobada en minuts per escàners automàtics; a AWS el port 3306 no s\'obre mai.',
                    self::loc(<<<'SH'
                        nc -z -w 5 "$AWS" 3306 2>/dev/null && fail "$AWS:3306 és accessible des d'Internet (tanca'l al grup de seguretat)"
                        msg "$AWS:3306 no és accessible"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.15.4', 'A l\'EC2: Apache, PHP-FPM i MariaDB actius',
                    'A l\'EC2 funcionen els tres serveis i s\'arrenquen amb el sistema. Comprova-ho: `systemctl status apache2 mariadb php*-fpm`.',
                    'verificar que la pila LAMP és instal·lada i en marxa a la instància.',
                    'els mateixos tres serveis que a l\'aula; el que canvia és tot el que els envolta.',
                    self::aws(<<<'SH'
                        for s in apache2 mariadb; do
                          systemctl is-active --quiet "$s" || fail "$s no està actiu"
                          systemctl is-enabled --quiet "$s" || fail "$s no s'arrenca amb el sistema"
                        done
                        systemctl list-units --type=service --state=active --no-legend 'php*-fpm.service' | grep -q . || fail "PHP-FPM no està actiu"
                        msg "apache2, mariadb i php-fpm actius"
                        SH), ['executor' => Executor::Agent],
                ),
                $this->c('10.15.5', 'A l\'EC2: la mateixa seguretat que a l\'aula',
                    'L\'Apache de l\'EC2 no revela versió, no llista carpetes i PHP té expose_php = Off. Comprova-ho amb `curl -I http://localhost`.',
                    'verificar que es repeteix l\'enduriment del pas 6 en un servidor nou.',
                    'un servidor nou a Internet sense enduriment és el més fàcil de trobar i de comprometre: l\'enduriment no es porta amb el codi, s\'ha de refer.',
                    self::aws(<<<'SH'
                        s=$(curl -sI -m 8 http://127.0.0.1/ | tr -d '\r' | grep -i '^Server:' | head -1)
                        grep -qiE '^Server:[[:space:]]*Apache[[:space:]]*$' <<<"$s" || fail "la capçalera diu «${s:-res}» (ServerTokens Prod)"
                        cfg=$(apcfg); [ -n "$cfg" ] || fail "apache2ctl no pot llegir la configuració"
                        bad=$(awk '/^[ \t]*Options[ \t]/ && $0 ~ /(^|[ \t+])Indexes/ { print $0 }' <<<"$cfg" | head -1)
                        [ -z "$bad" ] || fail "Indexes actiu: $bad"
                        for f in /etc/php/*/fpm/php.ini; do
                          grep -qiE '^[[:space:]]*expose_php[[:space:]]*=[[:space:]]*Off' "$f" || fail "$f no té expose_php = Off"
                        done
                        msg "Server: Apache, sense Indexes i expose_php = Off"
                        SH), ['executor' => Executor::Agent],
                ),
                $this->c('10.15.6', 'A l\'EC2: MariaDB només escolta a 127.0.0.1',
                    'A l\'EC2 MariaDB no escolta a cap adreça de xarxa. Comprova-ho: `ss -tlnp | grep 3306`.',
                    'verificar la segona capa: encara que el grup de seguretat falli, MariaDB no escolta.',
                    'defensa en profunditat: el grup de seguretat i el bind-address són dues portes, no una.',
                    self::aws(<<<'SH'
                        l=$(ss -Hltn 2>/dev/null | awk '$4 ~ /:3306$/ {print $4}')
                        [ -n "$l" ] || { msg "MariaDB no escolta per TCP"; exit 0; }
                        bad=$(grep -vE '^(127\.0\.0\.1|\[::1\]):3306$' <<<"$l" | tr '\n' ' ')
                        [ -z "$bad" ] || fail "MariaDB escolta a: $bad"
                        msg "MariaDB només a 127.0.0.1"
                        SH), ['executor' => Executor::Agent],
                ),
                $this->c('10.15.7', 'A l\'EC2: l\'usuari deploy només pot fer SFTP',
                    'L\'usuari deploy existeix, només té SFTP (ForceCommand internal-sftp), no és al grup sudo i no entra amb contrasenya. Comprova-ho: `sshd -T -C user=deploy,host=x,addr=1.2.3.4`.',
                    'verificar que la clau de desplegament no dona una terminal ni privilegis.',
                    'si la clau del servidor de l\'aula es filtra, l\'atacant només podria pujar fitxers al servidor del núvol, no executar res.',
                    self::aws(<<<'SH'
                        id deploy >/dev/null 2>&1 || fail "no existeix l'usuari deploy"
                        g=$(id -nG deploy)
                        grep -qwE 'sudo|admin|wheel|root' <<<"$g" && fail "deploy és als grups: $g (no pot tenir sudo)"
                        s=$(sshd -T -C user=deploy,host=localhost,addr=127.0.0.1 2>/dev/null)
                        grep -qiE '^forcecommand internal-sftp' <<<"$s" || fail "deploy no té «ForceCommand internal-sftp» a sshd_config"
                        grep -qi '^passwordauthentication no' <<<"$s" || fail "deploy pot entrar amb contrasenya (PasswordAuthentication no)"
                        msg "deploy: SFTP només, sense sudo, amb clau"
                        SH), ['executor' => Executor::Agent],
                ),
                $this->c('10.15.8', 'Des de l\'aula, la clau deploy_aws fa SFTP a l\'EC2',
                    'Des de l\'LXC web, la clau /root/.ssh/deploy_aws entra a l\'EC2 per SFTP com a deploy (flux F6, port 22). Comprova-ho: `sftp -i /root/.ssh/deploy_aws deploy@<usuari>-aws.julioantonio.cat`.',
                    'verificar el flux F6 i la clau de desplegament.',
                    'el servidor de l\'aula empeny cap al núvol: el núvol no entra mai a la xarxa de l\'empresa. És el camí invers de cap altre que s\'hagués pogut triar.',
                    self::ssh(self::SFTP."\n".<<<'SH'
                        key=/root/.ssh/deploy_aws
                        sudo -n test -f "$key" || fail "falta la clau $key"
                        m=$(sudo -n stat -c %a "$key"); [ "$m" = 600 ] || fail "$key té mode $m (ha de ser 600)"
                        out=$(sftp_deploy) || fail "sftp amb la clau deploy_aws falla: $(tail -1 <<<"$out" | cut -c1-120)"
                        grep -q 'Remote working directory' <<<"$out" || fail "sftp no respon com s'esperava"
                        msg "SFTP correcte a $AWS amb la clau deploy_aws"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 40],
                ),
                $this->c('10.15.9', 'La clau deploy_aws no dona una terminal',
                    'Amb la clau deploy_aws, executar una ordre per SSH a l\'EC2 no funciona: només hi ha SFTP. Comprova-ho: `ssh -i /root/.ssh/deploy_aws deploy@<usuari>-aws.julioantonio.cat id`.',
                    'verificar que la clau no obre cap terminal.',
                    'és la raó per no desplegar amb l\'usuari ubuntu: una clau amb sudo al servidor de l\'aula seria la clau de tot el núvol.',
                    self::ssh(self::SFTP."\n".<<<'SH'
                        key=/root/.ssh/deploy_aws
                        sudo -n test -f "$key" || fail "falta la clau $key"
                        sftp_deploy >/dev/null 2>&1 || fail "no puc validar la prova: primer ha de funcionar el SFTP amb la clau (check 10.15.8)"
                        out=$(sudo -n ssh -i "$key" "${SSHO[@]}" -T "deploy@$AWS" 'echo HDY_SHELL_OK' </dev/null 2>&1)
                        grep -q HDY_SHELL_OK <<<"$out" && fail "la clau deploy_aws obre una terminal a $AWS"
                        msg "la clau deploy_aws no executa ordres a $AWS"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 40],
                ),
                $this->c('10.15.10', 'La clau .pem d\'AWS no és al servidor web',
                    'No hi ha cap fitxer .pem ni clau del Learner Lab a l\'LXC web. Comprova-ho: `find / -name "*.pem" -not -path "/etc/*"`.',
                    'verificar la regla de l\'empresa: la clau .pem no es copia a cap servidor.',
                    'la clau .pem és de l\'usuari ubuntu, que té sudo a l\'EC2: si és a l\'LXC, qui entri a l\'LXC té tot el núvol.',
                    self::ssh(<<<'SH'
                        f=$(sudo -n find /root /home /srv /tmp /opt /var/www -maxdepth 5 -type f \( -iname '*.pem' -o -iname '*.ppk' -o -iname 'labsuser*' -o -iname 'vockey*' \) 2>/dev/null | head -3 | tr '\n' ' ')
                        [ -z "$f" ] || fail "hi ha claus a l'LXC: $f"
                        msg "cap clau .pem a l'LXC"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 30],
                ),
            ]],

            ['Pas 16 · Desplegar el portal a AWS', 2, <<<'MD'
                Desplega a l'EC2 la web pública del portal: puja el codi des del servidor web de l'aula amb
                l'usuari `deploy`, crea a l'EC2 la base de dades i la configuració pròpies, i puja-hi el
                catàleg. Comprova que un document nou de l'aula arriba a AWS quan tornes a pujar el catàleg.
                Explica per què no has pujat ni `config` ni `intranet`. (Opcional: fes que el catàleg pugi sol
                amb un segon temporitzador.)
                MD, [
                $this->c('10.16.1', 'A l\'EC2: codi pujat sense la intranet',
                    'A l\'EC2 hi ha /var/www/app amb public, lib i sql, i no hi ha la carpeta intranet. Comprova-ho: `ls /var/www/app`.',
                    'verificar que només s\'ha desplegat la web pública.',
                    'la intranet es protegeix per xarxa i al núvol no hi ha xarxa interna: desplegar-la seria exposar-la.',
                    self::aws(<<<'SH'
                        for d in public lib sql; do
                          [ -d "/var/www/app/$d" ] || fail "falta /var/www/app/$d (ha d'haver-se pujat per SFTP)"
                        done
                        [ ! -e /var/www/app/intranet ] || fail "hi ha /var/www/app/intranet: la intranet no es desplega a AWS"
                        msg "public, lib i sql pujats; sense intranet"
                        SH), ['executor' => Executor::Agent],
                ),
                $this->c('10.16.2', 'A l\'EC2: configuració i base de dades pròpies',
                    'L\'EC2 té el seu config.php (no copiat de l\'aula, no llegible per a tothom) i la base de dades app amb l\'usuari app. Comprova-ho: `ls -l /var/www/app/config` i `SHOW DATABASES`.',
                    'verificar que els secrets de l\'EC2 s\'han creat a l\'EC2.',
                    'la configuració i la contrasenya de la base de dades no es pugen: qui roba el codi de l\'aula no ha de tenir la contrasenya del núvol.',
                    self::aws(<<<'SH'
                        f=/var/www/app/config/config.php
                        [ -f "$f" ] || fail "falta $f (es crea a l'EC2)"
                        m=$(stat -c %a "$f"); [ $((8#$m & 7)) -eq 0 ] || fail "$f té mode $m (ha de ser 640 o més estricte)"
                        mysql -N -B -e "SHOW DATABASES LIKE 'app'" 2>/dev/null | grep -qx app || fail "no existeix la base de dades app"
                        mysql -N -B -e "SHOW TABLES FROM app" 2>/dev/null | grep -qx descarregues || fail "la base app no té l'esquema (sql/schema.sql)"
                        msg "config.php (mode $m) i base de dades app"
                        SH), ['executor' => Executor::Agent],
                ),
                $this->c('10.16.3', 'A l\'EC2: el catàleg és de lectura per al servei web',
                    'A l\'EC2 hi ha documents a /srv/cataleg i el servei web no els pot modificar. Comprova-ho: `ls -l /srv/cataleg`.',
                    'verificar que el servidor al núvol també té una còpia de només lectura.',
                    'la regla de l\'empresa («el servidor web no modifica mai el catàleg») val igual al núvol.',
                    self::aws(<<<'SH'
                        [ -d /srv/cataleg ] || fail "no existeix /srv/cataleg"
                        n=$(find /srv/cataleg -type f | wc -l); [ "$n" -ge 1 ] || fail "/srv/cataleg és buida (puja el catàleg)"
                        runuser -u www-data -- test -r /srv/cataleg || fail "www-data no pot llegir /srv/cataleg"
                        runuser -u www-data -- test -w /srv/cataleg && fail "www-data pot escriure a /srv/cataleg"
                        w=$(runuser -u www-data -- find /srv/cataleg -writable 2>/dev/null | head -2 | tr '\n' ' ')
                        [ -z "$w" ] || fail "www-data pot modificar: $w"
                        msg "$n documents a /srv/cataleg, de lectura per a www-data"
                        SH), ['executor' => Executor::Agent],
                ),
                $this->c('10.16.4', 'La web pública funciona a AWS',
                    'https://<usuari>-aws.julioantonio.cat/cataleg.php mostra el catàleg amb enllaços de descàrrega. Comprova-ho amb el navegador.',
                    'verificar RF10: el mateix portal al núvol amb el mateix catàleg.',
                    'és el que la direcció vol comparar: la mateixa aplicació en dos entorns diferents.',
                    self::loc(<<<'SH'
                        b=$(curl -sk -L -m 15 "http://$AWS/cataleg.php" 2>&1) || fail "$AWS/cataleg.php no respon: ${b:0:120}"
                        grep -q 'descarrega.php?f=' <<<"$b" || fail "cataleg.php a $AWS no mostra cap document"
                        msg "$AWS mostra el catàleg"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.16.5', 'La intranet i l\'administració no existeixen a AWS',
                    'A AWS no es serveixen /admin, /intranet, /config ni /sql (ni 200 ni 401). Comprova-ho amb `curl -I`.',
                    'verificar la matriu d\'accés: al núvol només hi ha la web pública.',
                    'qui desplega a Internet ha de tenir clar què no s\'hi posa: la intranet «protegida» per xarxa no té xarxa que la protegeixi.',
                    self::loc(<<<'SH'
                        for p in /admin/ /intranet/ /config/config.php /lib/db.php /sql/schema.sql; do
                          c=$(curl -sk -L -m 15 -o /dev/null -w '%{http_code}' "http://$AWS$p" 2>/dev/null)
                          case $c in 200|401) fail "$AWS$p respon $c (no s'ha de servir a AWS)" ;; esac
                        done
                        msg "a $AWS no hi ha intranet, administració ni fitxers interns"
                        SH), ['timeout' => 60],
                ),
                $this->c('10.16.6', 'El catàleg d\'AWS conté tots els documents de l\'aula',
                    'Cada document de /srv/cataleg de l\'aula surt a la web d\'AWS. Si n\'afegeixes un, torna a pujar el catàleg i espera el resultat. Comprova-ho amb el navegador.',
                    'verificar que el catàleg arriba a AWS tal com l\'aula el té.',
                    'el núvol només té el que l\'aula li puja: si una còpia no s\'actualitza, el web d\'AWS mostra un catàleg vell sense que ningú ho avisi.',
                    self::ssh(<<<'SH'
                        n=0
                        b=$(curl -sk -L -m 15 "http://$AWS/cataleg.php" 2>&1) || fail "$AWS/cataleg.php no respon: ${b:0:120}"
                        while IFS= read -r f; do
                          n=$((n + 1))
                          grep -qF -- "$f" <<<"$b" || fail "$f és a l'aula però no a $AWS (torna a pujar el catàleg)"
                        done < <(ls -1A /srv/cataleg | grep -E '^[A-Za-z0-9._-]+$')
                        [ "$n" -ge 1 ] || fail "/srv/cataleg de l'aula és buida"
                        msg "els $n documents de l'aula són a $AWS"
                        SH), ['executor' => Executor::Ssh, 'timeout' => 40],
                ),
                $this->c('10.16.7', 'Opcional: el catàleg puja sol',
                    'Hi ha un temporitzador (puja-cataleg.timer) actiu que puja el catàleg a AWS sense intervenció.',
                    'comprovar l\'automatització de la pujada.',
                    'automatitzar és còmode, però deixa una clau sense frase de pas en un servidor: cal poder explicar si és acceptable.',
                    self::ssh(<<<'SH'
                        systemctl is-active --quiet puja-cataleg.timer || fail "puja-cataleg.timer no està actiu"
                        msg "puja-cataleg.timer actiu"
                        SH), ['executor' => Executor::Ssh, 'tipus' => CheckKind::Optional],
                ),
            ]],

            ['Pas 17 · HTTPS a AWS amb HTTP-01', 2, <<<'MD'
                Emet el certificat de l'EC2 amb Let's Encrypt i el repte HTTP-01, activa HTTPS amb
                redirecció i comprova la renovació amb `--dry-run`. Explica per què aquí s'ha pogut fer amb
                HTTP-01 i què passa si tanques el port 80.
                MD, [
                $this->c('10.17.1', 'curl valida el certificat d\'AWS',
                    'curl obre https://<usuari>-aws.julioantonio.cat sense -k i sense queixes. Comprova-ho: `curl -sS https://<usuari>-aws.julioantonio.cat`.',
                    'verificar que el certificat és de confiança per a un client que valida.',
                    'a Internet no hi ha cap aula que s\'hi pugui acostumar: el certificat ha de ser de confiança per a tothom.',
                    self::loc(<<<'SH'
                        err=$(curl -sS -m 15 -o /dev/null "https://$AWS/" 2>&1) || fail "curl sense -k no valida el certificat de $AWS: ${err:0:140}"
                        msg "curl valida el certificat de $AWS"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.17.2', 'Certificat de Let\'s Encrypt de producció',
                    'El certificat servit per AWS l\'ha emès Let\'s Encrypt (no l\'staging) i no caduca en 14 dies. Comprova-ho: `openssl s_client -connect <usuari>-aws.julioantonio.cat:443`.',
                    'verificar emissor i vigència.',
                    'un certificat de staging o a punt de caducar és un avís al visitant.',
                    self::loc(<<<'SH'
                        c=$(echo | openssl s_client -connect "$AWS:443" -servername "$AWS" 2>/dev/null | openssl x509 -noout -issuer -enddate -checkend 1209600 2>/dev/null)
                        [ -n "$c" ] || fail "$AWS:443 no serveix cap certificat"
                        iss=$(sed -n 's/^issuer=//p' <<<"$c")
                        grep -qiE 'STAGING|Fake' <<<"$iss" && fail "és el certificat d'staging ($iss)"
                        grep -qi "Let's Encrypt" <<<"$iss" || fail "l'emissor no és Let's Encrypt ($iss)"
                        grep -q 'will not expire' <<<"$c" || fail "el certificat caduca aviat o ja ha caducat"
                        msg "emès per $iss"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.17.3', 'HTTP redirigeix a HTTPS a AWS',
                    'El port 80 d\'AWS redirigeix a https://. Comprova-ho: `curl -I http://<usuari>-aws.julioantonio.cat`.',
                    'verificar la redirecció a AWS.',
                    'la mateixa raó que a l\'aula: ningú no ha de quedar-se a HTTP sense voler. I el 80 s\'ha de deixar obert per a HTTP-01.',
                    self::loc(<<<'SH'
                        h=$(curl -sI -m 10 "http://$AWS/" | tr -d '\r') || fail "$AWS:80 no respon"
                        grep -qE '^HTTP/[0-9.]+ (301|302|307|308)' <<<"$h" || fail "http://$AWS no redirigeix (respon: $(head -1 <<<"$h"))"
                        grep -qiE '^Location: https://' <<<"$h" || fail "la redirecció no va a https://"
                        msg "http://$AWS redirigeix a HTTPS"
                        SH), ['timeout' => 30],
                ),
                $this->c('10.17.4', 'A l\'EC2: renovació automàtica amb HTTP-01',
                    'A l\'EC2, certbot té el temporitzador actiu i el certificat es renova amb un autenticador HTTP-01 (apache, webroot o standalone). Comprova-ho: `systemctl list-timers | grep certbot`, `ls /etc/letsencrypt/renewal`.',
                    'verificar la renovació automàtica a AWS.',
                    'la diferència amb l\'aula és la raó d\'aquest pas: a AWS el 80 és públic, i HTTP-01 és el repte més simple.',
                    self::aws(<<<'SH'
                        systemctl is-active --quiet certbot.timer || fail "certbot.timer no està actiu"
                        f=$(ls /etc/letsencrypt/renewal/*.conf 2>/dev/null | head -1)
                        [ -n "$f" ] || fail "no hi ha cap certificat gestionat per certbot"
                        a=$(grep -E '^authenticator' "$f" | head -1 | cut -d= -f2 | tr -d ' ')
                        case $a in apache|webroot|nginx|standalone) ;; *) fail "l'autenticador és «${a:-res}» (ha de ser HTTP-01: apache, webroot o standalone)" ;; esac
                        msg "renovació amb l'autenticador $a"
                        SH), ['executor' => Executor::Agent],
                ),
            ]],

            ['Pas 19 · Fluxos i inventari de ports', 2, <<<'MD'
                Revisa la taula de fluxos i documenta, per a cada flux, el port i la regla que el permet i
                com l'has provat. Per a cada flux, demostra que un trànsit semblant no permès (F7) queda
                denegat. Repeteix l'inventari de ports del pas 2 i justifica cada port nou amb el requisit
                que el demana.
                MD, [
                $this->c('10.19.1', 'Inventari de ports de l\'LXC: només SSH, HTTP i HTTPS',
                    'L\'LXC web només escolta a la xarxa pels ports 22, 80 i 443 (la resta, només a 127.0.0.1). Comprova-ho: `ss -tlnp`.',
                    'verificar que no hi ha cap servei exposat que no sigui a la taula de fluxos.',
                    'cada port obert és un servei que s\'ha de mantenir i justificar: és la regla de l\'empresa «cap servei exposat que no sigui a la taula de fluxos».',
                    self::ssh(<<<'SH'
                        bad=$(sudo -n ss -Hltn 2>/dev/null | awk '{print $4}' | grep -vE '^(127\.|\[::1\])' | grep -vE ':(22|80|443)$' | sort -u | tr '\n' ' ')
                        [ -z "$bad" ] || fail "ports exposats que no són a la taula de fluxos: $bad"
                        msg "només escolten a la xarxa 22, 80 i 443"
                        SH), ['executor' => Executor::Ssh],
                ),
                $this->c('10.19.2', 'Trànsit no permès (F7): la resta de ports de l\'LXC, denegats',
                    'Des de l\'aula no s\'arriba a cap altre port de l\'LXC (3306, 21, 25, 445, 8080, 8443). Comprova-ho: `nc -vz 10.X.0.21 3306`.',
                    'verificar la política DROP davant de trànsit semblant als fluxos permesos.',
                    'els fluxos són l\'excepció, no la norma: tot el que no està escrit s\'ha de poder demostrar que està tallat.',
                    self::loc(<<<'SH'
                        for p in 3306 21 25 445 8080 8443; do
                          nc -z -w 2 "$HE_IP_WEB" "$p" 2>/dev/null && fail "des de l'aula s'arriba a $HE_IP_WEB:$p (F7 ha de denegar-ho)"
                        done
                        msg "des de l'aula no s'arriba a cap port no permès de $HE_IP_WEB"
                        SH), ['timeout' => 30],
                ),
            ]],
        ];
    }

    // ── Codi comú ────────────────────────────────────────────────────────────────────────────

    /** Check que s'executa a HeimdEye. */
    private static function loc(string $body): string
    {
        return self::BASE."\n".$body;
    }

    /** Check que s'executa a l'LXC web (grup vm_web); defineix la capçalera obligatòria. */
    private static function ssh(string $body): string
    {
        return "# hosts: vm_web\n".self::BASE."\nSUDO='sudo -n'\n".self::APCFG."\n".$body;
    }

    /** Check que corre a l'EC2 de l'alumne, per l'agent registrat amb el rol «aws». */
    private static function aws(string $body): string
    {
        return "# hosts: aws\n".self::BASE."\nSUDO=''\n".self::APCFG."\n".$body;
    }

    /** Opcions i funció per fer sftp a l'EC2 amb la clau de desplegament (des de l'LXC web, com a root). */
    private const SFTP = <<<'SH'
        IP=$(getent ahostsv4 "$AWS" | awk 'NR==1 {print $1}')
        [ -n "$IP" ] || fail "$AWS no resol (ja has passat l'adreça elàstica al professor?)"
        SSHO=(-o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8)
        sftp_deploy() { printf 'pwd\n' | sudo -n sftp -i /root/.ssh/deploy_aws "${SSHO[@]}" -b - "deploy@$IP" 2>&1; }
        SH;

    /** @return array<string, mixed> */
    private function dns(string $id, string $name, string $expected, string $what): array
    {
        return $this->c($id, "$name resol a $what",
            "El DNS de l'aula (192.168.20.11) resol $name.<zona> a $what. Comprova-ho: `dig $name.<zona> @192.168.20.11`.",
            "comprovar que el nom $name és a la zona i apunta on toca.",
            'els noms són el que decideix a quin lloc virtual va cada petició; amb la IP sola, Apache no sap qui demana què.',
            self::loc(<<<SH
                got=\$(dig +short +time=3 +tries=1 A "$name.\$Z" @"\$HE_IP_DNS_AULA" 2>/dev/null | grep -E '^[0-9.]+\$' | tail -1)
                [ "\$got" = "\$$expected" ] || fail "$name.\$Z resol a '\${got:-res}' (ha de ser \$$expected)"
                msg "$name.\$Z -> \$got"
                SH),
            ['timeout' => 30],
        );
    }

    /** Check d'emissor: el certificat servit pel lloc és de Let's Encrypt de producció. */
    private static function le(string $name): string
    {
        return <<<SH
            iss=\$(echo | openssl s_client -connect 127.0.0.1:443 -servername "$name.\$Z" 2>/dev/null | openssl x509 -noout -issuer 2>/dev/null)
            [ -n "\$iss" ] || fail "$name.\$Z no serveix cap certificat"
            grep -qiE 'STAGING|Fake' <<<"\$iss" && fail "$name.\$Z fa servir el certificat d'staging (\$iss)"
            grep -qi "Let's Encrypt" <<<"\$iss" || fail "$name.\$Z no té un certificat de Let's Encrypt (\$iss)"
            msg "$name.\$Z: \$iss"
            SH;
    }

    /**
     * Un check: id <bloc>.<pas>.<n>, textos per a l'alumne, codi i opcions.
     *
     * @param  array{executor?: Executor, tipus?: CheckKind, timeout?: int, parametres?: list<array<string, mixed>>}  $o
     * @return array<string, mixed>
     */
    private function c(string $id, string $name, string $description, string $objective, string $justification, string $code, array $o = []): array
    {
        return [
            'nom' => "$id $name",
            'descripcio' => $description,
            'info' => "**Objectiu del test:** {$objective}\n\n**Justificació:** {$justification}\n",
            'codi' => $code,
            'executor' => $o['executor'] ?? Executor::Local,
            'tipus' => $o['tipus'] ?? CheckKind::Required,
            'temps_max_seg' => $o['timeout'] ?? 20,
            'parametres' => $o['parametres'] ?? null,
        ];
    }

    /** @param  list<array<string, mixed>>  $checks */
    private function task(Block $block, int $position, string $title, int $week, string $statement, array $checks): void
    {
        $task = $block->tasks()->updateOrCreate(['titol' => $title], ['enunciat' => $statement, 'posicio' => $position]);

        [$from, $until] = $week === 1
            ? ['2026-11-16', '2026-11-23 23:59:59']
            : ['2026-11-23', '2026-12-03 23:59:59'];

        foreach ($checks as $i => $def) {
            $check = $task->checks()->updateOrCreate(
                ['nom' => $def['nom']],
                [
                    'descripcio' => $def['descripcio'],
                    'info' => $def['info'],
                    'executor' => $def['executor'],
                    'tipus' => $def['tipus'],
                    'punts' => 1,
                    'actiu_des' => $from,
                    'data_limit' => $until,
                    'posicio' => $i + 1,
                ],
            );

            if (! $check->versions()->exists()) {
                $check->versions()->create([
                    'versio' => 1,
                    'codi' => $def['codi'],
                    'parametres' => $def['parametres'],
                    'temps_max_seg' => $def['temps_max_seg'],
                ]);
            }
        }
    }
}
