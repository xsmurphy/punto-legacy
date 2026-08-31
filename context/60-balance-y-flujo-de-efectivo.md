# Balance gerencial y Flujo de efectivo — plan del módulo

> Estado: **plan cerrado 2026-08-31, en ejecución.** D1 la cerró el owner en la
> conversación que originó este doc.

## Por qué

Pedido del owner: *"analizá si tenemos un balance entre los reportes… también
una sección de flujo de efectivo"*.

**Balance: no existía.** Ni endpoint ni página.

**Flujo de efectivo: existía y estaba mal.** `/reports/cashflow` →
`CashflowService`, con tres defectos que lo vuelven no confiable:

1. **`cashSales` no es efectivo.** La query es
   `SUM(transactionTotal) WHERE transactionType IN (0,6)`, **sin filtrar medio
   de pago**: una venta con tarjeta o transferencia suma como efectivo. En un
   reporte de caja, ése es el error central.
2. **`initialCash` no es un saldo de apertura.** Se calcula como el NETO del
   período anterior de igual largo (`CashflowService.php:29`). Con un rango de 7
   días, el "saldo inicial" es lo que pasó los 7 días previos — no el efectivo
   que había el día 1. Y `accumulated` es la suma de dos períodos, no un
   acumulado.
3. **Ignora las cuentas de Finanzas**, que sí tienen saldos reales
   (`fin_account.currentbalance`). El sistema tiene DOS verdades sobre el
   efectivo y no coinciden — la peor de las tres, porque las dos se presentan
   como ciertas.

## Decisiones cerradas

### D1 — Punto NO hace contabilidad (owner, 2026-08-31)

Textual: *"nosotros no nos metemos en lo contable"*.

Consecuencias, y existen para frenar el scope creep que este módulo invita:

- **No hay plan de cuentas, ni asientos, ni partida doble, ni patrimonio
  contable.** El patrimonio del balance es DERIVADO (Activo − Pasivo), no una
  cuenta que alguien carga.
- `AccountingCode` (mig 167) NO es una semilla de plan de cuentas: existe para
  COPIAR TAL CUAL el código que dicta el sistema del contador, y su propio
  docblock lo dice. No construir nada encima.
- El destinatario es **el dueño**, no el contador. La pregunta que el reporte
  responde es "¿cuánto tengo y cuánto debo?", no "¿cierra mi balance?".

Si algún día se quiere contabilidad de verdad, es otro módulo y otra decisión —
no una evolución de éste.

### D2 — El flujo de efectivo se rehace sobre `fin_movement`, no se parchea

`fin_movement` (mig 72) ya tiene todo: `accountid`, `categoryid`, `kind`
(`income|expense`), `amount` (SIEMPRE positivo, el signo lo da `kind`), `date`,
`paymentmethod`, `source`, `outletid`, `status`. Y `fin_account` lleva
`openingbalance` + `currentbalance` (cache recomputable como
`openingbalance + Σ movimientos activos`).

Los tres defectos de arriba son consecuencia de construirlo sobre `transaction`.
Parchear la fuente equivocada dejaría los mismos números mal con más código.

**Invariante del reporte nuevo, y su propio test:**

```
saldo inicial + entradas − salidas = saldo final
```

El reporte actual no puede satisfacerlo. El nuevo cuadra por construcción,
porque los tres términos salen de la misma tabla.

### D3 — El balance es a UNA FECHA, y en la v1 esa fecha es hoy

Un balance es una foto, no un rango — y el resto de los reportes del panel son
por rango. La diferencia es conceptual y hay que respetarla en la UI.

En la v1 la foto es **al día de hoy**, porque los saldos de cuentas y las
cuentas por cobrar/pagar se leen de su estado actual. Reconstruir un balance a
una fecha pasada exige rearmar saldos desde `fin_movement` y valorizar el
inventario contra el ledger de stock a esa fecha — es posible (los datos
están) pero es otro trabajo, y un dueño mira el balance de hoy.

### D4 — Rubros de la v1

**ACTIVO**
- Efectivo y bancos — `fin_account.currentbalance` por cuenta.
- Cuentas por cobrar — `OpenInvoicesService::general('income')`, neto de pagos.
- Inventario valorizado — `Inventory::onHandBulk` (`onHand × cogs`).

**PASIVO**
- Cuentas por pagar — `OpenInvoicesService::general('outcome')`.
- Obligaciones futuras — `ObligationsService::list()` (cheques emitidos, cuotas
  de crédito, compras con vencimiento).

**PATRIMONIO NETO** = Activo − Pasivo. Derivado, nunca capturado (D1).

### D5 — Lo que falta y se declara, no se disimula

**No hay activo fijo.** Un comercio tiene heladeras, vitrinas, vehículos, y
Punto no los modela en ningún lado. El patrimonio derivado queda entonces
SUBESTIMADO, y el reporte tiene que decirlo en la propia pantalla — no en un
comentario del código. Un número que se presenta como patrimonio y no incluye la
mitad de los bienes es peor que no mostrarlo.

Capturar activo fijo sería un módulo nuevo (altas, bajas, depreciación) y choca
con D1 en su borde. Queda fuera.

## Fases

| Fase | Qué |
|---|---|
| **B1** | `CashFlowService` reescrito sobre `fin_movement` + saldos por cuenta + entradas/salidas por categoría + el invariante de cuadre. Reemplaza al viejo en `/v1/reports/cashflow` |
| **B2** | Página `/reports/cashflow` actualizada |
| **B3** | `BalanceService` + `/v1/reports/balance` |
| **B4** | Página `/reports/balance` con la advertencia de D5 visible |

## Trampas

- `fin_movement.amount` es SIEMPRE positivo: el signo lo da `kind`. Sumar sin
  mirar `kind` da un total sin sentido.
- `status = 0` es anulado — **filtrarlo siempre**, o los movimientos revertidos
  se cuentan como reales.
- `fin_account.outletid` NULL = cuenta global de todas las sucursales. Un
  balance por sucursal no puede simplemente filtrar por outlet: hay que decidir
  qué hacer con las globales (en la v1 se muestran completas y se aclara).
- `currentbalance` es un CACHE. Para el saldo a una fecha hay que recomputar
  desde `openingbalance + Σ movimientos`, no leer la columna.
