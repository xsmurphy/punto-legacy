"use client"

import * as React from "react"
import { useChat } from "@ai-sdk/react"
import { DefaultChatTransport, isToolOrDynamicToolUIPart } from "ai"
import { useQueryClient } from "@tanstack/react-query"
import { usePathname } from "next/navigation"
import { useChatHistoryStore, useChatHistoryHydrated } from "./chat-history-store"
import type { StoredMessage } from "./chat-history-store"
import { messageHasCredential, redactMessage } from "./redact-credentials"
import type { AttachmentDraft } from "./attachment-types"
import { detectKind } from "./attachment-types"
import { parseTabularToCsv } from "./parse-tabular"
import { uploadTabular, generateImageThumbnail } from "./upload-attachment"
import { useAgentPageSnapshotStore } from "./page-snapshot-store"
import { getPanelToken } from "@/lib/auth/panel-token"

/** Tiempo de vida de un mensaje con credencial en el thread vivo. */
const CREDENTIAL_TTL_MS = 60_000

/**
 * Mapeo de la acción ejecutada por `confirm_action` → queryKeys de react-query
 * que el listado afectado consume. Cuando el agente confirma una mutación, el
 * onFinish dispara invalidateQueries para que el listado abierto se refresque
 * sin F5. Whitelist alineada con `lib/agent/confirm-tool.ts`.
 *
 * Notas de naming: el módulo de usuarios usa queryKey `["team"]` (no `users`).
 * Categorías/marcas/tags tienen su propio cache específico + uno combinado
 * `["taxonomies"]` consumido por la pantalla de items, ambos se invalidan.
 */
const ACTION_TO_QUERY_KEYS: Record<string, readonly string[][]> = {
  create_contact: [["contacts"]],
  update_contact: [["contacts"]],
  create_item: [["items"]],
  update_item_price: [["items"]],
  create_user: [["team"]],
  assign_role: [["team"]],
  create_category: [["categories"], ["taxonomies"]],
  create_brand: [["brands"], ["taxonomies"]],
  create_tag: [["tags"], ["taxonomies"]],
  // Crear una sucursal crea TAMBIÉN su caja inicial (invariante de la cadena
  // de alta, `OutletsService::create`), así que invalida las dos listas.
  create_outlet: [["outlets"], ["registers"]],
  create_register: [["registers"]],
  tabular_import: [["contacts"], ["items"]],
}

/** Fallback cuando no podemos resolver la acción desde el thread (ej. register
 * en una sesión anterior ya purgada del historial). Invalida todo lo que el
 * agente puede tocar. */
const ALL_AGENT_KEYS: readonly string[][] = [
  ["items"],
  ["contacts"],
  ["team"],
  ["categories"],
  ["brands"],
  ["tags"],
  ["taxonomies"],
  ["outlets"],
  ["registers"],
]

/**
 * Hook unificado para el agente. Envuelve `useChat` con:
 *   - hidratación al mount desde el historial persistido (localStorage)
 *   - persistencia automática al terminar cada respuesta (status pasa a "ready")
 *   - `clear()` que borra el state del useChat Y el localStorage
 *
 * Tanto el FAB como la página `/chat` usan este mismo hook → al abrir y
 * cerrar el Sheet, el último historial persistido se rehidrata. Si ambos
 * están montados a la vez, ver nota en chat-history-store.ts (last-write-wins).
 */
