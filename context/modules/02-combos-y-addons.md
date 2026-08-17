# 02 — Combos y add-ons

> Estado del doc: verificado contra código 2026-08-16
> Responsable de la última verificación: sesión 2026-08-16 (este doc)

## 1. Qué resuelve

Permite vender un producto acompañado de opciones que el cliente elige en el
momento (tamaño, guarnición, extras) o de una receta fija empaquetada a un
precio propio (combo). Cubre tres casos de negocio del comercio gastronómico:
"elegí tu bebida", "agregá extras que suman al precio" y "combo cerrado más
barato que comprar todo suelto".

## 2. Entidades y datos

| Tabla | Qué guarda | Invariantes / trampas |
|---|---|---|
| `addon_group` | Un grupo de opciones DE UN producto (`itemId` es el dueño, no reusable entre productos). | `CHECK (maxSelect IS NULL OR maxSelect >= GREATEST(minSelect,1))` — `api/database/migrations/postgres/134_addon_groups.sql:41-43`. `ON DELETE CASCADE` desde `item`: borrar el producto se lleva sus grupos — `134_addon_groups.sql:32`. |
| `addon_group_option` | Una opción del grupo; apunta a un producto REAL del catálogo (`itemId`), no a texto suelto. | `CHECK (NOT isLocked OR isDefault)` — un fijo siempre está elegido, forzado a nivel BD además de en el servicio — `134_addon_groups.sql:70`. `itemId` es `ON DELETE RESTRICT` (no CASCADE): no se puede borrar un producto que es opción de un add-on vigente — `134_addon_groups.sql:56`. `priceDelta >= 0` — nunca resta — `134_addon_groups.sql:69`. |
| `item_compound` | Receta del combo FIJO: `(parentItemId, childItemId, quantity, sort)`. | Es la misma tabla que usa producción/recetas — un combo fijo es, para el motor de stock, una receta más (`explodeRecipe` no distingue). Sin tabla de precio propia: el descuento se calcula al vuelo comparando `itemPrice` del padre vs. suma de precios de los hijos (`ItemCompoundService::comboPricing`, `api/v1/items.php:681-684`). |
| `combo_group` / `combo_group_item` | Maquinaria VIEJA de combo dinámico (mig 20). **Deprecada, sin dropear.** | Panel-only: `SaleService` nunca las leyó, así que nunca afectaron precio ni stock de una venta real (`context/41-addons-y-combos.md:258-260`). Migradas a `addon_group`/`addon_group_option` por la mig 136 (F5); el sub-recurso `resource=combo-groups` sigue respondiendo con `@deprecated` mientras existan lectores. |
| `itemSold.itemSoldParent` | FK a `item(itemId)` — el ÍTEM padre, NO el `itemSoldId` de la línea padre. | Con dos líneas del mismo producto en el carrito (una con queso, otra sin), esta columna es AMBIGUA sobre a cuál pertenece cada add-on — el vínculo exacto de línea vive en `itemSold.meta.addon.parentItemSoldId`, no acá (`api/lib/Sales/SaleService.php:1449-1455`). |
| `itemSold.meta` (jsonb) | Para líneas hijas de add-on: `{addon: {optionId, parentItemSoldId}}`. Para la línea con etiquetas: `{tags: [...]}`. | Es el ÚNICO lugar con el vínculo exacto a la línea padre (ver arriba). `null` si la línea no tiene tags ni es add-on — no persiste un objeto vacío (`SaleService.php:1461-1487`). |

No hay tabla N:M entre grupos y productos: D1 (owner, 2026-08-14) descartó
reusabilidad entre productos a propósito, por aislamiento. "Copiar grupos
desde otro producto" en el panel es una copia real de filas
(`AddonService::copyFromItem`, `api/lib/Items/AddonService.php:180-210`), no
una referencia — editar el copiado no afecta al original.

## 3. Reglas de negocio

1. **Combo fijo y combo dinámico son mecanismos DISTINTOS con etiquetas de
   `itemKind` que hoy casi no significan nada operativamente.** Combo fijo =
   receta cerrada en `item_compound`, sin elección del cliente, motor de
   stock = recetas (`explodeRecipe`). Combo dinámico = decisión del owner
   (2026-08-14) de tratarlo como "un producto con grupos de add-ons": desde
   F5 no tiene tabla ni lógica propia — es un `addon_group` con
   `minSelect=maxSelect` por decisión de preset de UI, nada más. El `kind`
   sigue existiendo solo como etiqueta semántica de catálogo/reportes, no
   como comportamiento — `context/41-addons-y-combos.md:74-83`,
   `frontend/app/(panel)/items/[id]/page.tsx:1811-1817`.
