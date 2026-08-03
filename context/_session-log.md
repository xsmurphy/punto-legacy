<!-- REGLA: Agregar entry al cierre de cada sesión de trabajo. Formato: más reciente arriba.
     Cap blando: 200 líneas. Al superar, mover las más antiguas a _session-log-archive-YYYY-MM.md -->

# Bitácora de Sesiones

## 2026-07-30/08-03 — Facturación electrónica F3-F7 completa + fixes de items/POS

Commits `aeb0315e..aaff6f14` (14 propios; entreverados con ~50 de sesiones paralelas sobre POS/admin/compras/`transaction_link` — ver sus propias entries/hand-off). Highlights: FE F3 (medios de pago reales, lookup de RUC vía Factomate/padrón público, notas de crédito, fix factura-por-el-neto-no-el-bruto) → F4 (rip-out del proveedor legacy) → F6 (portal público QR del comprador) → F7 (onboarding white-label — timbrado vive en la CAJA no duplicado, Punto nunca expone Factomate al tenant); fix de raíz `Validation::isValid` leía `true` de JSON como `false` (rompía TODO switch de `/modules`, no solo FE); tab Stock de ítems con KPIs/historial/ajuste reales (era "Próximamente"); fix VariantService CIA TypeError (bloqueaba guardar variantes); fix mesa POS que forzaba el pago sin dejar sumar cliente/descuento antes. Plan vivo en `context/28-facturacion-electronica-plan.md`. Pendiente: credencial admin de Factomate en env (F7 sin verificar contra API real), migs 92/93/95/100 sin correr en prod.

## 2026-07-30/31 — Finanzas (cheques/créditos/previsión) + notificaciones + OCR de compras + /admin SaaS completo (F1-F6)

Commits `774bd0d8..1f7b8dd0` (114, incluye facturación electrónica F3 y POS mobile de otra sesión en paralelo). Highlights: cheques/créditos/previsión (mig 102) + centro de notificaciones (mig 103) + OCR de compras foto/PDF con Gemini (migs 105/106) + reorg del sidebar + agente IA con gráficos/personalidad + signup E2E propio + `/admin` SaaS F1-F6 entero (dashboard, semáforo de salud, ficha de tenant, planes/módulos/créditos, roles/llaves/broadcast, facturación dogfooding) — todo revisado por code-reviewer (0 P0) y migraciones dry-run contra prod. Pendiente: configurar tenant emisor en `/admin`→Plataforma (F5); `APP_DEBUG=false` en Coolify.

## 2026-07-27/30 — Facturación electrónica Paraguay (SIFEN) — F0/F1/F2 contra API real de Factomate

Commits `d7feed6d..774bd0d8` (15). Highlights: F0 conexión de cuenta (migs 92/93/95, `CredentialVault` AES-256-GCM, módulo `einvoicePy`); pivot de proveedor a mitad de sesión — la F0 se había implementado contra Automate creyendo que era el motor, pero el motor real es **Factomate** (Automate es otro cliente suyo), corregido con mig 95 sin tocar la 92; F1 emisión automática con outbox transaccional en `SaleService` + drainer CAS; F2 listado/KuDE/cancelación/reconciliación; verificado end-to-end contra DEV con 2 facturas reales (correlativo 54 rechazada por SIFEN 1002 duplicado, 55 aprobada `FinalizadoOK`); 9 bugs que solo aparecían contra la API real, incluido que el taxRate se calculaba mal y habría emitido TODO exento de IVA sin error visible. Plan vivo en `context/28-facturacion-electronica-plan.md`. Pendiente: `APP_ENCRYPTION_KEY` sin configurar (el módulo no arranca sin eso), migs 92/93/95 sin correr en prod, F3-F7.

## 2026-07-29/30 — Wrapper DB (4 features rotas por el mismo bug) + descuentos por ítem + IVA toggle + agente IA fixes

Commits `3299437e..1076d1e9` (37; excluye 9 commits paralelos de facturación electrónica intercalados). Highlights: `_getTableSchema()` no listaba columnas reales → `hasVariants`/`pinhash` se escribían al JSONB y nunca cambiaban (migs 99/100); `ncmInsert` fallback ciego a PK `'id'` tumbaba `expenses` (`_resolveTablePk()`); `flattenJsonb` borraba el JSONB crudo en 5 sitios (venta guardada, config de caja con 3 cajas ignorando toggles, anular compra sin revertir stock) → fix de raíz con side-channel `Query::rawJsonb()`; descuentos ahora se reparten por ítem (`allocate-discounts.ts`) con alcance congelado + un descuento por producto (corrige mi propia implementación anterior); IVA toggle persistía mal (mig 101, `lineGross()` única fuente); fix P0 auth: `api-client` desemparejaba el device del POS por 401 heurístico. Pendiente: 5 bugs por reproducir en prod, deuda de permisos (17/45 chequeados en backend).

