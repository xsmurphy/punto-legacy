# 75 — Lotes y vencimientos

> Estado: **PLAN con decisiones cerradas por el owner (2026-09-15), sin
> implementar.** Reemplaza el análisis del 2026-09-10, que asumía un alcance
> "referencial" (lote registrado solo al ENTRAR). El pedido real es otro:
> importadoras y veterinarias con obligación legal de trazabilidad —
> productos de salud— necesitan saber a QUIÉN se le vendió cada lote y que el
> lote figure en la venta. Eso obliga a asignar lote en las SALIDAS.

## 1. Qué se pide

1. **Trazabilidad**: al vender, la línea lleva el número de lote; se puede
   responder "¿quién compró el lote X?" ante un retiro del mercado.
2. **Alertas de vencimiento**, con anticipación configurable.
3. **Distribuidoras**: además del stock propio, avisar de lo ya VENDIDO a sus
   clientes (comercios) que está por vencer, porque tienen que cambiárselo
   por un lote nuevo.
4. **Sin tocar el costeo** (promedio ponderado).

## 2. La idea que destraba todo: lote ≠ método de costeo

El análisis anterior mezclaba dos ejes independientes:

- **FIFO / LIFO / promedio ponderado** responden CUÁNTO COSTÓ lo que salió.
- **El lote** responde DÓNDE ESTÁ físicamente la cantidad — igual que el
  depósito, que ya es dimensión del ledger (`item, outlet, location`).

Asignar un lote en cada salida NO obliga a valorizar por lote. **El costo
sigue siendo el promedio ponderado del ítem**; el lote es solo un eje más de
la cantidad. Eso deja intacto el COGS, `RecipeCosting` y el cálculo de
promedio de `manageStock()`.

**Contracara declarada y aceptada**: el margen por lote no es real. Dos lotes
comprados a 250 y 130 por unidad se venden los dos al promedio (166,67). Para
cumplir trazabilidad no importa.

## 3. Decisiones cerradas por el owner (2026-09-15)

- **D1 — El lote es eje del ledger de stock, con costo promedio intacto.**
  Ver §2.
- **D2 — El sistema elige el lote por vencimiento (FEFO); el cajero puede
  corregir.** Sugiere el lote con saldo > 0 que vence primero; si la cantidad
  supera ese saldo, reparte en el siguiente.
- **D3 — Dos avisos distintos**, no un "alertar con stock en 0":
  - **Stock propio por vencer**: el lote tiene saldo. Acción: venderlo primero
    o darlo de baja como merma.
  - **Mercadería vendida por vencer**, POR CLIENTE: "Farmacia X tiene 100 del
    lote 123 y vence en 30 días". Acción: cambiárselo.
- **D4 — Los días de aviso se configuran SOLO por ítem.** Sin default de
  empresa ni de sucursal ni de categoría, y sin reglas de precedencia. Por qué
  no los otros:
  - por lote: lo carga quien recibe la compra, se repite en cada ingreso y se
    olvida;
  - por sucursal: un producto no vence distinto según dónde esté, y el aviso
    lo recibe el dueño;
  - dos niveles (empresa + ítem) generan "¿por qué me avisó a los 30 si puse
    60?".
  La carga masiva se resuelve con el editor masivo de ítems y con el bot, no
  con niveles de configuración.
- **D5 — El cambio de lote al cliente se registra como DEVOLUCIÓN + VENTA
  NUEVA.** No hay operación nueva de "canje de lote".
- **D6 — El aviso de mercadería VENDIDA se activa con UN interruptor de
  EMPRESA**, no por ítem. Una distribuidora lo prende y aplica a todos sus
  ítems con lotes; una veterinaria que vende al público lo deja apagado (para
  ella sería ruido). No es precedencia sobre D4: el interruptor decide SI
  existe el aviso de vendido, los días siguen saliendo del ítem. Lo normal es
  que un negocio sea distribuidor para todo lo que vende, no para algunos
  productos.
