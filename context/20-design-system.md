<!-- REGLA: Design system canónico de Punto POS. Lectura OBLIGATORIA antes de
     crear o modificar componentes visuales en panel-next. Referenciá la sección
     exacta en el brief del sub-agente. -->

# 20 — Design System

> Doc vivo. Referencia canónica de patrones UI para panel-next.
> Lee `context/14-ui-conventions.md` para reglas operativas básicas de shadcn;
> este doc agrega patrones de componentes, anti-patterns explícitos y el changelog
> de decisiones de diseño.

## Tabla de contenidos

- [1. Filosofia y principios](#1-filosofia-y-principios)
- [2. Tokens](#2-tokens)
- [3. Layouts](#3-layouts)
- [4. Componentes — patrones canonicos](#4-componentes--patrones-canonicos)
  - [4.1 Botones](#41-botones)
  - [4.2 Forms](#42-forms)
  - [4.3 Modales numericos — NumericPadDialog](#43-modales-numericos--numericpaddialog)
  - [4.4 Modales de formulario](#44-modales-de-formulario)
  - [4.5 Modales de confirmacion](#45-modales-de-confirmacion)
  - [4.6 Modales split 2-col](#46-modales-split-2-col)
  - [4.7 Listados / DataTable](#47-listados--datatable)
  - [4.8 Pickers](#48-pickers)
  - [4.9 Cards](#49-cards)
  - [4.10 KPIs / Stats](#410-kpis--stats)
  - [4.11 Reportes](#411-reportes)
  - [4.12 Detalles tipo invoice](#412-detalles-tipo-invoice)
  - [4.13 Empty states](#413-empty-states)
  - [4.14 Loading states](#414-loading-states)
  - [4.15 Banners](#415-banners)
  - [4.16 Toasts](#416-toasts)
  - [4.17 Sidebar](#417-sidebar)
- [5. Patrones POS especificos](#5-patrones-pos-especificos)
- [6. Anti-patrones](#6-anti-patrones)
- [7. Changelog de decisiones](#7-changelog-de-decisiones)
- [8. Como invocar este doc](#8-como-invocar-este-doc)

---

## 1. Filosofia y principios

**Shadcn-first.** No reinventes primitives que shadcn ya provee. Todo componente UI se compone con shadcn. Prohibido `<table>`, `<button>`, `<input>`, `<label>` nativos cuando existe un primitive shadcn equivalente.

**Touch-first en POS, keyboard-first en panel.** El POS opera en tablets con cajas de alto volumen: touch targets minimo 44px (`h-11` + padding). El panel opera con teclado y mouse: densidad media, tamaños shadcn default.

**Densidad media por default.** No comprimas espaciado para meter mas info. No agrandes para parecer "moderno". El default de shadcn es correcto.

**Disenar desde cero, no copiar legacy.** El POS y panel legacy son referencia funcional: que campos, que flujos, que edge cases. No son referencia visual. Tamaños, paddings, colores y tipografia vienen del design system shadcn + lo que ya esta en panel-next.

El caso del modal de transacciones ilustra el costo de no seguir esta regla: tuvo 3 iteraciones (stat cards → stat cards restyle → invoice pattern) porque se arranco copiando la estructura del legacy en lugar de disenar desde cero con los patrones canónicos.

**Multi-vertical.** El copy debe ser neutral. No usar "vendedor" (usar "usuario" u "operador"), no usar "mozo" (usar "asignado"), no usar "factura" cuando aplica (usar "comprobante"). Los strings de UI pueden vivir en verticales de gastronomia, retail, servicio tecnico, etc.

**Cuando hay duda, mirar legacy SOLO para entender la FUNCION.** Que dato se muestra, que accion se puede tomar, que estados existen. La forma visual siempre sale de panel-next.

---

## 2. Tokens

### Colores semanticos

Usa solo tokens del design system. Prohibido hex hardcodeado salvo excepciones documentadas.

| Token | Cuando usarlo |
|---|---|
| `background` | Fondo de pagina / dialog |
| `foreground` | Texto principal |
| `card` / `card-foreground` | Superficie de cards y su texto |
| `muted` | Fondo de secciones secundarias |
| `muted-foreground` | Labels, hints, timestamps, metadata |
| `primary` / `primary-foreground` | Accion principal, boton default |
| `destructive` / `destructive-foreground` | Eliminar, anular, error |
| `border` | Divisores, bordes de input |
| `input` | Borde especifico de inputs |
| `ring` | Focus ring |
| `accent` / `accent-foreground` | Hover states, seleccion activa en lista |

**Excepciones documentadas:**
- `bg-amber-500 text-white` → solo si el owner pide un badge de alerta fuera de la paleta semantica y lo documenta inline
- `text-emerald-600` → estado "Pagado" en credito (ver §4.12) — no hay token semantico equivalente a "exito positivo"
- Colores de categoria custom: vienen del JSONB del item, se renderizan como `style={{ backgroundColor: color }}`

**Prohibido:**
- Hex hardcodeado (`#22252A`, `#ffffff`, etc.) sin comentario de justificacion
- `text-white` como alternativa a `text-destructive-foreground` o `text-primary-foreground`

### Tipografia canonica

| Uso | Canon |
|---|---|
| Titulo de pagina | `<h1 className="text-2xl font-semibold">` |
| Titulo de seccion | `<h2 className="text-xl font-semibold">` |
| Titulo de subseccion | `<h3 className="text-base font-semibold">` |
| Texto principal | `text-sm` (default sin override) |
| Texto secundario | `text-sm text-muted-foreground` |
| Caption / timestamp | `text-xs text-muted-foreground` |
| Numeros | `tabular-nums` (siempre en montos, fechas, cantidades) |

**Nota sobre titulos de modal:** el titulo de un `<Dialog>` se trata como titulo de pantalla (`text-2xl font-semibold`). Anti-patron: `text-base font-semibold` para titulos de modal (detectado 2026-06-24).

### Spacing

| Contexto | Clase |
|---|---|
| Gap entre bloques de pagina | `gap-6` |
| Gap entre secciones | `gap-4` |
| Gap entre elementos de form group | `gap-3` |
| Gap entre chips / badges inline | `gap-2` |
| Padding interno por default | `p-4` |
| Padding en bloques grandes | `p-6` |

### Iconos

`lucide-react`. Tamaños:

| Contexto | Clase |
|---|---|
| Inline en body / boton con texto | `size-4` |
| Header de seccion | `size-5` |
| Empty state | `size-6` o `size-8` |
| Badge / chip chico | `size-3.5` |

**REGLA DURA:** los iconos solo van en:
1. Menu principal (sidebar nav) — caso unico donde son obligatorios
2. Botones de accion icon-only (`size="icon"`) — ej. close X, search, dropdown trigger
3. Empty states

**Prohibido:**
- Iconos en titulos de pagina (h1/h2/h3)
- Iconos en headers de `<Card>`
- Iconos en headers de `<Dialog>` / `<DialogHeader>`
- Iconos decorativos en cards de contenido

### Formato de números/montos/fechas

SIEMPRE via helpers que consumen `config` del tenant:

- `formatMoney(value, config)` — monto con prefix de moneda del tenant (ej. "Gs 1.500.000")
- `formatAmount(value, config)` — número con separadores del tenant sin prefix de moneda
- `niceDate(date, config)` — fecha formateada según preferencias del tenant

**Prohibido:**
- `value.toLocaleString()` sin locale explícito
- `toFixed(2)` para montos (puede romper si el tenant usa otros decimales)
- String interpolation directa de números crudos (ej. `` `Gs ${total}` ``)

---

## 3. Layouts

### Modales — escala de tamaños

| Bucket | Clase | Cuando usarlo |
|---|---|---|
| `xs` | `sm:max-w-md` | Confirmacion / alert (1-2 lineas + 2 botones). Ej. `<AlertDialog>` de eliminar. NumericPadDialog. |
| `sm` | `sm:max-w-lg` | shadcn default. Form de un solo campo chico. Casi nunca. |
| **`m`** | **`sm:max-w-2xl`** | **DEFAULT del proyecto.** Form tipico, dialog con varios campos, edicion simple. Arranca siempre aca. |
| `l` | `sm:max-w-4xl` | Listados, tablas, formularios complejos en 2 columnas |
| `xl` | `sm:max-w-6xl` | Modal split 2-col (lista + detalle), dashboards, vistas amplias |

**Regla operativa:**
- Arranca siempre en `m` (`sm:max-w-2xl`). No el `sm:max-w-lg` que viene de shadcn por default.
- Sube el tamano solo si el contenido lo pide.
- Baja a `xs` solo para alerts / NumericPadDialog.
- Alto: deja al contenido. Solo usar `max-h-[Xvh]` con `overflow-y-auto` si el contenido puede exceder el viewport (ej. split 2-col: `max-h-[80vh]`).

Anti-patron: `max-w-[95vw] w-[95vw] h-[90vh]` hardcodeado (detectado 2026-06-24 en modal de transacciones).

### Paginas

```tsx
<div className="max-w-7xl mx-auto px-4 py-6">
  <div className="flex flex-col gap-6">
    <header>
      <h1 className="text-2xl font-semibold">Titulo de pagina</h1>
    </header>
    {/* contenido */}
  </div>
</div>
```

### Sidebars

`shadcn Sidebar` primitive. Dos variantes en uso:
- `PanelSidebar`: navegacion completa del panel (items con icono + label)
- `PosSidebar`: minimal, 4-5 items, solo visible cuando el POS esta en un layout sin AppSidebar

---

## 4. Componentes — patrones canonicos

### 4.1 Botones

**Variantes:**
- `default` — accion primaria
- `outline` — accion secundaria / cancelar
- `destructive` — eliminar / accion irreversible
- `ghost` — accion terciaria, menú contextual

**Tamaños:**
- `size="default"` — default en paginas
- `size="sm"` — dentro de cards, rows de listado, headers de modal compact
- `size="lg"` — accion principal de modal (ej. "Aceptar" en NumericPadDialog)
- `size="icon"` — boton de accion sin texto (close, more, search)

**Jerarquia en DialogFooter:**
- Cancelar → `variant="outline"` a la izquierda
- Confirmar → `variant="default"` a la derecha
- Para DialogFooter usar el componente `<DialogFooter>` de shadcn (right-aligned por default)

**Para acciones destructivas:** usar `<AlertDialog>` + `<AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90">`

**Anti-pattern:**
- `text-white` hardcodeado en boton destructivo (usar `text-destructive-foreground`)
- `flex-1` en ambos botones del footer (splits el ancho — anti-patron visual)
- `className="h-8 w-8 p-0"` custom en lugar de `size="icon"`

### 4.2 Forms

Tres patrones segun complejidad:

| Caso | Pattern |
|---|---|
| 1-2 campos, sin validacion compleja | `<Input>` + `<Label>` sueltos, `gap-3` entre fields |
| Form con validacion | `shadcn Form` + `react-hook-form` + `zod` |
| Monto | `<MoneyInput>` (respeta `bootstrap.thousand`/`decimal` del tenant) |
| Telefono | `<PhoneInput>` con `libphonenumber-js` |
| Rango de fecha | `<Calendar>` + `<Popover>` |

Field stacking: vertical, `gap-3`.

**Anti-pattern:**
- `<input type="number">` para montos o precios (usar `<MoneyInput>`)
- `<table>` como layout de form (usar `flex flex-col gap-3`)

### 4.3 Modales numericos — NumericPadDialog

Ruta: `panel-next/components/pos/numeric-pad-dialog.tsx`

Pattern canonico (snippet real):

```tsx
<Dialog open={open} onOpenChange={(v) => !v && onClose()}>
  <DialogContent className="sm:max-w-md p-0 gap-0">
    {/* Header: title izquierda + mode label top-right */}
    <div className="flex items-center justify-between border-b px-6 py-4">
      <h2 className="text-lg font-semibold">{title}</h2>
      {modeLabelTopRight && (
        <span className="text-sm text-muted-foreground tabular-nums">
          {modeLabelTopRight}
        </span>
      )}
    </div>

    {/* Body: numpad */}
    <div className="px-6 py-6">
      <NumericPad
        mode={mode}
        value={value}
        onChange={onValueChange}
        onShiftToggle={onShiftToggle}
        onConfirm={onConfirm}
        onCancel={onClose}
      />
    </div>

    {/* Footer: botón único Aceptar full-width */}
    <div className="border-t px-6 py-4">
      <Button onClick={onConfirm} className="w-full" size="lg">
        {confirmLabel}
      </Button>
    </div>
  </DialogContent>
</Dialog>
```

Reglas:
- Bucket `xs` (`sm:max-w-md`), `p-0 gap-0` — el padding va en cada bloque interno
- Header: `flex justify-between`, titulo izquierda (`text-lg font-semibold`), mode label top-right (`text-sm muted-foreground tabular-nums`)
- Mode label top-right: `".00"` para decimal, `"%"` para percent, `null` para int/money
- Footer: `border-t` + `<Button className="w-full" size="lg">`
- `subtitle` prop esta DEPRECADO — se mantiene por compat pero no se renderiza
- Modos soportados: `"int"` | `"decimal"` | `"money"` | `"percent"`
- Hotkeys internos: numeros, `.`, `Backspace`, `Enter` (confirmar), `ESC` (cerrar), `Shift` (toggle modo)

**Anti-pattern:**
- Agregar `subtitle` visible (deprecado desde commit 532b36f)
- Poner el total/valor como "hero" grande centrado en el header
- Boton footer con ancho parcial

### 4.4 Modales de formulario

| Contenido | Bucket | Footer |
|---|---|---|
| 1 input | `xs` | Autofocus en input, Enter = submit, boton "Confirmar" full-width o DialogFooter minimal |
| Varios inputs | `m` (default) | `<DialogFooter>`: Cancelar outline + Confirmar default, right-aligned |

**Anti-pattern:**
- `flex-1` en ambos botones del footer
- Dialog sin `DialogTitle` (accesibilidad)

### 4.5 Modales de confirmacion

- Destructivo (eliminar, anular, vaciar) → `<AlertDialog>`
- Neutral (info, form corto) → `<Dialog>` normal

```tsx
// Accion destructiva en AlertDialog
<AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
  Eliminar
</AlertDialogAction>
```

**Anti-pattern:** `text-white` en `AlertDialogAction` (usar `text-destructive-foreground`)

### 4.6 Modales split 2-col

Bucket `xl` (`sm:max-w-6xl`). Grid `grid-cols-[1fr_1.2fr]`, `max-h-[80vh]`.

Caso canonico: `PosTransactionsDialog` (`panel-next/components/register/pos-transactions-dialog.tsx`)

```tsx
<DialogContent className="sm:max-w-6xl p-0 gap-0 overflow-hidden">
  <DialogHeader className="px-6 pt-6 pb-3 border-b">
    <DialogTitle className="text-2xl font-semibold">Transacciones</DialogTitle>
    <DialogDescription className="text-sm text-muted-foreground">
      Ultimas operaciones del comercio...
    </DialogDescription>
  </DialogHeader>
  <div className="grid grid-cols-[1fr_1.2fr] max-h-[80vh] min-h-0">
    <ListaIzquierda />  {/* border-r, filtros sticky + lista scrollable */}
    <DetallesDerecha />  {/* border-l, overflow-y-auto */}
  </div>
</DialogContent>
```

Estructura de la columna izquierda:
- `flex flex-col h-full min-h-0 border-r overflow-hidden`
- Filtros sticky: `shrink-0 bg-background border-b px-4 pt-3 pb-3`
- Lista scrollable: `flex-1 overflow-y-auto`

Estructura de la columna derecha:
- `flex flex-col h-full min-h-0 border-l overflow-hidden`
- Contenido: `flex-1 overflow-y-auto p-5`

### 4.7 Listados / DataTable

**Obligatorio para listados >= 10 items:**
- Usar `<DataTable>` de `@/components/data-table/data-table`
- Columnas requeridas: search input top, sort por col, date-range filter, export XLSX, column-toggle

**Para listados < 10 items en panel de detalle:**
- Lista vertical `<div className="divide-y">` — NO usar `<DataTable>`

**Anti-pattern:**
- `<table>` one-off custom
- Pagina de listado sin DataTable cuando hay mas de 10 rows

### 4.8 Pickers

Patron comun para UserPicker, CustomerPicker, ItemPicker:
- Bucket `sm` o `m` segun el contenido
- Header con `<Input>` de busqueda con `autoFocus`
- Body: lista vertical con `Avatar`/icono + nombre + descripcion opcional
- `<EmptyState>` cuando no hay match

Copy neutral: "Asignar usuario", "Seleccionar cliente" (no "Asignar vendedor").

### 4.9 Cards

**Cuando SI usar `<Card>`:**
- Agrupar secciones de form en settings/team
- Wrapper de bloques visuales en dashboards
- Contenedor de subseccion con `<CardHeader>` + `<CardContent>`

**Cuando NO usar `<Card>`:**
- Stat cards con iconos grandes arriba de listados
- Envolver cada row de un listado
- Envolver formularios simples que no necesitan agrupacion visual

**REGLA:** Los cards NO llevan icono en el `<CardHeader>`. Si el card es un "stat" solo mostrá label + valor grande + delta opcional, sin icono.

### 4.10 KPIs / Stats

Para stats inline en headers de pagina:

```tsx
<h1 className="text-2xl font-semibold">
  Reportes{" "}
  <span className="text-muted-foreground">· Gs 2.5M este mes</span>
</h1>
```

Para grilla de stats en dashboard:
- Grid de cards SIN icono
- Label arriba (`text-xs text-muted-foreground`), valor grande abajo (`text-2xl font-semibold tabular-nums`), delta opcional en `text-sm`
- NUNCA labels uppercase + `tracking-wider` en stats

### 4.11 Reportes

Estructura de pagina de reporte:
- Header: titulo + DateRange + filters + export button
- Body: `<DataTable>`
- Sin stat cards arriba del DataTable (decision 2026-06-24)

### 4.12 Detalles tipo invoice

Caso canonico: `TransactionDetail` en `panel-next/components/register/pos-transactions-dialog.tsx`

Estructura (snippets reales):

```tsx
{/* Cabecera 2-col: Cliente + Detalles */}
<div className="grid grid-cols-2 gap-6 mt-4">
  <div>
    <p className="text-xs font-medium text-muted-foreground mb-1">Cliente</p>
    <p className="text-sm">{detail.customerName || <span className="text-muted-foreground">Sin asignar</span>}</p>
  </div>
  <div>
    <p className="text-xs font-medium text-muted-foreground mb-1">Detalles</p>
    <dl className="text-sm space-y-1">
      <div className="flex justify-between gap-4">
        <dt className="text-muted-foreground">Fecha</dt>
        <dd className="tabular-nums">{formattedDate}</dd>
      </div>
    </dl>
  </div>
</div>

<Separator className="my-4" />

{/* Tabla de items */}
<Table>
  <TableHeader>
    <TableRow>
      <TableHead>Concepto</TableHead>
      <TableHead className="w-16 text-right">Cant.</TableHead>
      <TableHead className="text-right">Importe</TableHead>
    </TableRow>
  </TableHeader>
  <TableBody>
    {items.map((item, idx) => (
      <TableRow key={`${item.itemId}-${idx}`}>
        <TableCell>{item.name}</TableCell>
        <TableCell className="text-right tabular-nums">{item.count}</TableCell>
        <TableCell className="text-right tabular-nums">Gs {formatMoney(item.total, config)}</TableCell>
      </TableRow>
    ))}
  </TableBody>
</Table>

<Separator className="my-4" />

{/* Totals right-aligned */}
<div className="ml-auto max-w-xs">
  <dl className="space-y-1 text-sm">
    {discount > 0 && (
      <>
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">Subtotal</dt>
          <dd className="tabular-nums">Gs {formatMoney(subtotal, config)}</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt className="text-muted-foreground">Descuento</dt>
          <dd className="tabular-nums text-destructive">-Gs {formatMoney(discount, config)}</dd>
        </div>
      </>
    )}
  </dl>
  <Separator className="my-2" />
  <div className="flex justify-between gap-4 text-sm font-semibold">
    <span>Total</span>
    <span className="tabular-nums">Gs {formatMoney(total, config)}</span>
  </div>
</div>

{/* Status final — solo si hay estado especial */}
{isCredit && debt > 0 && (
  <div className="flex justify-between items-center mt-4">
    <span className="text-sm font-medium">Pendiente</span>
    <span className="text-base font-semibold tabular-nums text-destructive">
      Gs {formatMoney(debt, config)}
    </span>
  </div>
)}
```

Reglas de invoice detail:
- Header 2-col: cliente izquierda, metadata derecha
- `<dl>` para pares label/valor
- `<Separator>` entre header e items, entre items y totals, antes de status final
- Totals right-aligned (`ml-auto max-w-xs`)
- Status final: solo si hay credito con deuda o estado especial — al pie, sin badge
- NO badge tipo arriba
- NO total como "hero" gigante
- NO iconos en el detalle

### 4.13 Empty states

Usar `<EmptyState>` de `@/components/empty-state`:

```tsx
<EmptyState
  icon={Receipt}
  title="Sin transacciones"
  description="Cuando hagas ventas aparecerán acá."
/>
```

Los empty states son la unica excepcion donde los iconos van fuera del sidebar.

### 4.14 Loading states

| Caso | Pattern |
|---|---|
| Listado cargando | `<Skeleton>` (NO spinner) |
| Boton submit | `<Loader2 className="size-4 animate-spin" />` |
| POS bootstrap | `PosLoadingScreen` dedicada |

```tsx
// Boton con loading state
<Button disabled={isLoading}>
  {isLoading && <Loader2 className="size-4 animate-spin" />}
  Guardar
</Button>
```

### 4.15 Banners

Para warnings transversales (offline, configuracion faltante):
- Sticky top
- Tonos: `amber` (warning), info (default) — NO destructive para banners transversales
- Caso canonico: `OfflineBanner` en `panel-next/components/pos/offline-banner.tsx`

### 4.16 Toasts

`sonner` (ya configurado en el layout).

```tsx
toast.success("Items duplicados al carrito")
toast.error("La transaccion no tiene items para duplicar")
toast.info("Reimprimir #123 — abriendo vista de impresion...")
```

Reglas:
- Sin emojis
- Menos de 60 caracteres
- Imperativo cortés o descriptivo directo
- NO usar para errores de validacion de form (usar inline error)

### 4.17 Sidebar

`shadcn Sidebar` primitive. Items con prop `requires?: string` para permiso — filtrar al renderear.

Iconos SI (caso permitido — el sidebar es el unico lugar donde los iconos van siempre). Active state automatico via pathname.

---

## 5. Patrones POS especificos

### Touch targets

Minimo 44px de alto. Usar `h-11` + padding en botones principales del carrito y del grid de articulos.

```tsx
// Documentar inline cuando se sobreescribe el default de shadcn
<Button className="h-12 w-full" /* h-12 porque boton se opera con dedo en tablet */>
  Cobrar
</Button>
```

### Hotkeys globales del POS

`panel-next/hooks/use-pos-hotkeys.ts`

| Tecla | Accion |
|---|---|
| `Q` | Menu del POS |
| `W` | Buscador de articulos |
| `E` | Buscador de clientes |
| `R` | Menu de opciones de venta |
| `Enter` | Cobrar (si hay items en el carrito) |
| `ESC` | Lo manejan los overlays shadcn |

### Escape hatch para dialogs (OBLIGATORIO en nuevos atajos globales)

Antes de disparar cualquier atajo global, verificar que no haya un Dialog/AlertDialog de shadcn abierto:

```ts
// Escape-hatch general: si HAY cualquier shadcn Dialog/Sheet/AlertDialog
// abierto (descuento, qty, precio, titulo guardar, etc.), el atajo global
// NO debe disparar. Detecta via role/state de Radix (data-state="open").
if (typeof document !== "undefined" &&
    document.querySelector('[role="dialog"][data-state="open"], [role="alertdialog"][data-state="open"]')) {
  return
}
```

Todo atajo global nuevo debe incluir este check. Omitirlo significa que el atajo dispara mientras el usuario esta completando un form en un modal.

### LockScreen

PIN local (SHA-256 via Web Crypto API). Prohibido bcrypt en el browser.

### Sesion POS

Cookie `_jwt` (10 años). Separado del panel (`_jwt_panel`, 24h). No mezclar las rutas de login/logout.

---

## 6. Anti-patrones

| Anti-pattern | Por que | Alternativa | Caso real |
|---|---|---|---|
| Copiar estructura del legacy BS3 con shadcn primitives | Genera 3+ iteraciones de restyling | Disenar desde cero con patrones de panel-next | Modal transacciones POS (2026-06-24) |
| Stat cards con uppercase labels + tracking-wider | Replica el legacy, rompe el design system | `text-xs text-muted-foreground` sin uppercase | Varios — 2026-06-24 |
| `<table>` one-off custom | Inconsistente con DataTable, no tiene sort/export/search | `<DataTable>` para listas largas, `<Table>` shadcn para tablas cortas embebidas | — |
| `text-white` hardcodeado en boton destructivo | No respeta dark mode, no usa tokens semanticos | `text-destructive-foreground` | `cart-panel.tsx` — badge amber con `text-white` |
| Atajos globales sin escape hatch para dialogs | El atajo dispara mientras el usuario llena un form en un modal | Agregar el querySelector check (ver §5) | — |
| Terminologia verticalizada en strings genericos ("vendedor", "mozo") | Punto sirve multiples verticales | "usuario", "operador", "asignado" | Rename 2026-06-25 (commit 8b69da1) |
| `<Card>` con icono en header | Va en contra de la regla de iconos del design system | Card sin icono, solo label + valor | Regla owner 2026-06-25 |
| `<Card>` envolviendo cada row de listado | Peso visual innecesario, fragmenta la lista | `<div className="divide-y">` o DataTable | — |
| Interpolation sin validar array de pagos | "Pagado en null" en produccion | Validar `payments.length > 0` antes de iterar | POS 2026-06-24 |
| `flex-1` en ambos botones del DialogFooter | Ocupa todo el ancho — anti-patron visual | `<DialogFooter>` shadcn (right-aligned por default) | — |
| Iconos en headers de Card / titulos de seccion | Violacion de la regla de iconos | Sin icono, solo texto | Regla owner 2026-06-25 |
| `subtitle` en NumericPadDialog | Prop deprecada, no se renderiza | Omitir — usar solo `title` | Deprecado en commit 532b36f |
| PIN con bcrypt en browser | bcrypt es server-side; el browser no lo soporta natively | SHA-256 via Web Crypto API | — |
| Casting `(array) $rs->fields` en PHP | `$rs->fields` es un `CaseInsensitiveArray`, no un array PHP nativo | Acceder por nombre de columna: `$rs->fields['columna']` | Bug 2026-06-18 |
| `try/catch` que swallows excepciones en migration scripts | La migracion falla silenciosamente | Re-throw o log + abort | — |
| `max-w-[Xvw] w-[Xvw] h-[Xvh]` hardcodeado en Dialog | Replica el legacy, no usa la escala canonica | Usar la escala xs/sm/m/l/xl de §3 | Modal transacciones 2026-06-24 |
| Mostrar números/montos/fechas sin formato del tenant | Cada tenant tiene config propia (thousand sep, decimal sep, fecha format, moneda) | Usar helpers: `formatMoney(x, config)`, `formatAmount(x, config)`, `niceDate(date, config)`. NUNCA `toLocaleString()` sin locale, NUNCA concatenar moneda inline | hydration #418 commit 2fcbc2f |

---

## 7. Changelog de decisiones

| Fecha | Decision | Commit | Razon |
|---|---|---|---|
| 2026-06-25 | Regla: iconos SOLO en sidebar nav, botones icon-only y empty states. Prohibido en cards, titulos, headers de modal | — | Regla explicitada por owner |
| 2026-06-25 | NumericPadDialog unificado al pattern legacy: title + mode label en header, unidad inline en display, Aceptar full-width en footer. `subtitle` deprecado | 532b36f | 3 iteraciones para llegar al pattern correcto |
| 2026-06-25 | Rename "vendedor" → "usuario" / terminologia vertical-neutral en strings de UI | 8b69da1 | Punto sirve multiples verticales |
| 2026-06-25 | Escape hatch global para atajos POS cuando hay dialog shadcn abierto | 9ff3885 | Atajos disparaban mientras el usuario completaba un form en un modal |
| 2026-06-24 | Redesign invoice-style de detalle de transacciones: NO stat cards, tabla de items canonica, totals right-aligned, status final al pie, sin badge de tipo arriba | 5c30c2a | 3 iteraciones: stat cards → stat cards restyle → invoice pattern correcto |
| 2026-06-24 | Sin stat cards arriba de listados en paginas de reportes | — | Simplificacion post-planning; el DataTable tiene toda la info |
| 2026-06-24 | Roles seed = 3 (Dueno/Encargado/Cajero) | — | Simplificacion post-planning; el modelo de 5 roles era demasiado granular |

---

## 8. Como invocar este doc

**En briefs de sub-agentes:**

- Referencia puntual: "Seguí el patron §4.3 (NumericPadDialog)" o "Ver §4.6 para split 2-col"
- Para cambios cross-cutting (mas de 3 secciones): "Lee `context/20-design-system.md` completo antes de tocar JSX"
- Los sub-agentes deben FLAGEAR en el reporte si el brief contradice algo de este doc ("el brief decia X pero §N dice Y, opte por Y")

**Relacion con otros docs:**
- `context/14-ui-conventions.md` — reglas operativas de shadcn (tipografia, spacing, listados, formatos). Lee ese primero si es tu primera vez en el proyecto.
- `context/11-design-system.md` — manual de marca (colores de marca, logo, tipografia corporativa)
- Este doc (20) extiende el 14 con patrones de componentes concretos y el historial de decisiones de diseno
