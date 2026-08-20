import { BadgeCheck } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"

/*
 * Mini-mockups de UI para el sitio de marketing: cards chicas que imitan
 * pantallas del producto usando solo tokens del design system. Son
 * decorativas (aria-hidden) — nada acá es interactivo de verdad.
 */

function MockFrame({
  label,
  title,
  caption,
  children,
  className,
}: {
  label?: string
  title: string
  caption?: string
  children: React.ReactNode
  className?: string
}) {
  return (
    <div
      aria-hidden
      className={cn(
        "w-full max-w-sm select-none rounded-2xl border bg-background p-5 text-left",
        className,
      )}
    >
      {label ? (
        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          {label}
        </p>
      ) : null}
      <p className="text-base font-semibold tracking-tight">{title}</p>
      {caption ? (
        <p className="mt-0.5 text-sm text-muted-foreground">{caption}</p>
      ) : null}
      <div className="mt-4 flex flex-col gap-3">{children}</div>
    </div>
  )
}

function MockRow({
  left,
  right,
  sub,
}: {
  left: string
  right?: string
  sub?: string[]
}) {
  return (
    <div className="border-b border-border/60 pb-3 last:border-b-0 last:pb-0">
      <div className="flex items-baseline justify-between gap-3">
        <span className="text-sm font-medium">{left}</span>
        {right ? (
          <span className="text-sm font-semibold tabular-nums">{right}</span>
        ) : null}
      </div>
      {sub?.map((s) => (
        <p key={s} className="mt-0.5 text-xs text-muted-foreground">
          {s}
        </p>
      ))}
    </div>
  )
}

