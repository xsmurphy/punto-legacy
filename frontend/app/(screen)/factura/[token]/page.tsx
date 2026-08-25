"use client"

/**
 * Portal de consulta del comprador (F6 — context/28-facturacion-electronica-plan.md
 * §Portal). Ruta PÚBLICA: el link va impreso como QR en el comprobante y lo abre
 * alguien que no tiene cuenta en Punto.
 *
 * El token de la URL es la autorización (firmado, ver EInvoice\PortalToken):
 * la página no pide ni guarda ningún dato del visitante, y el backend solo
 * devuelve el documento de esa venta. No hay listado ni navegación a otras
 * facturas — eso exige un segundo factor del titular y es una decisión de
 * producto todavía abierta (ver §Portal del plan).
 *
 * Cliente y no server component: así el token nunca entra en un render del
 * servidor ni en un log de SSR, y la página se sirve estática.
 */

import * as React from "react"
import { use } from "react"
import { Download, ExternalLink, FileWarning, Loader2, ReceiptText } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { EmptyState } from "@/components/empty-state"
import { Separator } from "@/components/ui/separator"
import { Skeleton } from "@/components/ui/skeleton"
import { formatDateTime } from "@/lib/format-date"
import { formatCurrencyAmount } from "@/lib/format-money"
import { CurrencyFlag } from "@/components/ui/country-flag"

interface PortalDocument {
  status: string
  doctype: string
  companyName: string | null
  cdc: string | null
  documentNumber: string | null
  issuedAt: string | null
  cancelledAt: string | null
  saleDate: string | null
  total: number | null
  currency: string | null
  sifenStatus: string | null
  qrUrl: string | null
  kudeAvailable: boolean
}

/** Estado FISCAL primero: es lo único que le dice al comprador si su factura vale. */
function StatusBadge({ doc }: { doc: PortalDocument }) {
  if (doc.status === "cancelled") return <Badge variant="destructive">Anulada</Badge>
  if (doc.sifenStatus) {
    const approved = /aprob/i.test(doc.sifenStatus)
    return <Badge variant={approved ? "default" : "destructive"}>{doc.sifenStatus}</Badge>
  }
  if (doc.status === "issued") return <Badge>Emitida</Badge>
  if (doc.status === "error") return <Badge variant="destructive">Con problemas</Badge>
  return <Badge variant="secondary">En proceso</Badge>
}

function Field({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="text-sm">{value}</dd>
    </div>
  )
}

