<?php

namespace Database\Seeders;

/**
 * Codi dels checks i accions de TecnoServeis (bloc 3 de 0378). Cada check porta només el que fa servir:
 * la capçalera (troba la VPC, NC, X i CIDR) i les funcions i variables que el cos crida, directament o
 * a través d'altres funcions. Així l'alumne que obre un check només hi veu el que el fa funcionar.
 */
final class TecnoServeisCode
{
    /** Sempre: l'equip, l'AWS CLI i la VPC de l'alumne. */
    public const HEADER = <<<'SH'
        # hosts: aws
        command -v aws >/dev/null || { echo "Falta l'AWS CLI en aquest equip (rol aws)"; exit 1; }
        read -r VPC VNAME CIDR <<< "$(aws ec2 describe-vpcs --filters 'Name=tag:Name,Values=tecnoserveis-*' \
          --query 'Vpcs[0].[VpcId,Tags[?Key==`Name`]|[0].Value,CidrBlock]' --output text 2>&1)"
        [ "${VPC#vpc-}" != "$VPC" ] || { echo "No trobo la VPC tecnoserveis-<NomCognom>: $VPC $VNAME $CIDR"; exit 1; }
        NC=${VNAME#tecnoserveis-}
        X=$(cut -d. -f2 <<< "$CIDR")
        SH;

    /**
     * Peces, en l'ordre en què surten al codi: nom => [què la fa necessària (regex), codi].
     * Una funció és necessària si algú la crida; les variables, si algú les fa servir.
     */
    private const PIECES = [
        'python3' => ['/\bpython3 -c\b/', <<<'SH'
            command -v python3 >/dev/null || { echo "Falta python3 en aquest equip (rol aws)"; exit 1; }
            SH],
        'inst' => ['/(?<![\w-])inst(?![\w-])/', <<<'SH'
            # Instància en marxa de la VPC amb el Name acabat en $1: «id ip-privada ip-pública» (None si no n'hi ha).
            inst() {
              aws ec2 describe-instances --filters "Name=vpc-id,Values=$VPC" "Name=tag:Name,Values=*$1" Name=instance-state-name,Values=running \
                --query 'Reservations[].Instances[] | [0].[InstanceId,PrivateIpAddress,PublicIpAddress]' --output text
            }
            SH],
        'instancies' => ['/\$\{?(WEB|GW)/', <<<'SH'
            read -r WEB WEB_IP _ <<< "$(inst web)"
            read -r GW GW_IP GW_PUB <<< "$(inst gateway)"
            SH],
        'need' => ['/(?<![\w-])need(?![\w-])/', <<<'SH'
            need() { [ -n "$2" ] && [ "$2" != None ] || { echo "No trobo $1 a la VPC $VPC ($VNAME)"; exit 1; }; }
            SH],
        'net' => ['/(?<![\w-])net(?![\w-])/', <<<'SH'
            net() { case $1 in public-a) echo "10.$X.1.0/24" ;; public-b) echo "10.$X.2.0/24" ;; private-a) echo "10.$X.11.0/24" ;; private-b) echo "10.$X.12.0/24" ;; esac; }
            SH],
        'subnet' => ['/(?<![\w-])subnet(?![\w-])/', <<<'SH'
            subnet() { aws ec2 describe-subnets --filters "Name=vpc-id,Values=$VPC" "Name=cidr-block,Values=$(net "$1")" --query 'Subnets[0].SubnetId' --output text; }
            SH],
        'acl_of' => ['/(?<![\w-])acl_of(?![\w-])/', <<<'SH'
            acl_of() { aws ec2 describe-network-acls --filters "Name=association.subnet-id,Values=$(subnet "$1")" --query 'NetworkAcls[0].NetworkAclId' --output text; }
            SH],
        'sg_of' => ['/(?<![\w-])sg_of(?![\w-])/', <<<'SH'
            sg_of() { aws ec2 describe-instances --instance-ids "$1" --query 'Reservations[0].Instances[0].SecurityGroups[0].GroupId' --output text; }
            SH],
        'sg_permet' => ['/(?<![\w-])sg_permet(?![\w-])/', <<<'SH'
            # Alguna regla d'entrada del SG $1 deixa passar el tcp/$2 des de la IP $3 (o des del SG $4)?
            sg_permet() {
              aws ec2 describe-security-groups --group-ids "$1" --query 'SecurityGroups[0].IpPermissions' --output json | python3 -c '
            import ipaddress, json, sys
            port, ip, sg = int(sys.argv[1]), ipaddress.ip_address(sys.argv[2]), sys.argv[3]
            for p in json.load(sys.stdin):
                if p["IpProtocol"] not in ("-1", "tcp", "6"):
                    continue
                if p["IpProtocol"] != "-1" and not (p["FromPort"] <= port <= p["ToPort"]):
                    continue
                if any(ip in ipaddress.ip_network(r["CidrIp"]) for r in p.get("IpRanges", [])):
                    sys.exit(0)
                if sg and any(g.get("GroupId") == sg for g in p.get("UserIdGroupPairs", [])):
                    sys.exit(0)
            sys.exit(1)' "$2" "$3" "${4:-}"
            }
            SH],
        'nacl_decideix' => ['/(?<![\w-])nacl_decideix(?![\w-])/', <<<'SH'
            # La primera regla d'entrada de la NACL $1 que coincideix amb el tcp/$2 des de $3: allow o deny.
            nacl_decideix() {
              aws ec2 describe-network-acls --network-acl-ids "$1" --query 'NetworkAcls[0].Entries' --output json | python3 -c '
            import ipaddress, json, sys
            port, ip = int(sys.argv[1]), ipaddress.ip_address(sys.argv[2])
            for e in sorted(json.load(sys.stdin), key=lambda e: e["RuleNumber"]):
                if e["Egress"] or "CidrBlock" not in e or ip not in ipaddress.ip_network(e["CidrBlock"]):
                    continue
                r = e.get("PortRange")
                if e["Protocol"] == "-1" or (e["Protocol"] == "6" and r and r["From"] <= port <= r["To"]):
                    print(e["RuleAction"], e["RuleNumber"])
                    sys.exit(0)
            print("deny", "*")' "$2" "$3"
            }
            SH],
        'rcmd' => ['/(?<![\w-])rcmd(?![\w-])/', <<<'SH'
            # Comanda com a root a la instància $1 (SSM Run Command, /bin/sh). Escriu la sortida i surt amb el seu codi.
            rcmd() {
              local c
              c=$(aws ssm send-command --instance-ids "$1" --document-name AWS-RunShellScript \
                    --parameters "$(python3 -c 'import json, sys; print(json.dumps({"commands": [sys.argv[1]]}))' "$2")" \
                    --query Command.CommandId --output text) || return 1
              aws ssm wait command-executed --command-id "$c" --instance-id "$1" 2>/dev/null || true
              aws ssm get-command-invocation --command-id "$c" --instance-id "$1" --query StandardOutputContent --output text
              [ "$(aws ssm get-command-invocation --command-id "$c" --instance-id "$1" --query ResponseCode --output text)" = 0 ]
            }
            SH],
        'tolera' => ['/(?<![\w-])tolera(?![\w-])/', <<<'SH'
            # Crida aws idempotent: «ja hi és» o «no hi és» no és cap error.
            tolera() {
              local out
              out=$("$@" 2>&1) && return 0
              grep -qE 'Duplicate|NotFound|AlreadyExists' <<< "$out" && return 0
              echo "$out"; return 1
            }
            SH],
        'mou_port' => ['/(?<![\w-])mou_port(?![\w-])/', <<<'SH'
            # Mou al port $3 les regles d'entrada del SG $1 que obren el tcp/$2 (amb els mateixos orígens).
            mou_port() {
              local p q
              p=$(aws ec2 describe-security-groups --group-ids "$1" --output json \
                    --query "SecurityGroups[0].IpPermissions[?IpProtocol=='tcp' && FromPort==\`$2\` && ToPort==\`$2\`]")
              [ "$p" != "[]" ] || { echo "El SG $1 no té cap regla del tcp/$2"; return 0; }
              q=$(python3 -c 'import json, sys; p = json.loads(sys.argv[1]); [r.update(FromPort=int(sys.argv[2]), ToPort=int(sys.argv[2])) for r in p]; print(json.dumps(p))' "$p" "$3")
              tolera aws ec2 authorize-security-group-ingress --group-id "$1" --ip-permissions "$q" || exit 1
              aws ec2 revoke-security-group-ingress --group-id "$1" --ip-permissions "$p" >/dev/null || exit 1
            }
            SH],
        'LOC_DNAT' => ['/\$LOC_DNAT\b/', <<<'SH'
            # On és la primera regla DNAT del port 80 del gateway: «família taula cadena».
            LOC_DNAT='nft list ruleset | awk '"'"'/^table /{f=$2; t=$3} /^[[:space:]]*chain /{c=$2} /dnat/ && / dport 80 /{print f, t, c; exit}'"'"
            SH],
        'pas_publicar' => ['/(?<![\w-])pas_publicar(?![\w-])/', <<<'SH'
            pas_publicar() {  # Security Group del gateway: l'única entrada des d'Internet, tcp/80
              need "el gateway (Name *gateway)" "$GW"
              local G; G=$(sg_of "$GW"); need "el SG del gateway" "$G"
              tolera aws ec2 authorize-security-group-ingress --group-id "$G" --protocol tcp --port 80 --cidr 0.0.0.0/0 || exit 1
              echo "SG del gateway: tcp/80 obert a Internet"
            }
            SH],
        'pas_nftables' => ['/(?<![\w-])pas_nftables(?![\w-])/', <<<'SH'
            pas_nftables() {  # DNAT i SNAT del web, masquerade de sortida, política drop i registre, amb assegurança
              need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"; need "la IP pública del gateway" "$GW_PUB"
              local conf r
              conf=$(cat <<EOF
            #!/usr/sbin/nft -f
            # Tallafocs del gateway de TecnoServeis (tiquet 2). Generat per HeimdEye.
            flush ruleset

            table ip nat {
              chain prerouting {
                type nat hook prerouting priority dstnat;
                fib daddr type local tcp dport 80 dnat to $WEB_IP:80
              }
              chain postrouting {
                type nat hook postrouting priority srcnat;
                ip saddr $CIDR ip daddr != $CIDR masquerade
                ip daddr $WEB_IP tcp dport 80 masquerade
              }
            }

            table inet filter {
              chain input {
                type filter hook input priority filter; policy drop;
                ct state established,related accept
                iif lo accept
                udp sport 67 udp dport 68 accept
                limit rate 5/second log prefix "NFT-INPUT-DROP: "
              }
              chain forward {
                type filter hook forward priority filter; policy drop;
                ct state established,related accept
                ct status dnat ip daddr $WEB_IP tcp dport 80 accept
                ip saddr $CIDR ip daddr != $CIDR tcp dport { 80, 443 } accept
                limit rate 5/second log prefix "NFT-FORWARD-DROP: "
              }
            }
            EOF
            )
              # 1) Es comprova i es carrega en viu, amb una assegurança que ho buida en 5 minuts.
              r=$(rcmd "$GW" "set -e
            cat > /etc/nftables.conf.heimdeye <<'NFT'
            $conf
            NFT
            nft -c -f /etc/nftables.conf.heimdeye
            [ -f /etc/nftables.conf.abans-heimdeye ] || cp /etc/nftables.conf /etc/nftables.conf.abans-heimdeye 2>/dev/null || true
            u=heimdeye-nft-\$(date +%s)
            systemd-run --unit=\$u --on-active=300 /usr/sbin/nft flush ruleset >/dev/null
            echo \$u > /run/heimdeye-nft-unit
            nft -f /etc/nftables.conf.heimdeye
            echo carregat") || { echo "nftables no s'ha pogut carregar: $r"; exit 1; }
              # 2) Si el web es veu des d'Internet i Session Manager encara respon, es desa.
              sleep 3
              if ! curl -s -m 8 -o /dev/null "http://$GW_PUB/"; then
                rcmd "$GW" 'u=$(cat /run/heimdeye-nft-unit); systemctl stop "$u.timer"; if [ -f /etc/nftables.conf ]; then nft -f /etc/nftables.conf; else nft flush ruleset; fi' >/dev/null
                echo "Amb les regles noves el web no respon a http://$GW_PUB: s'han desfet (revisa el SG i les NACL)"; exit 1
              fi
              r=$(rcmd "$GW" 'set -e
            u=$(cat /run/heimdeye-nft-unit); systemctl stop "$u.timer"
            cp /etc/nftables.conf.heimdeye /etc/nftables.conf
            systemctl enable nftables >/dev/null 2>&1
            echo desat') || { echo "No s'han pogut desar les regles (l'assegurança les buidarà en 5 min): $r"; exit 1; }
              echo "nftables carregat i desat al gateway (DNAT cap a $WEB_IP:80)"
            }
            SH],
        'pas_nacl' => ['/(?<![\w-])pas_nacl(?![\w-])/', <<<'SH'
            pas_nacl() {  # una NACL per a les subxarxes públiques i una per a les privades, amb les tornades
              local pub pri s
              for s in public-a public-b private-a private-b; do need "la subxarxa $s ($(net "$s"))" "$(subnet "$s")"; done
              nacl() {  # nom -> id, creant-la si cal
                local id
                id=$(aws ec2 describe-network-acls --filters "Name=vpc-id,Values=$VPC" "Name=tag:Name,Values=$NC-nacl-$1" --query 'NetworkAcls[0].NetworkAclId' --output text)
                if [ -z "$id" ] || [ "$id" = None ]; then
                  id=$(aws ec2 create-network-acl --vpc-id "$VPC" --query NetworkAcl.NetworkAclId --output text \
                    --tag-specifications "ResourceType=network-acl,Tags=[{Key=Project,Value=TecnoServeis},{Key=Name,Value=$NC-nacl-$1}]") || exit 1
                fi
                echo "$id"
              }
              entry() {  # acl número ingress|egress cidr port-inici port-final allow|deny
                local a=(--network-acl-id "$1" --rule-number "$2" "--$3" --protocol tcp --cidr-block "$4" --port-range "From=$5,To=$6" --rule-action "$7")
                aws ec2 replace-network-acl-entry "${a[@]}" 2>/dev/null || aws ec2 create-network-acl-entry "${a[@]}" || exit 1
              }
              assoc() {  # subxarxa nacl
                local sub a
                sub=$(subnet "$1")
                a=$(aws ec2 describe-network-acls --filters "Name=association.subnet-id,Values=$sub" \
                      --query "NetworkAcls[0].Associations[?SubnetId=='$sub'].NetworkAclAssociationId" --output text)
                aws ec2 replace-network-acl-association --association-id "$a" --network-acl-id "$2" >/dev/null || exit 1
              }
              pub=$(nacl public); pri=$(nacl private)
              entry "$pub" 100 ingress 0.0.0.0/0 80 80 allow          # Internet -> web publicat; web -> NAT (http)
              entry "$pub" 110 ingress 0.0.0.0/0 1024 65535 allow     # respostes a les connexions del gateway
              entry "$pub" 120 ingress "$CIDR" 443 443 allow          # web -> NAT (https, Session Manager)
              entry "$pub" 100 egress 0.0.0.0/0 80 80 allow
              entry "$pub" 110 egress 0.0.0.0/0 443 443 allow          # inclou Session Manager
              entry "$pub" 120 egress 0.0.0.0/0 1024 65535 allow      # respostes a Internet i al web
              entry "$pri" 100 ingress "$(net public-a)" 80 80 allow  # gateway -> web (amb SNAT)
              entry "$pri" 110 ingress 0.0.0.0/0 1024 65535 allow     # respostes d'Internet via NAT
              entry "$pri" 100 egress 0.0.0.0/0 80 80 allow
              entry "$pri" 110 egress 0.0.0.0/0 443 443 allow          # inclou Session Manager
              entry "$pri" 120 egress "$CIDR" 1024 65535 allow        # respostes al gateway
              for s in public-a public-b; do assoc "$s" "$pub"; done
              for s in private-a private-b; do assoc "$s" "$pri"; done
              echo "NACL $NC-nacl-public ($pub) i $NC-nacl-private ($pri) associades"
            }
            SH],
        'pas_flowlogs' => ['/(?<![\w-])pas_flowlogs(?![\w-])/', <<<'SH'
            pas_flowlogs() {  # Flow Logs de tota la VPC cap a CloudWatch Logs, amb LabRole
              local lg acct
              lg=/tecnoserveis/$NC/flowlogs
              if [ "$(aws ec2 describe-flow-logs --filter "Name=resource-id,Values=$VPC" --query 'length(FlowLogs)' --output text)" != 0 ]; then
                echo "La VPC ja té flow log"; return
              fi
              tolera aws logs create-log-group --log-group-name "$lg" || exit 1
              acct=$(aws sts get-caller-identity --query Account --output text) || exit 1
              aws ec2 create-flow-logs --resource-type VPC --resource-ids "$VPC" --traffic-type ALL \
                --log-destination-type cloud-watch-logs --log-group-name "$lg" \
                --deliver-logs-permission-arn "arn:aws:iam::$acct:role/LabRole" --max-aggregation-interval 60 >/dev/null || exit 1
              echo "Flow log de la VPC cap a $lg"
            }
            SH],
        'obert' => ['/(?<![\w-])obert\s+"/', <<<'SH'
            # Prova si el port tcp $2 de $1 accepta connexions (2 s).
            obert() { timeout 2 bash -c "</dev/tcp/$1/$2" 2>/dev/null; }
            SH],
        'PORTS_ESCANEIG' => ['/\$PORTS_ESCANEIG\b/', <<<'SH'
            PORTS_ESCANEIG="21 22 23 25 53 110 143 443 445 3306 3389 5432 8080 8443"
            SH],
    ];

    /** El codi complet: capçalera, les peces que calen (i les que aquestes necessiten) i el cos. */
    public static function build(string $body): string
    {
        $used = [];
        $text = $body;
        do {
            $added = false;
            foreach (self::PIECES as $name => [$trigger, $code]) {
                if (! isset($used[$name]) && preg_match($trigger, $text)) {
                    $used[$name] = true;
                    $text .= "\n".$code;
                    $added = true;
                }
            }
        } while ($added);

        $parts = [self::HEADER];
        foreach (self::PIECES as $name => [, $code]) {
            if (isset($used[$name])) {
                $parts[] = $code;
            }
        }

        return implode("\n", $parts)."\n\n".$body;
    }
}
