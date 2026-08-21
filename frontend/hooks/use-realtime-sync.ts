"use client"

import * as React from "react"
import { useQueryClient } from "@tanstack/react-query"
import { subscribeRealtime, subscribeReconnect, type InvalidateEvent } from "@/lib/realtime"
import { queueCatalogSync } from "@/lib/catalog/realtime-catalog-sync"
import { runDeltaSync } from "@/lib/catalog/delta-sync"
import { useCatalogStore } from "@/lib/catalog/store"
import { usePosUIStore } from "@/lib/ui/store"

/**
 * Mapeo cerrado entity→queryKeys de TanStack Query. Cuando el server
 * publica `{ entity: "item", op: "update", ... }`, invalidamos los keys
 * listados — solo afecta queries ya cacheadas, no provoca fetch innecesarios.
 *
 * Si agregás un dominio nuevo a frontend, sumá su(s) queryKey(s) acá.
 *
 * IMPORTANTE (context/15 §Modelo quirúrgico, 2026-08-16): para `item` y
 * `contact`, el POS NO usa este mapeo para `pos-bootstrap` — ese caso lo
 * intercepta `useRealtimeSync` ANTES de llegar acá y lo resuelve con fetch
 * puntual por id (`lib/catalog/realtime-catalog-sync.ts`), sin recargar el
 * catálogo entero. Las demás keys de `item`/`contact` (ej. `item-addons`,
 * `customerAddress`) SÍ se siguen invalidando normal, incluso en el POS.
 */
