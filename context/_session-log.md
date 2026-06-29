<!-- REGLA: Agregar entry al cierre de cada sesión de trabajo. Formato: más reciente arriba.
     Cap blando: 200 líneas. Al superar, mover las más antiguas a _session-log-archive-YYYY-MM.md -->

# Bitácora de Sesiones

## 2026-06-29 — cleanup refactor flattenJsonb + phone storage sin "+" + reconnect device

Commits `5f745f43..91d09de9` (22). Highlights: cleanup refactor 28-jun en 10+ services (alias quoted + wrap CIA); convención phone storage SIN `+` end-to-end (mig 67 cleanup); feature reconnect device auto-aprobado (mig 68); window.print() global eliminado; `/v1/price_list` GET multi-realm; hotfix BD: phone Dueño + reset pass + outlet asignado + 4 GB swap server. Pendiente: drawer getIncome/getPaymentBreakdown (latente), crossing deviceId reconnect, TenantContext outletId opcional.

## 2026-06-28 — auth POS hotfix + wrappers BFF/DB + pantalla cliente al device flow + multi-outlet por usuario

Commits `e3915d80..8a804e87` (70). Highlights: fix `apiAuthTenant` realm pos-app + Bearer en pos-fetch + doble-prefix `/api/api/` (502/401 prod); merge `bff-proxy-unified` (-487 LOC, 7 BFF routes con `bffProxy()`); arq DB wrapper (`RecordsetIterator.fields` siempre array + `flattenJsonb` plano — breaking: SELECTs con alias quoted); mig 64 DROP `customer_display` (pantalla cliente = `device WHERE module='screen'`, namespace token por module); mig 66 `contact_outlet` M2M usuarios↔sucursales. Pendiente: smoke moduleLogout 60s, pay-dialog central → MoneyInput, drop `contact.outletid` legacy, Fase 3 device flow (KDS).

## 2026-06-27 — Sistema de impresión WebUSB + device authorization flow (invitation-based) + Bearer auth POS + moduleLogout centralizado

Commits `94fb4c7..e3915d80` (45). Highlights: (1) **WebUSB + ESC/POS** — PrinterBinding en BD (mig 59) por registerId, transport multi-modal (USB/BT/Network/window.print), renderer desde plantilla del panel, auto-print en POS, PrintersManager en AjustesPanel; (2) **Device Authorization Grant** — invitation-based flow reemplaza `/pos-pair`: admin genera UUID con outlet+caja+TTL, cajero abre link, admin aprueba 1 click; UI `/connect/[id]` + `/settings/devices`; migs 62 (`device_invitation`) + 63 (`device.module`); pairing idempotente por `browserLocalId` (mig 60, índice único parcial); (3) **Bearer auth POS** — device migra de cookie HttpOnly `_jwt` → `Authorization: Bearer` + `localStorage['punto.device.token']`; razón: cookies zombie no se pueden limpiar desde JS; `_jwt_panel` admin intacta; devices existentes deben re-pair al deploy; (4) **moduleLogout centralizado** — `lib/auth/module-logout.ts` + singleton queryClient: 401 en cualquier endpoint POS → clearDeviceToken + reset Zustand stores (catalog/cart/hotkeys/lock) + queryClient.clear(); `PosAuthGuard` refetchInterval 60s condicional (evita 401 loop post-logout); auto-cleanup remoto en ≤60s tras revocación. Pendiente: smoke test revocar→esperar 60s→verificar cleanup; Fases 3+ device flow (KDS, Display).

## 2026-06-25 — Día de cierres mayores: dual-session JWT POS + roles/permissions + PWA + offline-sync + rediseño detalle transacción + fix deploy fantasma

Commits `339b193..94fb4c7` (113). Highlights: (1) **JWT dual-session POS** — cookie `_jwt_panel` 24h + `_jwt_pos-device` 10 años, tabla `device` legacy reusada, `DeviceAuth.php`, `PosAuthGuard`, `/pos-pair` page, PIN SHA-256 Web Crypto; (2) **Roles+permisos** — `PermissionCatalog`, `RoleService` CRUD+cache, 3 seed roles (Dueño/Encargado/Cajero), `hasPermission()` global, UI `/settings/roles`, `user.permissions[]` en bootstrap; (3) **PWA** — manifest + Serwist service worker + install prompt; (4) **Offline-sync** — lease de numeración + queue IndexedDB + OfflineBanner + SyncQueueDialog; (5) **Rediseño detalle transacción POS** (5 iteraciones) — cliente HERO, split button CTA 3-puntos, items fondo gris, pagos por tipo, preview cotización hoja blanca; (6) **Fix deploy fantasma** (commit `14d5347`) — migración 58 `contact.role` smallint→varchar bloqueada por índice parcial con predicado `role=ANY(int[])` → container crasheaba → Coolify rollback silencioso → 3h de fixes ocultos; (7) **Design system canónico** `context/20-design-system.md` (648L+). Pendiente: Facturar cotización E2E, drop `contact.lockPass`, T4 Anular+Devolución.

