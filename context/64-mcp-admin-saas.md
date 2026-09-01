# 64 — MCP de admin (salud de clientes SaaS)

> Estado: **PLAN, sin implementar.** Fecha 2026-09-01. D1 cerrada por el owner,
> no relitigar. D2-D5 son PROPUESTAS y necesitan su OK — marcadas **[?]**.
> El dato ya está calculado (`TenantHealthService`, `AdminReportsService`) —
> este plan es transporte, no un motor nuevo. Ver §Estado del código.
> No confundir con `context/58-mcp-server.md` (MCP de TENANTS, en producción):
> son dos servers distintos, con distinta credencial y distinto dueño del
> dato — ver §D1.

## Qué pidió el owner

Un MCP para uso propio, como operador del SaaS: *"que nos ayude a tener
información del estado de nuestros clientes, semáforo, planes, vencimientos,
churn, etc."* — no el MCP de tenants (`context/58`), que conecta el Claude
de CADA COMERCIO a SUS PROPIOS datos.

## Decisiones — cerradas por el owner

### D1 — Expone estado y agregados del negocio SaaS, NO datos de negocio de los tenants

El MCP de admin responde "¿cómo están mis clientes?" (salud, planes,
vencimientos, churn, MRR, adopción de módulos), nunca "¿qué vendió el
comercio X?".

- **La propiedad del dato cambia de categoría, no de escala.** En el MCP de
  tenant, quien consiente es el dueño de los datos: el comercio conecta su
  Claude a lo suyo. En admin esa propiedad desaparece — los datos son de los
  clientes de Punto, y de los clientes de esos clientes. Una key de tenant
  filtrada expone un comercio; una key de admin filtrada expone a TODOS.
- **Precedente propio del proyecto**: `context/55-franquicias.md` decidió que
  el franquiciador NO entra al panel del franquiciado, solo ve agregados
  desde los rollups. El mismo criterio aplica acá con más fuerza, porque el
  operador del SaaS ni siquiera es parte de la relación comercial entre el
  comercio y sus clientes.
- **Bajar al detalle de un tenant puntual para soporte queda fuera de
  alcance**, no como pendiente de este plan. Si hace falta, pide otro
  mecanismo — motivo declarado, ventana de tiempo, registro de quién miró
  qué — más parecido a la impersonación que `/admin` ya tiene que a una API
  key permanente.

- **D6 — El MCP solo LEE lo persistido en `tenant_health`; nunca recalcula.**
  Cerrada por el owner (2026-09-01). `computeAll()` recorre todos los tenants
  y su `force` existe para el recálculo programado, no para una consulta de
  chat: una pregunta casual del operador no puede disparar el recómputo de
  toda la cartera. El MCP responde con la última foto y **declara cuándo se
  calculó** (`computed_at` ya está en la tabla), que es lo honesto — un score
  de hace tres días presentado como "ahora" es peor que uno viejo con fecha.
  Corolario: si la foto envejece, el problema es la frecuencia del job que la
  refresca, no el MCP.

## Estado del código (verificado)

**El semáforo y los agregados ya existen — no hay que calcular nada nuevo:**

- `api/lib/Admin/TenantHealthService.php` — score 0-100 por empresa sobre 6
  dimensiones (activity, breadth, depth, team, ai, commercial), cada una con
  subscore y `signals`; pesos en `platform_config.tenantHealthWeights`,
  editables sin deploy. Nivel green/yellow/red con override: última actividad
  hace más de 14 días es `red` sin importar el score. `computeAll()` (:82,
  con `force`), `computeFor()` (:96), `getDetail()` (:112), ranking
  `ORDER BY score ASC` (:176, los peores primero). Persiste en `tenant_health`
  e historial semanal en `tenant_health_history` (12 semanas).