- **D7 — La función no complica la operativa: el operador carga el dato solo
  donde NACE (la compra); todo lo demás lo decide el sistema.** Consecuencias
  que forman parte de la decisión:
  - la venta, la transferencia, la devolución y la anulación asignan lote
    solas (FEFO / herencia); el cajero ve el lote en la línea y lo cambia solo
    si quiere — no es un paso;
  - el arranque NO exige contar: el stock existente queda en "sin lote" y FEFO
    lo vende PRIMERO (§4.2); el conteo por lote es opcional;
  - la merma de un lote vencido se hace desde el aviso ("Dar de baja" con el
    lote precargado);
  - producción queda FUERA de alcance (no aplica a importadoras ni
    veterinarias).

## 4. Modelo

### 4.1 Ítem

Dos campos nuevos en `item` (nombres a definir en la mig, lowercase — ver
memoria de casing):

| Campo | Qué |
|---|---|
| controla lotes | booleano; si está activo, toda compra/ingreso pide lote + vencimiento |
| días de aviso | entero, **obligatorio** si controla lotes, precargado (ej. 30) — ningún ítem con lotes queda sin aviso por olvido. Un solo valor para los dos avisos |

### 4.1b Empresa

Un interruptor en Ajustes (clave en `company.config` JSONB, sin migración):
**"Avisarme la mercadería vendida por vencer"** (D6). Apagado por defecto.

### 4.2 Lote

Tabla nueva de **datos maestros**, no de saldos: `(companyId, itemId, número,
vencimiento)`, única por `(companyId, itemId, número)`. Nombre en código
distinto de "batch": **"lote" ya significa lote de PRODUCCIÓN**
(`production_batch`, mig 194). Propuesta: `stock_lot`.

- Si en una compra llega un número de lote que ya existe para el ítem, se
  REUSA. Si llega con otro vencimiento, el sistema avisa (no pisa en silencio).
- **Lote "sin lote" explícito por ítem**: al activar "controla lotes" sobre un
  ítem con stock existente, ese saldo se mueve a un lote sistema sin
  vencimiento. Es una FILA, no un NULL — así el invariante de §4.3 se sostiene
  sin excepciones. **FEFO lo consume PRIMERO** (antes que cualquier lote con
  fecha): es stock viejo, y así se agota vendiendo sin obligar a contar para
  arrancar (D7). Un conteo por lote puede redistribuirlo antes, opcional. No
  genera avisos (no tiene fecha). Las ventas que salen de él quedan con
  trazabilidad "sin lote" — esperado para el stock previo a activar.

### 4.3 Ledger

`stock` suma columna `lotId` (nullable a nivel schema).

**Invariante, aplicado en `Inventory::manageStock()`** (único escritor,
`api/lib/App/Domain/Inventory.php:987`): **un movimiento de un ítem que
controla lotes sin `lotId` se rechaza.** Ítems sin lotes siguen con NULL. Una
columna que las entradas llenan y las salidas dejan en NULL daría un ledger
que no cierra contra sí mismo (§8).

**Saldo por lote = `SUM(stockCount)` agrupado por `lotId`.** Mismo cálculo que
el saldo del ítem, derivado, una sola fuente (D2 de `context/52`). No hay
saldo por lote guardado aparte.

### 4.4 Venta

`itemSold` suma `lotId`. **Una línea = un lote**: si FEFO reparte 120 unidades
en 100 del lote 123 y 20 del 124, la venta lleva DOS líneas. Coincide con
SIFEN, que lleva un lote por ítem del DE (a verificar, §7).

## 5. Flujos

### 5.1 Ejemplo de punta a punta (stock previo en 0)

Compra, dos líneas del mismo ítem:
- x100 Ibuprofeno — lote 123 — vence 12/12 — 25.000
- x230 Ibuprofeno — lote 124 — vence 25/12 — 30.000

1. Se crean los lotes 123 y 124. Ledger: `+100 lote 123`, `+230 lote 124`.
2. Costo promedio del ítem: 55.000 / 330 = 166,67. El lote no guarda costo.
3. Venta de 120: FEFO → línea "lote 123 x100" + línea "lote 124 x20".
   Ledger: `-100 lote 123`, `-20 lote 124`. COGS 166,67 por unidad en ambas.
4. Saldos: lote 123 = 0, lote 124 = 210.
5. Avisos: el 123 no avisa como stock propio (saldo 0); sí como vendido si la
   empresa tiene el interruptor de vendido prendido (D6) y la venta tiene
   cliente. El 124 avisa como
   stock propio al entrar en ventana.
6. Retiro del lote 123: consulta sobre `itemSold.lotId` → clientes, facturas,
   fechas.

