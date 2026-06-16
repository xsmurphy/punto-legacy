/**
 * Fixtures del catálogo para desarrollo (dev seed).
 *
 * Respeta exactamente el tipo `PosItem` de `lib/types/pos-bootstrap.ts`.
 * Se usa para hidratar el `useCatalogStore` durante el desarrollo,
 * sin necesitar el BFF real.
 *
 * Se importa SOLO desde componentes cliente que lo cargan al montar (Slice A dev).
 * En producción, el store se hidrata desde `/api/pos/bootstrap`.
 */

import type { PosItem, PosCustomer, PosConfig, PosRegister } from "@/lib/types/pos-bootstrap"

// ── Config de tenant ──────────────────────────────────────────────────────────

export const fixtureConfig: PosConfig = {
  currency: "Gs",
  decimal: "no",
  thousand: "dot",
  taxName: "IVA",
  tinName: "RUC",
  country: "PY",
  companyName: "Punto Restaurante",
  companyId: "1",
  publicUrl: "http://localhost:3001",
}

// ── Caja ──────────────────────────────────────────────────────────────────────

export const fixtureRegisters: PosRegister[] = [
  {
    id: "reg-1",
    name: "Caja Principal",
    outletId: "out-1",
    expeditionPoint: "001",
  },
]

// ── Categorías (no son items — se derivan de los items) ───────────────────────

export const fixtureCategories = [
  { id: "cat-menu",   name: "Menú del día",        abbrev: "Me", color: "#6366f1" },
  { id: "cat-minutas", name: "Minutas",             abbrev: "Mi", color: "#0ea5e9" },
  { id: "cat-bebidas", name: "Bebidas con alcohol", abbrev: "Be", color: "#f59e0b" },
  { id: "cat-promos", name: "Promos",               abbrev: "Pr", color: "#10b981" },
  { id: "cat-pizzas", name: "Pizzas Gourmet",       abbrev: "Pi", color: "#ef4444" },
] as const

// ── Items ─────────────────────────────────────────────────────────────────────

export const fixtureItems: PosItem[] = [
  // Menú del día
  {
    id: "item-001",
    name: "Milanesa napolitana",
    sku: "MIL-NAP",
    price: 35000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-menu",
    categoryName: "Menú del día",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },
  {
    id: "item-002",
    name: "Pollo asado con papas",
    sku: "POL-ASA",
    price: 30000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-menu",
    categoryName: "Menú del día",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },
  {
    id: "item-003",
    name: "Sopa paraguaya",
    sku: "SOP-PAR",
    price: 8000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-menu",
    categoryName: "Menú del día",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },

  // Minutas
  {
    id: "item-004",
    name: "Hamburguesa clásica",
    sku: "HAM-CLA",
    price: 25000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-minutas",
    categoryName: "Minutas",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },
  {
    id: "item-005",
    name: "Sandwich de lomo",
    sku: "SAN-LOM",
    price: 22000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-minutas",
    categoryName: "Minutas",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },
  {
    id: "item-006",
    name: "Empanadas (x3)",
    sku: "EMP-X3",
    price: 12000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-minutas",
    categoryName: "Minutas",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },

  // Bebidas con alcohol
  {
    id: "item-007",
    name: "Cerveza Pilsen 960ml",
    sku: "CER-960",
    price: 9000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-bebidas",
    categoryName: "Bebidas con alcohol",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: true,
    stock: 24,
  },
  {
    id: "item-008",
    name: "Vino tinto copa",
    sku: "VIN-TIN",
    price: 15000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-bebidas",
    categoryName: "Bebidas con alcohol",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: true,
    stock: -3,
  },
  {
    id: "item-009",
    name: "Gin tónic",
    sku: "GIN-TON",
    price: 20000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-bebidas",
    categoryName: "Bebidas con alcohol",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: true,
    stock: 0,
  },

  // Promos
  {
    id: "item-010",
    name: "Combo Almuerzo",
    sku: "CMB-ALM",
    price: 40000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-promos",
    categoryName: "Promos",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },
  {
    id: "item-011",
    name: "2x1 Hamburguesas",
    sku: "2X1-HAM",
    price: 45000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-promos",
    categoryName: "Promos",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },

  // Pizzas Gourmet
  {
    id: "item-012",
    name: "Pizza Margherita",
    sku: "PIZ-MAR",
    price: 55000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-pizzas",
    categoryName: "Pizzas Gourmet",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },
  {
    id: "item-013",
    name: "Pizza Cuatro Quesos",
    sku: "PIZ-4Q",
    price: 65000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-pizzas",
    categoryName: "Pizzas Gourmet",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },
  {
    id: "item-014",
    name: "Pizza Rúcula y Jamón",
    sku: "PIZ-RUC",
    price: 70000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-pizzas",
    categoryName: "Pizzas Gourmet",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },
  {
    id: "item-015",
    name: "Pizza Napolitana",
    sku: "PIZ-NAP",
    price: 60000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-pizzas",
    categoryName: "Pizzas Gourmet",
    imageUrl: null,
    uom: null,
    kind: "product",
    trackInventory: false,
    stock: null,
  },
]

// ── Clientes ──────────────────────────────────────────────────────────────────

export const fixtureCustomers: PosCustomer[] = [
  {
    id: "cust-1",
    name: "Gustavo Sánchez",
    phone: "+595981234567",
    tin: "12345678-9",
    storeCredit: 0,
    isCreditable: true,
  },
  {
    id: "cust-2",
    name: "María López",
    phone: "+595971234567",
    tin: null,
    storeCredit: 15000,
    isCreditable: true,
  },
  {
    id: "cust-3",
    name: "Empresa XYZ S.A.",
    phone: null,
    tin: "80012345-6",
    storeCredit: 0,
    isCreditable: false,
  },
]

// ── Hotkeys de dev seed (config de ejemplo) ──────────────────────────────────
// Mezcla de categorías (isCategory) e items, para ver la grilla configurada.
// Shape idéntico a register.data.hotkeys. position = slot en la grilla 6×N.

export const fixtureHotkeys = [
  { itemId: "cat-menu", position: 0, color: "amber", isCategory: true },
  { itemId: "cat-minutas", position: 1, color: "slate", isCategory: true },
  { itemId: "cat-bebidas", position: 2, color: "sky", isCategory: true },
  { itemId: "cat-promos", position: 3, color: "emerald", isCategory: true },
  { itemId: "cat-pizzas", position: 4, color: "rose", isCategory: true },
  { itemId: "item-001", position: 6, color: "", isCategory: false }, // Milanesa
  { itemId: "item-007", position: 7, color: "violet", isCategory: false }, // Cerveza
  { itemId: "item-012", position: 8, color: "", isCategory: false }, // Pizza Margherita
  { itemId: "item-004", position: 10, color: "slate", isCategory: false }, // Hamburguesa
]

// ── Bootstrap completo para dev seed ─────────────────────────────────────────

export const fixtureBootstrap = {
  config: fixtureConfig,
  registers: fixtureRegisters,
  items: fixtureItems,
  customers: fixtureCustomers,
  user: { id: "1", role: 1 },
  outlet: { id: "out-1", name: "Central" },
  // Lista completa de sucursales (para el selector de setup en fixtures).
  outlets: [{ id: "out-1", name: "Central" }],
}
