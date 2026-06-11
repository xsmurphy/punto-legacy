"use client"

import * as React from "react"
import { useForm, type UseFormReturn } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Loader2, Building2, Globe, ScanLine, Share2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/ui/switch"
import { Skeleton } from "@/components/ui/skeleton"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
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
import { useSettings, useUpdateSettings } from "@/hooks/use-settings"
import { COMPANY_CATEGORIES } from "@/lib/company-categories"
import type { SettingsFormValues } from "@/lib/types/settings"

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

export default function SettingsPage() {
  const { data, isLoading, error } = useSettings()
  const update = useUpdateSettings()

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

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <Card>
          <CardContent className="p-8 text-center text-sm text-muted-foreground">
            No se pudieron cargar los ajustes. {error.message}
          </CardContent>
        </Card>
      </div>
    )
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6">
        <header className="flex items-end justify-between gap-4">
          <div className="flex flex-col gap-1">
            <h1 className="text-2xl font-semibold">Ajustes de la empresa</h1>
            <p className="text-sm text-muted-foreground">
              Datos fiscales, localización, comportamiento del POS y redes sociales.
              Aplica a TODA la empresa — los datos por sucursal viven en Sucursales.
            </p>
          </div>
          <Button type="submit" disabled={update.isPending || isLoading}>
            {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
            Guardar
          </Button>
        </header>

        <Tabs defaultValue="empresa" className="w-full">
          <TabsList className="grid w-full grid-cols-4 max-w-2xl">
            <TabsTrigger value="empresa" className="gap-1.5">
              <Building2 className="size-3.5" />
              Empresa
            </TabsTrigger>
            <TabsTrigger value="locale" className="gap-1.5">
              <Globe className="size-3.5" />
              Localización
            </TabsTrigger>
            <TabsTrigger value="pos" className="gap-1.5">
              <ScanLine className="size-3.5" />
              Comportamiento POS
            </TabsTrigger>
            <TabsTrigger value="social" className="gap-1.5">
              <Share2 className="size-3.5" />
              Redes
            </TabsTrigger>
          </TabsList>

          <TabsContent value="empresa" className="mt-6">
            {isLoading ? <TabSkeleton /> : <EmpresaTab form={form} />}
          </TabsContent>
          <TabsContent value="locale" className="mt-6">
            {isLoading ? <TabSkeleton /> : <LocaleTab form={form} />}
          </TabsContent>
          <TabsContent value="pos" className="mt-6">
            {isLoading ? <TabSkeleton /> : <PosTab form={form} />}
          </TabsContent>
          <TabsContent value="social" className="mt-6">
            {isLoading ? <TabSkeleton /> : <SocialTab form={form} />}
          </TabsContent>
        </Tabs>
      </form>
    </Form>
  )
}

// ── EMPRESA ─────────────────────────────────────────────────────────────────

function EmpresaTab({ form }: { form: UseFormReturn<SettingsFormValues> }) {
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <Section title="Identidad de la empresa">
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
      </Section>

      <Section title="Datos fiscales">
        <FormField
          control={form.control}
          name="billingName"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Razón social</FormLabel>
              <FormControl>
                <Input placeholder="Nombre fiscal" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="ruc"
          render={({ field }) => (
            <FormItem>
              <FormLabel>RUC</FormLabel>
              <FormControl>
                <Input placeholder="80012345-6" className="tabular-nums" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="billDetail"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Detalle adicional de facturación</FormLabel>
              <FormControl>
                <Textarea
                  rows={2}
                  placeholder="Datos extra que aparecen en facturas"
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </Section>

      <Section title="Contacto">
        <FormField
          control={form.control}
          name="phone"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Teléfono</FormLabel>
              <FormControl>
                <Input type="tel" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="email"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Email</FormLabel>
              <FormControl>
                <Input type="email" placeholder="contacto@miempresa.com" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="address"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Dirección</FormLabel>
              <FormControl>
                <Input placeholder="Calle y número" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="city"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Ciudad</FormLabel>
              <FormControl>
                <Input placeholder="Asunción" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </Section>
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
          render={({ field }) => (
            <FormItem>
              <FormLabel>País</FormLabel>
              <FormControl>
                <Input placeholder="PY" className="tabular-nums uppercase" maxLength={2} {...field} />
              </FormControl>
              <FormDescription className="text-xs">
                Código ISO 3166-1 alpha-2 (PY, AR, BR, etc.).
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
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

// ── REDES SOCIALES ──────────────────────────────────────────────────────────

function SocialTab({ form }: { form: UseFormReturn<SettingsFormValues> }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm font-medium">Redes sociales</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <p className="text-xs text-muted-foreground">
          Los links aparecen en facturas digitales, catálogo online y comprobantes.
        </p>
        <FormField
          control={form.control}
          name="social.facebook"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Facebook</FormLabel>
              <FormControl>
                <Input placeholder="https://facebook.com/miempresa" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="social.instagram"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Instagram</FormLabel>
              <FormControl>
                <Input placeholder="https://instagram.com/miempresa" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="social.youtube"
          render={({ field }) => (
            <FormItem>
              <FormLabel>YouTube</FormLabel>
              <FormControl>
                <Input placeholder="https://youtube.com/@miempresa" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="social.twitter"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Twitter / X</FormLabel>
              <FormControl>
                <Input placeholder="https://twitter.com/miempresa" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
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

function Section({
  title,
  children,
}: {
  title: string
  children: React.ReactNode
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">{children}</CardContent>
    </Card>
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