## 2026-07-29 — Fulfillment de órdenes (F-D-0/1) + repartidor + geocoding + tanda de bugs de uso real + P0 ventas

Commits `c1541ba6..904df1a4` (57). Highlights: columna `fulfillment` (mig 94) + snapshot de dirección + selector Mostrador/Retiro/Envío + mapa filtrado; `out_for_delivery` "En camino" (mig 96) + `courierid`/asignación (mig 97); destino explícito único en KDS/despacho/listados/comanda; board de 3 columnas en `/display`; catálogo único de bloques de impresión (79 tipos, antes 2 renderers perdían 12); ruta BFF `/api/geo` (Photon); P0 prod: `getAllWasteValue` leía `itemWaste` de columna en vez del JSONB `data`, tumbaba toda venta; fix `Validation::isValid` descartaba coordenadas negativas (Paraguay) en silencio; `context/_handoff.md` + skill `/end-session` únicas. Post-cierre (`b49e582c..7aec191a`, docs): catálogo de documentos imprimibles cerrado (Factura/Comprobante/Recibo/Nota de crédito/Remisión/Cotización/Orden, gift card → Comprobante) y hallazgo de que el botón "Interno" no hace nada y quema numeración fiscal.

## 2026-07-27 — Sesión multi-día: Estación de Impresión P0+P1, split de cuenta F3, historial de transiciones F-EVT-0, rediseño KDS+Órdenes+Espacios, libreta de direcciones

Commits `49962f74..6fd81ffd` (53) en una semana (19-jul → 27-jul).

- **Hecho**: Estación de Impresión P0 backend (mig 83, `PrintPoolService`, WS) + P1 pantalla `(screen)/print` con pairing/drenado. Split de cuenta en Espacios (migs 90/91, `SpaceSettlementService`, 4 modos, CAS anti doble cobro). Historial de transiciones `pos_order_event` (migs 85/86, `recordEvent()` en misma TX, base de SLA). Libreta de direcciones extendida sobre `customerAddress` (mig 87) + parser de coords compartido. Rediseños: KDS a flujo horizontal con recall, `/pos/ordenes` con barra flotante (cuadros/lista/mapa), Espacios con switch grilla/mapa. Bugs de raíz: `parseNaive` no stripeaba offsets `-03`/`+00` (fechas rotas en toda la app), `min-w-0` faltante en `SidebarInset`/`DataTable`, TX del wrapper DB sin contador de anidamiento, lock del POS solo en memoria (recarga pedía PIN de nuevo).
- **Decisión**: ADOdb no existe en el proyecto — nombres de métodos legacy no implican dependencia, referencias eliminadas de código y docs. Cancelar orden exige motivo (enforcement en el service). Pedir la cuenta no bloquea agregar órdenes. Etiquetas: `ready`=Listo, "Enviado" reservado a delivery. Costo de envío = ítem del catálogo (cascada zona→banda, sin API externa). SLA target = máximo por estación. Nombres vertical-neutrales ("pantalla de despacho", no "de mozos").
- **Pendiente**: deploy de ~30 commits + migs 83/85/86/87/89/90/91 (idempotentes, corren en boot). F-D-0 (fulfillment/delivery) cancelado por el owner, no relanzado — bloquea columnas de /pos/ordenes. Cobro de mesa: se corrigió clasificación 5xx y timeout, falta diagnosticar el error real reportado por el owner. Estación de impresión P2/P3. Decisión pendiente: ¿repartidor con app propia?
- **Atención**: agentes en paralelo sobre worktree compartido se pisan el trabajo entre sí (causó P0 en main, un `LEFT JOIN` perdido) — usar `isolation: "worktree"` siempre que 2+ agentes escriban en simultáneo. Un agente leyó el checkout compartido en vez de su propio worktree y dio por aplicado un cambio inexistente en su commit. Lint+build no alcanzan como gate — el code-reviewer encontró P0/P1 reales (doble cobro por saldo cacheado, reintento contado doble, ticket impreso marcado fallido) en commits que ya habían pasado ambos.

## 2026-07-19 — Sesión multi-día: Finanzas F3+medios de pago, agente IA batch, impresión unificada, Producción v1, Órdenes O0-O2, Espacios (rename Mesas), auth invariante un-cliente-un-realm

Commits `40fc0187..a95d814f` (~150) en dos semanas y media (03-jul → 19-jul). Sesión mayor con módulos nuevos + hardening cross-cutting.