### 5.2 Salidas y dónde se asigna el lote

| Operación | Lote |
|---|---|
| Venta (POS/panel) | FEFO sugerido, el cajero corrige (D2); reparte en líneas |
| Devolución | hereda el lote de la línea original (`ReturnService`) |
| Anulación | revierte por lote de cada línea (`SaleVoidService`) |
| Transferencia entre depósitos | lleva lote (`StockTransferService::create()` `:213`) |
| Merma / ajuste | lote obligatorio; desde el aviso de vencimiento viene precargado ("Dar de baja"). Vencer NO es merma automática (§8) |
| Conteo | opcional por lote para ítems que controlan lotes; no es requisito para arrancar (D7) |
| Producción | **fuera de alcance** (D7). Si un ítem con lotes entra en una receta, hay que resolverlo antes de habilitarlo — ver §10 |

### 5.3 Offline del POS

- El POS necesita el saldo por lote de los ítems que controlan lotes (bootstrap
  + sync incremental). Hoy no baja ni el desglose por depósito — es trabajo
  nuevo en `lib/pos-bff/reshape.ts` / `lib/types/pos-bootstrap.ts`.
- Sin red, sugiere FEFO con el saldo sincronizado menos sus propias ventas
  pendientes. Otra caja pudo haber vendido del mismo lote → el dato puede
  estar viejo (aceptado: offline es emergencia).
- **El backend nunca rechaza una venta ya emitida** (regla offline-first): si
  deja un lote en negativo, la guarda y la marca; aparece como aviso "lote en
  negativo" para revisar.
- Error físico (se entregó otro lote y no se corrigió): el sistema no puede
  saberlo. Lo corrige un conteo por lote, que genera ajuste con autor.

### 5.4 Cambio de lote al cliente (D5)

Devolución del lote 123 + venta del lote 124. Efectos:
- El neto vendido del lote 123 a ese cliente queda en 0 → su aviso de
  "vendido" desaparece solo.
- El lote 123 vuelve al stock propio → si está por vencer, dispara el aviso de
  stock propio → termina en merma con autor.

## 6. Avisos

- **Stock propio**: lotes con saldo > 0 y `vencimiento - hoy <= días de aviso
  del ítem`. Un aviso al entrar en ventana y otro al vencer.
- **Vendido** (solo si la empresa tiene el interruptor de D6 prendido): por
  `(lote, cliente)`, neto
  = unidades vendidas − devueltas de ese lote a ese contacto, > 0, dentro de
  ventana. Ventas sin cliente identificado no se pueden avisar por cliente: se
  agrupan como "sin cliente" (dato para decidir, no acción).
- **Ventanas con dedupe, no igualdad de día**: mismo criterio que
  `InvoiceAuthNoticeService`/`PlanLifecycleService` — un día sin corrida del
  cron no pierde el aviso. `alertKey` determinística por `(lote, tipo, etapa)`
  y `(lote, cliente, etapa)`.
- **Dónde se ve**: centro de notificaciones del panel como fuente DERIVADA en
  vivo (`FeedService::buildItems()` `:103,151`, molde de `ObligationsService`
  — si el lote se vende o se devuelve, el aviso se va solo) + email al dueño
  vía `api/lib/Notifications/TenantNotice.php` como DIGEST diario, no un mail
  por lote. Job en `api/v1/maintenance.php` + `api/docker/cron/crontab`.

## 7. Visibilidad y fiscal

- **Impresión**: lote y vencimiento como campos de la línea disponibles para
  la plantilla. Sale si la plantilla lo tiene (regla: lo que se imprime lo
  decide la plantilla), sin gating por tipo de documento.
- **Factura electrónica**: SIFEN tendría grupo de rastreo de mercadería por
  ítem (`gRasMerc`: `dNumLote`, `dVencMerc`). **A VERIFICAR** contra el manual
  técnico y contra lo que acepta FE-PY antes de F4. Si existe, el KuDE del
  motor lo muestra nativo.
- **Reportes**: existencias por lote/vencimiento; trazabilidad lote → ventas
  → clientes.

## 8. Arquitecturas a evitar (antes de proponer nada)

