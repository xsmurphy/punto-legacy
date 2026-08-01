# /admin SaaS — plan (dashboard, tenants, salud, planes, billing)

> Plan 2026-08-01. Visión del owner: /admin como centro de operación del
> SaaS — analíticas, gestión de tenants con **semáforo de salud/adopción**
> (retención proactiva: ver qué funciones no usan y ayudarlos a
> implementarlas), CRUD de planes y créditos IA, módulos, facturación
> emitida desde un tenant Punto propio, usuarios admin, modelos/proveedores
> y llaves de terceros.

## Base existente (no arrancar de cero)

- Realm admin operativo: `frontend/app/(admin)/admin/*` (dashboard,
  companies, users, audit, requests, reports) + `api/v1/admin/*` +
  `api/lib/Admin/*`. Auth propia (`_jwt_admin`, sesión opaca).
- Tablas ya en prod: `plans`, `billing_request`, `billing_invoice`,
  `ai_credit_ledger`, `ai_model_config`, `admin_audit`, `tenant_audit`.
- Billing: `BillingService` + dLocal (`PaymentsService`/DlocalGoProvider).
- Módulos activables por tenant (catálogo + gating en panel/POS).

## F1 — Dashboard + analíticas del SaaS

KPIs arriba (StatTile canónico): MRR, tenants activos/trial/morosos,
churn del mes, créditos IA consumidos vs facturados, señales de riesgo
(cuántos tenants en rojo). Charts (Recharts, patrones del panel):
evolución de MRR, altas/bajas por mes, GMV agregado de tenants (ya hay
rollups), consumo IA por modelo. Fuente: queries agregadas sobre lo
existente; nada de warehouse.

## F2 — Salud del tenant (semáforo) ⭐ prioridad del owner

**Objetivo**: dependencia alta = retención. Detectar subuso ANTES de la
baja y accionar ("no usan X, ayudémosles a implementarlo").

**Señales por tenant** (todas computables hoy, sin instrumentación nueva):

| Dimensión | Señal | Fuente |
|---|---|---|
| Actividad core | Ventas: recencia, frecuencia, tendencia 30/90 días | transaction / rollups |
| Amplitud de uso | Módulos activos vs efectivamente USADOS (órdenes creadas, espacios cobrados, producción corrida, movimientos de finanzas, drafts OCR) | tablas de cada módulo |
| Profundidad | Catálogo con stock cargado, medios de pago configurados, impresoras/bindings, plantillas, usuarios con roles ≠ dueño, sucursales | config + conteos |
| Equipo | Usuarios activos últimos 14 días / usuarios totales; devices POS vivos | auth_session / device |
| IA | Créditos consumidos por semana (tendencia) | ai_credit_ledger |
| Comercial | Estado de pago, días a vencimiento del plan, requests de soporte | billing_* |

**Score 0-100** = suma ponderada (pesos en config admin, no hardcodeados).
Semáforo: verde ≥70, amarillo 40-69, rojo <40 **o** cualquier señal
crítica sola (ej. 14 días sin ventas en un tenant que vendía a diario —
la recencia mata el promedio).

**Computación**: v1 on-demand con cache en tabla `tenant_health`
(companyid, score, semáforo, señales jsonb, computed_at) refrescada al
abrir la vista si tiene >6h — hoy hay pocos tenants, las queries agregadas
son baratas. Cuando haya cientos: job programado (mismo camino que los
rollups de context/18). NO bloquear el plan en infra de cron.

