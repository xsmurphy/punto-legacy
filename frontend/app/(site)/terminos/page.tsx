import type { Metadata } from "next"

import { LegalPage } from "@/components/site/legal-page"
import { EMPRESA, TERMINOS } from "@/lib/site/legal"

export const metadata: Metadata = {
  title: TERMINOS.titulo,
  alternates: { canonical: TERMINOS.url },
  description: `Términos y condiciones de uso de Punto, el sistema de punto de venta y gestión de ${EMPRESA.razonSocial}: plan y precio, cancelación, reembolsos, propiedad de los datos y responsabilidades.`,
}

export default function TerminosPage() {
  return <LegalPage doc={TERMINOS} />
}
