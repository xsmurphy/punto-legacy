# ADR-001: Relación franquiciador→tenant como acceso N→N (tabla puente)

**Estado:** Aceptado
**Fecha:** 2026-05-26
**Deciden:** xstian (producto/arquitectura) + Claude

## Contexto

Multi-tenant sobre la tabla `company` (PostgreSQL). Hoy la relación
franquiciador→tenant se modela con `company.parentId` (auto-referencial, **1→N**)
+ el flag `company.isParent`. Se usa solo en `panel/franchiser.php` y
`panel/mainFranchiser.php` (~6 queries `WHERE parentId = COMPANY_ID`).

Existen **dos jerarquías distintas** de gestión/impersonalización:
- **SaaS super-admin** (`panel/main.php`, `COMPANY_ID == ENCOM_COMPANY_ID`): gestiona e
  impersona **cualquier** company.
- **Franquiciador** (un tenant con `isParent`): gestiona e impersona **solo sus** tenants.

Dos fuerzas obligan a revisar el modelo:
1. **Propiedad y cobro son por-tenant e independientes.** Cada company (padre o hijo)
   tiene su propio dueño, plan y facturación. Un padre con 5 hijos = **6 cuentas
   facturadas por separado**. La relación franquiciador **NO** confiere propiedad ni billing.
2. Se necesita que un tenant hijo pueda ser accesible por **varios** franquiciadores
   (**N→N**), cosa que `parentId` (un solo padre) no soporta.

## Decisión

Separar **propiedad/billing** (per-tenant, en `company`, sin cambios) de la
**relación de acceso/gestión** (franquiciador→tenant), y modelar esta última como una
**tabla puente N→N** `franchiser_to_tenant` — puramente de **acceso**, no de propiedad.

```
franchiser_to_tenant
  franchiserId  uuid   → company.companyId   (el tenant que gestiona)
  tenantId      uuid   → company.companyId   (el tenant gestionado)
  relationType  text   (ej: 'manager' | 'owner-group' | 'partner')   -- opcional, default 'manager'
  status        smallint  default 1
  createdAt     timestamptz default now()
  UNIQUE (franchiserId, tenantId)
  index (franchiserId), index (tenantId)
```

- **Migración:** crear la tabla + backfill desde el modelo actual:
  `INSERT ... SELECT companyId AS tenantId, parentId AS franchiserId FROM company WHERE parentId IS NOT NULL`.
  `company.parentId` queda **deprecado para acceso** (puede subsistir un tiempo como
  dato denormalizado; la fuente de verdad de acceso pasa a ser la junction).
- **`company` (dueño, plan, billing) no se toca.**
- **Impersonalización:** un franquiciador puede entrar a un tenant **sii**
  `EXISTS(SELECT 1 FROM franchiser_to_tenant WHERE franchiserId = COMPANY_ID AND tenantId = ?)`.
  El SaaS-admin (encom) sigue pudiendo entrar a cualquiera (sin junction).
- **JWT (`_jwt_panel`):** al impersonar, **re-emitir** el JWT con el `cid`/`oid` del tenant
  + claim de auditoría `imp` = id del franquiciador real. Hoy el JWT **NO** se reemite
  (`getCompanyLoginSession` y `franchiser.php` solo cambian la sesión PHP) → el BFF/API
  nuevos, que scopean por el JWT, siguen viendo la empresa del login. Es un **bug** a
  resolver junto con esto.

## Opciones consideradas

### Opción A — `franchiser_to_tenant` (acceso N→N) — ELEGIDA
| Dimensión | Evaluación |
|-----------|------------|
| Complejidad | Baja (tabla + backfill + swap de ~6 queries) |
| Soporta N→N | Sí |
| Acopla billing | No (billing queda en `company`, independiente) |

**Pros:** soporta multi-padre; separa acceso de propiedad; superficie chica; backfill trivial.
**Cons:** un join más en el listado de franquiciador; hay que migrar las ~6 queries.

### Opción B — seguir con `company.parentId` (1→N)
**Pros:** cero cambios. **Cons:** **no** soporta que un tenant tenga varios franquiciadores. Descartada por el requisito N→N.

### Opción C — N→N con co-propiedad/billing compartido
**Pros:** modelo "de grupo" unificado. **Cons:** contradice el requisito de **cobro per-tenant independiente**; introduce conflictos de quién factura/manda. Descartada.

## Trade-off principal

Pagamos un join y una migración chica a cambio de: (a) soportar multi-franquiciador, y
(b) mantener limpio el invariante "cada tenant es dueño/factura lo suyo". El billing
nunca se mezcla con el acceso.

## Consecuencias

- **Más fácil:** que varios franquiciadores gestionen un mismo tenant; auditar accesos
  (la junction + claim `imp`); razonar sobre billing (siempre per-tenant).
- **Más difícil / a revisitar:** las ~6 queries de `franchiser.php`/`mainFranchiser.php`
  pasan a join; hay que mantener la junction sincronizada al crear/borrar tenants;
  decidir cuándo se elimina `company.parentId` del todo.
- **Seguridad:** la autorización de impersonalización pasa a depender de la junction.
  Aprovechar para cerrar el hueco actual de `franchiser.php` (valida `parentId` contra un
  valor del **URL**, no de la sesión).

## Action Items

1. [ ] Migración PG: crear `franchiser_to_tenant` + backfill desde `company.parentId`.
2. [ ] Registrar la entidad en `context/04-modelo-de-dominio.md` (+ invariante: acceso ≠ propiedad).
3. [ ] Swap de queries en `franchiser.php` / `mainFranchiser.php` (`parentId = ?` → junction).
4. [ ] Re-emit del JWT al impersonar (helper único en los 2 puntos de entrada) + claim `imp`
       + autorización por la junction; arreglar el `$p`-desde-URL de `franchiser.php`.
5. [ ] (Futuro) deprecar/eliminar `company.parentId` una vez que nada lo lea para acceso.