- `api/lib/Admin/AdminReportsService.php` — `overview()` (:97): conteos por
  estado (total/active/trial/suspended/cancelled) y **MRR** como
  `SUM(plans.price)` sobre empresas en buen estado comercial (excluye
  `planExpired` y `expiresAt` vencido). **Churn ya está**: bajas por mes en
  `series.tenantsByMonth[].churned` (:352-360). `payments(from, to)` (:472).
- `api/lib/Admin/PlanAdminService.php`, `ModuleAdminService.php`,
  `SystemStatusService.php`, `SaasBillingService.php`,
  `CompanyAdminService.php`, `PlatformConfigAdminService.php` — el resto de
  los agregados que el catálogo va a envolver.
- Endpoints existentes en `api/v1/admin/`: `dashboard.php`, `health.php`,
  `companies.php`, `plans.php`, `platform.php`, `audit.php`, `system.php`,
  `users.php`, `modules.php`, `ai-config.php`, `tenant-notes.php`, `me.php`,
  `login.php`. Todos sirven a la sesión interactiva del admin, ninguno a una
  credencial programática.
- Contexto previo: `context/34-admin-saas-plan.md` (F1-F6 implementadas).

**Auth del realm admin** (`api/lib/Auth/AdminAuth.php`):

- Misma tabla `auth_session`, `realm = 'admin'` (:6), vía
  `authSessionCreate`/`authResolve`. `companyId` es NULL — cross-tenant por
  diseño. La sesión interactiva ya se emite con `module = 'admin'`
  (`adminIssueSession()`, :82) — la tabla YA discrimina por `module` dentro
  del mismo realm, no hay que inventar esa partición.
- `adminMiddleware()` (:144) llama `authResolve(['admin'])` directo (:158).
  **No pasa por `apiAuthTenant()` ni carga `api/bootstrap.php`** — es un
  camino de auth completamente aparte del que usa el resto de `/v1/*`.
- Cookie `_jwt_admin`, TTL `ADMIN_JWT_TTL` (default 28800s).
- **`admin_audit` ya existe** y `adminAudit()` (`AdminAuth.php:100`) escribe
  ahí, best-effort. Pero audita la ACCIÓN administrativa (crear plan, tocar
  una empresa), no la CONSULTA — hoy nada llama `adminAudit()` en un GET.

**Lo que falta — y es el corazón del plan:**

1. **Credencial.** `ApiKeyService.php` (realm `api`, ver `context/58` M0)
   nace atada a una empresa y hereda `userId`/`roleId`/`outletId` del
   emisor — no sirve para admin, que es cross-tenant. Hace falta una
   credencial nueva, con pantalla de emisión/revocación en `/admin`.
2. **Los tres guards que el camino admin no tiene.** En el realm `api` del
   MCP de tenant, el read-only (405 fuera de GET/HEAD), el rate limit
   (60/min + 5000/día por key, fail-open) y la auditoría de toda lectura
   viven en `api/bootstrap.php:138-193` y `:303-341`, DENTRO de
   `apiAuthTenant()`. Como `adminMiddleware()` no pasa por ahí, hoy
   `/v1/admin/*` es read-only por costumbre y no por construcción — nada
   impide que una key nueva golpee `companies.php` con un POST.
3. **Catálogo de tools admin.** Nuevo y chico, envoltorios finos sobre
   servicios que ya devuelven el agregado listo. NO reusa
   `frontend/lib/agent/read-tools.ts` (catálogo del MCP de TENANTS) — el
   alcance es categóricamente distinto (D1).
4. **El route.** Dónde vive y con qué garantías de protocolo.

## Decisiones propuestas — falta OK del owner

### D2 [?] — Realm nuevo (no reusar `admin` tal cual)

