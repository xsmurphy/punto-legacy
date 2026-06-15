module.exports = [
"[externals]/next/dist/compiled/next-server/app-route-turbo.runtime.dev.js [external] (next/dist/compiled/next-server/app-route-turbo.runtime.dev.js, cjs)", ((__turbopack_context__, module, exports) => {

const mod = __turbopack_context__.x("next/dist/compiled/next-server/app-route-turbo.runtime.dev.js", () => require("next/dist/compiled/next-server/app-route-turbo.runtime.dev.js"));

module.exports = mod;
}),
"[externals]/next/dist/compiled/@opentelemetry/api [external] (next/dist/compiled/@opentelemetry/api, cjs)", ((__turbopack_context__, module, exports) => {

const mod = __turbopack_context__.x("next/dist/compiled/@opentelemetry/api", () => require("next/dist/compiled/@opentelemetry/api"));

module.exports = mod;
}),
"[externals]/next/dist/compiled/next-server/app-page-turbo.runtime.dev.js [external] (next/dist/compiled/next-server/app-page-turbo.runtime.dev.js, cjs)", ((__turbopack_context__, module, exports) => {

const mod = __turbopack_context__.x("next/dist/compiled/next-server/app-page-turbo.runtime.dev.js", () => require("next/dist/compiled/next-server/app-page-turbo.runtime.dev.js"));

module.exports = mod;
}),
"[externals]/next/dist/server/app-render/work-unit-async-storage.external.js [external] (next/dist/server/app-render/work-unit-async-storage.external.js, cjs)", ((__turbopack_context__, module, exports) => {

const mod = __turbopack_context__.x("next/dist/server/app-render/work-unit-async-storage.external.js", () => require("next/dist/server/app-render/work-unit-async-storage.external.js"));

module.exports = mod;
}),
"[externals]/next/dist/server/app-render/work-async-storage.external.js [external] (next/dist/server/app-render/work-async-storage.external.js, cjs)", ((__turbopack_context__, module, exports) => {

const mod = __turbopack_context__.x("next/dist/server/app-render/work-async-storage.external.js", () => require("next/dist/server/app-render/work-async-storage.external.js"));

module.exports = mod;
}),
"[externals]/next/dist/shared/lib/no-fallback-error.external.js [external] (next/dist/shared/lib/no-fallback-error.external.js, cjs)", ((__turbopack_context__, module, exports) => {

const mod = __turbopack_context__.x("next/dist/shared/lib/no-fallback-error.external.js", () => require("next/dist/shared/lib/no-fallback-error.external.js"));

module.exports = mod;
}),
"[externals]/next/dist/server/app-render/after-task-async-storage.external.js [external] (next/dist/server/app-render/after-task-async-storage.external.js, cjs)", ((__turbopack_context__, module, exports) => {

const mod = __turbopack_context__.x("next/dist/server/app-render/after-task-async-storage.external.js", () => require("next/dist/server/app-render/after-task-async-storage.external.js"));

module.exports = mod;
}),
"[project]/app/api/pos/bootstrap/route.ts [app-route] (ecmascript)", ((__turbopack_context__) => {
"use strict";

__turbopack_context__.s([
    "GET",
    ()=>GET,
    "dynamic",
    ()=>dynamic,
    "runtime",
    ()=>runtime
]);
/**
 * BFF — POS Bootstrap (stub / Sprint 0).
 *
 * Este endpoint es el punto de entrada del catálogo del POS. En Slice A,
 * este stub se reemplaza con la lógica real que compone en UNA sola
 * respuesta todo lo que el store de catálogo necesita para hidratar en memoria:
 *   - items vendibles (con precio, categoría, imagen)
 *   - clientes (id, nombre, teléfono)
 *   - config del tenant (moneda, decimales, separadores, impuestos)
 *   - cajas disponibles (registers) del outlet activo
 *   - outlets del tenant
 *   - usuarios del outlet (para atribución)
 *
 * Hoy devuelve un shape tipado vacío / placeholder para que el store
 * pueda wiring-arse sin fetch real.
 *
 * Fuentes upstream (cuando se implemente):
 *   GET /v1/bootstrap       → config + user + outlets
 *   GET /v1/items?pos=1     → items vendibles (itemCanSale=1, itemStatus=1)
 *   GET /v1/contacts        → clientes del tenant
 *   GET /v1/registers       → cajas del outlet activo
 *
 * Ver context/16-app-next-rewrite.md §4 (arquitectura BFF) y §7 Slice A.
 */ var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$server$2e$js__$5b$app$2d$route$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/server.js [app-route] (ecmascript)");
;
const runtime = "nodejs";
const dynamic = "force-dynamic";
async function GET() {
    // TODO (Slice A): leer cookie _jwt, llamar a las fuentes upstream en paralelo,
    // componer y cachear en Redis / revalidar con stale-while-revalidate.
    const stub = {
        config: {
            currency: "",
            decimal: "yes",
            thousand: "dot",
            taxName: "",
            tinName: "",
            country: "",
            companyName: "",
            companyId: "",
            publicUrl: ""
        },
        user: {
            id: "",
            role: 0
        },
        outlet: {
            id: "",
            name: ""
        },
        registers: [],
        items: [],
        customers: []
    };
    return __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$server$2e$js__$5b$app$2d$route$5d$__$28$ecmascript$29$__["NextResponse"].json(stub);
}
}),
];

//# sourceMappingURL=%5Broot-of-the-server%5D__0ldgdbd._.js.map