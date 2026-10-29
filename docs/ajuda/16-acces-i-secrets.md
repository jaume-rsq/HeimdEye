---
titol: Accés i secrets
ordre: 16
per: [professors]
actualitzat: 2026-10-29
---

# Accés i secrets

Els **secrets** són les contrasenyes i les claus que HeimdEye necessita. Es desen xifrats a la base
de dades (Inventari → **Secrets**) i, un cop desats, el valor no es torna a mostrar mai. No van
mai al repositori.

## Tipus de secret

| Tipus | Què guarda | Per a què |
| --- | --- | --- |
| **Valor** | Un text (un token, una contrasenya sola). | Una API, una dada que el codi necessita. |
| **Usuari i contrasenya** | Usuari i contrasenya. | Accés a Windows (WinRM) o a una API. |
| **Clau SSH** | Usuari, clau privada (o la ruta del fitxer al servidor) i clau pública. | Accés per SSH als Linux i MikroTik. |

Com les variables, un secret té un **nivell** (global, curs o mòdul), i mana el més concret.

## L'accés

Un secret de clau SSH o d'usuari i contrasenya pot fer d'**accés**: és l'usuari amb què HeimdEye
entra als equips.

- Cada mòdul té un **accés per defecte** (Inventari → Equips → **Accés per defecte**). El fan servir
  tots els equips que no en diuen un altre i els scripts `local`.
- Un **tipus d'equip** pot tenir un accés propi. Per exemple, els Windows amb un usuari i
  contrasenya, i la resta amb la clau SSH.
- Amb Ansible, la contrasenya d'un accés d'usuari i contrasenya serveix també per al `sudo`
  (`become: true`). Amb una clau SSH, l'usuari ha de poder fer `sudo` sense contrasenya.

A **Inventari → Alumnes**, el botó ▶ prova la connexió de cada equip amb el seu accés.

## Com fa servir un secret el codi

El codi només rep els secrets que esmenta, i a la sortida desada surten com a `********`. El nom
del secret va en majúscules a bash i tal com és a Ansible.

| Tipus | bash (`local`, `ssh`) | Ansible |
| --- | --- | --- |
| Valor | `$HE_SECRET_NOM` | `{{ nom }}` |
| Usuari i contrasenya | `$HE_SECRET_NOM_USER`, `$HE_SECRET_NOM_PASSWORD` | `{{ nom.user }}`, `{{ nom.password }}` |
| Clau SSH | `$HE_SECRET_NOM_USER`, `$HE_SECRET_NOM_KEY_FILE`, `$HE_SECRET_NOM_PUBKEY` | `{{ nom.user }}`, `{{ nom.key_file }}`, `{{ nom.pubkey }}` |

Un script `local` rep a més l'accés per defecte del mòdul: si és una clau SSH, `$HE_SSH_USER` i
`$HE_SSH_KEY` (la ruta de la clau); si és usuari i contrasenya, `$HE_CRED_USER` i
`$HE_CRED_PASSWORD`.

**Exemple (`local`): l'API de Zabbix respon amb el secret `api_zabbix`.**

```bash
curl -sf -m 5 -u "$HE_SECRET_API_ZABBIX_USER:$HE_SECRET_API_ZABBIX_PASSWORD" \
  "http://$HE_IP_ZABBIX/zabbix/api_jsonrpc.php" -o /dev/null \
  || { echo "HEIMDEYE_MSG: l'API no respon"; exit 1; }
echo "HEIMDEYE_MSG: l'API respon"
```

## La regla: cap secret a l'equip de l'alumne

L'alumne és root a les seves màquines. Tot el que hi arribi en clar ho pot llegir. Per això
HeimdEye **no deixa desar ni executar** codi que hi faria arribar un secret:

- **`local`**: es pot fer servir qualsevol secret, perquè el codi s'executa al servidor.
- **`ssh`**: el codi s'executa a l'equip de l'alumne, així que només pot fer servir `_USER` i
  `_PUBKEY` (no són secrets). La clau privada de l'accés es queda al servidor: ssh la fa servir
  per entrar, però no arriba a l'equip.
- **Ansible**: el valor, la contrasenya o la clau només dins una expressió amb `password_hash`
  (a l'equip hi arriba el hash) o en una tasca amb `delegate_to: localhost`.

**Exemple (Ansible): posar una contrasenya sense enviar-la.**

```yaml
- hosts: pve
  become: true
  gather_facts: false
  tasks:
    - name: Contrasenya de l'usuari
      ansible.builtin.user:
        name: "{{ USUARI }}"
        password: "{{ contrasenya_alumnes | password_hash('sha512') }}"
```

La comprovació és de text i, si dubta, rebutja: si el codi no es pot desar, el missatge diu per què.
