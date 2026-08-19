import { BadgeCheck } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
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
      <MockRow left="2× Milanesa napolitana" right="Gs. 145.000" sub={["Con papas · Sin ají"]} />
      <MockRow left="1× Papas rústicas" right="Gs. 48.000" />
      <MockTotal left="Cobrar" right="Gs. 193.000" />
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
      <MockRow left="Lucía Benítez" right="Gs. 1.240.000" sub={["8 compras · última hace 3 días"]} />
      <MockRow left="Marcos Ayala" right="Gs. 780.000" sub={["5 compras · debe Gs. 120.000"]} />
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

export const MODULE_MOCKUPS = {
  ticket: MockupTicket,
  arqueo: MockupArqueo,
  factura: MockupFactura,
  stock: MockupStock,
  clientes: MockupClientes,
  reporte: MockupReporte,
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
/* Mockup de comanda para la sección de rubros del home                */
/* ------------------------------------------------------------------ */

export function MockupComanda() {
  return (
    <MockFrame
      label="La venta, como baja del sistema"
      title="#42 · Ticket"
      className="bg-background/95 backdrop-blur"
    >
      <MockRow
        left="1× Pizza grande"
        sub={["½ Fugazzeta · ½ Napolitana", "Sin aceitunas"]}
      />
      <MockRow left="2× Limonada" right="Gs. 44.000" />
      <div className="flex items-center justify-between">
        <span className="text-xs text-muted-foreground">hace 2 min</span>
        <Button size="sm" variant="secondary" className="pointer-events-none">
          Cobrar
        </Button>
      </div>
    </MockFrame>
  )
}
