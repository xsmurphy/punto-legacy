"use client"

import * as React from "react"
import { toast } from "sonner"
import { isSupportedCountry, type CountryCode } from "libphonenumber-js"

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Checkbox } from "@/components/ui/checkbox"
import { PasswordInput } from "@/components/ui/password-input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  useAdminCompanies,
  useAdminTenantOutlets,
  useAdminCreateMigration,
} from "@/hooks/use-admin"
import { SUPPORTED_COUNTRIES, getCountry } from "@/lib/countries"

/**
 * Lo que el form del legacy trata como celular: SOLO dígitos. Es la regla de
 * `EncomClient::composeIdentifier()` (espejo de `$.isNumeric` del JS legacy):
 * con eso, el navegador le antepone el código de país elegido. Con espacios,
 * guiones o un "+" adelante viaja tal cual y no hace falta el código.
 */
const isPhoneIdentifier = (v: string) => /^\d+$/.test(v.trim())

const DOMAINS: Array<{ key: string; title: string; detail: string }> = [
  {
    key: "catalog",
    title: "Catálogo, combos y recetas",
    detail:
      "Categorías, marcas, etiquetas, artículos con su costo, y la composición de combos y recetas de producción.",
  },
  {
    key: "customers",
    title: "Clientes",
    detail:
      "Con documento, teléfono, dirección, saldo a favor y línea de crédito. Un cliente repetido con el mismo documento se une al que ya existe; si su teléfono ya lo tiene otro cliente o no es válido, entra sin teléfono y el número queda en la nota.",
  },
  {
    key: "suppliers",
    title: "Proveedores",
    detail: "Con su RUC, teléfono y dirección. Las compras históricas se vinculan a ellos.",
  },
  {
    key: "config",
    title: "Sucursales y cajas",
    detail: "Las cajas se crean con su timbrado y continúan la numeración donde quedó el legacy.",
  },
  {
    key: "users",
    title: "Usuarios",
    detail:
      "Con su PIN de caja y el rol de Punto más parecido al que tenían. La contraseña del panel no se migra: se restablece desde Equipo.",
  },
  {
    key: "payments",
    title: "Medios de pago",
    detail: "Los que el comercio tenía configurados. Los que ya existan en Punto se reusan, no se duplican.",
  },
  {
    key: "stock",
    title: "Stock inicial",
    detail:
      "El saldo de cada artículo en cada sucursal, con su costo, como una apertura de inventario. Necesita el catálogo y las sucursales. Un artículo sin costo conocido no se abre: queda nombrado en la bitácora para cargarle el costo y volver a lanzar.",
  },
  {
    key: "sales_history",
    title: "Ventas históricas",
    detail:
      "Las ventas del rango elegido, con sus líneas, como registro contable: alimentan reportes, balance y cuentas por cobrar. NO tocan el stock, la caja, la numeración fiscal ni la facturación electrónica. Cada línea guarda el costo con el que se vendió en el sistema anterior; si no lo tiene, el costo actual del artículo.",
  },
  {
    key: "purchases_history",
    title: "Compras históricas",
    detail:
      "Las compras del rango, con sus líneas y su documento de proveedor. Alimentan el gasto y las cuentas por pagar. Tampoco mueven stock.",
  },
  {
    key: "expenses_history",
    title: "Movimientos de caja históricos",
    detail:
      "Extracciones e ingresos de caja del rango. Quedan registrados para los reportes; no mueven el saldo de ninguna cuenta de hoy.",
  },
]

/**
 * Los dominios de HISTÓRICO. Se listan aparte porque son los únicos que el
 * operador tiene que ACOTAR con un rango de fechas.
 */
const HISTORY_DOMAINS = ["sales_history", "purchases_history", "expenses_history"]

/**
 * Seleccionado por defecto: todo lo de configuración y catálogo, porque ahí
 * migrar de menos es el error caro.
 *
 * El HISTÓRICO queda FUERA del default, y es la única excepción. No es una
 * duda sobre si conviene traerlo: es que su costo no se parece al del resto.
 * El sistema anterior no tiene un endpoint de líneas por rango para las
 * ventas, así que hay que pedirle el detalle de CADA venta, una por una y
 * paceadas para no voltearle el panel al cliente. Un comercio con miles de
 * ventas al año es un job de horas. Eso se elige a sabiendas, no se arrastra
 * en un default.
 */
const ALL_DOMAINS = DOMAINS.map((d) => d.key).filter((k) => !HISTORY_DOMAINS.includes(k))

