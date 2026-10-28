# Tutorial de HeimdEye

> Generat amb `php artisan heimdeye:tutorial` a partir del curs Tutorial (`TutorialSeeder`).
> És el mateix contingut que es veu a l'app: no l'editis a mà.

El tutorial és un curs com qualsevol altre: 2 mòduls, amb blocs, tasques, checks i accions, i 4 alumnes ficticis (Ada Lovelace, Alan Turing, Grace Hopper, Linus Torvalds), cadascun amb un servidor.

## TUT1 · Primers passos amb els checks

**Equips:** `servidor` (servidor{{num_taula}} → {{ip_servidor}}). **Variables de mòdul:** `ip_servidor = alumne{{num_taula}}`.

### Bloc 1. Com està organitzat HeimdEye

#### Tasca 1. Curs, mòduls, blocs, tasques i checks

Aquest tutorial és un curs de HeimdEye com qualsevol altre. El **curs** (Tutorial) té un **cicle**,
i el cicle té **mòduls**: aquest (TUT1, checks) i el següent (TUT2, accions). Cada mòdul es divideix
en **blocs** (més o menys una setmana de classe), cada bloc en **tasques** (el que fa l'alumne) i
cada tasca en **checks**: comprovacions binàries (passa o no passa) que HeimdEye executa cada nit
sobre els equips de cada alumne.

Els 4 alumnes són ficticis (Ada, Alan, Grace i Linus) i cadascun té un **servidor** (un contenidor
Ubuntu). Ets el seu professor: obre **Checks → Llista de checks**, tria el primer check d'aquesta
tasca i prem **Verificar ara** (▶). El resultat surt a l'historial i al **Seguiment**.

**Check: El servidor de l'alumne respon** · executor `local` · 1 punt

HeimdEye fa ping al servidor de l'alumne.

**Objectiu del test:** veure el check més senzill: un script de bash que s'executa al servidor HeimdEye (executor **local**) i passa si acaba amb codi 0.

**Justificació:** cada alumne té les seves variables, que arriben a l'script com a `HE_<VARIABLE>`. Aquí `HE_IP_SERVIDOR` és la variable de mòdul `ip_servidor = alumne{{num_taula}}`, i per a l'Ada val `alumne01`. La línia `HEIMDEYE_MSG:` és el missatge que veuen el professor i l'alumne.

```bash
ping -c1 -W2 "$HE_IP_SERVIDOR" >/dev/null || { echo "HEIMDEYE_MSG: $HE_IP_SERVIDOR no respon"; exit 1; }
echo "HEIMDEYE_MSG: $HE_IP_SERVIDOR respon al ping"
```

**Check: HeimdEye entra per SSH al servidor** · executor `ssh` · 1 punt

HeimdEye entra al servidor de l'alumne per SSH amb l'accés del mòdul i hi executa l'script.

**Objectiu del test:** veure l'executor **ssh**: el mateix bash, però executat a l'equip de l'alumne.

**Justificació:** la capçalera `# hosts: servidor` diu a quins tipus d'equip s'executa (Inventari → Equips del mòdul). L'usuari i la clau són els de l'accés del mòdul (un secret de tipus clau SSH); la clau no surt mai del servidor HeimdEye.

```bash
# hosts: servidor
echo "HEIMDEYE_MSG: dins de $(hostname) com a $(whoami)"
```

#### Tasca 2. Un check que falla

Un check que falla diu a l'alumne què li falta. Aquest comprova que el servidor publica una web
amb el nom de l'alumne. Ara falla per a tothom: a la tasca del mòdul TUT2 la publicaràs amb una
**acció**, i el check passarà a verd sol.

Fixa't en els **paràmetres**: el check `La web té el nom de l'alumne` en té un (`TITOL`), amb un
valor per defecte, que arriba a l'script com a `HE_PARAM_TITOL`.

**Check: El servidor web respon** · executor `local` · 1 punt

El port 80 del servidor de l'alumne respon. Comprova-ho: `curl -I http://alumneNN`.

**Objectiu del test:** comprovar un servei des de fora, com ho faria un usuari.

**Justificació:** mirar la configuració no garanteix que el servei funcioni: el check obre una connexió de veritat.

```bash
curl -sf -m 3 -o /dev/null "http://$HE_IP_SERVIDOR/" || { echo "HEIMDEYE_MSG: la web de $HE_IP_SERVIDOR no respon"; exit 1; }
echo "HEIMDEYE_MSG: la web respon"
```

**Check: La web té el nom de l'alumne** · executor `local` · 2 punts

La pàgina principal diu «Web de <nom de l'alumne>».

**Objectiu del test:** veure un check amb paràmetres i amb el nom de l'alumne (`HE_NOM_COMPLET`).

**Justificació:** els paràmetres permeten reutilitzar el mateix codi canviant només un valor, i el valor per defecte és el que s'usa a la ronda de cada nit.

- Paràmetre `TITOL` (Text que ha de sortir abans del nom), per defecte `Web de`

```bash
esperat="$HE_PARAM_TITOL $HE_NOM_COMPLET"
curl -sf -m 3 "http://$HE_IP_SERVIDOR/" | grep -qF "$esperat" \
  || { echo "HEIMDEYE_MSG: la web no diu «$esperat»"; exit 1; }
echo "HEIMDEYE_MSG: la web diu «$esperat»"
```

### Bloc 2. Crea el teu check

#### Tasca 1. Missatge de benvinguda

Ara et toca a tu. Crea un check en aquesta tasca (**Checks → Nou check**) que comprovi que el fitxer
`/etc/motd` del servidor de l'alumne conté la paraula `Benvingut`. Pista: executor **ssh**,
capçalera `# hosts: servidor` i `grep -q Benvingut /etc/motd`.

Abans de desar-lo, **prova'l** amb un o dos alumnes (botó Provar): el resultat surt a sota del
formulari i no queda enlloc. Després fes servir l'acció `Missatge de benvinguda` del mòdul TUT2
per a la meitat dels alumnes, i torna a verificar: uns en verd i els altres en vermell.

Si t'encalles, el check opcional d'aquesta tasca és una solució: duplica'l (botó Duplicar) i
canvia-hi el que vulguis.

**Check: Solució: el motd dona la benvinguda** · executor `ssh` · 1 punt · opcional

`/etc/motd` conté «Benvingut».

**Objectiu del test:** tenir una solució de referència per duplicar.

**Justificació:** els checks opcionals no compten per al mínim de la tasca, però sumen punts.

```bash
# hosts: servidor
grep -q Benvingut /etc/motd 2>/dev/null || { echo "HEIMDEYE_MSG: /etc/motd no dona la benvinguda"; exit 1; }
echo "HEIMDEYE_MSG: $(head -1 /etc/motd)"
```

## TUT2 · Accions: configurar els equips dels alumnes

**Equips:** `servidor` (servidor{{num_taula}} → {{ip_servidor}}). **Variables de mòdul:** `ip_servidor = alumne{{num_taula}}`.

### Bloc 1. Accions del professor

#### Tasca 1. Publicar la web dels alumnes

Les **accions** són scripts que el professor executa sobre els equips dels alumnes que tria: preparar
una pràctica, repartir fitxers, arreglar un servei... Cada execució queda a l'historial per alumne.

1. Obre **Accions → Llista d'accions** i tria `Publicar la web`.
2. Prem **Simular**: Ansible diu què canviaria (`--check --diff`) sense tocar res.
3. Prem **Executar** per a tots els alumnes. En acabar, l'acció llança sola els checks de
   verificació, que són els de la tasca *Un check que falla* del mòdul TUT1.
4. Mira el **Seguiment** i el **Leaderboard**: els alumnes han guanyat punts.
5. Executa `Aturar la web` per a un alumne i torna a verificar: el check torna a vermell.

El check d'aquesta tasca comprova el mateix que veuria l'alumne a la seva màquina.

**Check: nginx en marxa al servidor** · executor `ssh` · 1 punt

Hi ha un procés nginx en marxa al servidor de l'alumne. Comprova-ho: `pgrep -a nginx`.

**Objectiu del test:** veure com un check i una acció treballen junts.

**Justificació:** l'acció fa el canvi i el check en verifica l'efecte de manera independent: si algú atura el servei, el check ho detecta a la ronda de la nit.

```bash
# hosts: servidor
pgrep -x nginx >/dev/null || { echo "HEIMDEYE_MSG: nginx no està en marxa"; exit 1; }
echo "HEIMDEYE_MSG: nginx en marxa"
```

#### Acció: Publicar la web · executor `ansible`

Escriu una pàgina amb el nom de l'alumne i engega nginx. Les variables de l'alumne arriben al playbook com a variables d'Ansible (`nom_complet`).

- Paràmetre `TITOL` (Títol de la pàgina), per defecte `Web de`
- Verifica amb: «El servidor web respon», «La web té el nom de l'alumne»

```yaml
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
```

#### Acció: Aturar la web · executor `ansible`

Atura nginx al servidor de l'alumne, per veure com un check passa de verd a vermell.

```yaml
- hosts: servidor
  become: true
  gather_facts: false
  tasks:
    - name: Atura nginx
      ansible.builtin.command: nginx -s quit
      args:
        removes: /run/nginx.pid
```

#### Acció: Missatge de benvinguda · executor `ssh`

Escriu /etc/motd amb un missatge per a l'alumne. És una acció ssh: bash a l'equip de l'alumne.

- Paràmetre `MISSATGE` (Missatge), per defecte `Benvingut`

```bash
# hosts: servidor
echo "$HE_PARAM_MISSATGE, $HE_NOM_COMPLET" | sudo tee /etc/motd >/dev/null
echo "HEIMDEYE_MSG: motd escrit"
```
