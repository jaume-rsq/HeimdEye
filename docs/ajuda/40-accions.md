---
titol: Accions
ordre: 40
per: [professors]
actualitzat: 2026-10-29
---

# Accions

Les accions són scripts o playbooks que llances tu per canviar els equips dels alumnes: posar una
contrasenya, donar accés a la clau de HeimdEye, instal·lar un agent... No puntuen.

- **Simular** (només Ansible) executa amb `--check --diff`: no canvia res i mostra què canviaria.
  Les simulacions no surten a l'historial, només a «Darrera simulació».
- **Executar** demana els alumnes, els tipus d'equip i els paràmetres. Amb un sol alumne, els
  paràmetres es mostren calculats per a ell.
- Cada alumne és una execució a part: si un falla, els altres continuen.
- Una acció pot tenir **checks de verificació**: en acabar l'acció per a un alumne, es comproven
  per a ell.
- Com als checks, el codi té versions i cal provar-lo abans de desar-lo. A l'historial hi ha la
  graella per alumne i el botó **Executar amb la vN** si n'hi ha amb una versió anterior.
