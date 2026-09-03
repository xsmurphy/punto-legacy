# Sucursales, outlet scope y realms

Doc canónico de cómo Punto resuelve "¿de qué sucursal son estos datos?" según
el realm (panel / pos-app / device) y el selector global del panel. Consolida
lo que estaba disperso en `10-roadmap.md` §"Selector de sucursal" (ahora
implementado — ver drift al final).

## 1. El selector global del panel (view-scope)

Dropdown del logo en el sidebar. Cambia qué sucursal(es) ven los reportes y
listados de solo-lectura del panel, SIN tocar el outlet activo del JWT
(que sigue gobernando las escrituras).

```
frontend/hooks/use-view-scope.ts   localStorage["punto.viewOutletScope"]
        │  null | "all" | <uuid>
        ▼
frontend/lib/api-client.ts         header X-Outlet-Id: <valor>  (solo si != null)
        │
        ▼
api/bootstrap.php  (realm panel)   define('VIEW_OUTLET_ID', '' | <uuid>)
        │
        ▼
api/lib/Reports/Roc.php::build()   VIEW_OUTLET_ID gana sobre $outletId del endpoint
```

| Valor view-scope | Header | `VIEW_OUTLET_ID` | Efecto en `Roc::build` |
|---|---|---|---|
| `null` (no elegido) | no se manda | no se define | usa el `$outletId` que pasa el endpoint (default: `oid` del JWT) |
| `"all"` | `X-Outlet-Id: all` | `''` | omite el filtro `outletId` — consolida todas las sucursales |
| `<uuid>` | `X-Outlet-Id: <uuid>` | `<uuid>` (validado contra `companyId` del JWT) | fuerza ese outlet, ignora el del JWT |

Detalles clave (código real):
- `use-view-scope.ts`: persiste en localStorage, sync entre tabs vía evento
  `storage`, expone `readViewScope()` (lectura síncrona no-React, usada por
  `api-client`) y el hook `useViewScope()`.
- `api-client.ts` (línea ~107-112): solo agrega el header en el browser
  (`typeof window !== "undefined"`), y solo si `scopeRaw` no es vacío.
- `bootstrap.php` (línea ~215): el header **solo se procesa para
  `realm === 'panel'`** — el POS no puede mandar `X-Outlet-Id`. Valida que el
  UUID pertenezca al `companyId` del token; si no, lo ignora en silencio
  (defense-in-depth, no rompe la sesión).
- `Roc::build()` (docblock propio): centraliza el fragmento
  `AND companyId=… [AND outletId=…]` para ~21 endpoints de reports. El
  override de `VIEW_OUTLET_ID` se aplica ahí, no en cada endpoint.

> Desde 2026-09-02 hay un TERCER estado además de "una sucursal" y "todas":
> el consolidado ACOTADO a un conjunto (`VIEW_OUTLET_IDS` → `IN (...)`), que
> hoy solo usa el realm `api`. Ver §4.5.

## 2. Resolución de outlet por realm

| Realm | Fuente del outlet | Puede el cliente override-ar? |
|---|---|---|
| **panel** (reportes/dashboards vía Roc) | view-scope (`X-Outlet-Id`) si está seteado → si no, `oid` del JWT | Sí, view-scope es explícitamente para esto |
| **panel** (CRUDs simples: espacios, etc.) | `outletId` de body/query que manda el frontend | Sí — hoy sin integración con view-scope (ver §5, drift) |
| **pos-app** (device) | fila `device` resuelta del token (Bearer) — `outletScope` en el endpoint | **No** — cualquier `outletId` del body/query se ignora o se valida contra el device |
| **screen / kds / display** (device) | ídem pos-app: outlet de la fila `device` | No |

Patrón canónico `outletScope` (ver `api/v1/spaces.php` y
`api/v1/orders-core.php`):

```php
$outletScope = $isPosApp ? $outletId : null;
// pos-app: $outletScope = uuid del device → todo query/mutation se fuerza a eso
// panel:   $outletScope = null → el service usa el outletId que venga del request
```

Todo `WHERE`/`INSERT` de esos services recibe `$outletScope` y, si no es
`null`, ignora o valida contra el `outletId` del body/query. Esto es lo que
hace inmune al POS a un `outletId` falsificado en el payload del cliente.

## 3. Qué datos son por-sucursal vs por-tenant

Tabla actualizada contra el código (corrige drift de `10-roadmap.md` §294-334,
que estaba desactualizada — ver §6).

