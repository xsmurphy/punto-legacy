# Plan: Sincronización en tiempo real panel ↔ POS

**Estado:** En ejecución (iniciado 2026-06-19).
**Objetivo:** cuando un usuario muta un dato (item, cliente, venta, etc.) en panel o POS, todos los browsers abiertos del mismo tenant invalidan sus queries TanStack y reflejan el cambio sin recargar.

## Casos de uso

1. **Panel → POS**: el dueño edita el precio de un item en frontend → la tablet del POS lo refleja sin recargar.
2. **POS → Panel**: el cajero hace una venta → el dashboard del dueño actualiza KPIs y listados en vivo.
3. **Panel → Panel**: dos browsers del mismo dueño se mantienen sincronizados.

El alcance NO incluye:
- Sincronización entre tenants (cada empresa es un silo).
- Resolución de conflictos (last-write-wins; en MVP no hay edición concurrente del mismo registro).
- Eventos para mutaciones de admin realm (`/v1/admin/*`).

## Decisiones (cerradas con el owner)

1. **Stack**: WebSocket nativo (no SSE) — porque el `ws-server` Node ya está construido (`ws-server/index.js`).
2. **Bridge**: PHP publica en Redis Pub/Sub via `wsPublish()` (ya existe en `app/includes/ws_publish.php`). El `ws-server` reenvía a clientes WS suscritos.
3. **Canal de invalidación tenant-wide**: `<companyId>:invalidate` (separado de los canales `<companyId>` que usa `Notification.php` para no mezclar semánticas).
4. **Shape del evento** (actualizado 2026-08-16, ver `## Modelo quirúrgico 2026-08-16` más abajo — el campo `ids` es un agregado retrocompatible, no un reemplazo):
   ```json
   {
     "event": "invalidate",
     "data": {
       "entity": "item" | "contact" | "transaction" | "drawer" | "expense" | "outlet" | "setting" | "category" | "brand" | "tag" | "tax" | "...",
       "op": "create" | "update" | "delete",
       "id": "uuid-or-null",
       "scope": "all" | "dashboard",
       "ids": ["uuid", "..."]
     }
   }
   ```
   - `entity` mapea a queryKeys (`item` → `["items", ...]`, `transaction` → `["transactions", "reports", "dashboard"]`, etc.)
   - `scope: "dashboard"` se aplica automáticamente a las mutaciones de `transaction` y `drawer` para no provocar que el POS se reinvalide en cada venta (sería ruido innecesario en el cajero). El panel sí lo recibe.
   - `ids` (opcional, 2026-08-16): variante plural de `id` para operaciones que tocan VARIOS recursos de una — el batch de stock (`Inventory::flushRealtimeStockEvents()`) o un bulk-edit del panel. `id` sigue viajando (`null` cuando se usa `ids`) — un cliente que solo entiende `id` no se rompe, solo no aprovecha el sync quirúrgico por lote.
5. **Auth WS**: el ws-server NO autentica (mismo modelo que el uso actual de `Notification`). El browser solo conoce su `companyId` via JWT, y solo se suscribe a `<su companyId>:invalidate`. Defensa en profundidad: si otro cliente conoce un companyId ajeno, lo más que recibe son eventos "X tipo cambió" sin datos sensibles (no van payloads, solo señales de invalidación).
6. **Naming**: `realtimePublish($entity, $op, $id)` — wrapper sobre `wsPublish()` que estandariza el shape y resuelve el canal por `COMPANY_ID`. Vive en `app/includes/realtime.php`.
7. **Wire automático en `apiAuthTenant`**: igual que `tenantAudit`, después del request mutante. Mapeo endpoint→entity hardcoded (`/v1/items` → item, etc.). Lista cerrada, no auto-inferimos.

## Por qué este modelo

- **No requiere infra nueva**: Redis ya está en prod, ws-server tiene Dockerfile listo.
- **Polling no escala**: con 50+ tablets del POS por tenant, polling cada 5s son 600 requests/min al backend solo para "¿hay algo nuevo?".
- **Backend simple**: el publish son 3 líneas (lookup canal + JSON.stringify + Redis PUBLISH). Best-effort, nunca bloquea la mutación.
- **Frontend simple**: TanStack Query ya tiene queryKeys consistentes. Recibir un evento → `queryClient.invalidateQueries({ queryKey: ["items"] })`. Solo invalida lo que ya está cargado.

## Slices