const ENTITY_TO_QUERY_KEYS: Record<string, ReadonlyArray<readonly string[]>> = {
  item:              [
    ["items"], ["item"], ["pos-bootstrap"],
    // Grupos de add-ons por ítem (F4, context/41) — item_addons.php se
    // alias-ea a entity 'item' en el backend (bootstrap.php override), pero
    // sus hooks tienen queryKey propia que NO estaba mapeada acá: editar
    // add-ons de un producto no invalidaba el modal de selección abierto
    // en otra caja/browser (hallazgo del audit 2026-08-16).
    ["item-addons"], ["pos", "item-addons"],
  ],
  // pos-bootstrap: use-pos-bootstrap.ts embeda los clientes con staleTime 5min
  // (ver route.ts `/api/pos/bootstrap`) — sin esto, editar un cliente en admin
  // no llegaba al POS hasta que ese cache expirara solo.
  contact:           [
    ["contacts"], ["contact"], ["customers"], ["team-members"], ["pos-bootstrap"],
    // Direcciones de cliente (customer_address.php se alias-ea a 'contact')
    // — panel (use-contacts.ts) y POS (use-pos-customer-addresses.ts) usan
    // queryKeys propias, ninguna cubierta por las de arriba (mismo hallazgo
    // que item-addons).
    ["customerAddress"], ["pos", "customerAddress"],
  ],
  // user: sin pos-bootstrap hasta el audit 2026-08-16 — el POS SÍ consume
  // `users` del bootstrap (lock-screen PIN, selector de vendedor en línea de
  // venta, ver lock-screen.tsx / seller-picker-dialog.tsx). Editar un
  // team member en el panel no llegaba a la caja hasta el próximo bootstrap.
  user:              [["team"], ["pos-bootstrap"]],
  // outlet: sin pos-bootstrap hasta el audit 2026-08-16 — el bootstrap trae
  // datos de la sucursal activa (billing/tin/phone/coords) que el ticket
  // impreso usa; editarlos en /outlets no se reflejaba en la caja.
  outlet:            [["outlets"], ["pos-bootstrap"]],
  category:          [["categories"], ["taxonomies", "category"], ["pos-bootstrap"]],
  brand:             [["brands"], ["taxonomies", "brand"], ["pos-bootstrap"]],
  // tag: SIN pos-bootstrap a propósito, a diferencia de category/brand. El
  // owner nombró "etiquetas" en el criterio de cobertura (audit 2026-08-16),
  // pero la cadena se corta en el paso 3: `PosItem` no trae tags (ver
  // lib/types/pos-bootstrap.ts) y ningún componente del POS las renderiza
  // hoy — no hay nada que re-render-ear. Si el POS empieza a mostrar/filtrar
  // por tag, sumar `PosItem.tags` al reshape del bootstrap Y agregar
  // pos-bootstrap acá en el mismo cambio.
  tag:               [["tags"], ["taxonomies", "tag"]],
  // tax: sin pos-bootstrap hasta el audit 2026-08-16 — el carrito calcula
  // IVA con `PosBootstrap.taxes` (F2b, context/38); una tasa editada en el
  // panel no llegaba a la caja hasta el próximo bootstrap manual.
  tax:               [["taxes"], ["taxonomies", "tax"], ["pos-bootstrap"]],
  location:          [["outlet-locations"]],
  // `["pos-transaction"]` (singular, SIN id) — F6 context/40: la anulación
  // (SaleVoidService::void) emite `transaction update` y el detalle abierto
  // en `pos-transactions-dialog.tsx` está cacheado bajo `["pos-transaction",
  // encId]` (hooks/use-pos-transactions.ts). El key parcial matchea por
  // prefijo cualquier id abierto, sin depender de que el evento traiga el
  // mismo id (viene el UUID crudo, el detalle cachea con `enc(transactionId)`).
  transaction:       [["reports"], ["transactions"], ["pos-transactions"], ["pos-transaction"], ["dashboard"], ["dashboard-widget"]],
  drawer:            [["reports", "drawers"], ["dashboard"], ["dashboard-widget"]],
  expense:           [["reports", "expenses"], ["dashboard"], ["dashboard-widget"]],
  // setting también invalida pos-bootstrap porque lo usa el POS para leer config del tenant.
  setting:           [["settings"], ["modules"], ["bootstrap"], ["pos-bootstrap"]],
  screen:            [["screens"]],
  "price-list":      [["price-lists"], ["price-list-items"]],
  "parked-sale":     [["parked-sales"]],
  // OJO nombres: la entity real que publica el backend viene del PATH del
  // endpoint (`deriveEntityFromPath`, api/bootstrap.php), no de una
  // convención propia del front. `/v1/inventory_count` y `/v1/stock_transfer`
  // (nombres de archivo PHP, con guion bajo) derivan 'inventory_count' /
  // 'stock_transfer' — las keys de acá tenían guion medio ("inventory-
  // count"/"stock-transfer") y NUNCA matcheaban ningún evento real (audit
  // 2026-08-16: comparar contra lo que el backend puede publicar, no
  // confiar en el nombre "obvio").
  inventory_count:   [["inventory-counts"]],
  stock_transfer:    [["stock-transfers"]],
  // pos-bootstrap: plantillas de impresión ahora viajan embebidas en el
  // bootstrap del POS (context/08 §53, hueco P0 cerrado 2026-08-16) — sin
  // esto, editar una plantilla en el panel no llegaba al dispositivo hasta
  // el próximo bootstrap manual (mismo hallazgo que category/brand/tax/etc,
  // audit 2026-08-16).
  "document-template": [["document-templates"], ["pos-bootstrap"]],
  purchase:          [["purchases"]],
  // register: invalida pos-hotkeys (layout de teclas) y pos-bootstrap (config de caja).
  // El PUT ?resource=hotkeys dispara este evento → refetch de pos-hotkeys es benigno
  // (el servidor ya escribió antes del emit, no hay race).
  register:          [["pos-hotkeys"], ["pos-bootstrap"], ["registers"]],
  // Módulo de Órdenes (O1, context/24-orders-module-plan.md). Invalidación
  // genérica de queryKeys — NO es el canal KDS ({companyId}:kds:{outletId},
  // scope O2), ese lo consumen pantallas de cocina/mozos dedicadas.
  // order_items.php (line items de una orden) se alias-ea acá también
  // (bootstrap.php override) — mismas queryKeys, no hace falta entity aparte.
  order:             [["orders"]],
  // Módulo de Espacios (F2, context/15-espacios-module-plan.md). Invalida tanto
  // el plano operativo del POS (use-pos-spaces.ts) como la config del panel
  // (use-spaces.ts/use-space-sectors.ts, /settings/espacios) — ambos
  // consumen las mismas entidades bajo distintas auth. `space-settlement`
  // (F3, SpaceSettlementService::publishBalance) es prefix-match: invalida
  // TODOS los saldos cacheados, no solo el de la sesión que cambió — barato
  // (son queries livianas) y evita mapear sessionId→queryKey acá.
  // space-sectors.php se alias-ea acá también (bootstrap.php override) —
  // sin eso derivaría 'space-sector' (string distinto, huérfano); YA
  // resuelto, no repetir el override en el front.
  space:             [["pos-spaces"], ["pos-space-sectors"], ["spaces"], ["space-sectors"], ["space-settlement"]],
  // Entities que se publicaban pero el front descartaba en silencio por no
  // tener queryKey (context/15, hallazgo F) — sumadas 2026-08-15.
  "payment-method":  [["payment-methods"], ["finance", "config"], ["pos-bootstrap"]],
  giftcard:          [["reports"]],
  // pack: sold_pack.php/sold_pack_usage.php se alias-ean acá (bootstrap.php
  // override). Faltaba ["sold-packs"] — use-contacts.ts lo consume en el tab
  // financiero del contacto (vales de un cliente); sin esta key, usar un
  // pack no actualizaba el saldo visible hasta un refresh manual (audit
  // 2026-08-16).
  pack:              [["pack-components"], ["items"], ["pos-bootstrap"], ["sold-packs"]],
  // schedule (agenda/citas) todavía no tiene hook propio — vive dentro de
  // useReport("schedule", ...), que ya cae bajo el prefix "reports".
  schedule:          [["reports"]],
  // printer_binding.php (bindings de impresora por caja) — la entity real
  // es 'printer_binding' (nombre de archivo, ver comentario de
  // inventory_count/stock_transfer arriba). La key vieja "printJob" nunca
  // matcheaba NINGÚN entity real: ni éste ('printer_binding') ni el de
  // print-jobs.php ('print-job', ver más abajo) — audit 2026-08-16. El POS
  // SÍ consume esto (cart-panel.tsx, pay-dialog.tsx — routing de impresión
  // al cobrar), así que el hallazgo era real, no cosmético.
  printer_binding:   [["printer-bindings"]],
  // print-job (print-jobs.php, cola de impresión — context/26): sin
  // consumidor react-query hoy (la estación de impresión pollea directo con
  // fetch, ver lib/print-station/api.ts, no TanStack). El evento SÍ se
  // publica (apiAuthTenant lo dispara igual) pero no hay key que sumarle —
  // se deja afuera del mapa a propósito. Si en el futuro el panel arma un
  // listado de auditoría de impresión con useQuery, sumar 'print-job' acá.
  remision:          [["remisiones"]],
  // Endpoints que antes quedaban mudos por el default viejo del mapa
  // (context/15, hallazgo C) — ahora publican solo, sumados sus queryKeys.
  return:            [["returns-for-parent"], ["transactions"], ["pos-transactions"], ["reports"]],
  production:        [["production-orders"], ["production-capacity"], ["waste-events"]],
  waste:             [["waste-events"]],
  // voucher (vouchers.php, context/36 — plan cerrado, "sin implementar" en
  // el front más allá del canje inline del carrito): no hay listado
  // cacheado con react-query — issue/validate/consume son llamadas directas
  // dentro del flujo de venta (lib/cart/store.ts), no una query que
  // invalidar. Se deja afuera a propósito (audit 2026-08-16). Si se agrega
  // un listado de vales (panel o POS) con useQuery, sumar acá.
  //
  // customer_note (customer_note.php, alta de notas): se alias-ea a
  // 'contact' en el backend, pero HOY no tiene ningún consumidor
  // react-query en el front nuevo (el panel legacy en PHP no pasa por acá).
  // No hace falta entry propia — cuando exista un hook de notas, sumar su
  // queryKey al array de 'contact' de arriba, no una entity nueva.
}

