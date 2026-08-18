"use client"

import * as React from "react"

import { Input } from "@/components/ui/input"
import { DatePicker } from "@/components/date-picker"
import { Field } from "@/components/domain/purchases/purchase-form-fields"

/**
 * Número de comprobante + timbrado IMPRESOS en el documento del PROVEEDOR
 * (context/29-numeracion-y-exclusividad-de-caja.md §5) — documento ajeno,
 * NO generamos correlativo interno para él (owner 2026-08-17, textual:
 * "capturar el número del proveedor").
 *
 * Compartido por 3 formularios: compra/gasto (`/purchase`, `/purchase/
 * drafts/[id]`), nota de crédito de compra (`/purchase/[id]` — diálogo de
 * NC) y pago a proveedor (`MultiInvoicePaymentDialog`). Antes de esta
 * extracción, compra tenía su propio bloque "Timbrado/Prefijo/Número"
 * duplicado en `/purchase/page.tsx` y `/purchase/drafts/[id]/page.tsx` — NC
 * de compra y pago a proveedor no tenían ningún campo (ver auditoría, mig 144).
 */
export interface SupplierDocumentValue {
  /** "001-001" */
  prefix: string
  /** Correlativo, string para no perder el input mientras se tipea. */
  no: string
  /** Timbrado (antes mal llamado "Auth" en el form). */
  authNo: string
  /** Vencimiento del timbrado — "YYYY-MM-DD" o "". */
  authNoDueDate: string
  /** Fecha del documento del proveedor — solo cuando `showDocDate`. */
  docDate?: string
}

const PREFIX_RE = /^\d{3}-\d{3}$/
const NO_RE = /^\d{1,7}$/

export function supplierDocumentFormatWarning(value: Pick<SupplierDocumentValue, "prefix" | "no">): boolean {
  const prefixOk = value.prefix === "" || PREFIX_RE.test(value.prefix)
  const noOk = value.no === "" || NO_RE.test(value.no)
  return !prefixOk || !noOk
}

interface SupplierDocumentFieldsProps {
  value: SupplierDocumentValue
  onChange: (patch: Partial<SupplierDocumentValue>) => void
  title?: string
  /** NC de compra y pago a proveedor no tienen otro campo de fecha del
   *  documento — compra sí (Fecha factura, ya alimenta invoiceDate), así que
   *  ahí se omite para no duplicar. */
  showDocDate?: boolean
}

/**
 * Validación de formato ADVISORY, no bloqueante: es dato tipeado por un
 * humano mirando un papel, y el campo ya era opcional antes de esta
 * migración (compras sin comprobante formal — gastos varios — son un caso
 * real). Mostrar el error sin deshabilitar el guardado evita trabar al
 * operador por un typo mientras carga.
 */
export function SupplierDocumentFields({
  value,
  onChange,
  title = "Datos de factura",
  showDocDate = false,
}: SupplierDocumentFieldsProps) {
  const showWarning = supplierDocumentFormatWarning(value)

  return (
    <div className="flex flex-col gap-3 rounded-md border bg-background/40 p-3">
      <div className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
        {title}
      </div>
      <div className="grid grid-cols-2 gap-2">
        <Field label="Prefijo" id="supplierDocPrefix">
          <Input
            id="supplierDocPrefix"
            value={value.prefix}
            onChange={(e) => onChange({ prefix: e.target.value })}
            placeholder="001-001"
          />
        </Field>
        <Field label="Número" id="supplierDocNo">
          <Input
            id="supplierDocNo"
            type="number"
            min={0}
            step={1}
            value={value.no}
            onChange={(e) => onChange({ no: e.target.value })}
            placeholder="0000001"
          />
        </Field>
      </div>
      {showWarning && (
        <p className="text-xs text-muted-foreground">
          Formato esperado del comprobante: 001-001-1234567 — revisá si no coincide (no bloquea el guardado).
        </p>
      )}
      {showDocDate && (
        <Field label="Fecha del documento" id="supplierDocDate">
          <DatePicker
            id="supplierDocDate"
            value={value.docDate ?? ""}
            onChange={(v) => onChange({ docDate: v })}
          />
        </Field>
      )}
      <Field label="Timbrado" id="supplierAuthNo">
        <Input
          id="supplierAuthNo"
          value={value.authNo}
          onChange={(e) => onChange({ authNo: e.target.value })}
          placeholder="Opcional"
        />
      </Field>
      {value.authNo !== "" && (
        <Field label="Vencimiento del timbrado" id="supplierAuthNoDueDate">
          <DatePicker
            id="supplierAuthNoDueDate"
            value={value.authNoDueDate}
            onChange={(v) => onChange({ authNoDueDate: v })}
          />
        </Field>
      )}
    </div>
  )
}
