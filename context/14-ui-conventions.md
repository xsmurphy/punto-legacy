<!-- REGLA: Convenciones de UI del frontend. Lectura OBLIGATORIA antes de
     crear o modificar componentes visuales. Cualquier desviación documentada
     en el código (comentario "// razón:") o no se mergea. -->

# 14 — Convenciones de UI

Punto usa **shadcn/ui** con su tema default. **Mantener el default es la regla por
defecto**: solo se sobreescribe cuando hay razón documentada (no por gusto, no
para replicar el legacy).

---

## Regla #0 — Nunca replicar visual del legacy

El POS y panel legacy son referencia **funcional**: qué campos, qué flujos, qué
edge cases. **No** son referencia visual. Tamaños, paddings, colores, posiciones
y tipografía vienen del design system de shadcn + lo que ya está en frontend.

Si en un sub-agente leés un screenshot del legacy: usalo solo para entender qué
información mostrar. La forma visual sale de los patrones de frontend.

---

## Regla #1 — Tipografía canónica

| Uso | Canon | Ejemplo del repo |
|---|---|---|
| Título de página | `<h1 className="text-2xl font-semibold">` | `app/(panel)/items/page.tsx:366` |
| Título de sección dentro de página | `<h3 className="text-base font-semibold tracking-tight">` | `components/forms/form-section.tsx:36` |
| Label uppercase de bloque (Items, Pagos, Totales, …) | `<p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">` | `components/domain/transactions/transactions-list.tsx:928` |
| Texto principal | `text-foreground` (default) — sin override | |
| Texto secundario / descripciones | `text-sm text-muted-foreground` | `app/(panel)/items/page.tsx:374` |
| Subtexto chico (timestamp, hint) | `text-xs text-muted-foreground` | uso esporádico |

**Anti-patrón detectado (2026-06-24)**: título de modal como `<h2 text-base font-semibold>`. **Mal**. El título de un modal de página es h1/h2 con `text-2xl font-semibold` igual que cualquier título de pantalla.

---

## Regla #2 — Componentes shadcn sin hacks de tamaño

| Componente | Default canónico | Hack que NO hacer |
|---|---|---|
| `<Input>` | `h-9` (size default de shadcn) | `h-8 text-sm` (apretado, no-shadcn) |
| `<Button>` | `size="default"` (h-9) / `size="sm"` (h-8) / `size="lg"` / `size="icon"` | `className="h-8 w-8 p-0"` custom |
| `<Badge>` | `variant="default|secondary|destructive|outline"` | colores hardcoded |
| `<DialogContent>` | usar la escala de Regla #2.1 abajo | `max-w-[Xvw]` hardcoded |

**Cuándo sí sobreescribir**: cuando lo justifica una restricción real (mobile dense, POS touch-first con dedo de cajero, tabla con muchas columnas). En ese caso **documentar en comentario inline** (`// h-12 porque botón se opera con dedo en tablet`).

### Regla #2.1 — Escala de tamaños de modales/sheets

shadcn `<DialogContent>` viene con `sm:max-w-lg` por default (32rem / 512px). **Ese tamaño es chico para casi todos los casos del proyecto.** Pensar en estos buckets:

| Bucket | Clase Tailwind | Cuándo usarlo |
|---|---|---|
| `xs` | `sm:max-w-md` (28rem / 448px) | Confirmación / alert con 1-2 líneas y 2 botones (ej. `<AlertDialog>` de eliminar) |
| `sm` | `sm:max-w-lg` (32rem / 512px) | shadcn default. Form de **un solo campo** (ej. renombrar). Casi nunca. |
| **`m`** | **`sm:max-w-2xl` (42rem / 672px)** | **DEFAULT del proyecto.** Form típico, dialog con varios campos, edición simple. **Siempre arrancá acá.** |
| `l` | `sm:max-w-4xl` (56rem / 896px) | Listados, tablas, contenido tabular, formularios complejos en 2 columnas |
| `xl` | `sm:max-w-6xl` (72rem / 1152px) | Modal split 2-col (lista + detalle), dashboards, vistas amplias |

