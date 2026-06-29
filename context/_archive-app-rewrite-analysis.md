# 14 — Análisis: migrar /app (POS) a Next.js + shadcn

> **Creado:** 2026-06-15. **Estado:** análisis de factibilidad + plan propuesto.
> NO es decisión tomada todavía — es el documento de evaluación que pidió el
> owner para decidir si el POS se reescribe greenfield como se hizo con el panel
> (`context/12-panel-rewrite.md`).

---

## 0. TL;DR — Recomendación

**Es factible y recomendable, pero es un lift bastante más grande que frontend.**
El POS es la superficie operacionalmente crítica (offline-first, hardware,
fiscal, velocidad en caja). La buena noticia: **el backend de ventas ya está
modernizado y desacoplado** (`SaleService` idempotente en `/api`), así que el
nuevo POS pega contra la MISMA `/api` que ya consume frontend — igual que el
panel, "un cliente React puede pegar hoy sin esperar nada del backend".

Recomendación de approach (alineada con lo que propuso el owner):

1. **Online-first primero**, offline en fase posterior — PERO diseñando la capa
   de datos (store en memoria + IndexedDB + cola de sync) desde el día 1 para
   que offline sea una fase, no un re-rewrite.
2. **Reusar `/api` tal cual** (SaleService ya es idempotente y multi-realm).
3. **Portar verbatim** las piezas que son lógica pura framework-agnóstica:
   `ncm-ws.js` (cliente WS, 137 L), el builder de string del ticket, las
   constantes ESC/POS, `ncmScaleBarcode`, el cliente QZ Tray.
4. **Reescribir** todo lo acoplado a jQuery/DOM/Mustache (el 90% de los 26.5k L).
5. **Empezar por el core de caja** (venta contado/crédito + cobro + impresión +
   cierre de caja); los 3 módulos por rubro (mesas/agenda/delivery) son
   extensiones gateadas que vienen después.

Riesgo principal NO es el frontend: es **replicar fielmente el modelo
offline-first + la numeración fiscal de comprobantes** sin heredar las
fragilidades actuales (ver §4).

---

## 1. Estado actual de /app (los números)

| Métrica | Valor |
|---|---|
| Frontend JS | **`scripts/app.js` = 26.515 líneas**, 1 monolito, 32 namespaces `ncm*`, sin tests |
| Shell HTML | `index.php` = 4.319 L (es el shell completo del POS, PWA) |
| Backend PHP en /app | `action.php` 1.730 L (`processData`, fallback de ventas), `fetchs.php` 916 L (loader de datos), `includes/functions.php` 2.852 L |
| Pantallas/vistas | ~20 top-level + 8 sub-vistas de cliente (SPA por hash, no por URL) |
| Tipos de transacción | 10 (0,2,3,5,6,9,10,11,12,13 — ver §1.4) |
| Templates Mustache | 22 (solo 1 `Mustache.render` activo) |
| Módulos por rubro | 3 gateados (mesas/gastro, agenda/salud, órdenes/delivery) + FE por país |
| Libs frontend | ~35 (jQuery, Bootstrap 3, DataTables, chosen, moment, sweetalert2, Chart.js, Leaflet+routing, qz-tray, jsrsasign, PouchDB[muerto], lz-string, libphonenumber, Alpine[coexiste]) |

### 1.1 Routing y dispatcher
SPA mono-página con **routing por hash** (`#ruta` → `ncmEvents.navigate` →
`ncmEvents.actions()` en `app.js:3890-4391`). `ncmEvents` (3.628 L) es un
god-object que mezcla router + acciones — habrá que descomponerlo.

### 1.2 Concentración de código (top namespaces)
- `ncmEvents` 3.628 L (router+acciones) · `ncmTransactions` 1.871 L (venta/carrito)
- `ncmPrinters` 1.787 L (impresión) · `ncmItems` 1.686 L (catálogo)
- `ncmSettings` 1.445 L · `ncmTutorial` 1.346 L · `ncmCalendar` 1.222 L (agenda)
- `ncmCustomer` 1.068 L · `ncmOrders` 887 L (delivery) · `ncmDrawerManager` 795 L (caja)
- Núcleo de venta (`ncmEvents`+`ncmTransactions`+`ncmPayments`+`ncmPrinters`) ≈ **8.000 L**

