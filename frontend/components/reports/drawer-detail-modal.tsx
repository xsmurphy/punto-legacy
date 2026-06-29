"use client"

import * as React from "react"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { MoneyInput } from "@/components/ui/money-input"
import { Separator } from "@/components/ui/separator"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { type DrawerRow, useCloseDrawerPanel } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { cn } from "@/lib/utils"

interface DrawerDetailModalProps {
  drawer: DrawerRow | null
  onClose: () => void
  /** Llamado después de un cierre exitoso (además de onClose). */
  onClosed: () => void
}

export function DrawerDetailModal({ drawer, onClose, onClosed }: DrawerDetailModalProps) {
  const { data: bootstrap } = useBootstrap()
  const closeDrawer = useCloseDrawerPanel()

  // Estado local del monto contado — se resetea al abrir un nuevo drawer.
  const [countedAmount, setCountedAmount] = React.useState<number | null>(null)
  const prevDrawerIdRef = React.useRef<string | null>(null)

  React.useEffect(() => {
    if (drawer?.drawerId !== prevDrawerIdRef.current) {
      setCountedAmount(null)
      prevDrawerIdRef.current = drawer?.drawerId ?? null
    }
  }, [drawer?.drawerId])

  function handleClose() {
    if (countedAmount === null) return
    const date = new Date().toISOString().slice(0, 19).replace("T", " ")
    closeDrawer.mutate(
      { drawerId: drawer!.drawerId, amount: countedAmount, date },
      {
        onSuccess: () => {
          toast.success("Caja cerrada")
          onClosed()
          onClose()
        },
        onError: (err) => {
          toast.error(err instanceof Error ? err.message : "Error al cerrar la caja")
        },
      },
    )
  }

  const theoretical = drawer ? computeTheoretical(drawer) : 0
  const diff = drawer?.isClosed
    ? parseNum(drawer.closeAmount) - theoretical
    : null

  return (
    <Dialog
      open={drawer !== null}
      onOpenChange={(open) => {
        if (!open) onClose()
      }}
    >
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>
            {drawer?.outletName ?? ""} — {drawer?.registerName ?? ""}
          </DialogTitle>
          <DialogDescription asChild>
            <div>
              {drawer?.isClosed ? (
                <Badge variant="secondary">Cerrada</Badge>
              ) : (
                <Badge variant="default">En curso</Badge>
              )}
            </div>
          </DialogDescription>
        </DialogHeader>

        {drawer && (
          <div className="flex flex-col gap-4 text-sm">
            {/* Apertura */}
            <section className="grid grid-cols-2 gap-x-4 gap-y-1.5">
              <span className="text-muted-foreground">Apertura</span>
              <span className="tabular-nums text-right">{niceDateTime(drawer.openDate)}</span>
              <span className="text-muted-foreground">Abrió</span>
              <span className="text-right">{drawer.openUserName || "—"}</span>
              <span className="text-muted-foreground">Monto inicial</span>
              <span className="tabular-nums text-right">
                {formatMoney(parseNum(drawer.openAmount), bootstrap)}
              </span>
            </section>

            <Separator />

            {/* Movimientos */}
            <section className="flex flex-col gap-1.5">
              <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-1">
                Movimientos
              </p>
              <MovRow label="Ventas" value={parseNum(drawer.sold)} bootstrap={bootstrap} />
              <MovRow label="Ingresos" value={parseNum(drawer.income)} bootstrap={bootstrap} />
              <MovRow
                label="Egresos"
                value={-Math.abs(parseNum(drawer.expense))}
                bootstrap={bootstrap}
              />
              <MovRow
                label="Devoluciones"
                value={-Math.abs(parseNum(drawer.return))}
                bootstrap={bootstrap}
              />
            </section>

            <Separator />

            {/* Monto esperado */}
            <div className="flex justify-between font-medium">
              <span>Monto esperado</span>
              <span className="tabular-nums">{formatMoney(theoretical, bootstrap)}</span>
            </div>

            {/* Sección cierre (solo si está cerrada) */}
            {drawer.isClosed && (
              <>
                <Separator />
                <section className="flex flex-col gap-1.5">
                  <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-1">
                    Cierre
                  </p>
                  <div className="grid grid-cols-2 gap-x-4 gap-y-1.5">
                    <span className="text-muted-foreground">Cerró</span>
                    <span className="text-right">{drawer.closeUserName || "—"}</span>
                    <span className="text-muted-foreground">Fecha cierre</span>
                    <span className="tabular-nums text-right">
                      {niceDateTime(drawer.closeDate ?? "")}
                    </span>
                    <span className="text-muted-foreground">Monto declarado</span>
                    <span className="tabular-nums text-right">
                      {formatMoney(parseNum(drawer.closeAmount), bootstrap)}
                    </span>
                    <span className="text-muted-foreground">Diferencia</span>
                    <span
                      className={cn(
                        "tabular-nums text-right font-medium",
                        diff !== null && Math.abs(diff) < 1
                          ? "text-emerald-600"
                          : diff !== null && diff < 0
                            ? "text-destructive"
                            : "text-amber-600",
                      )}
                    >
                      {diff !== null
                        ? Math.abs(diff) < 1
                          ? "OK"
                          : `${diff > 0 ? "+" : ""}${formatMoney(diff, bootstrap)}`
                        : "—"}
                    </span>
                  </div>
                </section>
              </>
            )}

            {/* Sección cerrar caja (solo si está abierta) */}
            {!drawer.isClosed && (
              <>
                <Separator />
                <section className="flex flex-col gap-3">
                  <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                    Cerrar caja
                  </p>
                  <div className="flex flex-col gap-1.5">
                    <label
                      htmlFor="counted-amount"
                      className="text-sm font-medium leading-none"
                    >
                      Monto contado
                    </label>
                    <MoneyInput
                      id="counted-amount"
                      value={countedAmount}
                      onChange={setCountedAmount}
                      placeholder="0"
                    />
                    {countedAmount !== null && (
                      <p
                        className={cn(
                          "text-xs tabular-nums",
                          Math.abs(countedAmount - theoretical) < 1
                            ? "text-emerald-600"
                            : countedAmount - theoretical < 0
                              ? "text-destructive"
                              : "text-amber-600",
                        )}
                      >
                        {Math.abs(countedAmount - theoretical) < 1
                          ? "Sin diferencia"
                          : countedAmount - theoretical < 0
                            ? `Faltante: ${formatMoney(Math.abs(countedAmount - theoretical), bootstrap)}`
                            : `Sobrante: ${formatMoney(countedAmount - theoretical, bootstrap)}`}
                      </p>
                    )}
                  </div>
                  <Button
                    variant="destructive"
                    disabled={countedAmount === null || closeDrawer.isPending}
                    onClick={handleClose}
                    className="w-full"
                  >
                    {closeDrawer.isPending ? "Cerrando..." : "Cerrar caja"}
                  </Button>
                </section>
              </>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

// ── helpers ────────────────────────────────────────────────────────────────

function parseNum(v: unknown): number {
  if (typeof v === "number") return v
  if (typeof v === "string" && v !== "") {
    const n = Number(v)
    return Number.isFinite(n) ? n : 0
  }
  return 0
}

function computeTheoretical(r: DrawerRow): number {
  const open    = parseNum(r.openAmount)
  const sold    = parseNum(r.sold)
  const expense = parseNum(r.expense)
  const income  = parseNum(r.income)
  const ret     = parseNum(r.return)
  return open + sold + income - expense - Math.abs(ret)
}

function niceDateTime(iso: string): string {
  if (!iso) return "—"
  const d = new Date(iso.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString(undefined, {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  })
}

interface MovRowProps {
  label: string
  value: number
  bootstrap: Parameters<typeof formatMoney>[1]
}

function MovRow({ label, value, bootstrap }: MovRowProps) {
  return (
    <div className="flex justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span
        className={cn(
          "tabular-nums",
          value < 0 ? "text-destructive" : "",
        )}
      >
        {value < 0 ? "-" : ""}
        {formatMoney(Math.abs(value), bootstrap)}
      </span>
    </div>
  )
}
