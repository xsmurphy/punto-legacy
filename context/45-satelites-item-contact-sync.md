# 45 — Ítem y contacto como raíces de sync: tablas satélite

> Estado (2026-08-17): **implementado** (mig 139, `api/database/migrations/
> postgres/139_satelite_touch_parent.sql`). Ver §Resultado al final del doc
> para el detalle de lo que se corrigió del boceto, lo que quedó afuera y
> lo que sigue pendiente de payload. Regla general que el owner extrajo
> mid-sesión a partir del caso puntual de `context/44
> -listas-de-precio-offline.md` (overrides de precio por lista). Reemplaza
> ese caso puntual — ya NO es una excepción de precios, es el modelo
> entero para toda tabla que cuelgue de `item`/`contact`.

## La regla (textual del owner)

> "cualquier modificación a un dato relacionado a un item o a un cliente
> dispara la recarga de ese item o cliente, no importa el dato, recarga el
> item o cliente completo. Por ej si entro a modificar una de las 5
> direcciones de un cliente, en todos los POS se vuelve a recargar entero
> ese cliente; si modifico un precio en una lista asociada a un artículo o
> modifico un número en la receta de ese artículo, se dispara el update del
> artículo entero"

`item` y `contact` son las ÚNICAS dos entidades con delta-por-fila +
lápida de borrado (`item.updatedAt`/`contact.updatedAt` + trigger
`fn_record_deletion()`, mig 138, `context/43-sync-incremental.md`). Toda
tabla satélite que cuelgue de ellas, al cambiar, bumpea el `updated_at` del
padre — así el mecanismo que YA existe (delta + lápidas) cubre TODO sin
watermarks nuevos por tabla satélite.

## Inventario de tablas satélite (relevado por FK en el schema, no de memoria)

Búsqueda: `REFERENCES item(itemId)` / `REFERENCES contact(contactId)` en
`db-schema-postgres.sql` + `api/database/migrations/postgres/*.sql`.
Criterio de inclusión: la tabla describe/configura AL PADRE mismo (como
`price_list_item` describe el precio del ítem) — no cualquier FK. Una FK de
AUDITORÍA/ATRIBUCIÓN (quién hizo algo — `userId`, `supplierId`,
`responsibleId` en `transaction`/`purchase`/`drawer`/etc.) no es
"satélite": esas tablas no describen al contacto referenciado, lo
mencionan de paso.

## Decisión: el VÍNCULO es satélite, la ENTIDAD no (owner, 2026-08-16)

Pregunta del owner: *"si cambio el nombre de una categoría no debería disparar
un update a todos los items que tienen esa categoría, correcto?"*. Correcto, y
marca la línea que separa las dos cosas:

- **`item_category` / `item_brand` / `item_tag`** — el VÍNCULO: a qué categoría
  pertenece este ítem. Cambiarlo ES un cambio del ítem → trigger, bumpea el
  padre. Afecta a un ítem por vez.
- **`category` / `brand` / `tag` / `tax`** — la ENTIDAD y su nombre. Son pocas
  filas, viven en el bundle `settings` que se recarga entero. Renombrar una
  categoría **NO** debe bumpear ningún ítem: sería descargar 5.000 ítems en
  cada dispositivo por un cambio de texto.

✅ **Resuelto (2026-08-16)**: `PosItem` ya NO lleva `categoryName`/`brandName`
copiados — solo `categoryId`/`brandId`. El bootstrap del POS
(`/api/pos/bootstrap`) suma dos listas propias al bundle `settings`:
`PosBootstrap.categories: PosCategory[]` y `.brands: PosBrand[]`
(`frontend/lib/types/pos-bootstrap.ts`), traídas de `/v1/categories` y
`/v1/brands` (que ahora aceptan también el realm `pos-app` en GET, mismo
patrón que `/v1/taxes`/`/v1/payment-methods`). El nombre se resuelve contra
esas listas con `frontend/lib/catalog/resolve-names.ts`
(`useCategoryBrandMaps()` + `resolveCategoryName()`/`resolveBrandName()`) —
mismo criterio que ya usaba el carrito para `PosItem.taxId` contra la lista
de impuestos (`pos-bootstrap.ts` comentario de `PosTaxRate`).

