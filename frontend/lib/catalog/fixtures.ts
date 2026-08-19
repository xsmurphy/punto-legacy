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

import type { PosItem, PosCustomer, PosConfig, PosRegister, PosTaxRate, PosCategory, PosBrand, PaymentMethodConfig } from "@/lib/types/pos-bootstrap"

// ── Config de tenant ──────────────────────────────────────────────────────────

export const fixtureConfig: PosConfig = {
  currency: "Gs",
  decimal: "no",
  thousand: "dot",
  taxName: "IVA",
  tinName: "RUC",
  country: "PY",
  timezone: "America/Asuncion",
  companyName: "Punto Restaurante",
  companyId: "1",
  companyLogo: null,
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

// ── Categorías y marcas (context/45: lista propia, NO derivada de los items) ──

export const fixtureCategories: PosCategory[] = [
  { id: "cat-menu", name: "Menú del día" },
  { id: "cat-minutas", name: "Minutas" },
  { id: "cat-bebidas", name: "Bebidas con alcohol" },
  { id: "cat-promos", name: "Promos" },
  { id: "cat-pizzas", name: "Pizzas Gourmet" },
  // Sin productos asignados — ejercita el efecto colateral deseado (una
  // categoría vacía ahora existe para la caja, ver PosCategory JSDoc).
  { id: "cat-postres", name: "Postres" },
]

export const fixtureBrands: PosBrand[] = []

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
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
  },
  {
    id: "item-002",
    name: "Pollo asado con papas",
    sku: "POL-ASA",
    price: 30000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-menu",
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
  },
  {
    id: "item-003",
    name: "Sopa paraguaya",
    sku: "SOP-PAR",
    price: 8000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-menu",
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
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
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
  },
  {
    id: "item-005",
    name: "Sandwich de lomo",
    sku: "SAN-LOM",
    price: 22000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-minutas",
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
  },
  {
    id: "item-006",
    name: "Empanadas (x3)",
    sku: "EMP-X3",
    price: 12000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-minutas",
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
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
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: true,
    stock: 24,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
  },
  {
    id: "item-008",
    name: "Vino tinto copa",
    sku: "VIN-TIN",
    price: 15000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-bebidas",
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: true,
    stock: -3,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
  },
  {
    id: "item-009",
    name: "Gin tónic",
    sku: "GIN-TON",
    price: 20000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-bebidas",
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: true,
    stock: 0,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
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
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
  },
  {
    id: "item-011",
    name: "2x1 Hamburguesas",
    sku: "2X1-HAM",
    price: 45000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-promos",
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
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
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
  },
  {
    id: "item-013",
    name: "Pizza Cuatro Quesos",
    sku: "PIZ-4Q",
    price: 65000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-pizzas",
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
  },
  {
    id: "item-014",
    name: "Pizza Rúcula y Jamón",
    sku: "PIZ-RUC",
    price: 70000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-pizzas",
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
  },
  {
    id: "item-015",
    name: "Pizza Napolitana",
    sku: "PIZ-NAP",
    price: 60000,
    taxIncluded: true,
    taxId: "tax-1",
    categoryId: "cat-pizzas",
    brandId: null,
    outletId: null,
    imageUrl: null,
    uom: null,
    kind: "product",
    discountPercent: null,
    trackInventory: false,
    stock: null,
    isGroup: false,
    parentId: null,
    hasAddons: false,
    addonGroups: [],
    compoundItems: [],
  },
]

// ── Impuestos (F2b, context/38) ──────────────────────────────────────────────
// Todos los items del fixture usan "tax-1" (IVA 10%, incluido) — matchea el
// modelo paraguayo que ya asumía el TAX_RATE hardcodeado que este fixture
// reemplaza.

export const fixtureTaxes: PosTaxRate[] = [
  { id: "tax-1", rate: 10, kind: "rate" },
]

export const fixtureOutletTaxIncluded = true

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

// ── Métodos de pago para dev seed ────────────────────────────────────────────

export const fixturePaymentMethods: PaymentMethodConfig[] = [
  { id: "efectivo", name: "Efectivo", code: "A", hasChange: true, requiresIdentifier: false },
  {
    id: "tcredito",
    name: "T. Crédito",
    code: "S",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Nro de operación",
    identifierPlaceholder: "Ej. 123456",
  },
  {
    id: "tdebito",
    name: "T. Débito",
    code: "D",
    hasChange: false,
    requiresIdentifier: true,
    identifierLabel: "Nro de operación",
    identifierPlaceholder: "Ej. 123456",
  },
  { id: "transferencia", name: "Transferencia", code: "F", hasChange: false, requiresIdentifier: false },
]

// ── Bootstrap completo para dev seed ─────────────────────────────────────────

export const fixtureBootstrap = {
  config: fixtureConfig,
  registers: fixtureRegisters,
  items: fixtureItems,
  customers: fixtureCustomers,
  paymentMethods: fixturePaymentMethods,
  user: { id: "1", role: 1 },
  // lat/lng: coords de Asunción para que la vista mapa de /pos/ordenes tenga
  // un PIN de local con qué encuadrar cuando se diseña con fixtures.
  outlet: { id: "out-1", name: "Central", lat: -25.2637, lng: -57.6359 },
  // Lista completa de sucursales (para el selector de setup en fixtures).
  outlets: [{ id: "out-1", name: "Central" }],
  taxes: fixtureTaxes,
  outletTaxIncluded: fixtureOutletTaxIncluded,
  categories: fixtureCategories,
  brands: fixtureBrands,
  // Sin fixture propia: el flujo de impresión ya degrada a
  // `renderFallbackTicketHtml` cuando no hay plantilla resuelta (ver
  // `lib/hardware/printers/print-in-browser.ts`), así que [] es suficiente
  // para diseñar UI sin backend real.
  printTemplates: [] as import("@/lib/types/pos-bootstrap").PosPrintTemplate[],
}
