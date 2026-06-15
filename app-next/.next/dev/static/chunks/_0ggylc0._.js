(globalThis["TURBOPACK"] || (globalThis["TURBOPACK"] = [])).push([typeof document === "object" ? document.currentScript : undefined,
"[project]/components/ui/button.tsx [app-client] (ecmascript)", ((__turbopack_context__) => {
"use strict";

__turbopack_context__.s([
    "Button",
    ()=>Button,
    "buttonVariants",
    ()=>buttonVariants
]);
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/dist/compiled/react/jsx-dev-runtime.js [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$class$2d$variance$2d$authority$2f$dist$2f$index$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/class-variance-authority/dist/index.mjs [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f40$radix$2d$ui$2f$react$2d$slot$2f$dist$2f$index$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__$2a$__as__Slot$3e$__ = __turbopack_context__.i("[project]/node_modules/@radix-ui/react-slot/dist/index.mjs [app-client] (ecmascript) <export * as Slot>");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$utils$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/utils.ts [app-client] (ecmascript)");
;
;
;
;
const buttonVariants = (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$class$2d$variance$2d$authority$2f$dist$2f$index$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__["cva"])("group/button inline-flex shrink-0 items-center justify-center rounded-2xl border border-transparent bg-clip-padding text-sm font-medium whitespace-nowrap transition-all outline-none select-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/30 active:not-aria-[haspopup]:translate-y-px disabled:pointer-events-none disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4", {
    variants: {
        variant: {
            default: "bg-primary text-primary-foreground hover:bg-primary/80",
            outline: "border-border bg-background hover:bg-muted hover:text-foreground aria-expanded:bg-muted aria-expanded:text-foreground dark:bg-transparent dark:hover:bg-input/30",
            secondary: "bg-secondary text-secondary-foreground hover:bg-[color-mix(in_oklch,var(--secondary),var(--foreground)_5%)] aria-expanded:bg-secondary aria-expanded:text-secondary-foreground",
            ghost: "hover:bg-muted hover:text-foreground aria-expanded:bg-muted aria-expanded:text-foreground dark:hover:bg-muted/50",
            destructive: "bg-destructive/10 text-destructive hover:bg-destructive/20 focus-visible:border-destructive/40 focus-visible:ring-destructive/20 dark:bg-destructive/20 dark:hover:bg-destructive/30 dark:focus-visible:ring-destructive/40",
            link: "text-primary underline-offset-4 hover:underline"
        },
        size: {
            default: "h-8 gap-1.5 px-3 has-data-[icon=inline-end]:pr-2.5 has-data-[icon=inline-start]:pl-2.5",
            xs: "h-6 gap-1 px-2.5 text-xs has-data-[icon=inline-end]:pr-2 has-data-[icon=inline-start]:pl-2 [&_svg:not([class*='size-'])]:size-3",
            sm: "h-7 gap-1 px-3 has-data-[icon=inline-end]:pr-2 has-data-[icon=inline-start]:pl-2",
            lg: "h-9 gap-1.5 px-4 has-data-[icon=inline-end]:pr-3 has-data-[icon=inline-start]:pl-3",
            icon: "size-8",
            "icon-xs": "size-6 [&_svg:not([class*='size-'])]:size-3",
            "icon-sm": "size-7",
            "icon-lg": "size-9"
        }
    },
    defaultVariants: {
        variant: "default",
        size: "default"
    }
});
function Button({ className, variant = "default", size = "default", asChild = false, ...props }) {
    const Comp = asChild ? __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f40$radix$2d$ui$2f$react$2d$slot$2f$dist$2f$index$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__$2a$__as__Slot$3e$__["Slot"].Root : "button";
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(Comp, {
        "data-slot": "button",
        "data-variant": variant,
        "data-size": size,
        className: (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$utils$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["cn"])(buttonVariants({
            variant,
            size,
            className
        })),
        ...props
    }, void 0, false, {
        fileName: "[project]/components/ui/button.tsx",
        lineNumber: 55,
        columnNumber: 5
    }, this);
}
_c = Button;
;
var _c;
__turbopack_context__.k.register(_c, "Button");
if (typeof globalThis.$RefreshHelpers$ === 'object' && globalThis.$RefreshHelpers !== null) {
    __turbopack_context__.k.registerExports(__turbopack_context__.m, globalThis.$RefreshHelpers$);
}
}),
"[project]/lib/catalog/store.ts [app-client] (ecmascript)", ((__turbopack_context__) => {
"use strict";

__turbopack_context__.s([
    "useCatalogStore",
    ()=>useCatalogStore
]);
/**
 * Store en memoria del catálogo del POS (Zustand).
 *
 * Toda la UI del POS lee de este store — NUNCA hace fetch directo para
 * buscar un producto o un cliente. Esto garantiza:
 *   1. Búsqueda síncrona e instantánea (cero round-trips).
 *   2. Frontera offline: al activarla (fase offline), solo cambia la
 *      fuente de hidratación (BFF → IndexedDB), la UI no toca.
 *
 * Ciclo de vida:
 *   1. Al iniciar sesión de caja, `hydrate()` se llama con los datos del
 *      BFF `/api/pos/bootstrap`. El store pasa a `status: 'ready'`.
 *   2. La UI busca en `lib/catalog/search.ts` (índice local sobre `items`).
 *   3. Los comandos (`lib/commands/`) mutan vía el BFF y llaman a
 *      `patchCustomer` / `patchItem` para actualizar el store sin
 *      re-fetch total.
 *
 * TODO (Slice A): conectar `hydrate()` al fetch real de `/api/pos/bootstrap`.
 * TODO (Fase offline): persistir en IndexedDB (Dexie) + delta-sync.
 *
 * Ver context/16-app-next-rewrite.md §5 (frontera offline) y §7 Slice A.
 */ var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$zustand$2f$esm$2f$react$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/zustand/esm/react.mjs [app-client] (ecmascript)");
;
const initialState = {
    status: "idle",
    error: null,
    items: [],
    customers: [],
    config: null,
    registers: []
};
const useCatalogStore = (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$zustand$2f$esm$2f$react$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__["create"])()((set)=>({
        ...initialState,
        hydrate: (data)=>{
            set({
                status: "ready",
                error: null,
                items: data.items,
                customers: data.customers,
                config: data.config,
                registers: data.registers
            });
        },
        patchCustomer: (customer)=>{
            set((state)=>({
                    customers: state.customers.some((c)=>c.id === customer.id) ? state.customers.map((c)=>c.id === customer.id ? customer : c) : [
                        customer,
                        ...state.customers
                    ]
                }));
        },
        patchItem: (item)=>{
            set((state)=>({
                    items: state.items.map((i)=>i.id === item.id ? item : i)
                }));
        },
        reset: ()=>set(initialState)
    }));
if (typeof globalThis.$RefreshHelpers$ === 'object' && globalThis.$RefreshHelpers !== null) {
    __turbopack_context__.k.registerExports(__turbopack_context__.m, globalThis.$RefreshHelpers$);
}
}),
"[project]/lib/cart/store.ts [app-client] (ecmascript)", ((__turbopack_context__) => {
"use strict";

__turbopack_context__.s([
    "selectCartTotal",
    ()=>selectCartTotal,
    "useCartStore",
    ()=>useCartStore
]);
/**
 * Store del carrito de venta (Zustand).
 *
 * Maneja el estado local del carrito — líneas, selección, flags de modo
 * y cliente. Toda la lógica de mutación es síncrona (sin side-effects):
 * el commit real al backend se hace desde `lib/commands/create-sale.ts`.
 *
 * Ciclo de vida:
 *   1. El cajero agrega items desde el catálogo → `addItem`.
 *   2. Selecciona una línea → `selectLine` (muestra controles +/−).
 *   3. Ajusta cantidades / agrega notas.
 *   4. Cobra → `lib/commands/createSale` → `clear`.
 *
 * Para el total, computarlo en el componente desde `lines`:
 *   const lines = useCartStore(s => s.lines)
 *   const total = lines.reduce((s,l) => s + l.qty * l.unitPrice, 0)
 *
 * Ver context/16-app-next-rewrite.md §7 Slice A.
 */ var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$zustand$2f$esm$2f$react$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/zustand/esm/react.mjs [app-client] (ecmascript)");
;
const selectCartTotal = (s)=>s.lines.reduce((sum, line)=>sum + line.qty * line.unitPrice, 0);
// ── Store ─────────────────────────────────────────────────────────────────────
const initialState = {
    lines: [],
    selectedLineId: null,
    customer: null,
    credito: false,
    interno: false
};
const useCartStore = (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$zustand$2f$esm$2f$react$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__["create"])()((set, _get)=>({
        ...initialState,
        addItem: (item)=>{
            set((state)=>{
                const existing = state.lines.find((l)=>l.itemId === item.id);
                if (existing) {
                    return {
                        lines: state.lines.map((l)=>l.itemId === item.id ? {
                                ...l,
                                qty: l.qty + 1
                            } : l),
                        selectedLineId: existing.lineId
                    };
                }
                const newLine = {
                    lineId: crypto.randomUUID(),
                    itemId: item.id,
                    name: item.name,
                    qty: 1,
                    unitPrice: item.price
                };
                return {
                    lines: [
                        ...state.lines,
                        newLine
                    ],
                    selectedLineId: newLine.lineId
                };
            });
        },
        removeLine: (lineId)=>{
            set((state)=>{
                const remaining = state.lines.filter((l)=>l.lineId !== lineId);
                const nextSelected = state.selectedLineId === lineId ? remaining[remaining.length - 1]?.lineId ?? null : state.selectedLineId;
                return {
                    lines: remaining,
                    selectedLineId: nextSelected
                };
            });
        },
        incQty: (lineId)=>{
            set((state)=>({
                    lines: state.lines.map((l)=>l.lineId === lineId ? {
                            ...l,
                            qty: l.qty + 1
                        } : l)
                }));
        },
        decQty: (lineId)=>{
            set((state)=>{
                const line = state.lines.find((l)=>l.lineId === lineId);
                if (!line) return state;
                if (line.qty <= 1) {
                    const remaining = state.lines.filter((l)=>l.lineId !== lineId);
                    const nextSelected = state.selectedLineId === lineId ? remaining[remaining.length - 1]?.lineId ?? null : state.selectedLineId;
                    return {
                        lines: remaining,
                        selectedLineId: nextSelected
                    };
                }
                return {
                    lines: state.lines.map((l)=>l.lineId === lineId ? {
                            ...l,
                            qty: l.qty - 1
                        } : l)
                };
            });
        },
        selectLine: (lineId)=>{
            set({
                selectedLineId: lineId
            });
        },
        clear: ()=>{
            set({
                ...initialState
            });
        },
        setCustomer: (customer)=>{
            set({
                customer
            });
        },
        toggleCredito: ()=>{
            set((state)=>({
                    credito: !state.credito
                }));
        },
        toggleInterno: ()=>{
            set((state)=>({
                    interno: !state.interno
                }));
        },
        setLineNote: (lineId, note)=>{
            set((state)=>({
                    lines: state.lines.map((l)=>l.lineId === lineId ? {
                            ...l,
                            note
                        } : l)
                }));
        }
    }));
if (typeof globalThis.$RefreshHelpers$ === 'object' && globalThis.$RefreshHelpers !== null) {
    __turbopack_context__.k.registerExports(__turbopack_context__.m, globalThis.$RefreshHelpers$);
}
}),
"[project]/lib/catalog/fixtures.ts [app-client] (ecmascript)", ((__turbopack_context__) => {
"use strict";

/**
 * Fixtures del catálogo para desarrollo (dev seed).
 *
 * Respeta exactamente el tipo `PosItem` de `lib/types/pos-bootstrap.ts`.
 * Se usa para hidratar el `useCatalogStore` durante el desarrollo,
 * sin necesitar el BFF real.
 *
 * Se importa SOLO desde componentes cliente que lo cargan al montar (Slice A dev).
 * En producción, el store se hidrata desde `/api/pos/bootstrap`.
 */ __turbopack_context__.s([
    "fixtureBootstrap",
    ()=>fixtureBootstrap,
    "fixtureCategories",
    ()=>fixtureCategories,
    "fixtureConfig",
    ()=>fixtureConfig,
    "fixtureCustomers",
    ()=>fixtureCustomers,
    "fixtureItems",
    ()=>fixtureItems,
    "fixtureRegisters",
    ()=>fixtureRegisters
]);
const fixtureConfig = {
    currency: "Gs",
    decimal: "no",
    thousand: "dot",
    taxName: "IVA",
    tinName: "RUC",
    country: "PY",
    companyName: "Punto Restaurante",
    companyId: "1",
    publicUrl: "http://localhost:3001"
};
const fixtureRegisters = [
    {
        id: "reg-1",
        name: "Caja Principal",
        outletId: "out-1",
        expeditionPoint: "001"
    }
];
const fixtureCategories = [
    {
        id: "cat-menu",
        name: "Menú del día",
        abbrev: "Me",
        color: "#6366f1"
    },
    {
        id: "cat-minutas",
        name: "Minutas",
        abbrev: "Mi",
        color: "#0ea5e9"
    },
    {
        id: "cat-bebidas",
        name: "Bebidas con alcohol",
        abbrev: "Be",
        color: "#f59e0b"
    },
    {
        id: "cat-promos",
        name: "Promos",
        abbrev: "Pr",
        color: "#10b981"
    },
    {
        id: "cat-pizzas",
        name: "Pizzas Gourmet",
        abbrev: "Pi",
        color: "#ef4444"
    }
];
const fixtureItems = [
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
        trackInventory: false
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
        trackInventory: false
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
        trackInventory: false
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
        trackInventory: false
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
        trackInventory: false
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
        trackInventory: false
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
        trackInventory: true
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
        trackInventory: true
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
        trackInventory: true
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
        trackInventory: false
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
        trackInventory: false
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
        trackInventory: false
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
        trackInventory: false
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
        trackInventory: false
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
        trackInventory: false
    }
];
const fixtureCustomers = [
    {
        id: "cust-1",
        name: "Gustavo Sánchez",
        phone: "+595981234567",
        tin: "12345678-9",
        storeCredit: 0,
        isCreditable: true
    },
    {
        id: "cust-2",
        name: "María López",
        phone: "+595971234567",
        tin: null,
        storeCredit: 15000,
        isCreditable: true
    },
    {
        id: "cust-3",
        name: "Empresa XYZ S.A.",
        phone: null,
        tin: "80012345-6",
        storeCredit: 0,
        isCreditable: false
    }
];
const fixtureBootstrap = {
    config: fixtureConfig,
    registers: fixtureRegisters,
    items: fixtureItems,
    customers: fixtureCustomers,
    user: {
        id: "1",
        role: 1
    },
    outlet: {
        id: "out-1",
        name: "Central"
    }
};
if (typeof globalThis.$RefreshHelpers$ === 'object' && globalThis.$RefreshHelpers !== null) {
    __turbopack_context__.k.registerExports(__turbopack_context__.m, globalThis.$RefreshHelpers$);
}
}),
"[project]/components/register/product-area.tsx [app-client] (ecmascript)", ((__turbopack_context__) => {
"use strict";

__turbopack_context__.s([
    "ProductArea",
    ()=>ProductArea
]);
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/dist/compiled/react/jsx-dev-runtime.js [app-client] (ecmascript)");
/**
 * Área izquierda del register — grid de categorías/productos + barra inferior.
 *
 * Layout (fidelidad §6.1):
 *   - Grid de tiles: (a) categoría = color sólido + abreviatura estilo tabla
 *     periódica + label; (b) producto = imagen/color + nombre overlay.
 *   - Slots vacíos = tiles placeholder gris.
 *   - Barra inferior scrolleable: botón back circular + chips de categoría.
 *   - FAB "+" abajo-izquierda (stub).
 *   - Info de sesión: avatar + outlet + caja + versión.
 */ var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$index$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/dist/compiled/react/index.js [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$image$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/image.js [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$plus$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Plus$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/plus.mjs [app-client] (ecmascript) <export default as Plus>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$chevron$2d$left$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__ChevronLeft$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/chevron-left.mjs [app-client] (ecmascript) <export default as ChevronLeft>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$user$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__User$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/user.mjs [app-client] (ecmascript) <export default as User>");
var __TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/components/ui/button.tsx [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$utils$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/utils.ts [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/catalog/store.ts [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/cart/store.ts [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$fixtures$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/catalog/fixtures.ts [app-client] (ecmascript)");
;
var _s = __turbopack_context__.k.signature();
"use client";
;
;
;
;
;
;
;
;
function getCategoryMeta(categoryId) {
    if (!categoryId) return null;
    return __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$fixtures$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["fixtureCategories"].find((c)=>c.id === categoryId) ?? null;
}
// ── Tile de categoría ─────────────────────────────────────────────────────────
function CategoryTile({ category, onClick, isActive }) {
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("button", {
        onClick: onClick,
        className: (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$utils$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["cn"])("group relative flex aspect-square flex-col items-start justify-between overflow-hidden rounded-xl p-2.5 transition-all active:scale-95", isActive && "ring-2 ring-white/60"),
        style: {
            backgroundColor: category.color
        },
        "aria-label": category.name,
        children: [
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("span", {
                className: "text-3xl font-bold leading-none tracking-tight text-white/90",
                children: category.abbrev
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 57,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("span", {
                className: "line-clamp-2 text-left text-[11px] font-medium leading-tight text-white/80",
                children: category.name
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 61,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "absolute inset-0 bg-white/0 transition-colors group-hover:bg-white/10"
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 65,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/components/register/product-area.tsx",
        lineNumber: 47,
        columnNumber: 5
    }, this);
}
_c = CategoryTile;
// ── Tile de producto ──────────────────────────────────────────────────────────
function ProductTile({ item, onClick }) {
    const catMeta = getCategoryMeta(item.categoryId);
    const bgColor = catMeta?.color ?? "#6b7280";
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("button", {
        onClick: onClick,
        className: "group relative flex aspect-square flex-col overflow-hidden rounded-xl transition-all active:scale-95",
        "aria-label": item.name,
        children: [
            item.imageUrl ? /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$image$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["default"], {
                src: item.imageUrl,
                alt: item.name,
                fill: true,
                sizes: "(max-width: 768px) 33vw, 20vw",
                className: "object-cover"
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 90,
                columnNumber: 9
            }, this) : /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "absolute inset-0",
                style: {
                    backgroundColor: bgColor
                }
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 98,
                columnNumber: 9
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 to-transparent"
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 102,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "absolute inset-x-0 bottom-0 p-2",
                children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("span", {
                    className: "line-clamp-2 text-left text-[11px] font-medium leading-tight text-white",
                    children: item.name
                }, void 0, false, {
                    fileName: "[project]/components/register/product-area.tsx",
                    lineNumber: 106,
                    columnNumber: 9
                }, this)
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 105,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "absolute inset-0 bg-white/0 transition-colors group-hover:bg-white/10"
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 112,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/components/register/product-area.tsx",
        lineNumber: 83,
        columnNumber: 5
    }, this);
}
_c1 = ProductTile;
// ── Tile placeholder ─────────────────────────────────────────────────────────
function PlaceholderTile() {
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        className: "aspect-square rounded-xl bg-muted/40 dark:bg-muted/20"
    }, void 0, false, {
        fileName: "[project]/components/register/product-area.tsx",
        lineNumber: 121,
        columnNumber: 5
    }, this);
}
_c2 = PlaceholderTile;
function ProductArea() {
    _s();
    const items = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCatalogStore"])({
        "ProductArea.useCatalogStore[items]": (s)=>s.items
    }["ProductArea.useCatalogStore[items]"]);
    const addItem = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "ProductArea.useCartStore[addItem]": (s)=>s.addItem
    }["ProductArea.useCartStore[addItem]"]);
    const [view, setView] = __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$index$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useState"]("categories");
    const activeCategoryId = view === "categories" ? null : view.categoryId;
    // Items de la categoría activa
    const visibleItems = __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$index$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useMemo"]({
        "ProductArea.useMemo[visibleItems]": ()=>{
            if (view === "categories") return [];
            return items.filter({
                "ProductArea.useMemo[visibleItems]": (i)=>i.categoryId === view.categoryId
            }["ProductArea.useMemo[visibleItems]"]);
        }
    }["ProductArea.useMemo[visibleItems]"], [
        view,
        items
    ]);
    // Número de slots del grid (múltiplo de la cantidad de columnas) para slots vacíos
    const COLS = 5;
    const ROWS_MIN = 3;
    const MIN_TILES = COLS * ROWS_MIN;
    const handleCategoryClick = (categoryId)=>{
        setView({
            categoryId
        });
    };
    const handleBack = ()=>{
        setView("categories");
    };
    const handleProductClick = (item)=>{
        addItem({
            id: item.id,
            name: item.name,
            price: item.price
        });
    };
    // ── Render del grid ────────────────────────────────────────────────────────
    const renderGrid = ()=>{
        if (view === "categories") {
            const cats = __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$fixtures$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["fixtureCategories"];
            const filled = cats.length;
            const total = Math.max(MIN_TILES, Math.ceil(filled / COLS) * COLS);
            return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "grid flex-1 gap-2 p-3",
                style: {
                    gridTemplateColumns: `repeat(${COLS}, 1fr)`
                },
                children: [
                    cats.map((cat)=>/*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(CategoryTile, {
                            category: cat,
                            isActive: activeCategoryId === cat.id,
                            onClick: ()=>handleCategoryClick(cat.id)
                        }, cat.id, false, {
                            fileName: "[project]/components/register/product-area.tsx",
                            lineNumber: 174,
                            columnNumber: 13
                        }, this)),
                    Array.from({
                        length: total - filled
                    }).map((_, i)=>/*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(PlaceholderTile, {}, `ph-${i}`, false, {
                            fileName: "[project]/components/register/product-area.tsx",
                            lineNumber: 182,
                            columnNumber: 13
                        }, this))
                ]
            }, void 0, true, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 169,
                columnNumber: 9
            }, this);
        }
        const filled = visibleItems.length;
        const total = Math.max(MIN_TILES, Math.ceil(filled / COLS) * COLS);
        return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
            className: "grid flex-1 gap-2 p-3",
            style: {
                gridTemplateColumns: `repeat(${COLS}, 1fr)`
            },
            children: [
                visibleItems.map((item)=>/*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(ProductTile, {
                        item: item,
                        onClick: ()=>handleProductClick(item)
                    }, item.id, false, {
                        fileName: "[project]/components/register/product-area.tsx",
                        lineNumber: 196,
                        columnNumber: 11
                    }, this)),
                Array.from({
                    length: total - filled
                }).map((_, i)=>/*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(PlaceholderTile, {}, `ph-${i}`, false, {
                        fileName: "[project]/components/register/product-area.tsx",
                        lineNumber: 203,
                        columnNumber: 11
                    }, this))
            ]
        }, void 0, true, {
            fileName: "[project]/components/register/product-area.tsx",
            lineNumber: 191,
            columnNumber: 7
        }, this);
    };
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        className: "relative flex h-full flex-col overflow-hidden",
        children: [
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex-1 overflow-y-auto",
                children: renderGrid()
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 212,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(CategoryBar, {
                activeId: activeCategoryId,
                onBack: handleBack,
                onSelect: handleCategoryClick,
                showBack: view !== "categories"
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 215,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "absolute bottom-14 left-3 z-10",
                children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                    size: "icon",
                    variant: "secondary",
                    className: "size-10 rounded-full shadow-md",
                    "aria-label": "Agregar producto",
                    children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$plus$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Plus$3e$__["Plus"], {
                        className: "size-5"
                    }, void 0, false, {
                        fileName: "[project]/components/register/product-area.tsx",
                        lineNumber: 230,
                        columnNumber: 11
                    }, this)
                }, void 0, false, {
                    fileName: "[project]/components/register/product-area.tsx",
                    lineNumber: 224,
                    columnNumber: 9
                }, this)
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 223,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(SessionInfo, {}, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 235,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/components/register/product-area.tsx",
        lineNumber: 210,
        columnNumber: 5
    }, this);
}
_s(ProductArea, "QOKGpGevN8GvERFy7VfyaEJL9R0=", false, function() {
    return [
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCatalogStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"]
    ];
});
_c3 = ProductArea;
// ── CategoryBar ───────────────────────────────────────────────────────────────
function CategoryBar({ activeId, onBack, onSelect, showBack }) {
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        className: "flex h-11 shrink-0 items-center gap-2 border-t border-border bg-background/80 px-2 backdrop-blur-sm",
        children: [
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                size: "icon",
                variant: showBack ? "outline" : "ghost",
                className: "size-8 shrink-0 rounded-full",
                onClick: onBack,
                disabled: !showBack,
                "aria-label": "Volver a categorías",
                children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$chevron$2d$left$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__ChevronLeft$3e$__["ChevronLeft"], {
                    className: "size-4"
                }, void 0, false, {
                    fileName: "[project]/components/register/product-area.tsx",
                    lineNumber: 264,
                    columnNumber: 9
                }, this)
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 256,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex flex-1 gap-1.5 overflow-x-auto py-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden",
                children: __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$fixtures$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["fixtureCategories"].map((cat)=>/*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("button", {
                        onClick: ()=>onSelect(cat.id),
                        className: (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$utils$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["cn"])("flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors", activeId === cat.id ? "text-white" : "bg-muted text-muted-foreground hover:bg-muted/80"),
                        style: activeId === cat.id ? {
                            backgroundColor: cat.color
                        } : undefined,
                        children: cat.name
                    }, cat.id, false, {
                        fileName: "[project]/components/register/product-area.tsx",
                        lineNumber: 270,
                        columnNumber: 11
                    }, this))
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 268,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/components/register/product-area.tsx",
        lineNumber: 254,
        columnNumber: 5
    }, this);
}
_c4 = CategoryBar;
// ── Info de sesión ─────────────────────────────────────────────────────────────
function SessionInfo() {
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        className: "absolute bottom-12 right-3 flex items-center gap-2 rounded-lg bg-background/70 px-2.5 py-1.5 backdrop-blur-sm",
        children: [
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex size-6 items-center justify-center rounded-full bg-muted",
                children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$user$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__User$3e$__["User"], {
                    className: "size-3.5 text-muted-foreground"
                }, void 0, false, {
                    fileName: "[project]/components/register/product-area.tsx",
                    lineNumber: 295,
                    columnNumber: 9
                }, this)
            }, void 0, false, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 294,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex flex-col leading-none",
                children: [
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("span", {
                        className: "text-[10px] font-medium text-foreground",
                        children: "Central · Caja Principal"
                    }, void 0, false, {
                        fileName: "[project]/components/register/product-area.tsx",
                        lineNumber: 298,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("span", {
                        className: "text-[9px] text-muted-foreground",
                        children: "v0.1.0-alpha"
                    }, void 0, false, {
                        fileName: "[project]/components/register/product-area.tsx",
                        lineNumber: 301,
                        columnNumber: 9
                    }, this)
                ]
            }, void 0, true, {
                fileName: "[project]/components/register/product-area.tsx",
                lineNumber: 297,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/components/register/product-area.tsx",
        lineNumber: 293,
        columnNumber: 5
    }, this);
}
_c5 = SessionInfo;
var _c, _c1, _c2, _c3, _c4, _c5;
__turbopack_context__.k.register(_c, "CategoryTile");
__turbopack_context__.k.register(_c1, "ProductTile");
__turbopack_context__.k.register(_c2, "PlaceholderTile");
__turbopack_context__.k.register(_c3, "ProductArea");
__turbopack_context__.k.register(_c4, "CategoryBar");
__turbopack_context__.k.register(_c5, "SessionInfo");
if (typeof globalThis.$RefreshHelpers$ === 'object' && globalThis.$RefreshHelpers !== null) {
    __turbopack_context__.k.registerExports(__turbopack_context__.m, globalThis.$RefreshHelpers$);
}
}),
"[project]/lib/format-money.ts [app-client] (ecmascript)", ((__turbopack_context__) => {
"use strict";

/**
 * Formatea un número como moneda según la config del tenant.
 *
 * Reutilizable en contextos no-input (display, labels, botones).
 * Para inputs editables usar `<MoneyInput>`.
 */ __turbopack_context__.s([
    "formatMoney",
    ()=>formatMoney
]);
function formatMoney(value, config) {
    const thousand = config?.thousand === "comma" ? "," : ".";
    const decimalSep = thousand === "," ? "." : ",";
    const useDecimals = config?.decimal === "yes";
    const currency = config?.currency ?? "Gs";
    const decimals = useDecimals ? 2 : 0;
    const scaled = Math.round(value * Math.pow(10, decimals));
    const abs = Math.abs(scaled).toString().padStart(decimals + 1, "0");
    const intPart = abs.slice(0, abs.length - decimals) || "0";
    const decPart = decimals > 0 ? abs.slice(-decimals) : "";
    const withThousand = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousand);
    const number = decPart ? `${withThousand}${decimalSep}${decPart}` : withThousand;
    return `${currency} ${number}`;
}
if (typeof globalThis.$RefreshHelpers$ === 'object' && globalThis.$RefreshHelpers !== null) {
    __turbopack_context__.k.registerExports(__turbopack_context__.m, globalThis.$RefreshHelpers$);
}
}),
"[project]/components/register/cart-panel.tsx [app-client] (ecmascript)", ((__turbopack_context__) => {
"use strict";

__turbopack_context__.s([
    "CartPanel",
    ()=>CartPanel
]);
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/dist/compiled/react/jsx-dev-runtime.js [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$arrow$2d$up$2d$down$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__ArrowUpDown$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/arrow-up-down.mjs [app-client] (ecmascript) <export default as ArrowUpDown>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$search$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Search$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/search.mjs [app-client] (ecmascript) <export default as Search>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$user$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__User$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/user.mjs [app-client] (ecmascript) <export default as User>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$ellipsis$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__MoreHorizontal$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/ellipsis.mjs [app-client] (ecmascript) <export default as MoreHorizontal>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$x$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__X$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/x.mjs [app-client] (ecmascript) <export default as X>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$plus$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Plus$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/plus.mjs [app-client] (ecmascript) <export default as Plus>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$minus$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Minus$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/minus.mjs [app-client] (ecmascript) <export default as Minus>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$trash$2d$2$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Trash2$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/trash-2.mjs [app-client] (ecmascript) <export default as Trash2>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$chevron$2d$down$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__ChevronDown$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/chevron-down.mjs [app-client] (ecmascript) <export default as ChevronDown>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$dollar$2d$sign$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__DollarSign$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/dollar-sign.mjs [app-client] (ecmascript) <export default as DollarSign>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$tag$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Tag$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/tag.mjs [app-client] (ecmascript) <export default as Tag>");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$message$2d$square$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__MessageSquare$3e$__ = __turbopack_context__.i("[project]/node_modules/lucide-react/dist/esm/icons/message-square.mjs [app-client] (ecmascript) <export default as MessageSquare>");
var __TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/components/ui/button.tsx [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$utils$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/utils.ts [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/cart/store.ts [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/catalog/store.ts [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$format$2d$money$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/format-money.ts [app-client] (ecmascript)");
;
var _s = __turbopack_context__.k.signature(), _s1 = __turbopack_context__.k.signature();
"use client";
;
;
;
;
;
;
function CartPanel() {
    _s();
    const lines = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[lines]": (s)=>s.lines
    }["CartPanel.useCartStore[lines]"]);
    const selectedLineId = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[selectedLineId]": (s)=>s.selectedLineId
    }["CartPanel.useCartStore[selectedLineId]"]);
    const customer = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[customer]": (s)=>s.customer
    }["CartPanel.useCartStore[customer]"]);
    const credito = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[credito]": (s)=>s.credito
    }["CartPanel.useCartStore[credito]"]);
    const interno = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[interno]": (s)=>s.interno
    }["CartPanel.useCartStore[interno]"]);
    const total = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])(__TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["selectCartTotal"]);
    const clear = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[clear]": (s)=>s.clear
    }["CartPanel.useCartStore[clear]"]);
    const toggleCredito = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[toggleCredito]": (s)=>s.toggleCredito
    }["CartPanel.useCartStore[toggleCredito]"]);
    const toggleInterno = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[toggleInterno]": (s)=>s.toggleInterno
    }["CartPanel.useCartStore[toggleInterno]"]);
    const selectLine = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[selectLine]": (s)=>s.selectLine
    }["CartPanel.useCartStore[selectLine]"]);
    const removeLine = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[removeLine]": (s)=>s.removeLine
    }["CartPanel.useCartStore[removeLine]"]);
    const incQty = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[incQty]": (s)=>s.incQty
    }["CartPanel.useCartStore[incQty]"]);
    const decQty = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CartPanel.useCartStore[decQty]": (s)=>s.decQty
    }["CartPanel.useCartStore[decQty]"]);
    const config = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCatalogStore"])({
        "CartPanel.useCatalogStore[config]": (s)=>s.config
    }["CartPanel.useCatalogStore[config]"]);
    const selectedLine = lines.find((l)=>l.lineId === selectedLineId) ?? null;
    const totalValue = total;
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        className: "flex h-full flex-col border-l border-border bg-background",
        children: [
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(CartToolbar, {}, void 0, false, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 63,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(CustomerChip, {
                customer: customer
            }, void 0, false, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 66,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex-1 overflow-y-auto",
                children: lines.length === 0 ? /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(EmptyCart, {}, void 0, false, {
                    fileName: "[project]/components/register/cart-panel.tsx",
                    lineNumber: 71,
                    columnNumber: 11
                }, this) : /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                    className: "flex flex-col",
                    children: lines.map((line)=>{
                        const isActive = line.lineId === selectedLineId;
                        return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                            children: [
                                /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(CartLineRow, {
                                    line: line,
                                    isActive: isActive,
                                    config: config,
                                    onSelect: ()=>selectLine(isActive ? null : line.lineId)
                                }, void 0, false, {
                                    fileName: "[project]/components/register/cart-panel.tsx",
                                    lineNumber: 78,
                                    columnNumber: 19
                                }, this),
                                isActive && /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(ActiveLineControls, {
                                    lineId: line.lineId,
                                    qty: line.qty,
                                    onInc: ()=>incQty(line.lineId),
                                    onDec: ()=>decQty(line.lineId),
                                    onRemove: ()=>removeLine(line.lineId)
                                }, void 0, false, {
                                    fileName: "[project]/components/register/cart-panel.tsx",
                                    lineNumber: 85,
                                    columnNumber: 21
                                }, this)
                            ]
                        }, line.lineId, true, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 77,
                            columnNumber: 17
                        }, this);
                    })
                }, void 0, false, {
                    fileName: "[project]/components/register/cart-panel.tsx",
                    lineNumber: 73,
                    columnNumber: 11
                }, this)
            }, void 0, false, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 69,
                columnNumber: 7
            }, this),
            lines.length > 0 && /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex justify-center py-2",
                children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                    variant: "ghost",
                    size: "icon-sm",
                    onClick: clear,
                    className: "text-muted-foreground hover:text-destructive",
                    "aria-label": "Vaciar carrito",
                    children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$trash$2d$2$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Trash2$3e$__["Trash2"], {
                        className: "size-4"
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 110,
                        columnNumber: 13
                    }, this)
                }, void 0, false, {
                    fileName: "[project]/components/register/cart-panel.tsx",
                    lineNumber: 103,
                    columnNumber: 11
                }, this)
            }, void 0, false, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 102,
                columnNumber: 9
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(CartBottom, {
                credito: credito,
                interno: interno,
                onToggleCredito: toggleCredito,
                onToggleInterno: toggleInterno,
                total: totalValue,
                lineCount: lines.length,
                config: config
            }, void 0, false, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 116,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/components/register/cart-panel.tsx",
        lineNumber: 61,
        columnNumber: 5
    }, this);
}
_s(CartPanel, "9O6ueqxfnAYeM1prAjYD/TwaV5s=", false, function() {
    return [
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCatalogStore"]
    ];
});
_c = CartPanel;
// ── Toolbar ───────────────────────────────────────────────────────────────────
function CartToolbar() {
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        className: "flex items-center justify-between border-b border-border px-2 py-1.5",
        children: [
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex items-center gap-0.5",
                children: [
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                        variant: "ghost",
                        size: "icon-sm",
                        "aria-label": "Ordenar",
                        title: "Ordenar líneas",
                        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$arrow$2d$up$2d$down$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__ArrowUpDown$3e$__["ArrowUpDown"], {
                            className: "size-4"
                        }, void 0, false, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 136,
                            columnNumber: 11
                        }, this)
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 135,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                        variant: "ghost",
                        size: "icon-sm",
                        "aria-label": "Buscar",
                        title: "Buscar en el carrito",
                        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$search$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Search$3e$__["Search"], {
                            className: "size-4"
                        }, void 0, false, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 139,
                            columnNumber: 11
                        }, this)
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 138,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                        variant: "ghost",
                        size: "icon-sm",
                        "aria-label": "Cliente",
                        title: "Seleccionar cliente",
                        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$user$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__User$3e$__["User"], {
                            className: "size-4"
                        }, void 0, false, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 142,
                            columnNumber: 11
                        }, this)
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 141,
                        columnNumber: 9
                    }, this)
                ]
            }, void 0, true, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 134,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                variant: "ghost",
                size: "icon-sm",
                "aria-label": "Más opciones",
                title: "Más opciones",
                children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$ellipsis$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__MoreHorizontal$3e$__["MoreHorizontal"], {
                    className: "size-4"
                }, void 0, false, {
                    fileName: "[project]/components/register/cart-panel.tsx",
                    lineNumber: 146,
                    columnNumber: 9
                }, this)
            }, void 0, false, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 145,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/components/register/cart-panel.tsx",
        lineNumber: 133,
        columnNumber: 5
    }, this);
}
_c1 = CartToolbar;
// ── Chip de cliente ───────────────────────────────────────────────────────────
function CustomerChip({ customer }) {
    _s1();
    const setCustomer = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"])({
        "CustomerChip.useCartStore[setCustomer]": (s)=>s.setCustomer
    }["CustomerChip.useCartStore[setCustomer]"]);
    if (!customer) return null;
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        className: "flex items-center gap-2 border-b border-border px-3 py-1.5",
        children: [
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex-1 min-w-0",
                children: [
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("p", {
                        className: "truncate text-xs font-medium text-foreground",
                        children: customer.name
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 166,
                        columnNumber: 9
                    }, this),
                    customer.tin && /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("p", {
                        className: "text-[10px] text-muted-foreground",
                        children: customer.tin
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 168,
                        columnNumber: 11
                    }, this)
                ]
            }, void 0, true, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 165,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                variant: "ghost",
                size: "icon-xs",
                onClick: ()=>setCustomer(null),
                "aria-label": "Quitar cliente",
                children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$x$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__X$3e$__["X"], {
                    className: "size-3"
                }, void 0, false, {
                    fileName: "[project]/components/register/cart-panel.tsx",
                    lineNumber: 177,
                    columnNumber: 9
                }, this)
            }, void 0, false, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 171,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/components/register/cart-panel.tsx",
        lineNumber: 164,
        columnNumber: 5
    }, this);
}
_s1(CustomerChip, "6+pZOlEizSXE1Vl+IHyMnefgDwc=", false, function() {
    return [
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$cart$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCartStore"]
    ];
});
_c2 = CustomerChip;
// ── Fila de línea del carrito ─────────────────────────────────────────────────
function CartLineRow({ line, isActive, config, onSelect }) {
    const subtotal = line.qty * line.unitPrice;
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("button", {
        onClick: onSelect,
        className: (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$utils$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["cn"])("flex w-full items-center gap-2 px-3 py-2 text-left transition-colors", isActive ? "bg-accent" : "hover:bg-muted/50"),
        children: [
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("span", {
                className: "flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground",
                children: line.qty
            }, void 0, false, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 209,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "min-w-0 flex-1",
                children: [
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("p", {
                        className: "truncate text-sm font-medium text-foreground",
                        children: line.name
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 215,
                        columnNumber: 9
                    }, this),
                    line.note && /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("p", {
                        className: "truncate text-[10px] text-muted-foreground",
                        children: line.note
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 217,
                        columnNumber: 11
                    }, this),
                    !line.note && /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("p", {
                        className: "truncate text-[10px] text-muted-foreground",
                        children: [
                            line.qty > 1 && `x${line.qty} @ `,
                            (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$format$2d$money$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["formatMoney"])(line.unitPrice, config)
                        ]
                    }, void 0, true, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 220,
                        columnNumber: 11
                    }, this)
                ]
            }, void 0, true, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 214,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("span", {
                className: "shrink-0 text-sm font-semibold tabular-nums text-foreground",
                children: (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$format$2d$money$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["formatMoney"])(subtotal, config)
            }, void 0, false, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 228,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/components/register/cart-panel.tsx",
        lineNumber: 199,
        columnNumber: 5
    }, this);
}
_c3 = CartLineRow;
// ── Controles de línea activa ─────────────────────────────────────────────────
function ActiveLineControls({ lineId, qty, onInc, onDec, onRemove }) {
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        className: "border-b border-border bg-accent/50 px-3 pb-2 pt-1",
        children: [
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "mb-2 flex items-center justify-center gap-3",
                children: [
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                        size: "icon-sm",
                        variant: "outline",
                        onClick: onInc,
                        "aria-label": "Aumentar cantidad",
                        className: "size-9 rounded-full",
                        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$plus$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Plus$3e$__["Plus"], {
                            className: "size-4"
                        }, void 0, false, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 261,
                            columnNumber: 11
                        }, this)
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 254,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("span", {
                        className: "min-w-[3ch] text-center text-lg font-bold tabular-nums text-foreground",
                        children: [
                            "x",
                            qty
                        ]
                    }, void 0, true, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 264,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                        size: "icon-sm",
                        variant: "outline",
                        onClick: onDec,
                        "aria-label": "Disminuir cantidad",
                        className: "size-9 rounded-full",
                        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$minus$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Minus$3e$__["Minus"], {
                            className: "size-4"
                        }, void 0, false, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 275,
                            columnNumber: 11
                        }, this)
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 268,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                        size: "icon-sm",
                        variant: "destructive",
                        onClick: onRemove,
                        "aria-label": "Eliminar línea",
                        className: "size-9 rounded-full",
                        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$x$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__X$3e$__["X"], {
                            className: "size-4"
                        }, void 0, false, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 285,
                            columnNumber: 11
                        }, this)
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 278,
                        columnNumber: 9
                    }, this)
                ]
            }, void 0, true, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 253,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex items-center justify-center gap-2",
                children: [
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                        variant: "ghost",
                        size: "icon-xs",
                        className: "rounded-full",
                        "aria-label": "Opciones de línea",
                        title: "Opciones",
                        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$chevron$2d$down$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__ChevronDown$3e$__["ChevronDown"], {
                            className: "size-3"
                        }, void 0, false, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 292,
                            columnNumber: 11
                        }, this)
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 291,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                        variant: "ghost",
                        size: "icon-xs",
                        className: "rounded-full",
                        "aria-label": "Precio manual",
                        title: "Precio",
                        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$dollar$2d$sign$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__DollarSign$3e$__["DollarSign"], {
                            className: "size-3"
                        }, void 0, false, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 295,
                            columnNumber: 11
                        }, this)
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 294,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                        variant: "ghost",
                        size: "icon-xs",
                        className: "rounded-full",
                        "aria-label": "Vendedor",
                        title: "Vendedor",
                        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$user$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__User$3e$__["User"], {
                            className: "size-3"
                        }, void 0, false, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 298,
                            columnNumber: 11
                        }, this)
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 297,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                        variant: "ghost",
                        size: "icon-xs",
                        className: "rounded-full",
                        "aria-label": "Tag",
                        title: "Tag",
                        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$tag$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__Tag$3e$__["Tag"], {
                            className: "size-3"
                        }, void 0, false, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 301,
                            columnNumber: 11
                        }, this)
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 300,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                        variant: "ghost",
                        size: "icon-xs",
                        className: "rounded-full",
                        "aria-label": "Comentario",
                        title: "Comentario",
                        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$lucide$2d$react$2f$dist$2f$esm$2f$icons$2f$message$2d$square$2e$mjs__$5b$app$2d$client$5d$__$28$ecmascript$29$__$3c$export__default__as__MessageSquare$3e$__["MessageSquare"], {
                            className: "size-3"
                        }, void 0, false, {
                            fileName: "[project]/components/register/cart-panel.tsx",
                            lineNumber: 304,
                            columnNumber: 11
                        }, this)
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 303,
                        columnNumber: 9
                    }, this)
                ]
            }, void 0, true, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 290,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/components/register/cart-panel.tsx",
        lineNumber: 251,
        columnNumber: 5
    }, this);
}
_c4 = ActiveLineControls;
// ── Carrito vacío ─────────────────────────────────────────────────────────────
function EmptyCart() {
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        className: "flex h-full flex-col items-center justify-center select-none",
        children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("span", {
            className: "text-7xl font-black tracking-tight text-muted-foreground/10",
            "aria-hidden": true,
            children: "Punto"
        }, void 0, false, {
            fileName: "[project]/components/register/cart-panel.tsx",
            lineNumber: 316,
            columnNumber: 7
        }, this)
    }, void 0, false, {
        fileName: "[project]/components/register/cart-panel.tsx",
        lineNumber: 315,
        columnNumber: 5
    }, this);
}
_c5 = EmptyCart;
// ── Bottom: toggles + cobrar ──────────────────────────────────────────────────
function CartBottom({ credito, interno, onToggleCredito, onToggleInterno, total, lineCount, config }) {
    const totalFormatted = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$format$2d$money$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["formatMoney"])(total, config);
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        className: "shrink-0 border-t border-border bg-background p-2 pt-2",
        children: [
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "mb-2 flex items-center gap-1.5",
                children: [
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(ToggleChip, {
                        label: "CRÉDITO",
                        active: credito,
                        onClick: onToggleCredito
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 351,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(ToggleChip, {
                        label: "INTERNO",
                        active: interno,
                        onClick: onToggleInterno
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 356,
                        columnNumber: 9
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                        className: "flex-1"
                    }, void 0, false, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 361,
                        columnNumber: 9
                    }, this),
                    lineCount > 0 && /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("span", {
                        className: "text-xs text-muted-foreground",
                        children: [
                            lineCount,
                            " ",
                            lineCount === 1 ? "ítem" : "ítems"
                        ]
                    }, void 0, true, {
                        fileName: "[project]/components/register/cart-panel.tsx",
                        lineNumber: 363,
                        columnNumber: 11
                    }, this)
                ]
            }, void 0, true, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 350,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$button$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["Button"], {
                disabled: lineCount === 0,
                className: (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$utils$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["cn"])("h-auto w-full rounded-xl px-4 py-3 text-base font-bold transition-all active:scale-[0.98]", lineCount === 0 ? "bg-muted text-muted-foreground hover:bg-muted" : "bg-[#01D7A1] text-[#060A0E] hover:bg-[#01D7A1]/90"),
                "aria-label": `Cobrar ${totalFormatted}`,
                children: lineCount === 0 ? "Sin items" : totalFormatted
            }, void 0, false, {
                fileName: "[project]/components/register/cart-panel.tsx",
                lineNumber: 370,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/components/register/cart-panel.tsx",
        lineNumber: 348,
        columnNumber: 5
    }, this);
}
_c6 = CartBottom;
// ── Toggle chip ───────────────────────────────────────────────────────────────
function ToggleChip({ label, active, onClick }) {
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("button", {
        onClick: onClick,
        className: (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$utils$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["cn"])("rounded-full border px-2.5 py-0.5 text-[10px] font-bold tracking-wide transition-colors", active ? "border-[#01D7A1] bg-[#01D7A1]/20 text-[#01D7A1]" : "border-border bg-transparent text-muted-foreground hover:border-muted-foreground"),
        children: label
    }, void 0, false, {
        fileName: "[project]/components/register/cart-panel.tsx",
        lineNumber: 398,
        columnNumber: 5
    }, this);
}
_c7 = ToggleChip;
var _c, _c1, _c2, _c3, _c4, _c5, _c6, _c7;
__turbopack_context__.k.register(_c, "CartPanel");
__turbopack_context__.k.register(_c1, "CartToolbar");
__turbopack_context__.k.register(_c2, "CustomerChip");
__turbopack_context__.k.register(_c3, "CartLineRow");
__turbopack_context__.k.register(_c4, "ActiveLineControls");
__turbopack_context__.k.register(_c5, "EmptyCart");
__turbopack_context__.k.register(_c6, "CartBottom");
__turbopack_context__.k.register(_c7, "ToggleChip");
if (typeof globalThis.$RefreshHelpers$ === 'object' && globalThis.$RefreshHelpers !== null) {
    __turbopack_context__.k.registerExports(__turbopack_context__.m, globalThis.$RefreshHelpers$);
}
}),
"[project]/hooks/use-catalog-seed.ts [app-client] (ecmascript)", ((__turbopack_context__) => {
"use strict";

__turbopack_context__.s([
    "useCatalogSeed",
    ()=>useCatalogSeed
]);
/**
 * Hook que hidrata el `useCatalogStore` con fixtures de desarrollo.
 *
 * Se usa en el register page durante Slice A (dev seed).
 * En producción, la hidratación viene del BFF `/api/pos/bootstrap`
 * via `usePosBootstrap` + `useCatalogStore().hydrate()`.
 *
 * Solo hidrata si el store está en estado "idle" (nunca sobrescribe
 * datos reales del BFF).
 */ var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$index$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/dist/compiled/react/index.js [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/catalog/store.ts [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$fixtures$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/catalog/fixtures.ts [app-client] (ecmascript)");
var _s = __turbopack_context__.k.signature();
"use client";
;
;
;
function useCatalogSeed() {
    _s();
    const status = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCatalogStore"])({
        "useCatalogSeed.useCatalogStore[status]": (s)=>s.status
    }["useCatalogSeed.useCatalogStore[status]"]);
    const hydrate = (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCatalogStore"])({
        "useCatalogSeed.useCatalogStore[hydrate]": (s)=>s.hydrate
    }["useCatalogSeed.useCatalogStore[hydrate]"]);
    __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$index$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useEffect"]({
        "useCatalogSeed.useEffect": ()=>{
            if (status === "idle") {
                hydrate({
                    items: __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$fixtures$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["fixtureBootstrap"].items,
                    customers: __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$fixtures$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["fixtureBootstrap"].customers,
                    config: __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$fixtures$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["fixtureBootstrap"].config,
                    registers: __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$fixtures$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["fixtureBootstrap"].registers
                });
            }
        }
    }["useCatalogSeed.useEffect"], [
        status,
        hydrate
    ]);
}
_s(useCatalogSeed, "jd57UqMzQL4eR5bfwTj0HRZJGPs=", false, function() {
    return [
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCatalogStore"],
        __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$catalog$2f$store$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCatalogStore"]
    ];
});
if (typeof globalThis.$RefreshHelpers$ === 'object' && globalThis.$RefreshHelpers !== null) {
    __turbopack_context__.k.registerExports(__turbopack_context__.m, globalThis.$RefreshHelpers$);
}
}),
"[project]/app/(pos)/register/page.tsx [app-client] (ecmascript)", ((__turbopack_context__) => {
"use strict";

__turbopack_context__.s([
    "default",
    ()=>RegisterPage
]);
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/dist/compiled/react/jsx-dev-runtime.js [app-client] (ecmascript)");
/**
 * Pantalla de caja principal — Slice A1.
 *
 * Layout 2 columnas full-screen (fidelidad §6.1):
 *   ┌─────────────────────────────────┬──────────────────────┐
 *   │  ProductArea (~70%)             │  CartPanel (~30%)    │
 *   │  - Grid de tiles (cat/producto) │  - Toolbar iconos    │
 *   │  - CategoryBar inferior         │  - Lista de líneas   │
 *   │  - FAB "+" + info de sesión     │  - Controles activos │
 *   │                                 │  - Total verde       │
 *   └─────────────────────────────────┴──────────────────────┘
 *
 * Datos: fixture seed (dev) — hidratados en `useCatalogSeed()`.
 * En producción el store se hidrata desde `/api/pos/bootstrap`.
 *
 * Ver context/16-app-next-rewrite.md §6.1 y §7 Slice A.
 */ var __TURBOPACK__imported__module__$5b$project$5d2f$components$2f$register$2f$product$2d$area$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/components/register/product-area.tsx [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$components$2f$register$2f$cart$2d$panel$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/components/register/cart-panel.tsx [app-client] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$hooks$2f$use$2d$catalog$2d$seed$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/hooks/use-catalog-seed.ts [app-client] (ecmascript)");
;
var _s = __turbopack_context__.k.signature();
"use client";
;
;
;
function RegisterPage() {
    _s();
    // Hidrata el catálogo con fixtures (dev seed).
    // TODO (Slice A backend): reemplazar con hydrate() desde usePosBootstrap().
    (0, __TURBOPACK__imported__module__$5b$project$5d2f$hooks$2f$use$2d$catalog$2d$seed$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCatalogSeed"])();
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        className: "flex h-full w-full overflow-hidden",
        children: [
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex-[7] overflow-hidden",
                children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$register$2f$product$2d$area$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["ProductArea"], {}, void 0, false, {
                    fileName: "[project]/app/(pos)/register/page.tsx",
                    lineNumber: 34,
                    columnNumber: 9
                }, this)
            }, void 0, false, {
                fileName: "[project]/app/(pos)/register/page.tsx",
                lineNumber: 33,
                columnNumber: 7
            }, this),
            /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex-[3] overflow-hidden",
                children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$compiled$2f$react$2f$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$client$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$register$2f$cart$2d$panel$2e$tsx__$5b$app$2d$client$5d$__$28$ecmascript$29$__["CartPanel"], {}, void 0, false, {
                    fileName: "[project]/app/(pos)/register/page.tsx",
                    lineNumber: 39,
                    columnNumber: 9
                }, this)
            }, void 0, false, {
                fileName: "[project]/app/(pos)/register/page.tsx",
                lineNumber: 38,
                columnNumber: 7
            }, this)
        ]
    }, void 0, true, {
        fileName: "[project]/app/(pos)/register/page.tsx",
        lineNumber: 31,
        columnNumber: 5
    }, this);
}
_s(RegisterPage, "WkvvGR+e5A859lYEP363k+snPfU=", false, function() {
    return [
        __TURBOPACK__imported__module__$5b$project$5d2f$hooks$2f$use$2d$catalog$2d$seed$2e$ts__$5b$app$2d$client$5d$__$28$ecmascript$29$__["useCatalogSeed"]
    ];
});
_c = RegisterPage;
var _c;
__turbopack_context__.k.register(_c, "RegisterPage");
if (typeof globalThis.$RefreshHelpers$ === 'object' && globalThis.$RefreshHelpers !== null) {
    __turbopack_context__.k.registerExports(__turbopack_context__.m, globalThis.$RefreshHelpers$);
}
}),
]);

//# sourceMappingURL=_0ggylc0._.js.map