**UI**:
- Columna semáforo + score en el listado de tenants (sort por riesgo).
- Ficha del tenant, tab "Salud": radar de dimensiones, línea de score
  histórico (se guarda un snapshot semanal en `tenant_health_history`),
  y la parte accionable: **checklist de adopción** — funciones no usadas
  ordenadas por impacto ("Tiene Espacios contratado y 0 sesiones en 30
  días", "No configuró medios de pago ≠ efectivo") con el paso siguiente
  sugerido. Esto es lo que convierte el semáforo en acción comercial.
- Feed "tenants que cambiaron de color esta semana" en el dashboard F1.

## F3 — Gestión de tenants (completar la ficha)

Sobre `companies` existente: ficha con tabs — Resumen (salud), Config
(plan, módulos on/off con override manual, créditos IA: saldo + regalar/
ajustar con motivo → `ai_credit_ledger`), Facturación (historial
billing_invoice, estado dLocal), Actividad (tenant_audit), Acciones
(impersonar — ya existe—, suspender, extender trial, notas internas del
tenant: tabla `tenant_note`, companyid+authorid+texto+fecha).

## F4 — CRUD planes, módulos y créditos

- **Planes**: CRUD sobre `plans` (hoy solo lectura desde BillingService):
  precio, duración, límites (usuarios/sucursales/devices), módulos
  incluidos, créditos IA incluidos/mes. Cambios NO retroactivos: versionar
  (`plans.version` o soft-copy) para no mutar el plan de tenants vigentes.
- **Módulos**: catálogo admin — precio por módulo suelto (para los "Super
  Poderes" del agente, context/33), visibilidad (beta/GA), toggle global
  kill-switch.
- **Créditos IA**: precios por capability (`ai_model_config.creditsPerKToken`
  ya existe — UI de edición), paquetes de créditos comprables, y reporte
  de consumo/margen por tenant y por modelo (`ai_credit_ledger` lo tiene).

## F5 — Facturación "dogfooding" (Punto factura con Punto)

Decisión del owner: la facturación del SaaS se emite desde un tenant
Punto propio (Punto-la-empresa como tenant).

- Config admin: `billingTenantId` (+ registerId/outlet a usar).
- Al confirmarse un pago de suscripción (webhook dLocal → billing_invoice
  pagada), se crea la venta en el tenant Punto vía el MISMO
  `SaleService` (server-side, sin HTTP interno), con el cliente = empresa
  del tenant (contacto creado/matcheado por RUC) y el item = plan/módulo.
- Ventaja: numeración fiscal, timbrado y factura electrónica (context/28)
  salen gratis por el rail del producto. La factura del SaaS es una
  factura real del sistema.
- Cuidados: idempotencia por billing_invoice (una venta por invoice,
  UNIQUE), moneda/precio consistente, y NO mezclar métricas — el tenant
  Punto se excluye de las analíticas F1/F2 (flag `isInternal` en company).

## F6 — Plataforma: admins, modelos, llaves, config

- **Usuarios admin**: CRUD ya existe (`users.php`) — sumar roles admin
  (owner/soporte/comercial: comercial ve salud y notas, no toca planes ni
  llaves) + audit de acciones sensibles (admin_audit ya está).
- **Modelos/proveedores IA**: UI sobre `ai_model_config` (slug OpenRouter,
  capability, enabled, precio) + test de conectividad ("probar modelo").
- **Llaves de terceros y config de negocio**: mover a config DB-backed
  editable en admin las integraciones de NEGOCIO (Evolution/WhatsApp,
  Mailgun, SMS, dLocal sandbox/prod, flags de features, allowlist CORS) —
  tabla `platform_config` (key, value jsonb, updated_by, updated_at) con
  cache. **Secretos de bootstrap NO** (DB/Redis/S3/JWT/workers quedan en
  env — regla ya decidida). Las llaves se muestran enmascaradas y el
  cambio queda en admin_audit.
- **Otras que conviene sumar**: página de estado del sistema (versión
  deployada, migraciones aplicadas, colas/errores recientes vía Sentry),
  y broadcast a tenants (aviso en el panel de todos — mantenimiento,
  novedades) sobre la tabla `notify` que el centro de notificaciones ya
  consume.

## Orden y tamaño

F2 (salud) es la prioridad declarada del owner y F1 la vitrina — pero F2
depende de definir señales/pesos: arrancar por **F2 → F1 → F3 → F4 →
F6 → F5** (F5 último: depende de facturación electrónica estable en el
tenant interno). Cada fase es deployable sola.

## Decisiones abiertas (owner)

1. Pesos iniciales del score y umbrales del semáforo (propuesta arriba —
   ajustable en admin, pero hay que arrancar con algo).
2. Roles admin: ¿alcanza owner/soporte/comercial?
3. F5: ¿qué tenant es "Punto" (crear uno nuevo limpio?) y qué timbrado usa.
4. ¿Analíticas excluyen tenants de prueba/demo? (flag isInternal propuesto).

## Anti-objetivos

- Warehouse/BI externo — todo sobre PG existente.
- Instrumentación de eventos nueva en el panel para medir adopción v1
  (las tablas de cada módulo YA cuentan el uso real).
- Score con ML — suma ponderada transparente, explicable al equipo.
- Editar secretos de bootstrap desde la UI.