### 1.3 Pantallas (inventario para dimensionar)
Caja/venta · cobro multi-método/multi-moneda · catálogo/búsqueda · clientes
(8 sub-vistas: ficha/editar/historial/direcciones/notas/fidelidad) · mesas
(`tables_module`) · órdenes/delivery (`orders_module` + `orders_design.php`) ·
agenda/citas (`schedule_module`) · cotizaciones (type 9) · ventas guardadas
(type 2) · transacciones/reimpresión · cierre de caja/arqueo (caja ciega) ·
gift cards · hotkeys (grilla rápida) · ajustes · KDS/comanda · login/lock ·
tutorial. Devoluciones = modo del carrito (type 6), no pantalla.

### 1.4 Tipos de transacción
0 venta contado · 2 guardada/espera · 3 venta crédito · 5 pago de crédito ·
6 devolución/NC · 9 cotización · 10 remisión · 11 apertura mesa · 12 orden/pedido
· 13 cita. (Mismos `type` que ya entiende el backend.)

---

## 2. Qué hace rápida la caja hoy (el mecanismo a replicar)

**Confirmado por análisis:** NO hay inyección PHP→JS de datos. La caja es una
SPA que descarga TODO el catálogo vía AJAX y lo mantiene en memoria.

```
arranque → updateDB.all() (app.js:12922)
  → 6 fetchs paralelos a fetchs.php?load=  (settings, users, outlets, registers, items, customers)
  → ncmGlobals.products = result   (array plano en memoria, app.js:13103)
  → ncmStorage.addEntry('productsObj', ...)  (localStorage + LZString, app.js:13106)
  → window.srcItemsArr = buildSearcheableTableArray(...)  (índice de búsqueda, app.js:13108)
```

- **Store en memoria:** `ncmGlobals` (`app.js:12798`) — arrays planos
  `products[]` / `customers[]`, mutados in-place por id (sin store inmutable).
- **Búsqueda client-side:** scan **lineal O(n)** `indexOf` sobre strings
  concatenados (`name+category+brand+sku+id`), acento-insensible
  (`_ncmDBSearch`, `app.js:13729`). Debounce defensivo solo >10k items.
- **Persistencia:** **localStorage + LZString** (NO IndexedDB — el andamiaje
  PouchDB está 100% comentado, `app.js:16290`). Techo real de escala ~5-10 MB.
- **Sin paginación:** trae el catálogo completo, tope = `plan.max_items`
  (default 99999, `fetchs.php:719`).
- **Delta-sync existe:** el front manda `lastUpdate`, el server manda solo filas
  con `updated_at > lastUpdate` (`fetchs.php:712`) y el front mergea por id. El
  primer load baja todo.

**Implicación para el rewrite:** la velocidad viene de catálogo-en-memoria +
búsqueda local sin round-trips. El nuevo POS DEBE replicar esto — no asumir
búsqueda server-side. Es una mejora natural pasar a **IndexedDB real (Dexie)**
+ índice de búsqueda mejor que O(n) (ej. tokenización + Map, o MiniSearch/FlexSearch).

---

## 3. Análisis por factor (los que pidió el owner)

### 3.1 Offline-first → **empezar online-first, diseñar para offline**
- El SW (`cache-sw.php`) es cache-first para el shell; los datos los maneja
  localStorage, no el SW. Funciona offline sobre datos pre-sincronizados.
- **Plan:** MVP online-first (asume conexión), pero con la capa de datos
  (TanStack Query + IndexedDB + cola de sync) montada desde el inicio. Activar
  offline real = agregar SW (Workbox/`next-pwa`) + drenar cola = una fase, no
  un rewrite. **Riesgo si NO se diseña así desde día 1:** terminar con un
  online-first que requiere reescribir el data layer para offline.

