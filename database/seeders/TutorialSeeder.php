<?php

namespace Database\Seeders;

use App\Enums\CheckKind;
use App\Enums\CredentialType;
use App\Enums\Executor;
use App\Enums\ModuleKind;
use App\Enums\VariableScope;
use App\Models\AcademicYear;
use App\Models\Action;
use App\Models\Block;
use App\Models\Check;
use App\Models\Credential;
use App\Models\Cycle;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Tutorial de HeimdEye: un curs a part, organitzat com qualsevol curs real, que alhora és la
 * documentació de l'app. El visitant fa de professor de 4 alumnes ficticis (els contenidors
 * alumne01..alumne04 de docker/compose.demo.yaml): llegeix l'enunciat de cada tasca, executa els
 * checks, fa servir les accions per configurar els equips dels alumnes i crea els seus.
 *
 * L'estructura mateixa és un exemple: curs → cicle → mòduls → blocs → tasques → checks, amb
 * alumnes, un tipus d'equip, una variable de mòdul, una credencial i accions amb verificació.
 * Tots els textos (enunciat, descripció i info) són els que es veuen a l'app; heimdeye:tutorial
 * els exporta a docs/demo-tutorial.md.
 */
class TutorialSeeder extends Seeder
{
    public const CYCLE = 'TUT';

    public function run(): void
    {
        $year = AcademicYear::updateOrCreate(
            ['nom' => 'Tutorial'],
            ['data_inici' => '2026-09-01', 'data_fi' => '2027-08-31', 'es_actual' => ! AcademicYear::where('es_actual', true)->where('nom', '!=', 'Tutorial')->exists()],
        );
        $cycle = Cycle::updateOrCreate(['codi' => self::CYCLE], ['nom' => 'Tutorial de HeimdEye']);

        // Sense clau desada: fa servir HEIMDEYE_SSH_KEY_PATH, que a la demo només és al contenidor de proves.
        $credential = Credential::firstOrCreate(
            ['nom' => 'SSH alumnes de la demo'],
            ['tipus' => CredentialType::Ssh, 'nom_usuari' => 'alumne'],
        );

        $students = collect([
            [1, 'ada', 'Ada Lovelace'],
            [2, 'alan', 'Alan Turing'],
            [3, 'grace', 'Grace Hopper'],
            [4, 'linus', 'Linus Torvalds'],
        ])->map(fn (array $s) => Student::updateOrCreate(
            ['curs_id' => $year->id, 'usuari' => $s[1]],
            ['cicle_id' => $cycle->id, 'nom_complet' => $s[2], 'alies' => strtok($s[2], ' '), 'num_taula' => $s[0], 'x' => $s[0], 'es_actiu' => true],
        ));

        $checks = $this->module($year, $cycle, $credential, 'TUT1', 'Primers passos amb els checks', 1);
        $actions = $this->module($year, $cycle, $credential, 'TUT2', 'Accions: configurar els equips dels alumnes', 2);
        foreach ([$checks, $actions] as $module) {
            $module->students()->syncWithoutDetaching($students->pluck('id'));
            $module->teachers()->syncWithoutDetaching(User::where('rol', 'admin')->pluck('id'));
        }

        $this->checksModule($checks);
        $this->actionsModule($actions);
    }

    /** Mòdul del tutorial amb l'equip de cada alumne ("servidor") i la seva adreça com a variable. */
    private function module(AcademicYear $year, Cycle $cycle, Credential $credential, string $code, string $name, int $position): Module
    {
        $module = Module::updateOrCreate(
            ['curs_id' => $year->id, 'cicle_id' => $cycle->id, 'codi' => $code],
            [
                'nom' => $name,
                'descripcio' => "Tutorial de HeimdEye, part {$position}.",
                'tipus' => ModuleKind::Free,
                'credencial_id' => $credential->id,
            ],
        );
        $module->variables()->updateOrCreate(
            ['abast' => VariableScope::Module, 'clau' => 'ip_servidor'],
            ['valor' => 'alumne{{num_taula}}'],
        );
        $module->hostTypes()->updateOrCreate(
            ['nom' => 'servidor'],
            ['grup_ansible' => 'servidor', 'nom_host' => 'servidor{{num_taula}}', 'adreca' => '{{ip_servidor}}', 'posicio' => 1],
        );

        return $module;
    }

