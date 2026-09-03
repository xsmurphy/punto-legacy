# Franquicias — el franquiciador supervisa, no manda

> **Estado:** plan cerrado 2026-08-28 (D1–D7 decididas por el owner). Sin
> implementar. **D8 + F6 (acceso por MCP) agregados 2026-09-03**: el owner
> cerró el TECHO (la key ve exactamente lo que autoriza el D3), el resto del
> D8 es propuesta sin su OK. La tabla `franchiser_to_tenant` YA existe en prod (mig 08,
> 0 filas) y su modelo está aceptado en
> [ADR-001](adr/ADR-001-franchiser-tenant-acceso.md) — este doc NO lo relitiga,
> lo lleva al stack nuevo.

## 1. El problema

Varios tenants de Punto son franquiciados de una misma marca. El franquiciador
necesita ver cómo le va a su red. Hoy no tiene por dónde: el legacy tenía
`panel/franchiser.php`, que murió con el panel viejo, y el stack nuevo nunca lo
reimplementó.

**La franquicia NO pertenece al franquiciador** (owner, 2026-08-28). Es una
empresa independiente: su dueño, su plan, su factura, sus datos. El
franquiciador solo la SUPERVISA. Todo lo que sigue existe para que esa frase se
cumpla en el código y no solo en el discurso.

## 2. Lo que ya está decidido y no se toca

De ADR-001 (aceptado 2026-05-26), vigente:

- La relación vive en `franchiser_to_tenant` (N→N): `franchiserId`, `tenantId`,
  `relationType`, `status`. **Es acceso, no propiedad ni billing.**
- Un franquiciado puede ser supervisado por MÁS de un franquiciador (por eso
  N→N y no `company.parentId`, que además solo admite un padre).
- `company.parentId` / `company.isParent` quedan **deprecados para acceso**. Hoy
  `parentId` solo lo escribe `SignupService` cuando el alta trae `?parent=`
  (camino sin consumidor en el front nuevo). No construir nada sobre esas dos
  columnas.
- Propiedad y cobro siguen siendo por-tenant: un franquiciador con 5
  franquicias son **6 cuentas facturadas por separado**.

Lo que ADR-001 daba por hecho y **este doc revierte**: la impersonación. Ver D1.

## 3. Decisiones (cerradas por el owner 2026-08-28)

### D1 — El franquiciador supervisa; NO entra al panel del franquiciado

Ni impersonación, ni "entrar en modo lectura". El franquiciador tiene su propio
espacio (`/franquicias`) con métricas consolidadas de su red, y ahí termina su
alcance.

**Por qué, y no por comodidad:** las otras dos opciones obligan a que cada
endpoint operativo del panel sepa distinguir "operación propia" de "lectura
supervisada". Son ~200 archivos en `api/v1` + `api/lib`; uno solo que no mire el
flag convierte supervisión en operación. El 2026-08-26 un leak cross-tenant real
(el gráfico de ingresos de otra empresa) costó una auditoría completa de auth
— y ese leak NO fue un endpoint mal escrito, fue una cookie con scope de más.
Agregar deliberadamente un camino cross-tenant a la superficie operativa es la
categoría de cambio que ese incidente desaconseja.

Con supervisión agregada, en cambio, **ningún endpoint operativo cambia**: hay
UNA superficie nueva, de solo lectura, sobre datos ya agregados.

### D2 — La fuente son los rollups, no las tablas operativas

`rollup_sales_day`, `rollup_item_sales_day` y `rollup_payments_day` ya existen en
prod (context/18, context/48) y ya están agregados por `companyid, day, outletid,
registerid, userid, kind, status, channel`. El franquiciador lee de ahí y de
ningún otro lado.

Efecto lateral deseado: lo que el franquiciador puede ver queda acotado por la
FORMA del dato, no por la disciplina de quien escribe la query. En un rollup de
ventas por día no hay un cliente, ni una nota de línea, ni un arqueo.

