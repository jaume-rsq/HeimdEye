<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\AcademicYear;
use App\Models\Action;
use App\Models\Block;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Module;
use App\Models\Task;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Bloc 3 de 0378 (xarxa perimetral a AWS), tiquet 2: «Tallafocs en capes i resposta a un incident».
 *
 * Crea a la tasca «Tiquet 2» (la de l'enunciat del professor, que no es toca):
 *  - checks de les proves d'acceptació F1-F8, la IP fixa (R2) i la política (només tcp/80 des
 *    d'Internet, cap 22), obligatoris i d'1 punt;
 *  - accions que fan el que demana el tiquet (publicar el 80, nftables, NACL, Flow Logs, tot alhora
 *    i un escaneig de prova), per posar-ho en marxa o ajudar un alumne;
 *  - la incidència «La web de TecnoServeis no respon» amb 7 avaries (les 6 d'incident.sh i el DNAT
 *    a un port equivocat), una acció d'avaria i una de reparació amb el paràmetre AVARIA (0-6) i un
 *    check de resolució per avaria (desactivat i sense punts).
 *
 * Abans de crear-ho, ESBORRA el que hi hagi del bloc: els checks de la tasca «Tiquet 2» (amb les
 * seves execucions), les accions i les incidències del bloc. Les altres tasques del bloc no es toquen.
 *
 * Tot s'executa amb l'agent amb rol «aws»: una instància o la WSL amb l'AWS CLI i python3, amb les
 * credencials del Learner Lab de l'alumne, fora de la VPC de TecnoServeis. El que passa dins del
 * gateway i del web va per SSM Run Command. La VPC es troba pel tag Name=tecnoserveis-<NomCognom>,
 * X surt del seu rang 10.X.0.0/16, les subxarxes pel seu rang (10.X.1.0/24...) i les instàncies
 * pel Name acabat en «gateway» i en «web».
 *
 * Mòdul 0378 del curs actual, bloc 3, tasca «Tiquet 2»; es pot canviar amb HEIMDEYE_INCIDENCIA_MODUL,
 * _BLOC i _TASCA. S'executa amb: php artisan db:seed --class=Tiquet2TecnoServeisSeeder --force
 */
class Tiquet2TecnoServeisSeeder extends Seeder
{
    public string $moduleCode = '0378';

    public int $blockNumber = 3;

    public string $taskTitle = 'Tiquet 2';

    public const INCIDENT = 'La web de TecnoServeis no respon';

    private const INJECT_ACTION = 'TecnoServeis: injectar avaria';

    private const REPAIR_ACTION = 'TecnoServeis: desfer avaria';

    private const RECREATE_ACTION = 'T2: recrear una instància amb la IP fixa';

    /**
     * R2: la IP privada principal d'una instància no es pot canviar. Es fa una AMI de la instància,
     * se'n llança una de nova amb la IP fixa (mateixa subxarxa, SG, perfil, tipus, clau i tags), es
     * mouen l'Elastic IP i les rutes que hi apuntaven, i s'acaba l'antiga. L'AMI queda com a còpia.
     */
    private const RECREATE = <<<'SH'
        case "$HE_PARAM_QUINA" in
          gateway) OLD=$GW; IP=10.$X.1.10; SUB=public-a ;;
          web) OLD=$WEB; IP=10.$X.11.10; SUB=private-a ;;
          *) echo "QUINA ha de ser gateway o web"; exit 1 ;;
        esac
        need "el $HE_PARAM_QUINA (Name *$HE_PARAM_QUINA)" "$OLD"
        read -r CUR TYPE KEY PROFILE <<< "$(aws ec2 describe-instances --instance-ids "$OLD" \
          --query 'Reservations[0].Instances[0].[PrivateIpAddress,InstanceType,KeyName,IamInstanceProfile.Arn]' --output text)"
        [ "$CUR" != "$IP" ] || { echo "El $HE_PARAM_QUINA ja té la $IP: no cal fer res"; exit 0; }
        S=$(subnet "$SUB"); need "la subxarxa $SUB ($(net "$SUB"))" "$S"
        used=$(aws ec2 describe-network-interfaces --filters "Name=vpc-id,Values=$VPC" "Name=addresses.private-ip-address,Values=$IP" \
          --query 'NetworkInterfaces[0].NetworkInterfaceId' --output text)
        [ -z "$used" ] || [ "$used" = None ] || { echo "La $IP ja la fa servir $used"; exit 1; }
        SGS=$(aws ec2 describe-instances --instance-ids "$OLD" --query 'Reservations[0].Instances[0].SecurityGroups[].GroupId' --output text)
        TAGS=$(aws ec2 describe-instances --instance-ids "$OLD" --output json --query 'Reservations[0].Instances[0].Tags' | python3 -c '
        import json, sys
        tags = [t for t in json.load(sys.stdin) or [] if not t["Key"].startswith("aws:")]
        print(json.dumps([{"ResourceType": "instance", "Tags": tags}]))')

        echo "1/4 AMI de $OLD ($CUR)..."
        AMI=$(aws ec2 create-image --instance-id "$OLD" --name "$NC-$HE_PARAM_QUINA-$(date +%Y%m%d-%H%M%S)" \
          --description "Còpia abans de recrear amb la IP $IP (HeimdEye)" --query ImageId --output text) || exit 1
        aws ec2 wait image-available --image-ids "$AMI" || { echo "L'AMI $AMI no està disponible"; exit 1; }

        echo "2/4 Instància nova amb la $IP..."
        args=(--image-id "$AMI" --instance-type "$TYPE" --subnet-id "$S" --private-ip-address "$IP" --tag-specifications "$TAGS")
        # shellcheck disable=SC2206
        args+=(--security-group-ids $SGS)
        [ -z "$KEY" ] || [ "$KEY" = None ] || args+=(--key-name "$KEY")
        [ -z "$PROFILE" ] || [ "$PROFILE" = None ] || args+=(--iam-instance-profile "Arn=$PROFILE")
        NEW=$(aws ec2 run-instances "${args[@]}" --query 'Instances[0].InstanceId' --output text) || exit 1
        aws ec2 wait instance-running --instance-ids "$NEW" || exit 1
        [ "$HE_PARAM_QUINA" != gateway ] || aws ec2 modify-instance-attribute --instance-id "$NEW" --no-source-dest-check || exit 1

        echo "3/4 Elastic IP i rutes..."
        EIP=$(aws ec2 describe-addresses --filters "Name=instance-id,Values=$OLD" --query 'Addresses[0].AllocationId' --output text)
        if [ -n "$EIP" ] && [ "$EIP" != None ]; then
          aws ec2 associate-address --allocation-id "$EIP" --instance-id "$NEW" --allow-reassociation >/dev/null || exit 1
          echo "Elastic IP $EIP mogut a $NEW"
        fi
        OLD_ENI=$(aws ec2 describe-instances --instance-ids "$OLD" --query 'Reservations[0].Instances[0].NetworkInterfaces[0].NetworkInterfaceId' --output text)
        while read -r rt dest; do
          [ -n "$dest" ] || continue
          aws ec2 replace-route --route-table-id "$rt" --destination-cidr-block "$dest" --instance-id "$NEW" || exit 1
          echo "Ruta $dest de $rt cap a $NEW"
        done < <(aws ec2 describe-route-tables --filters "Name=vpc-id,Values=$VPC" --output json | python3 -c '
        import json, sys
        old, eni = sys.argv[1], sys.argv[2]
        for t in json.load(sys.stdin)["RouteTables"]:
            for r in t["Routes"]:
                if r.get("InstanceId") == old or r.get("NetworkInterfaceId") == eni:
                    print(t["RouteTableId"], r.get("DestinationCidrBlock", ""))' "$OLD" "$OLD_ENI")

        echo "4/4 S'acaba $OLD..."
        aws ec2 terminate-instances --instance-ids "$OLD" >/dev/null || exit 1
        echo "$HE_PARAM_QUINA recreat: $NEW amb la $IP (còpia a l'AMI $AMI; esborra-la quan no la necessitis)."
        if [ "$HE_PARAM_QUINA" = web ]; then
          echo "El DNAT del gateway apuntava a $CUR: torna a llançar «T2: nftables al gateway» quan el web arrenqui."
        else
          echo "La IP pública del gateway pot haver canviat (si no tenia Elastic IP)."
        fi
        SH;

    // ── Accions: el que demana el tiquet ──────────────────────────────────────────────────────

    /** [nom, descripció, cos, checks de verificació, temps màxim] */
    private const ACTIONS = [
        ['T2: obrir el 80 del gateway a Internet', 'Afegeix al Security Group del gateway l\'entrada tcp/80 des de 0.0.0.0/0 (l\'única que dona la cara).',
            'pas_publicar', ['Política: només el tcp/80 és obert a Internet'], 120],
        ['T2: tancar el que sobra als Security Groups', 'Dels Security Groups en ús a la VPC, treu les regles que inclouen el 22 (o tot el trànsit) i les obertes a Internet que no siguin el tcp/80 del gateway. Després assegura les del mínim privilegi: el web accepta el 80 del gateway, i el gateway el 80 i el 443 del web.',
            'pas_tancar', ['F2: El gateway només exposa el port 80', 'Política: només el tcp/80 és obert a Internet', 'Política: cap Security Group amb el 22'], 120],
        ['T2: nftables al gateway', 'Escriu /etc/nftables.conf al gateway (DNAT i SNAT del web, masquerade de sortida, input i forward amb política drop i registre DROP). El carrega en viu amb una assegurança de 5 minuts, comprova que el web es veu des d\'Internet i només llavors el desa. La còpia d\'abans queda a /etc/nftables.conf.abans-heimdeye.',
            'pas_nftables', ['F1: El web es veu des d\'Internet', 'F4: El web no surt per altres ports', 'F7: nftables amb política drop', 'F7: nftables publica el web (DNAT i SNAT)', 'F8: Session Manager continua funcionant'], 300],
        ['T2: NACL pròpies', 'Crea (o reaprofita) les NACL <NomCognom>-nacl-public i -nacl-private amb les regles de la solució de referència, incloses les tornades, i les associa a les quatre subxarxes.',
            'pas_nacl', ['F5: Les subxarxes tenen NACL pròpies', 'F1: El web es veu des d\'Internet', 'F3: El web surt a Internet per https', 'F4: El web no surt per altres ports', 'F8: Session Manager continua funcionant'], 300],
        ['T2: Flow Logs de la VPC', 'Crea el grup de logs /tecnoserveis/<NomCognom>/flowlogs i un flow log de tota la VPC (tot el trànsit) amb LabRole, si la VPC encara no en té.',
            'pas_flowlogs', ['F6: Flow Logs de la VPC a CloudWatch'], 120],
        ['T2: muntar tot el tiquet 2', 'Fa els cinc passos en ordre: obrir el 80 del gateway, tancar el que sobra als Security Groups, nftables, NACL i Flow Logs. No canvia les IP de les instàncies (R2): per a això hi ha «T2: recrear una instància amb la IP fixa».',
            "pas_publicar\npas_tancar\npas_nftables\npas_nacl\npas_flowlogs", ['F1: El web es veu des d\'Internet', 'F2: El gateway només exposa el port 80', 'F3: El web surt a Internet per https', 'F4: El web no surt per altres ports', 'F5: Les subxarxes tenen NACL pròpies', 'F6: Flow Logs de la VPC a CloudWatch', 'F7: nftables amb política drop', 'F8: Session Manager continua funcionant', 'Política: només el tcp/80 és obert a Internet', 'Política: cap Security Group amb el 22'], 600],
        ['T2: escaneig de prova', 'Prova des de l\'agent uns quants ports tancats de la IP pública del gateway, perquè quedin denegats als Flow Logs (REJECT) i al registre d\'nftables (F6 i F7). Els Flow Logs poden trigar uns minuts.',
            <<<'SH'
            need "la IP pública del gateway" "$GW_PUB"
            for i in 1 2 3; do for p in $PORTS_ESCANEIG; do obert "$GW_PUB" "$p"; done; done
            echo "Escaneig fet a $GW_PUB (ports $PORTS_ESCANEIG, 3 vegades)"
            SH, ['F7: nftables registra el que denega'], 300],
    ];

    // ── Checks de les proves d'acceptació ────────────────────────────────────────────────────

    /** [nom, descripció per a l'alumne, info (objectiu i justificació), cos] */
    private const CHECKS = [
        ['R2: IP privades fixes', 'El gateway té la 10.X.1.10 i el web la 10.X.11.10. Comprova-ho: P1 del tiquet 1 (describe-instances amb PrivateIpAddress).',
            "**Objectiu:** que el gateway i el web tinguin les IP privades fixes del requisit 2.\n\n**Per què:** el DNAT del gateway apunta a la IP del web; si canvia a cada build, el que hi ha desat a l'AMI deixa de funcionar.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            [ "$GW_IP" = "10.$X.1.10" ] && [ "$WEB_IP" = "10.$X.11.10" ] \
              || { echo "IP no fixes: gateway $GW_IP (ha de ser 10.$X.1.10), web $WEB_IP (ha de ser 10.$X.11.10)"; exit 1; }
            echo "gateway 10.$X.1.10 i web 10.$X.11.10"
            SH],
        ['F1: El web es veu des d\'Internet', 'La pàgina del web es veu a http://<IP pública del gateway>. Comprova-ho des del WSL: `curl -s -m 5 http://<IP-pública-del-gateway>`.',
            "**Objectiu:** que el web, sense IP pública, es publiqui a Internet a través del gateway (DNAT i SNAT).\n\n**Per què:** és el servei que l'empresa ofereix als clients; la resta de capes no tenen sentit si no funciona.",
            <<<'SH'
            need "la IP pública del gateway" "$GW_PUB"
            code=$(curl -s -m 8 -o /tmp/he-f1.html -w '%{http_code}' "http://$GW_PUB/")
            [ "$code" = 200 ] && [ -s /tmp/he-f1.html ] || { echo "http://$GW_PUB no respon amb la pàgina (codi ${code:-cap})"; exit 1; }
            echo "http://$GW_PUB respon ($(wc -c < /tmp/he-f1.html) bytes)"
            SH],
        ['F2: El gateway només exposa el port 80', 'Des de fora, el 80 de la IP pública del gateway és obert i la resta de ports habituals no. Comprova-ho: `nmap -Pn -T4 --max-retries 1 -p 1-1024,3306,8080 <IP-pública-del-gateway>`.',
            "**Objectiu:** que el gateway només exposi el servei publicat.\n\n**Per què:** mínim privilegi: cada port obert a Internet és una porta d'entrada que cal mantenir i vigilar.",
            <<<'SH'
            need "la IP pública del gateway" "$GW_PUB"
            obert "$GW_PUB" 80 || { echo "El port 80 de $GW_PUB no és obert"; exit 1; }
            oberts=""
            for p in $PORTS_ESCANEIG; do obert "$GW_PUB" "$p" && oberts="$oberts $p"; done
            [ -z "$oberts" ] || { echo "A més del 80, són oberts:$oberts"; exit 1; }
            echo "Només el 80 és obert (provats $PORTS_ESCANEIG)"
            SH],
        ['F3: El web surt a Internet per https', 'Des del web, una connexió https a Internet funciona. Comprova-ho dins del web: `curl -sI -m 8 https://example.com | head -1`.',
            "**Objectiu:** que la sortida permesa de la xarxa privada (tcp/80 i tcp/443) continuï funcionant amb les tres capes.\n\n**Per què:** el web s'ha d'actualitzar i Session Manager necessita el 443.",
            <<<'SH'
            need "el web (Name *web)" "$WEB"
            out=$(rcmd "$WEB" 'curl -sI -m 8 https://example.com | head -1') || true
            grep -q HTTP <<< "$out" || { echo "El web no surt per https: ${out:-sense resposta}"; exit 1; }
            echo "El web surt per https: $(tr -d '\r' <<< "$out")"
            SH],
        ['F4: El web no surt per altres ports', 'Des del web, una connexió a un port no permès (8080) queda tallada. Comprova-ho dins del web: `timeout 5 bash -c \'</dev/tcp/portquiz.net/8080\' && echo OBERT || echo TANCAT`.',
            "**Objectiu:** que la política denegui la sortida que no és tcp/80 ni tcp/443.\n\n**Per què:** un servidor compromès que pot sortir per qualsevol port pot exfiltrar dades o parlar amb un servidor de control.",
            <<<'SH'
            need "el web (Name *web)" "$WEB"
            out=$(rcmd "$WEB" "timeout 5 bash -c '</dev/tcp/portquiz.net/8080' 2>/dev/null && echo OBERT || echo TANCAT" | tr -d '[:space:]')
            [ "$out" = TANCAT ] || { echo "El web pot sortir pel 8080 (${out:-sense resposta})"; exit 1; }
            echo "La sortida pel 8080 és tallada"
            SH],
        ['F5: Les subxarxes tenen NACL pròpies', 'Les subxarxes públiques comparteixen una NACL pròpia i les privades una altra, cap de les dues per defecte. Comprova-ho amb `aws ec2 describe-network-acls` (IsDefault i Associations).',
            "**Objectiu:** que hi hagi una NACL per a les subxarxes públiques i una per a les privades.\n\n**Per què:** la NACL per defecte ho deixa passar tot; una NACL pròpia és la capa de subxarxa de la defensa en profunditat.",
            <<<'SH'
            declare -A acl
            for s in public-a public-b private-a private-b; do
              sub=$(subnet "$s"); need "la subxarxa $s ($(net "$s"))" "$sub"
              read -r id def <<< "$(aws ec2 describe-network-acls --filters "Name=association.subnet-id,Values=$sub" --query 'NetworkAcls[0].[NetworkAclId,IsDefault]' --output text)"
              [ "$def" = False ] || { echo "$s fa servir la NACL per defecte"; exit 1; }
              acl[$s]=$id
            done
            [ "${acl[public-a]}" = "${acl[public-b]}" ] || { echo "public-a i public-b no comparteixen NACL"; exit 1; }
            [ "${acl[private-a]}" = "${acl[private-b]}" ] || { echo "private-a i private-b no comparteixen NACL"; exit 1; }
            [ "${acl[public-a]}" != "${acl[private-a]}" ] || { echo "Les públiques i les privades fan servir la mateixa NACL"; exit 1; }
            echo "NACL pública ${acl[public-a]} i privada ${acl[private-a]}"
            SH],
        ['F6: Flow Logs de la VPC a CloudWatch', 'La VPC té un flow log actiu de tot el trànsit cap a CloudWatch Logs. Comprova-ho: `aws ec2 describe-flow-logs`.',
            "**Objectiu:** registrar les metadades de tot el trànsit de la VPC.\n\n**Per què:** sense registres no es pot saber qui ho intenta ni què es bloqueja, ni investigar un incident.",
            <<<'SH'
            read -r st tt dt lg <<< "$(aws ec2 describe-flow-logs --filter "Name=resource-id,Values=$VPC" \
              --query 'FlowLogs[0].[FlowLogStatus,TrafficType,LogDestinationType,LogGroupName]' --output text)"
            [ "$st" = ACTIVE ] || { echo "La VPC no té cap flow log actiu"; exit 1; }
            [ "$tt" = ALL ] || { echo "El flow log registra $tt, no tot el trànsit (ALL)"; exit 1; }
            [ "$dt" = cloud-watch-logs ] || { echo "El flow log va a $dt, no a CloudWatch Logs"; exit 1; }
            echo "Flow log actiu (ALL) cap a $lg"
            SH],
        ['F6: Els Flow Logs registren REJECT', 'Al grup de logs del flow log hi ha línies REJECT de les últimes 24 hores (el vostre escaneig). Comprova-ho: `aws logs filter-log-events --log-group-name <grup> --filter-pattern REJECT`.',
            "**Objectiu:** trobar el trànsit denegat als Flow Logs.\n\n**Per què:** un registre que mai no es consulta no serveix; cal saber-hi trobar un escaneig.",
            <<<'SH'
            lg=$(aws ec2 describe-flow-logs --filter "Name=resource-id,Values=$VPC" --query 'FlowLogs[0].LogGroupName' --output text)
            need "el grup de logs del flow log" "$lg"
            n=$(aws logs filter-log-events --log-group-name "$lg" --filter-pattern REJECT \
                  --start-time $(( ($(date +%s) - 86400) * 1000 )) --max-items 50 --query 'length(events)' --output text 2>/dev/null | head -1)
            [ "${n:-0}" -gt 0 ] 2>/dev/null || { echo "Cap REJECT a $lg les últimes 24 h: feu un escaneig i espereu uns minuts"; exit 1; }
            echo "$n línies REJECT a $lg"
            SH],
        ['F7: nftables amb política drop', 'Al gateway, les cadenes input i forward d\'nftables tenen política drop. Comprova-ho dins del gateway: `sudo nft list ruleset`.',
            "**Objectiu:** que el tallafocs del gateway denegui per defecte.\n\n**Per què:** amb política drop, el que no s'ha previst queda tancat; amb accept, el que s'oblida queda obert.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            rules=$(rcmd "$GW" 'nft list ruleset') || { echo "No es pot llegir nftables al gateway: $rules"; exit 1; }
            grep -qE 'hook input .*policy drop' <<< "$rules" || { echo "La cadena input no té política drop"; exit 1; }
            grep -qE 'hook forward .*policy drop' <<< "$rules" || { echo "La cadena forward no té política drop"; exit 1; }
            echo "input i forward amb política drop"
            SH],
        ['F7: nftables publica el web (DNAT i SNAT)', 'Al gateway hi ha un DNAT del port 80 cap a la IP del web i el SNAT (masquerade) del trànsit. Comprova-ho dins del gateway: `sudo nft list ruleset | grep -E \'dnat|masquerade|snat\'`.',
            "**Objectiu:** que el web es publiqui amb DNAT i que el web vegi el gateway com a origen (SNAT).\n\n**Per què:** sense SNAT, el SG del web, que només accepta el gateway, descartaria els clients d'Internet.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            rules=$(rcmd "$GW" 'nft list ruleset') || { echo "No es pot llegir nftables al gateway: $rules"; exit 1; }
            grep -qE "dnat (ip )?to $WEB_IP" <<< "$rules" || { echo "No hi ha cap DNAT cap al web ($WEB_IP)"; exit 1; }
            grep -qE 'masquerade|snat' <<< "$rules" || { echo "No hi ha SNAT ni masquerade"; exit 1; }
            echo "DNAT cap a $WEB_IP i SNAT carregats"
            SH],
        ['F7: nftables registra el que denega', 'nftables registra els paquets denegats amb un prefix que inclou DROP i amb límit de ritme, i n\'hi ha al registre de les últimes 24 hores. Comprova-ho: `sudo journalctl -k --since "1 day ago" | grep DROP | tail`.',
            "**Objectiu:** que el tallafocs deixi rastre del que denega, sense omplir el disc.\n\n**Per què:** els Flow Logs no veuen el que talla nftables (surt com a ACCEPT); aquest registre és l'únic que ho mostra.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            rules=$(rcmd "$GW" 'nft list ruleset') || { echo "No es pot llegir nftables al gateway: $rules"; exit 1; }
            grep -E 'log prefix "[^"]*DROP' <<< "$rules" | grep -q 'limit rate' \
              || grep -B1 -E 'log prefix "[^"]*DROP' <<< "$rules" | grep -q 'limit rate' \
              || { echo "Cap regla log amb prefix DROP i limit rate"; exit 1; }
            n=$(rcmd "$GW" "journalctl -k --since '24 hours ago' | grep -c DROP" | tr -d '[:space:]')
            [ "${n:-0}" -gt 0 ] 2>/dev/null || { echo "Cap línia DROP al registre del kernel les últimes 24 h"; exit 1; }
            echo "$n línies DROP al registre les últimes 24 h"
            SH],
        ['F8: Session Manager continua funcionant', 'El gateway i el web surten a Session Manager com a Online. Comprova-ho: `aws ssm describe-instance-information --query \'InstanceInformationList[].[InstanceId,PingStatus]\' --output table`.',
            "**Objectiu:** que les tres capes de filtratge no hagin trencat l'administració.\n\n**Per què:** és l'única via d'administració; si es talla, no es pot diagnosticar ni arreglar res des de dins.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            on=$(aws ssm describe-instance-information \
              --query "InstanceInformationList[?PingStatus=='Online' && (InstanceId=='$GW' || InstanceId=='$WEB')].InstanceId" --output text | wc -w)
            [ "$on" = 2 ] || { echo "Només $on de 2 instàncies (gateway i web) són Online a Session Manager"; exit 1; }
            echo "gateway i web Online a Session Manager"
            SH],
        ['Política: només el tcp/80 és obert a Internet', 'L\'única regla d\'entrada dels Security Groups en ús a la VPC amb origen 0.0.0.0/0 és la del tcp/80 del gateway. Comprova-ho: P6 del tiquet 1.',
            "**Objectiu:** aplicar la política de l'empresa a la capa de Security Groups.\n\n**Per què:** mínim privilegi: el web es publica pel 80 i res més ha de ser accessible des d'Internet.",
            <<<'SH'
            S=$(sgs_en_us); need "cap Security Group en ús" "$S"
            # shellcheck disable=SC2086
            any=$(aws ec2 describe-security-groups --group-ids $S \
              --query "SecurityGroups[].IpPermissions[?IpRanges[?CidrIp=='0.0.0.0/0'] || Ipv6Ranges[?CidrIpv6=='::/0']][].[IpProtocol,FromPort,ToPort]" \
              --output text | tr -s '[:space:]' ' ' | sed 's/ $//')
            [ "$any" = "tcp 80 80" ] || { echo "Regles obertes a Internet: ${any:-cap} (ha de ser només tcp 80 80)"; exit 1; }
            echo "Només el tcp/80 és obert a Internet"
            SH],
        ['Política: cap Security Group amb el 22', 'Cap regla d\'entrada dels Security Groups en ús a la VPC (els d\'alguna instància o interfície) inclou el port 22, ni en un rang ni com a «tot el trànsit».',
            "**Objectiu:** que l'administració continuï anant només per Session Manager.\n\n**Per què:** el 22 obert és l'objectiu número u dels atacs automàtics.",
            <<<'SH'
            S=$(sgs_en_us); need "cap Security Group en ús" "$S"
            # shellcheck disable=SC2086
            p22=$(aws ec2 describe-security-groups --group-ids $S \
              --query "SecurityGroups[].IpPermissions[?(FromPort<=\`22\` && ToPort>=\`22\`) || IpProtocol=='-1'][]" --output text)
            [ -z "$p22" ] || [ "$p22" = None ] || { echo "Hi ha regles que inclouen el 22: $(head -c 150 <<< "$p22")"; exit 1; }
            echo "Cap Security Group té el 22"
            SH],
    ];

    // ── Incidència: avaries, reparacions i checks de resolució ───────────────────────────────

    /** Nom de cada opció; la clau és el valor del paràmetre AVARIA. */
    private const OPTIONS = [
        0 => 'SG web: el gateway entra pel 8080 en lloc del 80',
        1 => 'nginx només escolta a 127.0.0.1',
        2 => 'nginx aturat',
        3 => 'NACL pública bloqueja el 80',
        4 => 'NACL privada bloqueja el 80',
        5 => 'SG gateway tancat al 80 des d\'Internet',
        6 => 'DNAT del gateway cap al port 8080 del web',
    ];

    private const INJECT = <<<'SH'
        case "$HE_PARAM_AVARIA" in
          0) need "el web (Name *web)" "$WEB"; mou_port "$(sg_of "$WEB")" 80 8080 ;;
          1) need "el web (Name *web)" "$WEB"
             rcmd "$WEB" "sed -i 's/listen 80 default_server;/listen 127.0.0.1:80 default_server;/' /etc/nginx/sites-enabled/default && nginx -s reload" || exit 1 ;;
          2) need "el web (Name *web)" "$WEB"; rcmd "$WEB" "systemctl stop nginx" || exit 1 ;;
          3|4) [ "$HE_PARAM_AVARIA" = 3 ] && s=public-a || s=private-a
             A=$(acl_of "$s"); need "la NACL de $s" "$A"
             tolera aws ec2 create-network-acl-entry --network-acl-id "$A" --rule-number 50 --ingress \
               --protocol tcp --port-range From=80,To=80 --cidr-block 0.0.0.0/0 --rule-action deny || exit 1 ;;
          5) need "el gateway (Name *gateway)" "$GW"
             tolera aws ec2 revoke-security-group-ingress --group-id "$(sg_of "$GW")" --protocol tcp --port 80 --cidr 0.0.0.0/0 || exit 1 ;;
          6) need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
             # Només en viu (no es desa): una regla nova, al davant, que envia el 80 al 8080 del web.
             rcmd "$GW" "set -e
        loc=\$($LOC_DNAT)
        [ -n \"\$loc\" ] || { echo 'No hi ha cap DNAT del port 80 al gateway'; exit 1; }
        set -- \$loc
        if [ \"\$1\" = inet ]; then d='dnat ip to'; else d='dnat to'; fi
        nft insert rule \"\$1\" \"\$2\" \"\$3\" fib daddr type local tcp dport 80 \$d $WEB_IP:8080" || exit 1 ;;
          *) echo "AVARIA ha de ser 0-6"; exit 1 ;;
        esac
        echo "Avaria $HE_PARAM_AVARIA injectada a $NC"
        SH;

    private const REPAIR = <<<'SH'
        case "$HE_PARAM_AVARIA" in
          0) need "el web (Name *web)" "$WEB"; mou_port "$(sg_of "$WEB")" 8080 80 ;;
          1) need "el web (Name *web)" "$WEB"
             rcmd "$WEB" "sed -i 's/listen 127.0.0.1:80 default_server;/listen 80 default_server;/' /etc/nginx/sites-enabled/default && nginx -s reload" || exit 1 ;;
          2) need "el web (Name *web)" "$WEB"; rcmd "$WEB" "systemctl start nginx" || exit 1 ;;
          3|4) [ "$HE_PARAM_AVARIA" = 3 ] && s=public-a || s=private-a
             A=$(acl_of "$s"); need "la NACL de $s" "$A"
             tolera aws ec2 delete-network-acl-entry --network-acl-id "$A" --rule-number 50 --ingress || exit 1 ;;
          5) need "el gateway (Name *gateway)" "$GW"
             tolera aws ec2 authorize-security-group-ingress --group-id "$(sg_of "$GW")" --protocol tcp --port 80 --cidr 0.0.0.0/0 || exit 1 ;;
          6) need "el gateway (Name *gateway)" "$GW"
             rcmd "$GW" "nft -a list ruleset | awk '/^table /{f=\$2; t=\$3} /^[[:space:]]*chain /{c=\$2} /dnat/ && / dport 80 / && /:8080/ {print f, t, c, \$NF}' |
        while read -r f t c h; do nft delete rule \"\$f\" \"\$t\" \"\$c\" handle \"\$h\"; done" || exit 1 ;;
          *) echo "AVARIA ha de ser 0-6"; exit 1 ;;
        esac
        echo "Avaria $HE_PARAM_AVARIA desfeta a $NC"
        SH;

    /** Checks de resolució: passen quan aquella part torna a estar bé. [nom, descripció, cos] */
    private const RESOLUTION = [
        0 => ['Incidència: el SG del web deixa entrar el gateway pel 80', 'El Security Group del web accepta el tcp/80 des del gateway (pel seu SG o per la seva IP).', <<<'SH'
            need "el web (Name *web)" "$WEB"; need "el gateway (Name *gateway)" "$GW"
            sg_permet "$(sg_of "$WEB")" 80 "$GW_IP" "$(sg_of "$GW")" || { echo "El SG del web no deixa entrar el gateway pel 80"; exit 1; }
            echo "El SG del web deixa entrar el gateway pel 80"
            SH],
        1 => ['Incidència: nginx escolta pel 80 a totes les interfícies', 'nginx escolta al port 80 en una adreça que no és només 127.0.0.1.', <<<'SH'
            need "el web (Name *web)" "$WEB"
            out=$(rcmd "$WEB" "ss -ltnH 'sport = :80' | awk '{print \$4}'") || { echo "No es pot consultar el web per SSM: $out"; exit 1; }
            echo "$out" | grep -E ':80$' | grep -qvE '^(127\.|\[::1\])' || { echo "nginx no escolta pel 80 fora de localhost: ${out:-res}"; exit 1; }
            echo "nginx escolta a $(echo "$out" | grep -E ':80$' | tr '\n' ' ')"
            SH],
        2 => ['Incidència: nginx en marxa', 'El servei nginx del web està actiu.', <<<'SH'
            need "el web (Name *web)" "$WEB"
            out=$(rcmd "$WEB" "systemctl is-active nginx") || true
            [ "$(echo "$out" | tr -d '[:space:]')" = active ] || { echo "nginx no està actiu: ${out:-sense resposta}"; exit 1; }
            echo "nginx actiu"
            SH],
        3 => ['Incidència: la NACL pública deixa entrar el 80', 'La primera regla d\'entrada de la NACL de public-a que coincideix amb el tcp/80 des d\'Internet és un allow.', <<<'SH'
            A=$(acl_of public-a); need "la NACL de public-a" "$A"
            read -r act num <<< "$(nacl_decideix "$A" 80 198.51.100.7)"
            [ "$act" = allow ] || { echo "La NACL de public-a denega el tcp/80 d'Internet (regla $num)"; exit 1; }
            echo "La NACL de public-a deixa entrar el 80 (regla $num)"
            SH],
        4 => ['Incidència: la NACL privada deixa entrar el 80 del gateway', 'La primera regla d\'entrada de la NACL de private-a que coincideix amb el tcp/80 des del gateway és un allow.', <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            A=$(acl_of private-a); need "la NACL de private-a" "$A"
            read -r act num <<< "$(nacl_decideix "$A" 80 "$GW_IP")"
            [ "$act" = allow ] || { echo "La NACL de private-a denega el tcp/80 del gateway (regla $num)"; exit 1; }
            echo "La NACL de private-a deixa entrar el 80 del gateway (regla $num)"
            SH],
        5 => ['Incidència: el SG del gateway és obert al 80 des d\'Internet', 'El Security Group del gateway accepta el tcp/80 des de 0.0.0.0/0.', <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            sg_permet "$(sg_of "$GW")" 80 198.51.100.7 || { echo "El SG del gateway no deixa entrar el 80 des d'Internet"; exit 1; }
            echo "El SG del gateway és obert al 80"
            SH],
        6 => ['Incidència: el DNAT del gateway va al port 80 del web', 'La primera regla DNAT del port 80 del gateway envia el trànsit al port 80 del web.', <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            r=$(rcmd "$GW" "nft list ruleset | awk '/dnat/ && / dport 80 /{print; exit}'") || { echo "No es pot llegir nftables al gateway: $r"; exit 1; }
            [ -n "$r" ] || { echo "No hi ha cap DNAT del port 80 al gateway"; exit 1; }
            port=$(grep -oE 'to [0-9.]+:[0-9]+' <<< "$r" | cut -d: -f2)
            [ -z "$port" ] || [ "$port" = 80 ] || { echo "El DNAT envia el 80 al port $port: $(xargs <<< "$r")"; exit 1; }
            echo "DNAT correcte: $(xargs <<< "$r")"
            SH],
    ];

    /**
     * El codi que ha de tenir cada check i acció del tiquet, pel nom (el fa servir
     * TecnoServeisCodiSeeder per posar al dia el que ja hi ha).
     *
     * @return array{checks: array<string, string>, accions: array<string, string>}
     */
    public static function codes(): array
    {
        $checks = [];
        foreach (self::CHECKS as [$name, , , $body]) {
            $checks[$name] = TecnoServeisCode::build($body);
        }
        foreach (self::RESOLUTION as [$name, , $body]) {
            $checks[$name] = TecnoServeisCode::build($body);
        }
        $actions = array_column(self::actionSpecs(), 'codi', 'nom');

        return ['checks' => $checks, 'accions' => $actions];
    }

    /**
     * Les accions del tiquet, tal com han de quedar (les fa servir run() i TecnoServeisCodiSeeder).
     * «verifica» són noms de checks de la tasca.
     *
     * @return list<array{nom: string, descripcio: string, codi: string, es_incidencia: bool, posicio: int, temps_max_seg: int, parametres: ?array, verifica: list<string>}>
     */
    public static function actionSpecs(): array
    {
        $specs = [];
        foreach (self::ACTIONS as $i => [$name, $description, $body, $verify, $timeout]) {
            $specs[] = ['nom' => $name, 'descripcio' => $description, 'codi' => TecnoServeisCode::build($body), 'es_incidencia' => false,
                'posicio' => $i + 1, 'temps_max_seg' => $timeout, 'parametres' => null, 'verifica' => $verify];
        }
        $specs[] = ['nom' => self::RECREATE_ACTION, 'descripcio' => 'R2: fa una AMI del gateway o del web, en llança una de nova amb la IP fixa (10.X.1.10 o 10.X.11.10) amb el mateix SG, perfil, tipus, clau i tags, hi mou l\'Elastic IP i les rutes, i ACABA la instància antiga. No es fa sola: llança-la només quan calgui. Si recrees el web, torna a llançar «T2: nftables al gateway».',
            'codi' => TecnoServeisCode::build(self::RECREATE), 'es_incidencia' => false, 'posicio' => count(self::ACTIONS) + 1, 'temps_max_seg' => 1800,
            'parametres' => [['name' => 'QUINA', 'label' => 'Instància (gateway o web)', 'default' => 'web']], 'verifica' => ['R2: IP privades fixes']];
        $param = [['name' => 'AVARIA', 'label' => 'Avaria (0-6)', 'default' => '0']];
        $specs[] = ['nom' => self::INJECT_ACTION, 'descripcio' => 'Trenca una part del camí cap a la web (paràmetre AVARIA, 0-6). Feta per a la incidència «'.self::INCIDENT.'».',
            'codi' => TecnoServeisCode::build(self::INJECT), 'es_incidencia' => true, 'posicio' => 20, 'temps_max_seg' => 180, 'parametres' => $param, 'verifica' => []];
        $specs[] = ['nom' => self::REPAIR_ACTION, 'descripcio' => 'Desfà l\'avaria AVARIA (0-6) de la incidència «'.self::INCIDENT.'».',
            'codi' => TecnoServeisCode::build(self::REPAIR), 'es_incidencia' => false, 'posicio' => 21, 'temps_max_seg' => 180, 'parametres' => $param, 'verifica' => []];

        return $specs;
    }

    /** @return array<string, string> la descripció de cada check, pel nom */
    public static function descriptions(): array
    {
        return array_column(self::CHECKS, 1, 0) + array_column(self::RESOLUTION, 1, 0);
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
        $title = env('HEIMDEYE_INCIDENCIA_TASCA', $this->taskTitle);
        // La tasca la crea el professor (amb l'enunciat); si no hi és, es crea buida.
        $task = $block->tasks()->firstOrCreate(['titol' => $title], ['posicio' => ($block->tasks()->max('posicio') ?? 0) + 1]);

        $this->clean($block, $task);

        $checks = [];
        foreach (self::CHECKS as $i => [$name, $description, $info, $body]) {
            $checks[$name] = $this->check($task, $i + 1, $name, $description, $info, TecnoServeisCode::build($body), CheckKind::Required, 1, null);
        }

        $actions = [];
        foreach (self::actionSpecs() as $spec) {
            $actions[$spec['nom']] = self::createAction($module, $block, $task, $spec, array_map(fn (string $n) => $checks[$n]->id, $spec['verifica']));
        }
        $inject = $actions[self::INJECT_ACTION];
        $repair = $actions[self::REPAIR_ACTION];

        $incident = Incident::create([
            'modul_id' => $module->id,
            'bloc_id' => $block->id,
            'tasca_id' => $task->id,
            'nom' => self::INCIDENT,
            'equip' => 'aws',
            'simptoma' => 'Els clients diuen que la web de TecnoServeis no carrega. Troba què ha canviat i arregla-ho.',
            'notes' => 'Llança-la amb 3 avaries per alumne quan tingui F1-F9 aprovades. Cal l\'agent amb rol aws (AWS CLI i python3 amb les credencials del Learner Lab) i SSM al gateway i al web. L\'avaria 6 (DNAT) només és en viu: un reinici del gateway la desfà.',
        ]);

        foreach (self::OPTIONS as $n => $name) {
            [$checkName, $description, $body] = self::RESOLUTION[$n];
            $check = $this->check($task, 100 + $n, $checkName, $description, null, TecnoServeisCode::build($body), CheckKind::Optional, 0, false);

            $incident->options()->create([
                'nom' => $name,
                'accio_id' => $inject->id,
                'parametres' => ['AVARIA' => (string) $n],
                'check_id' => $check->id,
                'accio_reparacio_id' => $repair->id,
                'posicio' => $n,
            ]);
        }
    }

    /**
     * Esborra el que hi hagi del tiquet: els checks i les incidències de la tasca, i les accions del
     * bloc que crea aquest seeder (pel nom). La resta del bloc (altres tiquets) no es toca.
     */
    private function clean(Block $block, Task $task): void
    {
        $incidents = Incident::where('bloc_id', $block->id)
            ->where(fn ($q) => $q->where('tasca_id', $task->id)->orWhere(fn ($q) => $q->whereNull('tasca_id')->where('nom', self::INCIDENT)))
            ->delete();
        $names = array_column(self::actionSpecs(), 'nom');
        $actions = Action::where('bloc_id', $block->id)->whereIn('nom', $names)->delete();
        $checks = $task->checks()->delete();

        $this->command?->info("Esborrat: {$checks} checks i {$incidents} incidències de «{$task->titol}», i {$actions} accions del tiquet al bloc {$block->numero}.");
    }

    /**
     * @param  array{nom: string, descripcio: string, codi: string, es_incidencia: bool, posicio: int, temps_max_seg: int, parametres: ?array}  $spec
     * @param  list<int>  $verify  ids dels checks de verificació
     */
    public static function createAction(Module $module, Block $block, Task $task, array $spec, array $verify): Action
    {
        $action = $module->actions()->create([
            'nom' => $spec['nom'],
            'bloc_id' => $block->id,
            'tasca_id' => $task->id,
            'descripcio' => $spec['descripcio'],
            'executor' => Executor::Agent,
            'es_incidencia' => $spec['es_incidencia'],
            'posicio' => $spec['posicio'],
        ]);
        $action->versions()->create([
            'versio' => 1,
            'temps_max_seg' => $spec['temps_max_seg'],
            'parametres' => $spec['parametres'],
            'checks_verificacio' => $verify ?: null,
            'codi' => $spec['codi'],
        ]);

        return $action;
    }

    private function check(Task $task, int $position, string $name, string $description, ?string $info, string $code, CheckKind $kind, int $points, ?bool $active): Check
    {
        $check = $task->checks()->create([
            'nom' => $name,
            'descripcio' => $description,
            'info' => $info,
            'executor' => Executor::Agent,
            'tipus' => $kind,
            'punts' => $points,
            'es_actiu' => $active, // els de resolució, desactivats: només resolen la incidència
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
