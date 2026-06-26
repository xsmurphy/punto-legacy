"use client"

import * as React from "react"
import type { TransactionDetail } from "@/hooks/use-transactions"
import type { PosConfig } from "@/lib/types/pos-bootstrap"
import { Dialog, DialogContent } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Printer } from "lucide-react"
import { formatAmount } from "@/lib/format-money"

interface QuotePrintViewDialogProps {
  tx: TransactionDetail | null
  config: PosConfig | null
  open: boolean
  onOpenChange: (open: boolean) => void
  isLoading?: boolean
}

export function QuotePrintViewDialog({ tx, config, open, onOpenChange, isLoading }: QuotePrintViewDialogProps) {
  function handlePrint() {
    window.print()
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
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
        <div className="flex justify-end gap-2 print:hidden">
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
        @media print {
          body * { visibility: hidden !important; }
          .quote-print-view, .quote-print-view * { visibility: visible !important; }
          .quote-print-view { position: absolute; inset: 0; padding: 24mm 20mm; box-shadow: none; border-radius: 0; }
          .print-hidden { display: none !important; }
        }
        /* En pantalla el preview es una HOJA DE PAPEL blanca — representa el
           documento impreso. Esto es intencional y consistente en light/dark
           mode (igual que invoice previews de Stripe/QuickBooks). El @media print
           le quita sombra/bordes para el papel real. */
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
                {new Date(tx.date.replace(" ", "T")).toLocaleDateString("es", {
                  day: "numeric",
                  month: "long",
                  year: "numeric",
                })}
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
