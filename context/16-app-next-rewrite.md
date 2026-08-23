# 16 — Plan: rewrite del POS (/app → app-next) a React/Next + shadcn

> **Creado:** 2026-06-15. **Decisión del owner:** el POS legacy (`/app`,
> jQuery + Bootstrap 3 + 26.5k L en un `app.js`) se reescribe greenfield en
> `app-next/`, mismo stack que `frontend`. Análogo al rewrite del panel
> (`context/12`). La feasibility y los subsistemas están analizados en
> `context/14`; las decisiones fiscales/numeración en `context/14 §9`.

> ⚠️ **DESACTUALIZADO desde 2026-06-16 — FUSIÓN.** El subproyecto `app-next/`
> standalone **fue eliminado**: el POS se fusionó DENTRO de `frontend` en
> `app/(pos)/pos` (un solo dominio/deploy, design system y auth `_jwt_panel`
> compartidos). Razón: el muro de seguridad real está en la API (realm +
> RBAC), no en el dominio. El sidebar del panel es contextual en `/pos`
> (módulos POS), siempre colapsado. Estado de slices: A1/A2/A3/A6 ✅;
> **A7 caja activa ✅** (claim `rid` del JWT vía `POST /v1/active-register`,
> guard server-side en `/v1/sales`); hotkeys = grilla config-driven
> (`register.data.hotkeys`, sin migración) chunk 1 ✅; **pendiente**: A5
> impresión, crear-cliente backend, F2 (lock/`_jwt_pos`/RBAC), hotkeys modo
> edición. Ya NO hay subdominio `app-next.punto.la`. Lo de abajo (§4 BFF, §6
> pantallas, §8 UX) sigue válido como spec; las refs a `app-next/` mapean a
> `frontend/`. Ver `_session-log` 2026-06-16 para el detalle.

---

## 0. TL;DR

- **Greenfield** en `app-next/` (Next 15 + TS + shadcn + Tailwind + TanStack
  Query), app **separada** de frontend (PWA, hardware, necesidades distintas),
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
  esperar nada del backend** (igual que frontend).
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
     skill `brand-manual`, verde Punto `#01D7A1` en accents/charts como frontend),
     tipografía, espaciados, componentes. NO se reubican elementos ni se cambian
     flujos. Si hay duda entre "más lindo/moderno" y "igual que el legacy", **gana
     igual que el legacy**.
   - Método: tomar cada pantalla legacy como **referencia visual 1:1** (screenshots
     / el HTML de `app/index.php` + render real) y reconstruirla con shadcn
     respetando layout, no inventar.
2. **Velocidad de caja primero.** Catálogo en memoria + búsqueda síncrona +
   teclado-first. Cero round-trips para buscar un producto o un cliente.
3. **Frontera offline explícita** (§5, implementada 2026-08-23). La caja
   arranca y opera sin red: venta contado/crédito, alta de cliente e impresión.
   Lo que necesita estado compartido entre cajas (espacios, órdenes) sigue
   exigiendo conexión y avisa localmente.
4. **Backend ya hecho.** Reusar `/api` vía BFF propio (§4); no reescribir backend
   salvo gaps puntuales.
5. **Mismas reglas que frontend** (§9): shadcn-first, `MoneyInput`, teléfonos
   E.164, `DataTable`, `npm run build` antes de pushear, etc.

---

## 3. Stack

Idéntico a `frontend` (`context/12 §Stack`):

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
equivalente). BFF same-origin como frontend (`app/api/v1/[...path]` → `/api`).

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
app-next/  (Next 15 PWA — separada de frontend, comparte /api y UI shadcn)
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

- **App separada** (no dentro de frontend): PWA offline-first parcial, hardware,
  pantalla de caja full-screen, ciclo de vida distinto.
- **BFF propio del POS** (D2): endpoints REST nuevos en el BFF de app-next que
  reshapean la `/api`. NO se exponen los endpoints crudos de `/api` al front, NO se
  reusa `fetchs.php` legacy. Ventas → el BFF reenvía a `api/v1/sales.php`
  (SaleService). Bootstrap del catálogo → `app/api/pos/bootstrap` compone lo que
  el store necesita.
- **Loopback in-container** para el BFF→API (mismo aprendizaje que el fix del BFF
  legacy, commit 1532fbb): `localhost:3000` + Host header, no salir a Cloudflare.

---

## 5. La frontera offline (IMPLEMENTADA — 2026-08-23)

> Esta sección decía "diseñada ahora, activada después". Ya está activada: el
> POS **arranca y opera sin internet**. Lo que sigue describe lo implementado.

### 5.1 Qué funciona sin red y qué no

| Operación | Sin conexión |
|---|---|
| **Arranque de la caja** (catálogo, cajas, impuestos, plantillas) | ✅ desde snapshot en IndexedDB |
| Venta **contado** (type 0) y **crédito** (type 3) | ✅ emite, imprime y encola |
| Búsqueda de productos/clientes | ✅ store en memoria |
| **Alta/edición de cliente** | ✅ |
| Impresión (ticket/factura) | ✅ plantillas cacheadas en el bootstrap |
| Espacios/mesas, órdenes, cobro de órdenes ajenas | ❌ **estado compartido entre cajas** |
| Arqueo, reportes, devoluciones, cotizaciones, transferencias, settings | ❌ online |

La frontera no es "lo que se puede", es **lo que se puede decidir solo**: dos
cajas resolviendo offline sobre la misma mesa producen un conflicto que después
no se reconcilia. Lo que se EMITE nunca necesita ponerse de acuerdo con nadie.

