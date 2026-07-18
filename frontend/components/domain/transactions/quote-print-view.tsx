"use client"

import * as React from "react"
import type { TransactionDetail } from "@/hooks/use-transactions"
import type { PosConfig } from "@/lib/types/pos-bootstrap"
import { Dialog, DialogContent } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Printer } from "lucide-react"
import { formatAmount } from "@/lib/format-money"
import { formatDateTime } from "@/lib/format-date"
import { buildTicketDataFromTransaction } from "@/lib/hardware/printers/build-ticket-data"
import { usePrintWithPicker } from "@/lib/hardware/printers/print-with-fallback"
import { usePrinterBindings } from "@/hooks/use-printer-bindings"
import { useCatalogStore } from "@/lib/catalog/store"

interface QuotePrintViewDialogProps {
  tx: TransactionDetail | null
  config: PosConfig | null
  open: boolean
  onOpenChange: (open: boolean) => void
  isLoading?: boolean
}

export function QuotePrintViewDialog({ tx, config, open, onOpenChange, isLoading }: QuotePrintViewDialogProps) {
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined)
  const allBindings = bindingsData?.bindings ?? []
  const { requestPrint, pickerDialog } = usePrintWithPicker()

  function handlePrint() {
    if (!tx) return
    // Wrapper compartido: arma TicketData con categoryId real por item (antes
    // hardcodeado a null, rompía el filtrado por categoría de las bindings).
    const ticketData = buildTicketDataFromTransaction(tx, config, "quote")
    // Sin binding para "quote" → printTicketInBrowser (plantilla del docType
    // o fallback genérico) en vez del window.print() del DOM crudo de antes,
    // que quedaba en blanco por el containing block del Dialog (shadcn usa
    // `transform` en DialogContent → rompe el hack de visibility:hidden).
    requestPrint("quote", ticketData, allBindings)
  }

  if (isLoading || !tx) {
    return (
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="max-w-2xl">
          <div className="flex items-center justify-center p-12 text-sm text-muted-foreground">
            Cargando cotización...
          </div>
        </DialogContent>
      </Dialog>
    )
  }

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => onOpenChange(false)}>
              Cerrar
            </Button>
            <Button onClick={handlePrint} className="gap-1.5">
              <Printer className="size-4" />
              Imprimir / PDF
            </Button>
          </div>
          <QuotePrintView tx={tx} config={config} />
        </DialogContent>
      </Dialog>
      {pickerDialog}
    </>
  )
}

interface QuotePrintViewProps {
  tx: TransactionDetail
  config: PosConfig | null
}