- **Revivir la tabla `inventory`** (`db-schema-postgres.sql:397`). Tiene
  `inventoryExpirationDate`/`inventoryUID`/`inventoryCount`, cero lectores, y
  es una tabla de SALDOS por lote: segundo lugar donde vive el stock,
  contradice la D2 de `context/52`. `OutletsService.php:307-315` ya anota que
  se dropea. **Se dropea en la F0.**
- **Valorizar por lote (FIFO/FEFO de costo)** — no es necesario para
  trazabilidad (§2) y toca el money-path del inventario.
- **Guardar saldo por lote** en una columna/tabla — el saldo es derivado del
  ledger.
- **Modelar el lote como variante del ítem** — una variante es un SKU vendible
  con precio y código de barras propios; el cliente compra el producto, no el
  lote.
- **`lotId` NULL como "sin lote"** en ítems que controlan lotes — rompe el
  invariante; el "sin lote" es una fila explícita (§4.2).
- **Una línea de venta con varios lotes** (JSON/array de lotes en la línea) —
  la trazabilidad y SIFEN van por línea; se parte la línea.
- **Job que descuente lotes vencidos automáticamente** — vencer no es salir
  del inventario; la baja es una merma con autor.
- **Configuración en varios niveles** para los días de aviso
  (empresa/sucursal/categoría + ítem) — rechazada en D4.
- **"Avisar vendido" como campo por ítem** — rechazado en D6: obliga a marcar
  producto por producto algo que es una característica del NEGOCIO.
- **Pasos nuevos obligatorios fuera de la compra** (elegir lote en la caja,
  contar antes de arrancar) — rechazados en D7.
- **"Alertar con stock en 0" como flag** — un aviso de lote en 0 sin decir
  quién lo tiene no sirve; el caso real es el aviso de VENDIDO por cliente
  (D3).

## 9. Fases propuestas (orden, sin OK de fases todavía)

| Fase | Qué |
|---|---|
| **F0** | Mig: `stock_lot`, `stock.lotId`, `itemSold.lotId`, campos de ítem; DROP de `inventory`; invariante en `manageStock()` |
| **F1** | Entradas: compra con lote+vencimiento por línea (`PurchasesService::create()` `:685`, autocompleta lote existente), lote "sin lote" al activar; ficha de ítem + editor masivo (`components/items/bulk-edit-dialog.tsx`); reporte de existencias por lote |
| **F2** | Salidas: venta FEFO en backend y POS (con corrección opcional del cajero y offline §5.3), devolución, anulación, transferencia, merma, conteo opcional |
| **F3** | Avisos: stock propio + vendido por cliente (interruptor de empresa D6), feed + digest, "Dar de baja" desde el aviso |
| **F4** | Impresión (campos de plantilla), FE-PY (`gRasMerc`, tras verificar), reporte de trazabilidad |

Producción: fuera de alcance (D7).

La trazabilidad legal recién existe con F2: vender un ítem con lotes antes de
F2 deja líneas sin lote que no se pueden reconstruir. **No habilitar "controla
lotes" a clientes hasta que F2 esté en producción.**

## 10. Preguntas abiertas

1. Valor precargado de "días de aviso" (¿30?).
2. Lote repetido con distinto vencimiento en una compra: ¿aviso y deja
   guardar, o bloquea?
3. UI del POS para corregir el lote: touch/teclado, sin desplazar botones
   (memoria de layout estable del POS). A diseñar en F2.
4. Un ítem con lotes usado como insumo de una receta: producción está fuera
   de alcance (D7), así que su consumo no sabe qué lote descontar. Opciones:
   impedir activar lotes en ítems que son insumo, o consumir FEFO sin pedir
   nada. A decidir antes de F2.
5. Bot: los dos campos del ítem deben poder setearse por el agente — hoy solo
   existen `create_item` y `update_item_price` (`frontend/lib/agent/confirm-api.ts`).
   Se resuelve en el trabajo de ampliación de herramientas del bot
   (`context/66`), no acá.

## 11. Docs relacionados

- `context/52-stock-ledger-unica-fuente.md` — ledger única; D2 y D5.
- `context/modules/05-stock.md` — cómo funciona el stock hoy.
- `context/modules/08-compras.md` — la recepción, principal punto de captura.
- `context/66-onboarding-conducido-por-el-agente.md` — alcance de escritura del bot.
- `context/76-produccion-kpis.md` — producción, relevante para F5.
