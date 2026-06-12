"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { DocumentTemplate } from "@/lib/types/document-template"

export function useDocumentTemplates() {
  return useQuery<{ templates: DocumentTemplate[] }>({
    queryKey: ["document-templates"],
    queryFn: () => api.get("/v1/document-templates"),
    staleTime: 30 * 1000,
  })
}

export function useDocumentTemplate(id: string | undefined) {
  return useQuery<DocumentTemplate>({
    queryKey: ["document-templates", id],
    queryFn: () => api.get<DocumentTemplate>(`/v1/document-templates?id=${id}`),
    enabled: !!id,
  })
}

export function useCreateDocumentTemplate() {
  const qc = useQueryClient()
  return useMutation<DocumentTemplate, Error, Partial<DocumentTemplate>>({
    mutationFn: (body) => api.post<DocumentTemplate>("/v1/document-templates", body as Record<string, unknown>),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["document-templates"] }),
  })
}

export function useUpdateDocumentTemplate() {
  const qc = useQueryClient()
  return useMutation<DocumentTemplate, Error, { id: string; values: Partial<DocumentTemplate> }>({
    mutationFn: ({ id, values }) =>
      api.put<DocumentTemplate>(`/v1/document-templates?id=${id}`, values as Record<string, unknown>),
    onSuccess: (_, { id }) => {
      qc.invalidateQueries({ queryKey: ["document-templates"] })
      qc.invalidateQueries({ queryKey: ["document-templates", id] })
    },
  })
}

export function useDeleteDocumentTemplate() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/document-templates?id=${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["document-templates"] }),
  })
}
