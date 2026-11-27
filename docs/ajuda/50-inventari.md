---
titol: Inventari i variables
ordre: 50
per: [professors]
actualitzat: 2026-11-28
---

# Inventari i variables

Menú **Inventari**, amb un selector de mòdul i pestanyes:

- **Alumnes**: cada alumne amb els seus equips i variables. El botó ▶ prova la connexió. Un clic a
  un alumne mostra què rep i d'on surt cada valor. Es mostra el valor de cada tipus d'equip en una taula **AlumnesxTipusEquip**.
- **Equips**: els tipus d'equip (pve, router, dns...) amb el nom, l'adreça i les credencials als tres nivells possibles: Global, Curs o Mòdul.
- **Variables**: Es mostren les variables i els tres nivells possibles.Es destaca en negreta, el valor que tindria preferencia.
- **Secrets**: contrasenyes i claus. El valor no es torna a mostrar mai. Es mostren també pels tres nivells i s'indica si es fa servir.

## Nivells

Variables, equips i secrets tenen un nivell: global, curs o mòdul. Mana el més concret. Una
variable pròpia d'un alumne fa una excepció només per a ell.

## Grups i `# hosts:`

Els **grups** són els tipus d'equip de la pestanya **Equips** (pve, router, dns...). No es
defineixen enlloc més: crear un tipus d'equip és crear un grup.

- `# hosts: pve` (executor ssh) vol dir: l'equip de tipus `pve` de cada alumne. Amb
  `# hosts: pve, router` s'executa a tots dos, un rere l'altre.
- El `hosts:` d'un play d'Ansible funciona igual: `- hosts: pve`.
- Cada tipus d'equip té un **Nom** i un **Grup Ansible** (p. ex. `router` i `vm_router`). Tots dos
  serveixen, a `# hosts:` i a Ansible. Si no hi ha cap motiu per fer-los diferents, posa'ls iguals.
- Un equip **únic** (p. ex. el servidor Zabbix) és el mateix per a tots els alumnes.
- Ansible també fa un grup per alumne (`taula01`, `taula02`...) amb tots els seus equips, i el grup
  `alumnes` amb tots els alumnes. `all` no es pot fer servir: cal dir els grups concrets.
- HeimdEye no deixa desar codi amb un grup que el mòdul no té.

## Plantilles

Un valor pot fer servir altres variables: `{{num_taula}}`, `{{ xarxa_aula | host(3) }}` (tercera
adreça útil de la xarxa), `{{ ip_pve | octet(4) }}`.

Els secrets mai no arriben en clar a un equip d'alumne. HeimdEye no deixa desar codi que ho faria.
