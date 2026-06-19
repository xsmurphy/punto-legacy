# Plan: Sincronización en tiempo real panel ↔ POS

**Estado:** En ejecución (iniciado 2026-06-19).
**Objetivo:** cuando un usuario muta un dato (item, cliente, venta, etc.) en panel o POS, todos los browsers abiertos del mismo tenant invalidan sus queries TanStack y reflejan el cambio sin recargar.

## Casos de uso

1. **Panel → POS**: el dueño edita el precio de un item en panel-next → la tablet del POS lo refleja sin recargar.
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
4. **Shape del evento**:
   ```json
   {
     "event": "invalidate",
     "data": {
       "entity": "item" | "contact" | "transaction" | "drawer" | "expense" | "outlet" | "setting" | "category" | "brand" | "tag" | "tax",
       "op": "create" | "update" | "delete",
       "id": "uuid-or-null",
       "scope": "all" | "dashboard"
     }
   }
   ```
   - `entity` mapea a queryKeys (`item` → `["items", ...]`, `transaction` → `["transactions", "reports", "dashboard"]`, etc.)
   - `scope: "dashboard"` se aplica automáticamente a las mutaciones de `transaction` y `drawer` para no provocar que el POS se reinvalide en cada venta (sería ruido innecesario en el cajero). El panel sí lo recibe.
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
| B | **Cliente WS** — hook `useRealtimeSync`, mapeo entity→queryKeys, wire en layout panel-next y `/pos` | El browser se sincroniza vía WS |
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

### Slice B — Cliente WS (panel-next)

**Archivos:**

- `panel-next/lib/realtime.ts` (NUEVO) — singleton WS client con reconnect exponential backoff:

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

- `panel-next/hooks/use-realtime-sync.ts` (NUEVO) — hook que invalida queries según entity:

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

- `panel-next/components/realtime-provider.tsx` (NUEVO) — abre el WS en cuanto el bootstrap nos entrega `companyId`:

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

- `panel-next/app/(panel)/layout.tsx` y `panel-next/app/(pos)/pos/layout.tsx` (MODIFICAR): leer `companyId` del bootstrap (ya está en algún Provider/Context) y envolver con `<RealtimeProvider>`. Dentro, llamar `useRealtimeSync("panel")` o `useRealtimeSync("pos")` desde un hook stub en el layout. El subagente debe encontrar dónde está el contexto del bootstrap y ajustar.

- `panel-next/.env.example` y `Coolify env vars`: agregar `NEXT_PUBLIC_WS_URL=wss://ws.punto.la`.

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

## Riesgos y mitigaciones

- **Tormenta de invalidaciones** (100 ventas/min en un POS de alto volumen): el cliente debounce queryClient invalidations con `staleTime` y `gcTime` de TanStack — invalidar 10 veces en 1s solo hace 1 refetch real. Si llega a ser problema: agregar `debounce` de 300ms en el hook.
- **WS desconectado por NAT/firewall corporate**: con backoff exponencial reconectamos. El usuario queda con datos cacheados (mismo escenario que polling con red caída) y se sincroniza al volver. Aceptable para MVP.
- **Auth WS débil**: un atacante con companyId ajeno puede suscribirse y recibir señales de "tipo X cambió". NO recibe datos. MVP acepta el riesgo; futuro slice puede agregar firma JWT en el subscribe.
- **ws-server caído**: las mutaciones siguen funcionando (publish es best-effort). Los browsers no se sincronizan hasta que vuelva. Coolify healthcheck + restart automático.

## Cronología de commits

- `02474e3` — Slices A+B: realtimePublish, wire en bootstrap, lib/realtime.ts, useRealtimeSync, RealtimeProvider + wire en PanelAuthGuard.
