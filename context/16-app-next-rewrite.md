# 16 — Plan: rewrite del POS (/app → app-next) a React/Next + shadcn

> **Creado:** 2026-06-15. **Decisión del owner:** el POS legacy (`/app`,
> jQuery + Bootstrap 3 + 26.5k L en un `app.js`) se reescribe greenfield en
> `app-next/`, mismo stack que `panel-next`. Análogo al rewrite del panel
> (`context/12`). La feasibility y los subsistemas están analizados en
> `context/14`; las decisiones fiscales/numeración en `context/14 §9`.

---

## 0. TL;DR

- **Greenfield** en `app-next/` (Next 15 + TS + shadcn + Tailwind + TanStack
  Query), app **separada** de panel-next (PWA, hardware, necesidades distintas),
  consumiendo la **misma `/api`** (el SaleService ya está hecho e idempotente).
- **UX = la del `/app` legacy, restyle con shadcn + colores de marca.** Mismos
  flujos y velocidad de caja; cambia el look, no la experiencia operativa.
- **Arranca por la base de caja**: facturación **contado/crédito** + **CRUD de
  clientes** + búsqueda de productos + cobro + impresión. Es el núcleo del POS.
- **Online-first**, pero con la capa de datos diseñada para que **eventualmente
  SOLO** venta contado/crédito + alta de clientes pasen a offline; **todo lo
  demás es online obligatorio** (regla de producto — ver memoria
  `project_offline_scope`).

---

## 1. Contexto

`context/14` ya estableció:
- El backend de ventas está **modernizado y desacoplado**: `SaleService`
  (`api/lib/Sales/SaleService.php`) es idempotente por `transactionUID` (UNIQUE +
  dup-check), valida cliente anti-IDOR, transaccional. El `/app` legacy ya postea
  ventas simples a `bff/sales` → `api/v1/sales.php`.
- La `/api` compartida ya sirve items, contacts, bootstrap, reports, etc. con
  `apiAuthTenant(['panel','pos-app'])` → **un cliente React puede pegar hoy sin
  esperar nada del backend** (igual que panel-next).
- El POS **no está en producción** (`context/01`) → corte limpio, sin migración
  de clientes ni compat shims.

Lo que hace rápida la caja legacy (a replicar): **catálogo completo en memoria +
búsqueda local instantánea + cobro optimista**. NO es jQuery — es el modelo de
datos. Ver `context/14 §2`.

---

## 2. Principios

1. **Fidelidad VISUAL ESTRUCTURAL con el legacy (lo más importante).** El objetivo
   es **migración natural sin re-aprendizaje**: un cajero de la caja vieja, al abrir
   la nueva, ya sabe dónde está todo y cómo operar — memoria muscular intacta.
   - Misma **estructura visual y disposición** de cada pantalla: dónde está el
     carrito, las categorías, el numpad, los botones de acción, el cobro, la
     búsqueda. Mismas posiciones, mismo orden, mismos flujos de clicks/teclas.
   - Lo que cambia es **solo el estilo**: shadcn + paleta de marca (`context/11`,
     skill `brand-manual`, verde Punto `#01D7A1` en accents/charts como panel-next),
     tipografía, espaciados, componentes. NO se reubican elementos ni se cambian
     flujos. Si hay duda entre "más lindo/moderno" y "igual que el legacy", **gana
     igual que el legacy**.
   - Método: tomar cada pantalla legacy como **referencia visual 1:1** (screenshots
     / el HTML de `app/index.php` + render real) y reconstruirla con shadcn
     respetando layout, no inventar.
2. **Velocidad de caja primero.** Catálogo en memoria + búsqueda síncrona +
   teclado-first. Cero round-trips para buscar un producto o un cliente.
3. **Online-first con frontera offline explícita** (§5). Hoy todo online; mañana
   solo venta contado/crédito + alta cliente offline.
