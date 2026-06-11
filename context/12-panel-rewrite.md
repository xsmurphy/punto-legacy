# Plan — Rewrite del panel a React + shadcn (greenfield)

> **Creado:** 2026-06-10. **Decisión del usuario:** el panel legacy MUERE.
> Se reescribe desde cero en React/Next + shadcn, usando `/panel` como guía
> funcional pero sin mantener el legacy en paralelo.

---

## Contexto

El backend `/api` compartida quedó listo en F2 (commits `c4d3231..a8c12a1`):
endpoints REST para outlets, settings, contacts, items, bootstrap, vpayments,
+ 21 reportes. Todo con `apiAuthTenant(['panel'])` o `(['panel','pos-app'])`
para los compartidos con /app. Eso significa que un cliente React puede pegar
hoy contra `/api` sin esperar nada más del backend.

El plan original (F3 oleadas legacy → F4 shell `@.php` → F5 congelar `panel/API/`)
**queda CANCELADO en lo que respecta al panel**. F3/F4/F5 eran para transformar
el panel legacy a la nueva forma; si el panel se reescribe, esa transformación
no aplica — el legacy se borra entero al final.

**Lo que SÍ sigue vivo del plan F3 original**: portar a `/api` los handlers
in-process del legacy que el nuevo panel necesite (cada vez que un slice React
necesite una operación que hoy está en `panel/a_X.php?action=Y`, ese handler
se porta a `/api/v1/X` antes — no como F3 separado, sino como dependencia del
slice React correspondiente).

---

## Stack

| Componente | Tecnología | Razón |
|---|---|---|
| Framework | **Next.js 15 (App Router)** | SSR + route handlers como BFF nativo en TS; cero PHP en el front nuevo |
| Lenguaje | **TypeScript estricto** | end-to-end con `/api` (generamos tipos del OpenAPI o manualmente) |
| UI | **shadcn/ui + Tailwind CSS** | componentes accesibles, código tuyo (no dependencia), DX |
| Data | **TanStack Query (v5)** | cache + invalidación + mutations + optimistic UI |
| Forms | **react-hook-form + zod** | validación tipada, integración con shadcn `<Form>` |
| Routing | Next App Router | file-based, shared layouts, parallel routes |
| Estado UI | Zustand (si hace falta) | global lightweight |
| Testing | Vitest + React Testing Library | velocidad |
| Bundler | Next interno (Turbopack) | sin config |
| Deploy | Coolify (container Node aparte) | Dockerfile multi-stage; misma red que `/api` |

**Lo que NO uso:**
- Vite + React Router puro (el SSR de Next ahorra el discovery de auth client-only)
- Mustache/jQuery/Alpine del legacy (greenfield = nada del legacy)
- PHP en el panel nuevo
- BFFs PHP del actual `panel/bff/` — los route handlers de Next los reemplazan

---

## Coexistencia durante la migración

Durante el rewrite, ambos paneles deben coexistir para no parar el desarrollo
de quien use Punto. **NO hay feature-freeze formal** porque el usuario decidió
que el legacy no se va a tocar — pero el sistema necesita seguir funcionando
hasta que el nuevo lo cubra al 100%.

**Routing por subdominio (Cloudflare + Coolify):**

| Subdomain | Apunta a | Estado |
|---|---|---|
| `panel.punto.la` | nuevo Next.js | default desde el día 1 del slice outlets |
| `panel-legacy.punto.la` | PHP actual (`/panel`) | escape para módulos no migrados todavía |
| `api.punto.la` | `/api` compartida (PHP) | sin cambios |
| `app.punto.la` | POS legacy (`/app`) | sin cambios; el POS no se reescribe |

**Auth compartida:**
- Cookie `_jwt_panel` se emite sobre `.punto.la` (no sobre subdomain específico).
- El usuario logueado en `panel.punto.la` puede saltar a `panel-legacy.punto.la`
  sin re-loguear durante la migración.