    private function checksModule(Module $module): void
    {
        $block = $this->block($module, 1, 'Com està organitzat HeimdEye');

        $this->task($block, 1, 'Curs, mòduls, blocs, tasques i checks', <<<'MD'
            Aquest tutorial és un curs de HeimdEye com qualsevol altre. El **curs** (Tutorial) té un **cicle**,
            i el cicle té **mòduls**: aquest (TUT1, checks) i el següent (TUT2, accions). Cada mòdul es divideix
            en **blocs** (més o menys una setmana de classe), cada bloc en **tasques** (el que fa l'alumne) i
            cada tasca en **checks**: comprovacions binàries (passa o no passa) que HeimdEye executa cada nit
            sobre els equips de cada alumne.

            Els 4 alumnes són ficticis (Ada, Alan, Grace i Linus) i cadascun té un **servidor** (un contenidor
            Ubuntu). Ets el seu professor: obre **Checks → Llista de checks**, tria el primer check d'aquesta
            tasca i prem **Verificar ara** (▶). El resultat surt a l'historial i al **Seguiment**.
            MD, [
            [
                'nom' => 'El servidor de l\'alumne respon',
                'executor' => Executor::Local,
                'descripcio' => 'HeimdEye fa ping al servidor de l\'alumne.',
                'info' => $this->info(
                    'veure el check més senzill: un script de bash que s\'executa al servidor HeimdEye (executor **local**) i passa si acaba amb codi 0.',
                    'cada alumne té les seves variables, que arriben a l\'script com a `HE_<VARIABLE>`. Aquí `HE_IP_SERVIDOR` és la variable de mòdul `ip_servidor = alumne{{num_taula}}`, i per a l\'Ada val `alumne01`. La línia `HEIMDEYE_MSG:` és el missatge que veuen el professor i l\'alumne.',
                ),
                'codi' => <<<'SH'
                    ping -c1 -W2 "$HE_IP_SERVIDOR" >/dev/null || { echo "HEIMDEYE_MSG: $HE_IP_SERVIDOR no respon"; exit 1; }
                    echo "HEIMDEYE_MSG: $HE_IP_SERVIDOR respon al ping"
                    SH,
            ],
            [
                'nom' => 'HeimdEye entra per SSH al servidor',
                'executor' => Executor::Ssh,
                'descripcio' => 'HeimdEye entra al servidor de l\'alumne per SSH amb la credencial del mòdul i hi executa l\'script.',
                'info' => $this->info(
                    'veure l\'executor **ssh**: el mateix bash, però executat a l\'equip de l\'alumne.',
                    'la capçalera `# hosts: servidor` diu a quins tipus d\'equip s\'executa (Inventari → Equips i variables). L\'usuari i la clau són els de la credencial del mòdul; la clau no surt mai del servidor HeimdEye.',
                ),
                'codi' => <<<'SH'
                    # hosts: servidor
                    echo "HEIMDEYE_MSG: dins de $(hostname) com a $(whoami)"
                    SH,
            ],
        ]);

        $this->task($block, 2, 'Un check que falla', <<<'MD'
            Un check que falla diu a l'alumne què li falta. Aquest comprova que el servidor publica una web
            amb el nom de l'alumne. Ara falla per a tothom: a la tasca del mòdul TUT2 la publicaràs amb una
            **acció**, i el check passarà a verd sol.

            Fixa't en els **paràmetres**: el check `La web té el nom de l'alumne` en té un (`TITOL`), amb un
            valor per defecte, que arriba a l'script com a `HE_PARAM_TITOL`.
            MD, [
            [
                'nom' => 'El servidor web respon',
                'executor' => Executor::Local,
                'descripcio' => 'El port 80 del servidor de l\'alumne respon. Comprova-ho: `curl -I http://alumneNN`.',
                'info' => $this->info(
                    'comprovar un servei des de fora, com ho faria un usuari.',
                    'mirar la configuració no garanteix que el servei funcioni: el check obre una connexió de veritat.',
                ),
                'codi' => <<<'SH'
                    curl -sf -m 3 -o /dev/null "http://$HE_IP_SERVIDOR/" || { echo "HEIMDEYE_MSG: la web de $HE_IP_SERVIDOR no respon"; exit 1; }
                    echo "HEIMDEYE_MSG: la web respon"
                    SH,
            ],
            [
                'nom' => 'La web té el nom de l\'alumne',
                'executor' => Executor::Local,
                'punts' => 2,
                'parametres' => [['name' => 'TITOL', 'label' => 'Text que ha de sortir abans del nom', 'default' => 'Web de']],
                'descripcio' => 'La pàgina principal diu «Web de <nom de l\'alumne>».',
                'info' => $this->info(
                    'veure un check amb paràmetres i amb el nom de l\'alumne (`HE_NOM_COMPLET`).',
                    'els paràmetres permeten reutilitzar el mateix codi canviant només un valor, i el valor per defecte és el que s\'usa a la ronda de cada nit.',
                ),
                'codi' => <<<'SH'
                    esperat="$HE_PARAM_TITOL $HE_NOM_COMPLET"
                    curl -sf -m 3 "http://$HE_IP_SERVIDOR/" | grep -qF "$esperat" \
                      || { echo "HEIMDEYE_MSG: la web no diu «$esperat»"; exit 1; }
                    echo "HEIMDEYE_MSG: la web diu «$esperat»"
                    SH,
            ],
        ]);

