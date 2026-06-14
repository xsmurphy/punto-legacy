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
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import { MoneyInput } from "@/components/ui/money-input"
import { useUpdateModuleConfig } from "@/hooks/use-modules"
import type { ConfigKind } from "@/lib/modules-catalog"
import type {
  LoyaltyConfig,
  TablesConfig,
  OrdersConfig,
  FeedbackConfig,
  CrmConfig,
  ModuleConfig,
} from "@/lib/types/module"

interface ModuleConfigDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  moduleKey: string
  moduleTitle: string
  configKind: ConfigKind
  /** Config actual del módulo (puede ser undefined si aún no cargó) */
  currentConfig?: ModuleConfig
}

export function ModuleConfigDialog({
  open,
  onOpenChange,
  moduleKey,
  moduleTitle,
  configKind,
  currentConfig,
}: ModuleConfigDialogProps) {
  if (configKind === "comingSoon") {
    return (
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{moduleTitle}</DialogTitle>
            <DialogDescription>
              La configuración avanzada de este módulo estará disponible próximamente.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => onOpenChange(false)}>
              Cerrar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    )
  }

  return (
    <ConfigDialogContent
      open={open}
      onOpenChange={onOpenChange}
      moduleKey={moduleKey}
      moduleTitle={moduleTitle}
      configKind={configKind}
      currentConfig={currentConfig}
    />
  )
}

// ── Inner component that holds form state ────────────────────────────────────
// Separado para que el estado de los inputs se monte fresco cada vez que el
// dialog se abre (la condición de render está en el padre).