- Login y signup también van al nuevo Next desde el día 1 (no requieren backend
  nuevo — pegan a `/api/v1/login` y `/api/v1/signup` existentes).

**Cuando el nuevo panel cubre el 100%:**
- `panel-legacy.punto.la` se borra.
- `/panel/` se elimina del repo.
- `panel/API/v1/admin/` (último viviente) se considera para migrar a `/api` también.
- `panel/bff/` desaparece.

---

## Estructura propuesta del repo

```
/panel-next/                       ← carpeta nueva
  app/
    (auth)/
      login/page.tsx
      signup/page.tsx
    (panel)/
      layout.tsx                    ← shell con sidebar + nav (shadcn)
      page.tsx                       ← dashboard
      outlets/
        page.tsx                     ← lista
        [id]/page.tsx                ← detalle/edit
        new/page.tsx                 ← create
      settings/page.tsx
      contacts/...
      items/...
      reports/[slug]/page.tsx
    api/                              ← route handlers (BFF nativo Next)
      auth/[...].ts
      proxy/[...].ts                 ← proxy genérico a /api con _jwt_panel
  components/
    ui/                              ← shadcn primitives (botón, card, dialog, …)
    layout/                          ← Sidebar, Topbar, Breadcrumbs
    domain/                          ← <OutletForm>, <ItemTable>, …
  lib/
    api-client.ts                    ← wrapper fetch + auth + error handling
    types/                           ← types generados de /api (TS interfaces)
    auth.ts                          ← sessión Next (server actions)
  hooks/                             ← useOutlets, useContacts, …
  middleware.ts                      ← gate de auth
  tailwind.config.ts
  next.config.ts
  Dockerfile
  package.json
```

El repo monorepo queda con: `/api` (PHP), `/app` (POS PHP), `/panel-next` (nuevo), `/panel` (legacy — se borra al final).

---

## Orden de slices

Cada slice ≈ 1 sprint chico (3-5 días, dev solo). El orden busca validar la
cañería con un módulo simple y subir complejidad gradualmente.

| # | Slice | Backend | Front | Notas |
|---|-------|---------|-------|-------|
| 0 | **Sprint 0 — Scaffold** | nada | Next + Tailwind + shadcn vacío + Dockerfile + Coolify deploy | 3-4 hs. Solo levanta. |
| 1 | **Auth flow** | endpoints existentes (`/api/v1/login`, `/api/v1/signup`, `_jwt_panel` cookie) | login.tsx, signup.tsx, middleware auth | Reusa cookie de `.punto.la`; sesión compartida con legacy. |
| 2 | **Layout + Dashboard básico** | `/api/v1/bootstrap` | shell sidebar + topbar shadcn, dashboard con KPIs | El dashboard real (widgets) viene más tarde; acá solo el shell. |
| 3 | **Outlets** | `/api/v1/outlets` (ya migrado) | lista + create + edit + archive | El más simple. Valida CRUD + form pattern. |
| 4 | **Settings** | `/api/v1/settings` (ya migrado) | general + templates + currencies + taxonomies | Schema-heavy, tabs. |
| 5 | **Contacts** | `/api/v1/contacts` (ya migrado, multi-realm) | lista + create + edit + addresses sub-resource | Primer slice grande. |
| 6 | **Items** | `/api/v1/items` (ya migrado, multi-realm) | base + stock + compounds + locations | XL. Partir en 2-3 PRs. |
| 7 | **Reports** | 21 endpoints ya migrados | una page por reporte, dataTable + charts | Volumen rápido. |
| 8 | **Dashboard real** | `/api/v1/reports/dashboard` + widgets | 17 widgets con TanStack Query | Reutiliza components reports. |
| 9+ | **Resto del legacy** | hay que portar handlers a `/api` por slice | purchases, transactions, modules, billing, schedule, production, …  | Cada uno lleva su slice de backend portage primero. |

