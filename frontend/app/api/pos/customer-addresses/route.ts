/**
 * BFF — POS Customer Addresses (context/27-delivery-sla-plan.md PARTE D +
 * F-D-0/F-D-1 delivery, §PARTE B).
 *
 * Front (POS) → /api/pos/customer-addresses → api/v1/customer_address.php
 *
 * El device POS no tiene sesión de panel (`_jwt_panel`); pega contra
 * `/v1/customer_address.php` con el Bearer del device (realm `pos-app`, ya
 * habilitado server-side — customer_address.php acepta
 * `apiAuthTenant(['panel', 'pos-app'])`). Reenvía el query string completo
 * (customerId, addressId) — solo GET (listar direcciones del cliente) y POST
 * (crear una dirección nueva) hacen falta para el flujo de delivery del
 * carrito; editar/eliminar direcciones sigue siendo exclusivo del panel
 * (tab "Direcciones" del contacto).
 *
 * GET  /api/pos/customer-addresses?customerId=<uuid>  → lista de direcciones
 * POST /api/pos/customer-addresses                    → crea una dirección
 *
 * Auth: Bearer token del device (_jwt en localStorage).
 */

import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/customer_address.php${req.nextUrl.search}`, requireBearer: true })
}

export async function POST(req: NextRequest) {
  return bffProxy(req, { upstreamPath: `/v1/customer_address.php${req.nextUrl.search}`, requireBearer: true })
}
