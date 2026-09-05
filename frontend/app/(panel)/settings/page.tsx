"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter, useSearchParams } from "next/navigation"
import { useForm, type Resolver, type UseFormReturn } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Loader2, Building2, Coins, Check, FileText, Tag, Trash2, Search } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { FormSection } from "@/components/forms/form-section"
import { Input } from "@/components/ui/input"
import { MoneyInput } from "@/components/ui/money-input"
import { Switch } from "@/components/ui/switch"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { cn } from "@/lib/utils"
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import {
  useSettings,
  useUpdateSettings,
  useSettingsCurrencies,
  useUpdateCurrencies,
  type SettingsCurrency,
} from "@/hooks/use-settings"
import { COMPANY_CATEGORIES } from "@/lib/company-categories"
import { SUPPORTED_COUNTRIES } from "@/lib/countries"
import { ThemePicker } from "@/components/theme-picker"
import { DocumentsTab } from "@/components/settings/documents-tab"
import { CompanyLogo } from "@/components/settings/company-logo"
import { StockCountListsField } from "@/components/settings/stock-count-lists-field"
import { EmptyState } from "@/components/empty-state"
import type { SettingsFormValues } from "@/lib/types/settings"
import { ModuleCatalogPanel } from "@/components/modules/module-catalog-panel"
import { PlanPanel } from "@/components/billing/plan-panel"
import {
  DEFAULT_SETTINGS_SECTION,
  HIDDEN_SETTINGS_SECTIONS,
  SETTINGS_SECTIONS,
  resolveSettingsSection,
  type SettingsSection,
  type SettingsSectionId,
} from "@/lib/settings/sections"
import { CountryFlag } from "@/components/ui/country-flag"
import { COUNTRY_LOCALE as TENANT_COUNTRY_LOCALE } from "@/lib/tenant-locale"

// Zonas horarias (IANA) — el usuario elige de una lista en vez de tipear el
// formato exacto. Foco LatAm + las comunes; el value es el IANA tz real.
const TIME_ZONES: { value: string; label: string }[] = [
  { value: "America/Asuncion", label: "Paraguay (Asunción)" },
  { value: "America/Argentina/Buenos_Aires", label: "Argentina (Buenos Aires)" },
  { value: "America/Sao_Paulo", label: "Brasil (São Paulo)" },
  { value: "America/Montevideo", label: "Uruguay (Montevideo)" },
  { value: "America/Santiago", label: "Chile (Santiago)" },
  { value: "America/La_Paz", label: "Bolivia (La Paz)" },
  { value: "America/Lima", label: "Perú (Lima)" },
  { value: "America/Bogota", label: "Colombia (Bogotá)" },
  { value: "America/Caracas", label: "Venezuela (Caracas)" },
  { value: "America/Guayaquil", label: "Ecuador (Guayaquil)" },
  { value: "America/Mexico_City", label: "México (Ciudad de México)" },
  { value: "America/Guatemala", label: "Guatemala" },
  { value: "America/Costa_Rica", label: "Costa Rica" },
  { value: "America/Panama", label: "Panamá" },
  { value: "America/Santo_Domingo", label: "Rep. Dominicana (Santo Domingo)" },
  { value: "America/New_York", label: "EE.UU. (Nueva York)" },
  { value: "America/Los_Angeles", label: "EE.UU. (Los Ángeles)" },
  { value: "Europe/Madrid", label: "España (Madrid)" },
  { value: "UTC", label: "UTC" },
]

// Al elegir País se autocompletan moneda/zona horaria/impuesto/decimales/separador
// con los defaults de ese país (el usuario puede ajustarlos después).
//
// La tabla ya no vive acá: se movió a `lib/tenant-locale.ts` porque no es un
// detalle de este formulario, es la ÚNICA fuente que sabe qué moneda / TZ /
// impuesto le corresponde a cada país — y por lo tanto lo que hay que
// consultar en cualquier parte del sistema donde el tenant todavía no
// configuró alguno de esos campos. Mientras estuvo encerrada acá, el resto
// del código resolvía esos huecos inventando Paraguay.
const COUNTRY_LOCALE = TENANT_COUNTRY_LOCALE

const settingsSchema = z.object({
  name: z.string(),
  address: z.string(),
  email: z.union([z.string().email("Email inválido"), z.literal("")]),
  billingName: z.string(),
  ruc: z.string(),
  billDetail: z.string(),
  website: z.string(),
  social: z.object({
    facebook: z.string(),
    instagram: z.string(),
    youtube: z.string(),
    twitter: z.string(),
  }),
  category: z.string(),
  slug: z
    .string()
    .max(40, "Máximo 40 caracteres")
    .regex(/^[a-z0-9-]*$/, "Solo minúsculas, números y guiones"),
  phone: z.string(),
  city: z.string(),
  country: z.string(),
  language: z.string(),
  timeZone: z.string(),
  currency: z.string(),
  thousandSeparator: z.string(),
  taxName: z.string(),
  tin: z.string(),
  itemsSaleLimit: z.string(),
  decimal: z.boolean(),
  sellsoldout: z.boolean(),
  itemSerialized: z.boolean(),
  drawerEmail: z.boolean(),
  drawerBlind: z.boolean(),
  drawerRequireClosedOrders: z.boolean(),
  // Ventana de anulación de un ítem de comanda, en minutos. 0 = sin límite.
  // El backend clampea igual; acá se corta el negativo para que el form no
  // ofrezca un valor que el server va a reinterpretar.
  settingOrderItemCancelWindowMinutes: z.number().min(0),
  settingRemoveTaxes: z.boolean(),
  paymentId: z.boolean(),
  creditLine: z.boolean(),
  storeCredit: z.boolean(),
  ignoreInternal: z.boolean(),
  stockCountBlind: z.boolean(),
  // D9 de context/63 — ortogonal a `stockCountBlind` (ciego es qué VE el que
  // cuenta; esto es qué PASA al terminar). En negativo a propósito: el default
  // del comercio es que el conteo sí ajuste.
  stockCountRecordOnly: z.boolean(),
  // D3 — listas fijas de conteo. Se validan también server-side con el mismo
  // criterio (`StockCountSettings::decodeLists`): sin nombre o sin artículos,
  // la lista no se guarda.
  stockCountLists: z.array(
    z.object({
      id: z.string(),
      name: z.string(),
      itemIds: z.array(z.string()),
    }),
  ),
  blockUsedDocNo: z.boolean(),
  autoSendDocs: z.boolean(),
  weightBarcodes: z.boolean(),
  deletedItemsHistory: z.boolean(),
  // D7/E1b de context/48-escalamiento-de-datos.md — editable desde
  // /settings/cierre-de-periodo (page propia), no desde este modal. Vive en
  // el schema porque el form hidrata desde el GET; ninguna sección de este
  // modal lo manda (el merge parcial del backend lo deja intacto).
  settingPeriodCloseMonths: z.number(),
  settingDrawerTolerance: z.number(),
  // Asistente IA — editable desde AgentSettingsDialog (chat), no desde este
  // modal. Viven en el schema porque el form los hidrata desde el GET, pero
  // ninguna seccion de este modal los manda: el merge parcial del backend los
  // deja intactos al guardar Empresa o POS (ver SECTION_FIELDS).
  agentName: z.string(),
  agentPersonality: z.enum(["professional", "friendly", "direct", "teacher"]),
})