4. **Backend ya hecho.** Reusar `/api` vía BFF propio (§4); no reescribir backend
   salvo gaps puntuales.
5. **Mismas reglas que panel-next** (§9): shadcn-first, `MoneyInput`, teléfonos
   E.164, `DataTable`, `npm run build` antes de pushear, etc.

---

## 3. Stack

Idéntico a `panel-next` (`context/12 §Stack`):

| Componente | Tecnología |
|---|---|
| Framework | Next.js 15 (App Router), `app-next/` |
| Lenguaje | TypeScript estricto (`strict`, `noUncheckedIndexedAccess`) |
| UI | shadcn/ui + Tailwind CSS 4 |
| Data server-state | TanStack Query 5 |
| Forms | react-hook-form + Zod |
| Estado UI/local | Zustand (carrito, sesión de caja, store en memoria) |
| Offline store (futuro) | IndexedDB vía Dexie (solo slice offline-eligible) |
| PWA | next-pwa / Workbox (cuando se active offline) |
| Hardware | QZ Tray (cliente tipado portado) + builder de ticket portado |
| Real-time | `ncm-ws` portado a TS (cliente WS, hook `useChannel`) |

Auth: cookie `_jwt` (realm `pos-app`), handoff JWT desde el panel (`app/handoff.php`
equivalente). BFF same-origin como panel-next (`app/api/v1/[...path]` → `/api`).

---

## 4. Arquitectura — Front → BFF → API → BD (separado por dominio)

Patrón canónico del proyecto: **cada dominio tiene su Front + su BFF; todos
comparten la misma API y la misma BD.** El BFF **formatea la data según lo que su
front necesita**. El front **nunca** pega directo a la `/api` principal ni se le
exponen sus endpoints crudos.

```
[Front app-next]  →  [BFF app-next]  →  [/api compartida]  →  [BD]
 React/shadcn         Next route          PHP (SaleService,
 (POS)                handlers            items, contacts…)
                      app/api/v1/[...]    apiAuthTenant(['pos-app'])
                      reshape POS
```
(Igual que: panel tiene front+BFF, ecommerce front+BFF, app móvil front+BFF — todos
sobre la misma `/api` + BD.)

```
app-next/  (Next 15 PWA — separada de panel-next, comparte /api y UI shadcn)
  app/(pos)/...           ← register, pay, customers, transactions, drawer, tables…
  app/api/...            ← ★ BFF del POS (Next route handlers). Reciben del front,
                            llaman a /api compartida, RESHAPEAN al shape que la
                            caja necesita (ej. /api/pos/bootstrap compone items+
                            customers+config+registers en una respuesta lista para
                            el store). El front solo habla con este BFF (same-origin).
  lib/api/                ← cliente del front → BFF app-next (NO a /api directo)
  lib/catalog/            ← store en memoria (Zustand) de productos/clientes/config
    search.ts             ← índice de búsqueda local (instantáneo)
  lib/commands/           ← capa de mutaciones con FRONTERA offline (§5)
    registry.ts           ← marca qué comandos son offline-eligible
    create-sale.ts        ← venta contado/crédito (offline-eligible)
    create-customer.ts    ← alta cliente (offline-eligible)
    ...                   ← el resto: online-only
  lib/hardware/
    qz-tray.ts            ← cliente QZ tipado (portado de app.js)
    ticket-builder.ts     ← builder de string del ticket (portado)
    barcode-scanner.ts    ← hook keyboard-wedge
  lib/realtime/ncm-ws.ts  ← cliente WS portado + useChannel
```

- **App separada** (no dentro de panel-next): PWA offline-first parcial, hardware,
  pantalla de caja full-screen, ciclo de vida distinto.
- **BFF propio del POS** (D2): endpoints REST nuevos en el BFF de app-next que
  reshapean la `/api`. NO se exponen los endpoints crudos de `/api` al front, NO se
  reusa `fetchs.php` legacy. Ventas → el BFF reenvía a `api/v1/sales.php`
  (SaleService). Bootstrap del catálogo → `app/api/pos/bootstrap` compone lo que
  el store necesita.