**Estimación total**: 4-6 meses dev solo, sin big-bang. El usuario puede usar
`panel-legacy.punto.la` para lo no migrado mientras tanto.

---

## Sprint 0 — Checklist concreto (3-4 hs)

1. `mkdir panel-next && cd panel-next`
2. `npx create-next-app@latest . --typescript --tailwind --app --no-src-dir --import-alias "@/*"`
3. `npx shadcn@latest init` (configuración: New York style, neutral base, CSS variables)
4. Componentes iniciales: `npx shadcn@latest add button card form input label dialog dropdown-menu sidebar`
5. Crear `lib/api-client.ts` con fetch wrapper que mande `Authorization: Bearer ${cookie._jwt_panel}` a `https://api.punto.la/v1/...`
6. `Dockerfile` multi-stage (build + runtime con `next start`)
7. `next.config.ts`: `output: 'standalone'` para slim docker
8. Levantar localmente, hit a `/api/v1/bootstrap` con cookie real → verificar 200
9. Push a un branch `panel-next-init`, deploy en Coolify como container `panel-next`, subdomain temporal `panel-next-dev.punto.la`
10. Verificar que `/api/v1/bootstrap` responde a través del cliente desde el container Node

Si esos 10 pasos terminan en 4hs, la cañería está validada y los slices reales
empiezan recto.

---

## Reglas de la nueva sesión

0. **`panel/a_<modulo>.php` = referencia funcional obligatoria de cada slice.** Antes de empezar cualquier slice, leer el módulo legacy equivalente entero (vista + handlers `?action=*`) y catalogar: campos del form, columnas de tabla, filtros, acciones, validaciones, permisos, integraciones cross-módulo. El legacy lleva años de iteración con clientes reales — saltearse esa lectura garantiza perder features que ya usan. La VISUAL no se replica (Linear-inspired + shadcn la reemplaza), pero el comportamiento funcional sí. Si algo en el legacy parece bug o cruft, preguntar antes de "limpiarlo".

1. **No tocar el panel legacy salvo bug crítico de seguridad.** Cero features nuevas. Bug fixes se pasan al `/panel-next` cuando el módulo correspondiente migra.
2. **No tocar `/api` salvo para bug fixes o para portar handlers que el `/panel-next` necesita.** El backend está al 97% listo.
3. **No mover lógica de negocio al cliente React.** El cliente formatea, valida y muestra. La lógica de negocio (tenant scoping, business rules, money path) vive en `/api`.
4. **TypeScript estricto desde día 1.** `strict: true`, sin `any` salvo en boundary points temporales documentados.
5. **shadcn copy-paste, no dependencia.** Los componentes shadcn viven en `components/ui/` como código tuyo — los editás libremente.

5.1. **shadcn-first siempre.** Todo componente UI se compone de primitives shadcn — nunca custom widgets ni libs UI alternativas. Patrones canónicos: date range picker = `<Calendar mode="range" />` + `<Popover>`; phone input = `<InputGroup>` + `<DropdownMenu>` (país) + `<Input>` + `libphonenumber-js`; command palette = `<Command>` + `<CommandDialog>`; toasts = `<Toaster>` + `toast()` de sonner ya envuelto; bottom-sheet mobile = `<Drawer>` (vaul envuelto); forms = `<Form>` + react-hook-form + zod. Si falta un primitive: `npx shadcn@latest add <componente>`. Si es composición: vive en `components/forms/` o `components/inputs/` usando primitives. NUNCA reinventar widgets que shadcn ya cubre.

