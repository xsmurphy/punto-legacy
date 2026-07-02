"use client"

/**
 * Panel de ventas en curso (parked sales).
 *
 * Muestra las ventas guardadas del operador en el outlet activo.
 * "Retomar" restaura el carrito y elimina la venta guardada.
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import { Clock, ShoppingCart } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { EmptyState } from "@/components/empty-state"
import {
  useParkedSales,
  useDeleteParkedSale,
  type ParkedSale,
} from "@/hooks/use-parked-sales"
import { useCartStore, lineSubtotal } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { formatMoney } from "@/lib/format-money"
import { toast } from "sonner"

export function ParkedSalesPanel() {
  const router = useRouter()
  const { data: sales, isLoading } = useParkedSales()
  const deleteParked = useDeleteParkedSale()
  const config = useCatalogStore((s) => s.config)

  const handleResume = (sale: ParkedSale) => {
    // Restaurar el carrito solo si el DELETE confirma en el servidor — evita
    // duplicar la venta si el request falla y el usuario reintenta.
    deleteParked.mutate(sale.id, {
      onSuccess: () => {
        useCartStore.getState().clear()
        useCartStore.setState({
          lines: sale.data?.cart ?? [],
          customer: sale.data?.customer ?? null,
          note: sale.data?.notes ?? null,
        })
        toast.success("Venta retomada")
        router.push('/pos')
      },
      onError: () => toast.error("No se pudo retomar la venta guardada"),
    })
  }

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2 p-3">
        {[1, 2, 3].map((i) => (
          <Skeleton key={i} className="h-16 w-full rounded-lg" />
        ))}
      </div>
    )
  }

  if (!sales || sales.length === 0) {
    return (
      <EmptyState
        icon={ShoppingCart}
        title="Sin ventas guardadas"
        description="Las ventas que pongas en espera desde el POS van a aparecer acá para retomarlas más tarde."
      />
    )
  }

  return (
    <div className="flex flex-col gap-1 p-2">
      {sales.map((sale) => {
        // Guard: una venta guardada corrupta (data/cart null) NO debe tumbar toda
        // la página. Se renderiza con 0 items para que el cajero pueda verla/borrarla.
        const cart = sale.data?.cart ?? []
        const itemCount = cart.reduce((sum, l) => sum + l.qty, 0)
        const total = cart.reduce((sum, l) => sum + lineSubtotal(l, false), 0)
        const timeLabel = formatRelativeTime(sale.createdAt)

        return (
          <div
            key={sale.id}
            className="flex items-center gap-3 rounded-lg border border-border bg-muted/20 px-3 py-2.5"
          >
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-foreground truncate">
                {sale.data?.title?.trim()
                  ? sale.data.title
                  : `${itemCount} ${itemCount === 1 ? "ítem" : "ítems"}`}
                {sale.data?.customer && (
                  <span className="ml-1.5 text-muted-foreground">
                    · {sale.data.customer.name}
                  </span>
                )}
              </p>
              {sale.data?.title?.trim() && (
                <p className="text-xs text-muted-foreground">
                  {itemCount} {itemCount === 1 ? "ítem" : "ítems"}
                </p>
              )}
              <div className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                <Clock className="size-3 shrink-0" />
                <span>{timeLabel}</span>
                <span>·</span>
                <span className="font-medium tabular-nums text-foreground">
                  {formatMoney(total, config)}
                </span>
              </div>
            </div>
            <Button
              size="sm"
              variant="outline"
              onClick={() => handleResume(sale)}
              disabled={deleteParked.isPending}
            >
              Retomar
            </Button>
          </div>
        )
      })}
    </div>
  )
}

function formatRelativeTime(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime()
  const minutes = Math.floor(diff / 60_000)
  if (minutes < 1) return "ahora"
  if (minutes < 60) return `hace ${minutes} min`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `hace ${hours} h`
  return `hace ${Math.floor(hours / 24)} d`
}
