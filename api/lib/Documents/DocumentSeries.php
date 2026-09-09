<?php
declare(strict_types=1);

namespace Punto\Api\Documents;

/**
 * Identidad de una SERIE de numeración fiscal: (timbrado, punto de expedición).
 *
 * El correlativo no existe solo. Un número de comprobante paraguayo es la
 * tripleta (timbrado, punto de expedición, correlativo) — context/29 §1-2 —
 * y `001-001-1234567` y `001-002-1234567` conviven legalmente porque son dos
 * ramas de numeración independientes.
 *
 * Consecuencia, y decisión del owner (2026-09-09): **cambiar el timbrado o el
 * punto de expedición abre una serie NUEVA.** No se resetea ningún contador,
 * nace una fila nueva en `document_sequence` y la vieja queda como registro de
 * lo que esa serie emitió.
 *
 * Antes de la mig 209, `document_sequence` tenía UNA fila por
 * (empresa, documento, scope) para toda la vida de la caja, y `prefix` era un
 * atributo mutable de esa fila: al editar el punto de expedición en el panel,
 * `RegisterAdminService::seedSequence()` pisaba el prefijo y dejaba el
 * contador. Así fue como una caja mandó el número 838 contra un punto de
 * expedición que iba por 614. Desde la mig 209 la serie ES la clave.
 *
 * ── Por qué un objeto y no dos strings sueltos ──
 * Qué constituye una serie es una REGLA FISCAL, y tiene que estar en un solo
 * lugar. Con dos parámetros sueltos, cada uno de los ~15 call-sites de
 * `DocumentNumber` decidiría por su cuenta si normaliza, si trimea y qué hace
 * con el vacío — y alcanza con que uno difiera para que su documento resuelva
 * a otra fila y arranque un contador paralelo.
 *
 * ── El vacío es "sin serie fiscal", no "desconocido" ──
 * `none()` es la serie de los documentos que NO son fiscales: merma,
 * producción, orden, orden de pago, remisión, conteo, transferencia,
 * cotización. Esos tienen una sola secuencia por scope, igual que antes de la
 * mig 209, y las columnas quedan en '' (NOT NULL) — nunca NULL, porque en un
 * índice único de Postgres `NULL <> NULL` y el `ON CONFLICT` del asignador
 * dejaría de matchear, creando una fila nueva por documento emitido.
 */
final class DocumentSeries
{
    public readonly string $auth;
    public readonly string $prefix;

    public function __construct(?string $auth = null, ?string $prefix = null)
    {
        $this->auth   = trim((string) $auth);
        $this->prefix = trim((string) $prefix);
    }

    /** Documento sin serie fiscal (no lleva timbrado ni punto de expedición). */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Serie VIGENTE de una caja, leída de su configuración fiscal
     * (`register.data`, JSONB de la mig 26 — el único lugar donde una persona
     * edita el timbrado y el punto de expedición; todo lo demás deriva).
     *
     * OJO: esto es el estado ACTUAL de la caja. Para una venta ya emitida hay
     * que usar la serie CONGELADA en la transacción (`fromFrozen()`), no esta:
     * si el admin cambió el punto entre la emisión offline y el sync, avanzar
     * la serie vigente con un número de la serie vieja es exactamente el bug
     * que la mig 209 corrige.
     *
     * `ncmExecute('SELECT data ...')` aplana el JSONB (Query::flattenJsonb):
     * las claves llegan directo en la fila y `$row['data']` no existe. Con
     * count=1 devuelve un CaseInsensitiveArray, NO un array plano — is_array()
     * sobre eso da false (footgun ya pisado en lease.php), de ahí el chequeo
     * con ArrayAccess.
     *
     * Fail-SOFT: una caja inexistente o sin timbrado devuelve `none()`. No
     * puede lanzar — se la llama desde el camino de emisión, y §53 manda que
     * el backend nunca rechace una venta ya emitida por el device.
     */
    public static function forRegister(string $registerId, string $companyId): self
    {
        if ($registerId === '' || $companyId === '') {
            return self::none();
        }

        $row = ncmExecute(
            'SELECT data FROM register WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$registerId, $companyId]
        );
        if (!(is_array($row) || $row instanceof \ArrayAccess)) {
            return self::none();
        }

        return new self(
            (string) ($row['registerInvoiceAuth']   ?? ''),
            (string) ($row['registerInvoicePrefix'] ?? ''),
        );
    }

    /**
     * Serie con la que se EMITIÓ un documento, desde lo congelado en su fila
     * de `transaction` (`invoiceauth` de la mig 145 + `invoiceprefix` de la
     * mig 209).
     *
     * Es la que vale para reconciliar la secuencia después de una venta: el
     * documento ya está en la calle con ESTOS datos impresos.
     *
     * @param array<string,mixed>|\ArrayAccess $tx fila de `transaction`
     */
    public static function fromFrozen(array|\ArrayAccess $tx): self
    {
        return new self(
            (string) ($tx['invoiceauth']   ?? ''),
            (string) ($tx['invoiceprefix'] ?? ''),
        );
    }

    /**
     * Serie congelada de una venta YA PERSISTIDA, por id de transacción.
     *
     * Lee de `transaction_registry` y no de `transaction`: el registry es la
     * tabla NO particionada que sostiene las unicidades globales (mig 156),
     * tiene `transactionid` como PK —un solo lookup, sin recorrer particiones—
     * y desde la mig 209 lleva las dos mitades de la serie. Lo mantiene en
     * sync el trigger AFTER INSERT, que ya corrió dentro de la transacción de
     * la venta.
     *
     * Fail-SOFT igual que `forRegister()`: sin fila devuelve `none()`. Se la
     * llama después del commit, en el camino best-effort de reconciliación de
     * la secuencia, y §53 manda que nada de eso pueda voltear una venta ya
     * emitida.
     */
    public static function forTransaction(string $transactionId, string $companyId): self
    {
        if ($transactionId === '' || $companyId === '') {
            return self::none();
        }

        $row = ncmExecute(
            'SELECT invoiceauth, invoiceprefix FROM transaction_registry
              WHERE transactionid = ? AND companyid = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!(is_array($row) || $row instanceof \ArrayAccess)) {
            return self::none();
        }

        return self::fromFrozen($row);
    }

    /** ¿Tiene timbrado o punto de expedición declarado? */
    public function isFiscal(): bool
    {
        return $this->auth !== '' || $this->prefix !== '';
    }

    /**
     * Etiqueta legible para logs y mensajes de error: `12345678/001-001`.
     * NO es una clave: la identidad en BD son las dos columnas, no este string.
     */
    public function label(): string
    {
        return $this->isFiscal()
            ? ($this->auth . '/' . $this->prefix)
            : '(sin serie fiscal)';
    }
}
