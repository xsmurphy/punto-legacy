import type { Metadata } from "next"
import Link from "next/link"
import { Check } from "lucide-react"

import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { WHATSAPP_URL } from "@/lib/site/contacto"
import { SIGNUP_URL } from "@/lib/site/links"
import { CtaFinal } from "@/components/site/cta-final"
import { FaqJsonLd, ProductJsonLd } from "@/components/site/structured-data"
import { getMarket, marketMoney } from "@/lib/site/markets"

const market = getMarket()
const PRECIO = marketMoney(market.plan.precio, market)
const CREDITOS = new Intl.NumberFormat("es-PY").format(market.plan.creditosIa)

export const metadata: Metadata = {
  title: "Precios y planes",
  alternates: { canonical: "/precios" },
  description: `Un solo plan con todo incluido: ${PRECIO} ${market.plan.periodo}. Facturación electrónica ilimitada, usuarios, cajas y productos sin límite.`,
}

const INCLUIDO = [
  {
    label: "Facturación electrónica ilimitada",
    detail: "Sin costo por comprobante ni cupos mensuales.",
  },
  {
    label: "Usuarios ilimitados",
    detail: "Sumá a todo tu equipo, cada uno con sus permisos.",
  },
  {
    label: "Cajas ilimitadas",
    detail: "Todas las terminales que necesite la sucursal.",
  },
  {
    label: "Productos ilimitados",
    detail: "El catálogo entero, con fotos y variantes.",
  },
  {
    label: "Transacciones ilimitadas",
    detail: "Vendé todo lo que puedas: no cobramos por ticket.",
  },
  {
    label: `${CREDITOS} créditos de IA por mes`,
    detail: "Para preguntarle a Punto AI por tus números y tus reportes.",
  },
  {
    label: "Soporte online 24/7",
    detail: "Te respondemos cualquier día, a cualquier hora.",
  },
]

const FAQS = [
  {
    q: "¿El precio es por negocio o por sucursal?",
    a: `Por sucursal. Cada local paga ${PRECIO} por mes y adentro no hay límites: todas las cajas, todos los usuarios y todos los productos que necesites. Si abrís una segunda sucursal, se suma solo esa.`,
  },
  {
    q: "¿La facturación electrónica se cobra aparte?",
    a: "No. Está incluida y es ilimitada — no cobramos por comprobante emitido ni vendemos paquetes de facturas. Emitís las que tu negocio necesite.",
  },
  {
    q: "¿Hay contrato o permanencia?",
    a: "No hay contrato ni permanencia. Se paga mes a mes y podés dar de baja cuando quieras; tus datos siguen siendo tuyos y te los llevás cuando lo pidas.",
  },
  {
    q: "¿Puedo ver el sistema antes de contratar?",
    a: "Sí. Escribinos y te lo mostramos funcionando con casos de tu rubro, para que veas cómo cargarías tus productos y cómo se cobra en tu mostrador antes de decidir.",
  },
  {
    q: "¿Qué pasa si se corta internet?",
    a: "El punto de venta sigue funcionando: la venta se emite igual y se sincroniza sola cuando vuelve la conexión. No se pierde nada ni hay que cargar nada dos veces.",
  },
  {
    q: "¿Necesito comprar equipos especiales?",
    a: "No. Punto funciona en la computadora, la tablet o el teléfono que ya tenés, desde el navegador. Si querés impresora de tickets o lector de código de barras, usás los que tengas o te asesoramos.",
  },
  {
    q: "¿Me ayudan a cargar mis productos?",
    a: "Sí. Te acompañamos en la puesta en marcha y podés importar tu catálogo y tus clientes desde una planilla en vez de cargarlos a mano.",
  },
  {
    q: "¿Qué son los créditos de IA y para qué alcanzan?",
    a: `Son el consumo de Punto AI, el asistente que responde sobre los datos de tu negocio. El plan incluye ${CREDITOS} créditos por mes, que cubren de sobra el uso normal de un comercio: preguntar cómo viene el mes, pedir un reporte o revisar qué producto dejó más margen. Si tu equipo lo usa mucho más, se pueden sumar créditos aparte.`,
  },
  {
    q: "¿El precio promocional sube después?",
    a: "El precio promocional se mantiene mientras tu cuenta siga activa. Si más adelante cambia la lista, te avisamos con anticipación.",
  },
]

