import type { Metadata } from "next"

import { LegalPage } from "@/components/site/legal-page"
import { EMPRESA, REEMBOLSOS } from "@/lib/site/legal"

export const metadata: Metadata = {
  title: REEMBOLSOS.titulo,
  alternates: { canonical: REEMBOLSOS.url },
  description: `Política de reembolsos de Punto, el sistema de punto de venta y gestión de ${EMPRESA.razonSocial}: qué cubre, cuándo devolvemos el dinero, cómo se pide y en qué plazo se acredita.`,
}

export default function ReembolsosPage() {
  return <LegalPage doc={REEMBOLSOS} />
}