`cogs` está en `rollup_sales_day`: el costo de la franquicia **no** se expone
(ver D3). Es una columna a excluir explícitamente en la proyección, con un test
que lo fije.

### D3 — Qué ve y qué no

Ve (por franquicia, por día/mes, y comparado entre franquicias):

- Ventas: cantidad, unidades, bruto, descuento, neto, impuestos.
- Ranking de productos vendidos (`rollup_item_sales_day`).
- Medios de pago y ticket promedio (`rollup_payments_day`).

No ve:

- Clientes (ni nombres, ni teléfonos, ni deuda).
- Costos, `cogs`, márgenes ni rentabilidad.
- Caja: arqueos, retiros, ingresos, diferencias.
- Usuarios del franquiciado ni su desempeño individual.
- Ningún documento: ni facturas, ni tickets, ni comandas.

Lo que un franquiciador quiera ver de más se discute como cambio de este doc, no
se resuelve en el PR.

### D4 — El franquiciado ACEPTA y puede revocar

El vínculo no lo crea nadie por decreto:

1. El franquiciador invita, por el mismo identificador con el que el
   franquiciado se loguea (teléfono — ver `feedback_phone_format_convention`).
2. La fila nace en `franchiser_to_tenant` con `status = 0` (pendiente).
3. El franquiciado ve la invitación en su panel y acepta o rechaza.
4. `status = 1` habilita la supervisión. El franquiciado puede revocar cuando
   quiera y vuelve a `status = 2` (revocado), sin borrar la fila: el histórico de
   quién supervisó qué y desde cuándo es auditoría.

`status` ya existe en la tabla (`smallint NOT NULL DEFAULT 1`). Los valores
0/1/2 son nuevos y hay que documentarlos en `04-modelo-de-dominio.md`.

**Ojo con el default:** la mig 08 dejó `DEFAULT 1`, o sea que una fila insertada
sin `status` nace ACEPTADA. El alta de invitación tiene que escribir el 0
explícito, y conviene un CHECK que lo obligue.

### D5 — El conjunto supervisable se resuelve server-side, siempre

El id del franquiciador sale de la sesión (`AUTHED_COMPANY_ID`), nunca del
cliente. El conjunto de tenants visibles es:

```sql
SELECT tenantid FROM franchiser_to_tenant
 WHERE franchiserid = :authedCompanyId AND status = 1
```

Un `tenantId` que venga del browser se VALIDA contra ese conjunto antes de
tocar nada; no se usa para construir el filtro. Es la misma regla que ya
gobierna `X-Outlet-Id` en `api/bootstrap.php` (validar pertenencia, y ante la
duda ignorar), y la que faltó en el leak del 26.

### D6 — Es un permiso del tenant, no un realm nuevo

El franquiciador es un tenant normal. Lo que lo habilita es:

- Tener al menos una fila con `franchiserid = <su companyId>` y `status = 1`.
- Que el usuario tenga el permiso nuevo `franchise.supervise.view`
  (grupo "Franquicias" en `PermissionCatalog`).

No hay realm nuevo, no hay cookie nueva, no hay login aparte. Un realm nuevo
significaría otro emisor de sesión, y "emisores divergentes de sesión" es
exactamente la causa raíz que documenta `context/54`.

### D7 — Sin tiempo real

Los rollups son diarios (`rollup_dirty` + reconcile). El panel del franquiciador
muestra hasta el cierre del día anterior, y lo dice en pantalla. "Hoy en vivo"
es una fase aparte (F4) y no bloquea nada: un franquiciador mira tendencias, no
opera la caja.

### D8 [?] — El MCP del franquiciador hereda el techo del panel, y NO es una key cross-tenant

*(propuesta 2026-09-03, sin OK del owner salvo el techo, que sí cerró: la key ve
exactamente lo que autoriza el D3, ni un campo más.)*

El pedido es "que un franquiciador tenga una API key del MCP que le habilite
acceder a todas sus franquicias". Se puede, pero **no** como variante de la key
que ya existe. Dos razones, y la segunda es la que manda.