Efecto colateral conservado a propósito: una categoría sin productos ahora
existe para la caja (antes se derivaba de los items presentes y una
categoría vacía era invisible).

El bloqueante para implementar los triggers de este documento queda
levantado — renombrar una categoría/marca ya no requiere re-bajar los
ítems que la usan.

Regla general que sale de esto: **un dato que pertenece a otra entidad no se
copia dentro del ítem; se referencia por id**. Cualquier campo desnormalizado
nuevo en `PosItem` reabre este problema.

### Satélites de `item` (candidatas a trigger)

| Tabla | Qué describe | Migración |
|---|---|---|
| `item_image` | Imágenes del producto | `17_item_image.sql` |
| `item_compound` | Receta (parent/child + cantidad) | `19_item_compound.sql` |
| `item_category` | Asignación a categoría(s) | `16_item_category.sql` |
| `item_brand` | Asignación a marca | `22_brand.sql` |
| `item_tag` | Asignación a etiqueta(s) | `39_tag.sql` |
| `itemLocation` | Depósitos donde "vive" el ítem | `05_item_location.sql` |
| `combo_group` / `combo_group_item` | Composición de combo | `20_combo_group.sql` |
| `addon_group` / `addon_group_option` | Grupos de add-ons (F1-F5, context/41) | `134_addon_groups.sql` |
| `price_list_item` | Override de precio por lista | `32_price_lists.sql` (el caso que originó este doc) |
| `pack_component` | Composición de un pack | `31_pack_services.sql` |

**Evaluadas y EXCLUIDAS a propósito** (alta frecuencia, mismo criterio que
ya excluye `transaction`/`drawer`/`expense` del watermark de `settings` en
`context/43` — "ruido de alta frecuencia ajeno al catálogo estático"):
`sold_pack`/`sold_pack_usage` (canje de pack, transaccional — una venta),
`voucher_item` (vale emitido, transaccional), `document_remision_item`
(remito, transaccional), `production_order`/`waste_event` (evento de
producción/merma, transaccional). Ninguna de estas "configura" el ítem —
registran un EVENTO sobre el ítem, con volumen comparable a `transaction`.

**Ya cubierta por otro mecanismo, no necesita trigger nuevo:** `stock`
(movimientos de inventario) — `Inventory::manageStock()` YA bumpea
`item.updatedAt` explícitamente (`updateRowLastUpdate('item', ...)`,
`api/lib/App/Domain/Inventory.php:764`) en su único choke point. Un
trigger genérico sobre `stock` DUPLICARÍA ese bump (inofensivo pero
redundante) — no sumarla a la lista de triggers nuevos.

**No relevada por el owner, encontrada acá, JUICIO PENDIENTE:**
`cRecordValue` (`db-schema-postgres.sql:698`, valores de formularios
custom por contacto — ver abajo, es de `contact` no de `item`). Baja
prioridad/uso incierto (formularios custom, feature poco visible) — listada
por completitud, no priorizada.

### Satélites de `contact` (candidatas a trigger)

| Tabla | Qué describe | Migración/ubicación |
|---|---|---|
| `customerAddress` | Direcciones del cliente (caso literal del owner) | `db-schema-postgres.sql:642` (`87_customer_address_extend.sql` la extiende) |
| `contactNote` | Notas del cliente | `db-schema-postgres.sql:712` |
| `cRecordValue` | Valores de formulario custom por contacto | `db-schema-postgres.sql:698` |

`contact` también tiene FKs de tipo `parentId`/`userId` con semántica
distinta según la fila (empresa matriz de una sucursal-cliente, vendedor
asignado) — son columnas de `contact` MISMO, no satélites, no aplica acá.

## Diseño: UN trigger genérico y parametrizado (no una función por tabla)

Mismo patrón que `fn_record_deletion()` (mig 138) — recibe la entity y la
columna PK por `TG_ARGV`, una sola función, N triggers declarados:

```sql
CREATE OR REPLACE FUNCTION fn_touch_parent() RETURNS TRIGGER AS $$
DECLARE
  parent_table   TEXT := TG_ARGV[0]; -- 'item' | 'contact'
  parent_pk_col  TEXT := TG_ARGV[1]; -- nombre de la PK del padre (itemId/contactId)
  fk_col         TEXT := TG_ARGV[2]; -- columna FK en la tabla satélite que apunta al padre
  fk_value       UUID;
  old_fk_value   UUID;
BEGIN
  IF TG_OP = 'DELETE' THEN
    EXECUTE format('SELECT ($1).%I', fk_col) INTO fk_value USING OLD;
  ELSE
    EXECUTE format('SELECT ($1).%I', fk_col) INTO fk_value USING NEW;
  END IF;

  EXECUTE format('UPDATE %I SET updatedAt = now() WHERE %I = $1', parent_table, parent_pk_col)
    USING fk_value;

  -- UPDATE que cambia el padre (ej. mover un override de un ítem a otro):
  -- bumpear TAMBIÉN el padre viejo, no solo el nuevo.
  IF TG_OP = 'UPDATE' THEN
    EXECUTE format('SELECT ($1).%I', fk_col) INTO old_fk_value USING OLD;
    IF old_fk_value IS DISTINCT FROM fk_value THEN
      EXECUTE format('UPDATE %I SET updatedAt = now() WHERE %I = $1', parent_table, parent_pk_col)
        USING old_fk_value;
    END IF;
  END IF;

  RETURN NULL; -- AFTER trigger
END;
$$ LANGUAGE plpgsql;

-- Un trigger por tabla satélite, ej.:
CREATE TRIGGER trg_price_list_item_touch_item
AFTER INSERT OR UPDATE OR DELETE ON price_list_item
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemId', 'itemId');

CREATE TRIGGER trg_customer_address_touch_contact
AFTER INSERT OR UPDATE OR DELETE ON customerAddress
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('contact', 'contactId', 'customerId');
```

(Boceto — el `EXECUTE format(...) USING` dinámico para leer una columna
arbitraria de `NEW`/`OLD` vía `to_jsonb()` puede simplificarse; validar
sintaxis exacta contra Postgres real antes de migrar, no asumida acá.)

## Cuidados (ninguno saltear)

- **`DELETE` usa `OLD`, el resto usa `NEW`** — cubierto en el boceto de
  arriba.
- **`UPDATE` que cambia el padre bumpea LOS DOS** (padre viejo y nuevo) —
  cubierto arriba. Caso real: reasignar una dirección de un contacto a
  otro (fusión de contactos duplicados, si existiera ese flujo).
- **Recursión / trabajo inútil**: el trigger toca la tabla PADRE
  (`item`/`contact`), no la satélite — no hay recursión (el UPDATE sobre
  `item` no dispara este mismo trigger, que está en la tabla satélite). Sí
  hay que evitar bumpear si la fila no cambió realmente — un `UPDATE` que
  reescribe la fila con los MISMOS valores (ej. el bulk-replace de
  `price_list_item`, que hace DELETE+INSERT completo aunque un solo ítem
  cambió) va a bumpear TODOS los ítems de la lista, no solo el que cambió
  — aceptable (mismo costo que ya asumía el diseño anterior de este doc) o
  no, a decidir: si `setItems()` pasara a un upsert selectivo en vez de
  DELETE+INSERT, el trigger por sí solo ya sería preciso sin cambios.
