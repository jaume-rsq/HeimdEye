<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\Module;
use App\Models\Task;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Bloc 4 de 0378: «Servidors proxy: TecnoServeis». Checks bàsics dels dos tiquets, només amb el que és
 * més important de cada proba d'acceptació (P1-P12 del tiquet 1 i Q2-Q6 del tiquet 2), tots amb
 * l'agent amb rol «aws» (AWS CLI i python3 amb les credencials del Learner Lab de l'alumne; el gateway,
 * el web i l'rproxy es miren per SSM Run Command). Fa servir les peces de TecnoServeisCode.
 *
 * Decisions de disseny:
 *  - Un check només llegeix. Les proves que canvien coses (P5, la franja horària, o Q1, que exigeix
 *    no tenir el transparent) no són checks. Les peticions que fan són innocents (un curl des del web).
 *  - Les que depenen d'un nom o d'una contrasenya que tria l'alumne (dominis bloquejats, la contrasenya
 *    d'empleat1) es comproven pel que queda al registre de Squid, no amb dades que HeimdEye no té.
 *  - Els checks del tiquet 1 han de continuar passant al tiquet 2: per això el mínim privilegi accepta
 *    el tcp/80 i el tcp/443, i P9/P10 segueixen redireccions (al T2 el HTTP redirigeix a HTTPS).
 *  - El domini és <usuari>.julioantonio.cat (HE_USUARI, el primer tros del correu del centre).
 *
 * Idempotent: no esborra res ni toca el que ja hi és. Si el codi d'un check ha canviat, hi afegeix una
 * versió nova (els resultats dels alumnes es conserven). El bloc, les tasques i els checks es creen si
 * falten. Els dies d'activació: HEIMDEYE_B4_T1_DES / HEIMDEYE_B4_T2_DES (per defecte, 24/11 i 1/12).
 *
 * Mòdul 0378 del curs actual, bloc 4 (HEIMDEYE_B4_MODUL, HEIMDEYE_B4_BLOC). S'executa amb:
 *   php artisan db:seed --class=Block04ProxySeeder --force
 */
class Block04ProxySeeder extends Seeder
{
    public string $moduleCode = '0378';

    public int $blockNumber = 4;

    /** Dies d'activació de cada tiquet (es poden canviar amb HEIMDEYE_B4_T1_DES i HEIMDEYE_B4_T2_DES). */
    public string $ticket1From = '2026-11-24';

    public string $ticket2From = '2026-12-01';

    /** Peces addicionals de codi (mateix format que TecnoServeisCode::PIECES). */
    private const EXTRA = [
        'rproxy' => ['/\$\{?RP\b/', <<<'SH'
            read -r RP RP_IP RP_PUB <<< "$(inst rproxy)"
            SH],
        'cw' => ['/(?<![\w-])cw(?![\w-])/', <<<'SH'
            # curl des del web (SSM Run Command): només escriu el codi HTTP. Els arguments són els de curl.
            cw() { rcmd "$WEB" "curl -s -o /dev/null -w '%{http_code}' --max-time 20 $*" | tr -d '\r\n'; }
            SH],
        'DOM' => ['/\$\{?DOM\b/', <<<'SH'
            [ -n "${HE_USUARI:-}" ] || { echo "Falta HE_USUARI (el vostre subdomini de julioantonio.cat)"; exit 1; }
            DOM=$HE_USUARI.julioantonio.cat
            SH],
    ];

