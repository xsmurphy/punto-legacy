# Hand-off — 2026-07-31

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Tres frentes en paralelo, en sesiones distintas mergeadas a `main`:
1. **Esta sesión**: cerrar el círculo financiero del POS (cheques, créditos,
   previsión, notificaciones) + arreglar bugs de testers, y construir
   `/admin` completo como centro de operación del SaaS (F1→F6) a pedido
   explícito del owner.
2. **Sesión paralela — facturación electrónica**: continuó F3 (medios de
   pago, lookup de RUC, notas de crédito) sobre `context/28-facturacion-
   electronica-plan.md`. Detalle de esa sesión NO está en este hand-off
   (era de otra sesión) — su plan doc es la fuente de verdad.
3. **Sesión paralela — POS mobile**: dashboard de turno en la bienvenida del
   menú, nav de módulos, calendario "Próximamente". Tampoco detallado acá.

## Estado al cerrar

Todo commiteado y pusheado a `main`, deploy verificado con build limpio en
worktree aislado antes de cada push (dos veces se rompió el deploy por
commits parciales de otra sesión — ver Callejones).

**Finanzas/notificaciones/OCR/panel** (mi sesión):
- Cheques nacen del pago (venta/crédito/compra) — mig 102, POS pide nro de
  cheque vía identifier existente. `/finanzas/creditos` (fin_loan, cuotas
  iguales) y `/finanzas/prevision` (vencimientos consolidados).
- Centro de notificaciones (mig 103): campanita vive en el menú del usuario
  (pie del sidebar), no en fila propia — cambiado a pedido del owner después
  de la primera implementación.
- OCR de facturas de compra (`/purchase` → botón subir → `/purchase/drafts`):
  acepta foto Y PDF nativo multipágina (Gemini vía OpenRouter, capability
  `vision`), prompt con guía por bloques + formatos paraguayos (técnicas de
  un proyecto hermano del owner, adaptadas), validación aritmética no
  bloqueante, match de proveedor por RUC. Aprobar reusa
  `PurchasesService::create` — nada entra a stock/finanzas sin humano.
- Sidebar reorganizado: Ventas / Compras y Gastos / Finanzas como grupos
  principales; Reportes quedó solo analytics. `StatTile` canónico en 14
  páginas de reportes (antes KPIs "crudos" sin card) + fix de "Gs. Gs." duplicado.
- Agente IA: genera gráficos (`render_chart`, Recharts) en el chat, nombre y
  personalidad configurables por empresa, indicador "Pensando…" ya no
  desaparece a mitad de respuesta (rotaba por fase real).
- Signup E2E: los endpoints llamaban scripts legacy borrados (`2fapin.php`/
  `phonevalidator.php`) → moría siempre en prod. OTP propio (`SignupOtp`,
  mig 106) con modo `SIGNUP_OTP=off|on` (default off, owner quiere signup
  funcionando YA sin validar OTP real todavía).

**`/admin` SaaS — F1 a F6 completas** (`context/34-admin-saas-plan.md`,
plan pedido por el owner y ejecutado fase por fase, cada una con
code-reviewer antes de dar por cerrada):
- F1 Dashboard: MRR, activos/trial/morosos, altas/bajas, créditos IA + 4
  series de 12 meses.
- F2 Semáforo de salud (mig 108): 6 dimensiones ponderadas (pesos en
  `platform_config`, editables sin deploy), override crítico (vendía y
  >14 días sin vender → rojo), checklist de adopción accionable, sort por
  riesgo server-side.
- F3 Ficha del tenant: 6 tabs, suspender/reactivar con columna `suspended`
  PROPIA (mig 110 — NO pisa el `blocked` de mora, bug real de la primera
  implementación, corregido en review), extender trial, ajuste de créditos
  IA auditado, notas internas (mig 109).
- F4 Planes con versionado NO retroactivo (cambiar precio archiva y crea
  plan nuevo), catálogo de módulos con kill-switch REAL (apaga para todos
  sin tocar estado por-tenant), modelos/paquetes/consumo de créditos IA.
- F6 Roles admin (owner/support/sales jerárquico), test de conectividad de
  modelos, llaves de terceros enmascaradas con precedencia config>env
  (`PlatformConfig::get`, merge por campo — fix de seguridad del día, ver
  Callejones), estado del sistema, broadcast a tenants.
- F5 Facturación dogfooding: pago dLocal confirmado → venta REAL en un
  tenant Punto propio (configurable en `admin/platform`), vía el MISMO
  `SaleService`, numeración fiscal real. Idempotencia de dos capas. Review: 0 P0.

**Todas las fases de `/admin` pasaron por code-reviewer** (billing y roles
con foco extra por ser el código más sensible del repo) — 0 P0 en total.

## Archivos y cambios

- `context/30-cheques-prevision-creditos.md`, `31-centro-de-notificaciones.md`,
  `32-ocr-facturas-compra.md`, `33-agente-especialidades-plan.md`,
  `34-admin-saas-plan.md` — planes de esta sesión, cada uno con su sección
  "implementada" al día.
- `api/lib/Admin/*` — backend completo de `/admin` nuevo (TenantHealthService,
  CompanyAdminService ampliado, PlanAdminService, AiAdminService,
  PlatformConfig/PlatformConfigAdminService, SaasBillingService,
  SystemStatusService, AdminReportsService).
- `api/lib/Support/TenantClock.php` — hora tenant-local para código que no
  pasa por `data.php` (bug real: varios writers de Finanzas escribían UTC).
