# Franquicias — el franquiciador supervisa, no manda

> **Estado:** plan cerrado 2026-08-28 (D1–D7 decididas por el owner). Sin
> implementar. La tabla `franchiser_to_tenant` YA existe en prod (mig 08,
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

## 4. Fases

| Fase | Qué entrega | Depende de |
|------|-------------|-----------|
| **F0** | Mig: `status` 0/1/2 documentado + CHECK, y `franchise.supervise.view` en `PermissionCatalog`. | — |
| **F1** | Invitación y aceptación: el franquiciador invita por teléfono, el franquiciado acepta/rechaza/revoca desde su panel. Sin ninguna lectura de datos todavía. | F0 |
| **F2** | `FranchiseSupervisionService` + `/v1/franchise/*`: resuelve el conjunto (D5) y sirve los agregados de D3 desde los rollups. Arnés de aislamiento OBLIGATORIO (ver §5). | F1 |
| **F3** | UI `/franquicias`: listado de la red, ficha por franquicia, comparativa. | F2 |
| **F4** | "Hoy" en vivo (agregado, mismo servicio) y export. | F3 |
| **F5** | Billing: el franquiciador es un add-on o un plan. Producto, no arquitectura. | F3 |

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

## 7. Preguntas abiertas para el owner

1. ¿La invitación la manda el franquiciador, o la crea la plataforma desde
   `/admin`? (F1 asume lo primero.)
2. ¿El franquiciador ve el nombre comercial del franquiciado o un alias que él
   le pone? (Cambia la ficha de F3.)
3. ¿Un franquiciado puede ver que es supervisado por más de uno? (El modelo lo
   permite; la UI de F1 tiene que mostrarlos a todos.)
