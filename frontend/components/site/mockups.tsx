import { BadgeCheck } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { applyMarketTerms, getMarket } from "@/lib/site/markets"
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
        // El color propio es obligatorio: el mockup se usa dentro de escenas
        // oscuras (tabs, spotlight) y sin él hereda el blanco del contenedor
        // y queda invisible sobre su fondo claro.
        "w-full max-w-sm rounded-2xl border bg-background p-5 text-left text-foreground select-none",
        className
      )}
    >
      {label ? (
        <p className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
          {label}
        </p>
      ) : null}
      <p className="text-base font-semibold tracking-tight">
        {applyMarketTerms(title)}
      </p>
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
        <span className="text-sm font-medium">{applyMarketTerms(left)}</span>
        {right ? (
          <span className="text-sm font-semibold tabular-nums">
            {applyMarketTerms(right)}
          </span>
        ) : null}
      </div>
      {sub?.map((s) => (
        <p key={s} className="mt-0.5 text-xs text-muted-foreground">
          {applyMarketTerms(s)}
        </p>
      ))}
    </div>
  )
}

function MockTotal({ left, right }: { left: string; right: string }) {
  return (
    <div className="flex items-center justify-between rounded-lg bg-primary px-4 py-2.5 text-primary-foreground">
      <span className="text-sm font-semibold">{applyMarketTerms(left)}</span>
      <span className="text-sm font-semibold tabular-nums">
        {applyMarketTerms(right)}
      </span>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Mockups de los tabs de módulos del home                             */
/* ------------------------------------------------------------------ */

export function MockupTicket() {
  return (
    <MockFrame label="Caja 1" title="Venta en curso">
      <MockRow left="2× Yerba compuesta 1kg" right="{money:56000}" />
      <MockRow left="1× Shampoo 400ml" right="{money:38000}" />
      <MockRow left="3× Jabón de tocador" right="{money:21000}" />
      <MockRow left="Descuento cliente frecuente" right="{money:-6000}" />
      <MockTotal left="Cobrar" right="{money:109000}" />
    </MockFrame>
  )
}

export function MockupArqueo() {
  return (
    <MockFrame label="Turno noche · Caja 2" title="Arqueo de caja">
      <MockRow left="Apertura" right="{money:500000}" />
      <MockRow left="Ventas en efectivo" right="{money:2140000}" />
      <MockRow left="Retiros" right="{money:-300000}" />
      <MockTotal left="Esperado" right="{money:2340000}" />
    </MockFrame>
  )
}

export function MockupFactura() {
  return (
    <MockFrame label="Factura electrónica" title="001-001-0000482">
      <MockRow
        left="González e Hijos S.A."
        sub={[`${getMarket().terminos.docFiscal} 80012345-6`]}
      />
      <MockRow left="12× Café en grano 1kg" right="{money:450000}" />
      <MockRow left="Gravadas 10%" right="{money:450000}" />
      <MockRow left="IVA 10%" right="{money:45000}" />
      <MockRow left="Total" right="{money:495000}" />
      <div className="flex items-center gap-2">
        <Badge variant="secondary" className="gap-1">
          <BadgeCheck className="size-3.5" />
          Comprobante aprobado
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
      <MockRow
        left="Carmen Ríos"
        right="{money:1180000}"
        sub={["9 compras · última hace 2 días"]}
      />
      <MockRow
        left="Diego Vera"
        right="{money:640000}"
        sub={["4 compras · debe {money:95000}"]}
      />
      <MockRow left="Elvira Ruiz" right="{money:460000}" sub={["3 compras"]} />
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
        <span className="text-sm font-semibold tabular-nums">
          {applyMarketTerms("{money:8420000}")}
        </span>
      </div>
    </MockFrame>
  )
}

export function MockupMesas() {
  const mesas = [
    {
      name: "Mesa 3",
      state: "Ocupada · 25 min",
      total: "{money:128000}",
      busy: true,
    },
    {
      name: "Mesa 7",
      state: "Pedido en cocina",
      total: "{money:96000}",
      busy: true,
    },
    {
      name: "Mesa 9",
      state: "Pidió la cuenta",
      total: "{money:215000}",
      busy: true,
    },
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
                m.busy ? "bg-chart-1" : "bg-muted-foreground/40"
              )}
            />
            <span className="flex flex-col">
              <span className="text-sm font-medium">{m.name}</span>
              <span className="text-xs text-muted-foreground">{m.state}</span>
            </span>
          </span>
          <span className="text-sm font-semibold tabular-nums">
            {applyMarketTerms(m.total)}
          </span>
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