export function QuotePrintView({ tx, config }: QuotePrintViewProps) {
  const items = (tx.transactionDatas ?? []).filter((i) => i.status !== 0)
  const subtotal = items.reduce((s, i) => s + i.total, 0)
  const discount = parseFloat(tx.discount) || 0
  const total = parseFloat(tx.total) || 0

  return (
    <>
      <style>{`
        /* Este preview es solo VISUAL en pantalla — representa cómo se ve la
           cotización antes de imprimir. La impresión real (botón "Imprimir /
           PDF") NO usa window.print() sobre este DOM: pasa por
           printTicketInBrowser (plantilla del docType renderizada a HTML de
           ticket, vía iframe oculto) o por printSale si hay binding físico.
           Por eso no hay reglas @media print acá — no aplica. */
        /* En pantalla el preview es una HOJA DE PAPEL blanca — representa el
           documento impreso. Esto es intencional y consistente en light/dark
           mode (igual que invoice previews de Stripe/QuickBooks). */
        .quote-print-view {
          font-family: system-ui, -apple-system, sans-serif;
          font-size: 14px;
          color: #1a1a1a;
          background: #ffffff;
          max-width: 210mm;
          margin: 0 auto;
          padding: 20px 22px;
          border-radius: 10px;
        }
        .quote-print-view table {
          width: 100%;
          border-collapse: collapse;
          margin-top: 12px;
        }
        .quote-print-view th {
          background: #f3f4f6;
          padding: 8px 10px;
          text-align: left;
          font-size: 12px;
          font-weight: 600;
          text-transform: uppercase;
          letter-spacing: 0.04em;
          border-bottom: 2px solid #e5e7eb;
        }
        .quote-print-view td {
          padding: 8px 10px;
          border-bottom: 1px solid #e5e7eb;
          vertical-align: top;
        }
        .quote-print-view .text-right { text-align: right; }
        .quote-print-view .totals { margin-top: 12px; }
        .quote-print-view .totals-row {
          display: flex;
          justify-content: space-between;
          padding: 4px 10px;
          font-size: 13px;
        }
        .quote-print-view .totals-row.total-final {
          font-size: 16px;
          font-weight: 700;
          border-top: 2px solid #1a1a1a;
          margin-top: 4px;
          padding-top: 8px;
        }
        .quote-print-view .footer {
          margin-top: 20px;
          padding-top: 12px;
          border-top: 1px solid #e5e7eb;
          font-size: 12px;
          color: #6b7280;
        }
      `}</style>
      <div className="quote-print-view">
        <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 16 }}>
          <div>
            <h2 style={{ fontSize: 20, fontWeight: 700, margin: 0 }}>
              {(config as { companyName?: string } | null)?.companyName ?? "Cotización"}
            </h2>
          </div>
          <div style={{ textAlign: "right" }}>
            <p style={{ fontWeight: 600, fontSize: 18, margin: 0 }}>COTIZACIÓN</p>
            {tx.documentNo && (
              <p style={{ color: "#6b7280", fontSize: 13, margin: "2px 0 0" }}>
                #{tx.invoicePrefix ? `${tx.invoicePrefix}-${tx.documentNo}` : tx.documentNo}
              </p>
            )}
            {tx.date && (
              <p style={{ color: "#6b7280", fontSize: 13, margin: "2px 0 0" }}>
                {formatDateTime(tx.date, "d 'de' MMMM yyyy")}
              </p>
            )}
          </div>
        </div>

        <div style={{ marginBottom: 14, padding: "8px 12px", background: "#f9fafb", borderRadius: 6 }}>
          <p style={{ margin: 0, fontWeight: 600, fontSize: 12, color: "#6b7280", textTransform: "uppercase", letterSpacing: "0.04em" }}>
            Cliente
          </p>
          <p style={{ margin: "2px 0 0", fontWeight: 600, fontSize: 14 }}>
            {tx.customerName?.trim() || "Sin cliente"}
          </p>
        </div>

        <table>
          <thead>
            <tr>
              <th className="text-right" style={{ width: 50 }}>Cant.</th>
              <th>Descripción</th>
              <th className="text-right">P. Unitario</th>
              <th className="text-right">Subtotal</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item, i) => (
              <tr key={i}>
                <td className="text-right tabular-nums">{item.count}</td>
                <td>
                  <span style={{ fontWeight: 500 }}>{item.name}</span>
                  {item.note && (
                    <span style={{ display: "block", fontSize: 12, color: "#6b7280" }}>{item.note}</span>
                  )}
                  {item.discount > 0 && (
                    <span style={{ display: "block", fontSize: 12, color: "#d97706" }}>
                      Desc. {Math.round(item.discount)}%
                    </span>
                  )}
                </td>
                <td className="text-right tabular-nums">{formatAmount(item.price, config)}</td>
                <td className="text-right tabular-nums">{formatAmount(item.total, config)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="totals" style={{ maxWidth: 280, marginLeft: "auto" }}>
          {subtotal !== total + discount && (
            <div className="totals-row">
              <span>Subtotal</span>
              <span className="tabular-nums">{formatAmount(subtotal, config)}</span>
            </div>
          )}
          {discount > 0 && (
            <div className="totals-row">
              <span>Descuento</span>
              <span className="tabular-nums" style={{ color: "#d97706" }}>
                -{formatAmount(discount, config)}
              </span>
            </div>
          )}
          <div className="totals-row total-final">
            <span>TOTAL</span>
            <span className="tabular-nums">{formatAmount(total, config)}</span>
          </div>
        </div>

        {tx.note && (
          <div style={{ marginTop: 16, padding: "8px 12px", background: "#f9fafb", borderRadius: 6 }}>
            <p style={{ margin: 0, fontWeight: 600, fontSize: 12, color: "#6b7280", textTransform: "uppercase", letterSpacing: "0.04em" }}>
              Observaciones
            </p>
            <p style={{ margin: "4px 0 0", fontSize: 13 }}>{tx.note}</p>
          </div>
        )}

        <div className="footer">
          <p>Esta cotización es válida por 30 días a partir de su fecha de emisión.</p>
          <p>Para confirmar el pedido o consultas, contáctenos.</p>
        </div>
      </div>
    </>
  )
}
