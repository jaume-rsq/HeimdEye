<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Block;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Bloc 2 (El meu datacenter: Proxmox VE): accés SSH, bridges i VM, consola SPICE,
 * Proxmox Backup Server amb còpies a dos PBS (el de l'aula i el d'un company) i snapshots.
 * Cada check porta a `descripcio` el text per a l'alumne. L'`info` (objectiu i justificació)
 * és a `data/check_info.php`.
 *
 * Decisions de disseny:
 *  - Tots els checks del Proxmox són `ssh` amb `# hosts: pve`: qm, pvesm i els fitxers de
 *    /etc/pve només es poden consultar des del mateix host i amb `sudo -n`.
 *  - No es valida cap VM per SSH (no hi ha tipus d'equip per a les VM d'alumne) ni cap
 *    cosa de Cloudflare: això es valora a la documentació i a la prova final.
 *  - El router ja no es comprova en aquest bloc (es valida en altres tasques). El check
 *    «SSH al router» que ja hi pugui haver a la BD no s'esborra des d'aquí (perdria
 *    l'estat dels alumnes): es desactiva des del panell.
 *  - Una versió només corregeix codi: els checks que ja tenen versions no es toquen.
 */
class Block02ProxmoxSeeder extends Seeder
{
    /** Opcions comunes per als checks locals que fan ssh. */
    private const SSH = <<<'SH'
        # HE_SSH_OPTS: no desa les claus de host (els alumnes reinstal·len les VM).
        opts=(-o BatchMode=yes -o ConnectTimeout=5 $HE_SSH_OPTS)
        [ -n "$HE_SSH_KEY" ] && opts+=(-i "$HE_SSH_KEY")
        SH;

    /** Noms dels storages de tipus pbs del Proxmox (variable `pbs`, separats per espai). */
    private const PBS = <<<'SH'
        pbs=$(sudo -n pvesm status 2>/dev/null | awk 'NR>1 && $2=="pbs" {print $1}' | paste -sd' ' -)
        [ -n "$pbs" ] || { echo "HEIMDEYE_MSG: no hi ha cap storage de tipus pbs"; exit 1; }
        SH;

    private const BRIDGES = ['name' => 'BRIDGES', 'label' => 'Bridges que han d\'existir (separats per espai)', 'default' => 'vmbr0 vmbr1 vmbr2'];

    private const MIN_PBS = ['name' => 'MIN_PBS', 'label' => 'Servidors PBS diferents amb còpies', 'default' => 2];

    public function run(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();

        $block = Block::updateOrCreate(
            ['modul_id' => $module->id, 'numero' => 2],
            [
                'titol' => 'El meu datacenter: Proxmox VE',
                'data_inici' => '2026-09-21',
                'data_fi' => '2026-09-25',
                'posicio' => 2,
            ],
        );

        $this->task($block, 1, 'Accés SSH als equips', <<<'MD'
            El servidor Proxmox (tercera adreça útil de la teva xarxa d'aula) ha d'acceptar
            connexions SSH de l'usuari `jaume` amb clau. A més, ha de seguir les bones pràctiques:
            sense accés per contrasenya i sense `root` amb contrasenya.
            MD, [
            [
                'nom' => 'SSH al servidor Proxmox',
                'descripcio' => 'HeimdEye entra al teu Proxmox per SSH com a jaume amb la seva clau. Comprova-ho: `ssh jaume@<ip del teu Proxmox>` des de l\'estació.',
                'codi' => self::SSH."\n".<<<'SH'
                    ssh "${opts[@]}" "$HE_SSH_USER@$HE_IP_PVE" true || exit 1
                    echo "SSH correcte a $HE_IP_PVE"
                    SH,
            ],
            [
                'nom' => 'SSH amb bones pràctiques al Proxmox',
                'executor' => Executor::Ssh,
                'tipus' => CheckKind::Optional,
                'descripcio' => 'El servei SSH del Proxmox no admet contrasenya i root no pot entrar amb contrasenya. Comprova-ho: `sudo sshd -T | grep -E "passwordauthentication|permitrootlogin"`.',
                'codi' => <<<'SH'
                    # hosts: pve
                    out=$(sudo -n sshd -T 2>&1) || { echo "No es pot executar sshd -T"; exit 1; }
                    pw=$(echo "$out" | awk '$1=="passwordauthentication" {print $2}')
                    root=$(echo "$out" | awk '$1=="permitrootlogin" {print $2}')
                    [ "$pw" = "no" ] || { echo "HEIMDEYE_MSG: l'accés per contrasenya continua actiu (passwordauthentication $pw)"; exit 1; }
                    [ "$root" != "yes" ] || { echo "HEIMDEYE_MSG: root pot entrar amb contrasenya (permitrootlogin yes)"; exit 1; }
                    echo "HEIMDEYE_MSG: sense contrasenya, permitrootlogin $root"
                    SH,
            ],
        ]);

        $this->task($block, 2, 'Màquines virtuals al Proxmox', <<<'MD'
            Comprova i documenta la xarxa virtual del teu Proxmox (`vmbr0`, `vmbr1` i `vmbr2`) i
            crea màquines virtuals de prova (servidor Linux, client Linux i client Windows).
            MD, [
            [
                'nom' => 'Hi ha alguna VM definida',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'descripcio' => 'El Proxmox té almenys una màquina virtual. Comprova-ho: `sudo qm list`.',
                // qm necessita root: l'usuari de l'accés ha de poder fer sudo -n qm list.
                'codi' => <<<'SH'
                    # hosts: pve
                    out=$(sudo -n qm list) || { echo "No es pot executar qm list"; exit 1; }
                    vms=$(echo "$out" | tail -n +2)
                    [ -n "$vms" ] || { echo "Cap VM definida"; exit 1; }
                    echo "$(echo "$vms" | wc -l) VM: $(echo "$vms" | awk '{print $1 " " $2}' | paste -sd, -)"
                    SH,
            ],
            [
                'nom' => 'Els bridges vmbr0, vmbr1 i vmbr2 existeixen',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'parametres' => [self::BRIDGES],
                'descripcio' => 'Al Proxmox existeixen els tres bridges de xarxa virtual. Comprova-ho: `ip -br link show type bridge`. (No cal que estiguin UP: un bridge sense cap VM en marxa surt com a NO-CARRIER.)',
                'codi' => <<<'SH'
                    # hosts: pve
                    falten=""
                    for b in $HE_PARAM_BRIDGES; do
                      ip link show "$b" >/dev/null 2>&1 || falten="$falten $b"
                    done
                    [ -z "$falten" ] || { echo "HEIMDEYE_MSG: falta el bridge:$falten"; exit 1; }
                    echo "HEIMDEYE_MSG: existeixen $HE_PARAM_BRIDGES"
                    SH,
            ],
        ]);

        $this->task($block, 3, 'Consola de les VM: SPICE vs VNC', <<<'MD'
            Configura l'accés a la consola de les teves VM amb SPICE (adaptador de pantalla `qxl`)
            i amb VNC (el predeterminat), compara'ls i raona quin faries servir en cada situació.
            MD, [
            [
                'nom' => 'Alguna VM configurada per a SPICE',
                'executor' => Executor::Ssh,
                'descripcio' => 'Almenys una VM té l\'adaptador de pantalla qxl, necessari per a SPICE. Comprova-ho: `sudo qm config <vmid> | grep vga`.',
                'codi' => <<<'SH'
                    # hosts: pve
                    ids=$(sudo -n qm list) || { echo "No es pot executar qm list"; exit 1; }
                    ids=$(echo "$ids" | awk 'NR>1 {print $1}')
                    [ -n "$ids" ] || { echo "HEIMDEYE_MSG: no hi ha cap VM"; exit 1; }
                    spice=""
                    for id in $ids; do
                      vga=$(sudo -n qm config "$id" 2>/dev/null | awk -F': ' '$1=="vga" {print $2}')
                      case "$vga" in qxl*) spice="$spice $id" ;; esac
                    done
                    [ -n "$spice" ] || { echo "HEIMDEYE_MSG: cap VM amb vga: qxl (SPICE)"; exit 1; }
                    echo "HEIMDEYE_MSG: SPICE (qxl) a les VM:$spice"
                    SH,
            ],
        ]);

        $this->task($block, 4, 'Proxmox Backup Server i còpies de sistema', <<<'MD'
            Connecta el teu Proxmox amb el Proxmox Backup Server de l'aula i defineix una política de
            còpies per a les teves VM (freqüència i retenció justificades). Les còpies s'han de fer al
            teu PBS i també al d'un company, com a còpia off-site. Fes una prova de restauració i
            documenta-la.
            MD, [
            [
                'nom' => 'PBS connectat com a emmagatzematge',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'descripcio' => 'El Proxmox té un emmagatzematge de tipus PBS i està actiu. Comprova-ho: `sudo pvesm status`.',
                'codi' => <<<'SH'
                    # hosts: pve
                    out=$(sudo -n pvesm status 2>&1) || { echo "No es pot executar pvesm status"; exit 1; }
                    tots=$(echo "$out" | awk 'NR>1 && $2=="pbs" {print $1}' | paste -sd, -)
                    [ -n "$tots" ] || { echo "HEIMDEYE_MSG: no hi ha cap storage de tipus pbs"; exit 1; }
                    actius=$(echo "$out" | awk 'NR>1 && $2=="pbs" && $3=="active" {print $1}' | paste -sd, -)
                    [ -n "$actius" ] || { echo "HEIMDEYE_MSG: el storage PBS ($tots) no està actiu: el PBS no respon?"; exit 1; }
                    echo "HEIMDEYE_MSG: PBS actius: $actius"
                    SH,
            ],
            [
                'nom' => 'Tasca de còpia programada cap al PBS',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'descripcio' => 'Hi ha una tasca de còpia (vzdump) activa, amb horari, que desa al PBS. Comprova-ho a Datacenter → Backup, o amb `sudo cat /etc/pve/jobs.cfg`.',
                'codi' => <<<'SH'
                    # hosts: pve
                    SH
                    ."\n".self::PBS."\n".<<<'SH'
                    jobs=$(sudo -n cat /etc/pve/jobs.cfg 2>/dev/null | awk -v pbs="$pbs" '
                      function flush() { if (id != "" && sched != "" && en != 0 && (st in P)) print id "->" st }
                      BEGIN { n = split(pbs, a, " "); for (i = 1; i <= n; i++) P[a[i]] = 1 }
                      /^vzdump:/ { flush(); id = $2; sched = ""; st = ""; en = 1; next }
                      /^[ \t]+schedule[ \t]/ { sub(/^[ \t]+schedule[ \t]+/, ""); sched = $0 }
                      /^[ \t]+storage[ \t]/ { st = $2 }
                      /^[ \t]+enabled[ \t]/ { en = $2 }
                      END { flush() }' | paste -sd, -)
                    [ -n "$jobs" ] || { echo "HEIMDEYE_MSG: cap tasca de còpia activa i amb horari cap a un storage PBS"; exit 1; }
                    echo "HEIMDEYE_MSG: tasca(es): $jobs"
                    SH,
            ],
            [
                'nom' => 'Retenció definida per a les còpies',
                'executor' => Executor::Ssh,
                'descripcio' => 'Hi ha un esquema de retenció (prune-backups: keep-last, keep-daily...) a la tasca de còpia o al storage PBS. Comprova-ho a l\'editor de la tasca, pestanya Retention.',
                'codi' => <<<'SH'
                    # hosts: pve
                    SH
                    ."\n".self::PBS."\n".<<<'SH'
                    jobs=$(sudo -n cat /etc/pve/jobs.cfg 2>/dev/null | awk -v pbs="$pbs" '
                      function flush() { if (id != "" && pr != "" && (st in P)) print id }
                      BEGIN { n = split(pbs, a, " "); for (i = 1; i <= n; i++) P[a[i]] = 1 }
                      /^vzdump:/ { flush(); id = $2; pr = ""; st = ""; next }
                      /^[ \t]+storage[ \t]/ { st = $2 }
                      /^[ \t]+(prune-backups|maxfiles)[ \t]/ { pr = $2 }
                      END { flush() }' | paste -sd, -)
                    stor=$(sudo -n cat /etc/pve/storage.cfg 2>/dev/null | awk -v pbs="$pbs" '
                      BEGIN { n = split(pbs, a, " "); for (i = 1; i <= n; i++) P[a[i]] = 1 }
                      /^[a-z]+:/ { name = ($1 == "pbs:") ? $2 : ""; next }
                      /^[ \t]+prune-backups[ \t]/ { if (name in P) print name }' | paste -sd, -)
                    [ -n "$jobs$stor" ] || { echo "HEIMDEYE_MSG: ni la tasca ni el storage PBS defineixen retenció (prune-backups)"; exit 1; }
                    echo "HEIMDEYE_MSG: retenció a tasques: ${jobs:--}; a storages: ${stor:--}"
                    SH,
            ],
            [
                'nom' => 'Hi ha còpies de VM al PBS',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'temps_max_seg' => 40,
                'descripcio' => 'El PBS té almenys una còpia de les teves VM. Comprova-ho: `sudo pvesm list <storage-pbs> --content backup`.',
                'codi' => <<<'SH'
                    # hosts: pve
                    SH
                    ."\n".self::PBS."\n".<<<'SH'
                    for s in $pbs; do
                      vmids=$(sudo -n pvesm list "$s" --content backup 2>/dev/null | awk 'NR>1 {print $5}' | sort -un | paste -sd, -)
                      [ -n "$vmids" ] && { echo "HEIMDEYE_MSG: còpies a $s (VM $vmids)"; exit 0; }
                    done
                    echo "HEIMDEYE_MSG: cap còpia als storages PBS ($pbs): s'ha executat la tasca?"
                    exit 1
                    SH,
            ],
            [
                'nom' => 'Còpies també al PBS d\'un company',
                'executor' => Executor::Ssh,
                'punts' => 2,
                'temps_max_seg' => 60,
                'parametres' => [self::MIN_PBS],
                'descripcio' => 'Tens còpies a dos servidors PBS diferents (el de l\'aula i el d\'un company), com a còpia off-site. Comprova-ho: dos storages PBS amb `server` diferent a `sudo cat /etc/pve/storage.cfg`.',
                'codi' => <<<'SH'
                    # hosts: pve
                    SH
                    ."\n".self::PBS."\n".<<<'SH'
                    servers=$(sudo -n cat /etc/pve/storage.cfg 2>/dev/null | awk '
                      /^[a-z]+:/ { n = ($1 == "pbs:") ? $2 : ""; next }
                      n != "" && $1 == "server" { print n, $2 }')
                    distinct=""
                    while read -r name server; do
                      [ -n "$name" ] || continue
                      c=$(sudo -n pvesm list "$name" --content backup 2>/dev/null | tail -n +2 | wc -l)
                      [ "$c" -gt 0 ] || continue
                      case " $distinct " in *" $server "*) ;; *) distinct="$distinct $server" ;; esac
                    done <<< "$servers"
                    cnt=$(echo $distinct | wc -w)
                    [ "$cnt" -ge "$HE_PARAM_MIN_PBS" ] || { echo "HEIMDEYE_MSG: còpies a $cnt servidor(s) PBS; en calen $HE_PARAM_MIN_PBS"; exit 1; }
                    echo "HEIMDEYE_MSG: còpies a $cnt servidors PBS:$distinct"
                    SH,
            ],
        ]);

        $this->task($block, 5, 'Snapshots', <<<'MD'
            Fes snapshots de les teves VM i restaura'n alguna a un punt anterior. Valora els avantatges
            dels snapshots i si són realment una alternativa a les còpies de seguretat.
            MD, [
            [
                'nom' => 'Alguna VM té snapshots',
                'executor' => Executor::Ssh,
                'descripcio' => 'Almenys una VM té un snapshot. Comprova-ho: `sudo qm listsnapshot <vmid>`.',
                'codi' => <<<'SH'
                    # hosts: pve
                    ids=$(sudo -n qm list) || { echo "No es pot executar qm list"; exit 1; }
                    ids=$(echo "$ids" | awk 'NR>1 {print $1}')
                    [ -n "$ids" ] || { echo "HEIMDEYE_MSG: no hi ha cap VM"; exit 1; }
                    amb=""
                    for id in $ids; do
                      n=$(sudo -n qm listsnapshot "$id" 2>/dev/null | grep -vc 'You are here' || true)
                      [ "${n:-0}" -gt 0 ] && amb="$amb $id($n)"
                    done
                    [ -n "$amb" ] || { echo "HEIMDEYE_MSG: cap VM té snapshots"; exit 1; }
                    echo "HEIMDEYE_MSG: snapshots a:$amb"
                    SH,
            ],
        ]);
    }

    /** @param  list<array<string, mixed>>  $checks */
    private function task(Block $block, int $position, string $title, string $statement, array $checks): void
    {
        $task = $block->tasks()->updateOrCreate(
            ['titol' => $title],
            ['enunciat' => $statement, 'posicio' => $position],
        );

        $info = (require database_path('seeders/data/check_info.php'))[$block->numero] ?? [];

        foreach ($checks as $i => $def) {
            $check = $task->checks()->updateOrCreate(
                ['nom' => $def['nom']],
                [
                    'descripcio' => $def['descripcio'],
                    'info' => $info[$def['nom']] ?? null,
                    'executor' => $def['executor'] ?? Executor::Local,
                    'tipus' => $def['tipus'] ?? CheckKind::Required,
                    'punts' => $def['punts'] ?? 1,
                    'actiu_des' => '2026-09-21',
                    'data_limit' => '2026-10-06 23:59:59',
                    'posicio' => $i + 1,
                ],
            );

            // No es toca el codi d'un check que ja té versions: es corregeix des del panell (versió nova).
            if (! $check->versions()->exists()) {
                $check->versions()->create([
                    'versio' => 1,
                    'codi' => $def['codi'],
                    'parametres' => $def['parametres'] ?? null,
                    'temps_max_seg' => $def['temps_max_seg'] ?? 20,
                ]);
            }
        }
    }
}