- **Consistencia de reloj — CRÍTICO, no verificado esta sesión.** El
  trigger solo puede usar `now()` de Postgres. `SyncService::watermarks()`
  devuelve `serverTime` con `TODAY` de PHP (`context/43`, ya auditado y
  corregido para los writes DIRECTOS de `item`/`contact` — ver "Consistencia
  de reloj" en ese doc). Un trigger que corre en la MISMA transacción de
  Postgres que el resto del request usa el reloj de la DB, que es la
  fuente que YA se verificó consistente contra `TODAY` (mismo servidor,
  mismo `pg_conn`). Igual, **hay que verificarlo explícitamente para este
  trigger en particular** antes de mergear — no asumir por analogía. Caso
  de prueba: bumpear un satélite, leer `item.updatedAt` resultante, pedirle
  el delta a `/v1/sync` con `since` = ese mismo instante menos épsilon, y
  confirmar que el ítem aparece (no un desfase que lo deje afuera).
- **Amplificación de escritura**: `stock` ya está cubierto por otro
  camino (ver tabla de arriba) — NO sumarle un trigger nuevo, duplicaría el
  `UPDATE` en cada venta con tracking de inventario. El resto de las
  satélites de la lista son de escritura baja/media (ediciones de catálogo
  desde el panel, no un evento por venta) — sin problema de volumen
  esperado, pero no medido con datos reales de prod (mismo bloqueo de
  acceso que `context/44`).

## Payload: bootstrap/bulk-get/delta tienen que compartir shape

`api/lib/Items/ItemsQuery.php` YA es la única fuente que usan `api/v1/
items.php` (bootstrap + bulk-get) Y `api/v1/sync.php` (delta) — un solo
lugar que arma el SELECT + reshape de `PosItem`. Esto es una buena noticia
estructural: sumar precios-por-lista/receta/add-ons al payload del ítem
en `ItemsQuery.php` UNA vez los propaga a los tres caminos sin que puedan
divergir por accidente — no hay que "acordarse" de mantenerlos iguales.
Falta confirmar lo mismo para `contact` (¿hay una `ContactsQuery`
equivalente y única, o el bootstrap arma el shape de `PosCustomer` en un
lugar distinto del bulk-get/delta de `contact`?) — no verificado esta
sesión.

## Relación con `context/44`

`context/44-listas-de-precio-offline.md` §Decisión 1 es la instancia
CONCRETA de esta regla general para `price_list_item`. Ese doc mantiene el
detalle específico de precios (motor espejo TS/PHP, resolución local); este
doc es la regla de sync que lo hace posible, generalizada al resto de
satélites de `item`/`contact`.

## Resultado (implementado 2026-08-17)

Migración `139_satelite_touch_parent.sql`. Un trigger genérico
`fn_touch_parent()` (mismo patrón TG_ARGV que `fn_record_deletion()`, mig
138), parametrizado con soporte para FK directa (la mayoría de las tablas)
o indirecta vía un join opcional (`addon_group_option`, ver abajo).

### Tablas con trigger

**`item`**: `item_image`, `item_compound` (solo `parentItemId` — ver
"Ambigüedades resueltas"), `item_category`, `item_brand`, `item_tag`,
`itemLocation`, `addon_group`, `addon_group_option` (indirecta vía
`groupId`), `price_list_item`, `pack_component` (solo `packItemId`).

**`contact`**: `customerAddress`, `contactNote`.

### Ambigüedades que el boceto no resolvía — corregidas contra Postgres real

1. **`item_compound`/`pack_component` tienen DOS FKs a `item`** (padre e
   insumo/componente). Solo la columna que describe al padre
   (`parentItemId`/`packItemId`) dispara el bump — el insumo referenciado
   (`childItemId`/`componentItemId`) es un producto con su propia ficha;
   participar en la receta ajena no lo cambia. Sin esto, cambiar la receta
   de un producto habría bumpeado también sus insumos sin motivo.
2. **`addon_group_option.itemId` NO es el dueño del grupo** — es el
   producto que se agrega como opción (un ítem ajeno). El dueño real se
   resuelve vía `groupId → addon_group.itemId` (join en el trigger). Un
   boceto ingenuo que leyera `itemId` directo de esta tabla habría
   bumpeado el ítem EQUIVOCADO (la opción, no el producto que tiene el
   grupo).
3. **Reloj — el hallazgo central de esta sesión.** `item.updated_at`/
   `contact.updated_at` son TIMESTAMPTZ, pero NINGÚN write del codebase los
   escribe con `now()` de Postgres — usan `TODAY` (`api/data.php`) o
   `TenantClock::now($companyId)` (`api/lib/Support/TenantClock.php`),
   ambos "hora actual en la timezone CONFIGURADA DEL TENANT, como naive
   string". `api/includes/db.php` fija la sesión de Postgres a
   `SET TIME ZONE 'America/Asuncion'` SIEMPRE — el naive string se
   reinterpreta vía esa sesión fija al guardarse, y el mismo mecanismo
   aplica al leer (`SyncService::watermarks()->serverTime` = `TODAY`). Un
   trigger con `now()` a secas (el boceto original) rompe esa igualdad
   para cualquier tenant con timezone distinta — y existe un fixture real
   así: `api/lib/Sales/verify_chain/seed.sql` tiene un tenant con
   `settingTimeZone: "America/Mexico_City"`.

   Verificado contra Postgres real (Docker, `postgres:16-alpine`, schema +
   migraciones completas + fixtures de `verify_chain`):
   - Con `fn_tenant_wall_clock(companyId)` (`now() AT TIME ZONE tz` del
     tenant, mismo contrato que `TenantClock::now()`): un write directo al
     ítem A (simulando `ItemService::update()`, TODAY del tenant MX) antes
     de un watermark, y un cambio de satélite del ítem B (mi trigger)
     después del mismo watermark → el delta trae SOLO B, nunca A. Correcto.
   - Contraprueba con el boceto original (`now()` crudo) y un tenant
     ADELANTADO a Asunción (`Europe/Madrid`, +5h en el momento de la
     prueba): un write con `now()` crudo ocurrido DESPUÉS de capturado el
     watermark tenant-aware **no aparece en el delta** — se pierde en
     silencio. Reproducido, no teórico.
   - `fn_tenant_wall_clock()` con `settingTimeZone` inválida/corrupta cae a
     `America/Asuncion` sin abortar la transacción del caller (mismo guard
     que `TenantClock::timezone()`).

4. **No-op real**: `to_jsonb(OLD) IS NOT DISTINCT FROM to_jsonb(NEW)` en
   UPDATE evita bump en upserts idempotentes (ej. `ON CONFLICT DO UPDATE`
   con los mismos valores).
5. **Mover un satélite entre padres bumpea LOS DOS.** Probado con
   `price_list_item.itemId` reasignado de un ítem a otro: ambos quedan con
   `updated_at` más nuevo que antes del move.

### Excluidas — decisión explícita, no olvido

- **`stock`**: `Inventory::manageStock()` ya bumpea `item.updatedAt` en su
  choke point — un trigger acá duplicaría el UPDATE en cada línea de cada
  venta. Confirmado con `pg_trigger`: la tabla `stock` no tiene ninguno de
  los triggers de esta migración.
- **`combo_group`/`combo_group_item`** (mig 20): deprecadas desde F5 (mig
  136, `context/41`) — el panel ya no monta el editor, `SaleService` nunca
  las consultó, y sus datos ya fueron copiados a `addon_group`/
  `addon_group_option` (que sí llevan trigger). Bumpear el ítem por un
  cambio acá sería costo sin beneficio — ningún consumidor lee esa tabla.
- **`sold_pack`/`sold_pack_usage`, `voucher_item`,
  `document_remision_item`, `production_order`, `waste_event`**:
  transaccionales (evento sobre el ítem, no configuración), mismo criterio
  que excluye `transaction`/`drawer`/`expense` del watermark de `settings`.
- **`cRecordValue`** (resuelve el "juicio pendiente"): auditado
  `api/lib`/`api/v1` completo — NO existe ningún INSERT/UPDATE a esta
  tabla, el único write es el DELETE del wipe de tenant (que no necesita
  bumpear un contacto que se está yendo con la company entera). Lectura
  legacy panel-only, nunca viaja a ningún payload del POS. Sin write path
  activo y sin consumidor, un trigger nunca dispararía en la práctica.

### Payload — qué satélites viajan realmente al POS (verificado, no asumido)

`ItemsQuery.php::buildItemsSelectSql()`/`presentItem()` siguen siendo la
ÚNICA fuente para bootstrap (`/v1/items` vía `/api/pos/bootstrap`),
bulk-get y delta (`SyncService`) — confirmado por código, no por
suposición: los tres caminos importan las mismas dos funciones. Mismo
shape por construcción, tal como preveía este doc.

| Satélite | ¿Viaja al POS? | Detalle |
|---|---|---|
| `addon_group`/`addon_group_option` | Sí, completo | `PosItem.addonGroups`, embebido (F4-F6, ya resuelto antes de esta sesión) |
| `item_image` | Parcial | Solo la portada (`sort=0`) como `PosItem.imageUrl`; la galería completa (hasta 5 imágenes) NO viaja — no hay UI de galería en el POS hoy |
| `item_category`/`item_brand` | Parcial | `PosItem.categoryId`/`brandId` (FK legacy única) sí viajan; la m2m completa (multi-categoría/marca, `isPrimary`) no — es un dato de reporting (dashboard), no de venta |
| `item_tag` | No | Exclusión YA documentada en `context/43` (audit 2026-08-16): `PosItem` no trae `tags`, nada los renderiza hoy |
| `price_list_item` | No (a propósito) | Mecanismo separado y planificado en `context/44` (motor espejo, sin implementar) — bumpear el ítem hoy fuerza un re-sync completo por un cambio de precio-por-lista, hasta que ese plan tenga su propio delta |
| `item_compound` (receta) | No, y no debería | Consumido SOLO server-side (`Inventory.php` al commitear la venta, `ProductionService.php`) — el POS nunca arma el carrito con la receta, la resuelve el server al vender. El bump es correcto (cumple la regla del owner) pero no "entrega" nada nuevo al cliente — no hay campo que llenar |
| `itemLocation`, `pack_component` | No | Mismo criterio que `item_compound` — consumidos por `LocationService`/`ReturnService`/`PackService`, server-side |
| `customerAddress` | No en bootstrap/delta, sí on-demand | `useCustomerAddressesPos()` (`/api/pos/customer-addresses`) las trae por-cliente con su propio cache + invalidación realtime YA cableada (`ENTITY_TO_QUERY_KEYS.contact` incluye `["pos","customerAddress"]`). El bump de `contact.updatedAt` cumple la regla igual (recarga el CONTACTO), la dirección en sí sigue su camino propio |
| `contactNote` | No | Panel-only, ningún componente del POS la lee |

Ningún caso de la tabla es "trigger inútil sin acción": o el dato viaja
(addons, imageUrl, categoryId/brandId), o no necesita viajar (recetas/
ubicaciones son server-only por diseño), o ya tiene su propio camino
verificado (direcciones, con realtime + cache dedicado). Las únicas dos
brechas de payload PRE-EXISTENTES (tags, price_list_item) ya estaban
documentadas en `context/43`/`context/44` antes de esta sesión — no son
descubrimientos nuevos, se listan acá para que quede completo el mapa
satélite → payload.

### Costo de escritura — medido, no estimado

Docker `postgres:16-alpine`, 1000 filas de `price_list_item` sobre 1000
ítems reales (simulando `PriceListService::setItems()`, DELETE+INSERT
completo — el patrón que ya preocupaba a este doc):

| Operación | Sin trigger | Con trigger | Overhead |
|---|---|---|---|
| DELETE 1000 filas | 5.3 ms | 228.8 ms | +223 ms |
| INSERT 1000 filas | 65.1 ms | 510.9 ms | +446 ms |
| **Total bulk edit (1000 ítems)** | **~70 ms** | **~740 ms** | **~670 ms** |

~700ms extra en un bulk edit de 1000 ítems (operación de admin, rara, no
un hot path) — proporcional y aceptable. Una venta de N líneas tiene
overhead CERO: `SaleService`/`Inventory::manageStock()` solo escriben en
`stock` (sin trigger nuevo) — confirmado por grep, ningún `INSERT`/
`UPDATE`/`DELETE` a `item_compound`/`price_list_item`/`addon_group*` ocurre
en el flujo de venta.

### Verificación

- Migración idempotente: aplicada dos veces en DB limpia (`migrate.php`
  completo + re-aplicación directa del SQL) sin error.
- `bash api/lib/Sales/verify_chain/run.sh`: verde (94/94 aserciones de
  impresión + venta/impuestos/factura para los dos tenants PY/MX) con la
  migración ya aplicada por el propio harness.
- Casos de la regla (no de la implementación), todos contra Postgres real:
  dirección de contacto → contacto en el delta; receta de ítem → ítem en
  el delta; `defaultAdjustment` de lista → NINGÚN ítem se toca; mover un
  override de lista entre ítems → ambos ítems bumpeados.
