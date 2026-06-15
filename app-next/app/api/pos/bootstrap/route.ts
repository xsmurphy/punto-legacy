/**
 * BFF — POS Bootstrap (stub / Sprint 0).
 *
 * Este endpoint es el punto de entrada del catálogo del POS. En Slice A,
 * este stub se reemplaza con la lógica real que compone en UNA sola
 * respuesta todo lo que el store de catálogo necesita para hidratar en memoria:
 *   - items vendibles (con precio, categoría, imagen)
 *   - clientes (id, nombre, teléfono)
 *   - config del tenant (moneda, decimales, separadores, impuestos)
 *   - cajas disponibles (registers) del outlet activo
 *   - outlets del tenant
 *   - usuarios del outlet (para atribución)
 *
 * Hoy devuelve un shape tipado vacío / placeholder para que el store
 * pueda wiring-arse sin fetch real.
 *
 * Fuentes upstream (cuando se implemente):
 *   GET /v1/bootstrap       → config + user + outlets
 *   GET /v1/items?pos=1     → items vendibles (itemCanSale=1, itemStatus=1)
 *   GET /v1/contacts        → clientes del tenant
 *   GET /v1/registers       → cajas del outlet activo
 *
 * Ver context/16-app-next-rewrite.md §4 (arquitectura BFF) y §7 Slice A.
 */

import { NextResponse } from "next/server"
import type { PosBootstrap } from "@/lib/types/pos-bootstrap"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(): Promise<NextResponse<PosBootstrap>> {
  // TODO (Slice A): leer cookie _jwt, llamar a las fuentes upstream en paralelo,
  // componer y cachear en Redis / revalidar con stale-while-revalidate.
  const stub: PosBootstrap = {
    config: {
      currency: "",
      decimal: "yes",
      thousand: "dot",
      taxName: "",
      tinName: "",
      country: "",
      companyName: "",
      companyId: "",
      publicUrl: "",
    },
    user: {
      id: "",
      role: 0,
    },
    outlet: {
      id: "",
      name: "",
    },
    registers: [],
    items: [],
    customers: [],
  }

  return NextResponse.json(stub)
}
