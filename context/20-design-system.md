<!-- REGLA: Design system canónico de Punto POS. Lectura OBLIGATORIA antes de
     crear o modificar componentes visuales en frontend. Referenciá la sección
     exacta en el brief del sub-agente. Este doc es autocontenido: no requiere
     acceso al repo para bootstrapear el mismo look en otra app (ver §9). -->

# 20 — Design System de Punto

> Doc vivo. Referencia canónica de patrones UI, tokens reales (extraídos del
> código) y guía de bootstrap para apps externas que compartan la UI/UX de
> Punto. Este NO es un rediseño — documenta lo que YA existe en `frontend/`.
> Lee `context/14-ui-conventions.md` para las reglas operativas base de
> shadcn (tipografía, tamaños, formatos); este doc las cita, no las duplica,
> y agrega tokens completos, componentes canon, patrones POS y el changelog
> de decisiones de diseño.

## Tabla de contenidos

- [1. Identidad y principios](#1-identidad-y-principios)
- [2. Tokens](#2-tokens)
- [3. Color](#3-color)
- [4. Componentes canon](#4-componentes-canon)
- [5. Prohibiciones](#5-prohibiciones)
- [6. Patrones de página](#6-patrones-de-página)
- [7. POS específico](#7-pos-específico)
- [8. Formatos](#8-formatos)
- [9. Guía para apps externas](#9-guía-para-apps-externas)
- [10. Changelog de decisiones](#10-changelog-de-decisiones)

---

## 1. Identidad y principios

Punto es un POS + panel de administración multi-vertical (gastronomía, retail,
servicio técnico). La UI es **shadcn/ui puro** (style `radix-rhea`, base color
`neutral`) con un único override de marca: verde Punto `#01D7A1` reservado a
acentos (nunca `--primary`).

**Principios:**

1. **Shadcn-first.** No se reinventan primitives. Prohibido `<table>`,
   `<button>`, `<input>`, `<label>` nativos cuando existe el equivalente
   shadcn. Ver §5.
2. **Densidad operativa.** El panel opera con teclado + mouse en densidad
   media (default de shadcn, sin comprimir ni agrandar). El POS opera con
   dedo en tablet — touch targets ≥ 44px — y 100% por teclado en el flujo de
   caja de alto volumen (autofocus, shortcuts, sin depender del mouse).
3. **Diseñar desde cero, no copiar legacy.** El panel/POS legacy (BS3,
   `context/11-design-system.md`) es referencia FUNCIONAL — qué campos, qué
   flujos, qué edge cases — nunca visual. Copiar su estructura con primitives
   shadcn generó 3 iteraciones de restyle en el modal de transacciones
   (2026-06-24); la lección quedó como regla dura.
4. **Sin emojis en UI.** Ni en copy, ni en toasts, ni en placeholders.
5. **es-PY neutro.** Acentuación correcta, vertical-neutral ("usuario" no
   "vendedor"/"mozo"; "comprobante" no siempre "factura").
6. **Multi-vertical.** Ningún string ni componente asume un rubro específico.

---

## 2. Tokens

Fuente: `frontend/app/globals.css` (Tailwind v4, `@theme inline` — no hay
`tailwind.config.*`, todo vive en CSS). Sistema de color: **OKLCH**. Style de
`components.json`: `radix-rhea`, `baseColor: neutral`, `cssVariables: true`.

### Tipografía

| Token | Valor | Uso |
|---|---|---|
| `--font-sans` | `var(--font-inter)` (declarado en CSS, fallback en `app/layout.tsx`) | Fuente base canónica Inter |
| Fuente real cargada | `Inter` (next/font/google, var `--font-inter`) + `JetBrains Mono` (var `--font-jetbrains-mono`) | `app/layout.tsx` — el `<html>` aplica `inter.variable` y `body` usa `font-sans`. |
| `--font-mono` | `JetBrains Mono, monospace` | Código, `kbd`, tabular contexts |
| `--font-serif` | `Source Serif 4, serif` | Sin uso activo detectado |
| `h1` | `text-2xl font-semibold`, `letter-spacing: -0.04em` global en `<h1>` | Título de página |
| `h2` | `text-xl font-semibold`, `letter-spacing: -0.03em` global | Título de sección |
| `h3` | `text-base font-semibold`, `letter-spacing: -0.02em` global | Subsección (§14 usa `tracking-tight` explícito) |
| Texto principal | `text-sm` sin override | Body default |
| Texto secundario | `text-sm text-muted-foreground` | Descripciones |
| Caption | `text-xs text-muted-foreground` | Timestamps, hints |
| Números | `tabular-nums` siempre | Montos, fechas, cantidades — evita jitter |
| `font-feature-settings` | `"cv11", "ss01"` en `<html>` | Variantes OpenType globales |

### Radius

| Token | Light | Dark |
|---|---|---|
| `--radius` | `0.45rem` | `0.7rem` |
| `--radius-sm` | `0.375rem` | — |
| `--radius-md` | `0.5rem` | — |
| `--radius-lg` | `0.75rem` | — |
| `--radius-xl` | `1rem` | — |
| `--radius-2xl` | `1.5rem` | — |
| `--radius-3xl` | `calc(var(--radius) * 2.2)` | — |
| `--radius-4xl` | `calc(var(--radius) * 2.6)` | — |

Nota: `--radius` difiere entre light (0.45rem) y dark (0.7rem) — dark queda
con esquinas más redondeadas (look Linear más suave sobre canvas oscuro).

### Colores — light (`:root`)

Todos los valores son OKLCH tal cual están en `globals.css`.

| Token | Valor OKLCH |
|---|---|
| `--background` | `oklch(1 0 0)` (blanco puro) |
| `--foreground` | `oklch(0.262 0.010 245.3)` |
| `--card` / `--popover` | `oklch(1 0 0)` |
| `--card-foreground` / `--popover-foreground` | `oklch(0.262 0.010 245.3)` |
| `--primary` | `oklch(0.262 0.010 245.3)` (neutro oscuro, NO verde) |
| `--primary-foreground` | `oklch(0.967 0.003 264.54)` |
| `--secondary` / `--muted` / `--accent` | `oklch(0.97 0 0)` |
| `--secondary-foreground` / `--accent-foreground` | `oklch(0.262 0.010 245.3)` |
| `--muted-foreground` | `oklch(0.556 0 0)` |
| `--destructive` | `oklch(0.577 0.245 27.325)` |
| `--destructive-foreground` | `oklch(1 0 0)` |
| `--border` / `--input` | `oklch(0.947 0.006 240.4)` |
| `--ring` | `oklch(0.708 0 0)` |
| `--brand` | `oklch(0.7800 0.1550 169)` (= verde Punto `#01D7A1`) |
| `--brand-foreground` | `oklch(0.262 0.010 245.3)` |
| `--chart-1..5` | `oklch(0.78/0.68/0.58/0.48/0.38 0.155/0.145/0.130/0.110/0.090 169)` — escala monocromática verde, mayor a menor luminosidad |
| `--sidebar` | `oklch(0.967 0.003 264.54)` |
| `--sidebar-foreground` | `oklch(0.262 0.010 245.3)` |

### Colores — dark (`.dark`)

| Token | Valor |
|---|---|
| `--background` | `#060A0E` (hex directo, NO oklch — canvas casi negro) |
| `--foreground` | `oklch(0.967 0.003 264.54)` |
| `--card` | `transparent` (el Card se ve solo por `ring-1 ring-foreground/10`; look "panel limpio" sin bloque de color, más Linear-like) |
| `--card-foreground` | `oklch(0.967 0.003 264.54)` |
| `--popover` | `oklch(0.262 0.010 245.3)` (sólido — flota sobre cualquier contenido) |
| `--popover-foreground` | `oklch(0.967 0.003 264.54)` |
| `--primary` | `oklch(0.947 0.006 240.4)` (gris claro, NO verde — CTAs neutros) |
| `--primary-foreground` | `oklch(0.262 0.010 245.3)` |
| `--secondary` / `--muted` / `--accent` | `#1A1D1F` |
| `--secondary-foreground` / `--accent-foreground` | `oklch(0.967 0.003 264.54)` |
| `--muted-foreground` | `oklch(0.708 0 0)` |
| `--destructive` | `oklch(0.704 0.191 22.216)` |
| `--destructive-foreground` | `oklch(1 0 0)` |
| `--border` | `oklch(1 0 0 / 10%)` |
| `--input` | `oklch(1 0 0 / 15%)` |
| `--ring` | `oklch(0.556 0 0)` |
| `--brand` | `oklch(0.7800 0.1550 169)` (mismo verde) |
| `--sidebar` | `oklch(0.262 0.010 245.3)` |
| `--sidebar-accent` (hover de items) | `#1A1D1F` |
| `--chart-1..5` | `oklch(0.82/0.70/0.58/0.48/0.38 0.16/0.15/0.13/0.11/0.09 169)` — arranca más claro que light para contraste sobre fondo oscuro |

**Regla de marca (documentada inline en globals.css):** el verde Punto NUNCA
es `--primary`. En light, `--primary` es neutro oscuro; en dark, gris claro.
El verde queda reservado a charts y acentos puntuales (badges, CTAs de marca
específicos, FAB del agente). Charts: escala de grises con jerarquía por
tamaño en... **no** — charts usan escala verde monocromática en ambos modos
(no grayscale; ver tabla arriba), la nota del comentario original en el CSS
sobre "grayscale en light" no refleja el valor actual del token — ver
Changelog.

### Sombras

Todas las `--shadow-*` tienen `--shadow-opacity: 0` en ambos modos → **no hay
sombras visibles** en el sistema actual (superficie flat, separación por
`border`/`ring` únicamente, no por elevación).

### Animaciones custom

| Token | Uso |
|---|---|
| `--animate-marquee` / `--animate-marquee-vertical` | `<EmptyState>` (Marquee decorativo) |
| `--animate-pin-pop` | LockScreen del POS — bounce al pintar un círculo del PIN |
| `--animate-pin-shake` | LockScreen — shake en PIN incorrecto |
| `--animate-pos-loading` | `PosLoadingScreen` — barra indeterminada |

### Tipografía táctil del POS

Scoped a `.pos-scope` (el `SidebarInset` del layout `(pos)/`): inputs/textareas
suben a `font-size: 1.0625rem` (~17px, un punto sobre `text-sm`), `font-weight:
600`, `letter-spacing: 0.025em`. Fuera del POS el panel usa el default shadcn
sin este override.

### Spacing (convención, no CSS var — clases Tailwind)

| Contexto | Clase |
|---|---|
| Gap entre bloques de página | `gap-6` |
| Gap entre secciones | `gap-4` |
| Gap entre elementos de form group | `gap-3` |
| Gap entre chips/badges inline | `gap-2` |
| Padding interno default | `p-4` |
| Padding en bloques grandes | `p-6` |

---

## 3. Color

### Semánticos — cuándo usar cada token

| Token | Cuándo |
|---|---|
| `background` / `foreground` | Fondo de página o dialog / texto principal |
| `card` / `card-foreground` | Superficie de cards |
| `muted` / `muted-foreground` | Secciones secundarias / labels, hints, timestamps |
| `primary` / `primary-foreground` | Acción principal, botón default |
| `secondary` / `accent` | Hover states, selección activa en lista |
| `destructive` / `destructive-foreground` | Eliminar, anular, error |
| `border` / `input` / `ring` | Divisores y bordes / borde de input / focus ring |
| `brand` / `brand-foreground` | Acentos de marca puntuales — NUNCA superficies grandes |

### Paleta de acentos unificada (`lib/ui/color-palette.ts`)

`PALETTE_COLORS` — 6 colores fijos, usados en Hotkeys, avatar de Usuarios,
Impresoras y Medios de pago:

| key | hex |
|---|---|
| `amber` | `#f59e0b` |
| `slate` | `#64748b` |
| `sky` | `#38bdf8` |
| `rose` | `#f43f5e` |
| `emerald` | `#10b981` |
| `violet` | `#8b5cf6` |

**Convención de storage: se persiste el `key` (`"amber"`), NUNCA el hex.** El
hex vive solo en `PALETTE_COLORS` — así se puede re-tunear la paleta sin
migrar datos. `resolveColorBg(value)` resuelve ambos casos: si `value`
empieza con `#` lo devuelve tal cual (compat con hex legacy en usuarios/
impresoras viejos), si no lo busca como key. Usar siempre `resolveColorBg`
al consumir un color guardado antes de aplicarlo a un `style`.

`<ColorPicker>` (`components/ui/color-picker.tsx`) es el picker canónico —
swatches circulares, `variant="default"` (`size-7`, ring foreground, para
forms) o `variant="overlay"` (`size-3.5`, ring blanco, para pills flotantes
sobre tiles oscuros — caso Hotkeys). `allowNone` agrega botón "sin color" que
emite `""`. No duplicar filas de swatches inline en ningún módulo nuevo.

### Excepciones documentadas a "solo tokens"

- `bg-amber-500 text-white` — badge de alerta fuera de la paleta semántica,
  solo si el owner lo pide explícito y queda documentado inline.
- `text-emerald-600` — estado "Pagado" en crédito (no hay token semántico
  equivalente a "éxito positivo").
- Colores de categoría custom (JSONB del item) → `style={{ backgroundColor:
  color }}` directo, porque son datos de usuario, no design tokens.
- `QuotePrintView` (preview de documento imprimible) — hoja de papel blanca
  con hex hardcodeado a propósito, ver §4.

### Regla dura

Prohibido hex hardcodeado sin comentario de justificación. Prohibido
`text-white` como alternativa a `text-destructive-foreground` /
`text-primary-foreground`.

---

## 4. Componentes canon

Inventario real en `frontend/components/ui/`: `alert`, `alert-dialog`,
`avatar`, `badge`, `button`, `calendar`, `card`, `chart`, `checkbox`,
`collapsible`, `color-picker`, `command`, `dialog`, `drawer`, `dropdown-menu`,
`empty`, `form`, `input`, `input-group`, `input-otp`, `label`, `money-input`,
`popover`, `progress`, `select`, `separator`, `sheet`, `sidebar`, `skeleton`,
`sonner`, `switch`, `table`, `tabs`, `textarea`, `toggle`, `tooltip`.

### DataTable — `components/data-table/data-table.tsx`

Obligatorio para todo listado ≥ 10 filas. Construido sobre TanStack Table +
primitives shadcn (`Table`, `DropdownMenu`, `Select`, `Checkbox`, `Skeleton`).
Trae: search input, sort por columna, date-range filter, export XLSX,
column-toggle (persistido en localStorage por `tableId`), paginación, loading
skeleton. Listados < 10 filas embebidos en un panel de detalle → lista
vertical `<div className="divide-y">`, no DataTable.

### MoneyInput — `components/ui/money-input.tsx`

Único componente permitido para inputs de monto. As-you-type estilo
calculadora: el usuario tipea solo dígitos, el separador decimal se inserta
automáticamente desde la derecha. Lee `bootstrap.thousand` (`"comma"` →
`,`/`.` anglo; `"dot"` → `.`/`,` es-PY) y `bootstrap.decimal` (`"yes"` = 2
decimales, `"no"` = 0) vía `useBootstrap()`. Al focus limpia el display para
tipeo directo; en blur sin input restaura el valor previo. `value: number |
null`, `onChange(next: number | null)`. Prohibido `<Input type="number">`
para cualquier campo de dinero.

### DatePicker / DateRangePicker — `components/date-picker.tsx` / `components/date-range-picker.tsx`

`Calendar` shadcn dentro de `Popover`. Reemplazan `<input type="date">`
nativo (evita el UI del browser, que varía entre sistemas). Trabajan con
strings ISO `"YYYY-MM-DD"` para compat directa con backend/form submits.
`DatePicker` soporta `captionLayout` (`"label" | "dropdown" | ...`) y límites
de navegación (`startMonth`/`endMonth`).

### CatalogManager — `components/catalog/catalog-manager.tsx`

Pattern canónico de CRUD chico (catálogos: taxonomías, hotkeys, etc.):
`DataTable` + `Dialog` de alta/edición + `AlertDialog` de borrado + drag&drop
reorder (`@dnd-kit`) + `ColorPicker` cuando el catálogo tiene color. Reusar
este componente para cualquier CRUD nuevo de catálogo simple en vez de
armar la tabla + dialogs desde cero.

### EmptyState — `components/empty-state.tsx`

```tsx
<EmptyState icon={Receipt} title="Sin transacciones"
  description="Cuando hagas ventas aparecerán acá." />

<EmptyState ghost icon={ClipboardList} title="Sin órdenes activas"
  description="..." />
```

Envuelve `Empty`/`EmptyHeader`/`EmptyTitle`/`EmptyDescription` de
`components/ui/empty` + un `Marquee` decorativo (repite el ícono 4x en loop
vertical, degradado de opacidad hacia arriba) — prop `ghost` (`boolean |
number`, default 4 filas) controla esto explícitamente; `ghost={false}` cae
a ícono grande estático (`size-12` box con `border bg-muted/30`).
`showMarquee` es el nombre viejo de la misma prop, deprecado pero vigente
por compat (sin `ghost` ni `showMarquee`, el default sigue siendo 4 filas).
Nunca un `<p>"No hay resultados"</p>` pelado ni un `<Empty>` armado a mano
call-site — siempre `<EmptyState>`. Único lugar (junto al sidebar) donde los
íconos van fuera de botones icon-only.

### RowActions — `components/data-table/row-actions.tsx`

Fila de `<DataTable>` con **2+ acciones** → `<RowActions actions={[...]} />`
(agrupa en `DropdownMenu` con trigger `MoreHorizontal`, destructivas al final
tras `DropdownMenuSeparator`). Con **1 sola acción** (tras filtrar `hidden`)
renderiza un botón ghost directo — nunca un dropdown de un solo ítem. Nunca
botones sueltos por columna ni `<button>` nativo.

### Select con sentinel

`<Select>` de shadcn no acepta `value=""` en un `<SelectItem>` (colisiona con
el estado "sin selección" interno de Radix). Usar un sentinel explícito
(`"__none__"`) y traducirlo a `null`/`undefined` al leer el valor — nunca
`value=""`.

### Toasts — `sonner`

`<Toaster position="top-center" />` (montado en `components/providers.tsx`;
también en `app/(screen)/layout.tsx` sin position override para esa surface).
Iconos custom por tipo (`success`/`info` en verde `--chart-1`, `warning` en
`amber-500`, `error` en `destructive`, `loading` spinner muted). Estilo vía
CSS vars: `--normal-bg: var(--popover)`, `--normal-text:
var(--popover-foreground)`, `--normal-border: var(--border))`.

```tsx
toast.success("Items duplicados al carrito")
toast.error("La transacción no tiene items para duplicar")
```

Reglas: sin emojis, menos de 60 caracteres, imperativo cortés o descriptivo
directo, nunca para errores de validación de form (usar inline error).

### Modales — escala de tamaños

| Bucket | Clase | Cuándo |
|---|---|---|
| `xs` | `sm:max-w-md` | Confirmación/alert, `NumericPadDialog` |
| `sm` | `sm:max-w-lg` | Default shadcn — form de un solo campo, casi nunca |
| **`m`** | **`sm:max-w-2xl`** | **Default del proyecto** — arrancá siempre acá |
| `l` | `sm:max-w-4xl` | Listados, tablas, formularios complejos en 2 columnas |
| `xl` | `sm:max-w-6xl` | Split 2-col, dashboards, vistas amplias |

Alto: dejar al contenido; `max-h-[Xvh] overflow-y-auto` solo si puede exceder
viewport (ej. split 2-col: `max-h-[80vh]`). Prohibido `max-w-[95vw]
w-[95vw] h-[90vh]` hardcodeado.

### Otros patrones de componente (detalle completo en §14/§4 legacy)

Ver `context/14-ui-conventions.md` Regla #2 (botones/badges/tamaños) y el
`context/20` previo a esta reescritura para snippets extensos de
`NumericPadDialog`, modal split 2-col, detalle tipo invoice de transacción y
KPIs — se preservan como referencia de implementación pero no se repiten acá
línea por línea; los principios ya están cubiertos en §3/§4/§6/§7.

---

## 5. Prohibiciones

| Prohibido | Usar en su lugar |
|---|---|
| `<table>`, `<button>`, `<input>`, `<label>` nativos | Primitive shadcn equivalente |
| `<input type="number">` para dinero | `<MoneyInput>` |
| `value.toLocaleString()` sin locale / `toFixed(2)` para montos | `formatMoney(value, config)` / `formatAmount(value, config)` |
| Hex hardcodeado sin comentario de justificación | Tokens semánticos (§3) |
| `text-white` en botón/badge destructivo | `text-destructive-foreground` |
| Iconos en h1/h2/h3, headers de `<Card>`, headers de `<Dialog>` | Sin ícono — solo texto |
| `<Card>` envolviendo cada row de un listado | `divide-y` o `<DataTable>` |
| `className="h-8 w-8 p-0"` custom en vez de `size="icon"` | `size=` prop de `<Button>` |
| `max-w-[Xvw] w-[Xvw] h-[Xvh]` hardcodeado en Dialog | Escala xs/sm/m/l/xl |
| `<Select>` con `value=""` | Sentinel `"__none__"` |
| `window.print()` sobre DOM arbitrario para comprobantes | Binding de impresora dedicado (`lib/hardware/printers`) — ver `context/05` |
| Emojis en UI/copy | — |
| Terminología verticalizada ("vendedor", "mozo") | "usuario", "operador", "asignado" |

Detalle operativo completo (checklist pre-merge, ejemplos de código) en
`context/14-ui-conventions.md`.

---

## 6. Patrones de página

### Header de página

```tsx
<div className="max-w-7xl mx-auto px-4 py-6">
  <div className="flex flex-col gap-6">
    <header>
      <h1 className="text-2xl font-semibold">Título de página</h1>
      <p className="text-sm text-muted-foreground">Descripción opcional</p>
    </header>
    {/* contenido */}
  </div>
</div>
```

### Tabs responsivas (patrón finanzas)

Grid en desktop, scroll horizontal en mobile — mismo componente `<Tabs>` de
shadcn, el contenedor de `<TabsList>` cambia de `grid grid-cols-N` a `flex
overflow-x-auto` bajo el breakpoint mobile.

### Dialogs

`max-h-[85vh] overflow-y-auto` cuando el contenido puede exceder el
viewport. Footer con `<DialogFooter>` (right-aligned por default): Cancelar
`variant="outline"` a la izquierda, Confirmar `variant="default"` a la
derecha. Nunca `flex-1` en ambos botones.

### Confirmación destructiva

`<AlertDialog>` siempre para eliminar/anular/vaciar:

```tsx
<AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
  Eliminar
</AlertDialogAction>
```

Neutral (info, form corto) → `<Dialog>` normal.

### Loading / skeleton

| Caso | Pattern |
|---|---|
| Listado cargando | `<Skeleton>` (nunca spinner) |
| Botón submit | `<Loader2 className="size-4 animate-spin" />` junto al label |
| POS bootstrap | `PosLoadingScreen` dedicada (barra `--animate-pos-loading`) |

### Invalidación optimista

React Query (`@tanstack/react-query`) es el estado de servidor en todo el
frontend. Mutaciones invalidan queries relacionadas al éxito; no hay patrón
de rollback optimista documentado como default — cada mutación decide según
el costo de un flash de dato stale.

---

## 7. POS específico

### Modo oscuro

`ThemeProvider defaultTheme="dark"` — el POS arranca en dark por default
(`app/layout.tsx`). El panel respeta la preferencia del sistema/usuario vía
`next-themes`.

### Touch targets

Mínimo 44px de alto — `h-11` + padding en botones principales del carrito y
grid de artículos. Overrides de tamaño del default shadcn se documentan
inline: `<Button className="h-12 w-full" /* h-12: se opera con dedo en
tablet */>`.

### Tipografía táctil

Ver §2 — `.pos-scope` sube inputs/textareas a `1.0625rem` / `font-weight:
600` / `letter-spacing: 0.025em` para lectura rápida sin esfuerzo del
cajero.

### Shortcuts globales (`hooks/use-pos-hotkeys.ts`)

| Tecla | Acción |
|---|---|
| `Q` | Menú del POS |
| `W` | Buscador de artículos |
| `E` | Buscador de clientes |
| `R` | Menú de opciones de venta |
| `Enter` | Cobrar (si hay items en el carrito) |
| `ESC` | Lo manejan los overlays shadcn (Dialog/Sheet cierran solos) |

Todo atajo global nuevo DEBE chequear que no haya un `Dialog`/`Sheet`/
`AlertDialog` abierto antes de disparar (`[role="dialog"][data-state="open"],
[role="alertdialog"][data-state="open"]` via `document.querySelector`) — si
no, el atajo dispara mientras el cajero completa un form en un modal.

### Flujo de pago (`components/register/pay-dialog.tsx`)

Un visor central único que funciona simultáneamente como display del
`remaining` y como input numérico editable. Al cubrir el total con un pago
la venta se confirma automáticamente (sin segundo click). Fases: `pay` →
`success` (pantalla de confirmación con vuelto). Los medios de pago se
renderizan como **pills** con código de atajo de una letra (ej. `A` =
Efectivo, `S` = T. Crédito, `D` = T. Débito, `G` = Giftcard) — el `code` viene
del `PaymentMethodConfig` del tenant. Medios secundarios (discriminados por
`systemKey`, ej. `"giftcard"`, `"internal"`) se listan en línea separada
debajo de la grilla principal.

### NumericPad / NumericPadDialog (`components/pos/`)

Input numérico as-you-type para cantidad, descuento, precio. Modos: `"int"`
| `"decimal"` | `"money"` | `"percent"`. Mode label top-right (`".00"` para
decimal, `"%"` para percent). Hotkeys internos: dígitos, `.`, `Backspace`,
`Enter` (confirmar), `ESC` (cerrar), `Shift` (toggle modo). Bucket `xs`,
footer con botón único "Aceptar" full-width `size="lg"`.

### LockScreen

PIN local de 4 dígitos, hash SHA-256 vía Web Crypto API (prohibido bcrypt en
browser — no soportado nativamente). Feedback visual: `pin-pop` al pintar un
círculo, `pin-shake` en PIN incorrecto (tokens de animación, §2).

### Sesión

Cookie `_jwt` (10 años, device pairing) separada de `_jwt_panel` (24h,
operador). El logout del sidebar del panel NO cierra la sesión del POS —
solo se cierra desde Ajustes → "Eliminar dispositivo del comercio".

### Colores de modo del POS (2026-07-19)

Los cajeros operan sin leer — el color, no el texto, es el canal principal
para saber en qué modo está la caja y qué hace el botón principal. Mapeo
único en `lib/pos/mode-visuals.ts` (`MODE_VISUALS` + `resolveCartMode()`) —
**nunca duplicar el mapping inline** en otro componente.

**Regla fija: un color por modo, en UN solo lugar** — el CTA principal del
carrito (`CartBottom` en `components/register/cart-panel.tsx`). La banda
superior (`ModeBanner`) fue removida 2026-07-19 por decisión del owner: la
identificación del tipo de transacción la da el botón de pago, sin slot
extra arriba del toolbar. El nombre del espacio + X de deselección viven en
`SpaceChip`.

| Modo | Color (`PALETTE_COLORS`) | CTA | Notas |
|---|---|---|---|
| Venta | ninguno — baseline oscuro actual | el TOTAL formateado (histórico — el cajero mira el botón para saber cuánto cobrar) | Cualquier color = "no estás cobrando"; por eso venta queda sin tinte |
| Orden (mostrador) | `emerald` | "Ordenar" + total en secundario | |
| Orden (espacio seleccionado) | `emerald` (mismo color) | nombre del espacio (ej. "Mesa 4") + ícono `Armchair` + total en secundario | Reemplaza al `SpaceChip` — la banda ya trae la X de deseleccionar |
| Cotización | `amber` | refleja el guardado en vuelo, no dispara acción | Cotización es una acción de guardado inmediato, NO un `posMode` sticky (`lib/cart/store.ts`) — el color solo vive mientras la promesa de `createQuote()` está en curso (`usePosUIStore.savingQuote`) |
| Agenda | `violet` | — | Reservado — "Modo reserva" (context/24, O4, fuera de alcance). Sin estado real hoy; queda definido en el mapping para que agenda lo consuma directo |

**Contraste**: blanco sobre `amber`/`emerald`/`violet` no llega a AA (medido
~2.1:1 / ~2.5:1 / ~4.2:1 contra el mínimo 3:1 de texto grande) — CTA y banda
usan `text-black` sobre los 3 acentos (>4.9:1, AA holgado en cualquier
tamaño). Los 3 hex son fijos (no tokens de tema) — se ven igual en light y
dark.

---

## 8. Formatos

**Regla dura: nunca `toLocaleString()` sin locale explícito, nunca
`toFixed(2)` para montos, nunca interpolación directa de números crudos.**
Siempre vía helper.

### Moneda

Dos implementaciones activas en el repo — ambas correctas para su contexto,
pero son fuentes separadas (inconsistencia real, ver Changelog):

| Helper | Path | Fuente de config | Uso |
|---|---|---|---|
| `formatMoney(value, config)` | `lib/format-money.ts` | `PosConfig` (`currency`/`thousand`/`decimal`) | POS — prefijo de moneda ya incluido, nunca escribir `Gs {formatMoney(...)}` |
| `formatAmount(value, config)` | `lib/format-money.ts` | `PosConfig` | Número sin prefijo (ej. filas de carrito, donde "Gs" solo va en el botón de cobrar) |
| `formatMoney(amount, bootstrap)` | `lib/format.ts` | `Bootstrap` (`currency`/`decimal`/`thousand`) | Panel — usa `Intl.NumberFormat` con locale `en-US` (thousand=comma) o `es-PY` (thousand=dot) |
| `formatInt(n, bootstrap)` | `lib/format.ts` | `Bootstrap` | Enteros con separador de miles, panel |
| `formatCurrencyAmount(amount, code)` | `lib/format-money.ts` | ISO 4217 code | Monedas extranjeras — `Intl.NumberFormat`, 0 decimales para `PYG/CLP/JPY/KRW/VND/IDR`, 2 para el resto |

Convención `thousand`: `"comma"` → separador de miles `,` (locale anglo,
decimal `.`) — `"dot"` → separador de miles `.` (es-PY/europeo, decimal `,`).
`decimal: "yes"` = 2 decimales, `"no"` = 0.

### Fecha

`lib/format-date.ts` — timestamps de negocio (ventas, caja, reportes) se
guardan en BD como hora LOCAL del comercio pero etiquetados como UTC (ej.
venta de las 22:19 local queda `"2026-06-29 22:19:38+00"`). `parseNaive(iso)`
strippea el offset y parsea los componentes tal cual en la TZ del browser
— evita el bug de restar 3h a algo que ya es hora local.

| Helper | Formato | Ejemplo |
|---|---|---|
| `formatDateTime(iso, fmt?)` | default `"d MMM HH:mm"` | `"29 jun 22:19"` |
| `formatDate(iso)` | `"d MMM yyyy"` | `"29 jun 2026"` |
| `formatTime(iso)` | `"HH:mm"` | `"22:19"` |
| `tenantNow(timeZone?)` | `"YYYY-MM-DD HH:MM:SS"` naive en TZ del tenant | para writes desde el cliente |

Locale: `date-fns` con `locale: es`. Solo usar `parseNaive`/estos helpers
para timestamps de negocio local-naive — NO para fechas genuinamente UTC ni
pickers de input (esos manejan `Date` nativos).

### Teléfono

Frontend captura/muestra en formato nacional sin `+`; el backend recibe/envía
E.164; `libphonenumber-js` es la única fuente de verdad para parsear/validar/
convertir. Storage en BD: sin el `+` inicial (ej. `"595991742353"`).

### Números tabulares

`tabular-nums` siempre en montos, fechas, cantidades — evita que los dígitos
salten de ancho al actualizarse (crítico en el visor de cobro del POS).

---

## 9. Guía para apps externas

Para levantar el mismo look & feel en una app fuera de este repo (sin acceso
a `frontend/`), sin depender de ningún archivo del monorepo:

**1. Instalar shadcn/ui** con Tailwind v4:

```bash
npx shadcn@latest init
```

Configuración equivalente a `components.json` de Punto:
```json
{
  "style": "radix-rhea",
  "tailwind": { "baseColor": "neutral", "cssVariables": true, "prefix": "" },
  "iconLibrary": "lucide"
}
```

**2. Pegar los tokens CSS** — copiar el bloque `:root { }` y `.dark { }` de
§2 (Colores light / Colores dark) tal cual, con los mismos nombres de
variable (`--background`, `--primary`, `--brand`, etc.). Punto usa OKLCH;
si el proyecto destino usa HSL/hex, convertir manteniendo los mismos
valores perceptuales — no reinventar la paleta.

**3. Fuente:** cargar `Inter` (Google Fonts) como `--font-sans` vía
`next/font/google` o `<link>` equivalente, más `JetBrains Mono` como
`--font-mono`. (El CSS del repo declara `Poppins` como fallback pero el
layout real carga `Inter` — usar Inter, ver Changelog.)

**4. Radius:** `--radius: 0.45rem` en light, `0.7rem` en dark. Escala
derivada: `sm 0.375rem / md 0.5rem / lg 0.75rem / xl 1rem / 2xl 1.5rem`.

**5. Sombras:** ninguna — `--shadow-opacity: 0` en todo el sistema.
Separación de superficies por `border`/`ring`, no por elevación. En dark,
`--card: transparent` (el card se distingue solo por `ring-1
ring-foreground/10`).

**6. Verde de marca:** `#01D7A1` (`oklch(0.78 0.155 169)`) — SOLO en
`--brand`/`--chart-*`, nunca en `--primary`. `--primary` es neutro
(oscuro en light, gris claro en dark).

**7. Reglas mínimas de consistencia** (aplicar aunque no se copie el resto
del sistema):
- Prohibido hex hardcodeado fuera de los tokens.
- `tabular-nums` en todo número, `text-2xl font-semibold` en h1.
- Sin emojis en UI.
- Iconos `lucide-react` solo en: nav, botones icon-only, empty states.
- Modales arrancan en `max-w-2xl` (no el `max-w-lg` default de shadcn).
- Toasts `sonner`, `position="top-center"`, sin emojis, <60 caracteres.

**8. NO copiar** sin adaptar: los helpers de formato (dependen de `config`/
`bootstrap` propios del tenant de Punto), el modelo de auth (`_jwt`/
`_jwt_panel`), ni los componentes de dominio (`CatalogManager`, `PayDialog`)
— son específicos del negocio, no del design system.

---

## 10. Changelog de decisiones

| Fecha | Decisión | Commit | Razón |
|---|---|---|---|
| 2026-07-19 | `ModeBanner` removida del carrito: el color de modo vive SOLO en el CTA de cobro. `SpaceChip` recupera nombre+X del espacio. Forma redonda de mesas solo en el mapa (grilla = tiles uniformes) | — | Owner: "la identificación del tipo de transacción se da por el botón de pago, no necesitamos otro ahí arriba" |
| 2026-07-19 | `EmptyState` unificado: prop `ghost` (boolean\|number) reemplaza `showMarquee` (deprecado, no removido) como forma explícita de pedir el patrón de ghost cards. `/pos/ordenes` migrado del `<Empty>` hand-rolled a `<EmptyState ghost>` | — | Había 2-3 empty states distintos en páginas del POS (Guardadas con ghosts, Órdenes con ícono en círculo sin ghosts); unificado a un solo modelo |
| 2026-07-18 | Reescritura completa de `context/20` como design system definitivo: tokens reales extraídos de `globals.css`/`components.json`, paleta de acentos, formatos, patrones POS, guía de bootstrap para apps externas | — | El doc anterior documentaba solo patrones de componentes sin los tokens crudos ni una guía standalone; se necesitaba una referencia autocontenida para compartir UI/UX fuera del repo |
| 2026-07-18 | Inter declarada canónica en `globals.css` y `context/20`. Eliminada declaración residual de Poppins | — | Owner decidió Inter como fuente; unificada la capa CSS/doc |
| 2026-07-18 | Inconsistencia detectada, NO corregida: dos implementaciones de `formatMoney` con firmas distintas (`lib/format-money.ts` sobre `PosConfig`, `lib/format.ts` sobre `Bootstrap`) — ambas en uso activo en contextos distintos (POS vs panel) | — | Documentado en §8 en vez de unificarse; unificar requeriría normalizar `PosConfig`/`Bootstrap` a una sola shape de config de tenant — fuera de scope de este doc |
| 2026-06-25 | Regla: iconos SOLO en sidebar nav, botones icon-only y empty states. Prohibido en cards, títulos, headers de modal | — | Regla explicitada por owner |
| 2026-06-25 | NumericPadDialog unificado: title + mode label en header, unidad inline en display, Aceptar full-width en footer. `subtitle` deprecado | 532b36f | 3 iteraciones para llegar al pattern correcto |
| 2026-06-25 | Rename "vendedor" → "usuario" / terminología vertical-neutral en strings de UI | 8b69da1 | Punto sirve múltiples verticales |
| 2026-06-25 | Escape hatch global para atajos POS cuando hay dialog shadcn abierto | 9ff3885 | Atajos disparaban mientras el usuario completaba un form en un modal |
| 2026-07-02 | Paleta de colores unificada (`lib/ui/color-palette.ts`) + ColorPicker canónico; se persiste el key del color no el hex, `resolveColorBg` cubre hex legacy | — | Swatches duplicados inline en Hotkeys/Usuarios/Impresoras; unificado + reusable |
| 2026-06-24 | Redesign invoice-style de detalle de transacciones: sin stat cards, tabla de items canónica, totals right-aligned, status final al pie, sin badge de tipo arriba | 5c30c2a | 3 iteraciones: stat cards → stat cards restyle → invoice pattern correcto |
| 2026-06-24 | Sin stat cards arriba de listados en páginas de reportes | — | Simplificación post-planning; el DataTable tiene toda la info |
| 2026-06-24 | Roles seed = 3 (Dueño/Encargado/Cajero) | — | Simplificación post-planning; el modelo de 5 roles era demasiado granular |

**Cómo invocar este doc en briefs de sub-agentes:** referencia puntual
("Seguí §7 para patrones POS") o lectura completa para cambios cross-cutting
(más de 3 secciones tocadas). Los sub-agentes deben FLAGEAR en el reporte si
el brief contradice algo de este doc. Relación con otros docs: `context/14`
(reglas operativas base, leer primero), `context/11` (manual de marca legacy
BS3 — superseded por este doc para todo lo que sea panel/POS en React;
sigue vigente para las pantallas legacy PHP no migradas).
