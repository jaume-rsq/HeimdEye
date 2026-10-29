---
titol: Inventari i variables
ordre: 50
per: [professors]
actualitzat: 2026-10-29
---

# Inventari i variables

Menú **Inventari**, amb un selector de mòdul i pestanyes:

- **Alumnes**: cada alumne amb els seus equips i variables. El botó ▶ prova la connexió. Un clic a
  un alumne mostra què rep i d'on surt cada valor.
- **Equips**: els tipus d'equip (pve, router, dns...) amb el nom, l'adreça i l'accés.
- **Variables**: el valor global, del curs i del mòdul. En negreta, el que mana.
- **Secrets**: contrasenyes i claus. El valor no es torna a mostrar mai.

## Nivells

Variables, equips i secrets tenen un nivell: global, curs o mòdul. Mana el més concret. Una
variable pròpia d'un alumne fa una excepció només per a ell.

## Plantilles

Un valor pot fer servir altres variables: `{{num_taula}}`, `{{ xarxa_aula | host(3) }}` (tercera
adreça útil de la xarxa), `{{ ip_pve | octet(4) }}`.

Els secrets mai no arriben en clar a un equip d'alumne. HeimdEye no deixa desar codi que ho faria.