`auth_session.module` ya separa la sesión interactiva (`module = 'admin'`)
de otras cosas dentro del mismo realm — es un precedente real, pero no
alcanza acá. La razón es la misma que cerró D4 en `context/58` para el MCP de
tenant: si la key naciera con `realm = 'admin'`, heredaría automáticamente
TODO lo que ese realm ya tiene permitido en cada endpoint de
`/v1/admin/*` — incluidas las mutaciones de `companies.php`, `plans.php`,
`platform.php` — sin que nadie lo haya decidido endpoint por endpoint. Con un
realm propio (ej. `admin-api`), cada endpoint opta explícitamente, igual que
`api` hizo para tenants. `module` queda libre para lo que ya usa: distinguir
sub-tipos de sesión dentro de un mismo realm, no para cargar la frontera de
seguridad.

Falta decidir el nombre del realm.

### D3 [?] — Los guards se EXTRAEN, no se copian al camino admin

Esta es la decisión arquitectónica más importante del plan. `adminMiddleware()`
necesita los mismos tres guards que `apiAuthTenant()` ya tiene para el realm
`api` — read-only por método, rate limit de dos ventanas, auditoría de cada
lectura — pero vive en un archivo distinto (`AdminAuth.php`, sin
`api/bootstrap.php` de por medio).

Copiar el bloque de `bootstrap.php:138-193` dentro de `adminMiddleware()` es
el camino corto y el que hay que rechazar: dos copias del mismo guard se
desincronizan apenas alguien ajusta una ventana de rate limit o cambia el
código de error en un solo lugar — ya pasó en este proyecto con los mapas de
labels y el vocabulario de tipos de transacción (regla en
`CLAUDE.md` §5). La solución correcta es extraer los tres guards a una
función compartida (ej. `authGuardProgrammaticAccess($realm, $sessionId)` en
`api/lib/Auth/`) que reciba el realm y el `AUTHED_SESSION_ID` resuelto, y que
tanto `apiAuthTenant()` como `adminMiddleware()` llamen. El realm `admin-api`
entra al mismo guard que el realm `api`, con su propia clave de rate limit y
su propia tabla de auditoría (`admin_audit` en vez de `tenant_audit`, porque
ahí es donde vive el resto del historial administrativo — ver hallazgo
arriba: `adminAudit()` ya existe pero solo audita mutaciones).

### D4 [?] — Catálogo de ~8-10 tools, envoltorios finos sobre lo que ya existe

Aplicando lo aprendido en la F1 del catálogo de tenant (`context/58` M2): las
respuestas se normalizan — enums traducidos, moneda declarada, campos
internos podados — hoy en `frontend/lib/agent/normalize-tool-result.ts`. El
MOTOR de normalización se puede reusar; el DICCIONARIO es otro, porque el
vocabulario es distinto (salud, planes, churn — no ítems ni transacciones).

Set propuesto, uno por servicio que ya existe:

- `get_tenant_health_ranking` — lista semaforeada, peores primero
  (`TenantHealthService::computeAll`/ranking, :176).
- `get_tenant_health_detail` — score + 6 subscores + `signals` de una empresa
  (`getDetail()`, :112).
- `get_business_overview` — estados, MRR, churn del mes
  (`AdminReportsService::overview()`, :97).
- `get_payments` — cobros en un rango (`AdminReportsService::payments()`,
  :472).
- `get_plans` — catálogo de planes y vencimientos (`PlanAdminService`).
- `get_module_adoption` — qué módulos usa cada tenant (`ModuleAdminService`).
- `get_system_status` — salud de infraestructura (`SystemStatusService`).
- `get_admin_audit` — auditoría administrativa reciente
  (`api/v1/admin/audit.php`).

### D5 [?] — Route propio, mismas garantías de protocolo que el de tenant

`/api/mcp-admin` (o nombre equivalente), replicando lo que `context/58` M1 ya
verificó contra un cliente real y que costó dos rondas de debugging
encontrar:

- **Stateless obligatorio**, `McpServer` por request — un server module-level
  compartido filtraría la credencial del primer cliente a todos los demás.
- **GET y DELETE con 405 inmediato**, sin pasar por el transporte — dejarlo
  colgado (SSE que nunca cierra) es peor que rechazar: manda a investigar el
  lugar equivocado.
