"use client"

import { AgentChatFloating } from "@/components/agent/agent-chat-floating"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePermission } from "@/hooks/use-permissions"

/**
 * Monta el Sheet del asistente IA DENTRO del POS.
 *
 * POR QUÉ EXISTE
 * --------------
 * El asistente del panel lo monta `panel-auth-guard.tsx`, y ese guard vive
 * solo en `app/(panel)/layout.tsx`: el grupo `(pos)` tiene su propio
 * `PosAuthGuard`, así que en la caja NUNCA se montó. Con el trigger del
 * sidebar apuntando al store compartido, el resultado era un botón que
 * prendía un estado que nadie escuchaba — el menú se cerraba y no pasaba nada
 * (reporte del owner, 2026-08-30). El trigger y el Sheet tienen que vivir en
 * la misma superficie.
 *
 * Va en el layout y no en el sidebar por el mismo motivo que `PosModeDialog`:
 * en mobile el sidebar ES un Sheet que se cierra al tocar el item, y con él se
 * desmontaría el chat que acaba de abrirse.
 *
 * SIN FAB
 * -------
 * `showFab={false}`: en la caja el único trigger es el item del sidebar. Un
 * botón flotante abajo a la derecha taparía el CTA de cobrar.
 *
 * SCOPE
 * -----
 * El agente corre contra la API del PANEL con la credencial del operador, no
 * con el Bearer del device — así que su alcance es el del usuario logueado,
 * igual que en el panel. `viewOutletId=""` significa "sin override": lee la
 * sucursal de su propia sesión. El POS no tiene selector de view-scope que
 * pudiera pisarla.
 *
 * El gate `ai.agent.use` espeja el del backend (api/v1/ai/execute.php:23) y es
 * el MISMO que oculta el item del sidebar (`components/layout/pos-sidebar.tsx`):
 * sin permiso no hay trigger ni Sheet.
 */
export function PosAgentChat() {
  const { data: bootstrap } = useBootstrap()
  const canUseAgent = usePermission("ai.agent.use")

  if (!canUseAgent || bootstrap?.companyId == null) return null

  return (
    <AgentChatFloating
      companyName={bootstrap.companyName}
      viewOutletId=""
      viewOutletName={bootstrap.activeOutletName ?? ""}
      showFab={false}
    />
  )
}
