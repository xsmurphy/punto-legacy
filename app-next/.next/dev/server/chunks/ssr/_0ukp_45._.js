module.exports = [
"[project]/lib/api-client.ts [app-ssr] (ecmascript)", ((__turbopack_context__) => {
"use strict";

/**
 * Cliente HTTP del POS (app-next).
 *
 * Hace requests SIEMPRE al BFF del propio Next app
 * (Route Handler en `app/api/v1/[...path]/route.ts`), que reenvía a la
 * `/api` compartida. Patrón: Front → BFF → API → BD.
 *
 * El front llama por path RELATIVO (`/v1/contacts`) → el `baseUrl()` devuelve
 * `/api` → `/api/v1/contacts` → matchea el catch-all del BFF. Same-origin,
 * sin CORS, cookie `_jwt` viaja sola (realm pos-app).
 *
 * NUNCA apuntar al API_URL directo desde el browser. El BFF es el único
 * punto de salida al backend PHP.
 */ __turbopack_context__.s([
    "ApiError",
    ()=>ApiError,
    "api",
    ()=>api
]);
class ApiError extends Error {
    status;
    payload;
    constructor(status, payload, message){
        super(message), this.status = status, this.payload = payload;
        this.name = "ApiError";
    }
}
/**
 * Browser: BFF same-origin (`/api`).
 * Server (SSR/Route Handler): API_URL directo para no hacer loop por el BFF.
 */ const baseUrl = ()=>{
    if ("TURBOPACK compile-time truthy", 1) {
        const url = process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL;
        if (!url) {
            throw new Error("API base URL missing (server). Set API_URL.");
        }
        return url.replace(/\/$/, "");
    }
    //TURBOPACK unreachable
    ;
};
async function request(path, init = {}) {
    const { jwt, headers, ...rest } = init;
    const hasBody = "body" in rest && rest.body !== undefined && rest.body !== null;
    const isMultipart = hasBody && typeof FormData !== "undefined" && rest.body instanceof FormData;
    const baseHeaders = {
        Accept: "application/json"
    };
    if (hasBody && !isMultipart) {
        baseHeaders["Content-Type"] = "application/json";
    }
    if (jwt) {
        baseHeaders.Authorization = `Bearer ${jwt}`;
    }
    const res = await fetch(`${baseUrl()}${path}`, {
        ...rest,
        credentials: "include",
        headers: {
            ...baseHeaders,
            ...headers
        }
    });
    const text = await res.text();
    const payload = text ? safeJson(text) : null;
    if (!res.ok) {
        const envelope = payload;
        const backendMsg = envelope?.error?.message;
        throw new ApiError(res.status, payload, backendMsg ?? `${rest.method ?? "GET"} ${path} → ${res.status}`);
    }
    // Unwrap el envelope canónico { ok: true, data: ... } → data.
    const envelope = payload;
    if (envelope && typeof envelope === "object" && envelope.ok === true && "data" in envelope) {
        return envelope.data;
    }
    return payload;
}
function safeJson(text) {
    try {
        return JSON.parse(text);
    } catch  {
        return text;
    }
}
const api = {
    get: (path, opts)=>request(path, {
            method: "GET",
            ...opts
        }),
    post: (path, body, opts)=>request(path, {
            method: "POST",
            body: body ? JSON.stringify(body) : undefined,
            ...opts
        }),
    postForm: (path, form, opts)=>request(path, {
            method: "POST",
            body: form,
            ...opts
        }),
    put: (path, body, opts)=>request(path, {
            method: "PUT",
            body: body ? JSON.stringify(body) : undefined,
            ...opts
        }),
    del: (path, opts)=>request(path, {
            method: "DELETE",
            ...opts
        }),
    /** URL absoluta para descargas directas. */ url: (path)=>`${baseUrl()}${path}`
};
}),
"[project]/hooks/use-pos-bootstrap.ts [app-ssr] (ecmascript)", ((__turbopack_context__) => {
"use strict";

__turbopack_context__.s([
    "usePosBootstrap",
    ()=>usePosBootstrap
]);
/**
 * Hook del bootstrap del POS.
 *
 * Consulta el BFF `/api/pos/bootstrap` (NOT la /api PHP directa).
 * El BFF compone items + clientes + config + cajas en una sola respuesta.
 *
 * Auth: requiere cookie `_jwt` válida (realm `pos-app`). Si responde 401,
 * el `PosAuthGuard` redirige a /login.
 *
 * TODO (Slice A): el stub actual devuelve arrays vacíos. Al conectar el BFF
 * real, este hook dispara la hidratación del `useCatalogStore`.
 */ var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f40$tanstack$2f$react$2d$query$2f$build$2f$modern$2f$useQuery$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/@tanstack/react-query/build/modern/useQuery.js [app-ssr] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$api$2d$client$2e$ts__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/api-client.ts [app-ssr] (ecmascript)");
"use client";
;
;
function usePosBootstrap() {
    return (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f40$tanstack$2f$react$2d$query$2f$build$2f$modern$2f$useQuery$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["useQuery"])({
        queryKey: [
            "pos-bootstrap"
        ],
        // Apunta al BFF POS (/api/pos/bootstrap), NO a /v1/bootstrap del panel.
        queryFn: ()=>__TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$api$2d$client$2e$ts__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["api"].get("/pos/bootstrap"),
        staleTime: 5 * 60 * 1000,
        retry: false
    });
}
}),
"[project]/components/ui/skeleton.tsx [app-ssr] (ecmascript)", ((__turbopack_context__) => {
"use strict";

__turbopack_context__.s([
    "Skeleton",
    ()=>Skeleton
]);
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/dist/server/route-modules/app-page/vendored/ssr/react-jsx-dev-runtime.js [app-ssr] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$utils$2e$ts__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/utils.ts [app-ssr] (ecmascript)");
;
;
function Skeleton({ className, ...props }) {
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
        "data-slot": "skeleton",
        className: (0, __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$utils$2e$ts__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["cn"])("animate-pulse rounded-2xl bg-muted", className),
        ...props
    }, void 0, false, {
        fileName: "[project]/components/ui/skeleton.tsx",
        lineNumber: 5,
        columnNumber: 5
    }, this);
}
;
}),
"[project]/components/layout/pos-auth-guard.tsx [app-ssr] (ecmascript)", ((__turbopack_context__) => {
"use strict";

__turbopack_context__.s([
    "PosAuthGuard",
    ()=>PosAuthGuard
]);
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/dist/server/route-modules/app-page/vendored/ssr/react-jsx-dev-runtime.js [app-ssr] (ecmascript)");
/**
 * Guard de autenticación del POS.
 *
 * Verifica que exista una sesión válida (`_jwt` cookie, realm `pos-app`)
 * consultando el BFF bootstrap. Si no hay sesión (401), redirige a
 * la pantalla de login del POS.
 *
 * Diferencias vs PanelAuthGuard (panel-next):
 *   - Cookie: `_jwt` (realm pos-app), NO `_jwt_panel`.
 *   - Layout: full-screen (sin sidebar).
 *   - Estado extra: sesión de caja (registerId, outletId) desde bootstrap.
 *
 * TODO (Sprint 0 → Slice A):
 *   - Conectar `useBootstrap()` al fetch real de `/api/pos/bootstrap`.
 *   - Implementar selector de caja si `registers.length > 1`.
 *   - Manejar handoff JWT desde `panel-next` (SSO: el cajero se loguea en
 *     panel y el POS hereda la sesión via cookie `.punto.la`).
 *
 * Ver context/16-app-next-rewrite.md §7 Sprint 0 y §3 (Auth: cookie _jwt).
 */ var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/dist/server/route-modules/app-page/vendored/ssr/react.js [app-ssr] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$navigation$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/node_modules/next/navigation.js [app-ssr] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$api$2d$client$2e$ts__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/lib/api-client.ts [app-ssr] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$hooks$2f$use$2d$pos$2d$bootstrap$2e$ts__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/hooks/use-pos-bootstrap.ts [app-ssr] (ecmascript)");
var __TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$skeleton$2e$tsx__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__ = __turbopack_context__.i("[project]/components/ui/skeleton.tsx [app-ssr] (ecmascript)");
"use client";
;
;
;
;
;
;
function PosAuthGuard({ children }) {
    const router = (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$navigation$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["useRouter"])();
    const { data: bootstrap, isLoading, error } = (0, __TURBOPACK__imported__module__$5b$project$5d2f$hooks$2f$use$2d$pos$2d$bootstrap$2e$ts__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["usePosBootstrap"])();
    __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["useEffect"](()=>{
        if (error instanceof __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$api$2d$client$2e$ts__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["ApiError"] && error.status === 401) {
            // TODO (Slice A): implementar página de login del POS.
            // Por ahora redirige al login del panel-next mientras no existe el login propio.
            router.replace("/login");
        }
    }, [
        error,
        router
    ]);
    if (isLoading) {
        return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
            className: "flex h-screen items-center justify-center bg-background",
            children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "flex flex-col items-center gap-4",
                children: [
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$skeleton$2e$tsx__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["Skeleton"], {
                        className: "h-8 w-48"
                    }, void 0, false, {
                        fileName: "[project]/components/layout/pos-auth-guard.tsx",
                        lineNumber: 46,
                        columnNumber: 11
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$components$2f$ui$2f$skeleton$2e$tsx__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["Skeleton"], {
                        className: "h-4 w-32"
                    }, void 0, false, {
                        fileName: "[project]/components/layout/pos-auth-guard.tsx",
                        lineNumber: 47,
                        columnNumber: 11
                    }, this)
                ]
            }, void 0, true, {
                fileName: "[project]/components/layout/pos-auth-guard.tsx",
                lineNumber: 45,
                columnNumber: 9
            }, this)
        }, void 0, false, {
            fileName: "[project]/components/layout/pos-auth-guard.tsx",
            lineNumber: 44,
            columnNumber: 7
        }, this);
    }
    // Si hay error no-401 (502, etc.), mostrar pantalla de error mínima.
    if (error && !(error instanceof __TURBOPACK__imported__module__$5b$project$5d2f$lib$2f$api$2d$client$2e$ts__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["ApiError"] && error.status === 401)) {
        return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
            className: "flex h-screen items-center justify-center bg-background",
            children: /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["jsxDEV"])("div", {
                className: "text-center",
                children: [
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["jsxDEV"])("p", {
                        className: "text-sm text-muted-foreground",
                        children: "No se pudo conectar al servidor."
                    }, void 0, false, {
                        fileName: "[project]/components/layout/pos-auth-guard.tsx",
                        lineNumber: 58,
                        columnNumber: 11
                    }, this),
                    /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["jsxDEV"])("p", {
                        className: "mt-1 text-xs text-muted-foreground",
                        children: error instanceof Error ? error.message : "Error desconocido"
                    }, void 0, false, {
                        fileName: "[project]/components/layout/pos-auth-guard.tsx",
                        lineNumber: 61,
                        columnNumber: 11
                    }, this)
                ]
            }, void 0, true, {
                fileName: "[project]/components/layout/pos-auth-guard.tsx",
                lineNumber: 57,
                columnNumber: 9
            }, this)
        }, void 0, false, {
            fileName: "[project]/components/layout/pos-auth-guard.tsx",
            lineNumber: 56,
            columnNumber: 7
        }, this);
    }
    // Sesión válida o bootstrap stub (Sprint 0 — el stub no lanza 401).
    // TODO (Slice A): validar que bootstrap.outlet.id y registers.length > 0.
    void bootstrap;
    return /*#__PURE__*/ (0, __TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["jsxDEV"])(__TURBOPACK__imported__module__$5b$project$5d2f$node_modules$2f$next$2f$dist$2f$server$2f$route$2d$modules$2f$app$2d$page$2f$vendored$2f$ssr$2f$react$2d$jsx$2d$dev$2d$runtime$2e$js__$5b$app$2d$ssr$5d$__$28$ecmascript$29$__["Fragment"], {
        children: children
    }, void 0, false);
}
}),
];

//# sourceMappingURL=_0ukp_45._.js.map