# 41 — Add-ons y combos

> Estado: **plan abierto** (2026-08-14). Análisis hecho, D1–D3 pendientes del
> owner. Nada implementado.

## Pedido del owner (2026-08-14)

- **Add-ons por producto**: al crear/editar un producto, poder añadir GRUPOS de
  productos como add-ons. Cada grupo con límite mínimo y máximo. Cada add-on
  puede sumar al precio o no. Pueden ser fijos (preseleccionados sin poder
  quitarse).
- **Combos predefinidos**: precio final del combo puede ser menor que la suma de
  sus productos → el descuento queda implícito.
- **Combos dinámicos**: el owner mismo lo dijo — "creo que es básicamente lo
  mismo que producto con add-on". El análisis lo confirma: se unifican.
- Implementar en panel Y en `/pos`.

## Estado actual (relevado, no asumido)

| Pieza | Estado |
|---|---|
| `combo_fijo` / `combo_dinamico` | Kinds declarados (mig 15). Mismos flags, CERO comportamiento distinto entre sí. |
| `item_compound` | Receta del combo fijo. `(parent, child, quantity, sort)`. UNIQUE (parent, child). |
| Venta de combo | El POS lo agrega como ítem plano; `SaleService` explota la receta recursivamente para stock (explodeRecipe, 2026-08-11). Sin UI de selección. |
| `itemSold.itemsoldparent` | Columna YA existe — soporte de líneas padre/hijo sin migración. |
| `toCompound` + `CompoundService` | **Deprecados** (F0 producción). El legacy tenía flags `preselected` — la idea de "fijo" ya existió. NO revivir. |
| Add-ons | No existe nada: ni tablas, ni UI, ni referencias vivas. |

## Modelo propuesto

Dos tablas nuevas + un vínculo. El add-on ES un producto del catálogo (no un
texto suelto): así hereda stock, receta, costo e impuestos, y la venta lo
descuenta con la misma maquinaria que cualquier ítem.

```sql
-- Grupo de opciones, reusable a nivel tenant ("Salsas", "Extras", "Bebida").
CREATE TABLE addon_group (
  groupId    UUID PRIMARY KEY,
  companyId  UUID NOT NULL,
  name       VARCHAR(80) NOT NULL,
  minSelect  SMALLINT NOT NULL DEFAULT 0,   -- 0 = opcional
  maxSelect  SMALLINT,                      -- NULL = sin tope
  sort       SMALLINT NOT NULL DEFAULT 0,
  status     BOOLEAN NOT NULL DEFAULT TRUE
);

-- Opciones del grupo: cada una apunta a un producto real.
CREATE TABLE addon_group_option (
  optionId    UUID PRIMARY KEY,
  groupId     UUID NOT NULL REFERENCES addon_group,
  itemId      UUID NOT NULL REFERENCES item,    -- el producto que se agrega
  priceDelta  NUMERIC(14,2) NOT NULL DEFAULT 0, -- 0 = no suma al precio
  isDefault   BOOLEAN NOT NULL DEFAULT FALSE,   -- preseleccionado
  isLocked    BOOLEAN NOT NULL DEFAULT FALSE,   -- fijo: no se puede quitar
  maxQty      SMALLINT NOT NULL DEFAULT 1,      -- cuántas veces la misma opción
  sort        SMALLINT NOT NULL DEFAULT 0
);

-- Qué grupos usa cada producto (N:M, con orden por producto).
CREATE TABLE item_addon_group (
  itemId   UUID NOT NULL REFERENCES item,
  groupId  UUID NOT NULL REFERENCES addon_group,
  sort     SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (itemId, groupId)
);
```

- `isLocked` implica `isDefault` (un fijo está siempre elegido). El guard va en
  el servicio, no en la UI.