**Hecho — Finanzas y medios de pago:** Fase 3 FinanceLedger (mig 73) con hooks post-commit best-effort + backfill/revert idempotente **corridos en PROD** (Efectivo −690M → +55M tras excluir compras sin cuenta de pago); drill-down por cuenta + DateRangePicker + agregación SQL; reorganización Operación/Reportes/Configuración. CRUD medios de pago (taxonomía+finAccountMap, systemKey), color+orden drag&drop (dnd-kit), `ColorPicker` compartido aplicado a hotkeys/usuarios/impresoras.

**Hecho — Agente IA:** batch confirmation (un `confirmToken` para N acciones), render determinístico con card Sí/No.

**Hecho — Impresión:** WebUSB/ESC-POS verificado end-to-end; unificación de 3 flujos sobre `printTicketInBrowser` (mató `window.print()` crudo); fix docType factura sin fallback a recibo (regla fiscal); plan Estación de Impresión (pool) documentado en `context/24`.

**Hecho — Producción v1** (`context/23`): F0 recetas canónicas en `item_compound` (mig 75 — el editor escribía una tabla que nadie leía; fix combo COGS a costo real; guard ciclos); F1 `production_order`+`waste_event` (migs 76/77, permiso `production.manage`); F2 UI `/produccion`. Merma = rendimiento ÷(1−m).

**Hecho — Órdenes** (`context/24`): O0 core (`pos_order`/`order_station`, estados 2 niveles CAS, correlativo advisory-lock, realtime canal `kds`); O1 modal POS (Pagar↔Ordenar, comandas, cobro por volcado al carrito); O2 KDS+display device-paired WS (responsive teléfono→TV, whitelist de transiciones por module).

**Hecho — Espacios** (ex Mesas, `context/15`): F0/F1 schema+editor layout (react-rnd); F2 operación POS (mapa en slot, sesión, cobro multi-orden); **rename completo Mesas→Espacios** (migs 81/82, sector obligatorio + default "Salón", data-fix huérfanos).

**Hecho — Infra/wrapper:** `ncmExecute` DML devuelve filas afectadas reales (500 fantasma de active-register); migs 74/77 **jsonb `?`→`jsonb_exists()`** (colisión con placeholder PDO tiraba TODOS los deploys); heap del build 1536→3072 (OOM); resiliencia PWA ChunkLoadError.

**Hecho — Tester panel/POS:** cierre de caja 500 (error real propagado), gift cards emisión unificada (tabla `giftcard`, modelo fiscal PY Recibo/Factura, mig 78), pago de créditos desde Clientes (permiso `pos.sale.creditPayment`, mig 74).

**Decisión:** auth — invariante **un-cliente-un-realm**: `api-client` mandaba Bearer del device y el panel se autenticaba como caja (root cause de bugs de espacios multi-sucursal) → `pos-client` nuevo + migración de call-sites. Design system: Inter canónica (Poppins out), `EmptyState` variante ghost unificada, `context/20` reescrito como doc definitivo, `context/25-sucursales-y-scopes` nuevo. Proceso: UI compleja se ejecuta con Opus, backend/mecánico con Sonnet.

**Pendiente:** Estación de Impresión (pool) sin implementar; Órdenes O3 (split/reservas) + O4 (ecommerce/agenda); Producción v2 (parcial/co-productos/reversa); "Texto Personalizado" plantillas sin repro; backlog testing 2026-07-07 en roadmap; RG90/Libro ventas; deploy pendiente del último lote (`a95d814f`).

**Atención:** jsonb `?` prohibido en queries PDO (memoria + doc); backfill/revert de Finanzas ya corrido en prod manualmente — no re-correr sin revisar; `space.tableid` (PK) conserva nombre viejo post-rename (deuda cosmética).

## 2026-06-30 — Saga CIA/wrapper DB + restructura api/ self-contained + timezone + features POS + observabilidad

Commits `f247a918..aebd1780` (~43). Jornada de incidentes en cascada + hardening arquitectónico.

**Hecho:** Fix raíz CIA: `_flattenJsonb`/`ncmExecute` restaurados a `CaseInsensitiveArray`; CIA canónica = `api/includes/lib/DB.php` (prohibido duplicar); widening de 9 `present`/`shape`/`pick` a `array|\CaseInsensitiveArray`; `GetRow`/`GetOne` agregados al wrapper (causaban 500 silente en pagos crédito + devoluciones). Api/ self-contained: build context `./api`, Dockerfile único, database+entrypoint+router movidos — deployado healthy. Timestamps: convención fijada como **tenant-local naive** (no UTC); helper `tenantNow` + `parseNaive`; `timezone` expuesta en bootstrap. Control de caja: FK `drawerId` en transaction (mig 70), resumen exacto por sesión. Observabilidad: log fatales a stderr + handlers globales + JSON 500 limpio + Sentry gateado por `SENTRY_DSN`. Seed admin: `seed_admin.php` idempotente corre en cada boot. IA: guardrails (scope, anti-cross-tenant, no-destructivo), permiso por-acción, respeta sucursal. POS: categoría/marca/etiqueta inline, ruteo Factura/Recibo, reimpresión con plantillas, QR invite device, gating caja, descuento removible, grids mobile.