5.2. **Teléfonos: front nacional, back E.164, `libphonenumber-js` siempre.** El usuario ve y tipea el número en formato nacional sin "+" (ej PY: `0981 612 192`). El backend recibe y devuelve E.164 (`+595981612192`). Conversión y validación SIEMPRE via `libphonenumber-js` — `parsePhoneNumber(input, country).format('E.164')` al submit, `isValidPhoneNumber(input, country)` para validar. NUNCA regex manual ni concatenar códigos a mano. Default país = "PY" hasta que la sesión indique otro. Componente reusable: `components/forms/phone-input.tsx` envolviendo `<InputGroup>` con dropdown de países (bandera + dial code) + `<Input>` libre.
6. **Tests obligatorios para el money path.** Forms de items, transacciones, billing — Vitest + React Testing Library.
7. **Cada slice cierra con un PR review** (code-reviewer subagent) antes de mergear a main.
8. **Decisiones arquitectónicas → este archivo.** No proliferar `docs/`. Si la decisión cambia, se actualiza este doc.

---

## Decisiones pendientes para la primera sesión

- **Tema de shadcn**: New York vs Default. Color base (Neutral / Stone / Zinc / …). Dark mode default sí/no.
- **Brand colors** del panel: hoy es `#01D7A1` (rebrand reciente). Se replica en CSS variables Tailwind.
- **Fonts**: Source Sans Pro (current panel) vs Inter (shadcn default) vs Geist.
- **i18n del nuevo panel**: el legacy tiene `es`, `en`, `pt`. El nuevo arranca `es` y agrega después? next-intl o paraglide?
- **Manejo del POS** (`/app`): ¿también se reescribe a React eventualmente o se queda en PHP? Decisión separada — el POS tiene modo offline complejo, no es prioridad.

---

## Documentos de referencia para el desarrollo

- `context/01-producto.md` — modelo de negocio
- `context/04-modelo-de-dominio.md` — schemas y entidades
- `context/07-glosario.md` — vocabulario (sucursal=outlet, depósito=location, caja=register)
- `context/11-design-system.md` — sistema de diseño actual (los colores/clases que se mantienen)
- `/panel/a_*.php` — guía funcional del legacy (qué hace cada módulo)
- `/api/v1/` + `/api/lib/` — backend disponible

---

## Track paralelo — Hardening del backend para 2000+ clientes

La `/api` actual tiene buenos cimientos (PSR-4, multi-realm auth, PostgreSQL +
JSONB, multi-tenant scoping por código) pero **NO está lista para 2000 clientes
sin trabajo adicional**. Esta sección lista lo que falta para escalar, en orden
de prioridad. Va EN PARALELO al rewrite del front — no antes, no después.

**Importante**: el orden NO bloquea slices del front. El front puede arrancar
contra la API actual; el hardening se aplica conforme la cantidad de clientes
reales crece.

### Issues estructurales detectados

1. **Cero tests automatizados del money path** (transactions, inventory, billing).
   El smoke test F2 que cazó 3 bugs cluster cubre CRUD básico, NO business
   logic. Una regresión silenciosa en stock cuesta plata real a escala.
2. **Stock con 4 fuentes de verdad** (`stock` ledger, `stockTrigger` cache,
   `inventory` batches, `toLocation` sub-partición) sin invariante PG que las
   una — el drift es inevitable con volumen. Ver plan I0-I6 en sección
   "Análisis del módulo de inventario" del roadmap.
3. **`manageStock` duplicado** (`/app/Domain/Inventory.php` ≡
   `panel/includes/functions.php`). Misma deuda class que los 3 bugs cluster
   cazados hoy (Query::update, DB.php divergido, ncmInsert routing).
4. **Multi-tenant scoping solo en código, sin RLS de PostgreSQL**. Un Service
   que olvide `WHERE companyId = ?` = cross-tenant data leak. Para 2000
   tenants hay que auditar todo o activar RLS como segunda barrera.
5. **Sin connection pooling** (`php -S` 8 workers abren conexión PG nueva por
   request).
6. **Sin cache de queries hot** (Redis solo para sessions). `bootstrap`,
   `settings`, `plans` se piden a PG en cada page load.
7. **Sin OpenAPI spec** → tipos TS del front se escriben a mano o se valida
   con zod en cada response.
