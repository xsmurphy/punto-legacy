"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { useForm, type UseFormReturn } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Loader2, Building2, Globe, ScanLine, Coins, Check, Palette, FileText, Tag, ListOrdered, Component, CreditCard, Monitor, ShieldCheck, Printer } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { FormSection } from "@/components/forms/form-section"
import { Input } from "@/components/ui/input"
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
import { EmptyState } from "@/components/empty-state"
import type { SettingsFormValues } from "@/lib/types/settings"
import { ModulesPanel } from "@/components/modules/modules-panel"
import { PlanPanel } from "@/components/billing/plan-panel"

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
  settingRemoveTaxes: z.boolean(),
  paymentId: z.boolean(),
  creditLine: z.boolean(),
  storeCredit: z.boolean(),
  ignoreInternal: z.boolean(),
  stockCountBlind: z.boolean(),
  blockUsedDocNo: z.boolean(),
  autoSendDocs: z.boolean(),
  taxPy: z.boolean(),
  weightBarcodes: z.boolean(),
  deletedItemsHistory: z.boolean(),
})

type SettingsSection =
  | "empresa"
  | "locale"
  | "pos"
  | "monedas"
  | "documentos"
  | "catalog"
  | "apariencia"
  | "modules"
  | "plan"

// `href` opcional: si está definido, el item del sidebar navega directo a esa
// URL (cerrando el modal) en lugar de switchear la sección interna. Útil para
// secciones que ya tienen una página dedicada con más contenido del que cabría
// en el modal — evita renderear cards "puente" redundantes adentro.
const SECTIONS: {
  id: SettingsSection
  label: string
  icon: React.ComponentType<{ className?: string }>
  href?: string
}[] = [
  { id: "empresa",    label: "Empresa",      icon: Building2 },
  { id: "locale",     label: "Localización", icon: Globe },
  { id: "pos",        label: "POS",          icon: ScanLine },
  { id: "monedas",    label: "Monedas",      icon: Coins },
  { id: "documentos", label: "Documentos",   icon: FileText },
  { id: "catalog",    label: "Catálogo",     icon: Tag, href: "/settings/catalog" },
  { id: "apariencia", label: "Apariencia",   icon: Palette },
  { id: "price-lists" as unknown as SettingsSection, label: "Listas de precios", icon: ListOrdered, href: "/settings/price-lists" },
  { id: "outlets"     as unknown as SettingsSection, label: "Sucursales",        icon: Building2,   href: "/outlets" },
  { id: "modules",    label: "Módulos",      icon: Component },
  { id: "plan",       label: "Mi plan",      icon: CreditCard },
  { id: "devices" as unknown as SettingsSection, label: "Dispositivos", icon: Monitor, href: "/settings/devices" },
  { id: "printers" as unknown as SettingsSection, label: "Impresoras", icon: Printer, href: "/settings/printers" },
  { id: "roles" as unknown as SettingsSection, label: "Roles y permisos", icon: ShieldCheck, href: "/settings/roles" },
  // Redes sociales se fusionó a la sección Empresa (al final del tab) en vez
  // de tener una sección propia — el tab solo con 4 inputs estaba subutilizado.
]

// Sections que escriben al form de settings — solo en esas mostramos el botón
// "Guardar" del header. Monedas tiene su propia mutation con botón propio;
// Documentos y Catálogo no escriben configuración (son listados / navegación).
const FORM_SECTIONS: SettingsSection[] = ["empresa", "locale", "pos", "apariencia"]

