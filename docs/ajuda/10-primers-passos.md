---
titol: Primers passos
ordre: 10
per: [professors]
actualitzat: 2026-10-29
---

# Primers passos

HeimdEye te una doble funció, d'una banda intenta simplificar l'administració remota dels equips d'un aula, definint **Accions** a fer i desant l'historial amb les execucions i els resultats. D'altra banda permet monitoritzar la feina dels alumnes amb **Checks** periòdics. 

Aquesta feina s'organitza amb aquestes categories:
  Curs Escolar -> Mòdul (matèria) -> Blocs (projectes) -> Tasques -> Checks

HeimdEye comprova cada nit, als equips dels alumnes, els **Checks** de cada tasca. Un check és una verificació binaria. Quan un check passa per primer cop, l'alumne l'ha assolit i en guanya els punts.S'enregistra l'històric de comprovacions i resultats.

Els check tenen un periode de validesa, i poden desactivar-se manualment. Cada check assigna uns punts en funció de la complexitat, i hi ha un bonus per assolir-los dins el termini.

L'objectiu de l'eina no és proporcionar una nota numérica. Simplement es vol proporcionar una eina a docents i alumnes que permeti avaluar l'evolució de les tasques assignades.

Per entendre com funcionen les **Accions** i els **Checks**, llegeix [Com funciona](?doc=com-funciona).

## El menú

| Entrada | Per a què serveix |
| --- | --- |
| **Leaderboard** | Classificació d'un mòdul amb els noms reals. La pública (`/leaderboard`) només mostra àlies. |
| **Seguiment** | Detall d'un alumne: cada check amb l'estat, la darrera execució i els punts. Hi pots verificar, corregir i prorrogar. |
| **Checks** | Llista, nou check i històric d'execucions. |
| **Accions** | Scripts que llances tu per canviar els equips dels alumnes (no puntuen). |
| **Inventari** | Equips, variables, secrets i alumnes de cada mòdul. |
| **Administració** | Usuaris i rols, cursos, cicles, mòduls i blocs. |
| **Ajuda** | Aquestes pàgines i un enllaç a la **demo** pública, on pots provar-ho tot sense tocar res de veritat. |

## Què veu cada rol

- **Administrador**: tot, inclosos els usuaris.
- **Professor**: els mòduls que imparteix. Només pot editar, esborrar o prorrogar els checks
  d'aquests mòduls.
- **Alumne**: només el seu seguiment, de lectura, a `/alumne`.

## La nit

Cada nit s'executen tots els checks actius per a tots els alumnes. Un check és **actiu** si
«Actiu» és Sí, o si és buit i avui és entre «actiu des de» i «actiu fins a». A la nit, si un equip
no respon, es torna a provar un cop al cap d'una estona.