### 3.2 Hardware / impresión → **bajo riesgo, mayormente portable**
- **QZ Tray 2.2.1** se conecta por WebSocket a localhost; firma server-side
  (`libraries/qztray/sign-message.php`, openssl SHA512). El cliente QZ es JS
  puro → se encapsula en un módulo TS tipado, funciona igual en React.
- **Ticket = texto plano** con padding a ancho fijo (32/40/48 chars) + un puñado
  de comandos ESC/POS (corte `\x1B\x69`, cajón `KICK()`, init/center). El builder
  de string (`ducumentPrintBuilder` + `rb.min.js`) es lógica pura **portable casi
  tal cual**. El dispatch (`justPrint`, lectura de `#printPrinterSelect`) está
  acoplado a jQuery → se reescribe.
- Otros: `window.print()` HTML (iframe), comandas a cocina (ruteo por
  `printCategories`), cajón de dinero (ESC/POS KICK), báscula (barcode pesado
  `ncmScaleBarcode`, portable), lector barcode (keyboard-wedge `codeScanner`,
  se reimplementa como hook), BLE/WIFI mobile (Cordova — fuera de scope web).
- ⚠️ Deuda de seguridad heredada: `sign-message.js` tiene una private key de
  demo embebida (inactiva, la pisa el firmado server-side) — no portarla.

### 3.3 Path fiscal → **lo maneja SIFEN (microservicio), pero la numeración es del cliente**
- La factura electrónica la maneja la integración SIFEN (módulo aparte). OK.
- **PERO la numeración de comprobantes la asigna el CLIENTE** offline
  (`updateDocumentNumber`, `app.js:13286`): incrementa el contador local del
  register y lo asigna a `invoiceno`. **Riesgo estructural de duplicados** si dos
  cajas comparten punto de expedición offline (= la regla fiscal documentada en
  `project_jerarquia_dominio`). Esto es independiente del frontend.
  **Resuelto (D3) con lease exclusivo + incremento estricto — ver §9.**

### 3.4 Real-time / WebSocket → **bajo riesgo, NO esencial para el core**
- `ncm-ws.js` (137 L) es un drop-in de Pusher sobre WebSocket nativo, con
  backoff + heartbeat + re-suscripción. **Framework-agnóstico → se porta verbatim**
  (o se envuelve en un hook `useChannel`).
- Backend publica por Redis Pub/Sub (`ws_publish.php`, falla en silencio). El
  `ws-server/` Node ya existe como microservicio.
- Canales consumidos son **todos accesorios**: `order` (panel órdenes/KDS,
  gated `ordersPanel`), `addCustomers`/`updateCustomer`/`deleteCustomer` (sync
  clientes), COS (segunda pantalla), `qrBancard` (resultado pago QR), `checkSession`
  (lock de caja). **La facturación funciona sin WS.** → el WS entra con los
  módulos que lo necesitan, no bloquea el MVP de caja.

### 3.5 Velocidad en caja → **React iguala o supera, si se replica el modelo**
- Lo que hace rápido al legacy NO es jQuery — es el catálogo-en-memoria + cola
  optimista (guarda local → confirma → encola). React con un store en memoria +
  IndexedDB + búsqueda indexada será igual o más rápido (render virtualizado,
  menos reflow que jQuery+DOM directo).
- **La cola de ventas** (`'sync'` + `'orphans'` en localStorage, loop cada 2s,
  `app.js:16372-16551`) hay que reimplementarla mejor: hoy tiene race condition
  `splice` síncrono vs callback async, UIDs por `Math.random()` (no UUID), y
  `sentUIDsArray` volátil. El SaleService backend YA es idempotente
  (`SaleService.php:51`, UNIQUE en `transactionUID`) → la cola nueva solo debe
  persistir confiable (IndexedDB) y reintentar; el backend cubre la dedup.

---

## 4. Riesgos y partes difíciles (ranking)

