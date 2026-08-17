# 24 — Sucursales y scopes

> Estado del doc: borrador (verificado contra código leyendo fuente, sin correr nada)
> Responsable de la última verificación: sesión 2026-08-17

## 1. Qué resuelve

De qué sucursal (`outlet`) son los datos que una request lee o escribe.
Punto es multi-sucursal por tenant (`Company → Outlet → Depósito/`location`
→ Caja/`register``), y casi todo dato operativo (stock, ventas, cajas,
mesas, órdenes) pertenece a UNA sucursal. Sin un mecanismo único para
resolver "¿cuál?", cada endpoint decidiría distinto y el resultado ya rompió
producción una vez (espacios creados en la sucursal de la caja en vez de la
elegida en el panel, 2026-07-19).

## 2. Entidades y datos

| Tabla/columna | Qué guarda | Invariantes / trampas |
|---|---|---|
| `outlet` | Sucursal del tenant. | `outletStatus` filtra activas; el fallback de `bootstrap.php` (regla 2) toma la de menor `outletId` entre las activas — no hay noción de "sucursal default" explícita, es implícita por orden. |
| `device.outletid` / `device.registerid` | Fuente de verdad de la sucursal/caja de un dispositivo POS pareado. | `bootstrap.php` (`:168-183`) los lee de ESTA fila en cada request de realm `pos-app`, nunca de un claim del token — un token viejo no puede traer un outlet desactualizado. |
| `auth_session.outletId` | Sucursal fijada al crear la sesión (panel: resuelta al login; pos-app: la del device en ese momento). | Para pos-app es informativo — el outlet operativo real se re-resuelve desde `device` en cada request (fila de arriba), no desde esta columna. |
| `fin_account.outletid` (mig `72_finance.sql:29`) | Cuenta financiera. `NULL` = cuenta global del tenant (todas las sucursales); UUID = cuenta de esa sucursal. | Único dato "híbrido" confirmado — el resto de tablas por-sucursal no tienen este patrón nullable-como-global. `finance/movements.php`/`checks.php` no fuerzan excluir cuentas globales al filtrar por outlet (NO VERIFICADO si eso produce mezcla en algún reporte). |

## 3. Reglas de negocio

