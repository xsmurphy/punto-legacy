# 40 — Anulación y Nota de Crédito

> Estado: **plan abierto** (2026-08-14). Pedido del owner desde `/pos` → detalle
> de transacción: los botones "Anular" y "Devolución" están deshabilitados.
> **Las cuatro decisiones están cerradas.** Todas las fases pueden ejecutarse.
> Nada implementado todavía.

## Qué pidió el owner, textual

- **Anulación**: cancela la factura. El número de factura **NO se libera** —
  queda usado. La venta anulada **no suma al total vendido**.
- **Devolución**: es una **nota de crédito**.
- Si el tenant tiene **facturación electrónica**, las dos operaciones tienen que
  estar integradas con la cancelación y la NC de la integración.

## Lo que YA existe (no rehacer)

Relevado antes de planificar — la base está mucho más armada de lo que parece
desde la UI:

| Pieza | Estado |
|---|---|
| `EInvoiceService::cancel($companyId, $docId, $reason)` | **Implementado y completo**: valida que el doc esté `issued`, exige motivo, manda el evento a SIFEN vía Factomate y marca `einvoice_document.status='cancelled'`. **Sin ningún caller.** |
| `SaleToInvoiceMapper` con `documentType=5` + `associatedCdc` | **Implementado**: sabe armar el payload de una nota de crédito electrónica asociada al CDC de la factura corregida. |
| `SaleType::Return = 6`, `SaleType::Canceled = 7` | Declarados en el enum. Nada los emite. |
| `transaction_link` con `kind='return'` | Tabla y tipo de vínculo listos (mig 115). |
| `document_sequence` + `DocumentNumber::allocate` | Listos (context/37). Falta el doctype `nota_credito` y su rango de timbrado. |

O sea: falta **la capa de negocio**, no la de integración.

## Lo que falta

1. **Anulación** — marcar la venta como anulada sin perder que existió, revertir
   stock, revertir el movimiento financiero, y disparar la cancelación
   electrónica si el tenant la tiene.
2. **Nota de crédito** — documento NUEVO, con su propia numeración y su propio
   timbrado, vinculado a la factura original, que devuelve stock y plata.
3. **Numeración** — `doctype = 'nota_credito'` scope `register` (F5 de
   context/37), con rango de timbrado propio: en PY la NC lleva timbrado
   separado de la factura.
4. **Reportes / rollups** — excluir lo anulado del total vendido, y restar las
   NC. Toca las tablas de rollup (context/18).
5. **UI** — habilitar los dos botones del detalle de transacción en `/pos`.

## Decisión de diseño ya tomada (no es opinable)

**La anulación NO cambia `transactionType` a 7.** Se agrega estado
(`voidedAt`, `voidReason`, `voidedBy`) sobre la venta original.

Por qué: el `transactionType` es lo que determina el tipo de documento y la
numeración (`SaleType` es la fuente de verdad, context/37). Pisarlo con 7
convertiría una factura emitida en "otra cosa", y el número de factura quedaría
colgado de una fila que ya no dice ser una factura — justo lo contrario de lo
que pidió el owner ("el número queda usado"). Con un flag, la factura sigue
siendo esa factura, con su número y su timbrado, marcada como anulada.

`SaleType::Canceled = 7` queda como está: declarado y sin uso.

## Decisiones (todas cerradas)

- **D1 — ¿La NC puede ser parcial?** CERRADA (owner, 2026-08-14): **parcial por
  ítem**. Se eligen qué ítems y cuántas unidades se devuelven, la NC lleva su
  propio detalle y se pueden emitir varias contra la misma factura hasta
  cubrirla. Consecuencias de modelo, a respetar desde el arranque:
  - la NC necesita detalle propio (`itemSold` de la NC), no derivado;
  - hay que acumular lo ya devuelto por factura para no permitir devolver más
    de lo vendido — el guard va contra la SUMA de las NC previas, no contra la
    última;
  - la relación factura→NC es 1:N (`transaction_link` ya lo soporta: su unique
    es por `(companyid, originid, derivedid, kind)` y cada NC es un `derivedid`
    distinto).
