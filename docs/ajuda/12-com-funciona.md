---
titol: Com funciona
ordre: 12
per: [professors]
actualitzat: 2026-10-29
---

# Com funciona

Les Accions permeten fer canvis en equips dels alumnes, per configurar alguna cosa, o per instal·lar software.

Els Checks no instal·len res als equips dels alumnes. S'executa un
codi (un script de bash o un playbook d'Ansible) **per a cada alumne**, i es revisa el resultat. Els check assolits donen punts als alumnes i permeten fer un ranking que els pot motivar a acabar les tasques a temps. 

El funcionament intern de checks i accions és molt semblant, ja que tots dos executen codi remotament i en desen un historial amb el resultat.

Per executar codi hi ha dos eines: Scripts **bash** o playbooks **Ansible**. L'accés als equips remots es fa amb credencials que es desen a la BBDD encriptades amb la clau de l'app. Cal disposar d'un accés als equips amb un usuari privilegiat.
   Linux -> Accés per SSH. Usuari amb permissos de sudo.
   Windows -> Accés per SSH o WinRM. Usuari del grup administradors.

Per accedir als equips es poden fer servir contrassenyes o claus SSH. 

Els equips, variables relacionades i secrets es gestionen a l'inventari. [Inventari](?doc=inventari).

## Què necessita un check o una acció

Tot check i tota acció necessita tres coses:

| Peça | Què és | On es tria |
| --- | --- | --- |
| **Codi** | El script o el playbook. Fa servir les variables de l'alumne. | A l'editor del check o de l'acció. |
| **Executor** | **On** i **com** s'executa el codi: `local`, `ssh` o `ansible`. | En crear-lo. Després no es pot canviar. |
| **Accés** | **Amb quin usuari i quina clau** (o contrasenya) HeimdEye entra a l'equip. | A Inventari: l'accés per defecte del mòdul, o el d'un tipus d'equip. |

Si falta l'accés o l'equip no el té configurat, el check no es pot executar (`error`) o l'equip
no respon (`unavailable`). No és culpa de l'alumne i no li treu punts.

Els check i les accions es poden provar mentre s'estan definint. Aquestes proves no queden enregistrades i permeten validar el seu funcionament abans de desar-los.

Es poden definir paràmetres específics d'un check o action que es calculen abans e'executar.

## Què passa en una execució

```
nit (02:00), Verificar o Executar
        │
        ▼
  un treball a la cua per alumne ──▶ HeimdEye prepara el context de l'alumne:
                                       variables (ip_router, num_taula...),
                                       paràmetres i els secrets que el codi esmenta
                                              │
                                              ▼
                                       executor: local · ssh · ansible
                                              │
                                              ▼
                                       resultat: pass / fail / error / unavailable / timeout / omes
                                       sortida desada (amb els secrets com a ********)
```

1. **Un treball per alumne.** Cada alumne és una execució a part. Si un equip no respon, els altres
   alumnes continuen. Els workers en fan uns quants alhora (4 a la instal·lació de referència).

2. **El context.** HeimdEye calcula les variables de l'alumne (vegeu
   [Equips, secrets i variables](?doc=inventari)), els paràmetres del codi i els secrets que el codi fa
   servir. 

3. **L'executor** executa el codi. Vegeu [Executors i exemples](?doc=executors).

4. **El resultat.** En bash, sortir amb `0` vol dir assolit (`pass`); qualsevol altre codi és
   `fail`. La línia `HEIMDEYE_MSG: ...` és el missatge que veuen el professor i l'alumne. Amb
   Ansible, `pass` vol dir que cap tasca ha fallat.

5. **El registre.** Es desa la sortida, la versió del codi i la durada. Si és la primera vegada
   que passa, l'alumne l'ha assolit des d'aquell dia.

## Els equips

HeimdEye no té una llista de màquines: té **tipus d'equip** (pve, router, dns...), i cada alumne
en té un de cada. El tipus diu el nom i l'adreça amb plantilles, per exemple `{{ip_router}}`, i
per a cada alumne es calcula la seva. Un tipus pot ser **únic** (un sol equip per a tothom, com un
servidor Zabbix). Això requereix organitzar la xarxa i els equips dels alumnes de bon començament. HeimdEye intenta ser flexible i permet que qualsevol variable es sobreescrigui per un alumne en concret.

El mateix codi indica sobre quin tipus d'equip s'executarà: 
- Executor `ssh` -> Capçalera `# hosts: ` 
- Executor Ansible -> Línia `hosts:` del play
- Executor `local` -> El codi s'executa al servidor HeimdEye i pot fer servir variables (`ping $HE_IP_ROUTER`).

## L'accés

L'accés es fa amb credencials anomenades **secret** que poden ser de tipus *clau SSH* o *usuari i contrasenya*. Cada mòdul en té un per defecte (Inventari → Equips → **Accés per defecte**) i cada tipus d'equip en pot fer servir un altre (els Windows, per exemple, amb usuari i contrasenya per WinRM). Perquè funcioni, l'equip de l'alumne ha de tenir aquest usuari i la clau pública: vegeu [Preparar els equips](?doc=preparar-equips).

La clau privada i les contrasenyes **no surten mai del servidor HeimdEye**. Vegeu
[Accés i secrets](?doc=acces-i-secrets).

## Com començar un mòdul nou

1. **Prepara els equips** dels alumnes amb l'usuari i la clau de HeimdEye.
2. **Desa les credencials dels equips** a l'inventari per poder accedir.
3. A **Inventari → Equips**, crea els tipus d'equip amb l'adreça (per exemple `{{ip_router}}`...) i tria
   l'**accés per defecte**.
4. A **Inventari → Variables**, defineix les variables que fan servir els equips.
5. A **Inventari → Alumnes**, prem ▶ per **provar la connexió** amb cada equip.
6. Escriu el primer check (un ping, per exemple), **prova'l** amb un parell d'alumnes i desa'l.
