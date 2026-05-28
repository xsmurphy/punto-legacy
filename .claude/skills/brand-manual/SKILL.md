---
name: brand-manual
description: Manual de marca de Punto — colores, tipografía y clases existentes (Bootstrap 3 + app.css) para construir UI consistente. Invocar SIEMPRE al crear o tocar UI/frontend del proyecto (HTML, vistas, formularios, botones, estilos), o cuando se pregunte por colores, botones, inputs, paleta, look & feel o "cómo se ve X". Asegura reutilizar lo existente sin inventar estilos ni rediseñar.
trigger: /brand-manual
---

# brand-manual — Identidad visual de Punto

Fuente única: **[context/11-design-system.md](../../../context/11-design-system.md)**. Leelo
antes de construir UI no trivial. Este skill es el atajo + las reglas duras.

## Regla de oro

Al crear o tocar UI, **reutilizá las clases y colores existentes** (Bootstrap 3 +
`panel/css/app.css`). **Nunca** inventes CSS ad-hoc, paletas nuevas ni rediseñes.
Si un patrón falta, agregalo al manual (`context/11-design-system.md`), no inline.

## Antes de escribir UI

1. Leé `context/11-design-system.md` (paleta, tipografía, componentes).
2. Buscá si el componente ya existe en el legacy (`panel/`, `app/`) y cloná su markup.
3. Usá las recetas de abajo. Frontend nuevo = **Bootstrap 3 + jQuery** (`08-convenciones.md §11`).

## Quick reference (clases reales)

**Colores** (rol → hex → clase): primario `#545ca6` `btn-primary` · éxito `#1ab667`
`btn-success` · info/teal `#4cb6cb` `btn-info` (CTA clásico) · warning `#fad733`
`btn-warning` · peligro `#f05050` `btn-danger` · texto `#788188` · bg `#f7f7f7`.

**Botón CTA principal** (teal pill, mayúsculas):
```html
<button class="btn btn-info btn-rounded btn-lg btn-block text-u-c font-bold">Ingresar</button>
```

**Input underline** (auth): `form-control input-lg no-border no-bg b-b`
**Input en caja** (forms internos): `form-control`
**Label:** `<label class="block font-bold text-u-c text-xs">…</label>`

**Tipografía:** Source Sans Pro, 14px. Mayúsculas `text-u-c`, bold `font-bold`, chico `text-xs`.
**Radio:** default 2px · pill `btn-rounded` · cajas `r-3x`. **Spacing:** `m-t/m-b/...-md`, `wrapper`, `no-padder`.
**Tablas:** BS3 `table` / `ncmDataTables`. **Modales:** infra `adm()` / `#modalTiny` (no a mano).

## Qué NO hacer

- No introducir tokens `--ds-*`, tailwind, ni un CSS framework nuevo.
- No cambiar colores/paleta del proyecto.
- No rediseñar pantallas existentes salvo pedido explícito.
