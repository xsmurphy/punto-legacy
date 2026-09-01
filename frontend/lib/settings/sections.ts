import type { ComponentType } from "react"
import {
  Building2,
  Coins,
  Component,
  FileText,
  KeyRound,
  LayoutGrid,
  ListOrdered,
  Lock,
  Monitor,
  Palette,
  Plug,
  Printer,
  ScanLine,
  ShieldCheck,
  Tag,
} from "lucide-react"

/**
 * Secciones de /settings — fuente única.
 *
 * Vive en `lib/` y no adentro de `app/(panel)/settings/page.tsx` porque hay
 * DOS consumidores que tienen que coincidir:
 *
 *  1. la página, que renderiza el menú y decide qué tab mostrar;
 *  2. el registro de navegación (`lib/navigation/routes.ts`), que deep-linkea
 *     cada tab con `/settings?section=<id>`.
 *
 * Mientras la lista vivía solo en la página, el registro no tenía contra qué
 * validarse: sus entradas apuntaban a `/settings` a secas y el buscador
 * encontraba "Monedas" para aterrizar en "Empresa". El test de cobertura
 * (`lib/navigation/__tests__/routes-coverage.test.ts`) cruza las dos listas
 * desde acá, así que un `?section=` inventado rompe la suite.
 *
 * El archivo es `.ts` a propósito (sin JSX): el test de Vitest corre en
 * `environment: "node"` y solo importa `lib/**`.
 */

/**
 * Tabs que la página renderiza adentro del modal.
 *
 * "plan" no está en el menú (salió el 2026-08-01 por pedido del owner: vive
 * en /history-billing) pero sigue siendo un destino válido de URL para links
 * viejos, así que es un tab aunque no aparezca en `SETTINGS_SECTIONS`.
 */
export type SettingsSection =
  | "empresa"
  | "pos"
  | "monedas"
  | "documentos"
  | "catalog"
  | "apariencia"
  | "modules"
  | "integraciones"
  | "plan"

/**
 * Ids del menú que NO son tabs: son links a páginas propias (tienen `href`).
 *
 * Antes se escribían `"outlets" as unknown as SettingsSection`, un casteo que
 * mentía —esos ids no tienen tab que renderizar— y que borraba justamente la
 * distinción que el deep-link necesita: `?section=roles` no puede switchear un
 * tab, tiene que navegar a /settings/roles.
 */
export type SettingsLinkId =
  | "price-lists"
  | "outlets"
  | "devices"
  | "sessions"
  | "apiKeys"
  | "printers"
  | "tables"
  | "roles"
  | "period-close"

/** Cualquier valor aceptable en `?section=`. */
export type SettingsSectionId = SettingsSection | SettingsLinkId

export interface SettingsSectionEntry {
  id: SettingsSectionId
  label: string
  icon: ComponentType<{ className?: string }>
  /**
   * Si está definido, el item del menú navega a esa URL (cerrando el modal) en
   * lugar de switchear la sección interna, y un `?section=<id>` que apunte acá
   * redirige a esa página. Útil para secciones que ya tienen una página
   * dedicada con más contenido del que cabría en el modal.
   */
  href?: string
}

/** Tab por defecto cuando `?section=` falta o no es válido. */
export const DEFAULT_SETTINGS_SECTION: SettingsSection = "empresa"

