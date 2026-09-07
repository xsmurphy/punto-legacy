/**
 * Índice de búsqueda local del catálogo POS.
 *
 * Búsqueda síncrona sobre los arrays del store en memoria. Cero
 * round-trips — igual que el legacy `ncmTransactions.searchItems()`.
 *
 * Algoritmo: normalización + startsWith + includes (por orden de
 * prioridad). Suficiente para catálogos de hasta ~50k items en memoria
 * (benchmarked en context/14). Si se necesita fuzzy real, reemplazar
 * con Fuse.js sin tocar la UI.
 *
 * TODO (Slice A): llamar a `searchItems` / `searchCustomers` desde
 * la barra de búsqueda del POS y desde el numpad de barcode.
 *
 * Ver context/16-app-next-rewrite.md §8 (UX velocidad).
 */

import type { PosItem, PosCustomer } from "@/lib/types/pos-bootstrap"

// ── Normalization ─────────────────────────────────────────────────────────────

function normalize(s: string): string {
  return s
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .trim()
}

/**
 * ¿El texto contiene la consulta, ignorando mayúsculas y acentos?
 *
 * Filtro de listas CORTAS ya en pantalla —opciones de un grupo de add-ons, los
 * artículos de un grupo de productos— donde no hay relevancia que ordenar: o
 * la fila entra o no entra. Vive acá y no en cada componente para que la
 * normalización sea UNA (buscar "jamon" tiene que encontrar "Jamón" en todos
 * los buscadores del POS, no solo en los que se acordaron de hacer NFD).
 *
 * Consulta vacía = todo pasa: el caller no tiene que ramificar.
 */
export function matchesText(haystack: string, query: string): boolean {
  const q = normalize(query)
  if (!q) return true
  return normalize(haystack).includes(q)
}

/**
 * A partir de cuántas filas una lista del POS gana su propio buscador
 * (pedido del owner 2026-09-07: "dentro de grupos de productos o combos tiene
 * que haber un buscador, porque muchas veces los listados son muy largos").
 *
 * Por debajo del umbral el input es ruido: el cajero ve la lista entera de un
 * vistazo y un campo de texto arriba solo le roba espacio y le sube el teclado
 * del OS en tablet.
 */
export const LIST_FILTER_THRESHOLD = 8

// ── Items ─────────────────────────────────────────────────────────────────────

/**
 * Busca items vendibles en el catálogo en memoria.
 *
 * @param items   Array del store (useCatalogStore.getState().items)
 * @param query   Texto libre del cajero (nombre, SKU, barcode)
 * @param limit   Máximo de resultados (default 50)
 * @returns Items ordenados por relevancia (exactMatch primero)
 */
export function searchItems(
  items: PosItem[],
  query: string,
  limit = 50,
): PosItem[] {
  const q = normalize(query)
  if (!q) return items.slice(0, limit)

  const exact: PosItem[] = []
  const starts: PosItem[] = []
  const includes: PosItem[] = []

  for (const item of items) {
    const name = normalize(item.name)
    const sku = item.sku ? normalize(item.sku) : ""

    if (name === q || sku === q) {
      exact.push(item)
    } else if (name.startsWith(q) || sku.startsWith(q)) {
      starts.push(item)
    } else if (name.includes(q) || sku.includes(q)) {
      includes.push(item)
    }

    if (exact.length + starts.length + includes.length >= limit * 3) break
  }

  return [...exact, ...starts, ...includes].slice(0, limit)
}

/**
 * Busca un item EXACTO por SKU/barcode. Retorna null si no encuentra.
 * Usado por el barcode scanner (keyboard-wedge) para match instantáneo.
 */
export function findItemBySku(items: PosItem[], sku: string): PosItem | null {
  const q = normalize(sku)
  return items.find((i) => i.sku !== null && normalize(i.sku) === q) ?? null
}

// ── Customers ─────────────────────────────────────────────────────────────────

/**
 * Busca clientes en el catálogo en memoria.
 *
 * @param customers Array del store
 * @param query     Nombre, teléfono o RUC/CI
 * @param limit     Máximo de resultados (default 30)
 */
export function searchCustomers(
  customers: PosCustomer[],
  query: string,
  limit = 30,
): PosCustomer[] {
  const q = normalize(query)
  if (!q) return customers.slice(0, limit)

  const exact: PosCustomer[] = []
  const starts: PosCustomer[] = []
  const includes: PosCustomer[] = []

  for (const c of customers) {
    const name = normalize(c.name)
    const phone = c.phone ? normalize(c.phone) : ""
    const tin = c.tin ? normalize(c.tin) : ""

    if (name === q || phone === q || tin === q) {
      exact.push(c)
    } else if (name.startsWith(q) || phone.startsWith(q) || tin.startsWith(q)) {
      starts.push(c)
    } else if (name.includes(q) || phone.includes(q) || tin.includes(q)) {
      includes.push(c)
    }

    if (exact.length + starts.length + includes.length >= limit * 3) break
  }

  return [...exact, ...starts, ...includes].slice(0, limit)
}
