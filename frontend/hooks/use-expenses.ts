"use client"

/**
 * Hook de mutaciones para Movimientos de Caja (Expenses).
 *
 * Solo soporta `update` y `delete` — el backend (`/v1/reports/expenses`)
 * no tiene `insert` porque los movimientos se crean únicamente desde el POS
 * (extracción/ingreso). El panel puede corregir o eliminar registros existentes.
 *
 * Backend: POST /v1/reports/expenses (proxeado por el catch-all BFF /api/v1/[...path])
 * Auth: realm panel (`_jwt_panel`).
 */

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { ExpenseRow } from "@/hooks/use-reports"

export type { ExpenseRow }

// ── Payloads ──────────────────────────────────────────────────────────────────

export interface UpdateExpensePayload {
  id: string
  date: string    // YYYY-MM-DD o YYYY-MM-DD HH:mm:ss
  total: number   // número plano (la API espera numérico, no locale-formatted)
  note: string
  user: string    // UUID del contacto o '' para vacío
}

export interface DeleteExpensePayload {
  id: string
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * El endpoint usa FormData (validateHttp lee $_POST) en lugar de JSON.
 * El catch-all BFF reenvía el body íntegro, así que mandamos FormData directo.
 */
function buildUpdateForm(p: UpdateExpensePayload): FormData {
  const form = new FormData()
  form.set("action", "update")
  form.set("id", p.id)
  form.set("date", p.date)
  form.set("total", String(p.total))
  form.set("note", p.note)
  if (p.user) form.set("user", p.user)
  return form
}

function buildDeleteForm(id: string): FormData {
  const form = new FormData()
  form.set("action", "delete")
  form.set("id", id)
  return form
}

// ── Mutaciones ────────────────────────────────────────────────────────────────

export function useUpdateExpense() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (payload: UpdateExpensePayload) =>
      api.postForm<{ id: string; action: string }>(
        "/v1/reports/expenses",
        buildUpdateForm(payload),
      ),
    onSuccess: () => {
      // Invalida todos los queries de reports/expenses (cualquier rango de fechas)
      qc.invalidateQueries({ queryKey: ["reports", "expenses"] })
    },
  })
}

export function useDeleteExpense() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (payload: DeleteExpensePayload) =>
      api.postForm<{ id: string; action: string }>(
        "/v1/reports/expenses",
        buildDeleteForm(payload.id),
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["reports", "expenses"] })
    },
  })
}