| # | Riesgo | Severidad | Mitigación |
|---|---|---|---|
| R1 | Replicar offline-first sin heredar fragilidades (cola, race conditions) | **Alta** | IndexedDB (Dexie) + cola transaccional + UUID v7 real client-side; SaleService ya da idempotencia |
| R2 | Numeración fiscal de comprobantes (duplicados cross-caja) | **Alta** | Decisión D3: ¿reserva de rango server-side vs client-side actual? |
| R3 | Volumen del rewrite (26.5k L, 20 pantallas, sin tests) | **Alta** | Fasear por módulo; core caja primero; tests E2E nuevos (Playwright) |
| R4 | Paridad de impresión térmica (muchos modos: QZ/HTML/BLE/RawBT/kick/comandas) | Media | Portar builder de string tal cual; QZ como módulo tipado; BLE/RawBT mobile fuera de scope web inicial |
| R5 | Búsqueda performante con catálogos grandes (>10k items) | Media | Índice mejor que O(n) (MiniSearch/FlexSearch) + virtualización |
| R6 | Módulos por rubro (mesas/agenda/delivery con Leaflet routing) | Media | Son gateados → fases separadas post-MVP |
| R7 | Mobile/Cordova (cámara barcode, BLE) | Baja | El rewrite web no cubre Cordova; decisión si se mantiene app nativa aparte |

---

## 5. Arquitectura propuesta del nuevo POS

```
app-next/  (Next.js 15 PWA — separado de frontend por necesidades offline/PWA)
  app/(pos)/...            ← rutas: caja, mesas, agenda, ordenes, clientes, caja/arqueo
  lib/store/               ← store en memoria (Zustand) + IndexedDB (Dexie)
    catalog.ts             ← productos/clientes en memoria, hidratados de IndexedDB
    search.ts              ← índice de búsqueda (MiniSearch/FlexSearch)
    sync-queue.ts          ← cola de ventas offline (reemplaza 'sync'/'orphans')
  lib/hardware/
    qz-tray.ts             ← cliente QZ tipado (portado)
    ticket-builder.ts      ← builder de string del ticket (portado del legacy)
    barcode-scanner.ts     ← hook keyboard-wedge (reimplementado)
  lib/realtime/
    ncm-ws.ts              ← cliente WS (portado de ncm-ws.js) + hook useChannel
  lib/api/                 ← cliente a /api compartida (mismo patrón BFF que frontend)
```

**Decisiones de arquitectura:**
- **App separada (`app-next/`)**, NO dentro de frontend: el POS es PWA
  offline-first con necesidades distintas (SW agresivo, IndexedDB, hardware).
  Comparten `/api` y se puede compartir un paquete de UI shadcn. (Decisión D1.)
- **Stack idéntico a frontend:** Next 15 + TS estricto + shadcn + Tailwind +
  TanStack Query + RHF + Zod. Reglas de memoria aplican (shadcn-first, MoneyInput,
  DataTable, phone E.164, etc.).
- **Data layer:** Zustand (store en memoria) + Dexie (IndexedDB) + TanStack Query
  (fetch/sync con `/api`). El catálogo se hidrata de IndexedDB al boot, se
  refresca con delta-sync (`lastUpdate`), la búsqueda corre sobre índice local.
- **Reusar `/api`:** ventas a `api/v1/sales.php` (SaleService idempotente); el
  resto de endpoints compartidos ya son `apiAuthTenant(['panel','pos-app'])`.

---

## 6. Plan de migración por fases

> Greenfield como frontend. El legacy `/app` queda en producción hasta que el
> nuevo cubra el core 100%. Coexisten en subdominios (`app.punto.la` legacy →
> `app-next.punto.la` o flip cuando esté listo).

**F0 — Sprint 0 / plumbing — ✅ COMPLETO (commits 2ead57f, 218ad54, dc3b5e5, 513bf9d, 2026-06-15)**

Scaffold `app-next/` ✅ — Next.js 15, React 19, TS, shadcn, Tailwind, TanStack Query. Stack idéntico a frontend. Dockerfile + `.dockerignore` para deploy en Coolify ✅.