## 2026-06-24 — POS sprint: grupos, cotizaciones, listado transacciones, page-context agente + cluster de bugs prod

Commits `902de84..59476e2` (~30). Highlights: refactor `/outlets/[id]` en 5 tabs (08ec213); drag&drop chat + thumbnail guard (308fafa, dfe4850); agente Nivel 1+2 page-context con `useAgentPageSnapshot` + Zustand store (5c86490, 36bee0a); `#5` grupos de catálogo abren `GroupItemsDialog` en POS — padres ya no se agregan al carrito (2ad615c); `#15` conversión de monedas informativa debajo del total en pay dialog (a1ce4fd); `#27` Guardar como Cotización (`SaleService::saveQuote`, type=9, sin stock/caja/pago, prefix PRES) + `QuotePrintViewDialog` (1a93cd6); T1+T2 modal listado de transacciones POS con paginación, Duplicar, Reimprimir, Ver PDF (c44af6e, 45f90dd); restyle modal al design system §14 (c978d47). Convenciones UI §14 nuevas — `context/14-ui-conventions.md` + regla crítica en CLAUDE.md (879d7e7, f8c01e3). Cluster de bugs P0 prod fixeados del sprint retail: `parked-sales.php` signature ncmExecute extra arg (f4cec88); mig48 `item` quoted vs lowercase bloqueaba deploy (85e8a86); `DrawerService::findOpenRow` CaseInsensitiveArray cast (5c1895a); 3 services retail con `itemtrackinventory >= 1` contra col BOOLEAN + JOIN a tabla `user` inexistente (5044ecf); 4 `SelectItem value=""` crasheando Radix — sentinel `__none__` (ba28cba); `transactionComplete = 0/1` vs BOOLEAN en 7 UPDATEs (df66e37); `VariantService INSERT` int vs bool (df66e37); React #300 hooks después de early return en `/contacts/[id]` (39dadbb).

## 2026-06-23 (tarde) — AI-8 + AI-9: Chat Attachments + Tabular Import

Implementadas dos features del agente IA: attachments infra (AI-8) y tabular import vía chat (AI-9). Se crearon ImportSession.php (Redis-backed, TTL 3600), ContactImporter.php (nuevo importer CSV para contactos), dos endpoints PHP (/v1/imports/upload y run), y se extendió el flujo confirm/execute con la acción `tabular_import`. En el front: attachment-types.ts, parse-tabular.ts (XLSX→CSV via SheetJS), upload-attachment.ts (usa api.postForm BFF same-origin), chips UI en AgentInputBox, state management en useAgentChat, message enrichment en AgentChatContent, y sección de instrucciones en el system prompt del route.ts. Pendiente para AI-10: imágenes (análisis visual), AI-11: PDFs/docs.

## 2026-06-23 — Sprint retail completo: módulos inventario/devoluciones + variantes + realtime fix + AuthSentinel + POS UX

Commits `f8d782e..902de84` (~110). 10 fases en una jornada: módulos retail nuevos (Conteo Inventario mig46, Ajustes Stock, Transferencias mig47, Devoluciones POS, CRUD Cajas, Depósitos en outlet); Variantes Phase 1 (mig48: `variantParentId`/`hasVariants`/`variantAttributes`, matriz cartesiana en UI); Phase B transacciones POS (CreditPaymentService, QuotePrintView, tabs docs asociados); AuthSentinel global 401 vía `api:unauthorized` CustomEvent; fix crítico Redis AUTH en `wsPublish` (prod tenía realtime mudo); fix PG column casing en 7 services legacy (camelCase quoted → lowercase sin quotes); realtime `refetchType:"active"` + 11 entities nuevas en `realtimeAfterMutation`; sidebar consolidado (Artículos colapsable, Contactos NavGroup, dropdown lateral en modo colapsado, logo+chevron unified trigger); polish POS (NumericPad as-you-type, cart extras con X, íconos Opciones pintados, toast top-center); agente IA: AI-7 WS invalidations + fix `get_transactions` endpoint.

## 2026-06-21 — Mega sprint: catálogo m2m + realtime + checkout screen + reports rollup + agente IA

