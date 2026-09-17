"use client"

import * as React from "react"
import { ChevronDown, ChevronRight } from "lucide-react"
import type { SummaryRow } from "@/lib/kds/summary"

/**
 * Vista "Resumen" del KDS — el consolidado del día para ARMAR (context/70).
 *
 * Reemplaza el área de la grilla, no la pantalla: la barra inferior sigue
 * abajo con sus contadores y sus botones. Quien arma bandejas lee de acá
 * cuántas unidades de cada plato salen hoy; las comandas una por una siguen
 * estando a una tecla de distancia.
 *
 * SOLO LECTURA (v1). Acá no se marca nada: el resumen no muestra de qué comanda
 * es cada unidad hasta que se abre el detalle, así que un bump desde esta
 * pantalla sería marcar a ciegas. Los atajos que escriben están bloqueados
 * mientras el resumen está en pantalla (ver `use-kds-hotkeys.ts`).
 *
 * ACÁ SÍ SE SCROLLEA, y es la única parte del KDS donde eso vale. El board
 * pagina en vez de scrollear porque un scroll que nadie va a tocar esconde
 * comandas para siempre en una TV colgada — la información desaparece en
 * silencio. Esta vista es una LISTA que se consulta: quien la lee está parado
 * enfrente con las manos en la bandeja, la recorre entera de arriba hacia
 * abajo y lo que más falta armar ya está primero. Paginar una lista de lectura
 * sería esconderle el final a quien justamente vino a contarlo.
 *
 * Tipografía con `clamp()` sobre `vw` como el resto del KDS: se lee a varios
 * metros. Sin `--kds-col` porque acá no hay columnas — el ancho es la pantalla.
 */

interface SummaryViewProps {
  rows: SummaryRow[]
}

export function KdsSummaryView({ rows }: SummaryViewProps) {
  /** Qué filas tienen el detalle abierto. Efímero: no se persiste ni se comparte. */
  const [expanded, setExpanded] = React.useState<Set<string>>(new Set())

  function toggle(key: string) {
    setExpanded((prev) => {
      const next = new Set(prev)
      if (!next.delete(key)) next.add(key)
      return next
    })
  }

  if (rows.length === 0) {
    return (
      <div className="flex h-full items-center justify-center text-muted-foreground">
        <p style={{ fontSize: "clamp(1rem, 1.5vw, 1.5rem)" }}>Sin pedidos para hoy</p>
      </div>
    )
  }

  return (
    <div className="h-full min-h-0 overflow-y-auto overscroll-contain">
      <ul className="flex flex-col gap-1">
        {rows.map((row) => {
          const open = expanded.has(row.key)
          return (
            <li key={row.key} className="rounded-lg border bg-card">
              <button
                type="button"
                aria-expanded={open}
                aria-label={`${row.name}: ${row.pending} por armar de ${row.total}`}
                onClick={() => toggle(row.key)}
                className="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2 text-left"
              >
                {/* El chevron es la señal de que hay detalle. Ocupa su lugar
                    siempre y solo rota — nada se corre al abrir la fila. */}
                {open ? (
                  <ChevronDown className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                ) : (
                  <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                )}

                <span className="min-w-0 flex-1">
                  <span
                    className="block font-semibold"
                    style={{ fontSize: "clamp(1.125rem, calc(0.75rem + 1.1vw), 2.25rem)" }}
                  >
                    {row.name}
                  </span>
                  {/* Las opciones van SIEMPRE visibles, no dentro del detalle:
                      "Milanesa 18" sin "arroz 10 / puré 8" no alcanza para
                      armar — es la cuenta que el total esconde. */}
                  {row.addons.length > 0 && (
                    <span
                      className="block text-muted-foreground"
                      style={{ fontSize: "clamp(0.875rem, calc(0.6rem + 0.6vw), 1.375rem)" }}
                    >
                      {row.addons.map((a) => `${a.name} ${a.qty}`).join(" · ")}
                    </span>
                  )}
                </span>

                {/* Lo pendiente es EL número de esta pantalla: grande, sólido y
                    alineado a la derecha para que la columna se lea de un
                    barrido vertical. Lo ya armado va atenuado al lado: es
                    control, no trabajo. Ambos se renderizan siempre —también
                    en cero— para que la fila no cambie de forma al marcarse. */}
                <span className="flex shrink-0 items-baseline gap-2 tabular-nums">
                  <span
                    className="font-bold"
                    style={{ fontSize: "clamp(1.5rem, calc(0.9rem + 1.9vw), 3.5rem)" }}
                  >
                    {row.pending}
                  </span>
                  <span
                    className="text-muted-foreground"
                    style={{ fontSize: "clamp(0.875rem, calc(0.6rem + 0.6vw), 1.375rem)" }}
                  >
                    {row.ready} listo
                  </span>
                </span>
              </button>

              {open && (
                <ul
                  className="flex flex-col gap-1 border-t px-3 py-2"
                  style={{ fontSize: "clamp(0.875rem, calc(0.6rem + 0.6vw), 1.375rem)" }}
                >
                  {row.orders.map((ref) => (
                    <li
                      key={ref.orderId}
                      className={`flex items-center gap-3 ${ref.ready ? "text-muted-foreground" : ""}`}
                    >
                      <span className="shrink-0 font-semibold tabular-nums">
                        #{ref.orderNumber ?? "—"}
                      </span>
                      <span className="min-w-0 flex-1 truncate">{ref.customerName ?? ""}</span>
                      <span className="shrink-0 font-semibold tabular-nums">{ref.qty}</span>
                      {/* "Listo" en palabras y no solo en gris: el color por sí
                          solo no distingue a la distancia ni para quien no lo
                          percibe. Ocupa su ancho siempre. */}
                      <span className="w-[5ch] shrink-0 text-right">{ref.ready ? "listo" : ""}</span>
                    </li>
                  ))}
                </ul>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