Slices implementados:
- **Scaffold base**: `app/(pos)/layout.tsx`, `app/api/v1/[...path]/route.ts` (catch-all BFF same-origin → PHP `/api`), `app/api/pos/bootstrap/route.ts` (auth handoff JWT `_jwt` realm `pos-app`), `hooks/use-pos-bootstrap.ts`, `hooks/use-catalog-seed.ts`.
- **Data layer base**: `lib/catalog/store.ts` (Zustand, catálogo en memoria), `lib/catalog/search.ts` (índice de búsqueda local), `lib/catalog/fixtures.ts` (datos de desarrollo), `lib/cart/store.ts` (estado del carrito).
- **Hardware stubs**: `lib/hardware/qz-tray.ts`, `lib/hardware/ticket-builder.ts`, `lib/hardware/barcode-scanner.ts` (hook keyboard-wedge).
- **Realtime stub**: `lib/realtime/ncm-ws.ts` (cliente WS portado de ncm-ws.js).
- **Commands**: `lib/commands/create-sale.ts`, `lib/commands/create-customer.ts`, `lib/commands/registry.ts`.
- **Slice A1 — pantalla de caja** (`app/(pos)/register/page.tsx`): carrito de venta, búsqueda de productos, listado de ítems del catálogo en memoria, UI con shadcn.
- **Slice A2 — modales de búsqueda**: modal de búsqueda de productos y modal de selección/búsqueda de cliente, integrados en la pantalla de caja.

**F1 — Núcleo de caja (online-first) — el grueso del valor**
Pantalla de venta (carrito + categorías + búsqueda local indexada), cobro
multi-método/multi-moneda, tipos 0/3 (contado/crédito), impresión (QZ + builder
de ticket portado + `window.print` fallback), cierre de caja/arqueo. Cliente
inline (alta rápida). **Al cerrar F1: una caja factura end-to-end contra `/api`.**

**F2 — Cola offline + PWA**
SW (Workbox/next-pwa), cola de sync transaccional (IndexedDB), UUID v7
client-side, reintentos, detección online/offline robusta (sin el bug del
handler `offline`). Numeración según decisión D3.

**F3 — Clientes + transacciones + features de venta**
8 sub-vistas de cliente (ficha/historial/direcciones/notas/fidelidad),
listado/reimpresión de transacciones, cotizaciones (9), guardadas (2),
devoluciones (6), gift cards, hotkeys.

**F4 — Módulos por rubro (gateados)**
Mesas/espacios (gastro), agenda/citas (salud), órdenes/delivery + KDS + mapas
Leaflet. Real-time WS (`ncm-ws` portado). Cada uno gated por su flag de módulo.

**F5 — Paridad final + corte**
FE/SIFEN, COS/segunda pantalla, QR Bancard/Pix, tutorial, settings completos.
Smoke test E2E vs legacy. Flip de subdominio. Borrar `/app` legacy.

---

## 7. Decisiones abiertas (para el owner)

- **D1** — ¿App separada `app-next/` o sub-app dentro de frontend? (Recomiendo
  separada por PWA/offline.)
- **D2** — ¿Online-first MVP y offline en F2 (recomendado), u offline desde F1?
- **D3** — ✅ **RESUELTO (2026-06-15): lease exclusivo por register + incremento
  estricto +1.** Ver §9 para el diseño completo. (Se descartó la reserva de
  rangos: genera huecos, ilegales para timbrado tradicional / non-FE, que es el
  grueso del mercado PY.)
- **D4** — **Mobile/Cordova:** ¿el rewrite web reemplaza la app nativa, o la app
  nativa (cámara barcode, BLE) se mantiene aparte? Define si BLE/RawBT entran.
- **D5** — Catálogos grandes: ¿hay tenants reales con >10k items? Define cuánto
  invertir en el índice de búsqueda.
- **D6** — ¿Se reutiliza el `ducumentPrintBuilder` legacy (servido hoy desde el
  panel) portándolo a TS, o se reescribe el builder de tickets?

---

## 8. Comparación con el rewrite del panel

| Dimensión | frontend | app-next (POS) |
|---|---|---|
| Backend listo | Sí (`/api` F2) | **Sí** (`/api` + SaleService idempotente) |
| Offline | No requerido | **Requerido** (núcleo del valor) |
| Hardware | No | **Sí** (impresión térmica, cajón, báscula, barcode) |
| Real-time | No | Sí (accesorio, no bloquea core) |
| Fiscal | Config | **Numeración + FE en el flujo de venta** |
| LOC a reescribir | Medio | **Alto** (26.5k L monolito) |
| Criticidad operacional | Media (gestión) | **Alta** (si la caja cae, no se vende) |