| # | Slice | Resultado |
|---|---|---|
| A | **Infra publish** — helper `realtimePublish`, wire desde `apiAuthTenant`, mapeo endpoint→entity | Mutaciones PHP publican a Redis. Sin cliente todavía. |
| B | **Cliente WS** — hook `useRealtimeSync`, mapeo entity→queryKeys, wire en layout frontend y `/pos` | El browser se sincroniza vía WS |
| C | **Deploy ws-server** — instrucciones para Coolify, env vars, subdominio | ws-server disponible en `wss://ws.punto.la` |
| D | **Plan vivo** (este archivo) — registro de avance, cronología | Trazabilidad |

### Slice A — Infra publish (PHP)

**Archivos:**

- `app/includes/realtime.php` (NUEVO) — helper único:

  ```php
  function realtimePublish(string $entity, string $op, ?string $id = null, string $scope = 'all'): void
  {
      // Defensive: no romper si COMPANY_ID no está definido (jobs CLI, etc.)
      if (!defined('COMPANY_ID') || !COMPANY_ID) return;

      $channel = COMPANY_ID . ':invalidate';
      wsPublish($channel, 'invalidate', [
          'entity' => $entity,
          'op'     => $op,
          'id'     => $id,
          'scope'  => $scope,
      ]);
  }
  ```

- `api/bootstrap.php` (MODIFICAR) — después del bloque `tenantAudit(...)` agregar `realtimeAfterMutation($__auditMethod, $__auditEndpoint, $__auditTargetId)` que mapea endpoint→entity con tabla cerrada:

  ```php
  function realtimeAfterMutation(string $method, string $endpoint, ?string $targetId): void
  {
      static $map = [
          '/v1/items'                 => ['entity' => 'item',        'scope' => 'all'],
          '/v1/contacts'              => ['entity' => 'contact',     'scope' => 'all'],
          '/v1/customers'             => ['entity' => 'contact',     'scope' => 'all'],
          '/v1/outlets'               => ['entity' => 'outlet',      'scope' => 'all'],
          '/v1/categories'            => ['entity' => 'category',    'scope' => 'all'],
          '/v1/brands'                => ['entity' => 'brand',       'scope' => 'all'],
          '/v1/tags'                  => ['entity' => 'tag',         'scope' => 'all'],
          '/v1/taxes'                 => ['entity' => 'tax',         'scope' => 'all'],
          '/v1/transactions'          => ['entity' => 'transaction', 'scope' => 'dashboard'],
          '/v1/orders'                => ['entity' => 'transaction', 'scope' => 'dashboard'],
          '/v1/drawer'                => ['entity' => 'drawer',      'scope' => 'dashboard'],
          '/v1/reports/drawers'       => ['entity' => 'drawer',      'scope' => 'dashboard'],
          '/v1/settings'              => ['entity' => 'setting',     'scope' => 'all'],
          '/v1/modules'               => ['entity' => 'setting',     'scope' => 'all'],
          '/v1/price_list'            => ['entity' => 'item',        'scope' => 'all'],
          '/v1/price_list_item'       => ['entity' => 'item',        'scope' => 'all'],
      ];
      // Match por prefijo (los endpoints aceptan ?id=, ?resource=, etc.)
      foreach ($map as $prefix => $cfg) {
          if (str_starts_with($endpoint, $prefix)) {
              $op = match ($method) {
                  'POST'  => 'create',
                  'PUT', 'PATCH' => 'update',
                  'DELETE' => 'delete',
                  default => 'update',
              };
              realtimePublish($cfg['entity'], $op, $targetId, $cfg['scope']);
              return;
          }
      }
  }
  ```

- `api/bootstrap.php` (MODIFICAR) — `require_once API_APP_DIR . '/includes/realtime.php';` cerca del top, junto a otros requires.
- `api/bootstrap.php` (MODIFICAR) — al final del bloque `if (in_array($__auditMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true))`, después de `tenantAudit(...)`, agregar:
  ```php
  realtimeAfterMutation($__auditMethod, $__auditEndpoint, $__auditTargetId);
  ```

**Sin tocar:** ningún Service ni endpoint individual. Todo el wire pasa por `apiAuthTenant` — mismo patrón que `tenantAudit`. Si un endpoint nuevo agrega más mutaciones, basta agregar línea al mapa.

### Slice B — Cliente WS (frontend)

**Archivos:**