Commits `793613c..645f9cb` (44). 5 ejes mayores en 3 días (context/14–18): migs 37–43 (tags/item_tag, customer_display, report_rollup+rollup_dirty, item_sales/payments rollup, ai_model_config); realtime sync panel↔POS vía WS singleton + `useRealtimeSync`; checkout screen completo (pairing → live → confirmed → idle, Redis vía fsockopen+RESP); rollup pre-agregado gateado por `REPORTS_ROLLUP_ENABLED` (RB-1+RB-2, cutover 5 reportes); agente IA AI-1..AI-3b (OpenRouter+DeepSeek, 13 tools con confirmToken, historial Zustand persist con `onFinishHydration`, UI ChatGPT-style + mobile Sheet). Hitos infra: dominio migrado a `app.punto.la`; 4 memorias nuevas (jwt_two_tokens, ai_agent_openrouter, ai_agent_scope, reports_rollup). Pendientes: smoke tests prod, calibración pricing agente, RB-3, AI-4/AI-5, UI cajas en /settings/devices.

## 2026-06-19 — Auditoría tenant, edición de venta completa, cierre de caja panel y mejoras UX

Commits `50eca3d..793613c` (7). Highlights: bug nombre empresa en /settings (3 capas); Team movido a tab en /contacts + redirect /settings/team; import items acepta .xlsx (SheetJS) + columna ETIQUETAS autocrea tags; archivar-antes-de-eliminar en items (hard-delete solo archivados sin ventas); modal detalle de caja + cerrar desde panel; detalle de venta con edición paridad completa (header+ítems+métodos de pago, gate editabilidad, docs asociados, tabs Pagos/Cotizaciones); módulo de auditoría tenant (tabla `tenant_audit`, instrumentación en `apiAuthTenant`, endpoint + UI, retención 2 meses vía pg_cron); mig 36 pg_cron fail-tolerant.

### Archivos para retomar por área

**Auditoría tenant**
- `api/bootstrap.php` — `apiAuthTenant()` + `tenantAudit()` (choke point de instrumentación)
- `api/v1/reports/audit.php` — endpoint GET con filtros
- `database/migrations/postgres/35_tenant_audit.sql` + `36_tenant_audit_pgcron.sql`
- `frontend/app/(panel)/reports/audit/page.tsx` — UI listado de auditoría

**Edición/detalle de venta**
- `api/v1/reports/transactions.php` — GET (detalle + items + métodos de pago) y PUT (edición, gate de editabilidad en backend)
- `frontend/components/domain/transactions/transactions-list.tsx` — DataTable + modal de detalle/edición

**Cierre de caja**
- `api/lib/Reports/DrawersService.php` — `close()` con guard `drawerCloseDate IS NULL`
- `frontend/components/reports/drawer-detail-modal.tsx` — modal de detalle + botón cerrar caja

**Import de items / archivar-eliminar**
- `api/lib/Items/ItemImporter.php` — importador CSV/XLSX + autocreación de tags
- `frontend/components/items/import-dialog.tsx` — SheetJS → CSV → POST
- `api/lib/Items/ItemRepository.php` — `hardDelete()` con guard ventas
- `api/v1/items.php` — DELETE handler (activos→Archivar, archivados→hard-delete)

**Migraciones auto**
- `database/migrate.php` — runner, trackea `schema_migrations`, fail-fast
- `docker-entrypoint.sh` — invoca migrate.php en cada deploy antes de arrancar PHP

**Pendiente crítico**: pg_cron requiere habilitar `shared_preload_libraries='pg_cron'` + restart en el Postgres managed de Coolify antes de que la mig 36 pueda programar la purga. Si la 36 ya corrió en no-op, se necesita una mig nueva con el bloque de scheduling.

---

## 2026-06-17 — MVP POS slices + X-ray cliente + mapa MapLibre
Commits `ce454a6..50eca3d` (39). Highlights: Slice 1 arqueo de caja (DrawerService + migs 33/34 race-condition-safe); Slice 3 gaps POS (barcode scanner, edición precio/descuento por línea, transacciones con duplicar/reimprimir); modal X-ray del cliente con `<ContactDetailView>` variant POS; pay dialog dinámico (métodos del bootstrap, grilla kbd, auto-confirm); bug fix coordenadas + mapa MapLibre/OpenFreeMap; autorrellenar precio con último precio de compra; fix `CaseInsensitiveArray` en `ContactAnalyticsService`.

## 2026-06-16 (tarde) — fixes frontend + cleanup masivo de contexto + workflow
Commits `1ce7a08..1ec8880` (9). Highlights: fix `itemSold` en `_getTableSchema()` (422 vacío en /purchase); 5 fixes UX frontend (phone flags, Tab→nueva línea en /purchase, favicon, menú settings); poda agresiva context/ (-44.5%, archives + split convenciones); `context-updater` apagado definitivamente; nueva `_feature-requests.md` con 32 pedidos del batch comercial.

## 2026-06-16 — POS post-fusión: pulido masivo, lock screen y módulos
Commits `556789c..5220d63` (~74). Highlights: fusión POS dentro de frontend y eliminación de app-next; slices A6/A7 (BFF bootstrap, caja activa, JWT con `rid`); lock screen scoped + IVA real + rework UX del menú principal.