export function useAgentChat({
  companyName,
  viewOutletId,
  viewOutletName,
  userId,
}: {
  companyName: string
  /** UUID de la sucursal seleccionada (view-scope), "all", o "" si no hay override. */
  viewOutletId: string
  /** Nombre de la sucursal seleccionada — alimenta el contexto del prompt. */
  viewOutletName: string
  /**
   * Dueño del historial (owner, 2026-08-31: "el historial debe estar atado al
   * User ID siempre"). Acá es el usuario logueado en el panel. Con `""` —
   * bootstrap todavía en vuelo— la conversación vive en memoria y no se
   * persiste: ver el docblock de `chat-history-store.ts`.
   */
  userId: string
}) {
  const setStored = useChatHistoryStore((s) => s.setMessages)
  const clearStored = useChatHistoryStore((s) => s.clear)
  const storeHydrated = useChatHistoryHydrated()
  const qc = useQueryClient()
  const pathname = usePathname()
  const snapshot = useAgentPageSnapshotStore((s) => s.snapshot)

  const chat = useChat({
    transport: new DefaultChatTransport({
      api: "/api/agent/chat",
      // El Bearer del panel va EXPLÍCITO. Nadie lo inyectaba: este transport no
      // pasa por `lib/api-client.ts`, que es el único que conoce la clave del
      // token, así que desde que `context/54` movió el panel de cookie a Bearer
      // (commit 0a269e97, 2026-08-27) el BFF venía recibiendo un header vacío
      // (`app/api/agent/chat/route.ts`, `req.headers.get("authorization") ?? ""`)
      // y todas las tools del agente salían sin credencial. `headers` es función
      // y no objeto para que se lea en CADA request: leerlo una vez al montar
      // congelaría el token de ese instante y sobreviviría a un re-login.
      headers: (): Record<string, string> => {
        const token = getPanelToken()
        return token ? { Authorization: `Bearer ${token}` } : {}
      },
      body: { companyName, viewOutletId, viewOutletName, pathname, snapshot: snapshot ?? undefined },
    }),
    onFinish: ({ message, messages }) => {
      // Recolectar (confirmToken → actions[]) de TODOS los registers previos del
      // thread. El register recibe `actions:[{action,payload},...]` y devuelve
      // `confirmToken` en su output; el execute posterior pasa solo ese token
      // (sin las actions). Sin este lookup no sabríamos qué entidades invalidar.
      const tokenToActions = new Map<string, string[]>()
      for (const msg of messages) {
        for (const part of msg.parts ?? []) {
          if (!isToolOrDynamicToolUIPart(part)) continue
          if (part.type !== "tool-register_action") continue
          if (part.state !== "output-available") continue
          const input = part.input as { actions?: Array<{ action?: string }> } | undefined
          const output = part.output as { confirmToken?: string } | undefined
          const actionNames = (input?.actions ?? [])
            .map((a) => a.action)
            .filter((a): a is string => !!a)
          if (actionNames.length > 0 && output?.confirmToken) {
            tokenToActions.set(output.confirmToken, actionNames)
          }
        }
      }

      const invalidated = new Set<string>()
      const invalidate = (keys: readonly string[][]) => {
        for (const k of keys) {
          const sig = k.join("|")
          if (invalidated.has(sig)) continue
          invalidated.add(sig)
          void qc.invalidateQueries({ queryKey: [...k] })
        }
      }

      for (const part of message.parts ?? []) {
        if (!isToolOrDynamicToolUIPart(part)) continue
        if (part.type !== "tool-execute_action") continue
        if (part.state !== "output-available") continue
        const input = part.input as { confirmToken?: string } | undefined
        const output = part.output as { error?: string; okCount?: number } | undefined
        if (!input?.confirmToken) continue // sin token, nada que invalidar
        if (output?.error) continue // execute abortó (token inválido/expirado)
        if ((output?.okCount ?? 0) === 0) continue // ninguna acción del lote tuvo éxito
        const actions = tokenToActions.get(input.confirmToken)
        if (!actions || actions.length === 0) {
          invalidate(ALL_AGENT_KEYS)
          continue
        }
        for (const action of actions) {
          invalidate(ACTION_TO_QUERY_KEYS[action] ?? ALL_AGENT_KEYS)
        }
      }
    },
    // Antes esto no existía: una falla de red, un 500, o un error de stream
    // del modelo quedaban sin rastro alguno en el cliente (ver
    // agent-chat-content.tsx para dónde se renderiza `error`).
    onError: (err) => {
      console.error("[agent] useChat error", err)
    },
  })

  // Hidratar el chat con el historial guardado APENAS persist termine de
  // leer localStorage. El primer render puede pasar antes de la hidratación
  // (SSR/Next), por eso no podemos asumir que `stored` ya tiene los mensajes
  // al mount — esperamos al flag. UNA sola vez por instancia del hook.
  const hydratedRef = React.useRef(false)
  const skipFirstEmptyPersistRef = React.useRef(true)

  React.useEffect(() => {
    // Sin usuario todavía no se hidrata: el historial es de alguien, y hacerlo
    // con `""` levantaría el cajón vacío y lo marcaría como ya hidratado, así
    // que cuando el bootstrap llegara con el id real ya no volvería a intentar.
    if (hydratedRef.current || !storeHydrated || !userId) return
    hydratedRef.current = true
    const stored = useChatHistoryStore.getState().histories[userId] ?? []
    if (stored.length > 0 && chat.messages.length === 0) {
      chat.setMessages(stored)
    } else {
      // No había nada que restaurar — el primer persist es legítimo.
      skipFirstEmptyPersistRef.current = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [storeHydrated, userId])

  // Race condition: el effect de persist puede ver chat.messages=[] antes de que
  // el setMessages del effect de hidratación termine de impactar. El flag
  // skipFirstEmptyPersistRef evita que ese [] vacío pise el historial restaurado.
  React.useEffect(() => {
    if (!hydratedRef.current) return
    if (skipFirstEmptyPersistRef.current && chat.messages.length === 0) {
      return
    }
    skipFirstEmptyPersistRef.current = false

    // Estampar createdAt en mensajes nuevos (sin createdAt todavía).
    // Los mensajes rehidratados ya traen su createdAt original desde localStorage
    // — no se sobreescribe. Esto garantiza que el timestamp sobrevive cualquier
    // reload sin races entre estructuras separadas del store.
    const now = Date.now()
    const stamped: StoredMessage[] = chat.messages.map((msg) => {
      const stored = msg as StoredMessage
      return stored.createdAt !== undefined ? stored : { ...stored, createdAt: now }
    })
    setStored(userId, stamped)
  }, [chat.messages, setStored, userId])

  // Auto-expiración de mensajes con credenciales: cuando aparece un nuevo
  // mensaje del assistant que contiene una contraseña visible, programar un
  // timeout que reemplace el texto a los 60s. Trackeamos por id para no
  // duplicar timers en re-renders. El store ya redacta al persistir, así
  // que al expirar el timer y llamar setMessages, el localStorage queda en
  // sync con el state.
  const scheduledRef = React.useRef<Set<string>>(new Set())
  React.useEffect(() => {
    for (const msg of chat.messages) {
      if (!messageHasCredential(msg)) continue
      if (scheduledRef.current.has(msg.id)) continue
      scheduledRef.current.add(msg.id)
      setTimeout(() => {
        chat.setMessages((prev) =>
          prev.map((m) => (m.id === msg.id ? redactMessage(m) : m)),
        )
      }, CREDENTIAL_TTL_MS)
    }
  }, [chat.messages, chat])

  const clear = React.useCallback(() => {
    chat.setMessages([])
    clearStored(userId)
    scheduledRef.current.clear()
  }, [chat, clearStored])

  const [attachments, setAttachments] = React.useState<AttachmentDraft[]>([])

  const processAttachment = React.useCallback(async (draft: AttachmentDraft): Promise<void> => {
    const update = (patch: Partial<AttachmentDraft>) =>
      setAttachments((prev) => prev.map((a) => (a.id === draft.id ? { ...a, ...patch } : a)))

    update({ status: "uploading" })

    try {
      if (draft.kind === "tabular") {
        const csvFile = await parseTabularToCsv(draft.file)
        const result  = await uploadTabular(csvFile)
        update({ status: "ready", ...result })
      } else if (draft.kind === "image") {
        const thumbnailDataUrl = await generateImageThumbnail(draft.file)
        update({ status: "ready", thumbnailDataUrl })
      } else {
        update({ status: "error", error: "Solo Excel/CSV e imágenes por ahora" })
      }
    } catch (e) {
      update({ status: "error", error: e instanceof Error ? e.message : "Error al procesar archivo" })
    }
  }, [])

  const addAttachment = React.useCallback((file: File): void => {
    const draft: AttachmentDraft = {
      id:       crypto.randomUUID(),
      file,
      kind:     detectKind(file),
      status:   "pending",
      filename: file.name,
    }
    setAttachments((prev) => [...prev, draft])
    void processAttachment(draft)
  }, [processAttachment])

  const removeAttachment = React.useCallback((id: string): void => {
    setAttachments((prev) => prev.filter((a) => a.id !== id))
  }, [])

  const clearAttachments = React.useCallback((): void => {
    setAttachments([])
  }, [])

  return { ...chat, clear, attachments, addAttachment, removeAttachment, clearAttachments }
}