- `frontend/lib/realtime.ts` (NUEVO) — singleton WS client con reconnect exponential backoff:

  ```ts
  type InvalidateEvent = {
    entity: string
    op: "create" | "update" | "delete"
    id: string | null
    scope: "all" | "dashboard"
  }

  type Subscriber = (ev: InvalidateEvent) => void

  let ws: WebSocket | null = null
  let backoffMs = 1000
  let retryTimer: ReturnType<typeof setTimeout> | null = null
  let companyId: string | null = null
  const subscribers = new Set<Subscriber>()

  export function connectRealtime(cid: string, wsUrl: string) {
    companyId = cid
    open(wsUrl)
  }

  function open(wsUrl: string) {
    if (ws && ws.readyState <= 1) return
    ws = new WebSocket(wsUrl)
    ws.onopen = () => {
      backoffMs = 1000
      ws?.send(JSON.stringify({ action: "subscribe", channel: `${companyId}:invalidate` }))
    }
    ws.onmessage = (m) => {
      try {
        const parsed = JSON.parse(m.data)
        if (parsed.event === "invalidate") {
          subscribers.forEach((cb) => cb(parsed.data))
        }
      } catch { /* ignore */ }
    }
    ws.onclose = () => scheduleReconnect(wsUrl)
    ws.onerror  = () => ws?.close()
  }

  function scheduleReconnect(wsUrl: string) {
    if (retryTimer) clearTimeout(retryTimer)
    retryTimer = setTimeout(() => open(wsUrl), backoffMs)
    backoffMs = Math.min(backoffMs * 2, 30_000)
  }

  export function subscribeRealtime(cb: Subscriber): () => void {
    subscribers.add(cb)
    return () => subscribers.delete(cb)
  }

  export function disconnectRealtime() {
    if (retryTimer) clearTimeout(retryTimer)
    ws?.close()
    ws = null
    subscribers.clear()
  }
  ```

- `frontend/hooks/use-realtime-sync.ts` (NUEVO) — hook que invalida queries según entity:

  ```ts
  "use client"
  import * as React from "react"
  import { useQueryClient } from "@tanstack/react-query"
  import { subscribeRealtime } from "@/lib/realtime"

  const ENTITY_TO_QUERY_KEYS: Record<string, string[][]> = {
    item:        [["items"], ["item"]],
    contact:     [["contacts"], ["contact"], ["customers"], ["team-members"]],
    outlet:      [["outlets"]],
    category:    [["categories"], ["taxonomies", "category"]],
    brand:       [["brands"], ["taxonomies", "brand"]],
    tag:         [["tags"], ["taxonomies", "tag"]],
    tax:         [["taxes"], ["taxonomies", "tax"]],
    transaction: [["reports"], ["transactions"], ["dashboard"]],
    drawer:      [["reports", "drawers"], ["dashboard"]],
    expense:     [["reports", "expenses"], ["dashboard"]],
    setting:     [["settings"], ["modules"], ["bootstrap"]],
  }

  /** scope que el cliente actual debe procesar.
   * "panel" → procesa "all" + "dashboard". "pos" → solo "all" (no se reinvalida por cada venta propia).
   */
  export function useRealtimeSync(clientScope: "panel" | "pos" = "panel") {
    const qc = useQueryClient()
    React.useEffect(() => {
      return subscribeRealtime((ev) => {
        if (clientScope === "pos" && ev.scope === "dashboard") return
        const keys = ENTITY_TO_QUERY_KEYS[ev.entity]
        if (!keys) return
        keys.forEach((k) => qc.invalidateQueries({ queryKey: k }))
      })
    }, [qc, clientScope])
  }
  ```

- `frontend/components/realtime-provider.tsx` (NUEVO) — abre el WS en cuanto el bootstrap nos entrega `companyId`:

  ```tsx
  "use client"
  import * as React from "react"
  import { connectRealtime, disconnectRealtime } from "@/lib/realtime"

  export function RealtimeProvider({ companyId, children }: { companyId: string; children: React.ReactNode }) {
    React.useEffect(() => {
      const url = process.env.NEXT_PUBLIC_WS_URL
      if (!url || !companyId) return
      connectRealtime(companyId, url)
      return () => disconnectRealtime()
    }, [companyId])
    return <>{children}</>
  }
  ```

- `frontend/app/(panel)/layout.tsx` y `frontend/app/(pos)/pos/layout.tsx` (MODIFICAR): leer `companyId` del bootstrap (ya está en algún Provider/Context) y envolver con `<RealtimeProvider>`. Dentro, llamar `useRealtimeSync("panel")` o `useRealtimeSync("pos")` desde un hook stub en el layout. El subagente debe encontrar dónde está el contexto del bootstrap y ajustar.

- `frontend/.env.example` y `Coolify env vars`: agregar `NEXT_PUBLIC_WS_URL=wss://ws.punto.la`.

### Slice C — Deploy ws-server (manual en Coolify)

Solo se documenta (el subagente NO toca Coolify). Pasos:

1. En Coolify, crear nuevo Resource → "Application" → "Dockerfile".
2. Source: mismo repo `xsmurphy/punto-legacy`, branch `main`, Dockerfile path `ws-server/Dockerfile`.
3. Env vars:
   - `REDIS_URL` — copiar del servicio Punto POS existente (mismo Redis).
   - `WS_PORT` — `6001` (interno).
