# 45 — Ítem y contacto como raíces de sync: tablas satélite

> Estado (2026-08-16): **plan, sin implementar.** Regla general que el owner
> extrajo mid-sesión a partir del caso puntual de `context/44
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

⚠ **Bloqueante para que esto funcione**: hoy `PosItem` lleva `categoryName` y
`brandName` DESNORMALIZADOS (`frontend/lib/types/pos-bootstrap.ts:126,129`),
no solo el id. Con el nombre copiado adentro del ítem, renombrar una categoría
deja los ítems ya descargados mostrando el nombre viejo aunque el bundle se
recargue — y la única forma de arreglarlo sería bumpear todos los ítems, que
es justo lo que queremos evitar.

**Antes de implementar los triggers**: sacar `categoryName`/`brandName` del
payload del ítem y resolver el nombre contra la lista del bundle. El patrón ya
existe en el mismo archivo para impuestos — el carrito busca por `PosItem.taxId`
en la lista de impuestos en vez de guardar la tasa copiada
(`pos-bootstrap.ts:171`). Mismo criterio, misma implementación.

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

## Qué falta para implementar (ninguno hecho esta sesión)

1. Validar la sintaxis exacta del trigger genérico contra Postgres real
   (el boceto de arriba no corrió).
2. Migración: función + N triggers (uno por satélite de la tabla de
   arriba, EXCLUYENDO `stock`).
3. Verificar consistencia de reloj (trigger vs `SyncService::watermarks()`)
   con un caso real, no por analogía.
4. Sumar los campos correspondientes a `ItemsQuery.php` (precios por
   lista, receta, add-ons, imágenes, categoría/marca/tag si no viajan ya) —
   confirmar shape idéntico en bootstrap/bulk-get/delta por construcción.
5. Confirmar o descartar una `ContactsQuery` única para `customerAddress`/
   `contactNote` con el mismo criterio.
6. Arnés: editar una dirección de contacto → el contacto aparece en el
   delta; editar la receta de un ítem → el ítem aparece en el delta;
   cambiar el `defaultAdjustment` de una lista → NINGÚN ítem aparece (solo
   `settings`), pero el precio local resuelto cambia igual
   (`context/44`).
