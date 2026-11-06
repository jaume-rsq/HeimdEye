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
 * Incidència «La web de TecnoServeis no respon» (projecte AWS: VPC tecnoserveis-<NomCognom>,
 * instància <NomCognom>-web amb nginx darrere d'un gateway). És l'antic incident.sh del professor
 * separat en 6 opcions: una acció d'avaria i una de reparació amb el paràmetre AVARIA (0-5), i un
 * check de resolució per avaria.
 *
 * Tot s'executa amb l'agent amb rol «aws»: una instància (o la WSL de l'alumne) amb l'AWS CLI i
 * les credencials del seu Learner Lab. Les avaries de la web (nginx) hi van per SSM Run Command,
 * com feia l'script. El NomCognom es dedueix del tag Name de la instància web en marxa.
 *
 * Va al mòdul 0378 (del curs actual), bloc 3, tasca «Tiquet 2», que ha de crear abans el professor;
 * es pot canviar amb HEIMDEYE_INCIDENCIA_MODUL, _BLOC i _TASCA. Els checks hi queden desactivats i
 * sense punts: només serveixen per resoldre la incidència.
 */
class IncidenciaTecnoServeisSeeder extends Seeder
{
    public string $moduleCode = '0378';

    public int $blockNumber = 3;

    public string $taskTitle = 'Tiquet 2';

    /** Comú a les accions i els checks: troba la web, la VPC i el NomCognom, i les funcions de l'script. */
    public const PRELUDE = <<<'SH'
        # hosts: aws
        command -v aws >/dev/null || { echo "Falta l'AWS CLI en aquest equip (rol aws)"; exit 1; }
        read -r WEB VPC NAME <<< "$(aws ec2 describe-instances \
          --filters Name=tag:Project,Values=TecnoServeis "Name=tag:Name,Values=*-web" Name=instance-state-name,Values=running \
          --query 'Reservations[0].Instances[0].[InstanceId,VpcId,Tags[?Key==`Name`]|[0].Value]' --output text 2>&1)"
        [ -n "$WEB" ] && [ "$WEB" != None ] && [ "${WEB#i-}" != "$WEB" ] \
          || { echo "No trobo la instància web en marxa (tags Project=TecnoServeis i Name=<NomCognom>-web): $WEB $VPC $NAME"; exit 1; }
        NC=${NAME%-web}
        sg_id() { aws ec2 describe-security-groups --filters "Name=vpc-id,Values=$VPC" "Name=group-name,Values=$1" --query 'SecurityGroups[0].GroupId' --output text; }
        subnet() { aws ec2 describe-subnets --filters "Name=tag:Name,Values=$NC-$1" "Name=vpc-id,Values=$VPC" --query 'Subnets[0].SubnetId' --output text; }
        acl_of() { aws ec2 describe-network-acls --filters "Name=association.subnet-id,Values=$(subnet "$1")" --query 'NetworkAcls[0].NetworkAclId' --output text; }
        need() { [ -n "$2" ] && [ "$2" != None ] || { echo "No trobo $1 a la VPC $VPC"; exit 1; }; }
        # Comanda a la web com a root (SSM Run Command). Escriu la sortida i surt amb el seu codi.
        rcmd() {
          local c
          c=$(aws ssm send-command --instance-ids "$WEB" --document-name AWS-RunShellScript \
                --parameters "$(python3 -c 'import json, sys; print(json.dumps({"commands": [sys.argv[1]]}))' "$1")" \
                --query Command.CommandId --output text) || return 1
          aws ssm wait command-executed --command-id "$c" --instance-id "$WEB" 2>/dev/null || true
          aws ssm get-command-invocation --command-id "$c" --instance-id "$WEB" --query StandardOutputContent --output text
          [ "$(aws ssm get-command-invocation --command-id "$c" --instance-id "$WEB" --query ResponseCode --output text)" = 0 ]
        }
        # Crida aws idempotent: «ja hi és» o «no hi és» no és cap error.
        tolera() {
          local out
          out=$("$@" 2>&1) && return 0
          grep -qE 'Duplicate|NotFound' <<< "$out" && return 0
          echo "$out"; return 1
        }
        SH;

    /** Nom de cada opció; la clau és el valor del paràmetre AVARIA. */
    private const OPTIONS = [
        0 => 'SG web: el gateway entra pel 8080 en lloc del 80',
        1 => 'nginx només escolta a 127.0.0.1',
        2 => 'nginx aturat',
        3 => 'NACL de public-a bloqueja el 80',
        4 => 'NACL de private-a bloqueja el 80',
        5 => 'SG gateway tancat al 80 des d\'Internet',
    ];

    private const INJECT = <<<'SH'
        case "$HE_PARAM_AVARIA" in
          0) W=$(sg_id web); G=$(sg_id gateway); need "el SG web" "$W"; need "el SG gateway" "$G"
             tolera aws ec2 revoke-security-group-ingress --group-id "$W" --protocol tcp --port 80 --source-group "$G" || exit 1
             tolera aws ec2 authorize-security-group-ingress --group-id "$W" --protocol tcp --port 8080 --source-group "$G" || exit 1 ;;
          1) rcmd "sed -i 's/listen 80 default_server;/listen 127.0.0.1:80 default_server;/' /etc/nginx/sites-enabled/default && nginx -s reload" || exit 1 ;;
          2) rcmd "systemctl stop nginx" || exit 1 ;;
          3|4) [ "$HE_PARAM_AVARIA" = 3 ] && s=public-a || s=private-a
             A=$(acl_of "$s"); need "la NACL de $s" "$A"
             tolera aws ec2 create-network-acl-entry --network-acl-id "$A" --rule-number 50 --ingress \
               --protocol tcp --port-range From=80,To=80 --cidr-block 0.0.0.0/0 --rule-action deny || exit 1 ;;
          5) G=$(sg_id gateway); need "el SG gateway" "$G"
             tolera aws ec2 revoke-security-group-ingress --group-id "$G" --protocol tcp --port 80 --cidr 0.0.0.0/0 || exit 1 ;;
          *) echo "AVARIA ha de ser 0-5"; exit 1 ;;
        esac
        echo "Avaria $HE_PARAM_AVARIA injectada a $NC"
        SH;

    private const REPAIR = <<<'SH'
        case "$HE_PARAM_AVARIA" in
          0) W=$(sg_id web); G=$(sg_id gateway); need "el SG web" "$W"; need "el SG gateway" "$G"
             tolera aws ec2 revoke-security-group-ingress --group-id "$W" --protocol tcp --port 8080 --source-group "$G" || exit 1
             tolera aws ec2 authorize-security-group-ingress --group-id "$W" --protocol tcp --port 80 --source-group "$G" || exit 1 ;;
          1) rcmd "sed -i 's/listen 127.0.0.1:80 default_server;/listen 80 default_server;/' /etc/nginx/sites-enabled/default && nginx -s reload" || exit 1 ;;
          2) rcmd "systemctl start nginx" || exit 1 ;;
          3|4) [ "$HE_PARAM_AVARIA" = 3 ] && s=public-a || s=private-a
             A=$(acl_of "$s"); need "la NACL de $s" "$A"
             tolera aws ec2 delete-network-acl-entry --network-acl-id "$A" --rule-number 50 --ingress || exit 1 ;;
          5) G=$(sg_id gateway); need "el SG gateway" "$G"
             tolera aws ec2 authorize-security-group-ingress --group-id "$G" --protocol tcp --port 80 --cidr 0.0.0.0/0 || exit 1 ;;
          *) echo "AVARIA ha de ser 0-5"; exit 1 ;;
        esac
        echo "Avaria $HE_PARAM_AVARIA desfeta a $NC"
        SH;

    /** Checks de resolució: passen quan la configuració és la correcta (l'alumne l'ha arreglada). */
    private const CHECKS = [
        0 => ['SG web: el gateway entra pel port 80', 'El grup de seguretat web deixa entrar el gateway pel port 80.', <<<'SH'
            W=$(sg_id web); G=$(sg_id gateway); need "el SG web" "$W"; need "el SG gateway" "$G"
            n=$(aws ec2 describe-security-groups --group-ids "$W" --output text --query \
              "length(SecurityGroups[0].IpPermissions[?IpProtocol=='-1' || (IpProtocol=='tcp' && FromPort<=\`80\` && ToPort>=\`80\`)].UserIdGroupPairs[] | [?GroupId=='$G'])")
            [ "$n" -gt 0 ] 2>/dev/null || { echo "El SG web no deixa entrar el gateway pel port 80"; exit 1; }
            echo "El SG web deixa entrar el gateway pel 80"
            SH],
        1 => ['nginx escolta pel 80 a totes les interfícies', 'nginx escolta al port 80 a una adreça que no és només 127.0.0.1.', <<<'SH'
            out=$(rcmd "ss -ltnH 'sport = :80' | awk '{print \$4}'") || { echo "No es pot consultar la web per SSM: $out"; exit 1; }
            echo "$out" | grep -E ':80$' | grep -qvE '^(127\.|\[::1\])' || { echo "nginx no escolta pel 80 fora de localhost: ${out:-res}"; exit 1; }
            echo "nginx escolta a $(echo "$out" | grep -E ':80$' | tr '\n' ' ')"
            SH],
        2 => ['nginx en marxa', 'El servei nginx de la instància web està actiu.', <<<'SH'
            out=$(rcmd "systemctl is-active nginx") || true
            [ "$(echo "$out" | tr -d '[:space:]')" = active ] || { echo "nginx no està actiu: ${out:-sense resposta}"; exit 1; }
            echo "nginx actiu"
            SH],
        3 => ['NACL de public-a no bloqueja el 80', 'La NACL de la subxarxa public-a no té cap regla d\'entrada que denegui el port 80 des de 0.0.0.0/0.', self::NACL_CHECK],
        4 => ['NACL de private-a no bloqueja el 80', 'La NACL de la subxarxa private-a no té cap regla d\'entrada que denegui el port 80 des de 0.0.0.0/0.', self::NACL_CHECK],
        5 => ['SG gateway obert al 80 des d\'Internet', 'El grup de seguretat gateway deixa entrar el port 80 des de 0.0.0.0/0.', <<<'SH'
            G=$(sg_id gateway); need "el SG gateway" "$G"
            n=$(aws ec2 describe-security-groups --group-ids "$G" --output text --query \
              "length(SecurityGroups[0].IpPermissions[?IpProtocol=='-1' || (IpProtocol=='tcp' && FromPort<=\`80\` && ToPort>=\`80\`)].IpRanges[] | [?CidrIp=='0.0.0.0/0'])")
            [ "$n" -gt 0 ] 2>/dev/null || { echo "El SG gateway no deixa entrar el 80 des d'Internet"; exit 1; }
            echo "El SG gateway és obert al 80"
            SH],
    ];

    /** Comú als dos checks de NACL; la subxarxa surt de HE_PARAM_SUBXARXA. */
    private const NACL_CHECK = <<<'SH'
        A=$(acl_of "$HE_PARAM_SUBXARXA"); need "la NACL de $HE_PARAM_SUBXARXA" "$A"
        n=$(aws ec2 describe-network-acls --network-acl-ids "$A" --output text --query \
          "length(NetworkAcls[0].Entries[?Egress==\`false\` && RuleAction=='deny' && RuleNumber<\`32767\` && CidrBlock=='0.0.0.0/0' && (Protocol=='-1' || (Protocol=='6' && PortRange.From<=\`80\` && PortRange.To>=\`80\`))])")
        [ "$n" = 0 ] || { echo "La NACL de $HE_PARAM_SUBXARXA té $n regles que deneguen el 80"; exit 1; }
        echo "La NACL de $HE_PARAM_SUBXARXA no bloqueja el 80"
        SH;

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
        // La tasca la crea el professor; si no hi és, es crea buida perquè hi vagin els checks.
        $task = $block->tasks()->firstOrCreate(['titol' => $title], ['posicio' => ($block->tasks()->max('posicio') ?? 0) + 1]);

        $inject = $this->action($module, $block, 'TecnoServeis: injectar avaria', 'Trenca una part del camí cap a la web (paràmetre AVARIA, 0-5). Feta per a la incidència «La web de TecnoServeis no respon».', self::INJECT, true, 1);
        $repair = $this->action($module, $block, 'TecnoServeis: desfer avaria', 'Desfà l\'avaria AVARIA (0-5) de la incidència «La web de TecnoServeis no respon».', self::REPAIR, false, 2);

        $incident = Incident::updateOrCreate(
            ['modul_id' => $module->id, 'nom' => 'La web de TecnoServeis no respon'],
            [
                'bloc_id' => $block->id,
                'equip' => 'aws',
                'simptoma' => 'Els clients diuen que la web de TecnoServeis no carrega. Troba què ha canviat i arregla-ho.',
                'notes' => 'Antic incident.sh: llança-la amb 3 avaries per alumne. Cal l\'agent amb rol aws (AWS CLI amb les credencials del Learner Lab) i SSM a la web.',
            ],
        );

        foreach (self::OPTIONS as $n => $name) {
            [$checkName, $description, $code] = self::CHECKS[$n];
            $check = $this->check($task, $n + 1, $checkName, $description, $code, in_array($n, [3, 4], true) ? ($n === 3 ? 'public-a' : 'private-a') : null);

            $incident->options()->updateOrCreate(
                ['nom' => $name],
                [
                    'accio_id' => $inject->id,
                    'parametres' => ['AVARIA' => (string) $n],
                    'check_id' => $check->id,
                    'accio_reparacio_id' => $repair->id,
                    'posicio' => $n,
                ],
            );
        }
    }

    private function action(Module $module, Block $block, string $name, string $description, string $body, bool $fault, int $position): Action
    {
        $action = $module->actions()->updateOrCreate(
            ['nom' => $name],
            ['bloc_id' => $block->id, 'descripcio' => $description, 'executor' => Executor::Agent, 'es_incidencia' => $fault, 'posicio' => $position],
        );
        if (! $action->versions()->exists()) {
            $action->versions()->create([
                'versio' => 1,
                'temps_max_seg' => 120,
                'parametres' => [['name' => 'AVARIA', 'label' => 'Avaria (0-5)', 'default' => '0']],
                'codi' => self::PRELUDE."\n".$body,
            ]);
        }

        return $action;
    }

    private function check(Task $task, int $position, string $name, string $description, string $body, ?string $subnet): Check
    {
        $check = $task->checks()->updateOrCreate(
            ['nom' => $name],
            [
                'descripcio' => $description,
                'executor' => Executor::Agent,
                'tipus' => CheckKind::Optional,
                'punts' => 0,
                'es_actiu' => false, // no surt com a feina de l'alumne: només resol la incidència
                'posicio' => $position,
            ],
        );
        if (! $check->versions()->exists()) {
            $check->versions()->create([
                'versio' => 1,
                'temps_max_seg' => 90,
                'parametres' => $subnet ? [['name' => 'SUBXARXA', 'label' => 'Subxarxa', 'default' => $subnet]] : null,
                'codi' => self::PRELUDE."\n".$body,
            ]);
        }

        return $check;
    }
}