        $block = $this->block($module, 2, 'Crea el teu check');

        $this->task($block, 1, 'Missatge de benvinguda', <<<'MD'
            Ara et toca a tu. Crea un check en aquesta tasca (**Checks → Nou check**) que comprovi que el fitxer
            `/etc/motd` del servidor de l'alumne conté la paraula `Benvingut`. Pista: executor **ssh**,
            capçalera `# hosts: servidor` i `grep -q Benvingut /etc/motd`.

            Abans de desar-lo, **prova'l** amb un o dos alumnes (botó Provar): el resultat surt a sota del
            formulari i no queda enlloc. Després fes servir l'acció `Missatge de benvinguda` del mòdul TUT2
            per a la meitat dels alumnes, i torna a verificar: uns en verd i els altres en vermell.

            Si t'encalles, el check opcional d'aquesta tasca és una solució: duplica'l (botó Duplicar) i
            canvia-hi el que vulguis.
            MD, [
            [
                'nom' => 'Solució: el motd dona la benvinguda',
                'executor' => Executor::Ssh,
                'tipus' => CheckKind::Optional,
                'descripcio' => '`/etc/motd` conté «Benvingut».',
                'info' => $this->info(
                    'tenir una solució de referència per duplicar.',
                    'els checks opcionals no compten per al mínim de la tasca, però sumen punts.',
                ),
                'codi' => <<<'SH'
                    # hosts: servidor
                    grep -q Benvingut /etc/motd 2>/dev/null || { echo "HEIMDEYE_MSG: /etc/motd no dona la benvinguda"; exit 1; }
                    echo "HEIMDEYE_MSG: $(head -1 /etc/motd)"
                    SH,
            ],
        ]);
    }

    private function actionsModule(Module $module): void
    {
        $block = $this->block($module, 1, 'Accions del professor');

        $this->task($block, 1, 'Publicar la web dels alumnes', <<<'MD'
            Les **accions** són scripts que el professor executa sobre els equips dels alumnes que tria: preparar
            una pràctica, repartir fitxers, arreglar un servei... Cada execució queda a l'historial per alumne.

            1. Obre **Accions → Llista d'accions** i tria `Publicar la web`.
            2. Prem **Simular**: Ansible diu què canviaria (`--check --diff`) sense tocar res.
            3. Prem **Executar** per a tots els alumnes. En acabar, l'acció llança sola els checks de
               verificació, que són els de la tasca *Un check que falla* del mòdul TUT1.
            4. Mira el **Seguiment** i el **Leaderboard**: els alumnes han guanyat punts.
            5. Executa `Aturar la web` per a un alumne i torna a verificar: el check torna a vermell.

            El check d'aquesta tasca comprova el mateix que veuria l'alumne a la seva màquina.
            MD, [
            [
                'nom' => 'nginx en marxa al servidor',
                'executor' => Executor::Ssh,
                'descripcio' => 'Hi ha un procés nginx en marxa al servidor de l\'alumne. Comprova-ho: `pgrep -a nginx`.',
                'info' => $this->info(
                    'veure com un check i una acció treballen junts.',
                    'l\'acció fa el canvi i el check en verifica l\'efecte de manera independent: si algú atura el servei, el check ho detecta a la ronda de la nit.',
                ),
                'codi' => <<<'SH'
                    # hosts: servidor
                    pgrep -x nginx >/dev/null || { echo "HEIMDEYE_MSG: nginx no està en marxa"; exit 1; }
                    echo "HEIMDEYE_MSG: nginx en marxa"
                    SH,
            ],
        ]);

        $tut1 = Module::where('codi', 'TUT1')->where('curs_id', $module->curs_id)->firstOrFail();
        $webChecks = Check::whereHas('task.block', fn ($q) => $q->where('modul_id', $tut1->id))
            ->whereIn('nom', ['El servidor web respon', 'La web té el nom de l\'alumne'])->pluck('id')->all();

        $this->action($module, $block, 1, 'Publicar la web', Executor::Ansible,
            'Escriu una pàgina amb el nom de l\'alumne i engega nginx. Les variables de l\'alumne arriben al playbook com a variables d\'Ansible (`nom_complet`).',
            <<<'YAML'
                - hosts: servidor
                  become: true
                  gather_facts: false
                  tasks:
                    - name: Pàgina amb el nom de l'alumne
                      ansible.builtin.copy:
                        dest: /var/www/html/index.html
                        content: "<h1>{{ TITOL }} {{ nom_complet }}</h1>\n"
                    - name: nginx en marxa
                      ansible.builtin.command: nginx
                      args:
                        creates: /run/nginx.pid
                YAML,
            [['name' => 'TITOL', 'label' => 'Títol de la pàgina', 'default' => 'Web de']],
            $webChecks,
        );

        $this->action($module, $block, 2, 'Aturar la web', Executor::Ansible,
            'Atura nginx al servidor de l\'alumne, per veure com un check passa de verd a vermell.',
            <<<'YAML'
                - hosts: servidor
                  become: true
                  gather_facts: false
                  tasks:
                    - name: Atura nginx
                      ansible.builtin.command: nginx -s quit
                      args:
                        removes: /run/nginx.pid
                YAML,
        );

        $this->action($module, $block, 3, 'Missatge de benvinguda', Executor::Ssh,
            'Escriu /etc/motd amb un missatge per a l\'alumne. És una acció ssh: bash a l\'equip de l\'alumne.',
            <<<'SH'
                # hosts: servidor
                echo "$HE_PARAM_MISSATGE, $HE_NOM_COMPLET" | sudo tee /etc/motd >/dev/null
                echo "HEIMDEYE_MSG: motd escrit"
                SH,
            [['name' => 'MISSATGE', 'label' => 'Missatge', 'default' => 'Benvingut']],
        );
    }

    private function block(Module $module, int $number, string $title): Block
    {
        return Block::updateOrCreate(
            ['modul_id' => $module->id, 'numero' => $number],
            ['titol' => $title, 'data_inici' => '2026-09-01', 'data_fi' => '2027-08-31', 'posicio' => $number],
        );
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
                    'tipus' => $def['tipus'] ?? CheckKind::Required,
                    'punts' => $def['punts'] ?? 1,
                    'es_actiu' => true,
                    'posicio' => $i + 1,
                ],
            );
            if (! $check->versions()->exists()) {
                $check->versions()->create([
                    'versio' => 1,
                    'codi' => $def['codi'],
                    'parametres' => $def['parametres'] ?? null,
                    'temps_max_seg' => 15,
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, string>>  $params
     * @param  list<int>  $verification  checks que es llancen en acabar
     */
    private function action(Module $module, Block $block, int $position, string $name, Executor $executor, string $description, string $code, array $params = [], array $verification = []): void
    {
        $action = Action::updateOrCreate(
            ['modul_id' => $module->id, 'nom' => $name],
            ['bloc_id' => $block->id, 'descripcio' => $description, 'executor' => $executor, 'posicio' => $position],
        );
        if (! $action->versions()->exists()) {
            $action->versions()->create([
                'versio' => 1,
                'codi' => $code,
                'parametres' => $params ?: null,
                'checks_verificacio' => $verification ?: null,
                'temps_max_seg' => 60,
            ]);
        }
    }

    private function info(string $objective, string $why): string
    {
        return "**Objectiu del test:** {$objective}\n\n**Justificació:** {$why}\n";
    }
}