- **Loopback in-container** para el BFF→API (mismo aprendizaje que el fix del BFF
  legacy, commit 1532fbb): `localhost:3000` + Host header, no salir a Cloudflare.

---

## 5. La frontera offline (diseñada ahora, activada después)

**Hoy: todo online.** Pero la capa de datos se diseña para que el día de mañana
**solo** estas operaciones funcionen offline (regla `project_offline_scope`):

| Operación | Offline-eligible (futuro) |
|---|---|
| Venta **contado** (type 0) | ✅ |
| Venta **crédito** (type 3) | ✅ |
| **Alta/edición de cliente** | ✅ |
| Búsqueda de productos/clientes (lectura) | ✅ (catálogo en memoria) |
| Todo lo demás (mesas, órdenes, reservas, arqueo, reportes, gift cards, devoluciones, cotizaciones, transferencias, settings…) | ❌ online obligatorio |

**Cómo se diseña para eso desde el día 1 (sin construir el offline todavía):**
- **Capa de comandos** (`lib/commands/`): toda mutación pasa por un comando con
  payload idempotente (UUID v7 client-side, que el `SaleService` ya deduplica).
  Un `registry` marca `{createSale, createCustomer}` como offline-eligible. Hoy
  todos ejecutan online; mañana, un interceptor encola SOLO los eligibles cuando
  no hay conexión, y los demás fallan duro con "requiere conexión".
- **Catálogo desacoplado de la red**: productos/clientes/impuestos/config viven en
  un store en memoria (`lib/catalog/`) con búsqueda local. Hoy se hidrata por
  fetch; mañana se persiste en IndexedDB (Dexie) + delta-sync. La UI lee del
  store, no de la red → cambiar la fuente no toca la UI.
- **Numeración**: cuando se active offline, las ventas usan el **lease +
  incremento estricto** de `context/14 §9` (online-only para el resto). Hoy,
  número server-side atómico.
- **No** construir SW/IndexedDB/cola ahora. Solo respetar estas costuras para que
  agregarlo sea una fase, no un re-rewrite.

---

## 6. Inventario de pantallas legacy → mapeo

(De `context/14 §1.3` — ~20 vistas + 8 subvistas de cliente. Routing por hash en
legacy → rutas App Router en app-next.)

| Grupo | Pantallas legacy | app-next |
|---|---|---|
| **Caja core (foco inicial)** | register (venta), pay (cobro), items/búsqueda, customer CRUD (8 subvistas), itemInfo, transactions/reimpresión, hotkeys | Slice A–C |
| Caja extendida | drawer (arqueo), openSaved (guardadas), openQuotes (cotizaciones), devoluciones (modo carrito), loadGiftcard | online |
| Restaurante | tables_module, viewTable, orders_module, schedule_module (`context/15`) | online, post-base |
| Sistema | settings, menu, lock/login, tutorial | según necesidad |

10 tipos de transacción (0,2,3,5,6,9,10,11,12,13) — el foco inicial es **0
(contado) y 3 (crédito)**.

---

## 6.1 Referencia visual por pantalla (ESTRUCTURA a replicar)

> Transcrito de los screenshots del POS legacy (sesión 2026-06-15). **Replicar la
> estructura/disposición, NO el diseño** — estilo = shadcn + marca panel-next
> (`context/11`). Cada región abajo va a su componente shadcn equivalente.

### Login (PIN de usuario)
Pantalla completa, fondo claro, todo centrado vertical: logo Punto (icono de
barras) → 4 círculos del PIN → ícono de huella → "Ingrese su código de usuario"
→ nombre de empresa en bold. Minimalista, sin chrome. Entrada numérica (numpad).

