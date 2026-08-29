"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { DocumentTemplateRow } from "@/lib/types/print-template"

/**
 * Lista todas las plantillas de impresión del tenant. El `config` JSONB lleva
 * el shape del editor visual (ver lib/types/print-template.ts → PrintTemplateConfig).
 */
export function useDocumentTemplates() {
  return useQuery<{ templates: DocumentTemplateRow[] }>({
    queryKey: ["document-templates"],
    queryFn: () => api.get("/v1/document-templates"),
    staleTime: 30 * 1000,
  })
}

export function useDocumentTemplate(id: string | undefined) {
  return useQuery<DocumentTemplateRow>({
    queryKey: ["document-templates", id],
    queryFn: () => api.get<DocumentTemplateRow>(`/v1/document-templates?id=${id}`),
    enabled: !!id,
    staleTime: 30 * 1000,
  })
}

export interface DocumentTemplatePayload {
  name?: string
  docType?: DocumentTemplateRow["docType"]
  pageSize?: DocumentTemplateRow["pageSize"]
  isDefault?: boolean
  config?: Record<string, unknown>
}

export function useCreateDocumentTemplate() {
  const qc = useQueryClient()
  return useMutation<DocumentTemplateRow, Error, DocumentTemplatePayload>({
    mutationFn: (body) =>
      api.post<DocumentTemplateRow>(
        "/v1/document-templates",
        body as unknown as Record<string, unknown>,
      ),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["document-templates"] }),
  })
}

export function useUpdateDocumentTemplate() {
  const qc = useQueryClient()
  return useMutation<DocumentTemplateRow, Error, { id: string; values: DocumentTemplatePayload }>({
    mutationFn: ({ id, values }) =>
      api.put<DocumentTemplateRow>(
        `/v1/document-templates?id=${id}`,
        values as unknown as Record<string, unknown>,
      ),
    onSuccess: (_, { id }) => {
      qc.invalidateQueries({ queryKey: ["document-templates"] })
      qc.invalidateQueries({ queryKey: ["document-templates", id] })
    },
  })
}

/**
 * Duplica una plantilla (pedido del owner 2026-08-28: rearmar un diseño de
 * cero para variarle algo era el único camino).
 *
 * GET del detalle + POST de una copia — el `config` completo NO viaja en el
 * listado, así que primero se trae la fila entera. La copia nace:
 *   - "Nombre (copia)": distinguible en el listado sin inventar numeración.
 *   - NUNCA por defecto: hay un solo default por (empresa, tipo de documento)
 *     y el original lo conserva — duplicar no puede robárselo en silencio.
 */
export function useDuplicateDocumentTemplate() {
  const qc = useQueryClient()
  return useMutation<DocumentTemplateRow, Error, string>({
    mutationFn: async (id) => {
      const original = await api.get<DocumentTemplateRow>(`/v1/document-templates?id=${id}`)
      return api.post<DocumentTemplateRow>("/v1/document-templates", {
        name: `${original.name} (copia)`,
        docType: original.docType,
        pageSize: original.pageSize,
        isDefault: false,
        config: original.config,
      } as unknown as Record<string, unknown>)
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["document-templates"] }),
  })
}

export function useDeleteDocumentTemplate() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/document-templates?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["document-templates"] }),
  })
}