8. **Sin observability formal** (logs estructurados, métricas por tenant,
   alertas, tracing). Sentry está pero no estructurado.
9. **God-functions del panel** (`panel/includes/functions.php` 10k líneas)
   que /api necesita — portage por demanda cuando un slice React lo requiere.
10. **Sin background jobs** — emails / webhooks / SIFEN bloquean el request.

### Plan priorizado

| # | Item | Esfuerzo | Trigger / cuándo |
|---|------|----------|------------------|
| H1 | **OpenAPI spec + tipos TS generados** | 1 sem | Antes del primer slice React real (después del Sprint 0) |
| H2 | **Tests del money path** (PHPUnit / Vitest contra /api) | 2-3 sem | En paralelo al rewrite del front. Sin esto no se mete a prod con 100+ clientes |
| H3 | **Observability**: logs estructurados JSON + métricas por tenant + alertas | 1 sem | Antes de 200 clientes |
| H4 | **pgbouncer** delante de Postgres + connection pooling | 2 días | Antes de 100 clientes concurrentes |
| H5 | **Auditoría tenant scoping**: grep + script que verifica `companyId` bindeado en TODOS los SELECT/UPDATE/DELETE de /api/lib | 1 sem | Antes de 500 clientes |
| H6 | **Cache Redis** para queries hot: `bootstrap`, `settings.general`, `plans`, `taxonomies` (TTL 60-300s, invalidación en write) | 1 sem | Cuando bootstrap p95 > 200ms |
| H7 | **Background jobs**: SIFEN async, emails async, webhooks async, billing recurrente (BeanstalkD / Redis Queue / cron) | 2 sem | Cuando aparezca el primer request timeout en prod |
| H8 | **Row-Level Security PG** sobre `companyId` (defense-in-depth, no reemplaza scoping en código) | 2 sem | Opcional pero recomendado pre-1000 clientes |
| H9 | **Consolidar `/shared/`**: DB.php + JsonbRouter helpers a un solo lugar | 3 días | Cualquier momento — bajo riesgo, alto valor |
| H10 | **Refactor módulo inventario** (plan I0-I6 del roadmap) | 6-8 sem | Post-MVP del nuevo panel, cuando el front esté estable |

**Estimación total**: ~3-4 meses calendario en paralelo al rewrite del front
(dev solo / mitad de tiempo). Para 2000 clientes alcanza si se ejecuta en
orden — no requiere arquitectura distribuida. Un solo PHP + un solo Postgres
bien tuneado lleva 2000 tenants con holgura.

### Lo que NO está en el plan (porque NO hace falta para 2000)

- Microservicios / arquitectura distribuida — sobrecarga sin beneficio a esta escala
- Reescribir `/api` de PHP a Node/Go — `/api` PHP está bien, el rewrite es el front
- Database sharding / multi-region — innecesario para 2000 tenants en un solo Postgres con pgbouncer
- Kubernetes — Coolify + Docker simple alcanza
- Event sourcing / CQRS — overkill; lo que hace falta es invariantes constraint en el ledger de stock
- Migrar `/app` (POS) también a algo nuevo — decisión separada del panel rewrite

### Métricas que disparan acción

| Métrica | Threshold | Acción |
|---|---|---|
| Clientes activos | > 50 | Empezar H1+H2 (OpenAPI + tests money path) |
| Clientes activos | > 100 | Activar H3+H4 (observability + pgbouncer) |
| Clientes activos | > 200 | Empezar H5+H6 (audit + cache) |
| Clientes activos | > 500 | Empezar H7 (background jobs) |
| Clientes activos | > 1000 | Empezar H8 (RLS) |
| Latencia bootstrap p95 | > 200ms | H6 sin esperar threshold de clientes |
| Cualquier reporte de drift de stock | inmediato | H10 sin esperar |
| Cualquier reporte de cross-tenant data | inmediato | H5+H8 simultáneo, P0 |