**La primera es mecánica.** Una key del MCP es hoy una fila de `auth_session`
con `companyid` / `userid` / `roleid` (context/58 M0), y todo endpoint resuelve
por `COMPANY_ID`. No hay ningún eje cross-tenant en el mecanismo. Hacer que un
token valga para varias empresas es exactamente la clase de cambio —una
credencial con alcance de más— que causó el leak del 2026-08-26.

**La segunda es de alcance.** Las tools de lectura del catálogo compartido
(`frontend/lib/agent/read-tools.ts`, expuestas como `punto_get_*`) son lectura
COMPLETA del tenant: contactos, movimientos de finanzas, transacciones, stock,
cajas. El D3 dice que el franquiciador no ve nada de eso. Darle esas tools
sobre su red no relaja el D3: lo elimina.

**La forma correcta no toca la key.** El franquiciador es un tenant normal
(D6), así que su key es una key común de realm `api`, emitida contra SU
`companyId`. Lo que se agrega es un grupo de tools nuevo —`punto_franchise_*`—
que pega contra `/v1/franchise/*`, o sea contra el MISMO
`FranchiseSupervisionService` de la F2, que ya resuelve el conjunto
server-side desde `AUTHED_COMPANY_ID` (D5) y ya proyecta solo lo del D3. El
token nunca deja de ser mono-tenant; el que sabe de varias empresas es el
servicio, como ya lo sabe para el panel.

Consecuencias que hay que respetar al implementarlo:

- **El conjunto se re-resuelve en CADA llamada**, nunca se congela en el `meta`
  de la key. Es lo que hace que el revoke del D4 tenga efecto: un franquiciado
  que pasa a `status = 2` desaparece de la respuesta siguiente, sin rotar nada.
- **Las tools se registran condicionalmente.** El transporte
  (`frontend/app/api/mcp/route.ts`) hoy registra el catálogo entero para toda
  key. Un tenant sin franquicias aceptadas, o cuyo usuario no tiene
  `franchise.supervise.view`, no debe VER las tools de franquicia — no alcanza
  con que devuelvan 403. Un catálogo lleno de tools que siempre fallan le
  enseña al modelo del cliente a ignorar los errores.
- **Permisos ⊆ usuario sigue rigiendo** (context/58 D6): la key hereda
  `franchise.supervise.view` del operador que la emitió, o no lo tiene.
- **Costo por llamada.** Una tool de franquicia abanica sobre N tenants: una
  llamada del cliente son N lecturas de rollup. El rate limit por key de
  context/58 §Rate limit está calibrado para 1 tenant por llamada y hay que
  recalibrarlo, no heredarlo.
- **La auditoría tiene dos lados.** M0 audita los GET del realm `api` en el
  `tenant_audit` del dueño de la key. Acá el franquiciado también tiene que
  poder ver que lo leyeron: si puede revocar (D4), necesita el dato sobre el
  que decide. Propuesta: una fila también en el `tenant_audit` del
  franquiciado. Es lo único de este D8 que agrega escritura nueva.

## 4. Fases

| Fase | Qué entrega | Depende de |
|------|-------------|-----------|
| **F0** | Mig: `status` 0/1/2 documentado + CHECK, y `franchise.supervise.view` en `PermissionCatalog`. | — |
| **F1** | Invitación y aceptación: el franquiciador invita por teléfono, el franquiciado acepta/rechaza/revoca desde su panel. Sin ninguna lectura de datos todavía. | F0 |
| **F2** | `FranchiseSupervisionService` + `/v1/franchise/*`: resuelve el conjunto (D5) y sirve los agregados de D3 desde los rollups. Arnés de aislamiento OBLIGATORIO (ver §5). | F1 |
| **F3** | UI `/franquicias`: listado de la red, ficha por franquicia, comparativa. | F2 |
| **F4** | "Hoy" en vivo (agregado, mismo servicio) y export. | F3 |
| **F5** | Billing: el franquiciador es un add-on o un plan. Producto, no arquitectura. | F3 |
| **F6** | Tools `punto_franchise_*` en el MCP, sobre el mismo servicio de F2 y con el mismo techo del D3. Registro condicional en el transporte. | F2 |

