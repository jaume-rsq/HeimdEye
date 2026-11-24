<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Action;
use App\Models\Block;
use App\Models\Incident;
use App\Models\Module;
use App\Models\Task;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Bloc 10, pas 21: la sessió d'incidències del divendres 27/11 (una hora). Dotze avaries (I01-I12),
 * en tres famílies (connectivitat, certificats, llocs web), del catàleg de la guia del professor (10.3).
 * Cap és una avaria de codi.
 *
 * Una incidència de HeimdEye per avaria, perquè cada una té el seu tiquet: el que diria un usuari,
 * mai la causa. Es llancen des d'Incidències, tres per alumne (una de cada família) i diferents de
 * les del company del costat. Cada incidència té:
 *  - una acció d'avaria (es_incidencia) amb el paràmetre AVARIA: una per a l'LXC web (ssh, grup
 *    vm_web, amb sudo -n) i una per al router MikroTik (local, que hi entra per SSH amb l'accés del mòdul);
 *  - un check de resolució que mira l'efecte i des d'on es veia el símptoma (no dona punts i no
 *    corre a la nit: només resol la incidència);
 *  - una acció de reparació, per a tu: restaurar-la no compta com a resolució.
 *
 * Limitacions respecte de la guia: HeimdEye no té pc-ubuntu ni pc-convidat com a equips; per això
 * I02 es comprova al router (no hi ha cap regla drop activa de la VLAN 101 cap al web pel 443) i I10
 * a la configuració de la intranet (el lloc inclou l'ACL), no des de les VLAN 101 i 102.
 *
 * Abans de la sessió: instantània de l'LXC web de cada alumne (pct snapshot <vmid> abans-incidencies)
 * i /export file=abans-incidencies al router. Les avaries guarden a /root/.heimdeye-incidents/ el que
 * cal per desfer-se (el valor original d'una línia o d'un permís).
 *
 * Cal haver executat abans Block10WebSeeder (el bloc, el tipus d'equip web i les tasques). Crea la
 * tasca «Pas 21 · Sessió d'incidències» si no hi és i s'hi guarda els checks de resolució. Es pot
 * tornar a executar: esborra les incidències, les accions i els checks que va crear i els torna a crear.
 *
 * S'executa amb: php artisan db:seed --class=Block10IncidentsSeeder --force
 */
class Block10IncidentsSeeder extends Seeder
{
    public const TASK = 'Pas 21 · Sessió d\'incidències';

    private const ACTION_WEB = 'Incidències web: avaria a l\'LXC';

    private const ACTION_WEB_REPAIR = 'Incidències web: reparar l\'LXC';

    private const ACTION_ROUTER = 'Incidències web: avaria al router';

    private const ACTION_ROUTER_REPAIR = 'Incidències web: reparar el router';

    private const BASE = <<<'SH'
        Z="$HE_NOM_DNS.$HE_NOM_DOMINI"
        msg() { echo "HEIMDEYE_MSG: $*"; }
        fail() { msg "$@"; exit 1; }
        SH;

    /** Peticions a l'LXC des de dins (no depenen del DNAT). */
    private const WEB = <<<'SH'
        web() { local h=$1 p=$2; shift 2; curl -sk -L -m 10 --resolve "$h:80:127.0.0.1" --resolve "$h:443:127.0.0.1" "$@" "http://$h$p"; }
        code() { web "$1" "$2" -o /dev/null -w '%{http_code}'; }
        SH;

    /** Petició a www des d'HeimdEye, pel DNAT (adreça d'aula del router). */
    private const VIA_DNAT = <<<'SH'
        dnat() { local p=$1; shift; curl -sk -L -m 10 --resolve "www.$Z:80:$HE_IP_ROUTER" --resolve "www.$Z:443:$HE_IP_ROUTER" "$@" "http://www.$Z$p"; }
        SH;

    /** Accés al router com al Bloc 8: només té sentit si és un MikroTik. */
    private const ROUTER = <<<'SH'
        opts=(-n -o BatchMode=yes -o ConnectTimeout=5 $HE_SSH_OPTS)
        [ -n "$HE_SSH_KEY" ] && opts+=(-i "$HE_SSH_KEY")
        r() { ssh "${opts[@]}" "$HE_SSH_USER@$HE_IP_ROUTER" "$@"; }
        so=$(r ':put routeros' 2>/dev/null); rc=$?
        [ $rc -eq 255 ] && fail "HeimdEye no pot entrar al router ($HE_IP_ROUTER)"
        [ "$(echo "$so" | tr -d '\r')" = routeros ] || fail "aquesta avaria és només per al MikroTik (el router és Linux)"
        SH;

    /** Funcions que s'executen com a root a l'LXC (sudo -n bash -c "$(declare -f ...)"). */
    private const ROOT_FUNCS = <<<'SH'
        D=/root/.heimdeye-incidents
        SITES=/etc/apache2/sites-available
        # Guarda (una sola vegada) una línia o un permís per poder-los restaurar.
        keep() { mkdir -p /root/.heimdeye-incidents; [ -s "/root/.heimdeye-incidents/$1" ] || cat > "/root/.heimdeye-incidents/$1"; }
        i01() { sed -i -E 's/^([[:space:]]*)Listen (80|443)$/\1Listen 127.0.0.1:\2/' /etc/apache2/ports.conf && systemctl restart apache2; }
        r01() { sed -i -E 's/^([[:space:]]*)Listen 127\.0\.0\.1:(80|443)$/\1Listen \2/' /etc/apache2/ports.conf && systemctl restart apache2; }
        i04() { systemctl disable --now apache2; }
        r04() { systemctl enable --now apache2; }
        i05() { sed -i 's#/etc/letsencrypt/live/wildcard/fullchain.pem#/etc/ssl/empresa/www-autosignat.crt#; s#/etc/letsencrypt/live/wildcard/privkey.pem#/etc/ssl/empresa/www-autosignat.key#' /etc/apache2/sites-available/www.conf && systemctl reload apache2; }
        r05() { sed -i 's#/etc/ssl/empresa/www-autosignat.crt#/etc/letsencrypt/live/wildcard/fullchain.pem#; s#/etc/ssl/empresa/www-autosignat.key#/etc/letsencrypt/live/wildcard/privkey.pem#' /etc/apache2/sites-available/www.conf && systemctl reload apache2; }
        i06() {
          f=/etc/apache2/sites-available/intranet.conf
          grep -m1 -E '^[[:space:]]*SSLCertificateKeyFile[[:space:]]' "$f" | keep I06.linia
          openssl genrsa -out /etc/ssl/empresa/intranet-nova.key 2048 2>/dev/null
          sed -i 's#SSLCertificateKeyFile .*#SSLCertificateKeyFile /etc/ssl/empresa/intranet-nova.key#' "$f"
          systemctl restart apache2 || true
        }
        r06() {
          f=/etc/apache2/sites-available/intranet.conf
          [ -s /root/.heimdeye-incidents/I06.linia ] || { echo "no tinc guardada la línia original de SSLCertificateKeyFile"; return 1; }
          k=$(awk '{print $2}' /root/.heimdeye-incidents/I06.linia)
          sed -i "s#SSLCertificateKeyFile .*#SSLCertificateKeyFile $k#" "$f"
          systemctl restart apache2
        }
        i07() { f=/usr/local/sbin/acmedns-auth.sh; [ "$(stat -c %a "$f")" = 0 ] || stat -c %a "$f" | keep I07.mode; chmod 000 "$f"; }
        r07() { chmod "$(cat /root/.heimdeye-incidents/I07.mode 2>/dev/null || echo 750)" /usr/local/sbin/acmedns-auth.sh; }
        i08() { sed -i 's/ServerName www\./ServerName wwww./' /etc/apache2/sites-available/www.conf && systemctl reload apache2; }
        r08() { sed -i 's/ServerName wwww\./ServerName www./' /etc/apache2/sites-available/www.conf && systemctl reload apache2; }
        i09() { sed -i 's#DocumentRoot /var/www/app/public#DocumentRoot /var/www/app/intranet#' /etc/apache2/sites-available/www.conf && systemctl reload apache2; }
        r09() { sed -i 's#DocumentRoot /var/www/app/intranet#DocumentRoot /var/www/app/public#' /etc/apache2/sites-available/www.conf && systemctl reload apache2; }
        i10() {
          f=/etc/apache2/sites-available/intranet.conf
          grep -m1 'snippets/intranet-acl.conf' "$f" | keep I10.linia
          sed -i '/snippets\/intranet-acl.conf/d' "$f" && systemctl reload apache2
        }
        r10() {
          f=/etc/apache2/sites-available/intranet.conf
          [ -s /root/.heimdeye-incidents/I10.linia ] || { echo "no tinc guardada la línia original de l'ACL"; return 1; }
          grep -q 'snippets/intranet-acl.conf' "$f" || sed -i "/ServerName intranet\./r /root/.heimdeye-incidents/I10.linia" "$f"
          systemctl reload apache2
        }
        i11() { systemctl disable --now "php$(ls /etc/php | sort -V | tail -1)-fpm"; }
        r11() { systemctl enable --now "php$(ls /etc/php | sort -V | tail -1)-fpm"; }
        i12() { f=/var/www/app/public; [ "$(stat -c %a "$f")" = 700 ] || stat -c %a "$f" | keep I12.mode; chmod 700 "$f"; }
        r12() { chmod "$(cat /root/.heimdeye-incidents/I12.mode 2>/dev/null || echo 755)" /var/www/app/public; }
        SH;

    /**
     * El catàleg d'avaries. Camps: codi, família, equip (web = LXC, router), títol, tiquet (el que
     * llegeix l'alumne), què es veu, causa i com es troba (notes del professor), check [nom, descripció,
     * executor, codi].
     *
     * @return list<array<string, mixed>>
     */
    private static function catalogue(): array
    {
        $cataleg = fn (string $what) => [
            "Incidència {$what}: el web respon pel DNAT",
            'https://www.<zona>/cataleg.php respon 200 vist des de HeimdEye, pel DNAT del router.',
            Executor::Local,
            self::BASE."\n".self::VIA_DNAT."\n".<<<'SH'
                c=$(dnat /cataleg.php -o /dev/null -w '%{http_code}')
                [ "$c" = 200 ] || fail "https://www.$Z/cataleg.php respon '${c:-res}' per $HE_IP_ROUTER (el DNAT)"
                msg "https://www.$Z/cataleg.php respon 200 pel DNAT"
                SH,
        ];

        return [
            ['I01', 'Connectivitat', 'web', 'Apache només escolta a localhost',
                'El web no s\'obre des de cap equip, però el servidor està engegat.',
                'connexió rebutjada de seguida; curl des del mateix servidor funciona',
                'Apache escolta només a 127.0.0.1 (ports.conf)', 'ss -tlnp', $cataleg('I01')],
            ['I02', 'Connectivitat', 'router', 'Regla que talla l\'HTTPS del personal',
                'Des dels ordinadors del personal no s\'obre ni el web ni la intranet. Els convidats diuen que a ells els va bé.',
                'temps esgotat al 443 des de la VLAN 101; des de la VLAN 102 i des de l\'aula, bé',
                'una regla drop nova al principi de forward', 'nc -vz des de dues xarxes; /ip firewall filter print i els comptadors',
                ['Incidència I02: cap regla drop de la VLAN 101 cap al web',
                    'Al router no hi ha cap regla drop activa de la VLAN 101 cap al web pel port 443 (es comprova al router, no des de pc-ubuntu).',
                    Executor::Local,
                    self::BASE."\n".self::ROUTER."\n".<<<'SH'
                        n=$(r "/ip firewall filter print count-only where chain=forward action=drop protocol=tcp dst-port=443 src-address=172.$HE_X.1.0/24 dst-address=$HE_IP_WEB disabled=no" | tr -d '\r[:space:]')
                        [ "${n:-0}" = 0 ] || fail "el router té $n regla(es) drop de 172.$HE_X.1.0/24 cap a $HE_IP_WEB:443 (/ip firewall filter print)"
                        msg "cap regla drop de la VLAN 101 cap al web pel 443"
                        SH]],
            ['I03', 'Connectivitat', 'router', 'DNAT del web desactivat',
                'La intranet funciona, però el web públic no s\'obre des de cap lloc. Des de casa surt un error 502.',
                'www no respon; intranet sí; pel nom d\'Internet, 502 de Cloudflare',
                'la regla de DNAT està desactivada', '/ip firewall nat print (marca X)', $cataleg('I03')],
            ['I04', 'Connectivitat', 'web', 'Apache aturat i desactivat',
                'Ahir es va reiniciar el servidor i des de llavors no hi ha web.',
                'connexió rebutjada; cap port 80 ni 443 a ss -tlnp',
                'Apache aturat i desactivat', 'systemctl status apache2, systemctl is-enabled apache2', $cataleg('I04')],
            ['I05', 'Certificats', 'web', 'www torna al certificat autosignat',
                'El navegador avisa que el web no és segur. Ahir no ho feia. Des de casa ni s\'obre.',
                'avís a www, sortida 60 de curl; pel túnel, 502; la intranet, bé',
                'www torna a fer servir l\'autosignat', 'openssl s_client (emissor igual al subjecte), grep SSLCertificate',
                ['Incidència I05: el certificat de www es verifica',
                    'curl valida el certificat de https://www.<zona> sense -k, des de HeimdEye.',
                    Executor::Local,
                    self::BASE."\n".<<<'SH'
                        err=$(curl -sS -m 10 -o /dev/null --resolve "www.$Z:443:$HE_IP_ROUTER" "https://www.$Z/" 2>&1) || fail "curl sense -k no valida el certificat de www.$Z: ${err:0:140}"
                        msg "curl valida el certificat de www.$Z"
                        SH]],
            ['I06', 'Certificats', 'web', 'La clau de la intranet no és la del certificat',
                'Algú va tocar el certificat de la intranet i ara no funciona cap web.',
                'connexió rebutjada a tots els llocs; apache2ctl configtest diu Syntax OK',
                'la clau privada de la intranet no és la del certificat i Apache no arrenca',
                'systemctl status apache2, intranet_error.log (AH02565; va a l\'ErrorLog del lloc, no a error.log)',
                ['Incidència I06: Apache en marxa i la intranet respon',
                    'Apache està actiu i la intranet respon 200 des de l\'LXC.',
                    Executor::Ssh,
                    "# hosts: vm_web\n".self::BASE."\n".self::WEB."\n".<<<'SH'
                        systemctl is-active --quiet apache2 || fail "Apache no està actiu (systemctl status apache2)"
                        c=$(code "intranet.$Z" /); [ "$c" = 200 ] || fail "la intranet respon '$c' (n'esperava 200)"
                        msg "Apache actiu i la intranet respon 200"
                        SH]],
            ['I07', 'Certificats', 'web', 'L\'script del repte DNS-01 perd el permís',
                'Ha arribat un avís: la renovació automàtica del certificat falla. El web va bé.',
                'cap símptoma al web; certbot renew --dry-run falla',
                'l\'script del repte no té permís d\'execució', 'certbot renew --dry-run, /var/log/letsencrypt/letsencrypt.log, ls -l',
                ['Incidència I07: l\'script del repte és executable',
                    '/usr/local/sbin/acmedns-auth.sh té permís d\'execució.',
                    Executor::Ssh,
                    "# hosts: vm_web\n".self::BASE."\n".<<<'SH'
                        f=/usr/local/sbin/acmedns-auth.sh
                        sudo -n test -f "$f" || fail "no existeix $f"
                        sudo -n test -x "$f" || fail "$f no és executable (ls -l)"
                        msg "$f és executable"
                        SH]],
            ['I08', 'Llocs web', 'web', 'ServerName de www mal escrit',
                'Qui entra per www veu una pàgina que diu que no hi ha cap lloc amb aquest nom, i un avís de certificat. Des de casa surt un error 502.',
                'www cau al lloc per defecte; pel túnel, 502 (cloudflared demana www.<zona> com a Origin Server Name i rep el certificat del lloc per defecte)',
                'ServerName mal escrit a www.conf', 'apache2ctl -S',
                ['Incidència I08: www mostra la pàgina de l\'empresa',
                    'www mostra la pàgina de l\'empresa i no la del lloc per defecte, vist des de HeimdEye pel DNAT.',
                    Executor::Local,
                    self::BASE."\n".self::VIA_DNAT."\n".<<<'SH'
                        w=$(dnat / | md5sum | cut -c1-32)
                        d=$(curl -sk -L -m 10 --resolve "no-existeix.$Z:80:$HE_IP_ROUTER" --resolve "no-existeix.$Z:443:$HE_IP_ROUTER" "http://no-existeix.$Z/" | md5sum | cut -c1-32)
                        [ "$w" != "$d" ] || fail "www.$Z serveix la pàgina del lloc per defecte (apache2ctl -S)"
                        c=$(dnat /cataleg.php -o /dev/null -w '%{http_code}'); [ "$c" = 200 ] || fail "cataleg.php respon '$c'"
                        msg "www mostra la pàgina de l'empresa"
                        SH]],
            ['I09', 'Llocs web', 'web', 'www serveix la carpeta de la intranet',
                'Un client ens diu que al web públic hi veu les estadístiques de descàrregues.',
                'www i el nom d\'Internet mostren la intranet',
                'l\'arrel de documents de www apunta a intranet/', 'apache2ctl -S, grep DocumentRoot',
                ['Incidència I09: www no serveix la intranet',
                    'https://www.<zona>/ no conté «Intranet del personal» i cataleg.php respon 200, vist des de HeimdEye.',
                    Executor::Local,
                    self::BASE."\n".self::VIA_DNAT."\n".<<<'SH'
                        dnat / | grep -qi 'Intranet del personal' && fail "www.$Z mostra la intranet (DocumentRoot de www)"
                        c=$(dnat /cataleg.php -o /dev/null -w '%{http_code}'); [ "$c" = 200 ] || fail "cataleg.php respon '$c' (n'esperava 200)"
                        msg "www serveix la web pública"
                        SH]],
            ['I10', 'Llocs web', 'web', 'La intranet perd l\'ACL i l\'autenticació',
                'Un convidat ha pogut obrir la intranet. I l\'administració diu "zona protegida" a tothom.',
                'la intranet respon 200 des de la VLAN 102; /admin/ dóna 403 sense demanar usuari',
                'falta l\'Include de l\'ACL al lloc de la intranet', 'comparar el lloc amb snippets/; curl des de pc-convidat',
                ['Incidència I10: la intranet torna a tenir l\'ACL',
                    'El lloc de la intranet torna a incloure l\'ACL i l\'autenticació (snippets/intranet-acl.conf).',
                    Executor::Ssh,
                    "# hosts: vm_web\n".self::BASE."\n".<<<'SH'
                        n=0
                        for f in $(sudo -n grep -lE "^[[:space:]]*ServerName[[:space:]]+intranet\.$Z" /etc/apache2/sites-enabled/* 2>/dev/null); do
                          n=$((n + 1))
                          sudo -n grep -qE '^[[:space:]]*Include(Optional)?[[:space:]].*intranet-acl\.conf' "$f" || fail "$(basename "$f") no inclou l'ACL (snippets/intranet-acl.conf)"
                        done
                        [ "$n" -ge 1 ] || fail "no trobo el lloc de la intranet a sites-enabled"
                        msg "la intranet inclou l'ACL"
                        SH]],
            ['I11', 'Llocs web', 'web', 'PHP-FPM aturat i desactivat',
                'El web diu "Service Unavailable".',
                '503 a les pàgines .php; un fitxer estàtic respondria',
                'PHP-FPM aturat i desactivat', 'error.log, systemctl list-unit-files \'php*-fpm*\', systemctl status php8.X-fpm', $cataleg('I11')],
            ['I12', 'Llocs web', 'web', 'La carpeta pública perd el permís del grup',
                'El web públic diu "Forbidden". La intranet funciona.',
                '403 a tot www',
                'public/ sense permís per al grup www-data', 'error.log («permission denied»), ls -ld', $cataleg('I12')],
        ];
    }

    /** Codi de l'acció d'avaria o de reparació per a l'LXC. */
    private static function webAction(string $prefix): string
    {
        return "# hosts: vm_web\n".self::ROOT_FUNCS."\n".<<<SH
            n=\$(printf '%02d' "\${HE_PARAM_AVARIA:-0}" 2>/dev/null)
            case "\$n" in 01|04|05|06|07|08|09|10|11|12) ;; *) echo "AVARIA ha de ser una de les de l'LXC (1, 4-12)"; exit 1 ;; esac
            sudo -n bash -c "\$(declare -f keep {$prefix}\$n); {$prefix}\$n" || exit 1
            echo "{$prefix}\$n fet a \$(hostname)"
            SH;
    }

    /** Codi de l'acció d'avaria o de reparació per al router. */
    private static function routerAction(bool $inject): string
    {
        $b = '/ip firewall filter';
        $on = $inject
            ? [2 => "r \"$b remove [find where comment=manteniment]\"; r \"$b add chain=forward action=drop protocol=tcp src-address=172.\$HE_X.1.0/24 dst-address=\$HE_IP_WEB dst-port=443 comment=manteniment place-before=0\"",
                3 => 'r "/ip firewall nat disable [find where chain=dstnat to-addresses=$HE_IP_WEB]"']
            : [2 => "r \"$b remove [find where comment=manteniment]\"",
                3 => 'r "/ip firewall nat enable [find where chain=dstnat to-addresses=$HE_IP_WEB]"'];

        return self::BASE."\n".self::ROUTER."\n".<<<SH
            case "\${HE_PARAM_AVARIA:-0}" in
              2) {$on[2]} ;;
              3) {$on[3]} ;;
              *) fail "AVARIA ha de ser una de les del router (2 o 3)" ;;
            esac
            msg "avaria \$HE_PARAM_AVARIA al router"
            SH;
    }

    public function run(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();
        $block = Block::where('modul_id', $module->id)->where('numero', 10)->first()
            ?? throw new RuntimeException('No existeix el bloc 10: executa primer Block10WebSeeder');
        $module->hostTypes()->where('nom', 'web')->exists()
            ?: throw new RuntimeException('No existeix el tipus d\'equip web: executa primer Block10WebSeeder');

        $task = $block->tasks()->firstOrCreate(
            ['titol' => self::TASK],
            ['enunciat' => $this->statement(), 'posicio' => ($block->tasks()->max('posicio') ?? 0) + 1],
        );
        $task->update(['enunciat' => $this->statement()]);

        // Es comença de zero: les incidències, les accions i els checks de resolució d'aquesta tasca.
        Incident::where('bloc_id', $block->id)->where('tasca_id', $task->id)->delete();
        Action::where('bloc_id', $block->id)->where('tasca_id', $task->id)->delete();
        $task->checks()->delete();

        $web = $this->action($module, $block, $task, self::ACTION_WEB, 'Avaria a l\'LXC web (I01 i I04 a I12): paràmetre AVARIA = número de la incidència.', Executor::Ssh, self::webAction('i'), true, 1);
        $webRepair = $this->action($module, $block, $task, self::ACTION_WEB_REPAIR, 'Desfà l\'avaria de l\'LXC web (AVARIA = número). Per a tu: no compta com a resolució.', Executor::Ssh, self::webAction('r'), false, 2);
        $router = $this->action($module, $block, $task, self::ACTION_ROUTER, 'Avaria al router MikroTik (I02 i I03): paràmetre AVARIA = número de la incidència.', Executor::Local, self::routerAction(true), true, 3);
        $routerRepair = $this->action($module, $block, $task, self::ACTION_ROUTER_REPAIR, 'Desfà l\'avaria del router (AVARIA = número). Per a tu: no compta com a resolució.', Executor::Local, self::routerAction(false), false, 4);

        foreach (self::catalogue() as $i => [$code, $family, $where, $title, $ticket, $seen, $cause, $find, $resolution]) {
            [$checkName, $checkDescription, $executor, $checkCode] = $resolution;
            $check = $task->checks()->create([
                'nom' => $checkName, 'descripcio' => $checkDescription, 'executor' => $executor,
                'tipus' => CheckKind::Optional, 'punts' => 0, 'es_actiu' => false, 'posicio' => $i + 1,
            ]);
            $check->versions()->create(['versio' => 1, 'codi' => $checkCode, 'temps_max_seg' => 60]);

            $incident = Incident::create([
                'modul_id' => $module->id, 'bloc_id' => $block->id, 'tasca_id' => $task->id,
                'nom' => "$code · $family: $title",
                'equip' => $where === 'web' ? 'web' : 'router',
                'simptoma' => $ticket,
                'notes' => "Família: $family. Es veu: $seen. Causa: $cause. Com es troba: $find."
                    .($where === 'router' ? ' Avaria al MikroTik: restaura-la amb /import file=abans-incidencies.rsc si cal.' : ' Cal una instantània de l\'LXC (abans-incidencies).'),
                'posicio' => $i + 1,
            ]);
            $n = (string) (int) substr($code, 1);
            $incident->options()->create([
                'nom' => $title,
                'accio_id' => ($where === 'web' ? $web : $router)->id,
                'parametres' => ['AVARIA' => $n],
                'check_id' => $check->id,
                'accio_reparacio_id' => ($where === 'web' ? $webRepair : $routerRepair)->id,
                'posicio' => 1,
            ]);
        }
    }

    private function statement(): string
    {
        return <<<'MD'
            Sessió d'incidències de divendres 27/11 (una hora). Rebràs uns tiquets amb el que diria un
            usuari: mai la causa. Per cada tiquet, entrega al document quatre línies: **el símptoma**, **les
            comprovacions que has fet i en quin ordre** (seguint el camí de la petició, apartat 16 de la
            Documentació), **la causa** i **la correcció**. HeimdEye diu si està resolta; el document diu si
            l'has entesa. Una incidència resolta reiniciant-ho tot o restaurant una còpia, sense causa, no val.
            MD;
    }

    private function action(Module $module, Block $block, Task $task, string $name, string $description, Executor $executor, string $code, bool $incident, int $position): Action
    {
        $action = $module->actions()->create([
            'nom' => $name, 'bloc_id' => $block->id, 'tasca_id' => $task->id, 'descripcio' => $description,
            'executor' => $executor, 'es_incidencia' => $incident, 'posicio' => $position,
        ]);
        $action->versions()->create([
            'versio' => 1, 'temps_max_seg' => 90, 'codi' => $code,
            'parametres' => [['name' => 'AVARIA', 'label' => 'Número de la avaria', 'default' => '0']],
        ]);

        return $action;
    }
}