    /** [nom, descripció per a l'alumne, info (objectiu i justificació), cos] */
    private const TICKET_1 = [
        ['P1: El proxy exigeix autenticació', 'Una petició pel proxy (gateway, port 3128) sense credencials rep un 407. Comprova-ho des del web: `curl -s -o /dev/null -w "%{http_code}\n" -x http://<IP-gateway>:3128 http://example.com`.',
            "**Objectiu:** que Squid no serveixi res a qui no s'ha identificat.\n\n**Per què:** el proxy és l'únic lloc on queda registrat qui demana què; sense autenticació, el registre no diu res i qualsevol màquina de la xarxa el pot fer servir.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            c=$(cw -x "http://$GW_IP:3128" http://example.com)
            [ "$c" = 407 ] || { echo "Sense credencials el proxy $GW_IP:3128 respon '$c' i hauria de respondre 407"; exit 1; }
            echo "Sense credencials, el proxy $GW_IP:3128 respon 407"
            SH],
        ['P2: empleat1 navega pel proxy', 'Al registre de Squid hi ha peticions amb codi 200 de l\'usuari empleat1. Comprova-ho: `sudo grep empleat1 /var/log/squid/access.log | tail -n 3` (navega abans amb empleat1 pel proxy).',
            "**Objectiu:** demostrar que l'autenticació accepta un usuari vàlid i que el registre en guarda el nom.\n\n**Per què:** «qui demana què» és el requisit central del CTO.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            n=$(rcmd "$GW" "grep -cE '/200 [0-9]+ [A-Z]+ [^ ]+ empleat1 ' /var/log/squid/access.log || true")
            [ "${n:-0}" -gt 0 ] || { echo "Al registre de Squid no hi ha cap petició d'empleat1 amb codi 200: navega pel proxy amb empleat1"; exit 1; }
            echo "empleat1 té $n peticions amb codi 200 al registre"
            SH],
        ['P3: La cau funciona i és limitada', 'Hi ha respostes servides des de la cau (TCP_HIT o TCP_MEM_HIT) al registre, i el disc de la cau (cache_dir) no passa de 500 MB. Comprova-ho: `sudo grep HIT /var/log/squid/access.log | tail -n 2` i `grep cache_dir /etc/squid/squid.conf`.',
            "**Objectiu:** que una segona petició del mateix objecte no tingui cost de xarxa, amb la cau acotada.\n\n**Per què:** és el benefici econòmic del proxy; una cau sense límit pot omplir el disc del gateway.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            n=$(rcmd "$GW" "grep -cE 'TCP_(MEM_)?HIT/' /var/log/squid/access.log || true")
            [ "${n:-0}" -gt 0 ] || { echo "No hi ha cap TCP_HIT ni TCP_MEM_HIT al registre: demaneu dos cops un objecte que es pugui guardar"; exit 1; }
            mb=$(rcmd "$GW" "grep -rhE '^cache_dir ' /etc/squid/squid.conf /etc/squid/conf.d 2>/dev/null | head -n 1 | awk '{print \$4}'")
            [[ "$mb" =~ ^[0-9]+$ ]] || { echo "No trobo cap cache_dir a la configuració de Squid"; exit 1; }
            [ "$mb" -le 500 ] || { echo "El disc de la cau és de $mb MB i el màxim és 500"; exit 1; }
            echo "$n respostes des de la cau; cache_dir de $mb MB"
            SH],
        ['P4: Els dominis bloquejats es bloquegen', 'Al registre hi ha peticions denegades (TCP_DENIED/403) a un domini de la vostra llista de bloquejats. Comprova-ho: `sudo grep TCP_DENIED /var/log/squid/access.log | tail -n 1`.',
            "**Objectiu:** que el proxy impedeixi l'accés als dominis de la llista.\n\n**Per què:** és el control d'ús d'Internet que demana l'empresa; el 403 amb TCP_DENIED és l'evidència que ho ha decidit Squid.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            n=$(rcmd "$GW" "grep -c 'TCP_DENIED/403' /var/log/squid/access.log || true")
            [ "${n:-0}" -gt 0 ] || { echo "No hi ha cap TCP_DENIED/403 al registre: proveu un domini bloquejat amb empleat1"; exit 1; }
            echo "$n peticions denegades (403) al registre"
            SH],
        ['P6: El proxy no és accessible des d\'Internet', 'El port 3128 del gateway no respon des d\'Internet i cap Security Group no l\'obre a fora. Comprova-ho des del WSL: `nmap -Pn -p 3128 <IP-pública-gateway>`.',
            "**Objectiu:** que només les xarxes privades puguin fer servir el proxy.\n\n**Per què:** un proxy obert a Internet es converteix en un proxy obert per a tothom, amb la IP i el compte de l'empresa.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "la IP pública del gateway" "$GW_PUB"
            obert "$GW_PUB" 3128 && { echo "El 3128 del gateway ($GW_PUB) respon des d'Internet"; exit 1; }
            G=$(sg_of "$GW"); need "el SG del gateway" "$G"
            sg_permet "$G" 3128 8.8.8.8 && { echo "El Security Group $G deixa entrar el tcp/3128 des d'Internet"; exit 1; }
            echo "El 3128 del gateway no respon des d'Internet i el SG no l'obre"
            SH],
        ['P7: El web s\'actualitza pel proxy sense usuari', 'Al registre de Squid hi ha peticions del web (la seva IP) a ubuntu.com. Comprova-ho: `sudo apt update` al web i `sudo grep ubuntu.com /var/log/squid/access.log | tail -n 3` al gateway.',
            "**Objectiu:** que els servidors s'actualitzin pel proxy, identificats per la seva IP.\n\n**Per què:** una màquina no té usuari; permetre-li només els dominis d'actualització limita el que pot fer si la comprometen.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            n=$(rcmd "$GW" "grep -E \"^[0-9.]+ +[0-9]+ $WEB_IP \" /var/log/squid/access.log | grep -c 'ubuntu.com' || true")
            [ "${n:-0}" -gt 0 ] || { echo "Al registre no hi ha peticions del web ($WEB_IP) a ubuntu.com: fes un apt update al web"; exit 1; }
            echo "El registre mostra $n peticions del web ($WEB_IP) a ubuntu.com"
            SH],
        ['P8: GoAccess llegeix el registre de Squid', 'GoAccess és instal·lat al gateway i, amb el format de Squid, interpreta les peticions del registre (peticions vàlides, no «Format Errors»). Comprova-ho: `sudo goaccess /var/log/squid/access.log --log-format=... --date-format=%s --time-format=%s`.',
            "**Objectiu:** poder veure el trànsit en un informe i no llegint logs.\n\n**Per què:** el CTO vol un informe gràfic; saber indicar el format a GoAccess és el que fa que l'informe tingui dades.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            n=$(rcmd "$GW" "command -v goaccess >/dev/null || { echo SENSE; exit 0; }
            goaccess /var/log/squid/access.log --log-format='%x.%^ %~%L %h %^/%s %b %m %U %^ %^/%^ %^' --date-format='%s' --time-format='%s' -o /tmp/he-ga.json >/dev/null 2>&1
            grep -o '\"valid_requests\": *[0-9]*' /tmp/he-ga.json | grep -o '[0-9]*\$'; rm -f /tmp/he-ga.json")
            [ "$n" != SENSE ] || { echo "GoAccess no és instal·lat al gateway (sudo apt install goaccess)"; exit 1; }
            [[ "$n" =~ ^[0-9]+$ ]] && [ "$n" -gt 0 ] || { echo "GoAccess no interpreta cap petició del registre: reviseu --log-format, --date-format i --time-format"; exit 1; }
            echo "GoAccess interpreta $n peticions del registre de Squid"
            SH],
        ['P9: www i app serveixen pàgines diferents', 'Les adreces www.<alumne>.julioantonio.cat i app.<alumne>.julioantonio.cat responen 200 des d\'Internet, per la mateixa IP, amb una pàgina diferent cada una. Comprova-ho: `curl -sL http://www.<alumne>.julioantonio.cat` i el mateix amb app.',
            "**Objectiu:** que un sol punt d'entrada (l'rproxy) serveixi dos webs segons el nom demanat.\n\n**Per què:** és l'encaminament per nom (capçalera Host), la base de publicar diversos serveis amb una sola IP pública.",
            <<<'SH'
            for h in www app; do
              c=$(curl -skL --max-redirs 3 -m 12 -o "/tmp/he-$h.html" -w '%{http_code}' "http://$h.$DOM/")
              [ "$c" = 200 ] || { echo "http://$h.$DOM respon '$c' i hauria de respondre 200"; rm -f /tmp/he-www.html /tmp/he-app.html; exit 1; }
            done
            same=0; cmp -s /tmp/he-www.html /tmp/he-app.html && same=1
            grep -qi www /tmp/he-www.html || w=1
            grep -qi app /tmp/he-app.html || a=1
            rm -f /tmp/he-www.html /tmp/he-app.html
            [ "$same" = 0 ] || { echo "www i app serveixen la mateixa pàgina: l'rproxy no encamina per nom"; exit 1; }
            [ -z "${w:-}" ] && [ -z "${a:-}" ] || { echo "Les pàgines no diuen a quin virtualhost s'ha arribat (www o app)"; exit 1; }
            echo "www.$DOM i app.$DOM serveixen pàgines diferents"
            SH],
        ['P11: El web no és accessible directament', 'Des del gateway, el tcp/80 del web no respon: només l\'rproxy hi arriba. Comprova-ho al gateway: `curl -s --connect-timeout 5 http://<IP-web>`.',
            "**Objectiu:** que el web només s'atengui a través de l'rproxy.\n\n**Per què:** si es pot arribar al servidor per un altre camí, es poden saltar les proteccions de l'rproxy.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            r=$(rcmd "$GW" "curl -s -o /dev/null --connect-timeout 5 http://$WEB_IP; echo \$?")
            [ "$r" != 0 ] || { echo "Des del gateway el web ($WEB_IP) respon pel tcp/80: només hauria d'acceptar l'rproxy"; exit 1; }
            echo "Des del gateway el web no respon (curl surt amb $r)"
            SH],
        ['P12: El web registra l\'adreça real del client', 'El registre de nginx del web (/var/log/nginx/access.log) mostra la IP pública de qui hi ha fet la petició, no la de l\'rproxy. Comprova-ho: `sudo tail -n 2 /var/log/nginx/access.log` al web.',
            "**Objectiu:** que el web sàpiga qui hi ha demanat, tot i el proxy pel mig.\n\n**Per què:** sense l'adreça real (X-Forwarded-For o real_ip) els registres i qualsevol bloqueig per IP no serveixen de res.",
            <<<'SH'
            need "el web (Name *web)" "$WEB"
            MY=$(curl -s -m 6 https://checkip.amazonaws.com | tr -d '[:space:]')
            [[ "$MY" =~ ^[0-9.]+$ ]] || { echo "No he pogut esbrinar la IP pública d'aquest equip"; exit 1; }
            curl -skL -o /dev/null -m 12 "http://www.$DOM/"
            sleep 1
            n=$(rcmd "$WEB" "tail -n 40 /var/log/nginx/access.log | grep -cF '$MY' || true")
            [ "${n:-0}" -gt 0 ] || { echo "El registre del web no mostra la IP del client ($MY): reviseu X-Forwarded-For o real_ip"; exit 1; }
            echo "El registre del web mostra la IP real del client ($MY)"
            SH],
        ['R: L\'Elastic IP és de l\'rproxy i el web no té IP pública', 'L\'Elastic IP eip-<NomCognom> és associada a l\'rproxy i el web no té cap IP pública. Comprova-ho: `aws ec2 describe-addresses --filters Name=tag:Name,Values=eip-*`.',
            "**Objectiu:** que el nom de l'empresa apunti a una IP que no canvia i que només l'rproxy la tingui.\n\n**Per què:** una IP elàstica estable és el que permet publicar noms de DNS; un web sense IP pública no es pot adreçar des d'Internet.",
            <<<'SH'
            need "l'rproxy (Name *rproxy)" "$RP"; need "el web (Name *web)" "$WEB"
            read -r EIP EIPINST <<< "$(aws ec2 describe-addresses --filters "Name=tag:Name,Values=eip-$NC" --query 'Addresses[0].[PublicIp,InstanceId]' --output text)"
            need "l'Elastic IP (etiqueta Name eip-$NC)" "$EIP"
            [ "$EIPINST" = "$RP" ] || { echo "L'Elastic IP $EIP no és associada a l'rproxy ($RP) sinó a '$EIPINST'"; exit 1; }
            wp=$(aws ec2 describe-instances --instance-ids "$WEB" --query 'Reservations[0].Instances[0].PublicIpAddress' --output text)
            [ -z "$wp" ] || [ "$wp" = None ] || { echo "El web té la IP pública $wp"; exit 1; }
            echo "L'Elastic IP $EIP és de l'rproxy i el web no té IP pública"
            SH],
        ['R: Mínim privilegi: només el 80 (i el 443) a Internet, cap 22', 'Cap Security Group de la VPC no obre a Internet res que no sigui el tcp/80 i el tcp/443, i cap no obre el 22. Comprova-ho: `aws ec2 describe-security-groups --filters Name=vpc-id,Values=<vpc-id>`.',
            "**Objectiu:** que l'únic que veu Internet sigui el que s'ha de publicar.\n\n**Per què:** cada port obert és superfície d'atac; el 22 a Internet és la porta d'entrada més atacada i amb Session Manager no cal.",
            <<<'SH'
            for p in $(regles_internet); do
              case $p in tcp/80/80|tcp/443/443) ;; *) echo "Hi ha una regla d'entrada oberta a Internet que no és el 80 ni el 443: $p"; exit 1 ;; esac
            done
            n22=$(aws ec2 describe-security-groups --filters "Name=vpc-id,Values=$VPC" \
              --query 'length(SecurityGroups[].IpPermissions[?IpProtocol==`-1` || (FromPort<=`22` && ToPort>=`22`)][])' --output text)
            [ "$n22" = 0 ] || { echo "Hi ha $n22 regles d'entrada que obren el 22 (o tot el trànsit) en algun Security Group"; exit 1; }
            echo "Oberts a Internet: $(regles_internet); cap Security Group no obre el 22"
            SH],
    ];

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    private const TICKET_2 = [
        ['Q2: El mode transparent intercepta el HTTP', 'Una petició HTTP del web, sense cap proxy configurat, apareix al registre de Squid. Comprova-ho: `curl http://example.com/prova` al web i `sudo tail -n 2 /var/log/squid/access.log` al gateway.',
            "**Objectiu:** que el tcp/80 de les subxarxes privades passi per Squid sense configurar res al client.\n\n**Per què:** no es pot dependre que cada empleat configuri el proxy; el desviament ho fa el gateway per ell.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            tag=heimdeye-q2-$RANDOM
            c=$(cw "http://example.com/$tag")
            sleep 2
            n=$(rcmd "$GW" "grep -c '$tag' /var/log/squid/access.log || true")
            [ "${n:-0}" -gt 0 ] || { echo "Una petició HTTP del web sense proxy (codi '$c') no apareix al registre de Squid: el tcp/80 no s'intercepta"; exit 1; }
            echo "La petició del web, sense proxy, és al registre de Squid (codi $c)"
            SH],
        ['Q3: El HTTPS continua funcionant', 'Una petició HTTPS del web a Internet, sense proxy, respon 200: el desviament del 80 no ha trencat el 443 (ni Session Manager). Comprova-ho: `curl -s -o /dev/null -w "%{http_code}\n" https://example.com` al web.',
            "**Objectiu:** que només el HTTP es desviï i que el HTTPS segueixi sortint pel NAT.\n\n**Per què:** interceptar el 443 sense pensar-hi deixa sense accés a apt i a Session Manager; cal entendre per què el proxy no el veu.",
            <<<'SH'
            need "el web (Name *web)" "$WEB"
            c=$(cw https://example.com)
            [ "$c" = 200 ] || { echo "Des del web, https://example.com respon '$c' i hauria de respondre 200"; exit 1; }
            echo "Des del web, el HTTPS surt i respon 200"
            SH],
        ['Q4: www i app serveixen el seu virtualhost per HTTPS', 'Les adreces https://www.<alumne>.julioantonio.cat i https://app.<alumne>.julioantonio.cat responen 200, amb pàgines diferents. Comprova-ho: `curl -s https://www.<alumne>.julioantonio.cat`.',
            "**Objectiu:** publicar els dos webs xifrats pel mateix punt d'entrada.\n\n**Per què:** és el que veu un client real: HTTPS amb el nom de l'empresa.",
            <<<'SH'
            for h in www app; do
              c=$(curl -sk -m 12 -o "/tmp/he-$h.html" -w '%{http_code}' "https://$h.$DOM/")
              [ "$c" = 200 ] || { echo "https://$h.$DOM respon '$c' i hauria de respondre 200"; rm -f /tmp/he-www.html /tmp/he-app.html; exit 1; }
            done
            same=0; cmp -s /tmp/he-www.html /tmp/he-app.html && same=1
            rm -f /tmp/he-www.html /tmp/he-app.html
            [ "$same" = 0 ] || { echo "www i app serveixen la mateixa pàgina per HTTPS"; exit 1; }
            echo "https://www.$DOM i https://app.$DOM serveixen pàgines diferents"
            SH],
        ['Q5a: Certificat de staging obtingut des del WSL', 'Al WSL hi ha un certificat de l\'entorn de proves (staging) de Let\'s Encrypt per a <alumne>.julioantonio.cat i *.<alumne>.julioantonio.cat, encara vigent, i el CNAME _acme-challenge apunta a acme-dns. Comprova-ho: `openssl x509 -in ~/certbot/conf/live/<alumne>-staging/fullchain.pem -noout -issuer -dates -ext subjectAltName` i `dig +short CNAME _acme-challenge.<alumne>.julioantonio.cat`.',
            "**Objectiu:** haver practicat tot el procés de la validació DNS-01 a l'entorn de proves abans de demanar el certificat real.\n\n**Per què:** staging no té els límits d'emissió de producció, que són compartits per tota la classe; equivocar-se allà no costa res, equivocar-se a producció pot bloquejar els companys durant una setmana.",
            <<<'SH'
            command -v openssl >/dev/null || { echo "Falta openssl en aquest equip"; exit 1; }
            command -v dig >/dev/null || { echo "Falta dig (sudo apt install -y dnsutils)"; exit 1; }
            [ -n "${HE_USUARI:-}" ] || { echo "Falta HE_USUARI (el vostre subdomini de julioantonio.cat)"; exit 1; }
            DOM=$HE_USUARI.julioantonio.cat
            cn=$(dig +short CNAME "_acme-challenge.$DOM" +time=3 +tries=1 | sed 's/\.$//')
            [[ "$cn" == *.acme.julioantonio.cat ]] || { echo "_acme-challenge.$DOM no té el CNAME cap a acme.julioantonio.cat (surt '${cn:-res}')"; exit 1; }
            f=""
            for h in "$HOME" /home/*; do
              [ -e "$h/certbot/conf/live/$HE_USUARI-staging/fullchain.pem" ] && { f=$h/certbot/conf/live/$HE_USUARI-staging/fullchain.pem; break; }
            done
            [ -n "$f" ] || { echo "No trobo el certificat de staging (--cert-name $HE_USUARI-staging) a ~/certbot/conf/live: feu el pas 4 de la guia al WSL"; exit 1; }
            [ -r "$f" ] || { echo "No puc llegir $f (l'heu demanat amb sudo? feu-ho amb el vostre usuari)"; exit 1; }
            crt=$(openssl x509 -in "$f" -noout -issuer -ext subjectAltName 2>/dev/null)
            [[ "$crt" == *STAGING* || "$crt" == *"Fake LE"* ]] || { echo "El certificat de $f no és de l'entorn de proves (staging): $(grep -i issuer <<< "$crt")"; exit 1; }
            [[ "$crt" == *"DNS:$DOM"* && "$crt" == *"DNS:*.$DOM"* ]] || { echo "El certificat de staging no cobreix $DOM i *.$DOM"; exit 1; }
            openssl x509 -in "$f" -noout -checkend 0 >/dev/null || { echo "El certificat de staging ha caducat"; exit 1; }
            echo "Certificat de staging vàlid per a $DOM i *.$DOM, i el CNAME apunta a acme-dns"
            SH],
        ['Q5: El certificat és real i cobreix el wildcard', 'curl sense -k valida el certificat de www.<alumne>.julioantonio.cat, l\'emissor és Let\'s Encrypt de producció i cobre <alumne>.julioantonio.cat i *.<alumne>.julioantonio.cat. Comprova-ho: `echo | openssl s_client -connect www.<alumne>.julioantonio.cat:443 -servername www.<alumne>.julioantonio.cat | openssl x509 -noout -issuer -ext subjectAltName`.',
            "**Objectiu:** un únic certificat de confiança per a tots els noms de l'empresa.\n\n**Per què:** un certificat de staging o que no cobreix el wildcard fa que els navegadors avisin; el wildcard evita demanar-ne un per cada virtualhost, amb els límits d'emissió compartits.",
            <<<'SH'
            command -v openssl >/dev/null || { echo "Falta openssl en aquest equip"; exit 1; }
            vr=$(curl -s -m 12 -o /dev/null -w '%{ssl_verify_result}' "https://www.$DOM/")
            [ "$vr" = 0 ] || { echo "curl no valida el certificat de www.$DOM (codi $vr): potser és de staging o no és el de producció"; exit 1; }
            crt=$(echo | openssl s_client -connect "www.$DOM:443" -servername "www.$DOM" 2>/dev/null | openssl x509 -noout -issuer -ext subjectAltName 2>/dev/null)
            [[ "$crt" == *"Let's Encrypt"* ]] || { echo "L'emissor no és Let's Encrypt: $(grep -i issuer <<< "$crt")"; exit 1; }
            [[ "$crt" != *STAGING* && "$crt" != *"Fake LE"* ]] || { echo "El certificat és de l'entorn de proves (staging) de Let's Encrypt"; exit 1; }
            [[ "$crt" == *"DNS:$DOM"* && "$crt" == *"DNS:*.$DOM"* ]] || { echo "El certificat no cobreix $DOM i *.$DOM"; exit 1; }
            echo "Certificat vàlid de Let's Encrypt per a $DOM i *.$DOM"
            SH],
        ['Q6: El HTTP redirigeix a HTTPS', 'http://www.<alumne>.julioantonio.cat respon un 301 amb la capçalera Location cap a https://. Comprova-ho: `curl -sI http://www.<alumne>.julioantonio.cat`.',
            "**Objectiu:** que ningú no es quedi navegant sense xifrar.\n\n**Per què:** una redirecció permanent (301) fa que els navegadors i els cercadors passin a HTTPS sense que l'usuari hi hagi de pensar.",
            <<<'SH'
            h=$(curl -sI -m 12 "http://www.$DOM/" | tr -d '\r')
            first=$(head -n 1 <<< "$h")
            [[ "$first" == *" 301"* ]] || { echo "http://www.$DOM respon '$first' i hauria de respondre 301"; exit 1; }
            grep -qi '^location: https://' <<< "$h" || { echo "El 301 no porta Location cap a https://"; exit 1; }
            echo "http://www.$DOM redirigeix amb 301 a HTTPS"
            SH],
    ];

    /** Checks que no necessiten l'AWS CLI ni la VPC (només miren el WSL i el DNS): porten només `# hosts: aws`. */
    private const WITHOUT_AWS = ['Q5a: Certificat de staging obtingut des del WSL'];

    private static function code(string $name, string $body): string
    {
        return in_array($name, self::WITHOUT_AWS, true)
            ? "# hosts: aws\n".$body
            : TecnoServeisCode::build($body, self::EXTRA);
    }

    /** @return array<string, array{ticket: int, code: string}> el codi de cada check, pel nom */
    public static function codes(): array
    {
        $codes = [];
        foreach ([1 => self::TICKET_1, 2 => self::TICKET_2] as $ticket => $checks) {
            foreach ($checks as [$name, , , $body]) {
                $codes[$name] = ['ticket' => $ticket, 'code' => self::code($name, $body)];
            }
        }

        return $codes;
    }

    public function run(): void
    {
        $code = env('HEIMDEYE_B4_MODUL', $this->moduleCode);
        $module = Module::where('codi', $code)
            ->orderByDesc(AcademicYear::select('es_actual')->whereColumn('cursos.id', 'moduls.curs_id')->limit(1))
            ->orderByDesc('id')
            ->first() ?? throw new RuntimeException("No existeix el mòdul {$code}");
        $number = (int) env('HEIMDEYE_B4_BLOC', $this->blockNumber);

        $block = Block::updateOrCreate(
            ['modul_id' => $module->id, 'numero' => $number],
            ['titol' => 'Servidors proxy', 'data_inici' => '2026-11-24', 'data_fi' => '2026-12-07', 'posicio' => $number],
        );

        $tickets = [
            1 => [self::TICKET_1, 'Tiquet 1', env('HEIMDEYE_B4_T1_DES', $this->ticket1From),
                "Proxy directe (Squid) amb cau, autenticació i restriccions, informe amb GoAccess i web publicat per un proxy invers (nginx).\n\nL'enunciat és al document del bloc. Els checks s'executen amb l'agent amb rol `aws`."],
            2 => [self::TICKET_2, 'Tiquet 2', env('HEIMDEYE_B4_T2_DES', $this->ticket2From),
                "Mode transparent, HTTPS i certificat real (acme-dns, Let's Encrypt).\n\nL'enunciat és al document del bloc. Els checks s'executen amb l'agent amb rol `aws`."],
        ];

        foreach ($tickets as $n => [$checks, $title, $from, $statement]) {
            // L'enunciat el pot haver editat el professor: només es posa en crear la tasca.
            $task = $block->tasks()->firstOrCreate(['titol' => $title], ['enunciat' => $statement, 'posicio' => $n]);
            foreach ($checks as $i => [$name, $description, $info, $body]) {
                $this->check($task, $i + 1, $name, $description, $info, self::code($name, $body), $from);
            }
            $this->command?->info("«{$title}»: {$task->checks()->count()} checks, actius des del {$from}.");
        }
    }

    private function check(Task $task, int $position, string $name, string $description, string $info, string $code, string $from): void
    {
        $check = $task->checks()->updateOrCreate(
            ['nom' => $name],
            [
                'descripcio' => $description,
                'info' => $info,
                'executor' => Executor::Agent,
                'tipus' => CheckKind::Required,
                'punts' => 1,
                'es_actiu' => null, // segueix actiu_des
                'actiu_des' => $from,
                'posicio' => $position,
            ],
        );

        // El codi d'un check amb versions no es toca: si ha canviat, s'hi afegeix una versió nova.
        $last = $check->versions()->orderByDesc('versio')->first();
        if ($last === null || $last->codi !== $code) {
            $check->versions()->create([
                'versio' => ($last?->versio ?? 0) + 1,
                'temps_max_seg' => 180,
                'codi' => $code,
            ]);
        }
    }
}