### Caja / register (pantalla principal) — layout 2 columnas
**Izquierda (~70%) — grid de productos:**
- Grid de tiles de 2 tipos: (a) **categoría** = color sólido + abreviatura grande
  estilo tabla periódica ("Me", "Mi", "Be"…) + label abajo ("Menú del día"…);
  (b) **producto** = imagen de fondo + nombre overlay abajo. Slots vacíos = tiles
  placeholder gris.
- **Barra inferior horizontal scrolleable** de categorías (botón back circular +
  chips: "Bebidas con alcohol", "Promos", "Pizzas Gourmet"…).
- FAB "+" abajo-izquierda. Info de sesión abajo-izquierda: avatar + "Central ·
  Caja Principal" + "Versión x.y.z".

**Derecha (~30%) — panel de carrito:**
- Toolbar de iconos arriba: ordenar/filtros, búsqueda (lupa), cliente (persona),
  más (…).
- Cliente seleccionado: nombre + documento + X para quitar.
- Líneas del carrito: badge de cantidad circular + nombre + subtexto
  ("KgnL8 @ 5.000") + precio a la derecha.
- Línea activa: nombre grande centrado + controles **"+" / "x1" / "−" / X roja**
  (eliminar) + fila de acciones de línea (dropdown ▼, $ precio, vendedor, tag,
  comentario).
- Tacho (vaciar carrito) centro-bajo.
- Bottom: toggles **CRÉDITO / INTERNO** + contador, y **botón grande full-width de
  total verde "Gs15.000"** = cobrar.

### Buscar / Crear cliente (modal)
Barra de búsqueda flotante arriba ("Buscar clientes"). Panel "CREAR CLIENTE" en 2
secciones: **DATOS DE FACTURACIÓN** (Razón Social, RUC/N° documento + lupa de
búsqueda SET, Tipo de identificación [select], botón CREAR CLIENTE, link "Borrar
Formulario") y **DATOS PERSONALES** (Nombre y Apellido, Doc. de Identidad, E-mail,
Teléfono, Dirección, Fecha de Nacimiento — 2 columnas).

### Búsqueda de productos (modal)
Barra de búsqueda flotante grande arriba. Lista de resultados: imagen circular +
**badge de código/stock** (rojo si negativo, verde si positivo) + nombre +
subtítulo de categoría (› Minutas) + precio a la derecha. (Búsqueda instantánea
local — §8.)

### Transacciones (modal) — layout 2 columnas
**Izquierda (lista):** título "Transacciones" + filtro "Fecha"; search; filas:
nombre (o "Sin Nombre") + monto + subtexto (fecha/hora/n° comprobante) + badge de
tipo ("Contado"). Fila seleccionada resaltada.
**Derecha (detalle):** tipo + nombre cliente grande + doc; arriba-derecha n°
comprobante "#001-002-3576" + fecha + botón **DUPLICAR** + "…"; tags
(PICKUP/TARJETA). Card de ítems (nº, nombre, vendedor, precio) + Descuento +
**TOTAL** grande. Abajo tabla de pagos (Método / Identificador / Monto).

### Detalle de producto (modal) — split 2 paneles
**Izquierda** (panel de color sólido): imagen circular del producto + nombre +
**precio grande "Gs 10.000"** + SKU + badge "Producto". **Derecha** (blanco):
nombre como header + grid 2×2 de metadata (SUCURSAL, IMPUESTO, CATEGORÍA, MARCA) +
sección **INVENTARIO** (stock por depósito: Central 440 [badge], Deposito gastro
0, … Principal 440) + sección **ARCHIVOS** (adjuntos). Online (lectura de stock).

