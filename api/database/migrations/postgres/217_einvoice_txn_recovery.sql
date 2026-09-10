-- 217_einvoice_txn_recovery.sql
-- El identificador con el que se RECUPERA un documento electrónico huérfano.
--
-- El problema que cierra, con los dos casos reales que lo destaparon
-- (2026-09-10, producción):
--
--   1. La nota de crédito nº 2 se emitió de verdad —el motor le dio CDC y un
--      `txnId`— y terminó igual en `error` con los ocho intentos agotados: en
--      el medio se deployó la serie propia de la NC, el payload cambió y la
--      `Idempotency-Key` (estable por documento) empezó a chocar con el body
--      original. Quedó un documento fiscal existente del lado del motor y una
--      fila nuestra que decía `error`, sin ninguna llave para volver a
--      encontrarlo. Averiguar si tenía efecto fiscal costó consultar la base
--      del motor a mano.
--   2. La factura 001-002-0000615 quedó `issued` con `provider_number` NULL, y
--      como `reconcile()` filtraba por `provider_number IS NOT NULL` no se
--      volvía a mirar NUNCA: sin esa llave no se podía consultar su estado en
--      SIFEN, ni bajar su KuDE, ni cancelarla.
--
-- ── Por qué una COLUMNA y no leerlo del `provider_response` ───────────────
--
-- El `txnId` YA venía en la respuesta de emisión y YA quedaba guardado dentro
-- del jsonb `provider_response` — nadie lo leía, pero estaba. No alcanza, por
-- tres razones que son las que convierten al dato en irrecuperable:
--
--   a) `provider_response` se PISA ENTERO en cada intento (el UPDATE del
--      camino de error lo reemplaza con la respuesta cruda nueva). El `txnId`
--      del intento que sí creó el documento no sobrevive al intento siguiente
--      que falló sin devolver ninguno. Es exactamente cómo se perdió el del
--      caso 1.
--   b) La llave de recuperación tiene que ser WRITE-ONCE: se escribe con
--      `COALESCE(provider_txn_id, ?)`, así que el primer valor que llega gana
--      y ningún intento posterior puede borrarlo. Un jsonb que se reemplaza
--      completo no puede expresar esa semántica.
--   c) Hay que poder FILTRAR por él (`WHERE status='error' AND
--      provider_txn_id IS NOT NULL` = "documentos que pueden estar emitidos
--      del otro lado"). Colgar un filtro de un camino de jsonb es la misma
--      clase de dependencia que dejó huérfana a la 615.
--
-- TEXT y no UUID a propósito: hoy el motor devuelve un UUID v7, pero esta
-- columna es la ÚLTIMA defensa contra perder un documento fiscal — si algún
-- día el formato cambia, un cast estricto haría fallar el UPDATE y perderíamos
-- justo el dato que existe para que nada se pierda. Mismo criterio que
-- `einvoice_account.provider_tenant_ref` (mig 206).

ALTER TABLE einvoice_document
  ADD COLUMN IF NOT EXISTS provider_txn_id TEXT;

COMMENT ON COLUMN einvoice_document.provider_txn_id IS
  'Identificador de la TRANSACCIÓN de emisión del motor (FE-PY: `txnId`, UUID v7). '
  'Lo devuelve el POST de emisión SIEMPRE, incluso cuando el documento termina en '
  'error y no llegó a generarse un CDC — por eso es la llave de recuperación '
  'directa (`GET /v1/tenants/{ref}/de/txn/{txnId}`) y no el CDC. Write-once: se '
  'escribe con COALESCE, nunca se pisa. Antes de reintentar un documento en '
  '`error` el drainer pregunta por acá si ya existe del otro lado.';

-- ── Backfill 1: rescatar los txnId que ya están enterrados en el jsonb ────
-- Recupera a los documentos que hoy están huérfanos en producción, incluida la
-- NC del caso 1. `jsonb_typeof` guarda contra una respuesta que no sea objeto.
-- Nada de `?` como operador jsonb: PDO lo reescribe a placeholder y tira el
-- boot (migs 74/77) — acá alcanza con `->>` IS NOT NULL.
UPDATE einvoice_document
   SET provider_txn_id = provider_response->>'txnId'
 WHERE provider_txn_id IS NULL
   AND provider_response IS NOT NULL
   AND jsonb_typeof(provider_response) = 'object'
   AND provider_response->>'txnId' IS NOT NULL
   AND provider_response->>'txnId' <> '';

-- ── Backfill 2: el caso 2, la 615 y sus hermanas ──────────────────────────
-- `provider_number` es un CACHÉ de la llave con la que el motor reconcilia el
-- documento, y en FE-PY esa llave ES el CDC (COMMENT de la mig 214). Un
-- documento `issued` con CDC y sin `provider_number` no es un documento sin
-- llave: es la misma llave sin copiar. Se completa acá, y `reconcile()` deja
-- además de depender de esta columna para no volver a fabricar huérfanos.
UPDATE einvoice_document
   SET provider_number = cdc
 WHERE status = 'issued'
   AND provider_number IS NULL
   AND cdc IS NOT NULL
   AND cdc <> '';
