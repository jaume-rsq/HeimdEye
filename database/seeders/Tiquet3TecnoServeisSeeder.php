<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\Check;
use App\Models\Module;
use App\Models\Task;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Bloc 3 de 0378 (xarxa perimetral a AWS), tiquet 3: «Accés remot dels empleats: VPN, SSH amb RADIUS i intranet».
 *
 * Crea a la tasca «Tiquet 3» (la de l'enunciat del professor, que no es toca):
 *  - checks de les proves d'acceptació del T3 (R1-R6, V1-V9), obligatoris i d'1 punt, més un d'opcional
 *    per a wstunnel; només són ACTIUS la setmana indicada (actiu_des / actiu_fins);
 * Les accions d'ajuda són a part (Tiquet3TecnoServeisAccionsSeeder). Abans de crear-ho, ESBORRA els
 * checks de la tasca «Tiquet 3» (amb les seves execucions); la resta del bloc no es toca.
 *
 * Tot s'executa amb l'agent amb rol «aws»: l'AWS CLI i python3 amb les credencials del Learner Lab de
 * l'alumne. Els checks A (R*, V5, V9, W) només miren AWS i el que passa dins del gateway, el web i auth
 * (per SSM Run Command). Els checks B (V1-V4) s'executen des del WSL de l'alumne amb la VPN aixecada:
 * si no ho és, fallen amb un missatge que ho diu. Cap secret ni prefix de wstunnel no és al codi: el
 * de wstunnel només es llegeix de la instància, i els secrets RADIUS es generen a auth.
 *
 * Mòdul 0378 del curs actual, bloc 3, tasca «Tiquet 3»; es pot canviar amb HEIMDEYE_INCIDENCIA_MODUL,
 * _BLOC i HEIMDEYE_T3_TASCA. Els dies actius, amb HEIMDEYE_T3_ACTIU_DES i _FINS (per defecte, la setmana
 * del 16 al 22 de novembre de 2026). S'executa amb: php artisan db:seed --class=Tiquet3TecnoServeisSeeder --force
 */
class Tiquet3TecnoServeisSeeder extends Seeder
{
    public string $moduleCode = '0378';

    public int $blockNumber = 3;

    public string $taskTitle = 'Tiquet 3';

    public string $activeFrom = '2026-11-16';

    public string $activeUntil = '2026-11-22';

    // ── Checks de les proves d'acceptació ────────────────────────────────────────────────────

    /** Cos de PAM (R4) per a una instància: [id de la instància, nom]. */
    private const PAM = <<<'SH'
        need "@NOM@ (Name *@NOM@)" "@VAR@"
        read -r -d '' s <<'EOS' || true
        mkdir -p /run/sshd
        grep -q pam_radius_auth /etc/pam.d/sshd || echo "sshd no fa servir pam_radius_auth (/etc/pam.d/sshd)"
        [ -s /etc/pam_radius_auth.conf ] || echo "falta /etc/pam_radius_auth.conf"
        grep -q "$AUTHIP" /etc/pam_radius_auth.conf 2>/dev/null || echo "pam_radius_auth.conf no apunta a $AUTHIP"
        [ "$(sshd -T 2>/dev/null | grep -cE '^(usepam yes|kbdinteractiveauthentication yes|passwordauthentication yes)$')" = 3 ] \
          || echo "sshd sense UsePAM, KbdInteractiveAuthentication i PasswordAuthentication a yes"
        id -u empleat1 >/dev/null 2>&1 && id -u empleat2 >/dev/null 2>&1 || echo "falten els comptes empleat1 i empleat2"
        true
        EOS
        out=$(rcmd "@VAR@" "AUTHIP=10.$X.11.20
        $s") || { echo "No es pot comprovar el @NOM@: $out"; exit 1; }
        [ -z "$out" ] || { echo "@NOM@: $(tr '\n' ';' <<< "$out")"; exit 1; }
        echo "@NOM@: sshd amb pam_radius_auth, UsePAM, kbd-interactive i contrasenya, i comptes dels empleats"
        SH;

    /** [nom, descripció per a l'alumne, info (objectiu i justificació), cos, tipus, punts] */
    private const CHECKS = [
        ['R1: WireGuard actiu al gateway amb dos peers', 'Al gateway, wg-quick@wg0 és actiu, escolta al 51820 i té almenys dos peers. Comprova-ho dins del gateway: `sudo wg show wg0`.',
            "**Objectiu:** que el servidor VPN estigui en marxa i tingui els dos empleats donats d'alta.\n\n**Per què:** sense VPN no hi ha accés remot als recursos interns; sense peers, ningú no pot entrar-hi.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            [ "$(rcmd "$GW" 'systemctl is-active wg-quick@wg0; true' | tr -d '[:space:]')" = active ] || { echo "wg-quick@wg0 no és actiu al gateway"; exit 1; }
            port=$(rcmd "$GW" 'wg show wg0 listen-port; true' | tr -d '[:space:]')
            [ "$port" = 51820 ] || { echo "wg0 escolta al port ${port:-cap} (ha de ser el 51820)"; exit 1; }
            n=$(rcmd "$GW" 'wg show wg0 peers | wc -l; true' | tr -d '[:space:]')
            [ "${n:-0}" -ge 2 ] 2>/dev/null || { echo "wg0 té ${n:-0} peers (n'hi ha de tenir almenys 2)"; exit 1; }
            echo "wg0 actiu al 51820 amb $n peers"
            SH, CheckKind::Required, 1],
        ['R2: La intranet respon al 8080 del web', 'El 8080 del web serveix la intranet. Comprova-ho dins del gateway: `curl -s http://10.X.11.10:8080`.',
            "**Objectiu:** que hi hagi un recurs intern al port 8080 del web.\n\n**Per què:** és el motiu de la VPN: un servei que només han de veure els empleats.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            n=$(rcmd "$GW" "curl -s -m 5 http://10.$X.11.10:8080 | grep -c Intranet; true" | tr -d '[:space:]')
            [ "${n:-0}" -ge 1 ] 2>/dev/null || { echo "La intranet no respon al 8080 del web (10.$X.11.10), mirat des del gateway"; exit 1; }
            echo "La intranet respon al 8080 del web"
            SH, CheckKind::Required, 1],
        ['R3: FreeRADIUS actiu a auth', 'auth té la IP fixa 10.X.11.20, no té IP pública i FreeRADIUS és actiu. Comprova-ho: `sudo systemctl is-active freeradius` a auth.',
            "**Objectiu:** un servidor d'autenticació propi, dins de la xarxa privada.\n\n**Per què:** les credencials dels empleats són el que més val; el servidor que les guarda no pot estar exposat.",
            <<<'SH'
            need "auth (Name *auth)" "$AUTH"
            [ "$AUTH_IP" = "10.$X.11.20" ] || { echo "auth té la IP $AUTH_IP (ha de ser 10.$X.11.20)"; exit 1; }
            [ "$AUTH_PUB" = None ] || { echo "auth té IP pública ($AUTH_PUB): no en pot tenir"; exit 1; }
            [ "$(rcmd "$AUTH" 'systemctl is-active freeradius; true' | tr -d '[:space:]')" = active ] || { echo "FreeRADIUS no és actiu a auth"; exit 1; }
            echo "auth a 10.$X.11.20, sense IP pública, amb FreeRADIUS actiu"
            SH, CheckKind::Required, 1],
        ['R3: El gateway i el web són clients RADIUS, cadascun amb el seu secret', 'A clients.conf d\'auth hi ha el gateway (10.X.1.10) i el web (10.X.11.10) com a clients, amb secrets diferents. Comprova-ho a auth: `sudo grep -A3 ^client /etc/freeradius/3.0/clients.conf`.',
            "**Objectiu:** que només els dos equips que ho necessiten puguin parlar amb RADIUS, i cadascun amb la seva credencial.\n\n**Per què:** amb un secret per client, comprometre'n un no obliga a canviar-los tots.",
            <<<'SH'
            need "auth (Name *auth)" "$AUTH"
            read -r -d '' s <<'EOS' || true
            python3 - "$GWIP" "$WEBIP" <<'PY'
            import re, sys
            t = open('/etc/freeradius/3.0/clients.conf').read()
            sec = {}
            for b in re.findall(r'client\s+\S+\s*\{(.*?)\}', t, re.S):
                m = re.search(r'ipaddr\s*=\s*(\S+)', b); s = re.search(r'secret\s*=\s*(\S+)', b)
                if m and s:
                    sec[m.group(1)] = s.group(1)
            falta = [ip for ip in sys.argv[1:] if ip not in sec]
            print('FALTA ' + ' '.join(falta) if falta else ('IGUALS' if len({sec[ip] for ip in sys.argv[1:]}) < 2 else 'OK'))
            PY
            EOS
            r=$(rcmd "$AUTH" "GWIP=10.$X.1.10; WEBIP=10.$X.11.10
            $s") || { echo "No es pot llegir clients.conf d'auth: $r"; exit 1; }
            case "$r" in
              OK) echo "Clients RADIUS gateway (10.$X.1.10) i web (10.$X.11.10), amb secrets diferents" ;;
              IGUALS) echo "El gateway i el web fan servir el mateix secret RADIUS: cadascun n'ha de tenir un"; exit 1 ;;
              *) echo "A clients.conf d'auth ${r#FALTA }: no són clients"; exit 1 ;;
            esac
            SH, CheckKind::Required, 1],
        ['V5: RADIUS rebutja una clau falsa i té els empleats', 'RADIUS té els usuaris empleat1 i empleat2, i rebutja una contrasenya falsa. Comprova-ho a auth: `radtest empleat1 fals 127.0.0.1 0 testing123` (Access-Reject).',
            "**Objectiu:** que l'autenticació dels empleats sigui real: coneix els usuaris i no deixa passar una clau falsa.\n\n**Per què:** un servidor que accepta qualsevol cosa no autentica res.",
            <<<'SH'
            need "auth (Name *auth)" "$AUTH"
            read -r -d '' s <<'EOS' || true
            for e in empleat1 empleat2; do
              cat /etc/freeradius/3.0/users /etc/freeradius/3.0/mods-config/files/authorize 2>/dev/null | grep -q "^$e[[:space:]]" || echo "FALTA $e"
            done
            r=$(radtest empleat1 "fals-$$" 127.0.0.1 0 testing123 2>&1 | grep -oE 'Access-(Accept|Reject)' | head -1)
            echo "RESPOSTA ${r:-cap}"
            true
            EOS
            r=$(rcmd "$AUTH" "$s") || { echo "No es pot consultar RADIUS a auth: $r"; exit 1; }
            grep -q '^FALTA' <<< "$r" && { echo "RADIUS no té l'usuari: $(grep '^FALTA' <<< "$r" | sed 's/FALTA //' | xargs)"; exit 1; }
            [ "$(grep '^RESPOSTA' <<< "$r")" = "RESPOSTA Access-Reject" ] \
              || { echo "RADIUS no rebutja una clau falsa a localhost ($(grep '^RESPOSTA' <<< "$r"))"; exit 1; }
            echo "empleat1 i empleat2 existeixen i una clau falsa dona Access-Reject"
            SH, CheckKind::Required, 1],
        ['R4: SSH amb RADIUS al gateway', 'Al gateway, sshd fa servir PAM amb pam_radius_auth, té UsePAM, KbdInteractiveAuthentication i PasswordAuthentication a «yes», i hi ha els comptes empleat1 i empleat2. Comprova-ho: `sudo sshd -T | grep -E "usepam|kbdinteractive|passwordauth"`.',
            "**Objectiu:** que l'entrada per SSH al gateway es validi contra RADIUS.\n\n**Per què:** una sola font de credencials: donar de baixa un empleat a RADIUS el treu de tots els equips alhora.",
            null, CheckKind::Required, 1],
        ['R4: SSH amb RADIUS al web', 'Al web, el mateix que al gateway: sshd amb PAM i pam_radius_auth, les tres opcions a «yes» i els comptes dels empleats.',
            "**Objectiu:** que el web també validi els empleats contra RADIUS, amb el secret del seu propi client.\n\n**Per què:** és un segon punt d'entrada; si es queda amb contrasenyes locals, l'autenticació única no serveix.",
            null, CheckKind::Required, 1],
        ['R5: Internet només veu el tcp/80 i el udp/51820', 'Els Security Groups només tenen oberts a 0.0.0.0/0 el tcp/80 i el udp/51820 (i el tcp/443 si fas servir wstunnel). El 22 i el 8080 no. Comprova-ho amb `aws ec2 describe-security-groups`.',
            "**Objectiu:** que la VPN sigui l'única entrada nova des d'Internet.\n\n**Per què:** el 22 i el 8080 oberts a Internet farien inútil la VPN.",
            <<<'SH'
            any=$(regles_internet)
            case "$any" in
              "tcp/80/80 udp/51820/51820") echo "Internet només veu tcp/80 i udp/51820" ;;
              "tcp/443/443 tcp/80/80 udp/51820/51820") echo "Internet veu tcp/80, udp/51820 i el tcp/443 de wstunnel" ;;
              *) echo "Regles obertes a 0.0.0.0/0: ${any:-cap} (han de ser tcp/80 i udp/51820, i opcionalment el tcp/443)"; exit 1 ;;
            esac
            SH, CheckKind::Required, 1],
        ['R5: nftables del T3 (drop, 22 i 8080 només per wg0)', 'Al gateway, nftables té política drop, el 22 i el 8080 només s\'accepten per wg0, i el 8080 no es publica amb DNAT. Comprova-ho dins del gateway: `sudo nft list ruleset`.',
            "**Objectiu:** que el tallafocs del gateway només deixi passar per la VPN el que és per als empleats.\n\n**Per què:** el SG ja ho limita, però el tallafocs del gateway és la capa que decideix qui hi arriba des de dins.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            rules=$(rcmd "$GW" 'nft list ruleset') || { echo "No es pot llegir nftables al gateway: $rules"; exit 1; }
            [ "$(grep -c 'policy drop' <<< "$rules")" -ge 2 ] || { echo "Les cadenes input i forward no tenen totes política drop"; exit 1; }
            for p in 22 8080; do
              l=$(grep -E "dport ($p|[{][^}]*$p)" <<< "$rules")
              [ -n "$l" ] || { echo "No hi ha cap regla per al tcp/$p"; exit 1; }
              [ "$(grep -vc wg0 <<< "$l")" -eq 0 ] || { echo "El tcp/$p no és només per wg0: $(grep -v wg0 <<< "$l" | head -1 | xargs)"; exit 1; }
            done
            ! grep -qE 'dnat (ip )?to [0-9.]+:8080' <<< "$rules" || { echo "El gateway publica el 8080 amb DNAT"; exit 1; }
            echo "Política drop; el 22 i el 8080 només per wg0; el 8080 no es publica"
            SH, CheckKind::Required, 1],
        ['R6: Security Group del web (22, 80 i 8080 des del gateway)', 'El Security Group del web només té el tcp/22, el tcp/80 i el tcp/8080, i tots només des del Security Group del gateway. Comprova-ho amb `aws ec2 describe-security-groups`.',
            "**Objectiu:** que el web no es pugui tocar si no és a través del gateway.\n\n**Per què:** mínim privilegi: el gateway és l'únic equip que fa de pont cap al web.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            aws ec2 describe-security-groups --group-ids "$(sg_of "$WEB")" --query 'SecurityGroups[0].IpPermissions' --output json | python3 -c '
            import json, sys
            gw = sys.argv[1]
            vistos, mal = set(), []
            for p in json.load(sys.stdin):
                r = (p["IpProtocol"], p.get("FromPort"), p.get("ToPort"))
                vistos.add(r)
                if p.get("IpRanges") or p.get("Ipv6Ranges") or {g["GroupId"] for g in p.get("UserIdGroupPairs", [])} != {gw}:
                    mal.append("%s/%s" % (r[0], r[1]))
            esperats = {("tcp", n, n) for n in (22, 80, 8080)}
            if vistos != esperats:
                print("Regles del web: %s (han de ser tcp/22, 80 i 8080)" % ", ".join(sorted("%s/%s" % (a, b) for a, b, c in vistos)))
                sys.exit(1)
            if mal:
                print("No són només des del Security Group del gateway: %s" % ", ".join(mal))
                sys.exit(1)
            print("El web accepta el tcp/22, 80 i 8080 només des del Security Group del gateway")' "$(sg_of "$GW")"
            SH, CheckKind::Required, 1],
        ['R6: Security Group d\'auth (udp/1812 des del gateway i el web)', 'El Security Group d\'auth només té el udp/1812, i només des del Security Group del gateway i el del web. Comprova-ho amb `aws ec2 describe-security-groups`.',
            "**Objectiu:** que només els dos clients puguin parlar amb RADIUS.\n\n**Per què:** auth guarda les credencials; ha de ser la instància amb menys superfície de tota la VPC.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"; need "auth (Name *auth)" "$AUTH"
            aws ec2 describe-security-groups --group-ids "$(sg_of "$AUTH")" --query 'SecurityGroups[0].IpPermissions' --output json | python3 -c '
            import json, sys
            ok = {sys.argv[1], sys.argv[2]}
            perms = json.load(sys.stdin)
            if {(p["IpProtocol"], p.get("FromPort"), p.get("ToPort")) for p in perms} != {("udp", 1812, 1812)}:
                print("Les regles d\x27auth no són només udp/1812")
                sys.exit(1)
            origens = {g["GroupId"] for p in perms for g in p.get("UserIdGroupPairs", [])}
            if any(p.get("IpRanges") or p.get("Ipv6Ranges") for p in perms) or origens != ok:
                print("El udp/1812 ha de venir només dels Security Groups del gateway i del web")
                sys.exit(1)
            print("auth accepta el udp/1812 només des del gateway i el web")' "$(sg_of "$GW")" "$(sg_of "$WEB")"
            SH, CheckKind::Required, 1],
        ['R6: La NACL privada deixa passar el 22 i el 8080 des del gateway', 'La NACL de les subxarxes privades deixa entrar el tcp/22 i el tcp/8080 des del gateway (10.X.1.10). Comprova-ho amb `aws ec2 describe-network-acls`.',
            "**Objectiu:** que la capa de subxarxa deixi passar el que la VPN necessita.\n\n**Per què:** el 22 no és un port efímer: sense regla pròpia a la NACL, l'SSH al web s'esgota mentre la intranet sí que funciona.",
            <<<'SH'
            acl=$(acl_of private-a); need "la NACL de private-a ($(net private-a))" "$acl"
            for p in 22 8080; do
              d=$(nacl_decideix "$acl" "$p" "10.$X.1.10")
              [ "${d%% *}" = allow ] || { echo "La NACL privada no deixa entrar el tcp/$p des del gateway ($d)"; exit 1; }
            done
            echo "La NACL privada deixa entrar el tcp/22 i el tcp/8080 des del gateway"
            SH, CheckKind::Required, 1],
        ['V9: AMI del gateway, del web i d\'auth', 'Hi ha una AMI pròpia de cada instància (gateway, web i auth), perquè el T3 sobrevisqui a un destroy/build. Comprova-ho amb `aws ec2 describe-images --owners self`.',
            "**Objectiu:** que tot el que s'ha configurat dins de les instàncies es pugui reconstruir.\n\n**Per què:** la configuració de dins (WireGuard, nftables, FreeRADIUS, PAM) viu a la instància; sense AMI, es perd.",
            <<<'SH'
            for nom in gateway web auth; do
              a=$(aws ec2 describe-images --owners self --filters "Name=name,Values=tecnoserveis-$NC-$nom" --query 'Images[0].ImageId' --output text)
              [ -n "$a" ] && [ "$a" != None ] || { echo "Falta l'AMI de $nom (nom tecnoserveis-$NC-$nom)"; exit 1; }
            done
            echo "AMI del gateway, del web i d'auth"
            SH, CheckKind::Required, 1],
        ['V1: Ping al gateway per la VPN', 'Des del WSL, amb la VPN aixecada, el gateway respon al ping a 10.200.X.1. Comprova-ho: `ping -c 2 10.200.X.1`.',
            "**Objectiu:** que el túnel funcioni de veritat, no només que existeixi la configuració.\n\n**Per què:** és el primer que prova qualsevol empleat. Sense això, no n'hi ha cap altra.",
            <<<'SH'
            vpn_activa || { echo "El gateway 10.200.$X.1 no respon al ping per la VPN: aixeca el túnel al WSL (sudo wg-quick up empleatN)"; exit 1; }
            echo "El gateway respon al ping per la VPN (10.200.$X.1)"
            SH, CheckKind::Required, 1],
        ['V2: La intranet es veu per la VPN', 'Des del WSL, amb la VPN aixecada, la intranet respon a http://10.X.11.10:8080. Comprova-ho: `curl -s http://10.X.11.10:8080`.',
            "**Objectiu:** que l'empleat arribi a la intranet pel camí previst.\n\n**Per què:** és el servei que justifica tot el tiquet.",
            <<<'SH'
            vpn_activa || { echo "La VPN no és activa al WSL (el gateway 10.200.$X.1 no respon)"; exit 1; }
            curl -s -m 5 "http://$WEB_IP:8080" | grep -q Intranet || { echo "La intranet no respon a http://$WEB_IP:8080 per la VPN"; exit 1; }
            echo "La intranet es veu per la VPN"
            SH, CheckKind::Required, 1],
        ['V3: Per la VPN no s\'arriba a res més', 'Per la VPN, els ports 80 i 3306 del web i el 22, 80 i 3306 d\'auth no responen: la VPN només dona accés al que cal. Comprova-ho: `nmap -Pn -p 22,80,3306 10.X.11.10 10.X.11.20`.',
            "**Objectiu:** que la VPN no sigui una porta oberta a tota la xarxa privada.\n\n**Per què:** mínim privilegi: un empleat només ha d'arribar a la intranet i a l'SSH del gateway i del web.",
            <<<'SH'
            vpn_activa || { echo "La VPN no és activa al WSL (el gateway 10.200.$X.1 no respon)"; exit 1; }
            oberts=""
            for p in 80 3306; do obert "$WEB_IP" "$p" && oberts="$oberts web:$p"; done
            for p in 22 80 3306; do obert "10.$X.11.20" "$p" && oberts="$oberts auth:$p"; done
            [ -z "$oberts" ] || { echo "Per la VPN responen:$oberts"; exit 1; }
            echo "Per la VPN no responen el 80 ni el 3306 del web, ni el 22, 80 i 3306 d'auth"
            SH, CheckKind::Required, 1],
        ['V4: El gateway i el web demanen contrasenya per SSH', 'Per la VPN, el gateway (10.200.X.1) i el web (10.X.11.10) ofereixen autenticació keyboard-interactive i contrasenya al port 22. Comprova-ho: `ssh -o PreferredAuthentications=none empleat1@10.200.X.1`.',
            "**Objectiu:** que el SSH dels dos equips accepti la contrasenya de RADIUS (via PAM).\n\n**Per què:** és el que diferencia aquest SSH del de les altres tiquets, que només acceptava claus.",
            <<<'SH'
            vpn_activa || { echo "La VPN no és activa al WSL (el gateway 10.200.$X.1 no respon)"; exit 1; }
            command -v ssh >/dev/null || { echo "Falta el client ssh al WSL"; exit 1; }
            for dest in "10.200.$X.1" "$WEB_IP"; do
              out=$(ssh -o BatchMode=yes -o ConnectTimeout=6 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
                    -o PreferredAuthentications=none -o PubkeyAuthentication=no "empleat1@$dest" true 2>&1)
              grep -q 'keyboard-interactive' <<< "$out" && grep -q 'password' <<< "$out" \
                || { echo "El SSH de $dest no ofereix keyboard-interactive i password: $(tail -1 <<< "$out" | cut -c1-120)"; exit 1; }
            done
            echo "El gateway i el web ofereixen keyboard-interactive i contrasenya"
            SH, CheckKind::Required, 1],
        ['V7: Internet no veu el 22 ni el 8080 del gateway', 'Des d\'Internet, el 22 i el 8080 de la IP pública del gateway no responen i la web pública (80) sí. Comprova-ho: `nmap -Pn -p 22,8080,80 <IP-pública-del-gateway>`.',
            "**Objectiu:** que afegir la VPN no hagi obert res més del que hi havia.\n\n**Per què:** és el risc d'aquest tiquet: l'SSH i la intranet han de ser només per la VPN.",
            <<<'SH'
            need "la IP pública del gateway" "$GW_PUB"
            for p in 22 8080; do
              ! obert "$GW_PUB" "$p" || { echo "El port $p de $GW_PUB és obert a Internet"; exit 1; }
            done
            curl -s -m 8 -o /dev/null "http://$GW_PUB/" || { echo "La web pública (http://$GW_PUB) no respon"; exit 1; }
            echo "El 22 i el 8080 de $GW_PUB no responen; la web pública sí"
            SH, CheckKind::Required, 1],
        ['W: wstunnel restringit a WireGuard (si el 443 és obert)', 'Si has obert el tcp/443 per fer servir wstunnel, el servei és actiu i només porta cap a localhost:51820 (no és un túnel obert a tot). Si no l\'has obert, no hi ha res a comprovar. Comprova-ho dins del gateway: `systemctl cat wstunnel`.',
            "**Objectiu:** que el túnel wstunnel, si existeix, només serveixi per portar WireGuard.\n\n**Per què:** un wstunnel sense `--restrict-to` és un proxy obert a Internet a través del teu gateway.",
            <<<'SH'
            case "$(regles_internet)" in
              *tcp/443/443*) ;;
              *) echo "El tcp/443 no és obert: sense wstunnel, res a comprovar"; exit 0 ;;
            esac
            need "el gateway (Name *gateway)" "$GW"
            [ "$(rcmd "$GW" 'systemctl is-active wstunnel; true' | tr -d '[:space:]')" = active ] || { echo "El tcp/443 és obert però el servei wstunnel no és actiu"; exit 1; }
            n=$(rcmd "$GW" 'systemctl cat wstunnel | grep -c "restrict-to.*localhost:51820"; true' | tr -d '[:space:]')
            [ "${n:-0}" -ge 1 ] 2>/dev/null || { echo "wstunnel no porta --restrict-to localhost:51820"; exit 1; }
            echo "wstunnel actiu i restringit a localhost:51820"
            SH, CheckKind::Optional, 0],
    ];

    /** @return array<string, string> el cos de cada check, pel nom (amb els de PAM resolts) */
    private static function bodies(): array
    {
        $bodies = [];
        foreach (self::CHECKS as [$name, , , $body]) {
            $bodies[$name] = $body ?? match ($name) {
                'R4: SSH amb RADIUS al gateway' => str_replace(['@NOM@', '@VAR@'], ['gateway', '$GW'], self::PAM),
                'R4: SSH amb RADIUS al web' => str_replace(['@NOM@', '@VAR@'], ['web', '$WEB'], self::PAM),
            };
        }

        return $bodies;
    }

    /**
     * El codi que ha de tenir cada check i acció del tiquet, pel nom.
     *
     * @return array{checks: array<string, string>}
     */
    public static function codes(): array
    {
        $checks = array_map(fn (string $body) => TecnoServeisCode::build($body), self::bodies());

        return ['checks' => $checks];
    }

    /** @return array<string, string> la descripció de cada check, pel nom */
    public static function descriptions(): array
    {
        return array_column(self::CHECKS, 1, 0);
    }

    public function run(): void
    {
        $code = env('HEIMDEYE_INCIDENCIA_MODUL', $this->moduleCode);
        $module = Module::where('codi', $code)
            ->orderByDesc(AcademicYear::select('es_actual')->whereColumn('cursos.id', 'moduls.curs_id')->limit(1))
            ->orderByDesc('id')
            ->first() ?? throw new RuntimeException("No existeix el mòdul {$code}");
        $number = (int) env('HEIMDEYE_INCIDENCIA_BLOC', $this->blockNumber);
        $block = Block::where('modul_id', $module->id)->where('numero', $number)->first()
            ?? throw new RuntimeException("El mòdul {$code} no té el bloc {$number}: crea'l primer");
        $title = env('HEIMDEYE_T3_TASCA', $this->taskTitle);
        // La tasca la crea el professor (amb l'enunciat); si no hi és, es crea buida.
        $task = $block->tasks()->firstOrCreate(['titol' => $title], ['posicio' => ($block->tasks()->max('posicio') ?? 0) + 1]);

        $this->clean($block, $task);

        $from = env('HEIMDEYE_T3_ACTIU_DES', $this->activeFrom);
        $until = env('HEIMDEYE_T3_ACTIU_FINS', $this->activeUntil);
        $bodies = self::bodies();
        $checks = [];
        foreach (self::CHECKS as $i => [$name, $description, $info, , $kind, $points]) {
            $checks[$name] = $this->check($task, $i + 1, $name, $description, $info, TecnoServeisCode::build($bodies[$name]), $kind, $points, $from, $until);
        }

        $this->command?->info("Creats: {$task->checks()->count()} checks (actius del {$from} al {$until}) a «{$task->titol}».");
    }

    /** Esborra els checks de la tasca. La resta del bloc no es toca. */
    private function clean(Block $block, Task $task): void
    {
        $checks = $task->checks()->delete();

        $this->command?->info("Esborrat: {$checks} checks de «{$task->titol}».");
    }

    private function check(Task $task, int $position, string $name, string $description, string $info, string $code, CheckKind $kind, int $points, string $from, string $until): Check
    {
        $check = $task->checks()->create([
            'nom' => $name,
            'descripcio' => $description,
            'info' => $info,
            'executor' => Executor::Agent,
            'tipus' => $kind,
            'punts' => $points,
            'es_actiu' => null, // segueix actiu_des / actiu_fins: només la setmana del T3
            'actiu_des' => $from,
            'actiu_fins' => $until,
            'posicio' => $position,
        ]);
        $check->versions()->create([
            'versio' => 1,
            'temps_max_seg' => 120,
            'codi' => $code,
        ]);

        return $check;
    }
}
