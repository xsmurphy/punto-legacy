import type { Metadata } from "next"

import { LegalPage } from "@/components/site/legal-page"
import { EMPRESA, PRIVACIDAD } from "@/lib/site/legal"

export const metadata: Metadata = {
  title: PRIVACIDAD.titulo,
  alternates: { canonical: PRIVACIDAD.url },
  description: `Cómo trata ${EMPRESA.razonSocial} los datos personales en Punto: qué datos usamos, con qué proveedores los compartimos, cookies, conservación y cómo ejercer tus derechos.`,
}

export default function PrivacidadPage() {
  return <LegalPage doc={PRIVACIDAD} />
}