4. Network: exponer el port 6001 a un subdominio `ws.punto.la` con TLS (Coolify lo maneja automático con Traefik).
5. Verificar: `wscat -c wss://ws.punto.la` y enviar `{"action":"ping"}` — debe responder `{"event":"pong"}`.

### Slice D — Doc vivo (este archivo)

A medida que cada slice se cierra, se anota el commit en la **Cronología** al final.

## Hardening 2026-08-15 — que olvidarse sea imposible, no improbable

Auditoría previa (misma fecha) encontró 6 gaps: stock sin publish en el flujo dominante (venta/compra/anulación/devolución/producción/merma), `scope: dashboard` escondiendo operaciones de transacción que el POS sí necesita, 10 endpoints mutantes sin mapear, `contact` sin invalidar `pos-bootstrap`, reconexión sin resync, y 6 entities publicadas que el front descartaba en silencio. Los cinco cambios de esta sesión, en orden de prioridad:

### 1. Stock: el publish vive en `manageStock()`, no en los callers

`Inventory::manageStock()` (`api/lib/App/Domain/Inventory.php`) es la única puerta de todo movimiento de stock (27 callers). Ahora publica `realtimePublish('item', 'update', null, 'all', $company)` ella misma, justo después del INSERT. Cubre de una sola vez venta, compra, anulación, devolución, producción, merma, ajuste, transfer, conteo y variantes — y a cualquier caller futuro, sin acordarse de nada.

- **Dedup por request**: `private static bool $stockEventPublished` en la clase — UN evento `item` por proceso PHP aunque `manageStock()` se llame N veces (venta de 30 líneas = 30 movimientos = 1 evento). Seguro porque el stack corre `php -S`/FPM (SAPI shared-nothing, sin proceso persistente tipo Swoole) — el flag se resetea entre requests. El front no usa el `id` del evento (invalida por `entity`, ver `use-realtime-sync.ts`), así que el evento va sin id.
- **Callers que publicaban `item` a mano** (`StockAdjustmentService`, `InventoryCountService`, `StockTransferService`, `StockMovementsService`) — se les sacó ese publish, queda uno solo.
- **companyId explícito**: `realtimePublish()` ahora acepta un 5º parámetro `$companyId` opcional (default `null` → cae a la constante global `COMPANY_ID`, back-compat total con los ~30 callers existentes). `manageStock()` lo pasa explícito porque `$company` puede venir de `ops['companyId']` (jobs/CLI como `ItemImporter`, sin request HTTP ni constante global definida).
- Best-effort intacto: `wsPublish()` ya absorbía el error de conexión — confirmado con el arnés corriendo SIN Redis (ver Verificación).

### 2. `bootstrap.php`: default invertido — todo mutante bajo `/v1/` publica

`realtimeAfterMutation()` dejó de ser una lista cerrada endpoint→entity. Ahora:

- **`$overrides`** — chico, solo para lo que el path no puede dar solo: alias semántico (`customers`→`contact`), `scope` distinto de `'all'` (`sales`/`transactions`/`orders`/`drawer`/`purchases` → `'dashboard'`), y `skipResources` (`giftcards?resource=validate`).
- **`$excluded`** — allowlist explícita y chica de endpoints que NO publican nada (hoy solo `/v1/admin`, fuera de alcance del plan). Ausencia en esta lista NUNCA silencia un endpoint — es lo opuesto del mapa viejo.
- **Cualquier otro endpoint mutante** deriva su `entity` del primer segmento del path, singularizado (`deriveEntityFromPath`/`singularizeSegment`) — `/v1/returns`→`return`, `/v1/waste`→`waste`, `/v1/order_items`→override `order`, etc. Esto cubre los 10 endpoints que antes quedaban mudos (returns, waste, production, order_items, item_addons, space-sectors, vouchers, sold_pack\*, customer_address, customer_note) sin tocarlos.
- **Fix de colisión**: el matching pasó de `str_starts_with` crudo a `endpointMatches()` (por segmento completo). El mapa viejo hacía que `/v1/orders-core` matcheara el prefijo `/v1/orders` Y `OrderCoreService` publicara su propio evento `order` — doble publish en cada mutación de `orders-core`. Ya no colisiona.

### 3. Scope por operación: transacción vs venta

`api/v1/transactions.php` (void/status/reject/DELETE/itemDeletion) y `SaleService::save()` (creación) autentican con `apiAuthPosContext()`, NO `apiAuthTenant()` — **nunca pasaban por `realtimeAfterMutation()`, con o sin mapa**. El publish ahí es explícito, mismo patrón que `CreditPaymentService::allocate()`:

- **void/status/reject/DELETE/itemDeletion** → `realtimePublish('transaction', op, $id, 'all')` — llegan al POS en caliente (el caso literal del owner).
- **creación de venta** (`SaleService::save()`, cubre `sales.php` Y `offline-sync.php`) → `realtimePublish('transaction', 'create', $id, 'dashboard')` — el panel actualiza KPIs en vivo; el POS sigue ignorando sus propias ventas (era ruido y además, antes de este cambio, el dashboard tampoco se enteraba: el gap era más profundo de lo que parecía).

### 4. Front: `contact`→`pos-bootstrap`, 9 entities nuevas con queryKey, warning en dev

`use-realtime-sync.ts`: `contact` suma `["pos-bootstrap"]`. Entities que se publicaban pero no tenían queryKey: `payment-method`, `giftcard`, `pack`, `schedule`, `printJob`, `remision` (hallazgo F) + `return`, `production`, `waste` (nuevas por el punto 2). Un `entity` sin mapear ya NO se descarta en silencio — `console.warn` en dev (gateado por `NODE_ENV`).

### 5. Reconexión = resync total

`frontend/lib/realtime.ts`: `hasEverConnected` distingue primera conexión de reconexión. En cada reconexión (no en la primera), dispara `subscribeReconnect()` → `use-realtime-sync.ts` llama `qc.invalidateQueries()` SIN queryKey (todo el cache). Justificación: `ws-server` es un relay puro sin backlog/replay — no hay forma de saber qué se perdió mientras el WS estuvo caído, así que invalidar todo es la única respuesta honesta.

### Verificación

- `npm run build` (frontend) — sin errores de tipos.
- `bash api/lib/Sales/verify_chain/run.sh` — arnés end-to-end completo (venta multi-tasa, impuestos, EInvoice, impresión) sin Redis en el entorno: el log muestra `[wsPublish] No se pudo conectar a Redis ... Connection refused` en cada venta y la venta se persiste igual (best-effort confirmado). Un bug de espera preexistente del arnés (`pg_isready` daba OK contra la instancia TEMPORAL de Postgres, antes de `CREATE DATABASE`) se arregló de paso — no relacionado con este cambio, pero bloqueaba correrlo.
- **`verify_realtime.php`** (nuevo, junto al arnés) — intercepta el PUBLISH real con un listener TCP fake (sin mocks) y demuestra: 5 movimientos de stock en el mismo proceso → 1 solo evento `item`; una anulación → evento `transaction` con `scope: 'all'`. Wireado como paso 3.5 de `run.sh`.

### Qué quedó fuera

- Endpoints que autentican con `apiAuthPosContext()` sin publish explícito propio: `parked-sales.php`, `screens.php`, `numbering/lease.php`, `unpair-pos-device.php` — si alguno empieza a mutar algo que otro dispositivo necesita saber en caliente, necesita su propio `realtimePublish()` explícito (no lo cubre el mapa).
- `/v1/register` sigue con publish redundante (mapa/derivación genérica + `RegisterAdminService` explícito) — preexistente, no introducido por este cambio, no tocado (no estaba en el alcance nombrado por el owner).
- Deduplicación de invalidaciones en ráfaga (mitigación de "Tormenta de invalidaciones" de la sección de abajo) — resuelta PARCIAL 2026-08-16: el sync quirúrgico de `item`/`contact` debounce (400ms/techo 1500ms, ver `## Modelo quirúrgico 2026-08-16`). El resto de los entities (transaction, category, etc.) sigue sin debounce propio — TanStack amortigua con `staleTime`/`gcTime`.
- Firma JWT en el subscribe del WS (mitigación de "Auth WS débil" de abajo) — sigue MVP.

## Modelo quirúrgico 2026-08-16

Pedido del owner, con precedente en el sistema legacy: *"si yo en el buscador
de productos buscaba Sandwich y me listaba todos, pero desde el panel editaba
el nombre de uno, en la lista automáticamente se modificaba"* — el back
mandaba `{channel, action, id}` (nunca datos) y el cliente actualizaba SOLO
ese registro. Objetivo de esta sesión: llevar el POS a ese mismo modelo para
`item`/`contact` (las dos colecciones grandes — tenants con 5000+ productos y
10000+ clientes), sin tocar la restricción de seguridad del `ws-server`.

### Por qué no van datos en el evento WS (no se negocia)