- `api/lib/Finance/*`, `api/lib/Purchases/PurchaseDraftService.php`,
  `api/lib/Notifications/FeedService.php` — nuevo del lado tenant.
- `frontend/app/(admin)/admin/*` — dashboard/companies/plans/modules/ai/
  platform/system nuevos o ampliados.
- `frontend/components/agent/*` — chart, thinking-indicator, settings dialog,
  clear-chat-button.
- Migraciones 102 a 114 (cheques/loans, notification_state, purchase_draft,
  signup_otp, printer_binding_station, tenant_health, company_suspended,
  tenant_note, admin_user_role, saas_billing) — todas corridas en prod y
  verificadas (dry-run o confirmación post-deploy).

⚠ Sin commitear al cierre (de OTRA sesión, en Finanzas — NO tocado):
`api/database/seeds/finance_backfill.php`, `api/lib/services/ReturnService.php`,
`api/v1/finance/backfill.php`, `api/v1/transactions.php`,
`context/22-finanzas-module-plan.md`, `frontend/hooks/use-finance-backfill.ts`.

## Callejones sin salida

1. **Mig 102 casi rompe el boot en loop**: el seed de "Cheque" hacía INSERT
   ciego y el owner ya tenía un método "Cheque" creado a mano → choque contra
   `uq_taxonomy_company_type_name`, `migrate.php` exit(1) en cada boot,
   Coolify mantenía el container viejo sin loans/forecast. Fix: el seed
   ADOPTA el método existente por nombre en vez de asumir que no existe.
   Lección: un seed "si no existe" tiene que buscar por el criterio real
   (nombre/unique), no solo por el flag que está seedeando.
2. **Commit `53c8dc91` sin el hunk completo**: `useFinanceSummary` cambió de
   firma pero el archivo quedó modificado en el working tree sin stagear —
   tumbó TODOS los deploys hasta que se detectó comparando el build local
   contra un checkout limpio del commit. Lección dura: **verificar SIEMPRE
   contra un worktree limpio del commit, nunca contra el working tree** —
   el working tree puede tener cambios de otro agente/sesión que enmascaran
   justo el archivo que falta.
3. **`unsuspend` pisando `blocked`**: el primer intento de F3 usó `blocked`
   (compartido con el bloqueo por mora) para "suspendido" — un tenant
   moroso que pasaba por suspend→unsuspend perdía la señal de mora. Fix de
   raíz: columna `suspended` propia (mig 110), no un parche.
4. **Agentes colgados esperando su propio build en background** (mismo
   síntoma repetido en 3-4 sub-agentes): piden `npm run build` en background
   y se "duermen" esperando la notificación en vez de esperar en foreground.
   Instrucción explícita "esperalo vos, en foreground" en los briefs
   posteriores lo redujo pero no lo eliminó del todo.
5. **`PlatformConfig::get` devolvía el array guardado ENTERO**, no mergeado
   campo a campo con el fallback de env — un POST parcial a `flags` podía
   apagar `SIGNUP_OTP` en silencio (fail-open de seguridad). Encontrado en
   review de F6, arreglado con `array_replace_recursive` en el wrapper.

## Próximo paso

Nada bloqueado técnicamente. Acciones manuales del owner en `/admin`:
1. **Plataforma → Facturación del SaaS**: elegir tenant/sucursal/caja
   emisor y activar `saasBilling.enabled` (F5 queda inerte sin esto).
2. **Plataforma → Integraciones**: cargar llaves de Evolution/WhatsApp si
   se quiere `SIGNUP_OTP=on` (hoy en `off`, el signup funciona sin validar
   el código).
3. **Coolify → env de la API**: `APP_DEBUG=false` — hoy sigue en `true`,
   lo que hace que el signup responda en modo debug (código fijo `0000`
   expuesto en la respuesta HTTP).
4. Revisar roles de los admins existentes (`/admin/users`) — el primer
   admin quedó `owner` en el backfill de la mig 112.

Si se retoma código: no hay nada a medio hacer de esta sesión. El plan
`context/33` (especialidades del agente / "Super Poderes") está
documentado pero sin arrancar — candidato a próximo bloque grande.

## Trampas conocidas

- Ningún tenant real tiene `isinternal=1` todavía — F5 (dogfooding) no
  emite nada hasta que se configure el tenant emisor (paso 1 de arriba).
- `SIGNUP_OTP=off` significa que CUALQUIER código pasa la verificación del
  teléfono — aceptable mientras `APP_DEBUG=true` lo hace irrelevante, pero
  si se apaga `APP_DEBUG` sin prender `SIGNUP_OTP`, el signup queda sin
  validar el teléfono (decisión consciente del owner, no bug).
- `ai_credit_package` (F4) es solo catálogo — la compra de paquetes por el
  tenant no existe todavía, explícitamente fuera de alcance.
- El preprocesado de imagen externo (`invoice-cleaner.actuo.app`, de un
  proyecto hermano del owner) no se adoptó para el OCR — Gemini solo por ahora.
- Trampas heredadas de sesiones previas, sin tocar esta sesión: coordenadas
  de sucursales en NULL, `lease.php` sin exclusividad por caja
  (`context/29`, no arrancado), razón social/RUC/timbrado sin viajar al
  POS en algunos templates.
- Facturación electrónica sigue en curso en paralelo (F3 avanzó) — para su
  estado real, ver `context/28-facturacion-electronica-plan.md`, no este
  archivo.