**Conclusión:** mismo patrón probado, pero ~2-3x el esfuerzo y riesgo del panel.
Lo que lo hace abordable es que el backend ya está hecho y el modelo
(catálogo-en-memoria + cola optimista) está entendido y es replicable mejor.

---

## 9. Numeración de comprobantes offline — diseño (resuelve D3)

> **Decidido 2026-06-15.** Reemplaza el `updateDocumentNumber` client-side actual
> (`app.js:13286`), que incrementa un contador local sin garantía de exclusividad
> — raíz del riesgo de duplicados.

### 9.1 El invariante
**Exactamente UN emisor activo por punto de expedición (= `register`) a la vez,
online u offline.** El punto de expedición es la unidad de secuencia. Cajas
distintas (caja 1, caja 2) son puntos de expedición distintos → facturan offline
en paralelo sin colisión. El caos solo surge con dos instancias del MISMO
register. (Regla de dominio: "no dos cajas con mismo punto de expedición".)

### 9.2 Por qué NO reserva de rangos
La reserva de bloques disjuntos resuelve duplicados pero **genera huecos** (folios
reservados no usados). SIFEN/FE tolera huecos, pero el **timbrado tradicional /
autoimpresor (non-FE) exige secuencia estricta SIN huecos** — y es el grueso de
los facturadores en PY. Descartada.

### 9.3 Mecanismo: lease exclusivo + incremento estricto +1
- **Lease**: derecho exclusivo a emitir en `register R`, con vencimiento T.
  Otorgado server-side atómicamente **solo si no hay otro lease vigente** para R.
- Con exclusividad garantizada, la caja incrementa **estricto +1** offline →
  secuencia perfecta sin huecos.
- **Auto-límite offline (clave)**: el device cachea su ventana
  (`last_renew + grace`); al acercarse avisa, y **al vencer deja de emitir**
  ("reconectate para renovar"). Así el viejo se corta solo ANTES de que otro
  pueda tomar el lease → nunca dos emisores.
- Evoluciona el `sessionId` actual (`app.js:17514` + `setSession` en
  `RegisterService`): pasa de "el último que abre gana, solo online" a "lease con
  TTL, no se puede pisar uno offline vigente". El canal `registerSession` sigue
  avisando el takeover online en tiempo real.

### 9.4 Config por defecto
| Parámetro | Default | Configurable |
|---|---|---|
| Modelo | Lease + offline | por register |
| Renovación online del lease | cada 60s (colgado del polling existente) | no |
| Grace offline (techo de operación sin conexión) | **12h** | por register |
| Aviso de vencimiento | a 1h del fin | no |
| Al vencer offline | **bloquea emisión** | no |
| Force-release | rol manager+, **online**, atestiguado + `admin_audit` | permiso |
| Online-only (número server-side `UPDATE …+1 RETURNING`) | off | por register |

### 9.5 Escenario de takeover offline (prueba de que no colisiona)
1. Device A, lease activo, último nº sincronizado = 100. Offline emite 101…150.
2. B quiere caja 1: si A tiene lease offline vigente, **B no puede emitir** (msg:
   "Caja 1 en uso, sesión offline hasta HH:MM").
3. A reconecta → sincroniza 101–150 (dedup por `transactionUID`, ya en
   `SaleService`). Server queda en 150. Secuencia estricta, sin huecos, sin dups.
4. Device muerto offline con lease vigente y necesitás la caja YA → **force-release**
   de manager (online, atestiguado, logueado) antes del vencimiento del grace.

### 9.6 Tradeoff aceptado
No se puede tener a la vez: (a) dos devices emitiendo en la misma caja offline,
(b) secuencia estricta sin huecos, (c) sin duplicados (imposible tipo CAP). El
lease sacrifica (a) — que la ley prohíbe igual. Costo: device muerto offline
bloquea el register hasta T (mitigado por grace sensato + force-release).
