# Cheques integrados + Previsión + Créditos básicos

> Plan cerrado con el owner el 2026-07-30. Decisiones NO relitigables:
> el cheque nace del pago (no de un form aparte); POS incluido con nro de
> cheque manual del cajero; créditos = básico (total / cuotas iguales /
> primera fecha, mensual, sin interés calculado).

## Contexto

`/finanzas/cheques` hoy es un registro manual aislado: `fin_check` con ciclo
`pending → deposited → cleared/bounced/cancelled`, movimiento financiero solo
al `cleared` (idempotente por UNIQUE `fin_movement (source='check', sourceid)`).
Nada lo alimenta: la compra tiene "Cheque" en el select pero el backend no crea
`fin_check` ni captura cuenta de pago (deuda "Parte 2" declarada en
`FinanceLedger::recordPurchase`, incidente 737M), y `CreditPaymentService`
tampoco. El POS ya tiene el mecanismo exacto para pedir el nro de cheque:
`requiresIdentifier`/`identifierLabel` en el medio de pago (taxonomy).

## F1 — El cheque nace del pago

**Mig 102** (un solo archivo con F2):
- `fin_check.transactionid uuid NULL` + UNIQUE parcial
  `(companyid, transactionid) WHERE transactionid IS NOT NULL` (idempotencia:
  una transacción genera a lo sumo un cheque).
- Seed/permitir `systemKey='check'` en taxonomy paymentMethod (hoy el union es
  cash|giftcard|internal). El seed crea el método "Cheque" con
  `requiresIdentifier=true`, `identifierLabel='Nro de cheque'` si no existe.
  `systemKey` es la identidad de comportamiento — NUNCA matchear por nombre.

**Backend — creación automática del cheque** (helper único, no tres copias):
- `CheckService::createFromPayment(companyId, transactionId, line, direction, ...)`
  idempotente por el UNIQUE de transactionid. Datos: `checknumber` = identifier
  capturado, `contactid`, `amount` = monto de la línea cheque, `duedate` si se
  capturó, `transactionid`. Estado inicial `pending`.
- Call-sites: venta POS (SaleService, direction=`received`), pago de factura a
  crédito (CreditPaymentService, `received`), compra (`issued`).
- El cheque NO genera movimiento al nacer — el flujo existente de `cleared`
  sigue siendo el único que impacta saldo. Cero doble contabilización: cuando
  el medio "Cheque" NO tenga `finAccountMap` a una cuenta real, la línea de
  pago no genera movimiento directo (mismo filtro que storeCredit/giftcard en
  `recordPaymentLines`) y la plata entra recién al efectivizar el cheque.

**Compras — resolver la Parte 2 de raíz** (no parche solo-cheque): el form de
compra persiste la línea de pago con método + cuenta (via finAccountMap, igual
que ventas) para TODOS los métodos, y `recordPurchase` deja de saltear el
movimiento. Cuando el método es cheque: campos banco/nro/vencimiento en el
form, y aplica la regla de arriba (sin movimiento directo, nace `fin_check`).

**POS**: el método con `systemKey='check'` ya dispara el prompt de identifier
existente (nro de cheque obligatorio). Sin cambios de layout del pay-dialog
(memoria muscular del cajero — posiciones estables). Funciona offline igual
que cualquier venta: el `fin_check` lo crea el server al registrar la venta
(sync incluido), no el cliente.

## F2 — Créditos básicos (fin_loan)

**Mig 102** (mismo archivo):
- `fin_loan`: loanid uuid PK, companyid, name (acreedor/descripción),
  principal numeric(14,2), installmentcount int, firstduedate, frequency
  (fijo 'monthly' v1), status ('active'|'settled'|'cancelled'), created_at,
  data jsonb.
- `fin_loan_installment`: installmentid uuid PK, loanid FK, companyid, seq int,
  duedate, amount numeric(14,2), status ('pending'|'paid'), paiddate,
  movementid uuid NULL (referencia lógica), UNIQUE (loanid, seq).

Generación: cuotas iguales `round(principal/n, 2)` con ajuste de redondeo en
la última. `LoanService`: create (genera cuotas), list, find, cancel, y
`payInstallment(id, accountId)` → `fin_movement` kind=expense,
source='loan_installment', sourceid=installmentid (idempotente por el UNIQUE
existente de fin_movement), descuenta saldo de cuenta; reversa si se desmarca.
Endpoint `/v1/finance/loans` (patrón exacto de `checks.php`, permiso
`finance.manage`). UI `/finanzas/creditos`: DataTable + dialog alta
(MoneyInput para montos) + detalle con cuotas y acción "Marcar pagada"
(pide cuenta).

## F3 — Previsión

Endpoint `/v1/finance/forecast?from=&to=`: unión de obligaciones futuras:
- Cheques emitidos `pending|deposited` por `duedate`.
- Cuotas `pending` de fin_loan por `duedate`.
- Compras a crédito impagas por vencimiento (cuentas por pagar).
- (income, separado) Cheques recibidos pendientes de depositar/cobrar.

UI `/finanzas/prevision`: lista ordenada por vencimiento con badge de tipo
(Cheque/Cuota/Factura), totales por semana/mes, filtro rango de fechas,
vencidos resaltados. Cada fila linkea a su origen. Solo lectura — las
acciones viven en cada módulo.

## Fuera de alcance v1

Intereses/amortización, frecuencias no mensuales, chequeras/talonarios,
impresión de cheques, multi-moneda, notificaciones de vencimiento (el módulo
de notificaciones de stock mínimo podría reusarse después).