- **D2 — ¿La mercadería devuelta vuelve al stock?** CERRADA y REVISADA (owner,
  2026-08-14). La primera respuesta fue "lo elige el cajero por ítem", pero el
  owner corrigió con un caso que rompe esa simplificación: una hamburguesa
  preparada que se devuelve NO puede volver al stock — los insumos ya se
  consumieron y no se des-preparan. Y sumó el criterio de fondo: **estas no son
  decisiones nuestras, son reglas del comercio.**

  Quedan separadas TRES cosas que se estaban mezclando:

  **a) Qué es POSIBLE — lo determina el sistema, no es opinable.** Depende de
  cómo el ítem descuenta stock al venderse:

  | Tipo de ítem | Al vender descontó | Se puede reponer |
  |---|---|---|
  | Con stock propio | ese mismo ítem | **Sí** — vuelve a su saldo |
  | Producción previa | el terminado, que tiene stock propio | **Sí** — vuelve el terminado |
  | Producción directa (receta al vender) | los INSUMOS de la receta | **No como ítem**: no tiene saldo propio. Solo cabe reponer los insumos, y únicamente si nunca se preparó |
  | Combo | lo que explotó su receta, en todos sus niveles | Igual que producción directa |
  | Servicio / sin stock | nada | **No** — no hay nada que reponer |

  La UI solo puede OFRECER lo que esta tabla habilita. Ofrecer "devolver al
  stock" en una hamburguesa preparada es ofrecer algo que el sistema no puede
  hacer bien.

  **b) Qué se hace por DEFECTO — regla del comercio, configurable por tenant.**
  En `company.config` (mismo lugar que el resto de los `setting*`):
  - `settingReturnRestock`: `restock` | `waste` | `ask` (default `ask`).
  - `settingReturnAllowIngredientReversal`: bool, default `false`. Habilita
    reponer los INSUMOS de una producción directa cuando el producto no llegó a
    prepararse. Va apagado por defecto porque el caso normal es que ya se
    preparó, y reponer insumos que sí se consumieron infla el inventario.

  **c) Qué decide el CAJERO en el momento** — solo cuando la regla del comercio
  dice `ask`, y solo dentro de lo que (a) habilita. El sistema no puede saber si
  la hamburguesa se preparó o no; esa información solo la tiene la persona que
  está atendiendo.

  Lo que NO vuelve al stock no desaparece: genera su `waste_event` con el costo,
  para que la pérdida quede registrada en vez de evaporarse del inventario.
  Reusa el módulo de merma existente (correlativo desde la mig 129) con un
  `wasteReason` sembrado tipo "Devolución de cliente".

- **D3 — ¿La NC devuelve dinero o deja saldo a favor?** CERRADA y REVISADA
  (owner, 2026-08-14). Se implementan las dos salidas, pero por el mismo
  criterio que D2 **la política es del comercio**, no nuestra ni del cajero por
  defecto: `settingReturnRefund`: `cash` | `credit` | `ask` (default `ask`).
  Con `ask`, el cajero elige en cada devolución; con las otras dos, la salida
  está fijada y la pantalla no pregunta.
  - **Salida de caja** → `fin_movement` (`kind='expense'`) contra la caja y el
    turno ABIERTOS al momento de la devolución, NO contra los de la venta
    original. Resuelve solo el caso "la venta fue en otro turno o en otra
    sucursal": la plata sale de donde efectivamente se entrega. El arqueo de ese
    turno tiene que mostrarla, si no el cajero cierra con diferencia.
  - **Saldo a favor** → acredita `contact.contactStoreCredit`. La columna YA
    existe y está VIVA: `SaleService` la acredita con los ítems `inCredit` y
    `Customer` la debita al usarla, así que la NC solo suma un origen más al
    mismo mecanismo — no hay cuenta corriente que inventar.
  - Saldo a favor exige cliente identificado. Si la venta fue sin cliente, esa
    opción no se ofrece aunque la política diga `credit`: no hay a quién
    acreditarle. Ahí se cae a salida de caja.

