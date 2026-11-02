---
titol: Inventari i variables
ordre: 50
per: [professors]
actualitzat: 2026-10-29
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

## Plantilles

Un valor pot fer servir altres variables: `{{num_taula}}`, `{{ xarxa_aula | host(3) }}` (tercera
adreça útil de la xarxa), `{{ ip_pve | octet(4) }}`.

Els secrets mai no arriben en clar a un equip d'alumne. HeimdEye no deixa desar codi que ho faria.
