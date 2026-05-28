<!-- Actualizar cuando: se agreguen/cambien tokens o componentes en panel/assets/design/,
     o cambie la regla de uso del design system. Ver también 08-convenciones.md §21. -->

# 11 — Design System (identidad visual única)

La identidad visual del producto está **codificada en tokens + componentes** para
mantener la misma visual sin importar el framework del front (BS3 legacy, Alpine, futuro).

## Por qué existe

El realm `/admin` se construyó primero con estilos ad-hoc (azul `#3b82f6` sobre
near-black, radius 12px, fuente system-ui) — **off-brand** respecto al producto.
El usuario pidió un design system para no repetir ese drift. Los tokens se
**derivaron del CSS canónico actual** (`panel/css/app.css` + `style.css`), no se
inventaron — el objetivo es preservar el look existente, no rediseñar.

## Archivos

| Archivo | Qué es |
|---------|--------|
| `panel/assets/design/tokens.css` | CSS custom properties (`--ds-*`): paleta, tipografía, spacing, radios, sombras. Tema light (default) + `.theme-dark`. |
| `panel/assets/design/base.css` | Componentes con prefijo `.ds-*` que consumen los tokens. |
| `panel/assets/design/ds.js` | Comportamiento (vanilla, sin deps): estado de carga de botones vía `data-loading-text`. |

Servidos en `/assets/design/tokens.css` y `/assets/design/base.css`.

## Tokens canónicos (fuente: app.css)

| Token | Valor | Rol |
|-------|-------|-----|
| `--ds-primary` | `#545ca6` (indigo) | acción primaria (btn-primary legacy) |
| `--ds-success` | `#1ab667` | éxito |
| `--ds-info` | `#4cb6cb` (teal) | info |
| `--ds-warning` | `#fad733` | advertencia |
| `--ds-danger` | `#f05050` | peligro/destructivo |
| `--ds-text` | `#545a5f` | texto principal |
| `--ds-text-muted` | `#788188` | texto base/secundario (color body de app.css) |
| `--ds-bg` | `#f7f7f7` | fondo de página |
| `--ds-surface` | `#ffffff` | cards/modales/tablas |
| `--ds-border` | `#dae0e3` | bordes |
| `--ds-font` | `"Source Sans Pro", …` | tipografía |
| `--ds-radius` | `2px` | radio canónico (flat, era BS3) |

Tema dark (`.theme-dark`): superficies slate del producto (`#232c32` / `#3b464d` / `#5a6a7a`).

## Componentes (base.css)

`.ds-app` (body), `.ds-header` + `.ds-header__brand` + `.ds-header__nav`, `.ds-main`,
`.ds-toolbar`, `.ds-btn` (+ `--secondary` / `--danger` / `--block`), `.ds-link-btn`
(+ `--danger`), `.ds-card` (+ `--pad`), `.ds-cards` + `.ds-card-link`, `.ds-table`
(+ `__empty`) + `.ds-row-actions`, `.ds-pill` (+ `--ok` / `--muted`), `.ds-label` /
`.ds-input` / `.ds-hint` / `.ds-form-error`, `.ds-overlay` (+ `.is-open`) + `.ds-modal`
+ `.ds-modal-actions`, `.ds-auth` + `.ds-auth__card`, `.ds-toast` (+ `.is-show`), `.ds-hidden`.

### Botones

- **`.ds-btn`** — botón estándar (indigo). Variantes de color: `--secondary`, `--danger`,
  `--info` (teal), `--success`. Modificadores: `--lg`, `--rounded`, `--uppercase`, `--block`.
- **`.ds-cta`** — el botón "clásico" del producto en **UNA clase**: teal pill, mayúsculas,
  bold, grande. Reproduce exacto el chain legacy `btn btn-info btn-rounded btn-lg text-u-c font-bold`.
  Para ancho completo, sumar `.ds-block`.

  ```html
  <!-- antes (Bootstrap) -->
  <button class="btn btn-info btn-rounded btn-lg btn-block text-u-c font-bold">Ingresar</button>
  <!-- ahora (design system) -->
  <button class="ds-cta ds-block">Ingresar</button>
  ```

### Estado de carga (loading)

`ds.js` da estado de carga declarativo: poné `data-loading-text` en un botón submit y
al enviar su form se deshabilita, muestra un spinner (`::before`) y cambia el texto.

- **Form con navegación clásica (POST)**: no hay que hacer nada más.
- **Flujo fetch (no navega)**: resetear en el `.then`/`.catch` con `window.dsBtn.reset(btn)`
  (en éxito que navega, no hace falta). API: `window.dsBtn.start(btn)` / `.reset(btn)`.

  ```html
  <button class="ds-cta" data-loading-text="Procesando…">Ingresar</button>
  <script src="/assets/design/ds.js"></script>
  ```

  Primer uso: `admin/login.html` + `scripts/login.js` (resetea en credenciales inválidas).

## Regla de uso

1. Toda UI **net-new** linkea `tokens.css` + `base.css` y usa `.ds-*` / `var(--ds-*)`.
   **Nunca** estilos ad-hoc ni paletas inventadas.
2. Si falta un componente, **agregarlo a `base.css`** (no inline en la página).
3. Módulos tenant migrados: clonar el markup legacy BS3 sigue siendo válido (ya
   tiene el visual correcto); el design system es obligatorio donde NO hay markup
   legacy que clonar.

## Estado / alcance

- **Alcance**: toda la UI (decisión del usuario, 2026-05-28).
- **Primer consumidor**: realm `/admin` (`login.html` / `home.html` / `users.html` →
  tema light, indigo, Source Sans Pro, radius 2px). Verificado en browser.
- **Forma**: cimiento primero (tokens + componentes + esta doc). Sin skill por ahora.
- **Pendiente**: rollout progresivo a módulos tenant a medida que se tocan (no big-bang).

Ver `08-convenciones.md` §21 y la memoria `feedback-reuse-existing-html`.