## 5. El arnés no es opcional

F2 no se mergea sin `api/tests/franchise_isolation_test.php`, mismo patrón que
`items_tenant_isolation_test.php` (Postgres descartable, dos tenants reales):

- Un franquiciador con `status = 0` (pendiente) NO ve nada.
- Un franquiciador revocado (`status = 2`) NO ve nada.
- Un franquiciador NO ve a un tenant con el que no tiene fila, aunque mande su
  `tenantId` explícito en el request.
- Un tenant normal (sin filas) que pega a `/v1/franchise/*` recibe 403.
- La proyección NO incluye `cogs` ni ninguna columna de costo.
- El conjunto sale de `AUTHED_COMPANY_ID`, no de un parámetro (chequeo estático,
  como el caso (e) del arnés de items).

Y para la F6, en el mismo arnés:

- Una key de realm `api` de un tenant SIN franquicias aceptadas no ve las tools
  `punto_franchise_*` en el listado del catálogo.
- Una key cuyo usuario no tiene `franchise.supervise.view` tampoco las ve.
- Revocar (`status = 2`) saca al franquiciado de la respuesta de la llamada
  SIGUIENTE, sin rotar ni reemitir la key.
- Las tools de franquicia devuelven la misma proyección que `/v1/franchise/*`:
  el chequeo de `cogs` corre sobre las dos superficies, no sobre una.

## 6. Arquitecturas rechazadas (no volver a proponerlas)

- **`company.parentId` como fuente de acceso** — un solo padre, y mezcla
  propiedad con acceso. Rechazado en ADR-001, sigue rechazado.
- **Impersonación del franquiciador** — ADR-001 la asumía; el owner la descartó
  el 2026-08-28. Contradice "la franquicia no le pertenece".
- **"Entrar en modo lectura"** — ver D1: obliga a que ~200 archivos respeten un
  flag, y uno solo que no lo mire rompe la promesa entera.
- **Realm nuevo para el franquiciador** — ver D6.
- **Leer las tablas operativas con un `IN (tenantIds)`** — es el patrón que
  convierte cada query nueva en una decisión de seguridad. Los rollups acotan
  por forma.
- **Una API key con alcance multi-tenant** — ver D8. El token sigue siendo
  mono-tenant; el que conoce la red es el servicio. Una credencial que vale
  para varias empresas es la forma del leak del 2026-08-26.
- **Exponerle al franquiciador las tools `punto_get_*` del catálogo común** —
  son lectura completa del tenant (contactos, finanzas, stock, cajas) y
  contradicen el D3 entero. Las tools de franquicia son propias y salen del
  servicio de la F2.
- **Un MCP que vea más que `/franquicias`** — evaluado y descartado por el
  owner el 2026-09-03. Dos definiciones de qué puede ver un franquiciador
  divergen sin falta, y la más permisiva sería justo la que no tiene pantalla
  donde auditarla.

## 7. Preguntas abiertas para el owner

1. ¿La invitación la manda el franquiciador, o la crea la plataforma desde
   `/admin`? (F1 asume lo primero.)
2. ¿El franquiciador ve el nombre comercial del franquiciado o un alias que él
   le pone? (Cambia la ficha de F3.)
3. ¿Un franquiciado puede ver que es supervisado por más de uno? (El modelo lo
   permite; la UI de F1 tiene que mostrarlos a todos.)
4. ¿El franquiciado ve en su panel que el franquiciador lo leyó por MCP, y con
   qué grano? (D8, último punto. Sin esto, "puede revocar" es una decisión sin
   dato sobre el que decidir.)
5. ¿La F6 entra en el mismo add-on de la F5, o el acceso por MCP se cobra
   aparte? (Cambia solo el producto, no la arquitectura.)
