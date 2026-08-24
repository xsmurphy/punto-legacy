import Link from "next/link"

import { PuntoLogo } from "@/components/layout/punto-logo"
import { FEATURE_CARDS } from "@/lib/site/modules"
import { RUBROS, RUBRO_GRUPOS } from "@/lib/site/rubros"
import { MODULOS } from "@/lib/site/modulos"
import { WHATSAPP_URL } from "@/lib/site/contacto"

const RECURSOS = [
  { label: "Precios", href: "/precios" },
  { label: "Contacto", href: "/contacto" },
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
      <p className="text-xs font-semibold tracking-wider text-white/45 uppercase">
        {title}
      </p>
      <ul className="flex flex-col gap-2">
        {/* Sin destino real se lista como texto: una flecha o un link que
            no lleva a ningún lado es peor que no ofrecerlo. */}
        {links.map((link) => (
          <li key={link.label}>
            {link.href === "#" ? (
              <span className="text-sm text-white/45">{link.label}</span>
            ) : (
              <Link
                href={link.href}
                className="text-sm text-white/65 transition-colors hover:text-white"
              >
                {link.label}
              </Link>
            )}
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
        <div className="grid grid-cols-2 gap-10 md:grid-cols-6">
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
                href={WHATSAPP_URL}
                target="_blank"
                rel="noopener noreferrer"
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
          {RUBRO_GRUPOS.map((grupo) => (
            <FooterColumn
              key={grupo.key}
              title={grupo.label}
              links={RUBROS.filter((r) => r.grupo === grupo.key).map((r) => ({
                label: r.label,
                href: `/para/${r.slug}`,
              }))}
            />
          ))}
          <div className="flex flex-col gap-8">
            <FooterColumn title="Recursos" links={RECURSOS} />
            <FooterColumn title="Legal" links={LEGAL} />
          </div>
        </div>
        <div className="mt-12 flex flex-col gap-2 border-t border-white/10 pt-6 md:flex-row md:items-center md:justify-between">
          <p className="text-xs text-white/45">
            © {new Date().getFullYear()} Punto
          </p>
        </div>
      </div>
    </footer>
  )
}