**Regla operativa**:
- **Por default arrancá en `m` (`sm:max-w-2xl`)**. NO en el `sm:max-w-lg` que viene de shadcn por default.
- Subí el tamaño solo si el contenido lo pide (más columnas, split, listado largo).
- Bajá a `xs` solo para alerts/confirmaciones.
- Para alto: dejar al contenido. Solo poner `max-h-[Xvh]` con `overflow-y-auto` si el contenido puede exceder el viewport.
- Para `Sheet` (lateral): por default `side="right"` con `sm:max-w-2xl` también (no el `sm:max-w-sm` default).

**Anti-patrón detectado (2026-06-24)**: modal del listado de transacciones POS con `max-w-[95vw] w-[95vw] h-[90vh]` hardcoded. **Mal**. Eso replica el legacy. Lo correcto: `sm:max-w-6xl` para split 2-col, alto del contenido.

**Si el `<Dialog>` no acepta override de tamaño** (componente shadcn que envuelve y fuerza): editá el componente base en `frontend/components/ui/dialog.tsx` para aceptar `size="xs|sm|m|l|xl"` como prop, default `m`. Esa edición vale el cost porque elimina la fricción.

### Regla #2.2 — Dialog (modal) es el default; Drawer solo en dos casos cerrados

Para paneles contextuales, formularios y detalles (incluidos los del POS), el
componente por defecto en DESKTOP es **`<Dialog>` centrado**. La regla nació
(2026-07-19) porque los sub-agentes metían `Sheet`/`Drawer` lateral para
información densa que necesita espacio — ahí un drawer a la derecha NO sirve.
Refinada 2026-08-01 por el owner; los ÚNICOS dos usos legítimos de Drawer:

1. **Mobile/tablet: bottom drawer para modales chicos de interacción** —
   confirmaciones, descuento, nota de venta, lista de precios, modificador de
   precio, cantidad. Se implementa vía el wrapper responsive canónico
   (Dialog centrado en desktop ↔ Drawer de abajo bajo el breakpoint), NUNCA
   importando Drawer directo en el call-site.
2. **Desktop: actionsheet** — lista corta de acciones contextuales. Nada de
   contenido denso, formularios largos ni listados.

Todo lo demás sigue igual:
- Panel de detalle/acciones con datos (ej. sesión de espacio) → `Dialog`.
- Formulario de alta/edición → `Dialog`.
- Confirmación → `AlertDialog` (que en mobile también baja como drawer vía el
  wrapper cuando aplique).
- En POS (touch-first), dentro del Dialog/Drawer usá botones `size="lg"` a
  ancho completo — el contenedor no releva de los touch targets grandes.

**Anti-patrón vigente**: `Sheet`/`Drawer` LATERAL para contenido denso
(2026-07-19: panel de sesión de espacio como Sheet → convertido a Dialog).
Eso sigue prohibido — el refinamiento habilita SOLO el bottom drawer mobile y
el actionsheet desktop.

---

## Regla #3 — Listados

Todo listado **largo** (>10 filas, búsqueda, sort, export) → **`<DataTable>`** de
`@/components/data-table/data-table`. Trae search, sort, paginación,
column-toggle y export incluidos. Ver memoria
`feedback_data_tables_convention`.

Listado **corto y embebido en un sheet/dialog** (ej. lista de monedas, lista de
usuarios al asignar) → grilla de `<Button variant="outline">` o `<Table>` shadcn
simple, sin DataTable.

**Empty state** siempre con `<EmptyState>` de `@/components/empty-state` (icono +
título + descripción). Nunca un `<p>"No hay resultados"</p>` pelado.

---

## Regla #4 — Layout y spacing