El `ws-server` NO autentica (decisión 5, arriba) — el browser se suscribe a
`<companyId>:invalidate` confiando en que su JWT es el único origen de ese
`companyId`, pero el canal en sí no lo verifica. Meter el precio/nombre/stock
del ítem en el evento significaría que cualquiera que adivine o conozca un
`companyId` ajeno se bajaría el catálogo y los precios de ese tenant sin
autenticar nada — el `ws-server` reenvía a TODO suscriptor del canal, no solo
a los legítimos. Por eso el evento sigue siendo señal pura (`entity`, `op`,
`id`/`ids`, `scope`) y el dato se pide por HTTP autenticado (Bearer del
device, `apiAuthTenant`) — la superficie sensible pasa siempre por un canal
que sí verifica el tenant.

**Qué haría falta para que sí fuera seguro poner payload en el WS** (no
implementado, para cuando/si se decida hacerlo): firmar el `subscribe` con un
JWT corto (el mismo `_jwt`/`_jwt_panel` que ya existe) y que el `ws-server`
lo valide contra el `companyId` del canal antes de aceptar la suscripción —
básicamente lo que la mitigación "Auth WS débil" de la sección de riesgos ya
proponía. Sin eso, el payload es una fuga de datos entre tenants.

### Ítems y clientes: fetch por id, no invalidar `pos-bootstrap`

Antes: cualquier cambio de `item`/`contact` invalidaba `["pos-bootstrap"]` →
el POS volvía a bajar `items` + `customers` enteros. Con 5000 productos y 30
tablets, un cambio de precio eran 30 descargas completas; con 10000+
clientes, peor.

Ahora (`frontend/hooks/use-realtime-sync.ts`, `frontend/lib/catalog/
realtime-catalog-sync.ts`):

- El evento trae `id`/`ids` (nunca datos, ver arriba). El POS pide SOLO esos
  registros por un BFF nuevo y los mergea en `useCatalogStore` con
  `patchItem(s)`/`patchCustomer(s)` — sin tocar la query `pos-bootstrap`.
- **`delete` no pide nada** — legacy exacto (`{action:"delete",id}` borraba
  local sin request; pedirlo sería un 404 garantizado). `removeItem(s)`/
  `removeCustomer(s)` sacan el registro del store, sincrónico.
- **Item desactivado/no-vendible**: el BFF filtra por `isSellableItemRow`
  (activo + `canSale`) — un `update` que dejó un ítem no-vendible vuelve del
  fetch como "no está", mismo tratamiento que un borrado real (se saca del
  store, no queda fantasma). Contactos no tienen este filtro: un contacto
  archivado SÍ se patchea (solo un `delete` explícito lo saca).
- **Ráfaga**: los ids se acumulan en un Set por request de sync y se
  resuelven con debounce — 400ms de silencio (trailing) con techo duro de
  1500ms (una ráfaga continua, ej. 20 ediciones seguidas desde el panel, no
  puede diferir el sync indefinidamente). Una ventana de debounce colapsa a
  UNA request por colección (items, customers), no N.
- **Fallo del fetch** (red, 5xx, parseo): fallback a invalidar
  `["pos-bootstrap"]` completo — mejor la recarga cara que quedarse con datos
  viejos en silencio.
- **Carrera con `useCatalogSeed`**: un `patchItem` que llega mientras un
  refetch de `pos-bootstrap` está en vuelo (register switch, expiración de
  `staleTime`, resync post-reconexión) podía pisarse en cualquiera de los dos
  sentidos. `lib/catalog/store.ts` guarda `lastPatchedAt`; `useCatalogSeed`
  compara contra `dataUpdatedAt` del bootstrap y descarta un `hydrate()` más
  viejo que el último patch aplicado (ver `hooks/use-catalog-seed.ts`).

### BFF: bulk-get, no un endpoint por id

El owner corrigió el diseño en el camino: un bulk-edit del panel (ej. 1000
productos) dispara UN evento con 1000 ids — pedirlos uno por uno serían 1000
requests. La ruta correcta es "traeme ESTOS ids" en una sola query, no
"traeme este id" repetido N veces:

- `POST /v1/items?resource=bulk-get` / `POST /v1/contacts?resource=bulk-get`
  (`api/v1/items.php`, `api/v1/contacts.php`) — body `{ids:[...]}`, `WHERE
  companyId = ? AND itemId/contactId IN (...)`. POST (no GET con ids en query
  string) por dos motivos: el largo de URL con miles de ids, y que ni el
  browser ni un proxy intermedio cachean un POST — la caja siempre recibe el
  dato fresco, justo el punto de un trigger "esto cambió recién". Techo de
  2000 ids por request (guarda de borde, no el camino normal).
  Aislamiento multi-tenant: `companyId` SIEMPRE del JWT — un id que no es del
  tenant del token simplemente no aparece en la respuesta.
  `bulk-get` está exceptuado del guard "pos-app es GET-only" de `items.php`
  (`bootstrap.php` lo excluye de `realtimeAfterMutation` vía
  `skipResources` — es una LECTURA que usa POST solo por tamaño de body, no
  debe disparar su propio evento fantasma).
