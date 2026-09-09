"use client"

import Link from "next/link"
import { Settings2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"

/**
 * Acceso a la configuración del asistente desde el header del chat.
 *
 * Reemplaza a `AgentSettingsDialog`, que se ELIMINÓ (D4 de
 * `context/69-contexto-del-negocio.md`). El dialog editaba nombre y
 * personalidad por su cuenta, y sumarle el contexto del negocio habría dejado
 * tres superficies para la misma config —el dialog, el tab de /settings y el
 * campo nuevo—. Dos formularios sobre el mismo campo divergen, así que quedó
 * uno solo: el tab `asistente` de /settings.
 *
 * Es un LINK y no un dialog a propósito: la config del asistente ya no son dos
 * campos sueltos, es una pantalla (nombre + personalidad + un textarea de 4000
 * caracteres) y esa pantalla ya existe.
 *
 * ── Por qué esto vive detrás de `showSettings` ──────────────────────────────
 *
 * El header del chat es COMPARTIDO entre el panel y la caja desde el
 * 2026-08-30 (`components/agent/agent-chat-content.tsx`), y
 * `components/pos/pos-agent-dialog.tsx` pasa `showSettings={false}` justamente
 * para que nada que se resuelva con credencial de PANEL entre en ese árbol. Un
 * link a `/settings` no llama a ningún hook de panel, pero manda al cajero a
 * una ruta que su credencial no abre —el POS es token-only, `_jwt` de device—,
 * así que el gate es el mismo. No montarlo fuera de ese flag.
 */
export function AgentSettingsLink() {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button variant="ghost" size="icon" asChild aria-label="Configurar asistente">
          <Link href="/settings?section=asistente">
            <Settings2 className="size-4" />
          </Link>
        </Button>
      </TooltipTrigger>
      <TooltipContent>Configurar asistente</TooltipContent>
    </Tooltip>
  )
}