| Patrón | Canon | Ejemplo |
|---|---|---|
| Página | `<div className="flex flex-col gap-6">` con `<header>` arriba | `transactions-list.tsx:655` |
| Card de sección | `<Card>` shadcn con `<CardHeader>` + `<CardContent>` | `app/(panel)/settings/page.tsx` |
| Gap entre bloques | `gap-6` (página), `gap-4` (sección), `gap-3` (form group), `gap-2` (chips inline) | |
| Padding interno | `p-4` o `p-6` según jerarquía. No mezclar `px-4 py-3` apretado | |
| Border-radius | default shadcn (token `radius`) — nunca `rounded-2xl` hardcoded salvo input box conversacional | |

---

## Regla #5 — Colores

Solo tokens. Los hex literales (ej. `#22252A`) solo cuando el owner pidió
explícitamente un valor exacto (caso category bar POS — documentado).

Tokens disponibles: `background`, `foreground`, `card`, `card-foreground`,
`muted`, `muted-foreground`, `accent`, `accent-foreground`, `primary`,
`primary-foreground`, `secondary`, `secondary-foreground`, `destructive`,
`destructive-foreground`, `border`, `input`, `ring`. El brand verde de Punto es
para **acentos puntuales** (FAB del agente, avatar del agente). No bg-primary
para cards/listings genéricos.

---

## Regla #6 — Iconos

`lucide-react`. Tamaños: `size-3.5` (chico, inline en chip), `size-4` (default
en botón), `size-5` (header), `size-6` (empty state grande). Sin emojis.

---

## Regla #7 — Formatos

| Tipo | Helper | Path |
|---|---|---|
| Moneda local | `formatMoney(amount)` o `formatAmount(amount)` | `lib/format-money.ts` |
| Moneda con código | `formatCurrencyAmount(amount, code)` | `lib/format-money.ts` |
| Fecha completa | `niceDate(date)`, `niceDateTimeFull(date)` | depende del módulo — revisar el panel |
| Teléfono | `libphonenumber-js`, mostrar nacional sin '+' | memoria `feedback_phone_format_convention` |
| Fecha relativa | `formatRelativeTime` | `lib/agent/format-relative-time.ts` |

**Anti-patrón**: mostrar un timestamp crudo `"2026-06-22 12:59:27-03"` en una
fila de listado. Siempre pasar por un helper de formato local.

---

## Regla #8 — Tono de microcopy

- Español rioplatense / paraguayo neutro
- Acentos correctos. "Selecciona**á** una transacción" NO "Selecciona una transaccion"
- Toast: imperativo cortés ("Items duplicados al carrito") o descriptivo (no
  emojis de relleno)
- Empty states: 1 línea de título + 1 línea de descripción concreta de qué
  hacer ("Ajustá el rango de fechas y volvé a consultar")

---

## Regla #10 — POS: posiciones estables, sin desplazamiento condicional

La UI del POS **no desplaza elementos interactivos de forma condicional** —
cada botón vive SIEMPRE en las mismas coordenadas. Los cajeros operan por
memoria muscular; un elemento que cambia de lugar según el estado (modo de
caja, espacio seleccionado, etc.) rompe el flujo y genera errores.

- Las **señales de estado se pintan sobre elementos existentes** (tinte, label,
  borde) — no insertando/quitando bloques que empujan el layout.
- Si una señal necesita su propio bloque (una banda), ese bloque **existe
  siempre con la misma altura**: neutral/invisible en el estado baseline pero
  ocupando su espacio. Nunca `return null` condicional que cambie la altura del
  contenedor.
- Verificación: comparar el render de dos estados (ej. venta vs orden) — ningún
  elemento interactivo debe cambiar de coordenadas.

**Anti-patrón detectado (2026-07-19)**: la banda de modo del carrito (`ModeBanner`)
hacía `return null` en venta y aparecía (h-7) en orden → el toolbar de abajo se
desplazaba 28px entre modos. **Mal**. Ahora la banda existe siempre a h-7,
neutral en venta.

---