1. **Jerarquía Company → Outlet → Depósito (`location`) → Caja (`register`)**. El stock se contabiliza a nivel Outlet por defecto (`stock.outletId`, ver `05-stock.md`); los depósitos (`toLocation`/`location`) son una sub-partición DENTRO del stock de la sucursal, no un scope independiente ni aditivo — moverlo entre depósitos de la misma sucursal no cambia el saldo total de esa sucursal.
2. **Resolución de `outletId` por realm — nunca confía en el request para device.** `bootstrap.php:168-233` (dentro de `apiAuthTenant()`): para `pos-app` con `deviceId`, `outletId`/`registerId` se leen de la fila `device` (`:169-175`); para `panel`, `outletId` viene de `AUTHED_OUTLET_ID` (columna de `auth_session`, fijada al login/cambio de sucursal) y `registerId` se fija a `''` incondicional (`:189`, ver `23-auth-y-permisos.md` regla 8). Si `outletId` queda vacío en cualquiera de los dos casos, un fallback común (`:194-200`) toma la primera sucursal activa del tenant por `outletId ASC`. Patrón canónico de scope hacia los services, usado en `spaces.php`/`orders-core.php`: `$outletScope = $isPosApp ? $outletId : null` — para pos-app se fuerza SIEMPRE ese outlet (ignora/valida cualquier `outletId` del body); para panel, `null` delega en el `outletId` explícito de query/body del request (regla 5).
3. **View-scope — override de solo-lectura, exclusivo de realm panel, no toca las escrituras.** Selector del dropdown del sidebar (`frontend/hooks/use-view-scope.ts`, persistido en `localStorage["punto.viewOutletScope"]`) manda header `X-Outlet-Id` (`frontend/lib/api-client.ts:99-114`) solo si el valor no es `null` (sin elegir = sin header = comportamiento default). `bootstrap.php` procesa el header y define la constante `VIEW_OUTLET_ID` **solo si `$realm === 'panel'`** (`:215`) — el POS no puede mandarlo. `'all'`/`''` → `VIEW_OUTLET_ID=''` (consolida todas las sucursales); un UUID se valida contra `companyId` del token antes de aceptarse (`:220-231`) — si no pertenece al tenant, se ignora EN SILENCIO (comentario explícito `:229-230`: "defense-in-depth, no rompemos la sesión"). `Roc::build()` (`api/lib/Reports/Roc.php:39-53`) es el único punto que lee `VIEW_OUTLET_ID`, y cuando está definida GANA sobre el `$outletId` que el endpoint le pasó (`:44-46`) — así los ~25 endpoints de `reports/*.php` heredan el selector sin tocar cada uno. `OUTLET_ID` (la constante base del JWT/sesión) NO se modifica por este mecanismo — las escrituras (venta, caja) siguen atadas a la sucursal activa de la sesión, nunca a lo que el dropdown de solo-lectura muestre.
4. **`Roc::build()` es el único choke point de aislamiento por-sucursal en reportes — 25 call-sites confirmados**, todos pasando `(string) OUTLET_ID` (o un `$effectiveOutletId` derivado) como segundo argumento (`grep -rn "Roc::build(" api` → 25 en `api/v1/reports/*.php` + `purchases.php`). `companyId` es obligatorio y se valida como UUID con `throw` si no lo es (`:41-43`) — no hay forma de que un reporte corra sin filtro de tenant. `outletId` es **opcional por diseño**: si no matchea el regex UUID (incluida cadena vacía), el fragmento SQL omite el `AND outletId=...` y el reporte devuelve TODAS las sucursales de esa company (`:49-51`) — el mecanismo que hace funcionar "Todas las sucursales" del view-scope (regla 3) es el MISMO camino que se dispara si `OUTLET_ID` llega vacío por cualquier otro motivo. Confirmado que eso puede pasar: si un tenant se queda con CERO sucursales activas, el fallback de `bootstrap.php:194-200` no encuentra ninguna fila y `OUTLET_ID` queda `''` — todo reporte de ese tenant, sin que el usuario haya tocado el selector, mostraría (vacío, porque no hay outlets, pero) sin filtro de outlet. Es un edge case degenerado (tenant sin sucursales activas es un estado roto en sí mismo), no una fuga cross-tenant — `companyId` sigue aplicado siempre — pero es el mismo patrón de "outletId vacío = sin filtro" que un bug en la resolución de arriba podría disparar con datos reales.
5. **CRUDs simples del panel (no-reporte) usan el `outletId` que manda el body/query, sin pasar por `Roc::build()` ni por el view-scope.** Ejemplo verificado: `spaces.php` — `$outletScope = $isPosApp ? $outletId : null` (`:32`); para panel, si el caller no manda `outletId` explícito, el endpoint responde `422 outletId requerido` (`:49-50,77-78`) en vez de asumir un default — NO cae a "todas" ni al outlet de la sesión. Este patrón (exigir el parámetro, nunca inferirlo en silencio) es el que evita que un panel sin integración con view-scope opere la sucursal equivocada; `context/25-sucursales-y-scopes.md §5` documenta que hoy NO todas las páginas del panel inicializan ese parámetro desde el view-scope global (deuda declarada, no relevada de nuevo en esta sesión).
6. **`TenantContext` (namespace `Punto\Api\Context`, `api/lib/Context/TenantContext.php`) NO permite `outletId` vacío — el constructor lanza `InvalidArgumentException` si `companyId`, `outletId` o `userId` llegan como `''`** (`:27-29`). 30 endpoints lo instancian vía `TenantContext::fromAuth()`. Esto es lo opuesto de "opcional": cualquier endpoint que pase por acá y reciba un `outletId` vacío (el edge case degenerado de la regla 4) corta con una excepción no capturada (500) en vez de operar sin scope — falla cerrado, no abierto. Los endpoints de `reports/*.php` (que usan `Roc::build()` directo, sin `TenantContext`) son los que SÍ pueden quedar sin filtro de outlet en silencio ante ese mismo edge case (regla 4) — la garantía depende de qué mecanismo use cada endpoint, no es uniforme en todo `/api`.
7. **Todo dato multi-tenant bindea `companyId`.** No se encontró ningún camino en `Roc::build()`, `TenantContext` o los `outletScope` de `spaces.php`/`orders-core.php` que omita el filtro de `companyId` — a diferencia de `outletId`, `companyId` siempre viene de la sesión autenticada (`AUTHED_COMPANY_ID`), nunca de un parámetro del cliente, y `Roc::build()` aborta con `RuntimeException` si no matchea el formato UUID (regla 4). NO VERIFICADO exhaustivamente fuera de estos tres mecanismos — no se auditaron los ~60 endpoints de `api/v1/*.php` uno por uno buscando un `WHERE` sin `companyId`.

## 4. Flujos principales

**Cambiar de sucursal en el panel** — `active-outlet.php` valida que el outlet elegido pertenezca al `companyId` del token y actualiza `AUTHED_OUTLET_ID` de la sesión activa (mecanismo exacto no re-relevado en esta sesión, ver `PanelAuth::issuePanelSession` para el análogo en login, `outletIdOverride`). Afecta las ESCRITURAS futuras de esa sesión.

**Cambiar el view-scope (dropdown del logo)** — client-side puro: `use-view-scope.ts` escribe `localStorage["punto.viewOutletScope"]` y sincroniza entre tabs vía evento `storage`; no pega al backend por sí mismo. El efecto se ve en la PRÓXIMA request de reporte, que manda `X-Outlet-Id` y pasa por `Roc::build()` (regla 3). Solo afecta LECTURAS de `reports/*`.