**Decisión:** NO migrar a Laravel/Node — hardening en DB.php; timezone = tenant-local naive (no UTC, actualizar docs que digan lo contrario); CIA canónica única.

**Pendiente:** Coolify: setear `ADMIN_EMAIL`/`ADMIN_PASSWORD` + `SENTRY_DSN`/`NEXT_PUBLIC_SENTRY_DSN`. Reabrir caja para que `drawerId` aplique a la sesión actual. Asignar impresora tipo "Factura" (bindings viejos de "Recibo" ya no disparan para ventas). Sweep `functions.php` por `GetRow`/`GetOne` latentes.

## 2026-06-29 — Auth rewrite (JWT→sesiones opacas) + limpieza legacy total + reestructura repo

Commits `7d0c09b2..3ddb96f0` (~50). Sesión estructural máxima — tres bloques mergeados a main.

**Hecho:**
- Auth rewrite F0–F6: tabla `auth_session` (mig 69) + `api/includes/auth_session.php`; 3 validadores colapsados en `authResolve($realms)`; emisores panel/device/admin a sesión opaca; revocación desde UI (`/settings/sessions`, endpoint `/v1/sessions`); rip-out completo de `jwt.php`. Token = `pt_`+random opaco (sha256 en BD). `realm` es columna, no claim criptográfico.
- Limpieza legacy: borrados `/app` (POS PHP), `/panel` (BS3), `/screens`, scripts MySQL→PG, `graphify-out`/mempalace/.venv, `/assets`, `/crons`, `panel/thirdparty`.
- Reestructura: `panel-next`→`frontend` (es todo el front: panel+POS+screen+admin+auth); `api/core` disuelto en `api/` (`api/includes/`, `api/lib/App/*`, vendor unificado, `chdir` eliminado); backend admin → `api/v1/admin/` + `api/lib/Admin/`. P0 cerrado: `migrate.php exec()` sin auth borrado.
- Docs de plan: `context/21-auth-rewrite.md`, `context/22-legacy-cleanup.md`.

**Decisión:** sesiones opacas stateful sobre JWT (revocación requerida por owner); `Punto\App` namespace conservado (colisión macOS case-insensitive con `api/lib/services`); cookies sin renombrar (`_jwt_panel`/`_jwt`/`_jwt_admin`); admin moderno (`frontend/(admin)`) es el canónico.

**Pendiente:**
- Deploy Coolify: cambiar build subdir `panel-next`→`frontend`; `app.punto.la` apunta al container frontend; PHP = API interna (`API_URL`); cutover = re-login masivo. `/admin` nunca probado en prod → smoke-test obligatorio.
- Reconstruir sobre stack moderno: facturas recurrentes (era cron→action.php, borrado); páginas `/screens` (recibo, factura E, gift card, order view, schedule confirm) — `SaleService`/transactions/orders/GiftCardService generan links `/screens/*` muertos; thumbnails items (250) + logo empresa.
- Polish: rename `Punto\App→Punto\Api` (diferido); romper `functions.php` (26k L); unificar `api/lib/services` lowercase; quitar `JWT_SECRET`/`ADMIN_JWT_SECRET`.
- Sweep completo de `context/*` — muchos docs referencian `/app`, `/panel`, `panel-next`, `api/core`, subdominios legacy.

**Atención:** `router.php` raíz necesita actualización para la nueva topología (un dominio `app.punto.la`, path-based); deploy hasta que se actualice Coolify mantiene el esquema viejo.

## 2026-06-29 — cleanup refactor flattenJsonb + phone storage sin "+" + reconnect device

Commits `5f745f43..91d09de9` (22). Highlights: cleanup refactor 28-jun en 10+ services (alias quoted + wrap CIA); convención phone storage SIN `+` end-to-end (mig 67 cleanup); feature reconnect device auto-aprobado (mig 68); window.print() global eliminado; `/v1/price_list` GET multi-realm; hotfix BD: phone Dueño + reset pass + outlet asignado + 4 GB swap server. Pendiente: drawer getIncome/getPaymentBreakdown (latente), crossing deviceId reconnect, TenantContext outletId opcional.

---

## Entries anteriores al 2026-07-19 — archivadas

Las sesiones anteriores viven en [_session-log-archive-2026-06.md](_session-log-archive-2026-06.md) y [_session-log-archive-2026-05.md](_session-log-archive-2026-05.md) (más antiguos).
