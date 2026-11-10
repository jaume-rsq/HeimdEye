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
 * Bloc 3 de 0378, tiquet 1: «Xarxa segmentada i sortida controlada». Checks de les proves P1-P8 i dels
 * requisits, perquè els alumnes revisin el punt de partida del tiquet 2.
 *
 * Abans de crear-los, ESBORRA els checks de la tasca «Tiquet 1» (amb les seves execucions). No toca
 * res més del bloc. Fa servir les mateixes peces de codi que el tiquet 2 (TecnoServeisCode) (agent amb rol «aws», AWS CLI i
 * python3, SSM al gateway i al web): veg. Tiquet2TecnoServeisSeeder.
 *
 * Els checks han de continuar passant després del tiquet 2: per això P4 no prova el 80 (el T2 el
 * publica) i el mínim privilegi del gateway admet l'entrada tcp/80 des d'Internet.
 *
 * Mòdul 0378 del curs actual, bloc 3, tasca «Tiquet 1»; es pot canviar amb HEIMDEYE_INCIDENCIA_MODUL,
 * _BLOC i HEIMDEYE_T1_TASCA. S'executa amb: php artisan db:seed --class=Tiquet1TecnoServeisSeeder --force
 */
class Tiquet1TecnoServeisSeeder extends Seeder
{
    public string $moduleCode = '0378';

    public int $blockNumber = 3;

    public string $taskTitle = 'Tiquet 1';

