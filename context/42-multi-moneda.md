# 42 — Multi-moneda (ventas, compras y arqueo)

> Estado: **feature request** (2026-08-17, owner). Sin planificar en detalle,
> sin decisiones cerradas, sin implementar. Este doc registra el pedido y el
> encuadre para no perderlo.

## Pedido del owner, textual

- Que las **ventas y compras** se puedan hacer en múltiples monedas.
- **Restricción legal PY**: por ley solo se pueden EMITIR facturas en **PYG o
  USD**. No es una preferencia nuestra ni del comercio — es el marco fiscal.
- Que el **cajero pueda recibir dinero en otras monedas** (BRL, ARS, USD…) y
  quede registrada la moneda y el monto recibido.
- Que al **cierre de caja** el cajero sepa cuánto tiene que tener en cada
  moneda: BRL, USD, ARS, PYG, etc.

## Encuadre — son DOS problemas, no uno

Mezclarlos es el error a evitar:

1. **Moneda del DOCUMENTO** (en qué moneda se emite la factura). Acotada por
   ley a PYG o USD. Afecta timbrado, numeración, impuestos, reportes fiscales y
   facturación electrónica (SIFEN tiene su propio manejo de moneda y tipo de
   cambio).
2. **Moneda del COBRO** (con qué billetes paga el cliente). Libre: puede pagar
   una factura en PYG con reales o pesos. No cambia el documento — cambia el
   arqueo y el arqueo es por moneda.

Un cliente puede pagar una factura **en PYG** con **BRL**, y las dos cosas son
correctas a la vez. El sistema tiene que poder expresar eso.

## Lo que ya existe (relevado por encima, verificar antes de planificar)

- `settingCurrency` en `company.config` — moneda única del tenant.
- `transaction.transactionCurrency` — columna EXISTENTE (aparece en el schema
  y en el mapa de columnas). Verificar si se escribe o está muerta.
- `fin_movement` / `fin_account` — el libro financiero es por cuenta; una
  cuenta por moneda podría ser el camino natural para el arqueo.
- Los montos se guardan en `NUMERIC(14,2)` sin moneda asociada: hoy la moneda
  es implícita y global.

## Preguntas abiertas (para cuando se planifique)

- **Tipo de cambio**: ¿se carga a mano por día, se toma de una fuente, se fija
  por sucursal? ¿Se congela en la transacción (imprescindible para auditar) o
  se recalcula? Congelarlo es casi seguro obligatorio: el valor del documento
  no puede cambiar retroactivamente.
- **Vuelto**: si paga en BRL una factura en PYG, ¿el vuelto sale en PYG o en
  BRL? Cambia el arqueo de las dos monedas.
- **Arqueo**: ¿saldo esperado por moneda de forma independiente (no se
  convierten entre sí), o todo convertido a la moneda base? Lo primero es lo
  que el owner describió ("cuánto tiene que tener en BRL, USD, ARS, PYG").
- **Precios**: ¿un ítem tiene precio por moneda, o un solo precio en moneda base
  que se convierte al cambio del día? Lo segundo es más simple pero deja el
  precio final a merced del tipo de cambio.
- **Reportes y rollups**: las tablas de rollup (context/18) suman importes sin
  moneda. Con multi-moneda hay que decidir si se acumulan convertidos a base,
  por moneda, o las dos cosas.
- **Facturación electrónica**: qué exige SIFEN cuando el documento va en USD
  (tipo de cambio en el DE, condición). Revisar antes de diseñar.

## Nota

El alcance más chico que ya entrega valor es el **punto 2 solo**: registrar la
moneda y el monto recibido en el cobro, y que el arqueo muestre el esperado por
moneda. No toca documento, ni timbrado, ni facturación electrónica, ni precios
— y es exactamente el dolor que el owner describió del cierre de caja. La
moneda del documento (punto 1) es un proyecto bastante más grande y puede ir
después.
