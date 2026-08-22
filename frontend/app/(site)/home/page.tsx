import type { Metadata } from "next"

import { CtaFinal } from "@/components/site/cta-final"
import { FeaturesGrid } from "@/components/site/features-grid"
import { SiteHero } from "@/components/site/hero"
import { getMarket } from "@/lib/site/markets"
import { ModulesTabs } from "@/components/site/modules-tabs"
import { ProductSpotlight } from "@/components/site/product-spotlight"

export const metadata: Metadata = {
  title: "Sistema de punto de venta y facturación electrónica",
  alternates: { canonical: "/" },
  description:
    "Punto de Venta, panel de administración y un asistente con IA que analiza tus datos. Facturación electrónica, stock y clientes en un mismo sistema.",
  openGraph: {
    title: "Punto — Sistema de punto de venta y facturación electrónica",
    description:
      "Punto de Venta, panel de administración e IA integrada, en un mismo sistema.",
    locale: getMarket().locale,
    type: "website",
  },
}

export default function HomePage() {
  return (
    <>
      <SiteHero />
      <ProductSpotlight />
      <ModulesTabs />
      <FeaturesGrid />
      <CtaFinal />
    </>
  )
}