| Dato | Scope | Cómo filtra |
|---|---|---|
| Contactos | Tenant (no filtra por outlet) | — |
| Items / catálogo | **Híbrida** (decisión owner 2026-08-22): la página de Artículos (`app/(panel)/items/page.tsx`) respeta el view-scope — "Todas" ve el tenant completo, una sucursal puntual ve solo lo asignado a ella + lo global (`outletId IS NULL`). El resto de los consumers de `/v1/items` (picker de Compras, receta de combos, agente IA) siguen viendo el catálogo COMPLETO siempre — ver §5 "Endpoint compartido" | `outletVisibilityClause()` en `api/lib/Items/ItemsQuery.php:319`, mismo criterio que ya usaba `pos-app`. Opt-in por query param `?respectViewScope=1` (`api/v1/items.php`), NO ambiente por el header — ver el comentario largo ahí para el porqué |
| Settings (taxonomías, empresa) | Tenant | — |
| Transacciones / ventas | Sucursal | `Roc::build` (panel) / `outletScope` (pos-app) |
| Stock / inventario | Sucursal | `Roc::build` / `outletScope` |
| Cajas / drawers | Sucursal | `outletScope`, device siempre trae su outlet |
| Espacios (mesas) | Sucursal | `outletScope` obligatorio, invariante sector-obligatorio |
| Órdenes | Sucursal | `outletScope` (`orders-core.php`) |
| Reportes (ventas, dashboard) | Sucursal, override-able con "Todas" | `Roc::build` + view-scope |
| Producción | Sucursal | mismo patrón `outletScope` que espacios/órdenes |
| Cuentas financieras (`fin_account`) | **Híbrida**: `outletid` NULL = cuenta global del tenant; UUID = cuenta de esa sucursal | `72_finance.sql` línea 29: `outletid uuid — null = cuenta global (todas las sucursales)`. `finance/movements.php` y `checks.php` toman `OUTLET_ID` del contexto cuando el body no lo manda, pero no fuerzan exclusión de cuentas globales |

## 4. Invariante cliente-por-realm (2026-07-19)

**Un cliente HTTP = un realm.** No mezclar credenciales.

- `frontend/lib/api-client.ts` (docblock íntegro, líneas 14-23): panel, cookie
  `_jwt_panel`, `credentials: "include"`. Prohibido agregar fallback a Bearer.
- `frontend/lib/api/pos-client.ts` / `pos-fetch.ts`: pos-app, Bearer explícito
  del device.
- `api/includes/auth_session.php` (líneas 111-127): el resolver único
  `authResolve()` prioriza **Bearer → cookies**. Si un cliente panel
  adjuntara el Bearer del device como fallback, la request de panel se
  autentica como device → **outlet scope equivocado**. Cita del docblock:
  > "el fallback de Bearer automático que [...] autenticaban como DEVICE →
  > outlet scope equivocado" — bug real: espacios creados en la sucursal del
  device, no la elegida en el panel.
- El mismo archivo loguea (líneas 154-174) cuando llegan credenciales
  **válidas de dos realms distintos** en la misma request — señal de cliente
  mal configurado, no debería pasar en uso normal.

## 4.5 Dirección decidida — el scope sale de las sucursales ASIGNADAS al usuario

**Decisión del owner, 2026-08-30. Implementada para el realm `api` el
2026-09-02; PENDIENTE para `panel`.**

El modelo decidido no es un permiso de "ver consolidado", sino:
**el acceso a la info de cada sucursal se define por los permisos del usuario Y
las sucursales que tiene asignadas.** El consolidado es la UNIÓN de sus
sucursales asignadas.

La convención de alcance (fuente de verdad `contact_outlet`, mig 66; CERO filas
= usuario GLOBAL; **nunca** la columna legacy `contact.outletid`) vive en
`api/lib/Outlets/OutletScope.php`. Antes estaba solo dentro de
`UsersService::rosterForOutlet()`.

Qué quedó hecho (realm `api` — API keys y MCP):

- `bootstrap.php` deriva el conjunto del USUARIO dueño de la key y lo deja en
  `VIEW_OUTLET_IDS`, además de acotar `OUTLET_ID` a ese conjunto (los lectores
  que bindean la constante sin pasar por `Roc` quedaban fuera del alcance).
- `Roc::build` estrenó el tercer estado: además de UNA sucursal o TODAS, emite
  `IN (...)` para el consolidado ACOTADO.
- Override por `?outletId=` validado contra el conjunto; una sucursal ajena da
  **403, nunca una lista vacía**. `X-Outlet-Id` NO se ensanchó: sigue siendo
  exclusivo de `panel`.