export default function FacturaPortalPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params)
  const [doc, setDoc] = React.useState<PortalDocument | null>(null)
  const [error, setError] = React.useState<string | null>(null)
  const [loading, setLoading] = React.useState(true)

  React.useEffect(() => {
    // Sin setLoading(true) acá: el estado ya arranca en `true` y el efecto
    // corre una sola vez por token (la URL no cambia sin remontar la página).
    let active = true
    fetch(`/api/v1/einvoice-public?t=${encodeURIComponent(token)}`, { cache: "no-store" })
      .then(async (res) => {
        const body = await res.json().catch(() => null)
        if (!active) return
        if (!res.ok || !body?.ok) {
          setError(body?.error?.message ?? "No pudimos abrir esta factura.")
          return
        }
        setDoc(body.data as PortalDocument)
      })
      .catch(() => {
        if (active) setError("No pudimos conectarnos. Revisá tu conexión y volvé a intentar.")
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => {
      active = false
    }
  }, [token])

  const kudeUrl = `/api/v1/einvoice-public?resource=kude&t=${encodeURIComponent(token)}`
  const isPending = doc !== null && !doc.kudeAvailable
  // `""` cuenta como ausente igual que `null` — el backend normaliza los
  // campos sin valor a string vacío, así que un `??` solo no alcanza.
  const docCurrency = doc?.currency?.trim() || null

  return (
    <main className="mx-auto flex min-h-screen w-full max-w-xl flex-col gap-6 px-4 py-8">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Tu factura electrónica</h1>
        <p className="text-sm text-muted-foreground">
          {doc?.companyName
            ? `Documento emitido por ${doc.companyName}.`
            : "Documento electrónico habilitado por la SET."}
        </p>
      </header>

      {loading ? (
        <Card>
          <CardHeader>
            <Skeleton className="h-5 w-40" />
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-2/3" />
            <Skeleton className="h-9 w-40" />
          </CardContent>
        </Card>
      ) : error ? (
        <EmptyState
          icon={FileWarning}
          title="No encontramos esta factura"
          description={error}
          ghost={false}
        />
      ) : doc ? (
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between gap-3">
              <div>
                <CardTitle>
                  {doc.documentNumber ? `Factura ${doc.documentNumber}` : "Factura electrónica"}
                </CardTitle>
                <CardDescription>
                  {doc.saleDate ? formatDateTime(doc.saleDate, "d MMM yyyy HH:mm") : "—"}
                </CardDescription>
              </div>
              <StatusBadge doc={doc} />
            </div>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field
                label="Total"
                value={
                  doc.total !== null ? (
                    // Bandera + código: el portal lo abre el cliente final y
                    // el monto puede venir en una divisa distinta a la del
                    // comercio. La bandera se lee de un vistazo; el código ISO
                    // sigue escrito al lado, no la reemplaza.
                    //
                    // Sin `?? "PYG"`: `doc.currency` es la moneda DEL
                    // DOCUMENTO, y antes un documento sin moneda se pintaba
                    // como guaraníes —bandera paraguaya incluida— en el portal
                    // que abre el cliente final. Inventar la divisa de un
                    // comprobante es peor que no mostrarla: si no vino, se
                    // pinta el importe solo, sin bandera ni código.
                    <span className="inline-flex items-center gap-1.5">
                      {docCurrency && (
                        <CurrencyFlag code={docCurrency} className="text-base leading-none" />
                      )}
                      <span className="tabular-nums">
                        {formatCurrencyAmount(doc.total, docCurrency)}
                      </span>
                      {docCurrency && (
                        <span className="text-muted-foreground">{docCurrency}</span>
                      )}
                    </span>
                  ) : (
                    "—"
                  )
                }
              />
              <Field
                label="Emitida"
                value={doc.issuedAt ? formatDateTime(doc.issuedAt, "d MMM yyyy HH:mm") : "Pendiente"}
              />
              {doc.cdc && (
                <div className="sm:col-span-2">
                  <Field
                    label="CDC"
                    value={<span className="break-all font-mono text-xs">{doc.cdc}</span>}
                  />
                </div>
              )}
            </dl>

            {isPending && (
              <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                Tu factura se está emitiendo. Volvé a abrir este enlace en unos minutos.
              </p>
            )}

            {doc.cancelledAt && (
              <p className="text-sm text-muted-foreground">
                Este documento fue anulado el {formatDateTime(doc.cancelledAt, "d MMM yyyy HH:mm")}.
              </p>
            )}

            {(doc.kudeAvailable || doc.qrUrl) && <Separator />}

            <div className="flex flex-wrap gap-2">
              {doc.kudeAvailable && (
                <Button asChild>
                  <a href={kudeUrl} target="_blank" rel="noreferrer">
                    <Download className="size-4" />
                    Ver documento (PDF)
                  </a>
                </Button>
              )}
              {doc.qrUrl && (
                <Button asChild variant="outline">
                  {/* Consulta pública en ekuatia (SIFEN): la fuente oficial del
                      estado del documento, no la nuestra. */}
                  <a href={doc.qrUrl} target="_blank" rel="noreferrer">
                    <ExternalLink className="size-4" />
                    Verificar en la SET
                  </a>
                </Button>
              )}
            </div>
          </CardContent>
        </Card>
      ) : (
        <EmptyState
          icon={ReceiptText}
          title="Sin documento"
          description="Esta venta todavía no tiene una factura electrónica asociada."
          ghost={false}
        />
      )}
    </main>
  )
}
