/**
 * Cliente WebSocket del POS — placeholder Sprint 0.
 *
 * El legacy `app/scripts/ncm-ws.js` implementa un cliente WS que:
 *   - Conecta al servidor `ws-server/` (Node.js WS) del proyecto.
 *   - Permite suscribirse a "canales" (por companyId + outletId).
 *   - Recibe eventos en tiempo real: nuevas órdenes de mesas, actualizaciones
 *     de stock, cambios de config, notificaciones de sincronización.
 *
 * Este módulo es el port TypeScript del cliente WS + el hook `useChannel`
 * para consumirlo en componentes React.
 *
 * TODO (Slice C — Restaurante / Slice A si se necesita real-time en caja):
 *   - Portar desde `app/scripts/ncm-ws.js` la lógica de connect/reconnect.
 *   - Implementar `useChannel(channelId, onMessage)`.
 *   - El servidor WS ya existe en `ws-server/` del repo — no reescribir.
 *
 * Referencia de port:
 *   ws-server/          → servidor existente (no tocar en Sprint 0).
 *   app/scripts/ncm-ws.js → cliente legacy a portar.
 *
 * Ver context/14-app-rewrite-analysis.md §3.4 (subsistema WS).
 * Ver context/16-app-next-rewrite.md §7 Slice C.
 */

"use client"

import * as React from "react"

// ── Types ─────────────────────────────────────────────────────────────────────

export type WsStatus = "disconnected" | "connecting" | "connected" | "error"

export interface WsMessage {
  channel: string
  event: string
  payload: unknown
}

// ── Hook placeholder ──────────────────────────────────────────────────────────

/**
 * Hook para suscribirse a un canal del servidor WS.
 * TODO (Slice C): implementar port de ncm-ws.js.
 *
 * @param channelId  Identificador del canal (ej. `orders:${outletId}`)
 * @param onMessage  Callback con el mensaje recibido
 * @param enabled    Si false, no conecta (útil durante auth loading)
 */
export function useChannel(
  _channelId: string,
  _onMessage: (msg: WsMessage) => void,
  _enabled = true,
): { status: WsStatus } {
  // TODO (Slice C): implementar.
  // Ver app/scripts/ncm-ws.js para el protocolo de mensajes.
  React.useEffect(() => {
    // placeholder — no-op
  }, [])

  return { status: "disconnected" }
}

/**
 * Envía un mensaje a un canal (para acciones que el server broadcastea).
 * TODO (Slice C): implementar.
 */
export function sendMessage(_channelId: string, _event: string, _payload: unknown): void {
  // TODO (Slice C): implementar.
  throw new Error("ncm-ws.sendMessage: no implementado aún — ver TODO en lib/realtime/ncm-ws.ts")
}
