# Hand-off — 2026-08-09

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Sesión de triaje amplio, entreverada con al menos dos sesiones paralelas sobre
el mismo working tree (detalle de transacción, giftcards, espacios). Ejes
propios: cerrar deuda de impuestos (orden manual + límite de columna que
abortaba ventas), un canal de cobro nuevo (Bancard QR), dos gaps de finanzas
(nota de crédito de compra, recibo que cancela varias facturas), un guard
fiscal (timbrado vencido) y soporte de clientes extranjeros para Paraguay.

## Estado al cerrar

Todo commiteado, pusheado a `main` y **deployado**. Migraciones 121-125
corridas y verificadas en el servidor de prueba (167.71.165.221, tenant ICAS).
El detalle por feature está en la bitácora y en los commits; acá solo lo que
condiciona retomar:

- **Bancard**: el canal QR está cableado end-to-end pero **sin probar contra
  el PSP real** (falta credencial válida; el shape de la respuesta se parsea
  defensivamente en `frontend/lib/payments/bancard-qr.ts` y falla visible si
  no encuentra ni imagen ni payload). El canal de terminal físico tiene UI y
  config de IP por caja, pero **no envía nada** — falta el PDF de Bancard,
  que el owner no tiene. uPay quedó como "Próximamente" (sin API pública).
- **Recibo multi-factura**: backend + UI del panel listos; el POS sigue
  cobrando de a una factura, a propósito.
- **Clientes extranjeros**: los códigos 14 y 17 de la Tabla 3 no están
  confirmados contra Factomate, así que la emisión ABORTA para esos casos en
  vez de arriesgar un receptor mal declarado ante SIFEN. El selector los
  marca "sin factura electrónica". Para habilitarlos: confirmar contra
  `GET /api/IdentityDocumentType/get` de la cuenta real.

## Archivos y cambios

- `context/40-reportes-fiscales-plan.md` — plan RG90 cerrado, en worktree
  sin mergear (ver Próximo paso).
- `frontend/lib/payments/bancard-qr.ts`, `api/v1/bancard.php` — canal QR.
- `api/v1/numbering/lease.php` + `RegisterService::invoiceAuthError()` — guard
  de timbrado vencido (es el punto único donde se asigna número fiscal).
- `api/lib/Sales/SaleToInvoiceMapper.php::mapIdType()` — Tabla 3 SET →
  codificación propia de Factomate. Son DOS tablas distintas, no mezclarlas.
- `TransactionLinkService::sumDerivedAmounts()` — superficie única de "cuánto
  se saldó" de un documento; todo lector nuevo pasa por ahí.
- Migraciones 121-125 (la 122 con el fix ajeno `52bfde6f`).

## Callejones sin salida

1. **Mig 122 tiró todos los deploys ~4h.** El bloque `DO` buscaba el CHECK
   viejo con `pg_get_constraintdef(...) ILIKE '%kind%IN%'`, pero Postgres
   normaliza `kind IN (...)` a `kind = ANY (ARRAY[...])` al persistir — el
   patrón nunca matcheaba, el DROP no corría, el `ADD CONSTRAINT` chocaba con
   el nombre autogenerado de la mig 115. Lo peor: `migrate.php` hacía `exit 1`,
   Coolify dejaba viva la imagen anterior, y el health respondía 200 con logs
   de "todo al día" — de la imagen VIEJA. Todo lo pusheado entre 19:24 y 23:44
   no llegó a prod sin ninguna señal visible. Para verificar un deploy real:
   mirar `schema_migrations` o los logs `[migrate]`, nunca el health check.
   Fix ajeno en `52bfde6f` (busca el constraint por columna, no por texto).
2. **Tres sesiones escribiendo el mismo working tree** — ediciones propias
   terminaron en commits ajenos dos veces (`2c555b39`, `1dc99c45`), un
   sub-agente abortó su parte de frontend por choque en vivo. Nada se perdió
   pero quedó bajo mensajes de commit equivocados. Con 2+ sesiones en
   paralelo, usar worktrees aislados desde el arranque.
3. Dos sub-agentes delegados terminaron el trabajo pero no commitearon,
   esperando la notificación de un `code-reviewer` que ellos mismos habían
   spawneado — hubo que verificar el working tree y commitear a mano.
4. `text-2xl` en inputs del POS nunca se aplicó: `.pos-scope input[type="text"]`
   en globals.css tiene más especificidad que la clase utilitaria. El síntoma
   reportado era el `h-14`, no el tamaño de fuente (documentado en
   `context/20-design-system.md` §7).
5. DB local de desarrollo rota: Postgres.app ya no existe pero el proceso
   sigue vivo en 5432 y rechaza conexiones. Todo se verificó contra el
   servidor de prueba, no en local.

## Próximo paso

Mergear `docs/reportes-fiscales` (worktree `.claude/worktrees/agent-aaeff690158acbfce`)
a main: el plan del generador RG90 está cerrado en `context/40-reportes-fiscales-plan.md`,
falta implementar. Bloqueante real antes de arrancar: compras NO congela el
IVA por tasa (`toTaxObj` no existe en `api/lib/Purchases/`, a diferencia de
ventas desde F2a) — sin eso el archivo de compras sale mal.

## Trampas conocidas

- **Estado manual en prod (tenant ICAS, servidor de prueba)**: "Control de
  caja a ciegas" quedó ACTIVADO en la caja "Nueva Caja" de Shopping Mariano
  (apagar en Sucursales → Cajas → Editar si no se quiere así). Módulo Bancard
  activado con ambos canales, provisionó/adoptó el medio de pago "QR"
  (`systemKey='qr'`). Medios de pago e impuestos reordenados por drag&drop
  (Efectivo e IVA 10% al tope).
- `/reports/drawers`: la mutación se gatea con `reports.drawers.view` (quien
  puede ver puede reescribir/borrar un arqueo) y `correct()` no deja rastro
  de quién corrigió ni el valor anterior. Preexistente, señalado al owner,
  no corregido.
- El número de comprobante no se congela por transacción:
  `transaction.invoicePrefix` queda vacío, el EEE-PPP+timbrado se reconstruye
  AL LEER desde la config actual de la caja. Si la caja renovó timbrado,
  ventas viejas se reportan con el timbrado nuevo — riesgo para reportes
  fiscales, sin resolver.
- Worktrees viejos sin limpiar: `docs/reportes-fiscales` (a propósito, no
  borrar), `lucid-lamarr-10832a`, `silly-ramanujan-b7433c`.
- Bancard QR y Caja POS sin probar contra el proveedor real — falta
  credencial válida y una prueba con QR real para confirmar el shape de la
  respuesta.