- Los cinco endpoints que bindean un outlet único sin pasar por `Roc` (stock,
  dashboard, cashflow, open_invoices, balance) usan `OutletScope::single()` y
  cortan con 422 accionable cuando el alcance es un subconjunto de 2+.
- Arnés: `api/tests/run_outlet_scope_test.sh` (31 checks, Postgres real).

**Lo que sigue pendiente**: el realm `panel`. Hoy `X-Outlet-Id: all` valida que
la sucursal pertenezca al tenant pero **no mira al usuario**, así que cualquiera
con permiso sobre un reporte lo sigue viendo consolidado de TODAS las sucursales
(P2 de la auditoría del 2026-08-26). Falta también la UI de asignación en
`/settings/team`. El mecanismo ya está construido: aplicarlo a `panel` es
alimentar `VIEW_OUTLET_IDS` desde `OutletScope::forUser()` también en esa rama
y decidir qué hace el selector cuando el usuario alcanza un subconjunto.

Interactúa con franquicias (`context/55`): un franquiciador supervisando a sus
franquiciados es el mismo problema un nivel arriba.

## 5. Reglas para código nuevo (checklist)

**Página nueva del panel que opera datos por-sucursal:**
- [ ] Inicializar el outlet desde el view-scope global (`useViewScope()` /
      `readViewScope()`), igual que `chat/page.tsx` y
      `use-dashboard-widget.ts` ya hacen.
- [ ] Si la página permite un override local (ej. un filtro propio), debe
      sincronizarse con el view-scope, no vivir aislado — hoy `espacios`
      (`settings/espacios/page.tsx`) NO lee el view-scope; es deuda pendiente,
      no el patrón a copiar.

**Endpoint nuevo multi-realm:**
- [ ] `outletScope` obligatorio para realms de device (`pos-app`, `screen`,
      `kds`) — resuelto de la fila `device`, nunca del body/query.
- [ ] Para panel: usar `Roc::build()` si es un reporte de lectura (hereda
      view-scope gratis); si es un CRUD, aceptar `outletId` de query/body
      explícito.
- [ ] Nunca confiar en `outletId` del body para un device — validar o
      ignorar, igual que `spaces.php`/`orders-core.php`.

**Endpoint compartido por una página "vista por sucursal" Y por pickers
cross-outlet (caso `/v1/items`, 2026-08-22):**
- [ ] Si el mismo endpoint alimenta tanto una página que debe obedecer el
      view-scope (ej. listado de Artículos) como selectores usados en OTROS
      flujos que necesitan ver el catálogo/dato completo sin importar la
      sucursal elegida (ej. picker de ítems en Compras o en la receta de un
      combo), el filtro de view-scope va **opt-in por query param explícito**
      (`?respectViewScope=1` en `/v1/items`), nunca ambiente solo porque
      `api-client.ts` ya manda `X-Outlet-Id` en TODAS las requests del panel.
      Ambiente rompe los pickers cross-outlet como efecto colateral apenas
      alguien fija una sucursal en el dropdown del logo.
- [ ] Solo la página/hook que debe obedecer el scope pasa el flag; el resto
      de los call-sites del mismo hook se quedan en el default (sin scope).

## 6. Drift encontrado vs `10-roadmap.md` §294-334

La sección "Selector de sucursal en menú del usuario (NUEVO 2026-06-12)"
describía la feature como **pendiente de implementar**. Ya está implementada
(`use-view-scope.ts` + `X-Outlet-Id` + `Roc::build`, 2026-06-13). Esa sección
del roadmap quedó obsoleta y debería marcarse resuelta o moverse a archive.

## 7. Referencias cruzadas

- `frontend/hooks/use-view-scope.ts` — fuente del view-scope.
- `frontend/lib/api-client.ts` — invariante de realm panel + envío del header.
- `api/bootstrap.php` (líneas ~180-233) — resolución de outlet por realm +
  procesamiento del override.
- `api/lib/Reports/Roc.php` — filtro compartido de reportes.
- `api/includes/auth_session.php` — resolver único de sesión, invariante
  Bearer vs cookie.
- `api/v1/spaces.php`, `api/v1/orders-core.php` — patrón `outletScope`
  canónico para endpoints multi-realm.
- `api/database/migrations/postgres/72_finance.sql` — `fin_account.outletid`
  nullable = cuenta global.

## Qué NO cubre este doc

Permisos por rol (quién puede ver/editar qué dentro del scope ya resuelto) —
ver `RoleService`/`PermissionCatalog`, `context/08-convenciones-criticas.md`
§50.
