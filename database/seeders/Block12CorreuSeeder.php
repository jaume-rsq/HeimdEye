<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Enums\Level;
use App\Models\Block;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Bloc 12 (unitat «Correu electrònic», 30/11-11/12/2026, 16 h): una tasca per cada pas del repte
 * que es pot comprovar, en l'ordre de l'enunciat. Els checks es numeren <bloc>.<pas>.<n>
 * (p. ex. «12.10.1 ...») perquè l'alumne i el professor vegin la progressió d'una ullada.
 * El pas 15 (incidències) no porta checks: té els seus propis seeders.
 *
 * On corre cada check:
 *  - «ssh» a l'LXC mail (grup vm_mail, 10.X.0.25): fitxers i configuració de Postfix, Dovecot i
 *    Rspamd, ports que escolten, registre de correu, clau DKIM.
 *  - «local» (HeimdEye, 192.168.20.12): el que es veu des de l'aula (DNS del central, el 25 pel
 *    DNAT del router, 587, 993, 995 i 443 de l'LXC mail, que l'alumne obre amb la regla
 *    «HeimdEye correu»).
 *
 * Els checks NO canvien res. Les sondes SMTP s'aturen al RCPT; les úniques que envien un missatge
 * són 12.12.1 (sense signar, amb From del domini) i 12.13.1 (GTUBE), i només si el servidor no
 * ho rebutja: llavors el missatge arriba a la bústia, que és el que s'avisa a l'alumne.
 *
 * Els textos per a l'alumne no revelen les trampes dels passos 10 (què autoritza l'SPF) i 12
 * (alineament de DMARC): diuen què s'ha d'aconseguir, no com.
 *
 * Els punts es mantenen un cop assolits. Actius des de dilluns 30/11 fins divendres 11/12: la
 * ronda nocturna de dijous a divendres (02:00) és l'última, perquè divendres al matí el professor
 * reconfigura els entorns (actiu_fins és un dia complet: el 12/12 ja no s'executa).
 *
 * Crea, si falten, el tipus d'equip «mail» (grup vm_mail, adreça {{ip_mail}}) i la variable de
 * mòdul ip_mail = 10.{{x}}.0.25. És idempotent: es pot tornar a executar sense duplicar res i
 * sense tocar el codi ni els resultats dels checks que ja hi són (el codi es corregeix des del
 * panell, amb una versió nova).
 *
 * Mòdul 9991, bloc 12. S'executa amb: php artisan db:seed --class=Block12CorreuSeeder --force
 */
class Block12CorreuSeeder extends Seeder
{
    private const FROM = '2026-11-30';

    private const UNTIL = '2026-12-11';

    /** Última ronda automàtica: nit de dijous a divendres, a les 02:00. */
    private const LIMIT = '2026-12-11 02:00:00';

    /** Noms i funcions comuns als checks bash. */
    private const BASE = <<<'SH'
        Z="$HE_NOM_DNS.$HE_NOM_DOMINI"      # zona de l'alumne
        AULA="$HE_IP_DNS_AULA"              # DNS central de l'aula
        msg() { echo "HEIMDEYE_MSG: $*"; }
        fail() { msg "$@"; exit 1; }
        SH;

    /** Consulta al DNS central (el que veuen els companys). */
    private const DIG = <<<'SH'
        q() { dig +short +time=3 +tries=1 "$@" @"$AULA" 2>/dev/null; }
        SH;

    /** Funcions i dades de les sondes SMTP escrites en Python (smtplib, sense autenticar). */
    private const PYSMTP = <<<'PY'
        import os, sys, smtplib, subprocess, ssl, socket

        Z = os.environ['HE_NOM_DNS'] + '.' + os.environ['HE_NOM_DOMINI']
        AULA = os.environ['HE_IP_DNS_AULA']
        MAIL_IP = os.environ.get('HE_IP_MAIL', '')
        HELO = 'heimdeye.julioantonio.cat'


        def msg(t):
            print('HEIMDEYE_MSG: ' + t)


        def fail(t):
            msg(t)
            sys.exit(1)


        def dig(kind, name):
            r = subprocess.run(['dig', '+short', '+time=3', '+tries=1', kind, name, '@' + AULA], capture_output=True, text=True)
            return [l.strip() for l in r.stdout.splitlines() if l.strip()]


        def mx_ip():
            """Adreça on els companys connecten (mx.<zona>, la del router)."""
            ips = [i for i in dig('A', 'mx.' + Z) if i.replace('.', '').isdigit()]
            if not ips:
                fail('mx.%s no resol al DNS de l\'aula (192.168.20.11)' % Z)
            return ips[0]


        def connect(ip, port, what):
            try:
                return smtplib.SMTP(ip, port, local_hostname=HELO, timeout=15)
            except (OSError, smtplib.SMTPException) as e:
                fail('%s (%s:%d) no respon: %s' % (what, ip, port, str(e)[:100]))
        PY;

    public function run(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();

        // Equip nou del bloc: l'LXC mail (10.X.0.25), accessible com els altres Linux (usuari jaume).
        $module->variables()->updateOrCreate(['nivell' => Level::Module, 'clau' => 'ip_mail'], ['valor' => '10.{{x}}.0.25']);
        $module->hostTypes()->updateOrCreate(['nom' => 'mail'], [
            'grup_ansible' => 'vm_mail', 'es_unic' => false, 'nom_host' => 'mail{{num_taula}}', 'adreca' => '{{ip_mail}}',
            'posicio' => ($module->hostTypes()->max('posicio') ?? 0) + 1,
        ]);

        $block = Block::updateOrCreate(
            ['modul_id' => $module->id, 'numero' => 12],
            ['titol' => 'Correu electrònic', 'data_inici' => self::FROM, 'data_fi' => self::UNTIL, 'posicio' => 12],
        );

        foreach ($this->tasks() as $position => [$title, $statement, $checks]) {
            $this->task($block, $position + 1, $title, $statement, $checks);
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: list<array<string, mixed>>}> títol, enunciat, checks
     */
    private function tasks(): array
    {
        return [
            ['Pas 1 · Punt de partida', <<<'MD'
                Abans de res, comprova que la infraestructura dels blocs anteriors funciona: els veïns
                OSPF són en estat Full, la zona `<usuari>.smix2.julioantonio.cat` respon des de l'aula,
                l'hora està sincronitzada i l'última regla de forward és el DROP. Sense això, els
                companys no et trobaran el servidor de correu.
                MD, [
                $this->c('12.1.1', 'La teva zona respon des de l\'aula',
                    'El DNS de l\'aula (192.168.20.11) retorna els NS de la teva zona i ns1 hi és. Comprova-ho: `dig NS <usuari>.smix2.julioantonio.cat @192.168.20.11`.',
                    'comprovar que la delegació de la teva zona funciona des de l\'aula.',
                    'els servidors de correu dels companys troben el teu MX preguntant pel DNS central. Si la zona no respon, cap correu no et pot arribar i qualsevol error posterior s\'hi pot confondre.',
                    self::loc(self::DIG, <<<'SH'
                        ns=$(q NS "$Z")
                        [ -n "$ns" ] || fail "el DNS de l'aula ($AULA) no retorna els NS de $Z"
                        grep -qi "^ns1\.$Z\.\?$" <<<"$ns" || fail "falta ns1.$Z entre els NS de $Z (hi ha: $(echo $ns | tr '\n' ' '))"
                        soa=$(q SOA "$Z")
                        [ -n "$soa" ] || fail "la zona $Z no respon el SOA des de l'aula"
                        msg "NS de $Z: $(echo $ns | tr '\n' ' ')"
                        SH),
                    ['timeout' => 30],
                ),
            ]],

            ['Pas 2 · L\'LXC mail i l\'accés de HeimdEye', <<<'MD'
                Crea l'LXC `mail` a la DMZ (VLAN 200, `10.X.0.25`, Ubuntu 26.04, 2 nuclis, 2 GB de RAM i
                12 GB de disc). Prepara'l com els servidors anteriors perquè HeimdEye hi entri: usuari
                `jaume`, clau pública de HeimdEye i `sudo` sense contrasenya. Obre l'accés amb la regla
                «HeimdEye correu» abans del DROP final. Registra'l a phpIPAM i inventaria els ports que escolten.
                MD, [
                $this->c('12.2.1', 'HeimdEye entra a mail',
                    'HeimdEye entra a l\'LXC mail (10.X.0.25) per SSH com a `jaume`, amb la seva clau, i hi té `sudo` sense contrasenya. Comprova-ho: `ssh jaume@10.X.0.25 sudo -n true`.',
                    'comprovar que HeimdEye pot administrar el servidor de correu: usuari jaume, clau pública i sudo sense contrasenya.',
                    'tots els checks que miren dins del servidor (Postfix, Dovecot, Rspamd, claus) depenen d\'aquest accés; sense ell no es pot verificar res més ni resoldre una incidència.',
                    self::ssh(<<<'SH'
                        $SUDO true || fail "jaume no té sudo sense contrasenya a $(hostname)"
                        msg "HeimdEye entra a $(hostname) i té sudo"
                        SH),
                ),
                $this->c('12.2.2', 'El sistema es diu mail.<zona>',
                    'El nom del sistema de l\'LXC és `mail.<usuari>.smix2.julioantonio.cat`. Comprova-ho: `hostname -f`.',
                    'comprovar que el servidor té el nom que els clients i els altres servidors esperen.',
                    'el nom del sistema surt a la salutació SMTP i als certificats i capçaleres; un nom diferent al DNS provoca avisos i rebuigs en els servidors dels companys.',
                    self::ssh(<<<'SH'
                        h=$(hostname -f 2>/dev/null)
                        [ "$h" = "mail.$Z" ] || fail "el nom del sistema és '${h:-res}' i hauria de ser mail.$Z"
                        msg "nom del sistema: $h"
                        SH),
                ),
            ]],

            ['Pas 3 · Noms, DNAT i certificat', <<<'MD'
                Publica els noms (`mail`, `mx`, `webmail` i el MX del domini), fes el DNAT del port 25
                cap a l'LXC, obre els fluxos F1, F2 i F6 i instal·la un certificat que cobreixi els tres noms.
                Demostra que el MX del teu domini es resol a través de 192.168.20.11.
                MD, [
                $this->c('12.3.1', 'Els noms de correu estan publicats',
                    'El DNS de l\'aula resol el MX del domini cap a `mx.<zona>`, `mx` i `mail` tenen el seu registre A i `webmail` és un CNAME cap a `mail`. Comprova-ho: `dig MX <zona> @192.168.20.11`.',
                    'comprovar que el domini de correu té MX, que mx apunta a l\'adreça d\'aula del router i mail a l\'LXC, i que webmail és un àlies de mail.',
                    'un servidor remot només et troba pel DNS: el MX diu a qui s\'ha d\'enviar el correu i mx i mail han d\'apuntar a llocs diferents segons des d\'on es vingui (fora o dins de la DMZ).',
                    self::loc(self::DIG, <<<'SH'
                        mxr=$(q MX "$Z")
                        [ -n "$mxr" ] || fail "$Z no té registre MX"
                        [ "$(echo "$mxr" | wc -l)" -eq 1 ] || fail "$Z té més d'un MX ($(echo $mxr | tr '\n' ' ')): ha de ser un de sol cap a mx.$Z"
                        grep -qiE "^[0-9]+ mx\.$Z\.?$" <<<"$mxr" || fail "el MX de $Z és '$mxr' i ha de ser mx.$Z"
                        a_mx=$(q A "mx.$Z" | grep -E '^[0-9.]+$' | tail -1)
                        [ -n "$a_mx" ] || fail "mx.$Z no té registre A"
                        [ "$a_mx" = "$HE_IP_ROUTER" ] || fail "mx.$Z resol a $a_mx i ha de ser l'adreça d'aula del teu router ($HE_IP_ROUTER)"
                        a_mail=$(q A "mail.$Z" | grep -E '^[0-9.]+$' | tail -1)
                        [ "$a_mail" = "$HE_IP_MAIL" ] || fail "mail.$Z resol a '${a_mail:-res}' i ha de ser $HE_IP_MAIL"
                        cn=$(q CNAME "webmail.$Z")
                        grep -qi "^mail\.$Z\.\?$" <<<"$cn" || fail "webmail.$Z ha de ser un CNAME cap a mail.$Z (hi ha: '${cn:-res}')"
                        msg "MX mx.$Z -> $a_mx; mail -> $a_mail; webmail -> mail"
                        SH),
                    ['timeout' => 40],
                ),
                $this->c('12.3.2', 'El port 25 respon a l\'adreça d\'aula del router',
                    'Des de l\'aula, el port 25 de l\'adreça de `mx.<zona>` (la del teu router) contesta amb una salutació SMTP: el DNAT arriba a l\'LXC mail. Comprova-ho: `nc mx.<zona> 25` des d\'una altra màquina de l\'aula.',
                    'comprovar que un servidor d\'un company pot connectar al teu servidor de correu pel DNAT del port 25.',
                    'el correu entre empreses entra per l\'adreça pública del router; si el DNAT o el flux F1 falten, ningú no et pot enviar res tot i que el servidor funcioni.',
                    self::loc(self::DIG, <<<'SH'
                        ip=$(q A "mx.$Z" | grep -E '^[0-9.]+$' | tail -1)
                        [ -n "$ip" ] || fail "mx.$Z no resol al DNS de l'aula"
                        line=$(timeout 12 bash -c 'exec 3<>"/dev/tcp/$0/25" || exit 3; read -r -t 8 l <&3 || exit 4; echo "$l"' "$ip" 2>/dev/null); rc=$?
                        [ $rc -ne 3 ] || fail "el port 25 de $ip (mx.$Z) no accepta connexions: DNAT o flux F1?"
                        [ $rc -eq 0 ] || fail "el port 25 de $ip connecta però no saluda"
                        case "$line" in 220*) ;; *) fail "el port 25 de $ip no respon com un servidor SMTP ('${line:0:60}')";; esac
                        msg "$ip:25 -> ${line:0:80}"
                        SH),
                    ['timeout' => 30],
                ),
                $this->c('12.3.3', 'El certificat cobreix mail, mx i webmail',
                    'El certificat que serveix el servidor (webmail i IMAP) és vigent i cobreix `mail.<zona>`, `mx.<zona>` i `webmail.<zona>`. Comprova-ho: `openssl s_client -connect mail.<zona>:993 -servername mail.<zona>`.',
                    'comprovar que el certificat és vigent i que els noms que fan servir els clients hi són.',
                    'un certificat caducat o que no cobreix el nom fa que els clients avisin o rebutgin la connexió; un wildcard de Let\'s Encrypt cobreix tots els noms de la zona amb un sol certificat.',
                    self::ssh(<<<'SH'
                        for port in 443 993; do
                            crt=$(echo | timeout 10 openssl s_client -connect 127.0.0.1:$port -servername "mail.$Z" 2>/dev/null | openssl x509 2>/dev/null)
                            [ -n "$crt" ] || fail "el port $port no serveix cap certificat"
                            openssl x509 -noout -checkend 0 <<<"$crt" >/dev/null || fail "el certificat del port $port ha caducat"
                            for n in mail mx webmail; do
                                openssl x509 -noout -checkhost "$n.$Z" <<<"$crt" 2>/dev/null | grep -q 'does match' || fail "el certificat del port $port no cobreix $n.$Z"
                            done
                        done
                        end=$(echo | openssl s_client -connect 127.0.0.1:993 -servername "mail.$Z" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
                        msg "certificat vigent (caduca $end) i cobreix mail, mx i webmail"
                        SH),
                    ['timeout' => 40],
                ),
            ]],

            ['Pas 4 · Postfix, bústies i àlies', <<<'MD'
                Instal·la Postfix i crea les bústies `direccio`, `suport` i `administracio` en format Maildir,
                sense terminal, i els àlies `info` (cap a administracio) i `postmaster` i `abuse` (cap a suport).
                Comprova amb `ss -lntp` que el port 25 escolta a totes les interfícies.
                MD, [
                $this->c('12.4.1', 'Les tres bústies existeixen en format Maildir',
                    'Hi ha els usuaris `direccio`, `suport` i `administracio`, sense terminal, i Postfix lliura en format Maildir (`home_mailbox = Maildir/`). Comprova-ho: `getent passwd suport` i `postconf home_mailbox`.',
                    'comprovar que les bústies existeixen, que no tenen terminal i que el correu es desa en Maildir.',
                    'un compte de correu no ha de permetre entrar al servidor, i Maildir (un fitxer per missatge) és el format que fan servir Dovecot i la majoria de serveis moderns.',
                    self::ssh(<<<'SH'
                        hm=$($SUDO postconf -h home_mailbox 2>/dev/null)
                        [ "$hm" = "Maildir/" ] || fail "home_mailbox val '${hm:-res}' i ha de ser Maildir/"
                        for u in direccio suport administracio; do
                            l=$(getent passwd "$u") || fail "no existeix l'usuari $u"
                            sh=${l##*:}
                            case "$sh" in */nologin|*/false) ;; *) fail "$u té terminal ($sh): ha de ser nologin";; esac
                            h=$(echo "$l" | cut -d: -f6)
                            $SUDO test -d "$h/Maildir" || fail "$u no té la carpeta $h/Maildir"
                        done
                        msg "direccio, suport i administracio: sense terminal i amb Maildir"
                        SH),
                ),
                $this->c('12.4.2', 'Els àlies info, postmaster i abuse arriben a la bústia que toca',
                    'L\'àlies `info` va a `administracio` i `postmaster` i `abuse` van a `suport`. Comprova-ho: `postmap -q info hash:/etc/aliases`.',
                    'comprovar que els àlies envien el correu a la bústia correcta.',
                    'els àlies permeten adreces funcionals (info, abuse) sense crear comptes; `postmaster` i `abuse` són adreces que tot domini ha de poder rebre.',
                    self::ssh(<<<'SH'
                        maps=$($SUDO postconf -h alias_maps | sed 's/,/ /g')
                        chk() { # <àlies> <bústia esperada>
                            got=$($SUDO postmap -q "$1" $maps 2>/dev/null | head -1 | tr -d ' ')
                            [ -n "$got" ] || fail "l'àlies $1 no existeix"
                            case "$got" in "$2"|"$2@"*) ;; *) fail "l'àlies $1 va a '$got' i ha d'anar a $2";; esac
                        }
                        chk info administracio; chk postmaster suport; chk abuse suport
                        msg "info -> administracio; postmaster i abuse -> suport"
                        SH),
                    ['timeout' => 25],
                ),
            ]],

            ['Pas 5 · Correu entre empreses', <<<'MD'
                Envia i rep correu amb el teu company i fes un enviament escrivint tu el diàleg SMTP.
                Comproveu mútuament que cap dels dos servidors és un relay obert. Bloqueja al router la
                sortida al port 25 cap a Internet.
                MD, [
                $this->c('12.5.1', 'El servidor no és un relay obert',
                    'Algú de l\'aula, sense autenticar-se, pot enviar correu al teu domini però no a un domini aliè: l\'intent cap a un tercer és rebutjat al `RCPT TO`. Comprova-ho amb `swaks --server mx.<zona> --from prova@exemple.test --to algu@exemple.net` des del servidor del company.',
                    'comprovar que el servidor accepta correu per al domini propi i rebutja el destinat a dominis aliens.',
                    'un relay obert deixa que qualsevol enviï correu brossa amb la teva adreça IP: en poques hores el servidor surt a les llistes negres i el correu legítim deixa d\'arribar.',
                    self::py(<<<'PY'
                        ip = mx_ip()
                        s = connect(ip, 25, 'el servidor de correu')
                        s.ehlo(HELO)
                        code, _ = s.mail('prova@exemple.test')
                        if code != 250:
                            fail('el servidor rebutja el remitent de prova (%d)' % code)
                        code, text = s.rcpt('algu@exemple.net')
                        ext = (code, text.decode(errors='replace')[:90])
                        s.rset()
                        s.mail('prova@exemple.test')
                        code2, text2 = s.rcpt('info@' + Z)
                        s.quit()
                        if ext[0] in (250, 251):
                            fail('RELAY OBERT: el servidor ha acceptat correu cap a exemple.net sense autenticar-se')
                        if code2 not in (250, 251):
                            fail('el servidor no accepta correu per al domini propi (info@%s: %d %s)' % (Z, code2, text2.decode(errors='replace')[:70]))
                        msg('tercer domini rebutjat (%d %s); info@%s acceptat' % (ext[0], ext[1], Z))
                        PY),
                    ['timeout' => 45],
                ),
                $this->c('12.5.2', 'El port 25 cap a Internet està bloquejat',
                    'Des de l\'LXC mail no es pot connectar al port 25 d\'un servidor d\'Internet (és la regla de bloqueig del router). Comprova-ho: `nc -vz -w 5 gmail-smtp-in.l.google.com 25` des de mail.',
                    'comprovar que el servidor no pot lliurar correu directament a Internet.',
                    'moltes xarxes bloquegen el 25 de sortida perquè un equip infectat no enviï brossa; i aquí és el que obliga a passar pel relay, que té reputació i controla els remitents.',
                    self::ssh(<<<'SH'
                        ip=""
                        for n in gmail-smtp-in.l.google.com mx-aol.mail.gm0.yahoodns.net; do
                            ip=$(getent ahostsv4 "$n" | awk 'NR==1 {print $1}')
                            [ -n "$ip" ] && break
                        done
                        [ -n "$ip" ] || fail "mail no resol cap servidor d'Internet per fer la prova (DNS de l'LXC o de ns1)"
                        if timeout 8 bash -c "exec 3<>/dev/tcp/$ip/25 && read -r -t 6 l <&3 && [ -n \"\$l\" ]" 2>/dev/null; then
                            fail "mail connecta al port 25 de $ip (Internet): falta bloquejar-lo al router"
                        fi
                        msg "el port 25 cap a $ip (Internet) no connecta"
                        SH),
                    ['timeout' => 25],
                ),
            ]],

            ['Pas 6 · IMAP i POP3 amb Dovecot', <<<'MD'
                Instal·la Dovecot i serveix IMAP i POP3 només amb TLS (ports 993 i 995). Obre els fluxos
                F3 i F4. Llegeix el mateix correu amb un client IMAP i amb un de POP3.
                MD, [
                $this->c('12.6.1', 'IMAPS (993) i POP3S (995) responen amb TLS i el nom correcte',
                    'Els ports 993 i 995 de `mail.<zona>` fan TLS i el certificat és el de `mail.<zona>`. Comprova-ho: `openssl s_client -connect mail.<zona>:993 -servername mail.<zona>`.',
                    'comprovar que la recollida del correu va xifrada de principi a fi i amb un certificat que correspon al nom del servidor.',
                    'IMAP i POP3 envien la contrasenya de l\'usuari: sense TLS qualsevol equip del camí la pot llegir; i un client només confia si el nom del certificat coincideix.',
                    self::loc(self::DIG, <<<'SH'
                        ip=$(q A "mail.$Z" | grep -E '^[0-9.]+$' | tail -1)
                        [ -n "$ip" ] || ip="$HE_IP_MAIL"
                        for p in 993 995; do
                            crt=$(echo | timeout 10 openssl s_client -connect "$ip:$p" -servername "mail.$Z" 2>/dev/null | openssl x509 2>/dev/null)
                            [ -n "$crt" ] || fail "el port $p de mail.$Z ($ip) no fa TLS: Dovecot o flux F3?"
                            openssl x509 -noout -checkend 0 <<<"$crt" >/dev/null || fail "el certificat del port $p ha caducat"
                            openssl x509 -noout -checkhost "mail.$Z" <<<"$crt" 2>/dev/null | grep -q 'does match' || fail "el certificat del port $p no és vàlid per a mail.$Z"
                        done
                        msg "993 i 995 de mail.$Z fan TLS amb el nom correcte"
                        SH),
                    ['timeout' => 40],
                ),
                $this->c('12.6.2', 'No hi ha IMAP ni POP3 sense xifrar',
                    'Els ports 143 (IMAP) i 110 (POP3) no escolten i Dovecot obliga a fer servir TLS. Comprova-ho: `ss -lnt | grep -E ":(110|143) "` i `doveconf ssl`.',
                    'comprovar que no hi ha cap manera de recollir el correu en clar.',
                    'un servei en clar obert fa inútil el xifratge dels altres: un client mal configurat enviaria la contrasenya sense protecció.',
                    self::ssh(<<<'SH'
                        open=$(ss -Hlnt | awk '{print $4}' | grep -E ':(110|143)$' | sort -u | tr '\n' ' ')
                        [ -z "$open" ] || fail "escolta IMAP o POP3 sense xifrar: $open"
                        ssl=$($SUDO doveconf -h ssl 2>/dev/null)
                        [ "$ssl" = "required" ] || fail "Dovecot té ssl = '${ssl:-res}' i ha de ser required"
                        msg "110 i 143 tancats i Dovecot amb ssl = required"
                        SH),
                    ['timeout' => 25],
                ),
            ]],

            ['Pas 7 · Enviament autenticat', <<<'MD'
                Configura el port 587 (submission) perquè només s'enviï amb autenticació i xifratge. Configura
                Thunderbird a pc-suport amb una bústia. Demostra que sense contrasenya no es pot enviar i troba
                al registre un intent fallit. Opcional: impedeix que suport enviï amb l'adreça de direccio.
                MD, [
                $this->c('12.7.1', 'El 587 exigeix xifratge i autenticació',
                    'El port 587 no ofereix `AUTH` abans de fer STARTTLS i, un cop xifrat, no deixa enviar correu a ningú sense autenticar-se. Comprova-ho: `openssl s_client -starttls smtp -connect mail.<zona>:587` i escriu `MAIL FROM` i `RCPT TO` sense `AUTH`.',
                    'comprovar que l\'enviament des dels clients només es pot fer amb TLS i amb un usuari i contrasenya.',
                    'el 587 és el port que fan servir els usuaris per enviar a qualsevol destinatari: si no demana autenticació és un relay obert, i si no xifra, la contrasenya viatja en clar.',
                    self::py(<<<'PY'
                        ips = [i for i in dig('A', 'mail.' + Z) if i.replace('.', '').isdigit()] or [MAIL_IP]
                        s = connect(ips[0], 587, 'el port d\'enviament')
                        s.ehlo(HELO)
                        if s.has_extn('auth'):
                            fail('el 587 ofereix AUTH abans de xifrar la connexió (només ha d\'oferir-lo dins TLS)')
                        if not s.has_extn('starttls'):
                            fail('el 587 no ofereix STARTTLS')
                        ctx = ssl.create_default_context()
                        ctx.check_hostname = False
                        ctx.verify_mode = ssl.CERT_NONE
                        try:
                            s.starttls(context=ctx)
                        except (smtplib.SMTPException, OSError) as e:
                            fail('el STARTTLS del 587 falla: %s' % str(e)[:80])
                        s.ehlo(HELO)
                        if not s.has_extn('auth'):
                            fail('dins TLS el 587 no ofereix AUTH: Dovecot o el sòcol d\'autenticació')
                        code, _ = s.mail('prova@exemple.test')
                        if code == 250:
                            code, text = s.rcpt('algu@exemple.net')
                        else:
                            text = b''
                        s.close()
                        if code in (250, 251):
                            fail('el 587 accepta correu cap a un tercer sense autenticar-se')
                        msg('587: sense AUTH en clar, STARTTLS correcte i rebuig sense autenticar (%d)' % code)
                        PY),
                    ['timeout' => 45],
                ),
                $this->c('12.7.2', 'Cada usuari només pot enviar amb la seva adreça',
                    'El 587 rebutja que un usuari autenticat enviï amb l\'adreça d\'un altre (`reject_sender_login_mismatch`). Comprova-ho: `postconf -P submission/inet/smtpd_sender_restrictions`.',
                    'comprovar que el servidor lliga cada remitent al seu usuari autenticat.',
                    'si qualsevol usuari pot posar l\'adreça que vulgui, un compte robat serveix per suplantar la direcció; el servidor ha de saber qui envia.',
                    self::ssh(<<<'SH'
                        r=$($SUDO postconf -P 'submission/inet/smtpd_sender_restrictions' 2>/dev/null)
                        grep -q 'reject_sender_login_mismatch' <<<"$r" || fail "el 587 no aplica reject_sender_login_mismatch"
                        m=$($SUDO postconf -h smtpd_sender_login_maps 2>/dev/null)
                        [ -n "$m" ] || m=$($SUDO postconf -P 'submission/inet/smtpd_sender_login_maps' 2>/dev/null)
                        [ -n "$m" ] || fail "falta smtpd_sender_login_maps (la taula que lliga remitent i usuari)"
                        msg "el 587 rebutja remitents que no són de l'usuari autenticat"
                        SH),
                    ['tipus' => CheckKind::Optional],
                ),
            ]],

            ['Pas 8 · Relay extern', <<<'MD'
                Configura Postfix perquè només el correu que surt de l'aula (no el dels companys) passi pel
                relay extern, amb el remitent canviat (flux F5). Envia un correu al teu compte del centre i
                compara'n les capçaleres amb les d'un correu del company. La contrasenya del relay no es
                posa mai a la línia d'ordres.
                MD, [
                $this->c('12.8.1', 'El relay extern està configurat i el secret protegit',
                    'El correu cap a fora de l\'aula s\'envia al 587 de `mail-eu.smtp2go.com` i els fitxers amb la contrasenya (`sasl_passwd*`) són de root i amb permisos 600. Comprova-ho: `ls -l /etc/postfix/sasl_passwd*` i `postmap -q iesjulioantonio.cat hash:/etc/postfix/transport`.',
                    'comprovar que el correu cap al centre es dirigeix al relay pel port 587 i que les credencials no les pot llegir ningú més.',
                    'el relay extern té reputació i accepta correu només d\'usuaris autenticats; la contrasenya és compartida per tota la classe: si es filtra, es desactiva l\'usuari per a tothom.',
                    self::ssh(<<<'SH'
                        for f in /etc/postfix/sasl_passwd /etc/postfix/sasl_passwd.db; do
                            [ -e "$f" ] || fail "falta $f"
                            st=$($SUDO stat -c '%U %a' "$f")
                            [ "$st" = "root 600" ] || fail "$f és '$st' i ha de ser root 600"
                        done
                        $SUDO grep -q 'smtp2go' /etc/postfix/sasl_passwd || fail "sasl_passwd no apunta al relay mail-eu.smtp2go.com"
                        maps=$($SUDO postconf -h transport_maps | sed 's/,/ /g')
                        t=""
                        if [ -n "$maps" ]; then
                            for k in iesjulioantonio.cat .iesjulioantonio.cat .cat '*'; do
                                t=$($SUDO postmap -q "$k" $maps 2>/dev/null | head -1) && [ -n "$t" ] && break
                            done
                        fi
                        [ -n "$t" ] || t=$($SUDO postconf -h relayhost)
                        grep -qi 'smtp2go' <<<"$t" || fail "el correu cap a iesjulioantonio.cat no va al relay (transport: '${t:-res}')"
                        grep -q ':587' <<<"$t" || fail "el relay s'ha d'utilitzar pel port 587 ($t)"
                        msg "sortida cap a l'exterior: $t; sasl_passwd* root 600"
                        SH),
                    ['timeout' => 30],
                ),
                $this->c('12.8.2', 'Hi ha un correu lliurat pel relay',
                    'Al registre de correu hi ha un lliurament cap al relay (`relay=mail-eu.smtp2go.com`) amb `status=sent`. Comprova-ho: `grep status=sent /var/log/mail.log | grep smtp2go`.',
                    'comprovar que el relay no només està configurat, sinó que ha lliurat realment un correu.',
                    'el registre és la prova de què ha fet el servidor: si no hi ha una línia status=sent, el correu no ha sortit, per bé que la configuració sembli correcta.',
                    self::ssh(<<<'SH'
                        n=$({ $SUDO journalctl -t postfix/smtp --no-pager 2>/dev/null; $SUDO cat /var/log/mail.log /var/log/mail.log.1 2>/dev/null; } | grep 'relay=mail-eu.smtp2go.com' | grep -c 'status=sent')
                        [ "${n:-0}" -ge 1 ] || fail "el registre no mostra cap lliurament pel relay amb status=sent"
                        msg "$n lliurament(s) pel relay amb status=sent"
                        SH),
                    ['timeout' => 40],
                ),
            ]],

            ['Pas 9 · Webmail', <<<'MD'
                Instal·la Roundcube a `https://webmail.<zona>`, amb HTTPS, integrat amb Dovecot i Postfix.
                Hi han d'arribar el personal (VLAN 101) i els servidors de la LAN (VLAN 100), no els convidats.
                MD, [
                $this->c('12.9.1', 'El webmail respon amb HTTPS',
                    'Hi ha un Roundcube a `https://webmail.<zona>/` amb HTTPS i un certificat que és vàlid per al nom. Comprova-ho: obre l\'adreça des de pc-ubuntu.',
                    'comprovar que el webmail és accessible pel seu nom, amb xifratge, i que és realment Roundcube.',
                    'el webmail permet llegir el correu des de qualsevol navegador sense configurar res, però és una porta d\'entrada amb contrasenya i ha d\'anar sempre per HTTPS.',
                    self::loc(self::DIG, <<<'SH'
                        ip=$(q A "webmail.$Z" | grep -E '^[0-9.]+$' | tail -1)
                        [ -n "$ip" ] || fail "webmail.$Z no resol al DNS de l'aula"
                        body=$(curl -sk -m 12 -L --resolve "webmail.$Z:443:$ip" "https://webmail.$Z/") || fail "https://webmail.$Z/ no respon ($ip:443): Apache, certificat o flux F3?"
                        grep -qi 'roundcube' <<<"$body" || fail "https://webmail.$Z/ respon però no és Roundcube"
                        crt=$(echo | timeout 10 openssl s_client -connect "$ip:443" -servername "webmail.$Z" 2>/dev/null | openssl x509 2>/dev/null)
                        openssl x509 -noout -checkend 0 <<<"$crt" >/dev/null 2>&1 || fail "el certificat de webmail.$Z ha caducat o no es pot llegir"
                        openssl x509 -noout -checkhost "webmail.$Z" <<<"$crt" 2>/dev/null | grep -q 'does match' || fail "el certificat no és vàlid per a webmail.$Z"
                        iss=$(openssl x509 -noout -issuer <<<"$crt" | sed 's/^issuer=//')
                        msg "Roundcube a https://webmail.$Z/ ($iss)"
                        SH),
                    ['timeout' => 40],
                ),
            ]],

            ['Pas 10 · Rspamd i SPF', <<<'MD'
                Instal·la Rspamd i Redis i connecta'ls a Postfix. Publica el registre SPF del teu domini. El
                company ha de veure el resultat a les capçaleres d'un correu teu: si no és correcte, esbrina per què.
                MD, [
                $this->c('12.10.1', 'L\'SPF del domini autoritza el teu servidor',
                    'El domini té un sol registre SPF i el servidor que envia el teu correu hi està autoritzat. Comprova-ho amb el resultat `spf=` de les capçaleres d\'un correu teu a la bústia del company.',
                    'comprovar que hi ha un únic registre SPF, que acaba amb una política restrictiva i que cobreix l\'adreça que veu el servidor receptor.',
                    'l\'SPF diu quins servidors poden enviar en nom del domini; un registre que no cobreix el servidor real, o que n\'hi ha dos, fa que el correu legítim es marqui com a fals.',
                    self::py(<<<'PY'
                        import ipaddress
                        txt = [t.replace('" "', '').strip('"') for t in dig('TXT', Z)]
                        spf = [t for t in txt if t.lower().startswith('v=spf1')]
                        if not spf:
                            fail('%s no té cap registre SPF' % Z)
                        if len(spf) > 1:
                            fail('%s té %d registres SPF i en pot tenir només un' % (Z, len(spf)))
                        mechs = spf[0].split()[1:]
                        allm = [m for m in mechs if m.lstrip('+-~?') == 'all']
                        if not allm or allm[-1][0] not in '-~':
                            fail('l\'SPF ha d\'acabar amb -all o ~all: "%s"' % spf[0])
                        target = ipaddress.ip_address(MAIL_IP)


                        def ips_of(kind, name):
                            out = []
                            for a in dig(kind, name):
                                try:
                                    out.append(ipaddress.ip_address(a))
                                except ValueError:
                                    pass
                            return out


                        def authorized(mech):
                            mech = mech.lstrip('+')
                            if mech.startswith('ip4:'):
                                return target in ipaddress.ip_network(mech[4:], strict=False)
                            head, _, arg = mech.partition(':')
                            arg, _, _ = arg.partition('/')
                            if head == 'a':
                                return target in ips_of('A', arg or Z)
                            if head == 'mx':
                                hosts = [h.split()[-1].rstrip('.') for h in dig('MX', arg or Z)]
                                return any(target in ips_of('A', h) for h in hosts)
                            return False


                        if not any(authorized(m) for m in mechs if not m.startswith(('-', '~', '?')) and m.lstrip('+') != 'all' and not m.startswith(('include:', 'redirect='))):
                            if any(m.startswith(('include:', 'redirect=')) for m in mechs):
                                fail('l\'SPF fa servir include/redirect i no es pot comprovar: usa ip4: o a:')
                            fail('el registre SPF no autoritza el servidor que envia el teu correu ("%s")' % spf[0])
                        msg('SPF: %s' % spf[0])
                        PY),
                    ['timeout' => 40],
                ),
                $this->c('12.10.2', 'Rspamd i Redis funcionen i Postfix els fa servir',
                    'Els serveis `rspamd` i `redis-server` estan actius i Postfix passa el correu per Rspamd (milter). Comprova-ho: `systemctl is-active rspamd redis-server` i `postconf smtpd_milters`.',
                    'comprovar que el filtre antispam i la base de dades que fa servir estan en marxa i connectats a Postfix.',
                    'sense el milter, Postfix no consulta Rspamd i no es comprova ni es signa res: SPF, DKIM i DMARC depenen d\'aquesta connexió.',
                    self::ssh(<<<'SH'
                        for s in rspamd redis-server; do
                            systemctl is-active --quiet "$s" || fail "el servei $s no està actiu"
                        done
                        m=$($SUDO postconf -h smtpd_milters)
                        grep -q ':11332' <<<"$m" || fail "Postfix no té el milter de Rspamd a smtpd_milters (hi ha: '${m:-res}')"
                        n=$($SUDO postconf -h non_smtpd_milters)
                        grep -q ':11332' <<<"$n" || fail "Postfix no passa pel milter el correu local (non_smtpd_milters: '${n:-res}')"
                        msg "rspamd i redis-server actius; milter $m"
                        SH),
                ),
            ]],

            ['Pas 11 · DKIM', <<<'MD'
                Genera la clau DKIM, publica la part pública amb el selector `mail` i signa el correu sortint.
                El company comprova a les capçaleres que la signatura és vàlida i de quin domini és.
                MD, [
                $this->c('12.11.1', 'La clau DKIM del DNS correspon a la del servidor',
                    'El registre `mail._domainkey.<zona>` conté la clau pública que correspon a la clau privada que fa servir Rspamd per signar. Comprova-ho amb `dig TXT mail._domainkey.<zona>` i `openssl pkey -pubout`.',
                    'comprovar que la clau que signa és la que el DNS publica, amb el selector `mail`.',
                    'el receptor valida la signatura amb la clau pública del DNS: si no coincideix amb la privada, el correu signat falla DKIM, exactament com si no estigués signat.',
                    self::ssh(<<<'SH'
                        cfg=/etc/rspamd/local.d/dkim_signing.conf
                        path=$($SUDO sed -n 's/^[[:space:]]*path[[:space:]]*=[[:space:]]*"\(.*\)";.*/\1/p' "$cfg" 2>/dev/null | head -1)
                        [ -n "$path" ] || path='/var/lib/rspamd/dkim/$domain.$selector.key'
                        key=$(sed "s/\$domain/$Z/g; s/\$selector/mail/g; s/\${domain}/$Z/g; s/\${selector}/mail/g" <<<"$path")
                        $SUDO test -e "$key" || fail "no trobo la clau privada del selector mail ($key)"
                        mine=$($SUDO openssl pkey -in "$key" -pubout -outform DER 2>/dev/null | base64 -w0)
                        [ -n "$mine" ] || fail "la clau privada $key no es pot llegir amb openssl"
                        txt=$(dig +short +time=3 +tries=1 TXT "mail._domainkey.$Z" @"$HE_IP_DNS" 2>/dev/null | tr -d '" \n')
                        [ -n "$txt" ] || fail "mail._domainkey.$Z no existeix a ns1"
                        pub=$(sed -n 's/.*p=\([A-Za-z0-9+\/=]*\).*/\1/p' <<<"$txt")
                        [ -n "$pub" ] || fail "el registre mail._domainkey.$Z no té cap p="
                        [ "$pub" = "$mine" ] || fail "la clau pública del DNS no correspon a la clau privada del selector mail"
                        msg "la clau del DNS (selector mail) correspon a la privada"
                        SH),
                    ['timeout' => 30],
                ),
            ]],

            ['Pas 12 · DMARC', <<<'MD'
                Publica la política DMARC del domini i fes que el teu servidor apliqui la dels altres. El
                company intentarà enviar-te un correu com si fos `direccio@<zona>` des del seu propi servidor,
                que també signa els missatges. Si el correu entra, explica per què i corregeix-ho.
                MD, [
                $this->c('12.12.1', 'Un correu que suplanta el domini és rebutjat',
                    'Un servidor que no és el teu, enviant un correu amb `From: direccio@<zona>` sense signatura vàlida del teu domini, rep un rebuig durant el diàleg SMTP. HeimdEye en fa la prova: si no ho rebutges, el missatge arribarà a la bústia de direccio.',
                    'comprovar que el servidor aplica la política DMARC i rebutja els correus que es fan passar pel domini.',
                    'la suplantació del remitent és la base de la pesca i dels fraus «del director»; DMARC és el que permet al receptor decidir què fer amb un correu que diu ser del domini i no ho és.',
                    self::py(<<<'PY'
                        from email.message import EmailMessage
                        ip = mx_ip()
                        m = EmailMessage()
                        m['From'] = 'direccio@' + Z
                        m['To'] = 'direccio@' + Z
                        m['Subject'] = 'Prova de HeimdEye (suplantacio del domini): no cal fer res'
                        m['Message-ID'] = '<heimdeye-spoof-%d@%s>' % (os.getpid(), HELO)
                        m.set_content('Aquest correu l\'envia HeimdEye per comprovar que el servidor rebutja la suplantacio. Si el llegeixes, no s\'ha rebutjat.\n')
                        s = connect(ip, 25, 'el servidor de correu')
                        s.ehlo(HELO)
                        try:
                            s.send_message(m, from_addr='heimdeye@' + Z, to_addrs=['direccio@' + Z])
                        except smtplib.SMTPResponseException as e:
                            txt = (e.smtp_error.decode(errors='replace') if isinstance(e.smtp_error, bytes) else str(e.smtp_error))[:80]
                            if e.smtp_code >= 500:
                                msg('suplantació rebutjada (%d %s)' % (e.smtp_code, txt))
                                sys.exit(0)
                            fail('el servidor no rebutja la suplantació: resposta temporal %d %s' % (e.smtp_code, txt))
                        except smtplib.SMTPRecipientsRefused as e:
                            codes = [v[0] for v in e.recipients.values()]
                            fail('el servidor no accepta ni la prova (%s)' % codes)
                        fail('el servidor ha ACCEPTAT un correu que suplanta direccio@%s: ara és a la bústia' % Z)
                        PY),
                    ['timeout' => 60],
                ),
                $this->c('12.12.2', 'La política DMARC atura la suplantació',
                    'El domini publica una política DMARC de rebuig que atura els correus que se\'n fan passar sense estar alineats amb el teu domini, també els que vénen d\'un altre servidor de l\'aula. Comprova-ho: `dig TXT _dmarc.<zona>`.',
                    'comprovar que el registre DMARC existeix, demana rebutjar i té un alineament que impedeix la suplantació per part d\'un servidor de l\'aula.',
                    'una política que només marca, o un alineament massa laxe, deixa passar correu fals encara que els tres mecanismes «passin»: la política és la que decideix què passa amb el que no s\'alinea.',
                    self::loc(self::DIG, <<<'SH'
                        r=$(q TXT "_dmarc.$Z" | tr -d '"' | grep -i '^v=DMARC1')
                        [ -n "$r" ] || fail "_dmarc.$Z no té cap registre DMARC"
                        tag() { sed -n "s/.*[; ]$1=\([^; ]*\).*/\1/p" <<<"; $r" | head -1 | tr 'A-Z' 'a-z'; }
                        p=$(tag p)
                        [ "$p" = "reject" ] || fail "la política DMARC és '${p:-res}' i ha de ser de rebuig"
                        [ "$(tag adkim)" = "s" ] && [ "$(tag aspf)" = "s" ] || fail "la política DMARC encara deixa passar la suplantació d'un servidor de l'aula (revisa'n l'alineament): $r"
                        msg "DMARC: $r"
                        SH),
                    ['timeout' => 30],
                ),
            ]],

            ['Pas 13 · Antispam', <<<'MD'
                Envia la prova GTUBE al servidor del company i interpreta les capçaleres i el registre d'un
                correu acceptat i d'un de rebutjat. Observa què fa la llista grisa.
                MD, [
                $this->c('12.13.1', 'La prova GTUBE és rebutjada',
                    'Un correu que conté la cadena de prova GTUBE (correu brossa de mostra) és rebutjat amb un 554 durant el diàleg SMTP. HeimdEye en fa la prova: si no el rebutges, arribarà a la bústia de direccio.',
                    'comprovar que el filtre antispam rebutja el correu brossa abans d\'acceptar-lo.',
                    'rebutjar durant el diàleg SMTP evita guardar el brossa i avisa el remitent legítim; acceptar-lo per esborrar-lo després genera rebots cap a adreces falses i omple les bústies.',
                    self::py(<<<'PY'
                        ip = mx_ip()
                        gtube = 'XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X'
                        raw = ('From: HeimdEye <heimdeye@julioantonio.cat>\r\nTo: direccio@%s\r\nSubject: Prova GTUBE de HeimdEye: no cal fer res\r\n'
                               'Message-ID: <heimdeye-gtube-%d@%s>\r\n\r\n%s\r\n' % (Z, os.getpid(), HELO, gtube))
                        s = connect(ip, 25, 'el servidor de correu')
                        s.ehlo(HELO)
                        try:
                            s.sendmail('heimdeye@julioantonio.cat', ['direccio@' + Z], raw)
                        except smtplib.SMTPResponseException as e:
                            txt = (e.smtp_error.decode(errors='replace') if isinstance(e.smtp_error, bytes) else str(e.smtp_error))[:80]
                            if e.smtp_code >= 500:
                                msg('GTUBE rebutjat (%d %s)' % (e.smtp_code, txt))
                                sys.exit(0)
                            fail('el servidor no rebutja el GTUBE: resposta temporal %d %s' % (e.smtp_code, txt))
                        except smtplib.SMTPRecipientsRefused as e:
                            fail('el servidor no accepta ni la prova (%s)' % [v[0] for v in e.recipients.values()])
                        fail('el servidor ha ACCEPTAT el GTUBE: ara és a la bústia de direccio')
                        PY),
                    ['timeout' => 60],
                ),
            ]],

            ['Pas 14 · Revisió de fluxos i ports', <<<'MD'
                Revisa fluxos i serveis: documenta la regla i la prova de cada flux i repeteix l'inventari
                de ports del pas 2. Només han d'escoltar 22, 25, 587, 993, 995 i 443.
                MD, [
                $this->c('12.14.1', 'Només escolten els ports acordats',
                    'L\'LXC mail només té oberts a la xarxa els ports 22, 25, 443, 587, 993 i 995; Redis i Rspamd només escolten en local. Comprova-ho: `ss -lntp`.',
                    'comprovar que el servidor no exposa cap servei que no sigui a la taula de fluxos.',
                    'cada port obert és superfície d\'atac; el que no figura a la taula de fluxos o és un servei oblidat (Redis, Rspamd, un 80 sense ús) o un error de configuració.',
                    self::ssh(<<<'SH'
                        extra=""
                        while read -r addr; do
                            port=${addr##*:}; host=${addr%:*}
                            case "$host" in 127.*|"[::1]"|::1) continue;; esac
                            case "$port" in 22|25|443|587|993|995) ;; *) extra="$extra $addr";; esac
                        done < <(ss -Hlnt | awk '{print $4}' | sort -u)
                        [ -z "$extra" ] || fail "ports oberts que no són a la taula de fluxos:$extra"
                        msg "només escolten 22, 25, 443, 587, 993 i 995 (la resta, en local)"
                        SH),
                ),
            ]],
        ];
    }

    // ── Codi comú ────────────────────────────────────────────────────────────────────────────

    /** Check bash que s'executa a HeimdEye. */
    private static function loc(string ...$parts): string
    {
        return self::BASE."\n".implode("\n", $parts);
    }

    /** Check bash que s'executa a l'LXC mail (grup vm_mail); defineix la capçalera obligatòria. */
    private static function ssh(string $body): string
    {
        return "# hosts: vm_mail\n".self::BASE."\nSUDO='sudo -n'\n".$body;
    }

    /** Check local escrit en Python (sonda SMTP): funcions comunes + el cos. */
    private static function py(string $body): string
    {
        return "python3 - <<'PY'\n".self::PYSMTP."\n\n\n".$body."\nPY\n";
    }

    /**
     * Un check: id <bloc>.<pas>.<n>, textos per a l'alumne, codi i opcions.
     *
     * @param  array{tipus?: CheckKind, timeout?: int}  $o
     * @return array<string, mixed>
     */
    private function c(string $id, string $name, string $description, string $objective, string $justification, string $code, array $o = []): array
    {
        $executor = str_starts_with($code, '# hosts:') ? Executor::Ssh : Executor::Local;

        return [
            'nom' => "$id $name",
            'descripcio' => $description,
            'info' => "**Objectiu del test:** {$objective}\n\n**Justificació:** {$justification}\n",
            'codi' => $code,
            'executor' => $executor,
            'tipus' => $o['tipus'] ?? CheckKind::Required,
            'temps_max_seg' => $o['timeout'] ?? 20,
        ];
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
                    'tipus' => $def['tipus'],
                    'punts' => 1,
                    'actiu_des' => self::FROM,
                    'actiu_fins' => self::UNTIL,
                    'data_limit' => self::LIMIT,
                    'posicio' => $i + 1,
                ],
            );

            // No es toca el codi d'un check que ja té versions: es corregeix des del panell (versió nova).
            if (! $check->versions()->exists()) {
                $check->versions()->create([
                    'versio' => 1,
                    'codi' => $def['codi'],
                    'parametres' => null,
                    'temps_max_seg' => $def['temps_max_seg'],
                ]);
            }
        }
    }
}