export default function PreciosPage() {
  return (
    <div className="pt-16">
      <ProductJsonLd />
      <FaqJsonLd faqs={FAQS} />
      {/* Encabezado + plan */}
      <section className="mx-auto w-full max-w-6xl px-4 py-20 md:px-6 md:py-28">
        <div className="mx-auto max-w-3xl text-center">
          <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
            Precios
          </p>
          {/* razón: escala display de marketing, no aplica escala panel (§14) */}
          <h1 className="mt-4 text-4xl font-semibold tracking-tight text-balance md:text-6xl">
            Un solo plan, con todo adentro
          </h1>
          <p className="mx-auto mt-5 max-w-2xl text-lg text-pretty text-muted-foreground md:text-xl">
            Sin versiones recortadas ni módulos que se desbloquean pagando de
            más. Un precio por sucursal y el sistema completo desde el primer
            día.
          </p>
        </div>

        <div className="mx-auto mt-12 max-w-2xl">
          <div className="overflow-hidden rounded-3xl border bg-muted/40">
            <div className="flex flex-col items-center gap-4 border-b px-8 py-10 text-center md:px-12">
              {market.plan.badge ? (
                <Badge className="rounded-full bg-foreground text-background hover:bg-foreground">
                  {market.plan.badge}
                </Badge>
              ) : null}
              <div className="flex items-baseline justify-center gap-2">
                {/* razón: escala display de marketing, no aplica escala panel (§14) */}
                <span className="text-5xl font-semibold tracking-tight tabular-nums md:text-6xl">
                  {PRECIO}
                </span>
              </div>
              <p className="text-base text-muted-foreground">
                {market.plan.periodo}
              </p>
              <div className="mt-2 flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                <Button asChild size="lg" className="rounded-full px-7">
                  <Link href={SIGNUP_URL}>Empezar</Link>
                </Button>
                <Button
                  asChild
                  size="lg"
                  variant="outline"
                  className="rounded-full px-7"
                >
                  <Link
                    href={WHATSAPP_URL}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    Escribinos
                  </Link>
                </Button>
              </div>
              <p className="text-sm text-muted-foreground">
                Se paga mes a mes, sin contrato.
              </p>
            </div>

            <ul className="grid gap-x-8 gap-y-5 px-8 py-10 md:grid-cols-2 md:px-12">
              {INCLUIDO.map((item) => (
                <li key={item.label} className="flex items-start gap-3">
                  <Check className="mt-0.5 size-4 shrink-0 text-chart-1" />
                  <span className="flex flex-col gap-0.5">
                    <span className="text-sm font-medium">{item.label}</span>
                    <span className="text-sm text-muted-foreground">
                      {item.detail}
                    </span>
                  </span>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </section>

      {/* Preguntas frecuentes */}
      <section className="mx-auto w-full max-w-3xl px-4 pb-20 md:px-6 md:pb-28">
        {/* razón: escala display de marketing, no aplica escala panel (§14) */}
        <h2 className="text-3xl font-semibold tracking-tight text-balance md:text-5xl">
          Preguntas frecuentes
        </h2>
        <Accordion type="single" collapsible className="mt-8 w-full">
          {FAQS.map((faq) => (
            <AccordionItem key={faq.q} value={faq.q}>
              <AccordionTrigger className="text-left text-base font-medium">
                {faq.q}
              </AccordionTrigger>
              <AccordionContent className="text-base text-muted-foreground">
                {faq.a}
              </AccordionContent>
            </AccordionItem>
          ))}
        </Accordion>
      </section>

      <CtaFinal />
    </div>
  )
}
