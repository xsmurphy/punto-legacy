"use client"

import * as React from "react"
import { toast } from "sonner"
import type { CountryCode } from "libphonenumber-js"

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
import { PhoneInput } from "@/components/forms/phone-input"
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
    title: "Catálogo",
    detail: "Categorías, marcas, etiquetas y artículos. El stock inicial no se migra.",
  },
  {
    key: "customers",
    title: "Clientes",
    detail: "Clientes con documento, teléfono y dirección.",
  },
  {
    key: "config",
    title: "Configuración, sucursales y cajas",
    detail: "Las cajas se crean con su timbrado y continúan la numeración donde quedó el legacy.",
  },
]

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
  const [phone, setPhone] = React.useState("")
  const [phoneE164, setPhoneE164] = React.useState<string | null>(null)
  const [country, setCountry] = React.useState<CountryCode>("PY")
  const [password, setPassword] = React.useState("")
  const [domains, setDomains] = React.useState<string[]>(["catalog", "customers", "config"])
  const [registerOutletId, setRegisterOutletId] = React.useState("")

  const companies = useAdminCompanies({ limit: 30, q: search || undefined })
  const outlets = useAdminTenantOutlets(companyId)
  const create = useAdminCreateMigration()

  // Al cerrar se limpia TODO, la contraseña incluida: el diálogo no puede
  // quedar con la credencial de un cliente cargada esperando al próximo.
  React.useEffect(() => {
    if (!open) {
      setCompanyId("")
      setSearch("")
      setPhone("")
      setPhoneE164(null)
      setPassword("")
      setDomains(["catalog", "customers", "config"])
      setRegisterOutletId("")
    }
  }, [open])

  const toggleDomain = (key: string, on: boolean) => {
    setDomains((prev) => (on ? [...new Set([...prev, key])] : prev.filter((d) => d !== key)))
  }

  const canSubmit =
    companyId !== "" && phoneE164 !== null && password.trim() !== "" && domains.length > 0 && !create.isPending

  const submit = () => {
    if (!canSubmit) return
    create.mutate(
      {
        companyId,
        // El legacy espera el teléfono en E.164, igual que su propio login.
        phone: phoneE164 ?? "",
        iso: country,
        password,
        domains,
        registerOutletId: registerOutletId || undefined,
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
              <Label htmlFor="migration-phone">Teléfono del cliente en el legacy</Label>
              <PhoneInput
                id="migration-phone"
                value={phone}
                country={country}
                onChange={(v) => {
                  setPhone(v.value)
                  setPhoneE164(v.isValid ? v.e164 : null)
                  setCountry(v.country)
                }}
              />
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