export function MigrationFormDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  onCreated: (jobId: string) => void
}) {
  const [companyId, setCompanyId] = React.useState("")
  const [search, setSearch] = React.useState("")
  // Un solo campo de texto: email o celular, tal como lo tipea el cliente.
  // Si es un celular, el form legacy le antepone el código de país de un
  // desplegable; acá se elige aparte y el backend los junta igual que el
  // navegador (context/77 §4). No se normaliza nada más.
  const [identifier, setIdentifier] = React.useState("")
  // Sin default cableado: arranca con el país de la empresa destino, y el
  // operador lo cambia si el cliente entra con un celular de otro país.
  const [phoneCountry, setPhoneCountry] = React.useState<CountryCode | "">("")
  const [phoneCountryTouched, setPhoneCountryTouched] = React.useState(false)
  const [password, setPassword] = React.useState("")
  const [domains, setDomains] = React.useState<string[]>(ALL_DOMAINS)
  const [registerOutletId, setRegisterOutletId] = React.useState("")
  const [historyFrom, setHistoryFrom] = React.useState("")
  const [historyTo, setHistoryTo] = React.useState("")

  const wantsHistory = domains.some((d) => HISTORY_DOMAINS.includes(d))
  const isPhone = isPhoneIdentifier(identifier)

  const companies = useAdminCompanies({ limit: 30, q: search || undefined })
  const outlets = useAdminTenantOutlets(companyId)
  const create = useAdminCreateMigration()

  // Al cerrar se limpia TODO, la contraseña incluida: el diálogo no puede
  // quedar con la credencial de un cliente cargada esperando al próximo.
  React.useEffect(() => {
    if (!open) {
      setCompanyId("")
      setSearch("")
      setIdentifier("")
      setPhoneCountry("")
      setPhoneCountryTouched(false)
      setPassword("")
      setDomains(ALL_DOMAINS)
      setRegisterOutletId("")
      setHistoryFrom("")
      setHistoryTo("")
    }
  }, [open])

  // El país de la empresa elegida propone el código, mientras el operador no
  // haya elegido otro a mano.
  // `undefined` = la empresa no está en la página de resultados actual (la
  // búsqueda cambió): no se pisa lo que ya se había propuesto.
  const companyCountry = (companies.data?.rows ?? []).find((c) => c.id === companyId)?.country
  React.useEffect(() => {
    if (phoneCountryTouched || companyId === "" || companyCountry === undefined) return
    const iso = companyCountry.trim().toUpperCase()
    setPhoneCountry(iso !== "" && isSupportedCountry(iso) ? (iso as CountryCode) : "")
  }, [companyId, companyCountry, phoneCountryTouched])

  // El país de la empresa puede no estar en la lista corta del selector.
  const phoneCountries = React.useMemo(
    () =>
      phoneCountry !== "" && !SUPPORTED_COUNTRIES.some((c) => c.code === phoneCountry)
        ? [...SUPPORTED_COUNTRIES, getCountry(phoneCountry)]
        : SUPPORTED_COUNTRIES,
    [phoneCountry],
  )

  const toggleDomain = (key: string, on: boolean) => {
    setDomains((prev) => (on ? [...new Set([...prev, key])] : prev.filter((d) => d !== key)))
  }

  const canSubmit =
    companyId !== "" &&
    identifier.trim() !== "" &&
    (!isPhone || phoneCountry !== "") &&
    password.trim() !== "" &&
    domains.length > 0 &&
    !create.isPending

  const submit = () => {
    if (!canSubmit) return
    create.mutate(
      {
        companyId,
        identifier: identifier.trim(),
        phoneCode: isPhone && phoneCountry !== "" ? `+${getCountry(phoneCountry).dialCode}` : undefined,
        password,
        domains,
        registerOutletId: registerOutletId || undefined,
        historyFrom: historyFrom || undefined,
        historyTo: historyTo || undefined,
      },
      {
        onSuccess: (res) => {
          setPassword("")
          onOpenChange(false)
          onCreated(res.jobId)
        },
        onError: (err) => toast.error(err.message || "No se pudo lanzar la migración"),
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Nueva migración</DialogTitle>
          <DialogDescription>
            Se entra al panel legacy con las credenciales del cliente y se importan sus datos a la empresa
            de Punto que elijas. La contraseña no se guarda: solo se usa para abrir la sesión.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="migration-company">Empresa destino en Punto</Label>
            <Input
              id="migration-company-search"
              placeholder="Buscar empresa por nombre o RUC…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            <Select value={companyId} onValueChange={setCompanyId}>
              <SelectTrigger id="migration-company">
                <SelectValue placeholder="Elegí la empresa" />
              </SelectTrigger>
              <SelectContent>
                {(companies.data?.rows ?? []).map((c) => (
                  <SelectItem key={c.id} value={c.id}>
                    {c.name || c.companyName || c.id}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">
              La empresa tiene que existir en Punto: el migrador importa datos, no crea cuentas.
            </p>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-2">
              <Label htmlFor="migration-identifier">Usuario del legacy</Label>
              <div className="flex gap-2">
                {isPhone && (
                  <Select
                    value={phoneCountry}
                    onValueChange={(v) => {
                      setPhoneCountry(v as CountryCode)
                      setPhoneCountryTouched(true)
                    }}
                  >
                    <SelectTrigger aria-label="Código de país" className="w-28 shrink-0">
                      {/* El trigger es angosto: muestra solo el código; la lista, país y código. */}
                      <SelectValue placeholder="País">
                        {phoneCountry !== "" ? `+${getCountry(phoneCountry).dialCode}` : undefined}
                      </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                      {phoneCountries.map((c) => (
                        <SelectItem key={c.code} value={c.code}>
                          {c.name} +{c.dialCode}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
                <Input
                  id="migration-identifier"
                  value={identifier}
                  onChange={(e) => setIdentifier(e.target.value)}
                  autoComplete="off"
                />
              </div>
              <p className="text-sm text-muted-foreground">
                Email o celular, escrito como el cliente lo tipea al entrar al panel legacy.
              </p>
            </div>

            <div className="flex flex-col gap-2">
              <Label htmlFor="migration-password">Contraseña</Label>
              <PasswordInput
                id="migration-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="off"
              />
            </div>
          </div>

          <div className="flex flex-col gap-3">
            <Label>Qué migrar</Label>
            {DOMAINS.map((d) => (
              <label key={d.key} className="flex items-start gap-3">
                <Checkbox
                  checked={domains.includes(d.key)}
                  onCheckedChange={(v) => toggleDomain(d.key, v === true)}
                  className="mt-0.5"
                />
                <span className="flex flex-col">
                  <span className="text-sm font-medium">{d.title}</span>
                  <span className="text-sm text-muted-foreground">{d.detail}</span>
                </span>
              </label>
            ))}
          </div>

          {/* Solo tiene sentido con `config`: es el respaldo para las cajas cuya
              sucursal del legacy no se haya podido mapear. */}
          {domains.includes("config") && companyId !== "" && (
            <div className="flex flex-col gap-2">
              <Label htmlFor="migration-outlet">Sucursal de respaldo para las cajas (opcional)</Label>
              <Select value={registerOutletId} onValueChange={setRegisterOutletId}>
                <SelectTrigger id="migration-outlet">
                  <SelectValue placeholder="Sin respaldo" />
                </SelectTrigger>
                <SelectContent>
                  {(outlets.data?.outlets ?? []).map((o) => (
                    <SelectItem key={o.id} value={o.id}>
                      {o.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-sm text-muted-foreground">
                Cada caja va a la sucursal que le corresponde. Esta se usa solo si alguna caja del legacy
                queda sin sucursal conocida; sin ella, esas cajas no se importan.
              </p>
            </div>
          )}

          {/* Solo aplica al histórico: es lo único que se acota por fechas. */}
          {wantsHistory && (
            <div className="flex flex-col gap-2">
              <Label htmlFor="migration-history-from">Rango del histórico</Label>
              <div className="flex items-center gap-2">
                <Input
                  id="migration-history-from"
                  type="date"
                  aria-label="Desde"
                  value={historyFrom}
                  onChange={(e) => setHistoryFrom(e.target.value)}
                />
                <span className="text-sm text-muted-foreground">a</span>
                <Input
                  type="date"
                  aria-label="Hasta"
                  value={historyTo}
                  onChange={(e) => setHistoryTo(e.target.value)}
                />
              </div>
              <p className="text-sm text-muted-foreground">
                Vacío = los últimos 12 meses. Entran los totales de cada venta y también qué se vendió,
                con su costo, así que el ranking de productos y el margen del período quedan completos.
                Se puede cortar y volver a lanzar, que sigue donde quedó sin duplicar nada. Un mes ya
                cerrado contablemente no se toca.
              </p>
            </div>
          )}

          <Alert>
            <AlertDescription>
              Volver a lanzar una migración sobre la misma empresa no duplica nada: lo ya importado se
              saltea. Si dos cajas comparten timbrado y punto de expedición, no se importa ninguna caja.
            </AlertDescription>
          </Alert>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button onClick={submit} disabled={!canSubmit}>
            {create.isPending ? "Conectando al legacy…" : "Migrar"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