export default function SettingsPage() {
  const router = useRouter()
  const { data, isLoading, error } = useSettings()
  const update = useUpdateSettings()
  const [open, setOpen] = React.useState(true)
  const [section, setSection] = React.useState<SettingsSection>("empresa")

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

  const form = useForm<SettingsFormValues>({
    resolver: zodResolver(settingsSchema),
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
      settingRemoveTaxes: !!data.settingRemoveTaxes,
      paymentId: !!data.paymentId,
      creditLine: !!data.creditLine,
      storeCredit: !!data.storeCredit,
      ignoreInternal: !!data.ignoreInternal,
      stockCountBlind: !!data.stockCountBlind,
      blockUsedDocNo: !!data.blockUsedDocNo,
      autoSendDocs: !!data.autoSendDocs,
      taxPy: !!data.taxPy,
      weightBarcodes: !!data.weightBarcodes,
      deletedItemsHistory: !!data.deletedItemsHistory,
    })
  }, [data, form])

  const onSubmit = async (values: SettingsFormValues) => {
    try {
      await update.mutateAsync(values)
      toast.success("Ajustes guardados")
    } catch (e) {
      toast.error("No se pudieron guardar los ajustes", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const activeLabel = SECTIONS.find((s) => s.id === section)?.label ?? ""
  const showSave = FORM_SECTIONS.includes(section)

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
              <nav
                aria-label="Secciones de configuración"
                className="flex shrink-0 gap-0.5 overflow-x-auto border-b bg-card p-2 pr-12 sm:flex-col sm:border-b-0 sm:border-r sm:p-3 sm:pr-3"
              >
                {SECTIONS.map(({ id, label, icon: Icon, href }) => {
                  const active = section === id
                  return (
                    <button
                      key={id}
                      type="button"
                      onClick={() => (href ? navigateAndClose(href) : setSection(id))}
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
                })}
              </nav>

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
                    <Button type="submit" size="sm" disabled={update.isPending || isLoading}>
                      {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                      Guardar
                    </Button>
                  )}
                </header>
                <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
                  {section === "empresa"    && (isLoading ? <TabSkeleton /> : <EmpresaTab form={form} logoUrl={data?.logo ?? null} hasLogo={!!data?.hasLogo} />)}
                  {section === "locale"     && (isLoading ? <TabSkeleton /> : <LocaleTab form={form} />)}
                  {section === "pos"        && (isLoading ? <TabSkeleton /> : <PosTab form={form} />)}
                  {section === "monedas"    && <MonedasTab />}
                  {section === "documentos" && <DocumentsTab onNavigate={navigateAndClose} />}
                  {section === "catalog"    && <CatalogTab onNavigate={navigateAndClose} />}
                  {section === "apariencia" && <AparienciaTab />}
                  {section === "modules"    && <ModulesPanel />}
                  {section === "plan"       && <PlanPanel />}
                </div>
                {/* Save bar mobile — el header está oculto en mobile (hidden sm:flex)
                    así que repetimos el botón abajo para tener un CTA accesible
                    sin pelearse con el botón X del header del DialogContent. */}
                {showSave && (
                  <div className="border-t bg-background p-3 sm:hidden">
                    <Button type="submit" className="w-full" disabled={update.isPending || isLoading}>
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
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem className="flex-1">
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
          </div>
          <FormField
            control={form.control}
            name="category"
            render={({ field }) => (
              <FormItem>
                <FormLabel>Rubro</FormLabel>
                <Select onValueChange={field.onChange} value={field.value}>
                  <FormControl>
                    <SelectTrigger className="w-full">
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
                  <SelectTrigger className="w-full">
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
              <FormControl>
                <Input placeholder="America/Asuncion" {...field} />
              </FormControl>
              <FormDescription className="text-xs">
                Formato IANA tz (ej. <code className="rounded bg-muted px-1">America/Asuncion</code>).
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
                  onValueChange={(v) => field.onChange(v)}
                >
                  <FormControl>
                    <SelectTrigger className="w-full">
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
                  <SelectTrigger className="w-full">
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
        <FormField
          control={form.control}
          name="taxPy"
          render={({ field }) => (
            <FormItem className="flex flex-row items-center justify-between rounded-md border p-3">
              <div>
                <FormLabel className="text-sm">Régimen tributario Paraguay</FormLabel>
                <FormDescription className="text-xs">
                  Activa cálculos fiscales específicos PY (IVA 10/5, libro de compra, RG90).
                </FormDescription>
              </div>
              <FormControl>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </FormControl>
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
                  <CountryFlag code={row.ccode} />
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

function CountryFlag({ code }: { code: string }) {
  // Emoji bandera desde código ISO 3166-1 alpha-2.
  const flag = code
    ? code
        .toUpperCase()
        .replace(/./g, (c) => String.fromCodePoint(127397 + c.charCodeAt(0)))
    : "🌐"
  return <span className="text-2xl leading-none">{flag}</span>
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
    phone: "",
    city: "",
    country: "",
    language: "es",
    timeZone: "America/Asuncion",
    currency: "₲",
    thousandSeparator: "dot",
    taxName: "IVA",
    tin: "RUC",
    itemsSaleLimit: "",
    decimal: false,
    sellsoldout: false,
    itemSerialized: false,
    drawerEmail: false,
    drawerBlind: false,
    settingRemoveTaxes: false,
    paymentId: false,
    creditLine: false,
    storeCredit: false,
    ignoreInternal: false,
    stockCountBlind: false,
    blockUsedDocNo: false,
    autoSendDocs: false,
    taxPy: false,
    weightBarcodes: false,
    deletedItemsHistory: false,
  }
}