export const SETTINGS_SECTIONS: SettingsSectionEntry[] = [
  // "Localización" se fusionó a Empresa (2026-08-01, pedido del owner): eran
  // dos secciones cortas de la misma ficha del negocio — identidad + idioma/
  // moneda. Mismo criterio que Redes sociales (ver nota al final de la lista).
  { id: "empresa", label: "Empresa", icon: Building2 },
  { id: "pos", label: "POS", icon: ScanLine },
  { id: "monedas", label: "Monedas", icon: Coins },
  { id: "documentos", label: "Documentos", icon: FileText },
  { id: "catalog", label: "Catálogo", icon: Tag, href: "/settings/catalog" },
  { id: "apariencia", label: "Apariencia", icon: Palette },
  { id: "price-lists", label: "Listas de precios", icon: ListOrdered, href: "/settings/price-lists" },
  { id: "outlets", label: "Sucursales", icon: Building2, href: "/outlets" },
  { id: "modules", label: "Módulos", icon: Component },
  // Integraciones = puentes con sistemas de terceros. Sección propia (no un
  // bloque más adentro de Módulos) por el mismo criterio con el que se
  // separaron las páginas: prenderlas no alcanza, hay credenciales de por
  // medio. Mismo panel, otro `kind` — ver lib/modules-catalog.ts.
  { id: "integraciones", label: "Integraciones", icon: Plug },
  { id: "devices", label: "Dispositivos", icon: Monitor, href: "/settings/devices" },
  { id: "sessions", label: "Sesiones", icon: KeyRound, href: "/settings/sessions" },
  // Página propia y NO una sección de Sesiones: una sesión solo se revoca
  // —nadie la crea desde una pantalla— y una key se EMITE, con un token que se
  // muestra una sola vez. Verbos distintos, superficies distintas.
  { id: "apiKeys", label: "Keys de integración", icon: Plug, href: "/settings/api-keys" },
  { id: "printers", label: "Impresoras", icon: Printer, href: "/settings/printers" },
  { id: "tables", label: "Espacios", icon: LayoutGrid, href: "/settings/espacios" },
  { id: "roles", label: "Roles y permisos", icon: ShieldCheck, href: "/settings/roles" },
  // D7/E1b de context/48-escalamiento-de-datos.md — página propia (listado +
  // acción de cierre), no un tab del modal.
  { id: "period-close", label: "Cierre de período", icon: Lock, href: "/settings/cierre-de-periodo" },
  // Redes sociales se fusionó a la sección Empresa (al final del tab) en vez
  // de tener una sección propia — el tab solo con 4 inputs estaba subutilizado.
]

/**
 * Secciones que existen como tab pero no como item del menú. Solo se llega por
 * URL; están acá para que el breadcrumb tenga un label y para que el registro
 * de navegación pueda referenciarlas sin romper el test de cobertura.
 */
export const HIDDEN_SETTINGS_SECTIONS: SettingsSectionEntry[] = [
  { id: "plan", label: "Mi plan", icon: FileText },
]

const BY_ID = new Map<string, SettingsSectionEntry>(
  [...SETTINGS_SECTIONS, ...HIDDEN_SETTINGS_SECTIONS].map((s) => [s.id, s]),
)

/** Todos los ids válidos para `?section=` — lo que cruza el test de cobertura. */
export const SETTINGS_SECTION_IDS: readonly string[] = [...BY_ID.keys()]

/** Entrada de una sección por id, o `null` si el id no existe. */
export function findSettingsSection(id: string | null | undefined): SettingsSectionEntry | null {
  if (!id) return null
  return BY_ID.get(id) ?? null
}

/**
 * Resuelve `?section=` a lo que la página tiene que hacer:
 *
 *  - `{ kind: "tab" }`   → mostrar ese tab adentro del modal;
 *  - `{ kind: "redirect" }` → la sección vive en su propia página, ir ahí.
 *
 * Un id desconocido (o ausente) cae al default en vez de romper: la URL es
 * entrada de usuario y un link viejo tiene que seguir abriendo Configuración.
 */
export function resolveSettingsSection(
  raw: string | null | undefined,
):
  | { kind: "tab"; section: SettingsSection }
  | { kind: "redirect"; href: string } {
  const entry = findSettingsSection(raw)
  if (!entry) return { kind: "tab", section: DEFAULT_SETTINGS_SECTION }
  if (entry.href) return { kind: "redirect", href: entry.href }
  return { kind: "tab", section: entry.id as SettingsSection }
}
