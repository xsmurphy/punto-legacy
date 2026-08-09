"use client"

/**
 * Selector de MODO del POS — modal con cards (owner 2026-08-09).
 *
 * Antes los modos (Orden, Cotización, Remisión, Cita) vivían mezclados con
 * las acciones de la venta en el drawer de Opciones. Son cosas distintas:
 * una acción opera sobre la transacción en curso; un modo CAMBIA el POS
 * entero (UI, CTA, qué acciones aplican). El selector vive ahora en el
 * sidebar y este modal es su única superficie.
 *
 * Colores/íconos por modo salen de MODE_VISUALS (lib/pos/mode-visuals.ts) —
 * fuente única, nunca mapping inline (context/20 §"Colores de modo del POS").
 * Venta es el baseline neutro sin tinte: "cualquier color = no estás
 * cobrando".
 */

import * as React from "react"
import { Check, ShoppingCart, Truck, CalendarPlus } from "lucide-react"

import {
  ResponsiveDialog,
  ResponsiveDialogContent,
  ResponsiveDialogHeader,
  ResponsiveDialogTitle,
} from "@/components/ui/responsive-dialog"
import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"
import { usePosUIStore } from "@/lib/ui/store"
import { useCartStore } from "@/lib/cart/store"
import { MODE_VISUALS } from "@/lib/pos/mode-visuals"
import { toast } from "sonner"

type PosMode = "venta" | "orden" | "cotizacion"

interface ModeCard {
  mode: PosMode | null // null = próximamente, no seleccionable
  label: string
  description: string
  icon: React.ComponentType<{ className?: string }>
  /** Hex de la paleta unificada (MODE_VISUALS). null = neutro (venta). */
  color: string | null
}

const CARDS: ModeCard[] = [
  {
    mode: "venta",
    label: "Venta",
    description: "Cobro al contado o crédito — el flujo normal de caja.",
    icon: ShoppingCart,
    color: MODE_VISUALS.venta.color,
  },
  {
    mode: "orden",
    label: "Orden",
    description: "El pedido va a cocina o preparación; se cobra después.",
    icon: MODE_VISUALS["orden-mostrador"].icon ?? ShoppingCart,
    color: MODE_VISUALS["orden-mostrador"].color,
  },
  {
    mode: "cotizacion",
    label: "Cotización",
    description: "Presupuesto imprimible — no mueve stock ni caja.",
    icon: MODE_VISUALS.cotizacion.icon ?? ShoppingCart,
    color: MODE_VISUALS.cotizacion.color,
  },
  {
    mode: null,
    label: "Remisión",
    description: "Documento de traslado de mercadería.",
    icon: Truck,
    color: null,
  },
  {
    mode: null,
    label: "Cita",
    description: "Reservas y turnos de la agenda.",
    icon: CalendarPlus,
    color: MODE_VISUALS.agenda.color,
  },
]

export function PosModeDialog() {
  const open = usePosUIStore((s) => s.modeDialogOpen)
  const setOpen = usePosUIStore((s) => s.setModeDialogOpen)
  const posMode = useCartStore((s) => s.posMode)
  const setPosMode = useCartStore((s) => s.setPosMode)

  function handleSelect(mode: PosMode) {
    if (mode !== posMode) {
      setPosMode(mode)
      // El toast dice QUÉ cambió del comportamiento, no solo el nombre del
      // modo — el efecto visible está en el CTA del carrito.
      if (mode === "orden") toast.success("Modo orden — el botón principal envía a cocina")
      else if (mode === "cotizacion") toast.success("Modo cotización — el botón principal genera la cotización")
      else toast.success("Modo venta")
    }
    setOpen(false)
  }

  return (
    <ResponsiveDialog open={open} onOpenChange={setOpen}>
      <ResponsiveDialogContent className="sm:max-w-lg">
        <ResponsiveDialogHeader>
          <ResponsiveDialogTitle>Modo del POS</ResponsiveDialogTitle>
        </ResponsiveDialogHeader>

        <div className="grid grid-cols-1 gap-2 py-2 sm:grid-cols-2">
          {CARDS.map((card) => {
            const Icon = card.icon
            const soon = card.mode === null
            const active = !soon && card.mode === posMode
            return (
              <button
                key={card.label}
                type="button"
                disabled={soon}
                onClick={() => card.mode && handleSelect(card.mode)}
                className={cn(
                  "flex items-start gap-3 rounded-lg border p-3 text-left transition-colors",
                  soon
                    ? "cursor-not-allowed opacity-55"
                    : "hover:bg-accent/50",
                  active && "border-foreground/40 bg-accent",
                )}
              >
                <span
                  className={cn(
                    "flex size-9 shrink-0 items-center justify-center rounded-md",
                    // Venta (color null) = tile neutro oscuro, coherente con
                    // "venta no tiene tinte". Los acentos llevan texto oscuro
                    // (contraste AA, ver docblock de mode-visuals).
                    card.color === null && "bg-foreground text-background",
                  )}
                  style={card.color ? { backgroundColor: card.color, color: "black" } : undefined}
                >
                  <Icon className="size-4.5" />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="flex items-center gap-2 text-sm font-medium">
                    {card.label}
                    {active && <Check className="size-3.5" />}
                    {soon && (
                      <Badge variant="secondary" className="text-[10px]">
                        Próximamente
                      </Badge>
                    )}
                  </span>
                  <span className="mt-0.5 block text-xs leading-snug text-muted-foreground">
                    {card.description}
                  </span>
                </span>
              </button>
            )
          })}
        </div>
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}