- **`initialize`/`tools/list` responden sin credencial.** Cualquier 401 en
  el handshake dispara el flujo OAuth del cliente y rompe la conexión — la
  key se exige recién al ejecutar cada tool, como error de tool y no de
  protocolo. Mismo hallazgo verificado contra prod en `context/58`.

## Fases

| Fase | Qué | Depende de |
|---|---|---|
| **A0** | Extraer los tres guards (D3) a función compartida; `apiAuthTenant()` pasa a llamarla, sin cambio de comportamiento para el realm `api` existente | — |
| **A1** | Realm `admin-api` (D2): credencial, pantalla de emisión/revocación en `/admin`, opt-in del realm en `adminMiddleware()` vía el guard de A0 | A0 |
| **A2** | Catálogo de 8-10 tools admin (D4), normalizadas con el motor de `normalize-tool-result.ts` y diccionario propio | A1 |
| **A3** | Route `/api/mcp-admin` (D5), prueba real contra un cliente MCP | A2 |
| **A4** | Auditoría de lectura sobre `admin_audit` cableada en el guard de A0 (hoy solo audita mutaciones) | A0 |

## Preguntas abiertas para el owner

- Si la credencial admin debe expirar más agresivamente que los 365 días de
  las keys de tenant, dado su alcance cross-tenant.
- Si conviene limitar el MCP admin por IP o por algún segundo factor, cosa
  que el de tenant no tiene.
- Si el churn que hoy calcula `AdminReportsService` (bajas por mes) alcanza,
  o hace falta una métrica de churn de ingresos (MRR perdido, no solo
  cuentas).

## Arquitecturas rechazadas — no reintroducir

- **Un solo MCP compartido con el de tenants, con un scope "admin" como
  flag.** Rechazado por el mismo motivo que separó `api` de `panel` en
  `context/58` D4: el allowlist tiene que ser explícito por endpoint, y acá
  además la categoría de riesgo es otra — cross-tenant vs. propio tenant, no
  una gradación del mismo riesgo. Ni el transporte ni el catálogo se
  comparten.
- **Reusar `realm = 'admin'` tal cual para la credencial programática.**
  Rechazado en D2: heredaría todo lo que el realm interactivo ya tiene
  permitido en cada endpoint, sin que nadie lo haya decidido explícitamente.
- **Copiar los guards de `bootstrap.php` dentro de `adminMiddleware()`.**
  Rechazado en D3: dos copias del mismo guard se desincronizan con el tiempo,
  patrón ya visto en este proyecto con los mapas de labels y el vocabulario
  de tipos de transacción.
- **Exponer el detalle de un tenant puntual (ventas, contactos, stock) por el
  MCP admin.** Rechazado en D1 por decisión explícita del owner — eso es
  soporte con motivo declarado, no una API key permanente.
- **Que el catálogo admin reuse `frontend/lib/agent/read-tools.ts`.**
  Rechazado: ese catálogo es del MCP de TENANTS, con datos de negocio de un
  solo comercio — el alcance de D1 lo excluye por definición, no por
  conveniencia técnica.

## Docs relacionados

- `context/58-mcp-server.md` — el MCP de TENANTS, en producción; molde de
  arquitectura (route stateless, McpServer por request, handshake sin
  credencial, rate limit de dos ventanas) que este plan replica del lado
  admin.
- `context/34-admin-saas-plan.md` — F1-F6 del panel `/admin`, de donde salen
  todos los servicios que este plan envuelve.
- `context/55-franquicias.md` — precedente de "agregados sí, datos de negocio
  no" que sostiene D1.
- `context/47-reportes-personalizados-y-export.md` — catálogo declarativo de
  datasets; no es prerequisito de este plan (los servicios de `Admin/` ya
  devuelven el agregado listo), pero es la misma idea que D8 de
  `context/58` aplicó al lado de tenant.