function ConfigDialogContent({
  open,
  onOpenChange,
  moduleKey,
  moduleTitle,
  configKind,
  currentConfig,
}: ModuleConfigDialogProps) {
  const updateConfig = useUpdateModuleConfig()

  // ── loyalty state ────────────────────────────────────────────────────────
  const loyaltyDefaults = (currentConfig as LoyaltyConfig | undefined) ?? {
    min: 0,
    value: 0,
    customerVisible: false,
  }
  const [loyaltyMin, setLoyaltyMin] = React.useState<number | null>(
    loyaltyDefaults.min ?? null,
  )
  const [loyaltyValue, setLoyaltyValue] = React.useState<number | null>(
    loyaltyDefaults.value ?? null,
  )
  const [loyaltyVisible, setLoyaltyVisible] = React.useState<boolean>(
    loyaltyDefaults.customerVisible ?? false,
  )

  // ── tables state ─────────────────────────────────────────────────────────
  const tablesDefaults = (currentConfig as TablesConfig | undefined) ?? { count: 0 }
  const [tablesCount, setTablesCount] = React.useState<string>(
    String(tablesDefaults.count ?? 0),
  )

  // ── orders state ─────────────────────────────────────────────────────────
  const ordersDefaults = (currentConfig as OrdersConfig | undefined) ?? {
    averageTime: 60,
  }
  const [ordersAvg, setOrdersAvg] = React.useState<string>(
    String(ordersDefaults.averageTime ?? 60),
  )

  // ── feedback state ───────────────────────────────────────────────────────
  const feedbackDefaults = (currentConfig as FeedbackConfig | undefined) ?? {
    question: "",
  }
  const [feedbackQuestion, setFeedbackQuestion] = React.useState<string>(
    feedbackDefaults.question ?? "",
  )

  // ── crm state ────────────────────────────────────────────────────────────
  const crmDefaults = (currentConfig as CrmConfig | undefined) ?? {
    dontAutoSendDocs: false,
  }
  const [crmDontSend, setCrmDontSend] = React.useState<boolean>(
    crmDefaults.dontAutoSendDocs ?? false,
  )

  // Re-sync state when currentConfig changes (e.g. query refetch after save)
  React.useEffect(() => {
    if (!open) return
    if (configKind === "loyalty") {
      const c = currentConfig as LoyaltyConfig | undefined
      setLoyaltyMin(c?.min ?? 0)
      setLoyaltyValue(c?.value ?? 0)
      setLoyaltyVisible(c?.customerVisible ?? false)
    } else if (configKind === "tables") {
      setTablesCount(String((currentConfig as TablesConfig | undefined)?.count ?? 0))
    } else if (configKind === "orders") {
      setOrdersAvg(
        String((currentConfig as OrdersConfig | undefined)?.averageTime ?? 60),
      )
    } else if (configKind === "feedback") {
      setFeedbackQuestion(
        (currentConfig as FeedbackConfig | undefined)?.question ?? "",
      )
    } else if (configKind === "crm") {
      setCrmDontSend(
        (currentConfig as CrmConfig | undefined)?.dontAutoSendDocs ?? false,
      )
    }
  }, [open, currentConfig, configKind])

  function buildPayload(): Record<string, unknown> {
    switch (configKind) {
      case "loyalty":
        return {
          min: loyaltyMin ?? 0,
          value: loyaltyValue ?? 0,
          customerVisible: loyaltyVisible,
        }
      case "tables":
        return { count: parseInt(tablesCount, 10) || 0 }
      case "orders":
        return { averageTime: parseInt(ordersAvg, 10) || 60 }
      case "feedback":
        return { question: feedbackQuestion }
      case "crm":
        return { dontAutoSendDocs: crmDontSend }
      default:
        return {}
    }
  }

  function handleSave() {
    updateConfig.mutate(
      { key: moduleKey, config: buildPayload() },
      {
        onSuccess: () => {
          toast.success("Configuración guardada")
          onOpenChange(false)
        },
        onError: (err) => {
          toast.error("No se pudo guardar la configuración", {
            description: err.message,
          })
        },
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{moduleTitle}</DialogTitle>
          <DialogDescription>
            Configuración del módulo. Los cambios se aplican de inmediato.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {configKind === "loyalty" && (
            <>
              <div className="space-y-1.5">
                <Label htmlFor="loyaltyMin">Compra mínima para sumar puntos</Label>
                <MoneyInput
                  id="loyaltyMin"
                  value={loyaltyMin}
                  onChange={setLoyaltyMin}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="loyaltyValue">Valor del punto</Label>
                <MoneyInput
                  id="loyaltyValue"
                  value={loyaltyValue}
                  onChange={setLoyaltyValue}
                />
              </div>
              <div className="flex items-center justify-between gap-4">
                <Label htmlFor="loyaltyVisible" className="leading-snug">
                  Visible para el cliente
                </Label>
                <Switch
                  id="loyaltyVisible"
                  checked={loyaltyVisible}
                  onCheckedChange={setLoyaltyVisible}
                />
              </div>
            </>
          )}

          {configKind === "tables" && (
            <div className="space-y-1.5">
              <Label htmlFor="tablesCount">Cantidad de mesas/espacios</Label>
              <Input
                id="tablesCount"
                type="number"
                min={0}
                value={tablesCount}
                onChange={(e) => setTablesCount(e.target.value)}
              />
            </div>
          )}

          {configKind === "orders" && (
            <div className="space-y-1.5">
              <Label htmlFor="ordersAvg">Tiempo promedio de preparación (min)</Label>
              <Input
                id="ordersAvg"
                type="number"
                min={1}
                value={ordersAvg}
                onChange={(e) => setOrdersAvg(e.target.value)}
              />
            </div>
          )}

          {configKind === "feedback" && (
            <div className="space-y-1.5">
              <Label htmlFor="feedbackQuestion">Pregunta para el cliente</Label>
              <Textarea
                id="feedbackQuestion"
                placeholder="¿Cómo calificarías tu experiencia?"
                value={feedbackQuestion}
                onChange={(e) => setFeedbackQuestion(e.target.value)}
                rows={3}
              />
            </div>
          )}

          {configKind === "crm" && (
            <div className="flex items-center justify-between gap-4">
              <Label htmlFor="crmDontSend" className="leading-snug">
                No enviar comprobantes automáticamente al cliente
              </Label>
              <Switch
                id="crmDontSend"
                checked={crmDontSend}
                onCheckedChange={setCrmDontSend}
              />
            </div>
          )}
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={updateConfig.isPending}
          >
            Cancelar
          </Button>
          <Button onClick={handleSave} disabled={updateConfig.isPending}>
            {updateConfig.isPending ? "Guardando…" : "Guardar"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