    /** [nom, descripció per a l'alumne, info (objectiu i justificació), cos] */
    private const CHECKS = [
        ['R1: VPC pròpia 10.X.0.0/16', 'Hi ha una VPC amb el tag Name tecnoserveis-<NomCognom> i el rang 10.X.0.0/16. Comprova-ho: `aws ec2 describe-vpcs --filters Name=tag:Name,Values=tecnoserveis-*`.',
            "**Objectiu:** que cada alumne tingui la seva VPC amb un rang propi.\n\n**Per què:** rangs que no se solapen permeten connectar xarxes (VPN, peering) sense conflictes.",
            <<<'SH'
            [[ "$CIDR" =~ ^10\.[0-9]+\.0\.0/16$ ]] || { echo "La VPC $VNAME té el rang $CIDR, no 10.X.0.0/16"; exit 1; }
            echo "VPC $VNAME ($VPC) amb $CIDR (X=$X)"
            SH],
        ['R1: Quatre subxarxes en dues zones', 'Hi ha les subxarxes public-a 10.X.1.0/24, public-b 10.X.2.0/24, private-a 10.X.11.0/24 i private-b 10.X.12.0/24; les «a» en una zona de disponibilitat i les «b» en una altra. Comprova-ho: `aws ec2 describe-subnets --filters Name=vpc-id,Values=<vpc-id>`.',
            "**Objectiu:** una xarxa segmentada en subxarxes públiques i privades, preparada per a dues zones.\n\n**Per què:** separar el que dona la cara del que és intern és la base de la DMZ; dues zones permeten alta disponibilitat.",
            <<<'SH'
            declare -A az
            for s in public-a public-b private-a private-b; do
              read -r id zone <<< "$(aws ec2 describe-subnets --filters "Name=vpc-id,Values=$VPC" "Name=cidr-block,Values=$(net "$s")" \
                --query 'Subnets[0].[SubnetId,AvailabilityZone]' --output text)"
              need "la subxarxa $s ($(net "$s"))" "$id"
              az[$s]=$zone
            done
            [ "${az[public-a]}" = "${az[private-a]}" ] || { echo "public-a (${az[public-a]}) i private-a (${az[private-a]}) no són a la mateixa zona"; exit 1; }
            [ "${az[public-b]}" = "${az[private-b]}" ] || { echo "public-b (${az[public-b]}) i private-b (${az[private-b]}) no són a la mateixa zona"; exit 1; }
            [ "${az[public-a]}" != "${az[public-b]}" ] || { echo "Les subxarxes a i b són a la mateixa zona (${az[public-a]})"; exit 1; }
            echo "4 subxarxes: a a ${az[public-a]}, b a ${az[public-b]}"
            SH],
        ['R1: Les subxarxes públiques donen IP pública', 'public-a i public-b assignen IP pública a les instàncies que s\'hi creen (MapPublicIpOnLaunch).',
            "**Objectiu:** que el gateway rebi IP pública en crear-se.\n\n**Per què:** és l'única instància que ha de ser accessible des d'Internet i fer de sortida.",
            <<<'SH'
            for s in public-a public-b; do
              m=$(aws ec2 describe-subnets --filters "Name=vpc-id,Values=$VPC" "Name=cidr-block,Values=$(net "$s")" --query 'Subnets[0].MapPublicIpOnLaunch' --output text)
              [ "$m" = True ] || { echo "$s no assigna IP pública ($m)"; exit 1; }
            done
            for s in private-a private-b; do
              m=$(aws ec2 describe-subnets --filters "Name=vpc-id,Values=$VPC" "Name=cidr-block,Values=$(net "$s")" --query 'Subnets[0].MapPublicIpOnLaunch' --output text)
              [ "$m" != True ] || { echo "$s assigna IP pública i és privada"; exit 1; }
            done
            echo "Les públiques assignen IP pública i les privades no"
            SH],
        ['P1: El web no té IP pública i el gateway sí', 'El gateway té una IP pública i el web cap. Comprova-ho amb la comanda P1 de l\'enunciat.',
            "**Objectiu:** que el web no es pugui adreçar des d'Internet.\n\n**Per què:** el que no té IP pública no es pot atacar directament.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            [ -n "$GW_PUB" ] && [ "$GW_PUB" != None ] || { echo "El gateway no té IP pública"; exit 1; }
            wp=$(aws ec2 describe-instances --instance-ids "$WEB" --query 'Reservations[0].Instances[0].PublicIpAddress' --output text)
            [ -z "$wp" ] || [ "$wp" = None ] || { echo "El web té la IP pública $wp"; exit 1; }
            echo "gateway $GW_PUB; web sense IP pública"
            SH],
        ['R3: El gateway no comprova origen i destí', 'La comprovació d\'origen/destí del gateway està desactivada. Comprova-ho: `aws ec2 describe-instances --query \'Reservations[].Instances[].SourceDestCheck\'`.',
            "**Objectiu:** que el gateway pugui reenviar paquets que no són seus.\n\n**Per què:** sense això, AWS descarta el trànsit del web que passa pel NAT.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            sd=$(aws ec2 describe-instances --instance-ids "$GW" --query 'Reservations[0].Instances[0].SourceDestCheck' --output text)
            [ "$sd" = False ] || { echo "La comprovació d'origen/destí del gateway està activada"; exit 1; }
            echo "Comprovació d'origen/destí desactivada"
            SH],
        ['P8: Les rutes són les del disseny', 'La taula de public-a envia 0.0.0.0/0 a l\'Internet Gateway i la de private-a a la instància gateway. Comprova-ho amb la comanda P8 de l\'enunciat.',
            "**Objectiu:** que la sortida de la xarxa privada passi pel gateway.\n\n**Per què:** les rutes decideixen per on surt cada paquet: és el que fa privada una subxarxa.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"
            rt() {  # subxarxa -> destí de 0.0.0.0/0 (la taula associada o, si no n'hi ha, la principal)
              local q='RouteTables[0].Routes[?DestinationCidrBlock==`0.0.0.0/0`] | [0].[GatewayId,InstanceId,NetworkInterfaceId]' t
              t=$(aws ec2 describe-route-tables --filters "Name=association.subnet-id,Values=$(subnet "$1")" --query "$q" --output text)
              [ -n "$t" ] && [ "$t" != None ] || t=$(aws ec2 describe-route-tables --filters "Name=vpc-id,Values=$VPC" Name=association.main,Values=true --query "$q" --output text)
              echo "$t"
            }
            pub=$(rt public-a); pri=$(rt private-a)
            grep -q 'igw-' <<< "$pub" || { echo "La taula de public-a no envia 0.0.0.0/0 a l'Internet Gateway (${pub:-cap ruta})"; exit 1; }
            eni=$(aws ec2 describe-instances --instance-ids "$GW" --query 'Reservations[0].Instances[0].NetworkInterfaces[0].NetworkInterfaceId' --output text)
            grep -qE "$GW|$eni" <<< "$pri" || { echo "La taula de private-a no envia 0.0.0.0/0 al gateway (${pri:-cap ruta})"; exit 1; }
            echo "public-a -> IGW; private-a -> gateway"
            SH],
        ['P2: La sortida del web passa pel gateway', 'Des del web, la IP que veu Internet és la IP pública del gateway. Comprova-ho dins del web: `curl -s https://checkip.amazonaws.com`.',
            "**Objectiu:** comprovar que el NAT manual funciona.\n\n**Per què:** el web no té IP pública: si surt, és perquè el gateway li fa masquerade.",
            <<<'SH'
            need "el web (Name *web)" "$WEB"; need "la IP pública del gateway" "$GW_PUB"
            ip=$(rcmd "$WEB" 'curl -s -m 8 https://checkip.amazonaws.com' | tr -d '[:space:]')
            [ "$ip" = "$GW_PUB" ] || { echo "El web surt amb la IP ${ip:-cap} i no amb la del gateway ($GW_PUB)"; exit 1; }
            echo "El web surt a Internet amb la IP del gateway ($ip)"
            SH],
        ['P3: El web pot descarregar actualitzacions', 'Des del web, el gestor de paquets baixa les metadades sense errors. Comprova-ho dins del web: `sudo apt update`.',
            "**Objectiu:** que els servidors interns es puguin actualitzar.\n\n**Per què:** un servidor sense actualitzacions acumula vulnerabilitats conegudes.",
            <<<'SH'
            need "el web (Name *web)" "$WEB"
            out=$(rcmd "$WEB" 'timeout 100 apt-get update -q > /tmp/he-apt 2>&1; rc=$?; grep -E "^(Err|W:|E:)" /tmp/he-apt | head -3; exit $rc' 2>&1) \
              || { echo "apt-get update falla al web: $(head -c 200 <<< "$out")"; exit 1; }
            [ -z "$(tr -d '[:space:]' <<< "$out")" ] || { echo "apt-get update dona errors: $(head -c 200 <<< "$out")"; exit 1; }
            echo "apt-get update sense errors"
            SH],
        ['P4: El gateway no exposa ports d\'administració', 'Des de fora, cap dels ports habituals d\'administració i serveis (22, 3389, 443, 3306...) del gateway és obert. El 80 no es prova: el tiquet 2 el publica. Comprova-ho: `nmap -Pn --top-ports 1000 <IP-pública-del-gateway>`.',
            "**Objectiu:** que el gateway no ofereixi cap servei a Internet.\n\n**Per què:** cada port obert és superfície d'atac; l'administració va per Session Manager.",
            <<<'SH'
            need "la IP pública del gateway" "$GW_PUB"
            oberts=""
            for p in $PORTS_ESCANEIG; do obert "$GW_PUB" "$p" && oberts="$oberts $p"; done
            [ -z "$oberts" ] || { echo "Ports oberts al gateway:$oberts"; exit 1; }
            echo "Cap port obert ($PORTS_ESCANEIG)"
            SH],
        ['P5: El web es veu des del gateway', 'Des del gateway, la pàgina del web respon per la seva IP privada. Comprova-ho dins del gateway: `curl -s http://<IP-privada-del-web>`.',
            "**Objectiu:** que el web funcioni i que el gateway hi arribi.\n\n**Per què:** és el camí que farà servir la publicació del tiquet 2.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            code=$(rcmd "$GW" "curl -s -m 5 -o /dev/null -w '%{http_code}' http://$WEB_IP/" | tr -d '[:space:]')
            [ "$code" = 200 ] || { echo "Des del gateway, http://$WEB_IP no respon amb 200 (${code:-cap resposta})"; exit 1; }
            echo "El gateway veu el web a http://$WEB_IP"
            SH],
        ['R6: Mínim privilegi als Security Groups', 'El SG del web només accepta el tcp/80 des del gateway, i el del gateway accepta el tcp/80 i el tcp/443 des de la xarxa privada. Cap regla obre a Internet res més que el tcp/80 del gateway (tiquet 2).',
            "**Objectiu:** que cada instància accepti només el que necessita.\n\n**Per què:** mínim privilegi: si una regla sobra, és una porta oberta que ningú vigila.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            W=$(sg_of "$WEB"); G=$(sg_of "$GW")
            sg_permet "$W" 80 "$GW_IP" "$G" || { echo "El SG del web no deixa entrar el tcp/80 del gateway"; exit 1; }
            sg_permet "$W" 80 198.51.100.7 && { echo "El SG del web deixa entrar el tcp/80 des d'Internet"; exit 1; }
            for p in 80 443; do sg_permet "$G" "$p" "$WEB_IP" "$W" || { echo "El SG del gateway no deixa entrar el tcp/$p del web (NAT)"; exit 1; }; done
            any=$(aws ec2 describe-security-groups --filters "Name=vpc-id,Values=$VPC" \
              --query "SecurityGroups[].IpPermissions[?IpRanges[?CidrIp=='0.0.0.0/0'] || Ipv6Ranges[?CidrIpv6=='::/0']][].[IpProtocol,FromPort,ToPort]" \
              --output text | tr -s '[:space:]' ' ' | sed 's/ $//')
            [ -z "$any" ] || [ "$any" = "tcp 80 80" ] || { echo "Regles obertes a Internet: $any"; exit 1; }
            echo "SG web: 80 des del gateway; SG gateway: 80 i 443 des del web"
            SH],
        ['P6: Cap Security Group amb el 22', 'Cap regla d\'entrada de cap Security Group de la VPC inclou el port 22, ni en un rang ni com a «tot el trànsit». Comprova-ho amb la comanda P6 de l\'enunciat.',
            "**Objectiu:** que no hi hagi cap port d'administració obert.\n\n**Per què:** el 22 obert és l'objectiu número u dels atacs automàtics.",
            <<<'SH'
            p22=$(aws ec2 describe-security-groups --filters "Name=vpc-id,Values=$VPC" \
              --query "SecurityGroups[].IpPermissions[?(FromPort<=\`22\` && ToPort>=\`22\`) || IpProtocol=='-1'][]" --output text)
            [ -z "$p22" ] || [ "$p22" = None ] || { echo "Hi ha regles que inclouen el 22: $(head -c 150 <<< "$p22")"; exit 1; }
            echo "Cap Security Group té el 22"
            SH],
        ['P7: Administració per Session Manager', 'El gateway i el web surten a Session Manager com a Online. Comprova-ho: `aws ssm describe-instance-information`.',
            "**Objectiu:** administrar sense obrir cap port d'entrada.\n\n**Per què:** Session Manager autentica amb IAM i deixa registre, sense exposar SSH.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            on=$(aws ssm describe-instance-information \
              --query "InstanceInformationList[?PingStatus=='Online' && (InstanceId=='$GW' || InstanceId=='$WEB')].InstanceId" --output text | wc -w)
            [ "$on" = 2 ] || { echo "Només $on de 2 instàncies (gateway i web) són Online a Session Manager"; exit 1; }
            echo "gateway i web Online a Session Manager"
            SH],
        ['R7: Instàncies etiquetades', 'El gateway i el web porten l\'etiqueta Project=TecnoServeis. Comprova-ho amb la comanda P1 de l\'enunciat.',
            "**Objectiu:** poder trobar i netejar els recursos del projecte.\n\n**Per què:** és el que fan servir destroy.sh i les proves per saber què és vostre.",
            <<<'SH'
            need "el gateway (Name *gateway)" "$GW"; need "el web (Name *web)" "$WEB"
            for i in "$GW" "$WEB"; do
              p=$(aws ec2 describe-instances --instance-ids "$i" --query 'Reservations[0].Instances[0].Tags[?Key==`Project`]|[0].Value' --output text)
              [ "$p" = TecnoServeis ] || { echo "La instància $i no té Project=TecnoServeis ($p)"; exit 1; }
            done
            echo "gateway i web amb Project=TecnoServeis"
            SH],
        ['R7: AMI del gateway i del web', 'Hi ha una AMI vostra del gateway i una del web (el nom o el tag Name conté «gateway» i «web»). Comprova-ho: `aws ec2 describe-images --owners self`.',
            "**Objectiu:** poder reconstruir l'entorn des de les AMI.\n\n**Per què:** les AMI són el punt de partida de cada sessió i el que fa reproduïble el build.",
            <<<'SH'
            names=$(aws ec2 describe-images --owners self --query 'Images[].[Name, Tags[?Key==`Name`]|[0].Value]' --output text)
            grep -qi gateway <<< "$names" || { echo "No hi ha cap AMI vostra del gateway"; exit 1; }
            grep -qi web <<< "$names" || { echo "No hi ha cap AMI vostra del web"; exit 1; }
            echo "AMI del gateway i del web: $(grep -ciE 'gateway|web' <<< "$names") imatges"
            SH],
    ];