## 2026-06-15 — app-next Slice A3 cobro, correcciones UI y salida de Dropbox
Commits `9781463..77518ee` (31). Highlights: módulos Packs de Servicios y Listas de Precios (migs 31/32); tab Direcciones contactos; Slices A1/A2/A3 del POS rewrite + Dockerfile Coolify.

## 2026-06-14 — /modules, billing tenant+admin, /admin greenfield y dLocal Go
Commits `1edc674..780ec4b` (63). Highlights: marketplace /modules + billing completo + /admin reescrito en frontend; dLocal Go con webhook anti-doble-acreditación; 12 reportes nuevos; módulo Compras y Gastos slice 1 + view-scope "Todas las sucursales" + MoneyInput/DatePicker convencional + revert Intercepting Routes.

## 2026-06-13 — Sprint mayor frontend: selector sucursal, /settings, dashboard
Commits `fd5e5b3..580d79a` (15). Highlights: selector de sucursal + /settings modal Alfred + dashboard 2-col legacy; JSONB demote slice II (migs 25/26/27); 10/24 reportes implementados; patrón canónico Front → BFF → API reafirmado.

## 2026-06-12 — Editor de plantillas, refactor theme y refactor taxonomy
Commits `1c2055b..7d52335` (25). Highlights: editor visual de plantillas de impresión (`/settings/print-templates`); refactor theme tweakcn b5eYG4A9A + multi-category; refactor `taxonomy` → tablas dedicadas en 4 slices con triggers PG bidireccionales.

## 2026-06-11 — frontend CRUDs y auditoría de tokens
Commits `772f12b..df3cf03`. Highlights: build-out CRUDs reales (auth+outlets+contacts+items+settings); plan refactor profundo de Items en `context/13`; auditoría de consumo de tokens (agents Opus→Sonnet, CLAUDE.md reescrito).

## 2026-06-10 — F2 cierre técnico y PIVOTE panel legacy → React
Commits `2f68193..c4978c1`. Highlights: F2 cierre técnico 100% (outlets/settings/bootstrap/contacts/items/vpayments); rebrand visual Encom→Punto; PIVOTE arquitectónico — panel legacy se reescribe greenfield en frontend con plan en `context/12`; desacople /panel → /api fases 0/1/2 completas (21/23 reportes migrados).

## 2026-06-09 — Deploy a Coolify single-container y onboarding production
Commits `ea7b67f..5ea3a2d`. Highlights: pasaje de 4-services a container único con `router.php` por Host header; auth phone-first system-wide con libphonenumber + migración 12; SSO panel→app con JWT pos-app de 15s + Redis sessions.

## 2026-06-07 — F3.5 impersonación JWT, F3.4 fix y workflow optimization
Commits `fb4a691..456092f`. Highlights: F3.5 "Ingresar como empresa" con JWT panel; F3.4 fix saveCompany balance; code-reviewer solo en commits alto riesgo + context-updater consolidado en /end-session.

## 2026-06-07 — F3.3 delete cascade y F3.2 update company
Commit `5a6e4ab` + `5fe4b39`. Highlights: softDelete/hardDelete de company (~57 DELETEs ordenados); PATCH endpoint con whitelist + JSONB config merge; UI danger zone + form edición 10 campos.

## 2026-06-06 — Bug bashing post-PSR-4, device pairing y JWT_TTL 10 años
Commits `4f28aa3..70dbc22`. Highlights: 5 bugs cazados (PHP 8 hardening + JSONB-demote); device pairing backend completo con tabla `device` + migración 11; "Eliminar Punto de este dispositivo" auto-revoke; JWT_TTL /app a 10 años como modelo device pairing.

## 2026-06-05 — F3.1 Companies + PSR-4 Slices 2-15 mega-sesión
Commits `ceed82d..747384d`. Highlights: F3.1 Admin realm deployable con `CompanyAdminService`; SMTP/NCM creds → env; PSR-4 Slices 2-15 (~7573 callsites migrados acumulados, functions.php 5117 → 2560 líneas); 8 clases en `Punto\App\*` (Json, Output, Validation, Str, Date, Math, Arr, Cond, Taxonomy, Store, Customer, Query, Document, Money, Inventory, GiftCard, Notification).

## 2026-06-04 — PSR-4 Slice 0, CI mínimo y consolidación fetch handlers
Commits `2aa149f..8a7819c`. Highlights: estructura `Punto\App\*` con composer PSR-4 (3172 clases) + plan completo en `docs/PLAN_functions_php_PSR4.md`; CI GitHub Actions 3 jobs paralelos + `.editorconfig`; `app/fetch.php` eliminado y fallback Hashids cerrado (deuda de seguridad).