**Request del POS (device)** — `apiAuthTenant(['pos-app'])` ignora cualquier `outletId` que el body/query intenten mandar; lo fuerza desde `device.outletid` (regla 2). Un `outletId` falsificado en el payload del cliente no tiene efecto — es el mecanismo que hace "inmune" al POS a esa clase de ataque (documentado también en `spaces.php`/`orders-core.php`).

**Reporte con "Todas las sucursales"** — el mismo código path que un tenant con cero outlets activos (regla 4): `Roc::build()` omite el filtro. La diferencia es la intención — acá es explícita (usuario eligió "Todas"), en el edge case es un accidente de configuración.

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| Auth y permisos (`23-auth-y-permisos.md`) | `authResolve()`/`apiAuthTenant()` entrega `outletId`/`registerId` ya resueltos antes de que este módulo actúe. | Que la resolución por realm (regla 2) es la ÚNICA fuente — ningún endpoint debería re-derivar el outlet de un claim propio. |
| Stock (`05-stock.md`) | Lee/escribe `stock` scopeado a `(itemId, outletId)`. | Que el `outletId` que llega ya está validado contra el tenant — stock no revalida pertenencia. |
| Reportes (`reports/*.php`, 25 endpoints) | Delegan el filtro `companyId`/`outletId` a `Roc::build()`. | Que ningún reporte nuevo arme su propio fragmento `WHERE outletId=...` a mano — repetir el patrón sin pasar por `Roc` reintroduce el riesgo que motivó centralizarlo (P2 de code-review citado en el docblock de `Roc.php`). |
| Espacios / Órdenes (`12-espacios.md`, `11-ordenes-y-comandas.md`) | `outletScope` obligatorio para pos-app, exigido explícito (422) para panel. | Que un endpoint nuevo de escritura siga el mismo patrón (`spaces.php`/`orders-core.php`) en vez de leer `OUTLET_ID` de la sesión en silencio cuando el panel opera "para otra sucursal". |
| Finanzas (`fin_account`) | Cuenta puede ser global (`outletid IS NULL`) o de sucursal. | Que los reportes de finanzas no mezclan sin querer una cuenta global con el total de una sucursal — NO VERIFICADO (regla, tabla §2). |

## 6. Offline

No aplica de forma diferenciada a este módulo más allá de lo ya cubierto en
`23-auth-y-permisos.md §6`: el device POS resuelve su `outletId` desde la
fila `device` en cada request (nunca localmente/offline), y la resolución de
scope en sí no tiene una ruta "sin red" — ocurre server-side cuando la
request (encolada o no) llega a `bootstrap.php`.

## 7. Huecos conocidos y NO verificado

- **Edge case de tenant sin sucursales activas → `Roc::build()` sin filtro de outlet** (regla 4) — confirmado por lectura de código, no reproducido en runtime; impacto acotado (no cruza `companyId`) pero el patrón "vacío = sin filtro" es el mismo que protegería una fuga real si `OUTLET_ID` se vaciara por un bug de resolución.
- **Falta de uniformidad entre `Roc::build()` (falla abierto ante `outletId` vacío) y `TenantContext` (falla cerrado con excepción)** — mismo dato, dos garantías distintas según qué mecanismo use el endpoint. No relevado si hay un tercer patrón adicional en el codebase.
- **Páginas del panel sin integración con view-scope** (`context/25 §5`, no re-verificado en esta sesión — se cita como deuda ya documentada, ej. `settings/espacios/page.tsx`).
- **NO VERIFICADO**: si `fin_account.outletid IS NULL` (cuenta global) puede mezclarse sin distinción con cuentas de sucursal en algún reporte de `finance/reports.php`/`summary.php`.
- **NO VERIFICADO**: el flujo completo de `active-outlet.php` (cambio de sucursal activa en panel) — se citó por nombre pero no se leyó línea por línea en esta sesión.
- **NO VERIFICADO**: si algún endpoint fuera de `reports/*` y de los `outletScope` de espacios/órdenes arma su propio filtro de outlet sin pasar por ninguno de los dos mecanismos — no se auditaron los ~60 archivos de `api/v1/*.php` uno por uno con ese objetivo específico.

## 8. Planes y decisiones relacionados

- `context/25-sucursales-y-scopes.md` — doc previo (nivel raíz de `context/`), con el mismo contenido a nivel arquitectura; este doc lo actualiza al estándar de `context/modules/` con evidencia línea por línea re-verificada.
- `context/modules/23-auth-y-permisos.md` — resolución de identidad/realm que antecede a todo scope de sucursal.
- `context/modules/05-stock.md` — consumidor directo del scope de outlet para el saldo de inventario.