- **D4 — ¿Hasta cuándo se puede anular?** CERRADA (owner, 2026-08-14): **48
  horas desde la emisión**, y el corte se aplica en LOS DOS lados.
  - El botón "Anular" se deshabilita en la UI pasado el plazo, con el motivo a
    la vista y ofreciendo "Devolución" en su lugar.
  - El endpoint RECHAZA la anulación pasado el plazo, aunque el request llegue
    igual. El guard de UI es comodidad; el que manda es el del servidor —
    deshabilitar un botón no es un control de acceso, y este es un límite
    fiscal, no una preferencia de interfaz.
  - El plazo se cuenta desde la **fecha de emisión de la factura**
    (`transactionDate`), no desde el último cambio ni desde el momento del
    pedido. Es la fecha que mira SIFEN.
  - **Aplica a todos los tenants, tengan o no facturación electrónica**
    (asunción declarada, no preguntada de nuevo). El owner respondió el plazo
    sin distinguir, y un documento fiscal no deja de serlo porque no se
    transmita: permitir anular una factura en papel un mes después es peor para
    la auditoría que el caso con FE, donde al menos SIFEN lo rechazaría. Si más
    adelante se quiere relajar para tenants sin FE, es un flag, no un rediseño.
  - Pasado el plazo el camino correcto es la **nota de crédito**, que no tiene
    límite de tiempo.

## Fases propuestas

- **F1** — Anulación interna: estado sobre la venta, reverso de stock, reverso
  del movimiento financiero, exclusión de reportes. Sin FE.
- **F2** — Anulación integrada con FE: dispara `EInvoiceService::cancel()`
  cuando el tenant la tiene. El corte de 48 h (D4) va en F1, no acá: es un
  límite del documento, no de la integración, y tiene que valer también para
  quien no emite electrónicamente.
- **F3** — Numeración de NC: doctype `nota_credito`, rango de timbrado propio
  por caja, UI en el tab Cajas.
- **F4** — Nota de crédito interna: documento, detalle, vínculo con la original,
  reverso de stock y plata. D1, D2 y D3 cerradas — no depende de nada.
- **F5** — NC electrónica: emisión con `documentType=5` + `associatedCdc`,
  reusando el mapper que ya existe.
- **F6** — UI en `/pos`: habilitar los botones con sus confirmaciones.

## Notas

- La anulación y la NC son cosas DISTINTAS y no intercambiables: anular borra el
  hecho económico (la venta no ocurrió), la NC lo corrige (ocurrió y se
  devuelve). Mezclarlas es el error clásico — por eso el owner las pidió
  separadas.
- Todo lo que revierta stock tiene que pasar por `Inventory::manageStock` y
  respetar la explosión recursiva de recetas: anular la venta de un combo tiene
  que devolver los insumos de TODOS sus niveles, no solo el primero.
- **Criterio general, del owner (2026-08-14):** lo que es política del negocio
  —si repone stock, si devuelve plata o deja saldo— no se cablea ni se deja
  librado al cajero por defecto: se configura por tenant, con `ask` como valor
  inicial para no imponer una respuesta a comercios que todavía no la
  definieron. Lo que el sistema SÍ decide solo es qué es técnicamente posible.
  Esa frontera vale para todo el módulo, no solo para D2 y D3.
- La ANULACIÓN usa las mismas reglas de reposición que la NC. El caso típico
  —anular a los dos minutos, antes de preparar— es justamente donde reponer
  insumos de una producción directa SÍ corresponde, y es la razón de que
  `settingReturnAllowIngredientReversal` exista en vez de prohibirlo siempre.