### Menú de operaciones (overlay del "…") — ops_menu
Overlay sobre el register atenuado. Nombre de empresa arriba-centro. Opciones en
columnas con separadores verticales: **Ver Control de Caja · Ver Transacciones ·
Ver Agenda · Ver Órdenes · Ver Ajustes · Bloquear o Salir (ESC)**. Abajo-izq:
usuario + rol + "Central › Caja Principal" + versión. Toolbar arriba-derecha:
tema (dark/light), ayuda (?), refresh, fullscreen, wifi/estado.

### Mesas / espacios (tables_module) — ver `context/15`
Grid de tarjetas de mesa: tile "+ Espacio" (agregar) + mesas numeradas (1…N) con
estado "Libre" (gris claro), **ocupada** (color: verde con nombre de mozo+cliente,
ej. "6 · Administrador · Gustavo"), reservada (amarillo). Algunas con nombre
("Espacio 3 · HAB 3"). Ícono de timer por mesa, menú "⋮". Panel derecho = carrito
(vacío con watermark de marca, CRÉDITO/INTERNO, total). FAB +. **(El módulo nuevo
de `context/15` reemplaza este grid fijo por sectores + plano + capacidad.)**

### Órdenes (orders_module)
Izquierda: search "Buscar órdenes activas" + filas de orden (ícono de tipo +
barra de color por estado a la izquierda + n° "#1551" + nombre/"-" + ubicación y
tiempo "Aquí, hace 5 días" / "Espacio 2, hace 12 días" + fecha de creación + "⋮").
Barra inferior de filtros con contadores: **Todos 7 · Pendientes 0 · En Espera 4 ·
En Proceso 1 · Enviado 2** + fullscreen + pin de ubicación. Derecha: carrito.

### Agenda / calendario (schedule_module)
Izquierda: timeline de día (horas 8:00–…, línea de hora actual roja punteada),
FABs flotantes a la izquierda (acciones), toggles de vista abajo (día/semana/
recursos, fecha "lunes 15 de junio", fullscreen, date-picker, prev/next).
Derecha: "ASIGNE UNA FECHA Y HORARIO" + **botón grande "Agendar"** (gradiente).

> **Las 5 primeras pantallas son la spec visual de Slice A/B** (caja core). Las 5
> extendidas (detalle producto, ops menu, mesas, órdenes, agenda) son Slice B/C —
> mesas/órdenes/agenda se rehacen según `context/15`. Criterio único: **misma
> disposición que el legacy, restyle shadcn + marca Punto** (el watermark "ENCOM"
> de los carritos vacíos se reemplaza por Punto).

---

## 7. Slices (plan de ejecución)

**Sprint 0 — Scaffold (1 sprint)**
`app-next/` con stack base, theme shadcn + marca, BFF same-origin a `/api`, auth
POS (handoff `_jwt` realm `pos-app`), layout de caja a pantalla completa, store de
catálogo en memoria + bootstrap (equivalente a los `fetchs` legacy: items,
customers, taxes, config, registers, outlets, users), capa de comandos vacía con
el registry de offline-eligibility.

**Slice A — Núcleo de caja: venta contado/crédito + CRUD clientes (el grueso)**
- **Pantalla de venta** (`register`): carrito + categorías + búsqueda local
  instantánea + numpad + barcode (keyboard-wedge) + hotkeys grid. UX espejo del
  legacy, restyle shadcn.
- **Cobro** (`pay`): contado (type 0) y crédito (type 3), multi-método,
  multi-moneda, `MoneyInput`. Emite a `api/v1/sales.php` (SaleService).
- **CRUD de clientes**: alta rápida inline + ficha/edición; teléfonos E.164
  (libphonenumber). Búsqueda local instantánea.
- **Impresión**: builder de ticket portado + cliente QZ Tray tipado + fallback
  `window.print`.
- Comandos `createSale` / `createCustomer` marcados offline-eligible (ejecutan
  online por ahora).
- **Cierre de Slice A: una caja factura contado/crédito end-to-end, da de alta
  clientes e imprime — contra la `/api` real.**