## Regla #11 — POS: montos, cantidades y porcentajes se capturan con el pad, NUNCA con un input

Todo valor numérico de la operación de caja (precio, monto, descuento —fijo o
porcentual—, cantidad, apertura/movimiento de caja) se captura con
`<NumericPad>` (`components/pos/numeric-pad.tsx`): visor grande de solo
lectura + teclas en pantalla + captura de teclado físico + input `sr-only`
que abre/oculta el teclado del OS al tocar el visor (mismo mecanismo que el
PIN del lock screen). Los `<Input>`/`<MoneyInput>` en el POS son SOLO para
texto y configuración.

Esta regla se decidió hace meses y ya fue violada una vez: el 2026-08-25 un
slice móvil introdujo `NumericField`, un wrapper que en <768px reemplazaba el
pad por un input nativo, documentándolo como "decisión del owner". El owner lo
declaró regresión el mismo día y se revirtió (merge `89d3c6c5`). Si un
teléfono hace incómodo el pad, se ajusta EL PAD (tamaños responsive de teclas
y visor — ya lo tiene), no se cambia la superficie de captura. Guard test:
`frontend/lib/pos/__tests__/numeric-capture.test.ts`.

Corolario (2026-08-25): los menús de acciones contextuales (fila de
transacción, orden, badge de estado) usan `<ActionMenu>`
(`components/ui/action-menu.tsx`) — DropdownMenu en desktop, drawer inferior
en móvil, labels texto solo. Prohibido montar un `DropdownMenu` de acciones
directo en un call-site del POS.

Corolario (2026-08-26, segunda vez que pasa): un toggle explícito del
usuario en Ajustes ("Mostrar teclado virtual") manda SIEMPRE sobre
cualquier heurístico de dispositivo (`pointer: coarse`, ancho de viewport,
etc.). El heurístico es el default antes de que el usuario decida algo; una
vez que decidió, ninguna detección automática lo puede pisar. Ya pasó antes
con `NumericField` (arriba): un slice asumió qué quería el dispositivo en
vez de leer la preferencia guardada.

---

## Checklist para review de un componente nuevo

Antes de mergear (o de cerrar el brief de un sub-agente):

- [ ] Titulo de página = `<h1 className="text-2xl font-semibold">`
- [ ] Inputs sin override de `h-X` salvo razón documentada
- [ ] Botones con `size=` prop, no `className="h-X"`
- [ ] Subtextos en `text-sm text-muted-foreground` (no `text-xs` por default)
- [ ] EmptyState component, no `<p>` pelado
- [ ] DataTable para listados grandes
- [ ] DialogContent sin `max-w-[Xvw]` hardcoded — usar `sm:max-w-2xl|3xl|4xl|5xl|6xl`
- [ ] DialogContent sin `p-0` a mano — si el modal tiene header fijo + cuerpo scrolleable + footer, va `sectioned` + `<DialogBody>` (gutter 24px, ver `context/20` §4 "Modales — padding y secciones")
- [ ] Sin hex colors hardcoded (excepto pedidos explícitos del owner)
- [ ] Sin emojis
- [ ] Formatos pasan por helpers (`formatAmount`, `niceDateTimeFull`, etc.)
- [ ] Acentos correctos en strings ES
- [ ] Cero referencias visuales al legacy (estructura ≠ pixels)

---

## Regla operativa para sub-agentes

Cuando recibís un brief con un screenshot del legacy o una descripción de pantalla:

1. Leé la lista de regla 0–8 antes de tocar JSX
2. Buscá la pantalla análoga en frontend (`app/(panel)/items/page.tsx`,
   `app/(panel)/outlets/[id]/page.tsx`, `components/domain/transactions/transactions-list.tsx`)
3. Copiá la estructura/tipografía/spacing de **esa**, no del screenshot
4. Si el brief contradice estas reglas, FLAG en el reporte ("el brief decía X
   pero §N dice Y, opté por Y") — no aplicar X silenciosamente
