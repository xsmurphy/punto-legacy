"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/**
 * Timbrado de la caja — la caja ES el punto de expedición (context/29 §1),
 * así que su timbrado (número, EEE-PPP, vigencia) vive acá y no en la
 * pantalla de facturación electrónica, que solo lo LEE.
 */
export interface RegisterFiscal {
  /** Número de timbrado asignado por la SET. */
  invoiceAuth: string
  /** "EEE-PPP" — establecimiento y punto de expedición (ej. "001-001"). */
  invoicePrefix: string
  /** Inicio de vigencia, "YYYY-MM-DD". */
  invoiceAuthStart: string
  /** Vencimiento, "YYYY-MM-DD". */
  invoiceAuthExpiration: string
}

/**
 * PRÓXIMO número a emitir por tipo de documento — sale de `document_sequence`
 * (context/37), que es el correlativo real de la caja. Nunca llega vacío: la
 * secuencia arranca en 1 y se siembra al crear la caja. Un timbrado no siempre
 * arranca en 1 (la SET puede autorizar desde 2336) y eso es exactamente lo que
 * este campo fija.
 *
 * Al ESCRIBIR, vacío significa "no lo toques" — no "sin numeración".
 *
 * Solo están los documentos que HOY tienen emisión real y numerada por caja.
 * Faltan de la lista del owner, por motivos distintos:
 *   - Nota de crédito / remisión / comprobante interno: el documento todavía no
 *     existe (la remisión tiene el SaleType 10 declarado pero nada lo emite).
 *     Numerar algo que no se emite sería un campo muerto.
 *   - Recibo: los pagos de crédito (type 5) no llevan numeración propia hoy.
 *   - Orden: `pos_order.ordernumber` es por SUCURSAL, no por caja — su
 *     secuencia existe pero con scope `outlet`.
 */
export interface RegisterNumbering {
  factura: string
  cotizacion: string
}

/**
 * Cuántos dígitos ocupa el correlativo al IMPRIMIRSE
 * (`document_sequence.padwidth`, mig 159). 7 = formato fiscal PY
 * (`001-001-0002129`).
 *
 * Va aparte de `RegisterNumbering` porque no es el número: el correlativo se
 * guarda como entero y los ceros a la izquierda son formato. Tipear
 * "00002129" en "Próxima factura" guardaba 2129 y volvía sin ceros — el
 * ancho no tenía dónde vivir. Ahora vive acá.
 *
 * Al ESCRIBIR, vacío significa "no lo toques", igual que en `numbering`.
 */
export interface RegisterPadWidth {
  factura: number
  cotizacion: number
}

/**
 * Fin del rango autorizado por el timbrado. Al agotarse, el asignador corta la
 * emisión en vez de facturar fuera de timbrado. Null = sin techo declarado.
 */
export interface RegisterRange {
  facturaTo: number | null
}

export interface RegisterListItem {
  id: string
  name: string
  outletId: string
  outletName: string
  status: boolean
  /** Control de caja a ciegas: el cajero opera el turno sin ver montos
   *  acumulados (dashboard del menú y arqueo sin totales). Panel-only:
   *  el device POS lo lee pero no puede modificarlo. */
  blindControl: boolean
  fiscal: RegisterFiscal
  numbering: RegisterNumbering
  padWidth: RegisterPadWidth
  range: RegisterRange
  /** Hotkeys de artículo configurados en esta caja que hoy no resuelven
   *  contra su catálogo visible (otra sucursal, o ítem borrado) — el POS ya
   *  degrada esos slots a vacío-reusable (no rompe la grilla), esto es solo
   *  la señal para que el admin sepa qué caja conviene reconfigurar.
   *  Ver RegisterAdminService::orphanHotkeyCounts(). */
  orphanHotkeys: number
}

export function useRegistersAdmin() {
  return useQuery<{ registers: RegisterListItem[] }>({
    queryKey: ["registers", "admin"],
    queryFn: () => api.get<{ registers: RegisterListItem[] }>("/v1/register?resource=listAll"),
    staleTime: 30 * 1000,
  })
}

export function useCreateRegister() {
  const qc = useQueryClient()
  return useMutation<
    { id: string; name: string },
    Error,
    {
      outletId: string
      name: string
      /** Timbrado y numeración van en el alta: la caja es el punto de
       *  expedición y el número desde el que arranca es dato del timbrado. */
      fiscal?: Partial<RegisterFiscal>
      numbering?: Partial<RegisterNumbering>
      /** Dígitos del correlativo impreso (mig 159). Formato, no número. */
      padWidth?: Partial<RegisterPadWidth>
      range?: { facturaTo?: string }
    }
  >({
    mutationFn: (vars) => {
      const payload: Record<string, unknown> = {
        action: "create",
        outletId: vars.outletId,
        name: vars.name,
      }
      if (vars.fiscal !== undefined)    payload.fiscal    = vars.fiscal
      if (vars.numbering !== undefined) payload.numbering = vars.numbering
      if (vars.padWidth !== undefined)  payload.padWidth  = vars.padWidth
      if (vars.range !== undefined)     payload.range     = vars.range
      return api.post<{ id: string; name: string }>("/v1/register", payload)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["registers"] })
      qc.invalidateQueries({ queryKey: ["bootstrap"] })
      qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
    },
  })
}

export function useUpdateRegister() {
  const qc = useQueryClient()
  return useMutation<
    { ok: boolean },
    Error,
    {
      id: string
      name?: string
      status?: boolean
      blindControl?: boolean
      fiscal?: Partial<RegisterFiscal>
      numbering?: Partial<RegisterNumbering>
      /** Dígitos del correlativo impreso (mig 159). Formato, no número. */
      padWidth?: Partial<RegisterPadWidth>
      range?: { facturaTo?: string }
    }
  >({
    mutationFn: (vars) => {
      const payload: Record<string, unknown> = { action: "update", id: vars.id }
      if (vars.name !== undefined)         payload.name         = vars.name
      if (vars.status !== undefined)       payload.status       = vars.status
      if (vars.blindControl !== undefined) payload.blindControl = vars.blindControl
      if (vars.fiscal !== undefined)       payload.fiscal       = vars.fiscal
      if (vars.numbering !== undefined)    payload.numbering    = vars.numbering
      if (vars.padWidth !== undefined)     payload.padWidth     = vars.padWidth
      if (vars.range !== undefined)        payload.range        = vars.range
      return api.post<{ ok: boolean }>("/v1/register", payload)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["registers"] })
      qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
      // El device POS lee el flag vía pos-config (GET ?resource=config).
      qc.invalidateQueries({ queryKey: ["pos-config"] })
    },
  })
}

export function useDeleteRegister() {
  const qc = useQueryClient()
  return useMutation<{ deleted: "soft" | "hard"; reason?: string }, Error, string>({
    mutationFn: (id) =>
      api.post<{ deleted: "soft" | "hard" }>("/v1/register", { action: "delete", id }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["registers"] })
      qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
    },
  })
}