// Normaliza acentos para que "impresion" matchee "Impresoras", "modulos"
// matchee "Módulos", etc. Mismo patrón que components/modules/module-catalog-panel.tsx
// y lib/catalog/search.ts — no hay helper compartido en lib/ hoy, así que se
// repite localmente en vez de crear una dependencia nueva por 3 líneas.
function normalize(s: string): string {
  return s
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
}

// Sections que escriben al form de settings — solo en esas mostramos el botón
// "Guardar" del header. Monedas tiene su propia mutation con botón propio;
// Documentos y Catálogo no escriben configuración (son listados / navegación).
//
// "Apariencia" NO está acá aunque edite preferencias visibles: su único control
// es el ThemePicker, que persiste en el cliente vía next-themes y nunca tocó
// este form (ver SECTION_FIELDS, su lista es vacía). Mientras estuvo en la
// lista, la sección mostraba un "Guardar" que mandaba un payload vacío y
// devolvía un toast de éxito sin haber guardado nada — un botón que miente.
const FORM_SECTIONS: SettingsSection[] = ["empresa", "pos"]

// Qué keys del form manda cada sección al guardar — el merge parcial del
// backend (SettingsService::updateGeneral) solo toca las keys presentes en
// el payload, así que esto ES el contrato de "qué puede tocar esta sección".
// Debe reflejar exactamente los <FormField name="..."> que cada tab renderiza
// (EmpresaTab + LocaleTab para "empresa", PosTab para "pos") — si se agrega
// un campo a un tab, agregarlo acá también. "apariencia" no tiene campos
// propios hoy (el ThemePicker es 100% cliente, ver AparienciaTab): guardar
// ahí manda un payload vacío, no-op válido en el backend.
//
// Reemplaza el patrón anterior (un solo useForm para las 3 secciones, submit
// mandaba SIEMPRE los ~40 campos): eso hacía que guardar Apariencia pisara
// RUC/rubro/etc. con lo que hubiera en el form en ese momento — ver
// diagnóstico 2026-08-18 en context/29 y el fix en hooks/use-settings.ts.
const SECTION_FIELDS: Partial<Record<SettingsSection, (keyof SettingsFormValues)[]>> = {
  empresa: [
    "name", "slug", "category", "website",
    "language", "timeZone", "country", "currency", "decimal",
    "thousandSeparator", "taxName", "tin",
  ],
  pos: [
    "sellsoldout", "settingRemoveTaxes", "weightBarcodes", "itemsSaleLimit",
    "drawerEmail", "drawerBlind", "drawerRequireClosedOrders", "settingDrawerTolerance",
    "settingOrderItemCancelWindowMinutes",
    "blockUsedDocNo", "autoSendDocs",
    "stockCountBlind", "stockCountRecordOnly", "stockCountLists",
    "itemSerialized", "deletedItemsHistory",
    "creditLine", "storeCredit", "paymentId", "ignoreInternal",
  ],
  apariencia: [],
}

export default function SettingsPage() {
  // useSearchParams() requiere Suspense boundary durante el prerender (Next
  // App Router). Mismo patrón que settings/catalog/page.tsx e items/page.tsx.
  return (
    <React.Suspense fallback={null}>
      <SettingsPageInner />
    </React.Suspense>
  )
}

