---
titol: Com s'escriu l'ajuda
ordre: 90
per: [professors]
actualitzat: 2026-10-29
---

# Com s'escriu l'ajuda

Cada pàgina és un fitxer Markdown a `docs/ajuda` del repositori. Es llegeixen en obrir l'ajuda:
per afegir-ne o canviar-ne una, n'hi ha prou d'editar el fitxer i desplegar-lo. No cal tocar codi.

```markdown
---
titol: Checks
ordre: 20
per: [professors]
actualitzat: 2026-10-29
---

# Checks

Text en Markdown, amb taules i tot.
```

- `titol`: el nom al menú d'ajuda.
- `ordre`: la posició (de més petit a més gran).
- `per`: `professors`, `alumnes` o `tots`. Els alumnes veuen les seves a `/alumne/ajuda`.
- `actualitzat`: la data del darrer canvi. Durant dues setmanes la pàgina surt marcada amb
  **nou**, i el menú Ajuda mostra quantes n'hi ha.
- El nom del fitxer fa d'adreça: `20-checks.md` és `?doc=checks`. El número només serveix per
  ordenar els fitxers a la carpeta.

Quan canviï alguna cosa que afecti els usuaris, afegeix-ho també a [Novetats](?doc=novetats).
