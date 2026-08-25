"use client"

/**
 * PosTransactionDetailDialog — detalle de UNA transacción dentro del POS,
 * sin listado al lado.
 *
 * Por qué existe: el tab Financiero de Cliente (`AccountStatementSection`,
 * variant="pos") necesita abrir el detalle de una factura/recibo SIN salir
 * del POS. `/transactions/{id}` es una ruta del panel (realm cookie
 * `_jwt_panel`) — el POS opera con Bearer de device (`_jwt`), dos realms que
 * no se cruzan (ver invariante en `lib/api-client.ts`). Antes de esta
 * fecha, `AccountStatementSection` hacía `router.push` a esa ruta desde
 * cualquier variant y sacaba al cajero al panel administrativo (bug
 * reportado por el owner — el POS nunca debe navegar fuera de sí mismo).
 *
 * Reusa `TransactionDetail` de `pos-transactions-dialog.tsx` (mismo resolver
 * que el listado de transacciones del POS — `usePosTransactionDetail`, vía
 * BFF `/api/pos/transactions/[id]` con Bearer del device). F4 de
 * context/39-detalle-transaccion.md (migrar el POS al resolver canónico
 * `TransactionDetailService`) sigue abierta — este dialog usa el mismo
 * servicio que ya usa el resto del POS (`TransactionService::getSingle()`),
 * no inventa un tercero.
 *
 * `transactionId` viaja SIN encodear: `enc()`/`dec()` en el backend PHP son
 * identity (`api/head.php`), así que el `saleId`/`transactionId` crudo que
 * devuelve `useContactStatement` (uuid) sirve directo como `encId`.
 */

import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { TransactionDetail } from "@/components/register/pos-transactions-dialog"

interface Props {
  transactionId: string | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function PosTransactionDetailDialog({ transactionId, open, onOpenChange }: Props) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/* Bucket l — contenido tabular (items + pagos/recibos), ver Regla #2.1
          de context/14-ui-conventions.md.

          `p-0` a mano y NO `sectioned`: éste es el caso de excepción que
          documenta context/20 §4 — el modal monta un módulo entero
          (`TransactionDetail` trae su propio header, cuerpo scrolleable y
          gutter de 24px), no un header/body/footer que el primitive deba
          espaciar.

          El botón de cerrar se superponía al total (reporte del owner). El
          arreglo NO vive acá: el gutter derecho lo repone el header de
          `TransactionDetail`, que es la raíz compartida por este dialog y por
          el split de `PosTransactionsDialog` — los dos lo tenían. */}
      <DialogContent className="sm:max-w-4xl max-h-[85vh] p-0 gap-0 overflow-hidden">
        <DialogHeader className="sr-only">
          <DialogTitle>Detalle de transacción</DialogTitle>
        </DialogHeader>
        <TransactionDetail
          encId={transactionId}
          onClose={() => onOpenChange(false)}
          bordered={false}
        />
      </DialogContent>
    </Dialog>
  )
}