- `minSelect > 0` = el POS no deja confirmar sin elegir (ej. "Bebida: min 1
  max 1" = radio obligatorio).
- **Combo dinámico se unifica acá**: `combo_dinamico` pasa a ser un producto
  cuyo precio es el del combo y cuyos grupos son obligatorios (min=max). No
  hay una segunda maquinaria.
- **Combo fijo sigue en `item_compound`** (receta fija, sin elección). Su
  descuento implícito = suma de precios de los hijos − precio del combo; se
  muestra en ficha y ticket, no requiere schema.

## Venta (backend)

- `SaleInput` acepta por línea `selections: [{optionId, qty}]`. El server
  revalida TODO contra el modelo: min/max por grupo, opciones vigentes,
  priceDelta desde la BD — el precio NUNCA viaja del cliente.
- Persistencia: línea padre (el producto, precio base + suma de deltas) +
  líneas hijas en `itemSold` con `itemsoldparent` — columna ya existente. El
  ticket y la cocina imprimen padre + hijas indentadas.
- Stock: cada opción elegida descuenta como lo que es — si el add-on es un
  ítem con stock propio baja su saldo; si es producción directa explota su
  receta (`explodeRecipe`). Los `isLocked/isDefault` descuentan igual aunque el
  cajero no los haya tocado.
- Offline: las selecciones viajan en el payload de la venta encolada; la
  validación corre al sincronizar. Igual que hoy con el resto de la venta.

## POS

- Producto CON grupos → el tap abre modal de selección (radio si min=max=1,
  checkboxes/steppers si no; los `isLocked` aparecen marcados y deshabilitados;
  el precio se actualiza en vivo). Producto SIN grupos → tap agrega directo,
  como hoy. CERO fricción agregada a lo que no usa la feature.
- Carrito: línea padre expandible con sus add-ons; editar reabre el modal con
  lo elegido.
- Teclado: el modal respeta la operación sin mouse (context/pos): flechas +
  espacio para marcar, Enter confirma, ESC cancela.

## Panel

- **Ficha del producto**: sección "Add-ons" — adjuntar grupos existentes,
  crear uno nuevo inline, ordenar. Vista previa de cómo lo ve el cajero.
- **Catálogo de grupos** (`/settings/catalog` o sección propia): CRUD de
  grupos y opciones. Un grupo editado impacta en todos los productos que lo
  usan — esa es la gracia de que sea reusable.
- **Combo fijo**: la ficha ya edita `item_compound`; se agrega el bloque de
  precio que muestre suma de componentes vs precio del combo y el descuento
  resultante.

## Decisiones pendientes del owner

- **D1 — ¿Grupos reusables a nivel comercio, o por producto?** El modelo
  propone REUSABLES ("Salsas" se define una vez y se adjunta a 20 productos;
  editarlo impacta en los 20). La alternativa (grupos embebidos en cada
  producto) evita el efecto dominó pero multiplica mantenimiento. Recomendado:
  reusables.
- **D2 — Precio del combo dinámico**: ¿precio FIJO del combo sin importar lo
  elegido (las opciones premium usan `priceDelta` para recargo), o precio =
  suma de lo elegido con un % de descuento? Cambia el modelo de pricing y el
  ticket. Recomendado: fijo + deltas (es lo que hace toda la industria y lo que
  el modelo ya soporta sin campos extra).
- **D3 — ¿El add-on con priceDelta=0 imprime en el ticket del cliente?** En
  cocina siempre sale; en el ticket fiscal un add-on sin precio puede listar o
  no. (Detalle de impresión, no bloquea el modelo — decidir antes de F5.)

## Fases

- **F1** — Migs (3 tablas) + `AddonService` (CRUD + validador de selecciones).
- **F2** — Panel: catálogo de grupos + sección en ficha del producto.
- **F3** — Venta: `SaleInput.selections`, revalidación server-side, líneas
  padre/hijo, stock por selección. SIN UI todavía — testeable por API.
- **F4** — POS: modal de selección + carrito expandible + teclado.
- **F5** — Combo fijo: descuento visible en ficha/ticket; combo dinámico
  migrado al mecanismo de grupos; impresión cocina/cliente (D3).
- **F6** — Reportes: ventas por add-on (qué opciones salen más), respetando
  líneas padre/hijo en los rollups.

## Notas

- NO tocar `toCompound`/`CompoundService` (deprecados). Todo lo nuevo va sobre
  `item_compound` y las tablas nuevas.
- `ProductsService` (reportes) filtra por `combo/precombo/comboAddons` — al
  implementar, revisar que las líneas hijas no dupliquen venta en los rollups
  (la plata vive en el padre + deltas; las hijas llevan cantidad, no importe,
  salvo su delta).
- La ficha de producto del POS (`product-info-dialog`, 2026-08-14) muestra
  stock por sucursal: cuando existan add-ons, esa ficha NO necesita cambios —
  los add-ons son productos y ya se consultan individualmente.