2. **`priceDelta` (por opción) define si esa opción suma al precio; 0 = no
   suma.** No hay % de descuento sobre la suma (D2, owner 2026-08-14) —
   `AddonService.php:17-18`, `134_addon_groups.sql:57-59`. Consecuencia: un
   "combo dinámico" con precio base y todo `priceDelta=0` es un combo de
   precio fijo armado con la misma maquinaria que un add-on con recargo.
3. **`isLocked` implica `isDefault` siempre**, forzado en dos capas: CHECK de
   BD (`134_addon_groups.sql:70`) y `normalizeGroups()` en el servicio, que
   ignora lo que mande el caller y fuerza `isDefault=true` si `isLocked=true`
   (`AddonService.php:393-396`). El POS pinta la opción marcada y
   deshabilitada. Al vender, si el cliente NO la manda en el payload,
   `validateSelections` la agrega sola con `qty=1` — `AddonService.php:265-270`.
4. **`minSelect`/`maxSelect` cuentan OPCIONES DISTINTAS elegidas, no la suma
   de cantidades.** `maxQty` es el tope de repetir LA MISMA opción (ej. "2x
   panceta"). Son dos contadores independientes —
   `AddonService.php:286-323`, mismo criterio espejado en el modal del POS
   (`addon-picker-dialog.tsx:20-22`).
5. **El precio NUNCA viaja del cliente en la venta.** El payload de la línea
   trae `selections: [{optionId, qty}]`; `AddonService::validateSelections`
   recalcula `priceDelta` desde la BD para cada opción — `AddonService.php:212-235`,
   `SaleService.php:1526-1529`. El POS sí calcula y muestra el precio en vivo
   para la UI, pero eso es cosmético: el servidor no confía en él.
6. **El total de la venta (`transactionTotal`) SIGUE sin derivarse del detalle
   de líneas — sigue siendo lo que informa el cliente (`$input->subtotal`).**
   Consecuencia explícita documentada en el código: el POS DEBE sumar los
   `priceDelta` al subtotal y al cobro él mismo, igual que ya hace con el
   precio base de cada línea — si no lo hace, el add-on se vende gratis
   (`SaleService.php:1504-1521`). Esto es una decisión de continuidad de
   contrato, no un bug: cerrarlo del todo (validar el total contra el
   detalle) es un cambio de contrato aparte, no hecho.
7. **`isLocked`/`isDefault` descuentan stock igual que cualquier otra opción,
   aunque el cajero nunca haya tocado el modal.** No hay excepción para
   "vino marcado solo" — `SaleService.php:1526-1529`, `AddonService.php:265-270`.
8. **Cada opción descuenta como el producto que es**, con la misma máquina que
   cualquier línea de venta: si tiene stock propio baja su saldo, si es
   producción directa explota su receta recursivamente
   (`Inventory::explodeRecipe`) — `SaleService.php:1494-1502`,
   `api/lib/App/Domain/Inventory.php:152-200`. La cantidad de la opción se
   multiplica por las unidades del padre (2 hamburguesas con queso extra = 2
   quesos) — `SaleService.php:1523-1524`.
9. **D3 — el ticket fiscal solo lista add-ons con `priceDelta > 0`; la
   comanda lista todos, incluidos los gratis.** VERIFICADO vigente:
   `buildTicketItemsFromTransaction` filtra líneas hijas de importe 0 salvo
   `opts.includeFreeAddons`, y `buildTicketDataFromTransaction` pasa
   `includeFreeAddons: docType === "order"` —
   `frontend/lib/hardware/printers/build-ticket-data.ts:439-467, 504-509`.
10. **Combo fijo: el descuento implícito se calcula al vuelo, nunca se
    persiste.** `ItemCompoundService::comboPricing` compara `itemPrice` del
    padre contra la suma de precios de los hijos — `api/v1/items.php:681-684`,
    mostrado en la ficha del panel (`ComboPricingCard`,
    `frontend/app/(panel)/items/[id]/page.tsx:1946-2013`).
11. **`toCompound`/`CompoundService` están deprecados — nada nuevo debe
    tocarlos.** Todo lo vigente (combo fijo) vive en `item_compound`
    (`context/41-addons-y-combos.md:30, 266`). NO verificado en esta sesión
    si algún call-site legacy los sigue leyendo — no se auditó ese código.

## 4. Flujos principales

**Venta directa con add-ons (POS → `SaleService`):**
1. El cajero toca un producto con `hasAddons=true` (flag pre-calculado por
   `ItemsQuery`, ver §5). Si tiene grupos, se abre el modal de selección
   (`addon-picker-dialog.tsx`); si no, agrega directo — cero fricción para el
   85%+ de productos sin la feature.
2. El modal fuerza mínimos/máximos por grupo antes de dejar confirmar
   (`Enter` deshabilitado si la selección no es válida). Precio en vivo =
   base + Σ deltas.
3. Al cobrar, cada línea del carrito con `selections` no vacío pasa por
   `expandAddonSelections` ANTES de abrir la transacción SQL: revalida contra
   `AddonService::validateSelections` y expande en líneas hijas dentro del
   mismo array de detalle — `SaleService.php:1540-1620`.
4. Persistencia: la línea padre entra igual que siempre; cada hija genera su
   propio `itemSold` con `itemSoldParent = itemId del padre` y
   `meta.addon = {optionId, parentItemSoldId}`. Las hijas pasan por el MISMO
   pipeline que cualquier línea — impuestos propios (`taxId` del add-on),
   stock, `meta.transactionDetails` para ticket/comanda.
5. Error de selección inválida (grupo obligatorio sin elegir, opción
   inexistente, cantidad repetida) → `InvalidAddonSelectionException` →
   `InvalidSaleInputException` → 422, mismo canal que cualquier otro error de
   venta (`SaleService.php:1555-1562`). Igual en venta online que en
   sincronización offline (el batch de sync mapea por venta, una selección
   inválida no tumba el lote entero).

**Orden/mesa con add-ons → cobro (Cobrar mesa/orden):** ver §5, es donde el
plan se queda corto — el hallazgo más importante de esta verificación.

**Panel — edición de grupos:** la ficha del producto reemplaza TODOS los
grupos del ítem en cada guardado (`AddonService::replaceForItem` hace
DELETE + INSERT completo dentro de una transacción, `AddonService.php:125-170`)
— no hay edición incremental de un grupo individual desde la API.

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| Catálogo (`item`) | Cada opción de add-on ES un `item` real: hereda stock, receta, costo, impuestos. `addon_group.itemId` y `addon_group_option.itemId` son FKs a `item`. | Asume que el ítem-opción está `itemStatus=1` al guardarse desde el panel (`assertItemOwnedByTenant(..., requireActive: true)`, `AddonService.php:377-382`) — pero NO se revalida `itemStatus` en `validateSelections` al vender (solo se valida `group.status` y límites). **NO VERIFICADO**: si un ítem-opción se desactiva después de vivir en un grupo, ¿la venta lo sigue aceptando? El código de `validateSelections` no filtra por `itemStatus`, así que aparentemente sí — no se confirmó con una prueba end-to-end. |
| Stock (`explodeRecipe`) | Cada opción vendida descuenta como el ítem que es, incluidas recetas propias explotadas recursivamente. | Asume que `manageStock`/`saleExplodesRecipe` es el único camino de movimiento — mismo invariante que el resto de la venta (ver `01-catalogo-items.md`/`05-stock.md` cuando existan). Combo fijo usa la MISMA `explodeRecipe` que ya usaba antes de F1: sin cambios de esta feature. |
| Impuestos | Cada línea hija de add-on lleva SU PROPIO `taxId` (el del ítem-opción), congelado por `enrichWithTaxes` igual que cualquier línea top-level. `tax: 0.0` en la línea recién armada es un placeholder — lo completa el motor de impuestos después, no antes (`SaleService.php:1591`). | Asume que el add-on tiene su propio impuesto configurado correctamente en catálogo — no hereda el impuesto del producto padre. Un add-on exento vendido junto a un producto gravado factura correctamente distinto por línea. |
| Precios/listas | **NO VERIFICADO.** No se auditó si `priceDelta` respeta listas de precio por cliente/lista — el modelo lo trata como un valor fijo por opción, sin lectura de `03-listas-de-precio.md` en el código revisado. Riesgo: un add-on podría no respetar el mismo mecanismo de lista que el producto base. |
| **Órdenes/mesas (`pos_order_item`)** | La orden le pide al carrito sus líneas para crear `CreateOrderItemInput` — pero ese tipo **NO tiene campo `selections`** (`frontend/hooks/use-orders.ts:156-164`). El backend `OrderCoreService` tampoco lo lee ni lo persiste (cero menciones de `selections`/`addon` en `api/lib/Orders/OrderCoreService.php`). | **Asume — de forma implícita y sin declararlo — que el add-on ya quedó "cobrado" en el `unitPrice` plano de la línea** (`cart-panel.tsx:332` manda `price: l.unitPrice`, que YA incluye el delta). Consecuencia real: una orden de mesa con add-ons cobra la plata correcta pero **pierde el desglose por completo** — no hay línea hija en `pos_order_item`, no hay stock específico de la opción elegida en ese momento (ver siguiente fila), y la comanda de cocina no puede mostrar "con queso extra" porque el dato nunca llegó a persistirse. |
| **Órdenes → Venta (cobro de mesa, `loadFromOrder`)** | `loadFromOrder` reconstruye `CartLine[]` desde `OrderItem[]` — que tampoco tiene `selections` (`use-orders.ts:45-65`) — así que la línea reconstruida llega a `SaleService` **sin `selections`**. | Esto significa que **`expandAddonSelections` nunca corre para ventas que se originaron como orden de mesa**: no se generan líneas hijas de `itemSold`, no se descuenta el stock específico de la opción elegida (el add-on-producto nunca se resta de inventario), y el ticket/comanda de esa venta no puede indentar add-ons porque no existen como líneas. Es una laguna de **plata correcta / inventario y trazabilidad rotos**, más severa que el gap de impresión que documenta el plan. **NO estaba señalada en `context/41-addons-y-combos.md`** — es el hallazgo principal de esta verificación. |
| Impresión — comanda de cocina vía Órdenes (`buildOrderTicketData`) | Arma `TicketItem[]` directo desde `order.items` (`pos_order_item`), sin `isAddonChild`, sin filtrar/indentar nada de add-on — `frontend/lib/orders/print-comandas.ts:29-63`. | Confirma el gap que el plan ya señalaba ("Comanda de cocina sin add-ons", `context/41:165-172`): sigue vigente. Es consecuencia directa de la fila anterior — si la orden nunca recibió `selections`, no hay nada que indentar. |
| Impresión — ticket/comanda vía Venta directa (`buildTicketDataFromTransaction`) | Si la venta SÍ trae `selections` (venta directa de mostrador, sin pasar por orden), el add-on aparece correctamente indentado (`ticketItemName`, prefijo `"  + "`) y D3 se aplica (`docType==="order"` incluye gratis, lo demás no). | Asume que `tx.transactionDatas` trae `type:"addon"` por línea — cierto solo para ventas armadas por `SaleService::expandAddonSelections`. |
| Detalle de transacción del panel (reimpresión) | `TxDetailFull` no expone `meta.addon` — `buildTicketDataFromTxDetail` no puede indentar hijas ni aplicar D3 al reimprimir desde el panel. | Gap ya señalado en el plan (`context/41:173-176`), pendiente en `context/39-detalle-transaccion.md`. NO VERIFICADO en esta sesión si sigue exactamente así — se tomó del plan sin releer `TxDetailFull` línea por línea. |
| Sincronización / offline | `PosItem.addonGroups` viaja embebido en CADA ítem del bootstrap/bulk-get/delta (`ItemsQuery.php:154-214`, `buildItemsSelectSql`), y `useItemAddonsPos` lee `useCatalogStore.items[].addonGroups` sin red (`use-item-addons-pos.ts:41-55`). | Verificado de punta a punta — ver §6. El realtime que invalida ese cache tiene una ineficiencia conocida (fallback genérico en vez de sync quirúrgico por ítem, `context/41:236-250`) que NO se corrigió — no rompe correctness, sí sobre-invalida. |
| Reportes (`ProductsService`) | F6 (reportes por add-on) **no implementada** — filtra por `combo/precombo/comboAddons` pero no se auditó si excluye correctamente las líneas hijas para no duplicar venta en los rollups. | **NO VERIFICADO**: no se leyó `ProductsService` en esta sesión; el plan deja la advertencia (`context/41:268-271`) sin confirmar si ya se implementó o sigue abierta. |

## 6. Offline (POS)

**Verificado de punta a punta.** Los grupos de add-ons viajan embebidos en
cada ítem del catálogo local, no como un endpoint aparte:

- `buildItemsSelectSql` arma `addonGroups` con un `LEFT JOIN LATERAL` +
  `json_agg` correlacionado por ítem — mismo SELECT compartido por
  bootstrap, bulk-get y el delta de sync incremental (`ItemsQuery.php:171-214`).
- `presentItem()` decodifica el JSON (el driver `pdo_pgsql` devuelve
  `json_agg` como string) y normaliza `NULL`/ítems sin grupos a `[]`, nunca
  `null` — el POS no debe distinguir "sin campo" de "sin add-ons"
  (`ItemsQuery.php:104-119`).
- El hook que antes hacía `GET /api/pos/item-addons?itemId=X` en CADA
  apertura del modal (invendible offline para cualquier grupo obligatorio)
  ahora lee `useCatalogStore.items[].addonGroups` sin red —
  `use-item-addons-pos.ts:1-55`. El endpoint BFF `/api/pos/item-addons` quedó
  sin consumidores y se borró (según el plan; no se verificó su ausencia
  física en el árbol de rutas en esta sesión — **NO VERIFICADO**).
- La venta con `selections` viaja en el payload de la venta encolada
  offline igual que el resto de la venta; la validación de
  `AddonService::validateSelections` corre recién al sincronizar (server),
  no en el dispositivo — el POS confía en su copia local para la UX pero el
  server es la autoridad final, igual que con cualquier otro dato de venta
  offline.

## 7. Huecos conocidos y NO verificado

- **Órdenes/mesas pierden `selections` por completo** (ver §5) — plata
  correcta, pero sin stock de la opción, sin línea hija, sin dato para la
  comanda. No está en la lista de gaps del plan; se agrega acá como hallazgo
  de esta verificación.
- **Comanda de cocina de Órdenes sin add-ons** — confirmado vigente
  (`print-comandas.ts` no tiene ningún campo de add-on). Coincide con lo que
  ya señalaba `context/41-addons-y-combos.md:165-172`.
- **Detalle de transacción del panel sin padre/hijo** — tomado del plan, NO
  releído línea por línea en esta sesión.
- **`itemStatus` de la opción al vender**: no se confirmó si `validateSelections`
  rechaza una opción cuyo ítem fue desactivado después de creado el grupo.
- **Precios/listas**: no se auditó si `priceDelta` respeta listas de precio
  por cliente — módulo `03-listas-de-precio.md` no se leyó en esta sesión.
- **F6 (reportes por add-on)**: no implementada según el plan; no se
  verificó el estado real de `ProductsService` respecto a duplicar venta de
  líneas hijas en los rollups.
- **Ineficiencia de realtime**: `item_addons.php` publica sin `itemId` en
  query string, así que cualquier edición de add-ons invalida el bootstrap
  COMPLETO en vez de sync quirúrgico por ítem — correcto pero caro. Tomado
  del plan, no reverificado línea por línea.
- **`toCompound`/`CompoundService` deprecados**: no se auditó si algún
  call-site legacy los sigue usando pese a la marca de deprecado.

## 8. Planes y decisiones relacionados

- `context/41-addons-y-combos.md` — plan cerrado (D1-D4), estado de fases
  F1-F5 hechas, F6 pendiente. Fuente de las decisiones del owner citadas en
  este doc.
- `context/45-satelites-item-contact-sync.md` — el patrón "satélite embebido
  en el ítem" que sostiene el embebido offline de `addonGroups` (plan aún no
  implementado en general, pero add-ons ya sigue ese patrón de facto).
- `context/39-detalle-transaccion.md` — resolución pendiente del gap de
  `meta.addon` en `TxDetailFull`.
- `context/38-impuestos-multi-pais.md` — motor de impuestos que congela
  `taxId` por línea, incluidas las hijas de add-on.