/**
 * `panel` recibe TODOS los eventos. `pos` ignora scope="dashboard" — el
 * cajero no quiere reinvalidar items en cada venta propia.
 */
export function useRealtimeSync(clientScope: "panel" | "pos" = "panel") {
  const qc = useQueryClient()
  React.useEffect(() => {
    const unsubInvalidate = subscribeRealtime((ev: InvalidateEvent) => {
      if (clientScope === "pos" && ev.scope === "dashboard") return

      // Sync quirúrgico (POS only, context/15 §Modelo quirúrgico): item y
      // contact con id(s) conocido(s) se resuelven con fetch puntual por id
      // en vez de invalidar `pos-bootstrap` (catálogo/clientes completos —
      // caro con 5000+ items o 10000+ clientes). `ev.ids` (batch, ej. venta
      // multi-línea o bulk-edit del panel) tiene prioridad sobre `ev.id`
      // singular cuando ambos vienen.
      if (clientScope === "pos" && (ev.entity === "item" || ev.entity === "contact")) {
        const ids = ev.ids && ev.ids.length > 0 ? ev.ids : ev.id ? [ev.id] : []
        if (ids.length > 0) {
          queueCatalogSync(ev.entity, ev.op, ids, qc)
          // Las demás queryKeys mapeadas a esta entity (ej. item-addons,
          // customerAddress) SÍ se invalidan normal acá — el sync quirúrgico
          // solo reemplaza `pos-bootstrap`.
          const otherKeys = (ENTITY_TO_QUERY_KEYS[ev.entity] ?? []).filter((k) => k[0] !== "pos-bootstrap")
          otherKeys.forEach((k) => qc.invalidateQueries({ queryKey: [...k], refetchType: "active" }))
          return
        }
        // Sin id ni ids (no debería pasar hoy para item/contact, pero si
        // pasa) → cae al comportamiento genérico de abajo, con
        // pos-bootstrap incluido — mejor la recarga cara que quedarse sin
        // invalidar nada.
      }

      const keys = ENTITY_TO_QUERY_KEYS[ev.entity]
      if (!keys) {
        // El backend ahora publica por default (bootstrap.php invirtió el
        // mapa, ver context/15) — un entity nuevo sin queryKey NO es un bug
        // silencioso: solo avisamos en dev para que se note y se sume acá.
        if (process.env.NODE_ENV !== "production") {
          console.warn(`[realtime] entity "${ev.entity}" sin queryKeys mapeados en ENTITY_TO_QUERY_KEYS — evento ignorado`, ev)
        }
        return
      }
      keys.forEach((k) => qc.invalidateQueries({ queryKey: [...k], refetchType: "active" }))

      // Bug de listas de precios (2026-08-16, ver context/15 §Qué quedó
      // afuera): invalidar ["price-lists"]/["price-list-items"] arriba
      // refresca el LISTADO de listas (settings del panel), pero
      // `/v1/price_resolve` es una mutación sin queryKey — nada la
      // invalida. Sin este bump, un carrito ya armado en el POS sigue
      // cobrando los precios resueltos ANTES de que el admin edite la
      // lista activa. `usePriceContext` (hooks/use-price-context.ts) suma
      // este nonce a su efecto y re-resuelve con el mismo contexto
      // (cliente/lista/líneas) que ya tenía — el realtime solo mueve el
      // estado, no re-implementa la lógica de resolución acá.
      if (clientScope === "pos" && ev.entity === "price-list") {
        usePosUIStore.getState().bumpPriceResolveNonce()
      }
    })

    // Resync tras reconexión (ver lib/realtime.ts): no hay backlog en el
    // ws-server, así que no sabemos qué nos perdimos mientras el WS estuvo
    // caído. Antes (context/15, hallazgo E) esto invalidaba TODO el cache —
    // con 5000+ items o 10000+ clientes, cada reconexión (wifi intermitente,
    // proxy que cierra el socket) volvía a bajar el catálogo entero.
    //
    // Ahora (context/43-sync-incremental.md): en el POS, con `companyId` ya
    // conocido (el store está caliente — sino no habría nada para
    // reconectar), se corre el sync incremental — trae SOLO lo que cambió
    // desde la última marca de agua + los borrados (tabla de lápidas). El
    // panel sigue con el invalidate-todo viejo (no tiene el problema de
    // volumen que motivó este cambio) y el POS cae al mismo invalidate-todo
    // si por algún motivo no hay companyId todavía (fallback seguro, nunca
    // deja de refrescar).
    const unsubReconnect = subscribeReconnect(() => {
      if (clientScope === "pos") {
        const companyId = useCatalogStore.getState().config?.companyId
        if (companyId) {
          void runDeltaSync(String(companyId), qc)
          return
        }
      }
      qc.invalidateQueries()
    })

    return () => {
      unsubInvalidate()
      unsubReconnect()
    }
  }, [qc, clientScope])
}