**Slice B — Caja extendida (online)**
Transacciones/reimpresión, ventas guardadas (2), cotizaciones (9), devoluciones
(6), gift cards, arqueo/cierre de caja (drawer).

**Slice C — Restaurante (online)**
Módulo de mesas nuevo (`context/15`): sectores, sesiones, órdenes, split, reservas
+ órdenes/delivery + agenda. Todo online.

**Slice D — Sistema + corte**
Settings, lock/login, tutorial. Smoke test E2E vs legacy. Flip de subdominio,
borrar `/app` legacy.

**Fase offline (diferida, post-base)**
SW + IndexedDB + cola de sync + lease de numeración (`context/14 §9`) — SOLO para
`createSale` + `createCustomer`. Activar la frontera ya diseñada en §5.

---

## 8. UX del legacy a preservar (velocidad)

No perder en el restyle:
- **Catálogo en memoria + búsqueda síncrona** (sin round-trip por tecla).
- **Teclado-first**: atajos (equivalente a mousetrap), foco persistente en
  búsqueda, Enter para agregar, numpad para cantidad/monto.
- **Barcode scan** (keyboard-wedge) y barcode pesado (báscula) — `context/14 §3.2`.
- **Hotkeys grid** (productos rápidos configurables).
- **Cobro optimista** (la sensación de “guardado al instante”), aunque hoy sea
  online — la UI confirma rápido y reconcilia.
- **Layout de una pantalla** para la venta (carrito + categorías + numpad), sin
  navegación intermedia.

---

## 9. Reglas (heredadas de panel-next — memoria del proyecto)

shadcn-first y obligatorio sobre HTML nativo · `MoneyInput` para todo monto ·
teléfonos front nacional / back E.164 (libphonenumber) · `DataTable` para listados
· `EmptyState` compartido · `npm run build` local antes de pushear (Coolify
rolbackea silente) · paleta de marca (`context/11`) · nada de Mustache (acá ni
siquiera Alpine — es React puro).

---

## 10. Coexistencia y corte

- Mientras app-next no cubra el core, `app.punto.la` sigue sirviendo el legacy.
  app-next en subdominio aparte (ej. `app-next.punto.la`) o flip cuando Slice A+B
  estén listos. Cookie `_jwt` sobre `.punto.la` compartida.
- **Corte limpio** al final (sin clientes reales que migrar): se borra `/app`
  legacy cuando app-next lo cubre. (El `type=11/12` de mesas se elimina con
  `context/15`.)

---

## 11. Decisiones abiertas

- **D1 — Subdominio → ✅ `app-next.punto.la`** en paralelo durante el refactor;
  al terminar se migra a `app.punto.la` y se borra el legacy.
- **D2 — Bootstrap del catálogo → ✅ REST nuevo vía BFF propio** (Front → BFF →
  API → BD). NO reusar `fetchs.php` legacy NI exponer endpoints crudos de `/api`.
  El BFF de app-next compone/reshapea (ej. `app/api/pos/bootstrap`) lo que el
  store del POS necesita. Ver §4.
- **D3 — Arqueo/drawer offline**: confirmado online (no entra en la frontera).
- **D4 — Mobile**: ¿app-next PWA cubre el caso mobile, o se mantiene la app
  Cordova legacy aparte? (`context/14 §3.2` D4.)
- **D5 — Cuándo activar offline**: ¿en qué milestone se construye la fase offline?
  (post Slice A+B, o más adelante.)

---

## 12. Relación con otros docs
- `context/14-app-rewrite-analysis.md` — feasibility, subsistemas, numeración (§9).
- `context/15-mesas-module-plan.md` — el módulo de mesas vive en app-next (Slice C).
- `context/12-panel-rewrite.md` — el rewrite hermano (panel); mismo stack y reglas.
- `context/11-design-system.md` + skill `brand-manual` — colores/clases de marca.
- Memoria `project_offline_scope` — frontera offline (solo venta simple + cliente).
