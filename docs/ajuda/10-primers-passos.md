---
titol: Primers passos
ordre: 10
per: [professors]
actualitzat: 2026-10-29
---

# Primers passos

HeimdEye comprova cada nit, als equips dels alumnes, les tasques de cada bloc. Cada tasca té
**checks**: comprovacions de sí o no (un ping, una ordre per SSH, un playbook d'Ansible). Quan un
check passa per primer cop, l'alumne l'ha **assolit** i en guanya els punts.

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
