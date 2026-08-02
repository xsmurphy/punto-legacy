"use client"

import * as React from "react"
import { KeyRound, Loader2, Megaphone } from "lucide-react"
import { toast } from "sonner"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Label } from "@/components/ui/label"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { AdminRoleGate } from "@/components/admin/admin-role-gate"

import {
  useAdminPlatformConfig,
  useAdminSetPlatformGroup,
  useAdminBroadcast,
  type AdminPlatformGroup,
} from "@/hooks/use-admin"

// ── Grupo de integración ─────────────────────────────────────────────────────

const SOURCE_LABEL: Record<string, string> = {
  config: "Configurado acá",
  env: "Usando variable de entorno del server",
  none: "Sin configurar",
}

function PlatformGroupCard({ group }: { group: AdminPlatformGroup }) {
  const setGroup = useAdminSetPlatformGroup()
  const [values, setValues] = React.useState<Record<string, string>>(() =>
    Object.fromEntries(Object.entries(group.fields).map(([key, f]) => [key, f.value])),
  )

  const onSave = () => {
    setGroup.mutate(
      { key: group.key, fields: values },
      {
        onSuccess: () => toast.success(`${group.label}: guardado`),
        onError: (err) => toast.error(err.message ?? "Error"),
      },
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold tracking-tight">{group.label}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        {Object.entries(group.fields).map(([key, f]) => (
          <div key={key} className="space-y-1.5">
            <div className="flex items-center justify-between">
              <Label htmlFor={`${group.key}.${key}`}>{f.label}</Label>
              <Badge variant="outline" className="text-[10px] font-normal text-muted-foreground">
                {SOURCE_LABEL[f.source]}
              </Badge>
            </div>
            <Input
              id={`${group.key}.${key}`}
              type={f.secret ? "password" : "text"}
              value={values[key] ?? ""}
              onChange={(e) => setValues((v) => ({ ...v, [key]: e.target.value }))}
              autoComplete="off"
            />
          </div>
        ))}
        <div className="flex justify-end">
          <Button size="sm" onClick={onSave} disabled={setGroup.isPending}>
            {setGroup.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
            Guardar
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

// ── Broadcast ─────────────────────────────────────────────────────────────────

function BroadcastForm() {
  const broadcast = useAdminBroadcast()
  const [title, setTitle] = React.useState("")
  const [message, setMessage] = React.useState("")
  const [link, setLink] = React.useState("")

  const onSend = () => {
    broadcast.mutate(
      { title, message, link: link.trim() || undefined },
      {
        onSuccess: () => {
          toast.success("Aviso enviado a todos los tenants")
          setTitle("")
          setMessage("")
          setLink("")
        },
        onError: (err) => toast.error(err.message ?? "Error"),
      },
    )
  }

  const canSend = title.trim() !== "" && message.trim() !== ""

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold tracking-tight">Aviso a todos los tenants</CardTitle>
        <CardDescription>
          Aparece en el centro de notificaciones de cada empresa (mantenimiento, novedades).
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="broadcast-title">Título</Label>
          <Input id="broadcast-title" value={title} onChange={(e) => setTitle(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="broadcast-message">Mensaje</Label>
          <Textarea
            id="broadcast-message"
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            rows={3}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="broadcast-link">Link (opcional)</Label>
          <Input
            id="broadcast-link"
            value={link}
            onChange={(e) => setLink(e.target.value)}
            placeholder="https://…"
          />
        </div>
        <div className="flex justify-end">
          <AlertDialog>
            <AlertDialogTrigger asChild>
              <Button size="sm" className="gap-2" disabled={!canSend}>
                <Megaphone className="size-4" />
                Enviar a todos los tenants
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>¿Enviar aviso a todos los tenants?</AlertDialogTitle>
                <AlertDialogDescription>
                  Crea una notificación visible para todas las empresas. No se puede deshacer.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <AlertDialogAction onClick={onSend} disabled={broadcast.isPending}>
                  {broadcast.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                  Enviar
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>
      </CardContent>
    </Card>
  )
}

// ── Página ────────────────────────────────────────────────────────────────────

function AdminPlatformPageContent() {
  const { data, isLoading } = useAdminPlatformConfig()
  const groups = data?.groups ?? []

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <KeyRound className="size-5 text-muted-foreground" />
        <h1 className="text-2xl font-bold">Plataforma</h1>
      </div>

      <Tabs defaultValue="integrations">
        <TabsList>
          <TabsTrigger value="integrations">Integraciones</TabsTrigger>
          <TabsTrigger value="broadcast">Broadcast</TabsTrigger>
        </TabsList>

        <TabsContent value="integrations" className="space-y-4">
          <p className="text-sm text-muted-foreground">
            Si guardás un valor acá, gana sobre la variable de entorno del server. Los secretos se muestran
            enmascarados — solo se pisan si escribís un valor nuevo.
          </p>
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Cargando…</p>
          ) : (
            <div className="grid gap-4 md:grid-cols-2">
              {groups.map((g) => (
                <PlatformGroupCard key={g.key} group={g} />
              ))}
            </div>
          )}
        </TabsContent>

        <TabsContent value="broadcast" className="space-y-4">
          <BroadcastForm />
        </TabsContent>
      </Tabs>
    </div>
  )
}

export default function AdminPlatformPage() {
  return (
    <AdminRoleGate minRole="owner">
      <AdminPlatformPageContent />
    </AdminRoleGate>
  )
}