    /** @return array<string, string> el codi de cada check, pel nom */
    public static function codes(): array
    {
        $codes = [];
        foreach (self::CHECKS as [$name, , , $body]) {
            $codes[$name] = TecnoServeisCode::build($body);
        }

        return $codes;
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
        $title = env('HEIMDEYE_T1_TASCA', $this->taskTitle);
        $task = $block->tasks()->firstOrCreate(['titol' => $title], ['posicio' => ($block->tasks()->max('posicio') ?? 0) + 1]);

        $deleted = $task->checks()->delete();
        $this->command?->info("Esborrats {$deleted} checks de «{$task->titol}».");

        foreach (self::CHECKS as $i => [$name, $description, $info, $body]) {
            $this->check($task, $i + 1, $name, $description, $info, $body);
        }
    }

    private function check(Task $task, int $position, string $name, string $description, string $info, string $body): void
    {
        $check = $task->checks()->create([
            'nom' => $name,
            'descripcio' => $description,
            'info' => $info,
            'executor' => Executor::Agent,
            'tipus' => CheckKind::Required,
            'punts' => 1,
            'posicio' => $position,
        ]);
        $check->versions()->create([
            'versio' => 1,
            'temps_max_seg' => 150,
            'codi' => TecnoServeisCode::build($body),
        ]);
    }
}