- `POST /api/pos/items-batch` / `POST /api/pos/customers-batch`
  (`frontend/app/api/pos/*-batch/route.ts`) — BFF fino sobre lo de arriba:
  valida body, cachea el Bearer del device vía `bffProxy` (mismo cliente que
  el resto de `/api/pos/*`), reshapea con `@/lib/pos-bff/reshape.ts` (única
  fuente de verdad del shape `PosItem`/`PosCustomer`, compartida con `/api/
  pos/bootstrap` — evita que los dos caminos diverjan y `patchItem` meta un
  objeto con shape distinto en el store).

### Stock: batching, no umbral

`Inventory::manageStock()` (única puerta de todo movimiento de stock, 27
callers) acumulaba UN evento sin id por request (hardening 2026-08-15). Con
el sync quirúrgico ya armado, "sin id" significaba "invalidar `pos-bootstrap`
completo en cada venta de cualquier caja" — peor que el polling que esto
reemplazó, en un tenant de 30 tablets con ventas cada pocos segundos.

Ahora `manageStock()` acumula los **itemIds** tocados (no solo un flag) y
`flushRealtimeStockEvents()` — registrada como `register_shutdown_function`,
corre una sola vez al final del request — publica UN evento `item` con TODOS
esos ids en `ids`. Una venta de N líneas (N ítems distintos) es un evento con
N ids, no N eventos ni un evento ciego. El owner planteó primero un umbral de
cantidad (arriba del cual degradar a "recargar todo") y después lo reemplazó:
con `bulk-get` resolviendo cualquier cantidad de ids en una sola query, no
hace falta — el batch entero viaja, sin techo de negocio (el BFF sí tiene el
techo de borde de 2000 mencionado arriba, defensivo, no normal).

`companyId` explícito: `manageStock()` puede correr sin request HTTP (jobs,
`ItemImporter`) — el acumulador guarda el `companyId` del primer caller del
batch, igual que antes.

### Verificación

`api/lib/Sales/verify_chain/verify_realtime.php` (paso 3.5 de `run.sh`)
ahora cubre:

1. 5 movimientos sobre el MISMO item → 1 evento, `ids` con 1 id (dedup
   dentro del batch), `id` viaja `null` (retrocompat).
2. 2 movimientos sobre items DISTINTOS → 1 evento, `ids` con los 2 ids (sin
   umbral).
3. Anulación de transacción → `transaction`/`all` (sin cambios, regression
   guard).

`Inventory::flushRealtimeStockEvents()` es pública e idempotente (drena y
resetea su propio acumulador) para que el arnés la invoque explícito y
simule fin-de-request sin esperar al shutdown real de PHP.

### Auditoría de cobertura — "todo lo que usa /pos y se configura en /panel"

El owner pidió recorrer el payload de `/api/pos/bootstrap` entero (la
definición operativa de "lo que el POS usa") y confirmar la cadena completa:
¿el endpoint publica? → ¿el `entity` tiene queryKey? → ¿la UI del POS
re-renderiza? Estado al cierre de esta sesión:

| Qué (bootstrap) | Entity real | Modelo | Estado |
|---|---|---|---|
| Ítems | `item` | Quirúrgico (fetch por id/batch) | Nuevo — antes recargaba `pos-bootstrap` entero |
| Clientes | `contact` | Quirúrgico | Nuevo — antes recargaba `pos-bootstrap` entero |
| Usuarios (PIN/vendedor) | `user` | Recarga `pos-bootstrap` | Arreglado — sin `pos-bootstrap` en el mapa |
| Config tenant + sucursal activa | `setting` | Recarga `pos-bootstrap` | Ya cubierto |
| Sucursales (billing/tin/coords) | `outlet` | Recarga `pos-bootstrap` | Arreglado — sin `pos-bootstrap` en el mapa |
| Cajas + hotkeys | `register` | Recarga `pos-bootstrap` + `pos-hotkeys` | Ya cubierto |
| Medios de pago | `payment-method` | Recarga `pos-bootstrap` | Ya cubierto |
| Impuestos (motor de IVA del carrito) | `tax` | Recarga `pos-bootstrap` | Arreglado — sin `pos-bootstrap` en el mapa |
| Categorías / Marcas (inline en `PosItem`) | `category` / `brand` | Recarga `pos-bootstrap` | Ya cubierto |
| Etiquetas | `tag` | — | Sin gap real: `PosItem` no trae tags, el POS no las renderiza (cadena se corta en paso 3) |
| Impresoras por caja | `printer_binding` | Recarga `["printer-bindings"]` | Arreglado — la key vieja `"printJob"` no matcheaba NINGÚN entity real (ni éste ni `print-job`) |
| Add-ons por ítem | `item` (alias de `item_addons.php`) | Recarga `["item-addons"]` | Arreglado — sin queryKey propia |
| Direcciones de cliente | `contact` (alias de `customer_address.php`) | Recarga `["customerAddress"]` | Arreglado — sin queryKey propia |
| Packs / vales de pack | `pack` | Recarga `["sold-packs"]` | Arreglado — faltaba, saldo del contacto no se actualizaba solo |
| Conteo de inventario | `inventory_count` | Recarga `["inventory-counts"]` | Arreglado — key vieja con guion medio (`"inventory-count"`) nunca matcheaba el entity real (guion bajo, del nombre de archivo PHP) |
| Transferencia de stock | `stock_transfer` | Recarga `["stock-transfers"]` | Arreglado — mismo bug que arriba |
| Sectores/mesas (Espacios) | `space` (alias de `space-sectors.php`) | Recarga `["space-sectors"], ...` | Ya cubierto |
| Line items de una orden | `order` (alias de `order_items.php`) | Recarga `["orders"]` | Ya cubierto |
| Vouchers | `voucher` | — | Sin consumidor react-query (canje inline en el carrito, no una lista cacheada) |
| Notas de cliente | `contact` (alias de `customer_note.php`) | — | Sin consumidor react-query en el front nuevo (panel legacy PHP no pasa por acá) |

Método: comparar `deriveEntityFromPath`/`endpointMatches`/`$overrides` de
`api/bootstrap.php` (lo que el backend PUEDE publicar) contra
`ENTITY_TO_QUERY_KEYS` de `frontend/hooks/use-realtime-sync.ts` (lo que el
front reconoce) endpoint por endpoint — no una lista de memoria. Cada fila
"Arreglado" tenía una entity real que o no estaba en el mapa, o estaba con un
string que nunca iba a matchear (guion vs guion bajo, alias equivocado).

### Qué quedó fuera (a propósito)

- **`price_list`/`price_list_item`**: publican entity `item` (override de
  `bootstrap.php`, preexistente) pero editar una lista de precios no
  invalida `["price-lists"]`/`["price-list-items"]` propio — un segundo
  browser del panel viendo el listado de listas de precio no se entera hasta
  refrescar. Gap real, encontrado en esta auditoría, no en el alcance
  nombrado por el owner (no está en el payload de `/pos/bootstrap`) — queda
  para una sesión futura.
- **Firma JWT en el subscribe del WS** — sigue MVP (ver "Por qué no van datos
  en el evento WS" arriba). Precondición para poder meter payload en el
  evento, no implementado.
- **Bootstrap del POS bajando 10000+ clientes al arrancar**: fuera de
  alcance de esta sesión (el owner lo pidió como observación, no como
  trabajo). El sync quirúrgico resuelve "cada CAMBIO no baja todo" pero no
  toca la carga INICIAL — un tenant con 10k clientes sigue trayendo los 10k
  en el primer `/api/pos/bootstrap`. Si se ataca, la dirección más obvia es
  que `customers` deje de viajar en el bootstrap y el buscador de clientes
  pegue server-side (mismo patrón que `search.ts` hoy hace en memoria, pero
  con debounce + `/v1/contacts?q=`) — cambio de arquitectura más grande,
  necesita su propio plan.

## Riesgos y mitigaciones

- **Tormenta de invalidaciones** (100 ventas/min en un POS de alto volumen): el cliente debounce queryClient invalidations con `staleTime` y `gcTime` de TanStack — invalidar 10 veces en 1s solo hace 1 refetch real. Si llega a ser problema: agregar `debounce` de 300ms en el hook.
- **WS desconectado por NAT/firewall corporate**: con backoff exponencial reconectamos. El usuario queda con datos cacheados (mismo escenario que polling con red caída) y se sincroniza al volver. Aceptable para MVP.
- **Auth WS débil**: un atacante con companyId ajeno puede suscribirse y recibir señales de "tipo X cambió". NO recibe datos. MVP acepta el riesgo; futuro slice puede agregar firma JWT en el subscribe.
- **ws-server caído**: las mutaciones siguen funcionando (publish es best-effort). Los browsers no se sincronizan hasta que vuelva. Coolify healthcheck + restart automático.

## Cronología de commits

- `02474e3` — Slices A+B: realtimePublish, wire en bootstrap, lib/realtime.ts, useRealtimeSync, RealtimeProvider + wire en PanelAuthGuard.
