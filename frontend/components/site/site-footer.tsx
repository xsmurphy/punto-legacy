import Link from "next/link"

import { FEATURE_CARDS, HOME_RUBROS } from "@/lib/site/modules"

const RECURSOS = [
  { label: "Precios", href: "#" },
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
      <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
        {title}
      </p>
      <ul className="flex flex-col gap-2">
        {links.map((link) => (
          <li key={link.label}>
            <Link
              href={link.href}
              className="text-sm text-muted-foreground transition-colors hover:text-foreground"
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
    <footer className="border-t">
      <div className="mx-auto w-full max-w-6xl px-4 py-14 md:px-6">
        <div className="grid grid-cols-2 gap-10 md:grid-cols-5">
          <div className="col-span-2 flex flex-col gap-4 md:col-span-1">
            <p className="text-lg font-semibold tracking-tight">Punto</p>
            <p className="text-sm text-muted-foreground">
              El sistema de tu negocio: ventas, caja, facturación y stock en un
              solo lugar.
            </p>
            <div className="flex items-center gap-4">
              <Link
                href="#"
                className="text-sm text-muted-foreground transition-colors hover:text-foreground"
              >
                Instagram
              </Link>
              <Link
                href="#"
                className="text-sm text-muted-foreground transition-colors hover:text-foreground"
              >
                WhatsApp
              </Link>
            </div>
          </div>
          <FooterColumn
            title="Producto"
            links={FEATURE_CARDS.slice(0, 8).map((f) => ({
              label: f.title,
              href: "#",
            }))}
          />
          <FooterColumn
            title="Más producto"
            links={FEATURE_CARDS.slice(8).map((f) => ({
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
        <div className="mt-12 flex flex-col gap-2 border-t pt-6 md:flex-row md:items-center md:justify-between">
          <p className="text-xs text-muted-foreground">
            © {new Date().getFullYear()} Punto
          </p>
          <p className="text-xs text-muted-foreground">Hecho en Paraguay</p>
        </div>
      </div>
    </footer>
  )
}
