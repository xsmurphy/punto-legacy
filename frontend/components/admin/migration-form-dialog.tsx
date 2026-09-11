"use client"

import * as React from "react"
import { toast } from "sonner"

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
    detail: "Con documento, teléfono, dirección, saldo a favor y línea de crédito.",
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
      "Las ventas del rango elegido, con sus líneas, como registro contable: alimentan reportes, balance y cuentas por cobrar. NO tocan el stock, la caja, la numeración fiscal ni la facturación electrónica. Cada línea guarda el costo ACTUAL del artículo, porque el sistema anterior no expone el costo del día de la venta: el margen histórico es una aproximación. Conviene cargar los costos ANTES de importar — un artículo sin costo deja esa línea sin margen, y cargárselo después no la corrige.",
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
  // Un solo campo de texto: el login del legacy resuelve si es email o
  // celular. No se valida ni se normaliza acá — cualquier transformación
  // cambiaría la credencial que el cliente usa todos los días.
  const [identifier, setIdentifier] = React.useState("")
  const [password, setPassword] = React.useState("")
  const [domains, setDomains] = React.useState<string[]>(ALL_DOMAINS)
  const [registerOutletId, setRegisterOutletId] = React.useState("")
  const [historyFrom, setHistoryFrom] = React.useState("")
  const [historyTo, setHistoryTo] = React.useState("")

  const wantsHistory = domains.some((d) => HISTORY_DOMAINS.includes(d))

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
      setPassword("")
      setDomains(ALL_DOMAINS)
      setRegisterOutletId("")
      setHistoryFrom("")
      setHistoryTo("")
    }
  }, [open])

  const toggleDomain = (key: string, on: boolean) => {
    setDomains((prev) => (on ? [...new Set([...prev, key])] : prev.filter((d) => d !== key)))
  }

  const canSubmit =
    companyId !== "" &&
    identifier.trim() !== "" &&
    password.trim() !== "" &&
    domains.length > 0 &&
    !create.isPending

  const submit = () => {
    if (!canSubmit) return
    create.mutate(
      {
        companyId,
        identifier: identifier.trim(),
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
              <Input
                id="migration-identifier"
                value={identifier}
                onChange={(e) => setIdentifier(e.target.value)}
                autoComplete="off"
              />
              <p className="text-sm text-muted-foreground">
                Email o número de celular con el que el cliente entra al panel legacy.
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
                Vacío = los últimos 12 meses. El detalle de cada venta se le pide de a una al sistema
                anterior, así que un rango largo puede tardar horas: se puede cortar y volver a lanzar,
                que sigue donde quedó sin duplicar nada. Un mes ya cerrado contablemente no se toca.
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