function MockTotal({ left, right }: { left: string; right: string }) {
  return (
    <div className="flex items-center justify-between rounded-lg bg-primary px-4 py-2.5 text-primary-foreground">
      <span className="text-sm font-semibold">{left}</span>
      <span className="text-sm font-semibold tabular-nums">{right}</span>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Mockups de los tabs de módulos del home                             */
/* ------------------------------------------------------------------ */

export function MockupTicket() {
  return (
    <MockFrame label="Caja 1" title="Venta en curso">
      <MockRow left="1× Lomito árabe" right="Gs. 35.000" sub={["Sin cebolla · Extra queso"]} />
      <MockRow left="2× Jugo de mburucuyá" right="Gs. 24.000" />
      <MockTotal left="Cobrar" right="Gs. 59.000" />
    </MockFrame>
  )
}

export function MockupArqueo() {
  return (
    <MockFrame label="Turno noche · Caja 2" title="Arqueo de caja">
      <MockRow left="Apertura" right="Gs. 500.000" />
      <MockRow left="Ventas en efectivo" right="Gs. 2.140.000" />
      <MockRow left="Retiros" right="Gs. -300.000" />
      <MockTotal left="Esperado" right="Gs. 2.340.000" />
    </MockFrame>
  )
}

export function MockupFactura() {
  return (
    <MockFrame label="Factura electrónica" title="001-001-0000482">
      <MockRow left="González e Hijos S.A." sub={["RUC 80012345-6"]} />
      <MockRow left="Total" right="Gs. 495.000" sub={["IVA 10% incluido: Gs. 45.000"]} />
      <div className="flex items-center gap-2">
        <Badge variant="secondary" className="gap-1">
          <BadgeCheck className="size-3.5" />
          Aprobada por SIFEN
        </Badge>
      </div>
    </MockFrame>
  )
}

export function MockupStock() {
  return (
    <MockFrame label="Depósito central" title="Por reponer">
      <MockRow left="Aceite 900ml" right="quedan 4" sub={["mínimo 12"]} />
      <MockRow left="Arroz 1kg" right="quedan 7" sub={["mínimo 20"]} />
      <MockRow left="Azúcar 1kg" right="quedan 2" sub={["mínimo 15"]} />
    </MockFrame>
  )
}

export function MockupClientes() {
  return (
    <MockFrame label="Clientes" title="Los que vuelven">
      <MockRow left="Carmen Ríos" right="Gs. 1.180.000" sub={["9 compras · última hace 2 días"]} />
      <MockRow left="Diego Vera" right="Gs. 640.000" sub={["4 compras · debe Gs. 95.000"]} />
      <MockRow left="Elvira Ruiz" right="Gs. 460.000" sub={["3 compras"]} />
    </MockFrame>
  )
}

export function MockupReporte() {
  // Barras decorativas: alturas fijas, token chart-1 (verde Punto).
  const bars = [35, 55, 40, 70, 90, 60, 45]
  return (
    <MockFrame label="Hoy" title="Ventas por hora">
      <div className="flex h-28 items-end gap-2">
        {bars.map((h, i) => (
          <div
            key={i}
            className="flex-1 rounded-sm bg-chart-1/80"
            style={{ height: `${h}%` }}
          />
        ))}
      </div>
      <div className="flex items-baseline justify-between">
        <span className="text-xs text-muted-foreground">08:00 — 20:00</span>
        <span className="text-sm font-semibold tabular-nums">Gs. 8.420.000</span>
      </div>
    </MockFrame>
  )
}

export function MockupMesas() {
  const mesas = [
    { name: "Mesa 3", state: "Ocupada · 25 min", total: "Gs. 128.000", busy: true },
    { name: "Mesa 7", state: "Pedido en cocina", total: "Gs. 96.000", busy: true },
    { name: "Mesa 12", state: "Libre", total: "—", busy: false },
  ]
  return (
    <MockFrame label="Salón" title="Mesas abiertas">
      {mesas.map((m) => (
        <div
          key={m.name}
          className="flex items-center justify-between gap-3 border-b border-border/60 pb-3 last:border-b-0 last:pb-0"
        >
          <span className="flex items-center gap-2.5">
            <span
              className={cn(
                "size-2 rounded-full",
                m.busy ? "bg-chart-1" : "bg-muted-foreground/40",
              )}
            />
            <span className="flex flex-col">
              <span className="text-sm font-medium">{m.name}</span>
              <span className="text-xs text-muted-foreground">{m.state}</span>
            </span>
          </span>
          <span className="text-sm font-semibold tabular-nums">{m.total}</span>
        </div>
      ))}
    </MockFrame>
  )
}

export const MODULE_MOCKUPS = {
  ticket: MockupTicket,
  arqueo: MockupArqueo,
  factura: MockupFactura,
  stock: MockupStock,
  clientes: MockupClientes,
  reporte: MockupReporte,
  mesas: MockupMesas,
} as const

/* ------------------------------------------------------------------ */
/* Mockup genérico data-driven (rubros)                                */
/* ------------------------------------------------------------------ */

export function DataMockup({
  label,
  title,
  caption,
  rows,
  footer,
}: {
  label?: string
  title: string
  caption?: string
  rows: { left: string; right?: string; sub?: string[] }[]
  footer?: { left: string; right: string }
}) {
  return (
    <MockFrame label={label} title={title} caption={caption}>
      {rows.map((r) => (
        <MockRow key={r.left} {...r} />
      ))}
      {footer ? <MockTotal left={footer.left} right={footer.right} /> : null}
    </MockFrame>
  )
}


/* ------------------------------------------------------------------ */
/* Mockup ancho del panel (spotlight del home)                         */
/* ------------------------------------------------------------------ */

/**
 * Dashboard del panel, compuesto con tokens del design system.
 * TODO: reemplazar por un screenshot real del panel cuando esté disponible.
 */
export function MockupPanelDashboard() {
  const bars = [38, 52, 44, 61, 78, 96, 70, 58, 82, 66, 49, 40]
  const stats = [
    { label: "Ventas de hoy", value: "Gs. 8.420.000", delta: "+12% vs. ayer" },
    { label: "Tickets", value: "184", delta: "promedio Gs. 45.760" },
    { label: "Por cobrar", value: "Gs. 2.310.000", delta: "9 clientes" },
  ]
  const top = [
    { name: "Empanada de carne", qty: "142 u.", total: "Gs. 1.278.000" },
    { name: "Café con leche", qty: "98 u.", total: "Gs. 1.470.000" },
    { name: "Combo desayuno", qty: "61 u.", total: "Gs. 1.586.000" },
  ]

  return (
    <div
      aria-hidden
      className="w-full select-none overflow-hidden rounded-2xl border bg-background"
    >
      {/* Barra superior del panel */}
      <div className="flex items-center justify-between border-b px-5 py-3.5">
        <div className="flex items-center gap-2.5">
          <span className="size-2 rounded-full bg-chart-1" />
          <span className="text-sm font-semibold tracking-tight">Resumen del día</span>
        </div>
        <span className="text-xs text-muted-foreground">Todas las sucursales</span>
      </div>

      <div className="grid gap-5 p-5 md:grid-cols-3 md:p-6">
        {stats.map((s) => (
          <div key={s.label} className="flex flex-col gap-1 rounded-xl border p-4">
            <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              {s.label}
            </span>
            <span className="text-xl font-semibold tabular-nums md:text-2xl">
              {s.value}
            </span>
            <span className="text-xs text-muted-foreground">{s.delta}</span>
          </div>
        ))}
      </div>

      <div className="grid gap-5 px-5 pb-6 md:grid-cols-5 md:px-6">
        <div className="flex flex-col gap-4 rounded-xl border p-4 md:col-span-3">
          <div className="flex items-baseline justify-between">
            <span className="text-sm font-semibold tracking-tight">Ventas por hora</span>
            <span className="text-xs text-muted-foreground">08:00 — 20:00</span>
          </div>
          <div className="flex h-32 items-end gap-1.5">
            {bars.map((h, i) => (
              <div
                key={i}
                className="flex-1 rounded-sm bg-chart-1/80"
                style={{ height: `${h}%` }}
              />
            ))}
          </div>
        </div>

        <div className="flex flex-col gap-3 rounded-xl border p-4 md:col-span-2">
          <span className="text-sm font-semibold tracking-tight">Lo más vendido</span>
          {top.map((t) => (
            <div
              key={t.name}
              className="flex items-baseline justify-between gap-3 border-b border-border/60 pb-2.5 last:border-b-0 last:pb-0"
            >
              <span className="flex flex-col">
                <span className="text-sm font-medium">{t.name}</span>
                <span className="text-xs text-muted-foreground">{t.qty}</span>
              </span>
              <span className="text-sm font-semibold tabular-nums">{t.total}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
