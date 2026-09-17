"use client"

/**
 * Nombres de etapas de órdenes del comercio, disponibles para cualquier
 * componente vía `useOrderStatusLabels()`.
 *
 * Cada shell los provee desde SU fuente, porque cada uno tiene su propio
 * bootstrap y su propia credencial (un cliente HTTP = un realm):
 *   - panel      → `PanelOrderStatusLabels` (bootstrap del panel)
 *   - caja       → `PosOrderStatusLabels` (config del catálogo: sobrevive offline)
 *   - pantallas  → `OrderStatusLabelsProvider` con el contexto del device
 *
 * Los componentes compartidos (badge, detalle, mapa, mesa) no saben en qué
 * shell están: piden el hook y listo. Sin provider (un árbol que no lo monta)
 * salen los nombres de fábrica — nunca un nombre vacío.
 */

import * as React from "react"

import { useBootstrap } from "@/hooks/use-bootstrap"
import type { Order } from "@/hooks/use-orders"
import { useCatalogStore } from "@/lib/catalog/store"
import {
  normalizeOrderStatusLabels,
  orderStatusLabel,
  orderStatusLabelFor,
  type OrderStatusLabelKey,
  type OrderStatusLabels,
} from "@/lib/orders/order-status-labels"

const OrderStatusLabelsContext = React.createContext<OrderStatusLabels>({})

export function OrderStatusLabelsProvider({
  labels,
  children,
}: {
  labels: unknown
  children: React.ReactNode
}) {
  // Normalizado y memoizado por contenido: el bootstrap refetcheado devuelve
  // un objeto nuevo con los mismos nombres y no tiene por qué re-renderizar
  // todos los consumidores.
  const key = JSON.stringify(normalizeOrderStatusLabels(labels))
  const value = React.useMemo(() => JSON.parse(key) as OrderStatusLabels, [key])
  return <OrderStatusLabelsContext.Provider value={value}>{children}</OrderStatusLabelsContext.Provider>
}

export function PanelOrderStatusLabels({ children }: { children: React.ReactNode }) {
  const { data } = useBootstrap()
  return <OrderStatusLabelsProvider labels={data?.orderStatusLabels}>{children}</OrderStatusLabelsProvider>
}

export function PosOrderStatusLabels({ children }: { children: React.ReactNode }) {
  const labels = useCatalogStore((s) => s.config?.orderStatusLabels)
  return <OrderStatusLabelsProvider labels={labels}>{children}</OrderStatusLabelsProvider>
}

export interface OrderStatusLabelResolver {
  labels: OrderStatusLabels
  /** Nombre de una etapa suelta (filtros, menú de estado, historial). */
  label: (status: OrderStatusLabelKey | string) => string
  /** Nombre de la etapa de una orden completa (resuelve el de envío). */
  labelFor: (order: Pick<Order, "status" | "fulfillment">) => string
}

export function useOrderStatusLabels(): OrderStatusLabelResolver {
  const labels = React.useContext(OrderStatusLabelsContext)
  return React.useMemo(
    () => ({
      labels,
      label: (status) => orderStatusLabel(status, labels),
      labelFor: (order) => orderStatusLabelFor(order, labels),
    }),
    [labels],
  )
}
