---
titol: Executors i exemples
ordre: 14
per: [professors]
actualitzat: 2026-10-29
---

# Executors i exemples

L'executor diu on s'executa el codi i com hi arriba. Es tria en crear el check o l'acció i
després no es pot canviar: si cal un altre executor, és un check nou.

| Executor | On s'executa | Quins equips | Amb quin accés |
| --- | --- | --- | --- |
| `local` | Al **servidor HeimdEye**, amb bash. | Cap: el codi fa servir les adreces de les variables (`$HE_IP_ROUTER`). | L'accés per defecte del mòdul, si el codi el vol fer servir (`$HE_SSH_KEY`). |
| `ssh` | A **cada equip** de la capçalera `# hosts:`, per SSH, amb bash. | Els de l'alumne dels tipus d'equip de `# hosts:`. | El del tipus d'equip, o l'accés per defecte del mòdul. |
| `ansible` | `ansible-playbook` al servidor HeimdEye, un per alumne. | Els dels grups del `hosts:` de cada play. | El de cada tipus d'equip, o l'accés per defecte del mòdul. |

Totes tres reben les mateixes dades de l'alumne:

| Què | bash (`local`, `ssh`) | Ansible |
| --- | --- | --- |
| Variables de l'alumne | `$HE_IP_ROUTER`, `$HE_NUM_TAULA`, `$HE_USUARI`... (en majúscules) | `{{ ip_router }}`, `{{ num_taula }}`... |
| Paràmetres | `$HE_PARAM_NOM` | `{{ NOM }}` |
| Secrets | `$HE_SECRET_NOM...` (vegeu [Accés i secrets](?doc=acces-i-secrets)) | `{{ nom }}`, `{{ nom.user }}`... |

Per veure el codi amb les dades d'un alumne concret, obre el botó **Info** del check al Seguiment.

## Com es llegeix el resultat

**bash** (`local` i `ssh`):

- Sortir amb `0` és `pass` (en una acció, `ok`); qualsevol altre codi és `fail`.
- La línia `HEIMDEYE_MSG: text` és el missatge. Si no n'hi ha, és la primera línia de la sortida.
- La línia `HEIMDEYE_OMES: motiu` (només checks) vol dir que ara no s'aplica: l'estat de l'alumne
  no canvia.
- Amb `ssh`, si no es pot connectar (codi 255) és `unavailable`.

**Ansible**:

- Un check és `pass` si cap tasca falla i tots els equips responen. Un check **no pot canviar
  res**: si alguna tasca surt `changed`, és `error`. Posa `changed_when: false` a les tasques
  `command` i `shell`.
- Una acció és `ok`, `changed` o `failed`, com Ansible.
- Si el `hosts:` no coincideix amb cap equip de l'alumne, és `error`, mai `pass`.

## Executor `local`

El codi s'executa al servidor HeimdEye. És el més senzill i el que convé per a tot el que es pot
mirar des de fora: un ping, un port obert, una resolució DNS, una web.

**Check: el router de l'alumne respon al ping.**

```bash
ping -c1 -W2 "$HE_IP_ROUTER" >/dev/null || { echo "HEIMDEYE_MSG: $HE_IP_ROUTER no respon"; exit 1; }
echo "HEIMDEYE_MSG: $HE_IP_ROUTER respon al ping"
```

**Check: el DNS de l'alumne resol el seu domini.**

```bash
ip=$(dig +short "@$HE_IP_DNS" "www.$HE_NOM_DOMINI" A)
[ -n "$ip" ] || { echo "HEIMDEYE_MSG: www.$HE_NOM_DOMINI no resol"; exit 1; }
echo "HEIMDEYE_MSG: www.$HE_NOM_DOMINI és $ip"
```

Un script `local` també pot entrar per SSH ell mateix, amb l'accés per defecte del mòdul. Serveix
per als equips que no tenen bash, com un MikroTik:

```bash
out=$(ssh $HE_SSH_OPTS -i "$HE_SSH_KEY" "$HE_SSH_USER@$HE_IP_ROUTER" ':put [/system identity get name]') \
  || { echo "HEIMDEYE_MSG: no puc entrar al router"; exit 1; }
echo "HEIMDEYE_MSG: el router es diu $out"
```

`$HE_SSH_OPTS` porta les opcions perquè no es desin les claus dels equips (els alumnes reinstal·len
les VM sovint).

## Executor `ssh`

