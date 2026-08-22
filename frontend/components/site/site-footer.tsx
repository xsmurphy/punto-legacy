import Link from "next/link"

import { PuntoLogo } from "@/components/layout/punto-logo"
import { FEATURE_CARDS, HOME_RUBROS } from "@/lib/site/modules"
import { MODULOS } from "@/lib/site/modulos"

const RECURSOS = [
  { label: "Precios", href: "/precios" },
  { label: "Ayuda", href: "#" },
  { label: "Guías", href: "#" },
]

const LEGAL = [
  { label: "Términos", href: "#" },
  { label: "Privacidad", href: "#" },
]

function FooterColumn({
  title,
  links,
}: {
  title: string
  links: { label: string; href: string }[]
}) {
  return (
    <div className="flex flex-col gap-3">
      <p className="text-xs font-semibold uppercase tracking-wider text-white/45">
        {title}
      </p>
      <ul className="flex flex-col gap-2">
        {links.map((link) => (
          <li key={link.label}>
            <Link
              href={link.href}
              className="text-sm text-white/65 transition-colors hover:text-white"
            >
              {link.label}
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}

export function SiteFooter() {
  return (
    // Cierre oscuro del sitio: arranca en <CtaFinal> y termina acá.
    <footer className="bg-neutral-950 text-white">
      <div className="mx-auto w-full max-w-6xl px-4 py-14 md:px-6">
        <div className="grid grid-cols-2 gap-10 md:grid-cols-5">
          <div className="col-span-2 flex flex-col gap-4 md:col-span-1">
            <PuntoLogo scheme="on-dark" className="h-6 w-[88px]" />
            <p className="text-sm text-white/65">
              El sistema de tu negocio: Punto de Venta, panel, facturación
              electrónica y stock, en un mismo lugar.
            </p>
            <div className="flex items-center gap-4">
              <Link
                href="#"
                className="text-sm text-white/65 transition-colors hover:text-white"
              >
                Instagram
              </Link>
              <Link
                href="#"
                className="text-sm text-white/65 transition-colors hover:text-white"
              >
                WhatsApp
              </Link>
            </div>
          </div>
          <FooterColumn
            title="Producto"
            links={[
              ...MODULOS.map((m) => ({
                label: m.label,
                href: `/modulos/${m.slug}`,
              })),
              ...FEATURE_CARDS.slice(0, 5).map((f) => ({
                label: f.title,
                href: "#",
              })),
            ]}
          />
          <FooterColumn
            title="Más producto"
            links={FEATURE_CARDS.slice(5).map((f) => ({
              label: f.title,
              href: "#",
            }))}
          />
          <FooterColumn
            title="Soluciones"
            links={HOME_RUBROS.map((r) => ({
              label: r.label,
              href: `/para/${r.slug}`,
            }))}
          />
          <div className="flex flex-col gap-8">
            <FooterColumn title="Recursos" links={RECURSOS} />
            <FooterColumn title="Legal" links={LEGAL} />
          </div>
        </div>
        <div className="mt-12 flex flex-col gap-2 border-t border-white/10 pt-6 md:flex-row md:items-center md:justify-between">
          <p className="text-xs text-white/45">
            © {new Date().getFullYear()} Punto
          </p>
          <p className="text-xs text-white/45">Hecho en Paraguay</p>
        </div>
      </div>
    </footer>
  )
}