Los módulos de estado compartido avisan **localmente** con
`ConnectionRequiredNotice` (`components/pos/connection-required.tsx`), nunca con
una pantalla global. Detectan el corte con `isPaused` y no con `isError`: con el
`networkMode: "online"` default de TanStack Query, una query sin red no falla —
queda `paused` en `pending` para siempre. Sin eso, `/pos/ordenes` mostraba "Sin
órdenes activas", afirmando algo que no podía saber.

### 5.2 Arranque en frío: red / cache / nada

`lib/pos/bootstrap-source.ts` concentra la política, aislada de React:

1. **Red OK** → se sirve, y se persiste el snapshot para el próximo arranque.
2. **Red caída o 5xx** → se sirve el snapshot de IndexedDB y se marca
   `catalogFromCache` en `offline-sync-store`.
3. **401 / 4xx** → NO degrada. El server opinó sobre esta sesión; servir el
   snapshot dejaría operando a un device revocado.
4. **Sin red y sin snapshot** → única pantalla bloqueante que queda
   (`PosAuthGuard`). Un device que jamás sincronizó no tiene catálogo, ni cajas,
   ni correlativo: no hay nada que dejar operar.

`networkMode: "always"` en `usePosBootstrap` es **obligatorio**: con el default,
offline el `queryFn` ni siquiera corre y el fallback nunca se alcanzaría.

### 5.3 Dónde vive lo persistido

Todo en **IndexedDB `punto-pos-offline`** (vía `idb`), con un solo dueño del
schema: `lib/pos/offline-db.ts`.

| Store | Contenido |
|---|---|
| `pendingSales` (v1) | Cola de ventas emitidas sin conexión |
| `snapshots` (v2) | Snapshot del bootstrap completo — catálogo, clientes, cajas, impuestos, plantillas |

Se persiste **la respuesta del BFF tal cual**, no un espejo del state shape del
`useCatalogStore`: un solo formato que migrar. El store sigue siendo memoria
pura y se hidrata igual sin enterarse de la fuente.

**Por qué no el Service Worker.** Había una ruta `NetworkFirst` para
`/api/pos/bootstrap` en `app/sw.ts` con esta intención, y **nunca funcionó**:
serwist evalúa los matchers RegExp con `regExp.exec(url.href)` — contra el href
completo, no el pathname — así que `/^\/api\/pos\/bootstrap/` no matcheaba
nunca. Ruta muerta en silencio desde que se escribió. Corregir el patrón habría
alcanzado para cachear, pero la Cache API es la storage equivocada acá: el
bootstrap trae la lista de clientes (PII) y no participa del `moduleLogout()`
del device. Se eliminó la ruta; IndexedDB es el único dueño del bootstrap
offline. **Cuidado al agregar rutas nuevas al SW: usar matchers de función sobre
`url.pathname`, no RegExp anclados.**

### 5.4 Purga (PII)

| Evento | Snapshot | Cola de ventas |
|---|---|---|
| `moduleLogout()` (sesión muerta, revocación remota) | borrado | **se conserva** |
| "Eliminar dispositivo del comercio" (explícito) | borrado | borrado + caches `pos-*` |

La cola sobrevive al logout a propósito: son ventas **ya emitidas e impresas**
que el backend todavía no recibió — documentos fiscales que existen en papel y
en ningún otro lado. El borrado total es solo la acción explícita del operador,
y avisa antes si hay pendientes (`components/pos/remove-device-dialog.tsx`).

### 5.5 Indicador de estado

`OfflineStatusPill` flota sobre el workspace (`absolute`, esquina inferior
izquierda): aparecer y desaparecer **no mueve ningún botón**, que es regla dura
del POS. La banda full-width (`OfflineBanner`) quedó acotada al único estado
terminal —ventas que no se van a sincronizar solas— donde el desplazamiento del
layout es el precio correcto.

### 5.6 Un bootstrap por realm

El POS ya no consume `/v1/bootstrap` (realm panel) en ningún punto. Lo hacía el
layout —con el Bearer del device— y **gateaba todo el render con
`if (!bootstrap)`**: sin red ese fetch no volvía nunca y la caja quedaba clavada
en el loading screen aunque el catálogo estuviera cacheado. Era el segundo
bloqueo del arranque offline. El auto-lock usa `users.length` y el price-context
lee `outlet.id`, ambos del catalog store.

También desapareció la query duplicada `["pos-bootstrap-auth"]` del guard: era
una segunda request al endpoint más caro del POS y un segundo camino hacia el
mismo dato, del cual solo uno podía aprender a degradar.

### 5.7 Capa de comandos (sin cambios)

Toda mutación pasa por un comando con payload idempotente (UUID client-side que
el `SaleService` deduplica). El `registry` marca `{createSale, createCustomer}`
como offline-eligible; el resto falla duro con "requiere conexión". La
numeración la resuelve el device localmente (`lib/pos/invoice-numbering.ts`,
context/29) — el arriendo de bloques fue rechazado.

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
> estructura/disposición, NO el diseño** — estilo = shadcn + marca frontend
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

**Fase offline — HECHA**
Cola de ventas en IndexedDB (2026-06-25), numeración local del device
(context/29 — el lease fue rechazado), y arranque en frío sin red con snapshot
del bootstrap (2026-08-23). Ver §5, que describe lo implementado.

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

## 9. Reglas (heredadas de frontend — memoria del proyecto)

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
- `context/15-espacios-module-plan.md` — el módulo de mesas vive en app-next (Slice C).
- `context/12-panel-rewrite.md` — el rewrite hermano (panel); mismo stack y reglas.
- `context/11-design-system.md` + skill `brand-manual` — colores/clases de marca.
- Memoria `project_offline_scope` — frontera offline (solo venta simple + cliente).