El mateix bash, però executat **dins de l'equip de l'alumne**, amb l'usuari de l'accés. La
capçalera `# hosts:` és obligatòria i diu a quins tipus d'equip va (separats per comes). Serveix per
mirar l'estat de dins: un servei, un fitxer, un paquet.

**Check: el pve de l'alumne respon (el ping fet des de dins).**

```bash
# hosts: pve
ping -c1 -W2 "$HE_IP_ROUTER" >/dev/null || { echo "HEIMDEYE_MSG: el pve no arriba al router"; exit 1; }
echo "HEIMDEYE_MSG: el pve $(hostname) arriba al router"
```

**Check: hi ha almenys una VM creada.** Amb un paràmetre, per reutilitzar-lo:

```bash
# hosts: pve
n=$(sudo -n qm list | tail -n +2 | wc -l)
[ "$n" -ge "$HE_PARAM_MIN_VMS" ] || { echo "HEIMDEYE_MSG: hi ha $n VMs, en calen $HE_PARAM_MIN_VMS"; exit 1; }
echo "HEIMDEYE_MSG: $n VMs"
```

Paràmetres: `[{"name": "MIN_VMS", "label": "Mínim de VMs", "default": 1}]`. Un check necessita el
`default` de tots els paràmetres, perquè a la nit s'executa sol.

**Acció: deixar un missatge a l'alumne.**

```bash
# hosts: pve
echo "$HE_PARAM_MISSATGE, $HE_NOM_COMPLET" | sudo tee /etc/motd >/dev/null
echo "HEIMDEYE_MSG: motd escrit"
```

Amb diversos equips (`# hosts: pve, router`), el codi s'executa a cada un, un rere l'altre. El
resultat és `pass` només si passa a tots. Els equips Windows (WinRM) no serveixen per a `ssh`:
fes servir Ansible.

## Executor `ansible`

Un playbook que HeimdEye executa al seu servidor amb un inventari fet per a aquell alumne: només
porta els seus equips dels grups que el playbook demana. Va bé per a les accions (és idempotent i
es pot **Simular**) i per als equips Windows. El `hosts:` de cada play és obligatori i no pot ser
`all`.

**Check: el pve respon a Ansible.**

```yaml
- hosts: pve
  gather_facts: false
  tasks:
    - name: Ping
      ansible.builtin.ping:
```

`ansible.builtin.ping` no és un ping de xarxa: comprova que Ansible hi entra i que hi ha Python.
Per a un equip sense Python (un MikroTik), fes servir `ansible.builtin.raw`. Per a un Windows,
`ansible.windows.win_ping`:

```yaml
- hosts: estacio_windows
  gather_facts: false
  tasks:
    - name: Ping WinRM
      ansible.windows.win_ping:
```

**Check: el servei DNS està en marxa** (`changed_when: false`, perquè un check no canvia res):

```yaml
- hosts: vm_dns_bind
  gather_facts: false
  tasks:
    - name: named actiu
      ansible.builtin.command: systemctl is-active named
      changed_when: false
```

**Acció: instal·lar la clau de HeimdEye a un altre usuari.** La clau pública d'un secret es pot
fer servir sense problema, perquè no és secreta:

```yaml
- hosts: vm_router
  become: true
  gather_facts: false
  tasks:
    - name: Carpeta .ssh
      ansible.builtin.file:
        path: "/home/{{ USUARI }}/.ssh"
        state: directory
        owner: "{{ USUARI }}"
        mode: "0700"
    - name: Clau de HeimdEye
      ansible.builtin.lineinfile:
        path: "/home/{{ USUARI }}/.ssh/authorized_keys"
        line: "{{ ssh_heimdeye.pubkey }}"
        create: true
        owner: "{{ USUARI }}"
        mode: "0600"
```

(Al servidor només hi ha els mòduls d'`ansible-core` i la col·lecció `ansible.windows`. Per fer
servir `ansible.posix.authorized_key` caldria instal·lar `ansible.posix`.)

Paràmetres: `[{"name": "USUARI", "label": "Usuari", "default": "{{usuari}}"}]`. Abans
d'executar-la, **Simula-la**: diu què canviaria sense tocar res.

## Quin executor triar

- Es pot mirar des de fora (ping, port, DNS, web)? **`local`**.
- Cal mirar dins d'un Linux i n'hi ha prou amb una ordre? **`ssh`**.
- És un Windows, una acció que canvia coses o cal un mòdul d'Ansible? **`ansible`**.