function SettingsPageInner() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const { data, isLoading, error } = useSettings()
  const update = useUpdateSettings()
  const [open, setOpen] = React.useState(true)

  // La sección activa SALE DE LA URL, no de un useState: el buscador del
  // sidebar (command palette) deep-linkea cada tab con `/settings?section=X`
  // y hasta 2026-09-01 el estado inicial estaba hardcodeado en "empresa" —
  // buscabas "Monedas", lo encontrabas, y aterrizabas en "Empresa".
  //
  // Con la URL como única fuente de verdad no hay estado que sincronizar:
  // back/forward, refresh y links compartidos muestran lo mismo.
  const resolved = resolveSettingsSection(searchParams.get("section"))
  const section = resolved.kind === "tab" ? resolved.section : DEFAULT_SETTINGS_SECTION

  // `?section=` que apunta a una sección con página propia (roles, sucursales,
  // impresoras…): se redirige a esa página en vez de caer al default. El id
  // expresa a dónde quiere ir el usuario; su casa canónica es esa página, no
  // un tab del modal. `replace` para no dejar la URL puente en el history.
  const redirectHref = resolved.kind === "redirect" ? resolved.href : null
  React.useEffect(() => {
    if (redirectHref) router.replace(redirectHref)
  }, [redirectHref, router])

  // Cambiar de tab reescribe el query string. `replace` y no `push` a
  // propósito: el modal se cierra con router.back() (ver handleOpenChange), y
  // con push cada tab visitado sería un paso de history — cerrar el modal
  // volvería al tab anterior en vez de salir de Configuración, y el botón
  // atrás del navegador obligaría a desandar N tabs para volver a donde el
  // usuario estaba antes de abrirlo. Mismo criterio que el `?tab=` de
  // settings/catalog/page.tsx.
  const selectSection = React.useCallback(
    (id: SettingsSectionId) => {
      const sp = new URLSearchParams(searchParams.toString())
      sp.set("section", id)
      router.replace(`/settings?${sp.toString()}`, { scroll: false })
    },
    [router, searchParams],
  )

  // Filtro del buscador de secciones. Se deja vivir aunque el usuario cambie
  // de sección: si filtró por "docu" para llegar a Documentos, lo más probable
  // es que quiera seguir mirando esa misma vecindad (ej. después ir a
  // Catálogo) sin retipear.
  const [sectionQuery, setSectionQuery] = React.useState("")

  const filteredSections = React.useMemo(() => {
    const q = normalize(sectionQuery.trim())
    if (!q) return SETTINGS_SECTIONS
    return SETTINGS_SECTIONS.filter((s) => normalize(s.label).includes(q))
  }, [sectionQuery])

  // Cuando el modal se cierra, salimos de la ruta /settings. router.back() si
  // hay history (caso común: vino del sidebar dropdown); fallback a "/" para
  // visitas directas por URL. setTimeout para que la animación de cierre alcance
  // a correr antes de la navegación (sino el modal se "teletransporta").
  const handleOpenChange = React.useCallback(
    (next: boolean) => {
      if (next) return
      setOpen(false)
      setTimeout(() => {
        if (typeof window !== "undefined" && window.history.length > 1) {
          router.back()
        } else {
          router.push("/")
        }
      }, 120)
    },
    [router],
  )

  // Navegación a una página separada desde una sección del modal (ej. Catálogo
  // → /settings/catalog, Documentos "Nueva plantilla" → /settings/print-templates).
  // Cierra el modal primero para que la transición se vea limpia.
  const navigateAndClose = React.useCallback(
    (href: string) => {
      setOpen(false)
      setTimeout(() => router.push(href), 120)
    },
    [router],
  )

  // Resolver acotado a la seccion activa. `settingsSchema` cubre los ~40
  // campos del form, pero cada tab edita solo los suyos (SECTION_FIELDS) y
  // varios campos del schema ya NO tienen UI en este modal — `email`, por
  // ejemplo, se administra a nivel sucursal desde la mig de outlets.
  //
  // Validar el schema COMPLETO en cada submit hacía que un valor legacy
  // invalido en un campo invisible bloqueara el guardado de cualquier
  // seccion: `handleSubmit` se traga el fallo del resolver, `onSubmit` nunca
  // corre, y no hay toast ni campo donde corregirlo — el usuario ve que
  // "Guardar no hace nada". Es exactamente el fallo silencioso que este
  // modal viene a eliminar, así que solo validamos lo que la seccion manda.
  const sectionRef = React.useRef<SettingsSection>("empresa")
  sectionRef.current = section

  const resolver = React.useMemo(
    () =>
      ((values, context, options) => {
        const fields = SECTION_FIELDS[sectionRef.current] ?? []
        const schema = fields.length > 0
          ? settingsSchema.pick(
              Object.fromEntries(fields.map((f) => [f, true])) as Parameters<
                typeof settingsSchema.pick
              >[0],
            )
          : settingsSchema.pick({} as Parameters<typeof settingsSchema.pick>[0])
        return zodResolver(schema)(values, context, options)
      }) as Resolver<SettingsFormValues>,
    [],
  )

  const form = useForm<SettingsFormValues>({
    resolver,
    defaultValues: emptyValues(),
  })

  React.useEffect(() => {
    if (!data) return
    form.reset({
      name: data.name ?? "",
      address: data.address ?? "",
      email: data.email ?? "",
      billingName: data.billingName ?? "",
      ruc: data.ruc ?? "",
      billDetail: data.billDetail ?? "",
      website: data.website ?? "",
      social: {
        facebook: data.social?.facebook ?? "",
        instagram: data.social?.instagram ?? "",
        youtube: data.social?.youtube ?? "",
        twitter: data.social?.twitter ?? "",
      },
      category: data.category ?? "",
      slug: data.slug ?? "",
      phone: data.phone ?? "",
      city: data.city ?? "",
      country: data.country ?? "",
      language: data.language ?? "es",
      timeZone: data.timeZone ?? "",
      currency: data.currency ?? "",
      thousandSeparator: data.thousandSeparator ?? "dot",
      taxName: data.taxName ?? "IVA",
      tin: data.tin ?? "",
      itemsSaleLimit: data.itemsSaleLimit ?? "",
      decimal: !!data.decimal,
      sellsoldout: !!data.sellsoldout,
      itemSerialized: !!data.itemSerialized,
      drawerEmail: !!data.drawerEmail,
      drawerBlind: !!data.drawerBlind,
      drawerRequireClosedOrders: !!data.drawerRequireClosedOrders,
      settingDrawerTolerance: Number(data.settingDrawerTolerance ?? 0) || 0,
      settingOrderItemCancelWindowMinutes:
        Number(data.settingOrderItemCancelWindowMinutes ?? 0) || 0,
      settingRemoveTaxes: !!data.settingRemoveTaxes,
      paymentId: !!data.paymentId,
      creditLine: !!data.creditLine,
      storeCredit: !!data.storeCredit,
      ignoreInternal: !!data.ignoreInternal,
      stockCountBlind: !!data.stockCountBlind,
      stockCountRecordOnly: !!data.stockCountRecordOnly,
      stockCountLists: data.stockCountLists ?? [],
      blockUsedDocNo: !!data.blockUsedDocNo,
      autoSendDocs: !!data.autoSendDocs,
      weightBarcodes: !!data.weightBarcodes,
      deletedItemsHistory: !!data.deletedItemsHistory,
      agentName: data.agentName ?? "",
      agentPersonality: data.agentPersonality ?? "professional",
    })
  }, [data, form])

  const onSubmit = async (values: SettingsFormValues) => {
    // Guard defensivo: sin `data` no hay nada legítimo que guardar (el form
    // está en emptyValues() — ver el useEffect de arriba). El botón ya queda
    // disabled sin `data`, pero un Enter en un input dispara el submit del
    // <form> igual, sin pasar por el botón. El segundo guard cubre un Enter
    // presionado en una sección sin botón "Guardar" (ej. Documentos) — el
    // <form> envuelve todo el modal, no solo la sección activa.
    if (!data || !FORM_SECTIONS.includes(section)) return

    const fields = SECTION_FIELDS[section] ?? []
    // Sin campos que mandar no hay nada que guardar: cortar antes de disparar
    // la mutation evita un POST vacío y un toast de éxito engañoso.
    if (fields.length === 0) return

    const partial: Partial<SettingsFormValues> = {}
    for (const key of fields) {
      ;(partial as Record<string, unknown>)[key] = values[key]
    }

    try {
      await update.mutateAsync(partial)
      toast.success("Ajustes guardados")
    } catch (e) {
      toast.error("No se pudieron guardar los ajustes", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const activeLabel =
    [...SETTINGS_SECTIONS, ...HIDDEN_SETTINGS_SECTIONS].find((s) => s.id === section)?.label ?? ""
  const showSave = FORM_SECTIONS.includes(section)

  // Mientras se resuelve la redirección a la página propia de la sección no
  // renderizamos el modal: pintar "Empresa" por un frame es exactamente el
  // aterrizaje equivocado que este deep-link viene a eliminar.
  if (redirectHref) return null

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        // Modal de pantalla amplia con sidebar + content. Overrides:
        // - mobile fullscreen (con teclado, un modal chico tapa los inputs);
        // - desktop ancho 64rem (clamp para no exceder viewports chicos);
        // - reset de gap/padding del DialogContent default (el grid interno
        //   maneja su propio layout y queremos que el header/scroll lleguen
        //   a los bordes).
        className={cn(
          "gap-0 overflow-hidden p-0",
          "max-sm:!inset-0 max-sm:!h-dvh max-sm:!max-w-none max-sm:!w-auto max-sm:!translate-x-0 max-sm:!translate-y-0 max-sm:!rounded-none",
          "sm:!max-w-[min(64rem,calc(100vw-2rem))] sm:!w-full",
        )}
      >
        <DialogHeader className="sr-only">
          <DialogTitle>Configuración</DialogTitle>
          <DialogDescription>
            Ajustes de la empresa, POS, documentos y apariencia.
          </DialogDescription>
        </DialogHeader>

        {error ? (
          <div className="p-8 text-center text-sm text-muted-foreground">
            No se pudieron cargar los ajustes. {error.message}
          </div>
        ) : (
          <Form {...form}>
            <form
              onSubmit={form.handleSubmit(onSubmit)}
              className="flex h-full min-h-0 w-full flex-col overflow-hidden sm:grid sm:h-[80vh] sm:grid-cols-[220px_1fr]"
            >
              {/* Sidebar interno. Vertical en desktop, horizontal scrolleable
                  en mobile. pr-12 mobile deja lugar al botón X absolute. */}
              <div className="flex shrink-0 flex-col border-b bg-card sm:border-b-0 sm:border-r">
                {/* Buscador de secciones — solo desktop. En mobile el nav es
                    una fila horizontal scrolleable de 14 chips: meter un input
                    de ancho completo arriba le come una fila entera a un modal
                    que ya recorta alto (max-sm:!h-dvh), a cambio de un
                    beneficio chico — scrollear 14 chips con el dedo ya es
                    rápido. En desktop sí vale la pena: es la columna vertical
                    de 14 labels la que se beneficia de filtrar por texto
                    (mismo patrón que el buscador de Claude). */}
                <div className="hidden p-3 pb-0 sm:block">
                  <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      type="search"
                      value={sectionQuery}
                      onChange={(e) => setSectionQuery(e.target.value)}
                      placeholder="Buscar…"
                      aria-label="Buscar sección de configuración"
                      className="pl-9"
                    />
                  </div>
                </div>
                <nav
                  aria-label="Secciones de configuración"
                  className="flex shrink-0 gap-0.5 overflow-x-auto p-2 pr-12 sm:flex-col sm:p-3 sm:pr-3"
                >
                  {filteredSections.length === 0 ? (
                    // Vacío discreto: es un sidebar de 220px, no la página —
                    // <EmptyState> (icono grande + título + descripción) no
                    // entra ahí. Un texto chico alcanza.
                    <p className="px-2.5 py-4 text-center text-xs text-muted-foreground">
                      Sin resultados
                    </p>
                  ) : (
                    filteredSections.map(({ id, label, icon: Icon, href }) => {
                      const active = section === id
                      return (
                        <button
                          key={id}
                          type="button"
                          onClick={() => (href ? navigateAndClose(href) : selectSection(id))}
                          className={cn(
                            "flex shrink-0 items-center gap-2 rounded-md px-2.5 py-2 text-left text-sm transition-colors sm:w-full",
                            active
                              ? "bg-accent font-medium text-accent-foreground"
                              : "text-muted-foreground hover:bg-accent/50 hover:text-foreground",
                          )}
                          aria-current={active ? "page" : undefined}
                        >
                          <Icon className="size-4 shrink-0" />
                          <span>{label}</span>
                        </button>
                      )
                    })
                  )}
                </nav>
              </div>

              {/* Content area: header breadcrumb (+Guardar) + scroll vertical.
                  pr-14 deja espacio para el botón X del DialogContent (absolute
                  top-4 right-4 + size-icon-sm ≈ 32px). Sin esto, "Guardar"
                  queda tapado por la X cuando showSave es true. */}
              <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                <header className="hidden items-center justify-between gap-2 border-b py-3 pl-6 pr-14 text-sm sm:flex">
                  <div className="flex items-center gap-2 text-muted-foreground">
                    <span>Configuración</span>
                    <span className="text-muted-foreground/50">›</span>
                    <span className="text-foreground">{activeLabel}</span>
                  </div>
                  {showSave && (
                    <Button type="submit" size="sm" disabled={update.isPending || isLoading || !data}>
                      {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                      Guardar
                    </Button>
                  )}
                </header>
                <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
                  {/* Empresa = identidad + localización (fusionadas). */}
                  {section === "empresa"    && (isLoading ? <TabSkeleton /> : (
                    <div className="flex flex-col gap-8">
                      <EmpresaTab form={form} logoUrl={data?.logo ?? null} hasLogo={!!data?.hasLogo} />
                      <LocaleTab form={form} />
                    </div>
                  ))}
                  {section === "pos"        && (isLoading ? <TabSkeleton /> : <PosTab form={form} />)}
                  {section === "monedas"    && <MonedasTab />}
                  {section === "documentos" && <DocumentsTab onNavigate={navigateAndClose} />}
                  {section === "catalog"    && <CatalogTab onNavigate={navigateAndClose} />}
                  {section === "apariencia" && <AparienciaTab />}
                  {section === "modules"    && <ModuleCatalogPanel kind="module" />}
                  {section === "integraciones" && <ModuleCatalogPanel kind="integration" />}
                  {section === "plan"       && <PlanPanel />}
                </div>
                {/* Save bar mobile — el header está oculto en mobile (hidden sm:flex)
                    así que repetimos el botón abajo para tener un CTA accesible
                    sin pelearse con el botón X del header del DialogContent. */}
                {showSave && (
                  <div className="border-t bg-background p-3 sm:hidden">
                    <Button type="submit" className="w-full" disabled={update.isPending || isLoading || !data}>
                      {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                      Guardar {activeLabel}
                    </Button>
                  </div>
                )}
              </div>
            </form>
          </Form>
        )}
      </DialogContent>
    </Dialog>
  )
}

// ── EMPRESA ─────────────────────────────────────────────────────────────────

function EmpresaTab({
  form,
  logoUrl,
  hasLogo,
}: {
  form: UseFormReturn<SettingsFormValues>
  logoUrl: string | null
  hasLogo: boolean
}) {
  // Solo "Identidad de la empresa" en este tab — los datos fiscales (razón
  // social, RUC), contacto (teléfono, email, dirección) y redes sociales
  // viven a nivel sucursal porque cada local puede tener su propia ficha
  // fiscal y canales de contacto. Para editarlos: /outlets/<id>.
  return (
    <div className="flex flex-col gap-8">
      <div className="grid grid-cols-1">
        <Subsection title="Identidad de la empresa">
          {/* Logo + Nombre en la misma fila — el logo es la identidad
              principal de la empresa, va a la izquierda del nombre como en
              el patrón ProductPhoto de items. */}
          <div className="flex items-start gap-4">
            <CompanyLogo logoUrl={logoUrl} hasLogo={hasLogo} />
            <div className="grid flex-1 grid-cols-1 gap-6 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Nombre de la empresa</FormLabel>
                    <FormControl>
                      <Input {...field} />
                    </FormControl>
                    <FormDescription className="text-xs">
                      Aparece en el header del panel, recibos y reportes.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="slug"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Slug</FormLabel>
                    <FormControl>
                      <Input placeholder="mi-empresa" {...field} />
                    </FormControl>
                    <FormDescription className="text-xs">
                      Identificador único de tu empresa en Punto (URLs públicas). Solo
                      minúsculas, números y guiones.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>
          </div>
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <FormField
              control={form.control}
              name="category"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Rubro</FormLabel>
                  <Select onValueChange={field.onChange} value={field.value}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder="Seleccionar…" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {COMPANY_CATEGORIES.map((g) => (
                        <SelectGroup key={g.group}>
                          <SelectLabel>{g.group}</SelectLabel>
                          {g.items.map((item) => (
                            <SelectItem key={item.value} value={item.value}>
                              {item.label}
                            </SelectItem>
                          ))}
                        </SelectGroup>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="website"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Sitio web</FormLabel>
                  <FormControl>
                    <Input type="url" placeholder="https://miempresa.com" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </div>
        </Subsection>

      </div>
    </div>
  )
}

// ── LOCALE ──────────────────────────────────────────────────────────────────

function LocaleTab({ form }: { form: UseFormReturn<SettingsFormValues> }) {
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <Section title="Idioma y zona horaria">
        <FormField
          control={form.control}
          name="language"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Idioma</FormLabel>
              <Select onValueChange={field.onChange} value={field.value}>
                <FormControl>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  <SelectItem value="es">Español</SelectItem>
                  <SelectItem value="en">Inglés</SelectItem>
                  <SelectItem value="pt">Portugués</SelectItem>
                </SelectContent>
              </Select>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="timeZone"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Zona horaria</FormLabel>
              <Select value={field.value || undefined} onValueChange={field.onChange}>
                <FormControl>
                  <SelectTrigger>
                    <SelectValue placeholder="Seleccionar zona horaria" />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  {TIME_ZONES.map((tz) => (
                    <SelectItem key={tz.value} value={tz.value}>
                      {tz.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <FormDescription className="text-xs">
                Elegí la zona horaria de tu negocio.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="country"
          render={({ field }) => {
            // Normalizamos a uppercase: el backend devuelve a veces 'py'
            // legacy y SUPPORTED_COUNTRIES usa códigos ISO uppercase.
            const value = (field.value ?? "").toString().toUpperCase()
            return (
              <FormItem>
                <FormLabel>País</FormLabel>
                <Select
                  value={value}
                  onValueChange={(v) => {
                    field.onChange(v)
                    // Autocompletar locale del país elegido.
                    const loc = COUNTRY_LOCALE[v.toUpperCase()]
                    if (loc) {
                      form.setValue("currency", loc.currency, { shouldDirty: true })
                      form.setValue("timeZone", loc.timeZone, { shouldDirty: true })
                      form.setValue("taxName", loc.taxName, { shouldDirty: true })
                      // La etiqueta del documento fiscal del cliente también
                      // es del país (RUC/CNPJ/CUIT/RFC): antes quedaba en
                      // "RUC" aunque el tenant eligiera Brasil.
                      form.setValue("tin", loc.tinName, { shouldDirty: true })
                      form.setValue("decimal", loc.decimal, { shouldDirty: true })
                      form.setValue("thousandSeparator", loc.thousandSeparator, { shouldDirty: true })
                      form.setValue("language", loc.language, { shouldDirty: true })
                    }
                  }}
                >
                  <FormControl>
                    <SelectTrigger>
                      <SelectValue placeholder="Seleccionar país…" />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {SUPPORTED_COUNTRIES.map((c) => (
                      <SelectItem key={c.code} value={c.code}>
                        <span className="inline-flex items-center gap-2">
                          <span className="text-base leading-none">{c.flag}</span>
                          <span>{c.name}</span>
                          <span className="text-xs text-muted-foreground tabular-nums">
                            {c.code}
                          </span>
                        </span>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )
          }}
        />
      </Section>

      <Section title="Moneda y formato">
        <FormField
          control={form.control}
          name="currency"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Símbolo de moneda</FormLabel>
              <FormControl>
                <Input placeholder="₲" {...field} />
              </FormControl>
              <FormDescription className="text-xs">
                Aparece antes de los montos. Para Paraguay: <code className="rounded bg-muted px-1">₲</code> o <code className="rounded bg-muted px-1">Gs</code>.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="decimal"
          render={({ field }) => (
            <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
              <div>
                <FormLabel className="text-sm">Usar decimales</FormLabel>
                <FormDescription className="text-xs">
                  Apagado para Paraguay (PYG sin centavos). Encendido para USD, BRL, ARS, etc.
                </FormDescription>
              </div>
              <FormControl>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </FormControl>
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="thousandSeparator"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Separador de miles</FormLabel>
              <Select onValueChange={field.onChange} value={field.value}>
                <FormControl>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  <SelectItem value="dot">Punto — 12.345.678</SelectItem>
                  <SelectItem value="comma">Coma — 12,345,678</SelectItem>
                </SelectContent>
              </Select>
              <FormMessage />
            </FormItem>
          )}
        />
      </Section>

      <Section title="Impuestos">
        <FormField
          control={form.control}
          name="taxName"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Nombre del impuesto</FormLabel>
              <FormControl>
                <Input placeholder="IVA" {...field} />
              </FormControl>
              <FormDescription className="text-xs">
                Cómo se llama el impuesto fiscal local (IVA en PY/AR/UY, GST en otros).
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="tin"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Etiqueta del documento fiscal del cliente</FormLabel>
              <FormControl>
                <Input placeholder="RUC" {...field} />
              </FormControl>
              <FormDescription className="text-xs">
                RUC en PY, CUIT en AR, CNPJ en BR — depende del país.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
      </Section>
    </div>
  )
}

// ── POS BEHAVIOR ────────────────────────────────────────────────────────────

function PosTab({ form }: { form: UseFormReturn<SettingsFormValues> }) {
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <Section title="Ventas">
        <ToggleField
          form={form}
          name="sellsoldout"
          label="Permitir vender sin stock"
          desc="Si está apagado, la caja bloquea ventas de productos con stock 0."
        />
        <ToggleField
          form={form}
          name="settingRemoveTaxes"
          label="Permitir quitar impuestos en venta"
          desc="El cajero puede sacar el IVA manualmente del ticket."
        />
        <ToggleField
          form={form}
          name="weightBarcodes"
          label="Códigos de barras con peso"
          desc="Acepta barcodes EAN-13 con peso embebido (carnicerías, fruterías)."
        />
        <FormField
          control={form.control}
          name="itemsSaleLimit"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Límite de ítems por venta</FormLabel>
              <FormControl>
                <Input
                  type="number"
                  inputMode="numeric"
                  placeholder="Sin límite"
                  className="tabular-nums"
                  {...field}
                />
              </FormControl>
              <FormDescription className="text-xs">
                Vacío = sin límite. Útil para cajas autoservicio.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
      </Section>

      <Section title="Cajas y arqueo">
        <ToggleField
          form={form}
          name="drawerEmail"
          label="Enviar arqueo por email al cierre"
          desc="Mail con el resumen del cierre de caja al supervisor."
        />
        <ToggleField
          form={form}
          name="drawerBlind"
          label="Cierre ciego"
          desc="El cajero ingresa el efectivo contado sin ver el sistema."
        />
        <ToggleField
          form={form}
          name="drawerRequireClosedOrders"
          label="Exigir órdenes cobradas y espacios cerrados"
          desc="La caja no cierra el turno mientras la sucursal tenga órdenes sin cobrar o espacios abiertos. Lo que cuenta es el cobro, no el estado del proceso: un pedido ya cobrado que todavía está en camino no frena el cierre. Alcanza a toda la sucursal, no solo a esa caja: los espacios no pertenecen a ninguna caja y cualquiera los puede cobrar."
        />
        <FormField
          control={form.control}
          name="settingOrderItemCancelWindowMinutes"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Minutos para anular un ítem de comanda</FormLabel>
              <FormControl>
                {/* Input numérico y NO MoneyInput: son minutos, no un monto —
                    el MoneyInput aplicaría los separadores y decimales de la
                    moneda del comercio a un número que no es plata. */}
                <Input
                  type="number"
                  inputMode="numeric"
                  min={0}
                  step={1}
                  placeholder="0"
                  className="tabular-nums"
                  name={field.name}
                  ref={field.ref}
                  onBlur={field.onBlur}
                  value={String(field.value ?? 0)}
                  onChange={(e) =>
                    field.onChange(Math.max(0, Math.floor(Number(e.target.value) || 0)))
                  }
                />
              </FormControl>
              <FormDescription className="text-xs">
                Cuánto tiempo tiene el cajero para sacar un ítem de una comanda
                ya cargada. En 0 no hay límite. Pasado ese tiempo la anulación
                la tiene que hacer un encargado con su usuario; el cajero ve el
                motivo en pantalla, no un botón que falla sin explicación.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="settingDrawerTolerance"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Tolerancia de cuadre</FormLabel>
              <FormControl>
                {/* MoneyInput y no Input type=number: es un monto en la moneda
                    del comercio y respeta sus separadores (memoria
                    `feedback_money_inputs_convention`). */}
                <MoneyInput
                  value={field.value ?? 0}
                  onChange={(v) => field.onChange(v ?? 0)}
                  placeholder="0"
                />
              </FormControl>
              <FormDescription className="text-xs">
                Diferencia que el reporte de Control de Cajas todavía considera
                &quot;cuadra&quot;. En 0 el arqueo tiene que dar exacto; el
                redondeo de la moneda nunca se marca como faltante. Subila si el
                vuelto se redondea a 50 o 100.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />
        <ToggleField
          form={form}
          name="blockUsedDocNo"
          label="Bloquear nro. de documento usado"
          desc="No permite reusar números de factura/recibo emitidos."
        />
        <ToggleField
          form={form}
          name="autoSendDocs"
          label="Enviar comprobantes automáticamente"
          desc="Mail/WhatsApp del comprobante al cliente al cerrar la venta."
        />
      </Section>

      <Section title="Stock e inventario">
        <ToggleField
          form={form}
          name="stockCountBlind"
          label="Conteos de stock ciegos"
          desc="El operador no ve el stock teórico mientras cuenta."
        />
        {/* D9 de context/63. Hermano del anterior y ORTOGONAL a él: ciego es
            qué ve el que cuenta, esto es qué pasa al terminar. Se dejaron como
            dos interruptores y no como un "modo" de cuatro estados porque
            fusionarlos obliga al dueño a leer una matriz para cambiar una sola
            cosa.

            Nombrado en negativo a propósito: el default del comercio es que el
            conteo SÍ ajuste, y este toggle apagado es ese default. */}
        <ToggleField
          form={form}
          name="stockCountRecordOnly"
          label="El conteo no modifica el stock"
          desc="Las diferencias quedan registradas para consultarlas, pero el inventario no se ajusta al finalizar."
        />
        <StockCountListsField form={form} />

        <ToggleField
          form={form}
          name="itemSerialized"
          label="Serializados (lote / serie por unidad)"
          desc="Habilita el seguimiento por número de serie o lote."
        />
        <ToggleField
          form={form}
          name="deletedItemsHistory"
          label="Conservar historial de ítems eliminados"
          desc="Los reportes incluyen items que fueron borrados/archivados."
        />
      </Section>

      <Section title="Cobranza">
        <ToggleField
          form={form}
          name="creditLine"
          label="Línea de crédito por cliente"
          desc="Permite cobrar a cuenta corriente con límite por contacto."
        />
        <ToggleField
          form={form}
          name="storeCredit"
          label="Crédito en tienda (gift card / saldo)"
          desc="Los clientes pueden tener saldo a favor para usar en compras."
        />
        <ToggleField
          form={form}
          name="paymentId"
          label="Solicitar ID de pago"
          desc="Pide número de comprobante en pagos electrónicos."
        />
        <ToggleField
          form={form}
          name="ignoreInternal"
          label="Excluir ventas internas de reportes"
          desc="Las ventas marcadas como internas no cuentan en KPIs y comisiones."
        />
      </Section>
    </div>
  )
}

// SocialTab eliminado — la sección Redes sociales se fusionó a la sección
// Empresa al final del tab (sub-bloque renderizado por <Subsection>). El tab
// independiente "Social" tenía solo 4 inputs y resultaba subutilizado.

// ── MONEDAS ─────────────────────────────────────────────────────────────────

/**
 * Editor de cotizaciones por moneda. UI Y mutación independientes del form
 * de settings general — esto es una mutación aparte (action=update&type=currencies).
 * El usuario puede tocar las dos cosas y guardar cada una con su propio botón
 * para evitar pisar uno con el otro.
 */
function MonedasTab() {
  const { data, isLoading, error } = useSettingsCurrencies()
  const update = useUpdateCurrencies()
  const [rows, setRows] = React.useState<SettingsCurrency[]>([])
  const [savedAt, setSavedAt] = React.useState<number | null>(null)

  React.useEffect(() => {
    if (data?.rows) setRows(data.rows)
  }, [data])

  const onSave = async () => {
    try {
      await update.mutateAsync(rows)
      setSavedAt(Date.now())
      toast.success("Cotizaciones guardadas")
    } catch (e) {
      toast.error("No se pudieron guardar las cotizaciones", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  if (error) {
    return (
      <Card>
        <CardContent className="p-8 text-center text-sm text-muted-foreground">
          No se pudieron cargar las cotizaciones. {error.message}
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-end justify-between gap-3">
          <div>
            <CardTitle className="text-base font-semibold tracking-tight">Cotizaciones por moneda</CardTitle>
            <p className="mt-1 text-xs text-muted-foreground">
              Si vendés a clientes que pagan en moneda extranjera, ingresá la tasa de
              cambio actual respecto a tu moneda local. Cero = la moneda no se ofrece.
            </p>
          </div>
          <Button
            type="button"
            onClick={onSave}
            disabled={update.isPending || isLoading}
            size="sm"
          >
            {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
            {savedAt && !update.isPending && <Check className="mr-1 size-4" />}
            Guardar cotizaciones
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        {isLoading && (
          <div className="flex flex-col gap-2">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        )}
        {!isLoading && rows.length === 0 && (
          <EmptyState
            icon={Coins}
            title="Sin monedas configuradas"
            description="Tu país no tiene monedas extranjeras predefinidas."
            showMarquee={false}
            className="border-dashed py-6"
          />
        )}
        {!isLoading && rows.length > 0 && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {rows.map((row, idx) => (
              <div
                key={`${row.ccode}-${row.code}-${idx}`}
                className="flex items-center justify-between gap-3 rounded-md border p-3"
              >
                <div className="flex items-center gap-3">
                  {/* text-2xl: la fila de cotizaciones usa la bandera más
                      grande que el resto de los consumidores. */}
                  <CountryFlag code={row.ccode} className="text-2xl leading-none" />
                  <div className="flex flex-col">
                    <div className="text-sm font-semibold tracking-wide">
                      {row.code}
                    </div>
                    <div className="text-xs text-muted-foreground">{row.ccode}</div>
                  </div>
                </div>
                <Input
                  type="number"
                  inputMode="decimal"
                  step="0.0001"
                  placeholder="0"
                  value={row.value || ""}
                  onChange={(e) => {
                    const v = e.target.value
                    const newRows = [...rows]
                    newRows[idx] = { ...row, value: v === "" ? 0 : Number(v) }
                    setRows(newRows)
                  }}
                  className="tabular-nums w-32 text-right"
                />
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

// ── HELPERS ────────────────────────────────────────────────────────────────

function ToggleField({
  form,
  name,
  label,
  desc,
}: {
  form: UseFormReturn<SettingsFormValues>
  // Solo permitimos paths de keys boolean del form
  name: keyof SettingsFormValues
  label: string
  desc: string
}) {
  return (
    <FormField
      control={form.control}
      name={name as never}
      render={({ field }) => (
        <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
          <div>
            <FormLabel className="text-sm">{label}</FormLabel>
            <FormDescription className="text-xs">{desc}</FormDescription>
          </div>
          <FormControl>
            <Switch
              checked={field.value as boolean}
              onCheckedChange={field.onChange}
            />
          </FormControl>
        </FormItem>
      )}
    />
  )
}

/**
 * Bloque de form sin bordes — solo título arriba (sm font-medium) + contenido
 * verticalmente espaciado. Reemplaza la Card que envolvía cada bloque del form
 * — dentro del modal de settings, esa Card extra se sentía redundante con el
 * borde del propio Dialog.
 */
// Aliases locales a FormSection compartido — jerarquía visual consistente
// cross-app (settings, contacts, outlets, items).
function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return <FormSection title={title}>{children}</FormSection>
}

function Subsection({
  title,
  description,
  className,
  children,
}: {
  title: string
  description?: string
  className?: string
  children: React.ReactNode
}) {
  return (
    <FormSection title={title} description={description} className={className}>
      {children}
    </FormSection>
  )
}

// ── APARIENCIA ──────────────────────────────────────────────────────────────

function CatalogTab({ onNavigate }: { onNavigate?: (href: string) => void }) {
  // Cada card lleva al deep-link de su tab en /settings/catalog (?tab=brands|
  // taxes). Antes los 3 cards iban al mismo href y la pagina default arrancaba
  // siempre en Categorías — la card "Impuestos" abría Categorías por bug UX.
  const links = [
    {
      title: "Categorías",
      description: "Categorías para organizar productos.",
      Icon: Tag,
      href: "/settings/catalog?tab=categories",
    },
    {
      title: "Marcas",
      description: "Marcas / fabricantes de los productos.",
      Icon: Building2,
      href: "/settings/catalog?tab=brands",
    },
    {
      title: "Impuestos",
      description: "Tasas de IVA y otros impuestos para facturación.",
      Icon: FileText,
      href: "/settings/catalog?tab=taxes",
    },
    {
      title: "Motivos de merma",
      description: "Motivos para registrar merma de producción y ajustes.",
      Icon: Trash2,
      href: "/settings/catalog?tab=waste-reasons",
    },
  ]
  // Si nos pasan onNavigate (modal context), cerramos el modal antes de navegar
  // — sin esto, Next.js cambia de ruta pero el Dialog queda montado encima de
  // la nueva página por un instante. Fallback Link normal si no hay handler.
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {links.map((l) => {
          const className =
            "group flex flex-col gap-2 rounded-lg border bg-card p-4 transition hover:border-foreground/30 text-left"
          const content = (
            <>
              <l.Icon className="size-5 text-muted-foreground transition group-hover:text-foreground" />
              <div>
                <h3 className="font-medium">{l.title}</h3>
                <p className="text-xs text-muted-foreground">{l.description}</p>
              </div>
            </>
          )
          return onNavigate ? (
            <button
              key={l.title}
              type="button"
              onClick={() => onNavigate(l.href)}
              className={className}
            >
              {content}
            </button>
          ) : (
            <Link key={l.title} href={l.href} className={className}>
              {content}
            </Link>
          )
        })}
      </div>
    </div>
  )
}

function AparienciaTab() {
  // Sin Card — el ThemePicker (3 cards visuales lado a lado) ya es bastante
  // contenido visual; envolverlo en otro Card era desprolijo. Subsection da
  // título consistente con el resto de tabs sin agregar borde extra.
  return (
    <Subsection title="Tema">
      <p className="text-xs text-muted-foreground">
        La preferencia se guarda en este dispositivo. Atajo: presioná{" "}
        <kbd className="rounded border bg-muted px-1.5 py-0.5 font-mono text-[10px]">
          D
        </kbd>{" "}
        en cualquier pantalla para alternar entre claro y oscuro.
      </p>
      <ThemePicker />
    </Subsection>
  )
}

function TabSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      {[0, 1, 2].map((i) => (
        <Card key={i}>
          <CardHeader>
            <Skeleton className="h-4 w-32" />
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

function emptyValues(): SettingsFormValues {
  return {
    name: "",
    address: "",
    email: "",
    billingName: "",
    ruc: "",
    billDetail: "",
    website: "",
    social: { facebook: "", instagram: "", youtube: "", twitter: "" },
    category: "",
    slug: "",
    phone: "",
    city: "",
    country: "",
    language: "es",
    // Vacíos a propósito. Esto es el shape del formulario ANTES de que llegue
    // la config real del tenant; pre-llenarlo con "America/Asuncion" / "₲" /
    // "RUC" significaba que un tenant sin esos campos guardados terminaba
    // guardando Paraguay sin haberlo elegido nunca. Los completa el país que
    // el usuario seleccione (COUNTRY_LOCALE) o la respuesta del backend.
    timeZone: "",
    currency: "",
    thousandSeparator: "dot",
    taxName: "",
    tin: "",
    itemsSaleLimit: "",
    decimal: false,
    sellsoldout: false,
    itemSerialized: false,
    drawerEmail: false,
    drawerBlind: false,
    drawerRequireClosedOrders: false,
    settingDrawerTolerance: 0,
    settingOrderItemCancelWindowMinutes: 0,
    settingRemoveTaxes: false,
    paymentId: false,
    creditLine: false,
    storeCredit: false,
    ignoreInternal: false,
    stockCountBlind: false,
    stockCountRecordOnly: false,
    stockCountLists: [],
    blockUsedDocNo: false,
    autoSendDocs: false,
    weightBarcodes: false,
    deletedItemsHistory: false,
    settingPeriodCloseMonths: 1,
    agentName: "",
    agentPersonality: "professional",
  }
}
