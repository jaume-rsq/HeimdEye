---
titol: Checks
ordre: 20
per: [professors]
actualitzat: 2026-10-29
---

# Checks

Menú **Checks → Llista de checks**. La llista s'agrupa per bloc: clica la capçalera d'un bloc per
plegar-lo, i la seva casella marca tots els checks del bloc.

## Estats d'una execució

| Estat | Vol dir |
| --- | --- |
| `pass` | Assolit. |
| `fail` | L'alumne encara no ho té. |
| `error` | El check no s'ha pogut executar (codi, variable o credencial que falta...). |
| `unavailable` | L'equip no respon. |
| `timeout` | Ha superat el temps màxim. |
| `omes` | Ara el check no s'aplica a aquest alumne. No canvia el seu estat. |

La icona de la llista resumeix la darrera execució de cada alumne: verd, tots l'han assolit amb la
versió vigent; blau, encara n'hi ha que no; vermell, ningú l'ha assolit; taronja, algun no s'ha
pogut comprovar; gris, no s'ha executat mai.

## Crear i editar

- **Nou check**: mòdul, tasca, executor (no es pot canviar després), punts, dates i el codi.
- Abans de desar un codi nou cal **Provar-lo** amb alguns alumnes. La prova no compta ni es desa.
- Canviar el codi, els paràmetres o el temps màxim crea una **versió** nova. Una versió només ha de
  corregir el codi: per comprovar una cosa nova, crea un check nou. Els assoliments que ja hi ha
  no es recalculen mai.
- Els punts, les dates i la tasca són del check: canviar-los no crea cap versió.
- Esborrar un check l'esborra del tot, amb els assoliments dels alumnes. L'avís diu què es perd.

## Verificar

- **Verificar ara** (un check) o **Verificar** (els checks marcats): s'executen ara per als alumnes
  que triïs. El resultat compta igual que el de la nit.
- A l'historial d'un check hi ha una graella per alumne. Si n'hi ha que s'han comprovat amb una
  versió anterior, surt **Verificar amb la vN** amb aquests alumnes ja marcats.

## Prorrogar

Amb checks marcats, **Prorrogar** hi posa una data nova d'«actiu fins a», de data límit o de totes
dues. Un camp buit no es canvia, i val per a tota la classe.

- Qui assoleixi el check abans de la data límit nova té el bonus de puntualitat, també si ja
  l'havia assolit després de l'antiga.
- Els checks amb «Actiu» forçat a Sí o No no fan servir «actiu fins a».
- Només es canvien els checks que pots editar.
