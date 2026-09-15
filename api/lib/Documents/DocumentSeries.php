<?php
declare(strict_types=1);

namespace Punto\Api\Documents;

/**
 * Identidad de una SERIE de numeración fiscal: (timbrado, punto de expedición,
 * serie SIFEN).
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
 * ── La serie SIFEN (`dSerieNum`) es la tercera parte (mig 223) ──
 * SIFEN permite informar dos letras de serie por punto de expedición, y si el
 * punto ya emitió con serie exige LA MISMA en todo documento posterior
 * (rechazo `1110 — Serie informada incorrecta`). Al cambiarla (AA → AB) la
 * numeración reinicia, así que es identidad por el mismo motivo que el
 * timbrado y el punto: cambiarla abre una secuencia nueva. Vacía = sin serie,
 * que es el default (decisión del owner 2026-09-15: opcional, nunca
 * precargada).
 *
 * A diferencia del timbrado y el punto —que son de la CAJA y los comparten
 * todos sus documentos fiscales—, la serie es POR TIPO DE DOCUMENTO: la
 * numeración de SIFEN es por doctype, y un sistema anterior pudo emitir
 * facturas con serie y notas de crédito sin ella. Por eso `forRegister()` pide
 * el doctype y la caja guarda una clave por documento (`SERIE_CONFIG_KEYS`).
 *
 * ── Por qué un objeto y no strings sueltos ──
 * Qué constituye una serie es una REGLA FISCAL, y tiene que estar en un solo
 * lugar. Con parámetros sueltos, cada uno de los ~15 call-sites de
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
    /**
     * Formato de `dSerieNum`: dos letras mayúsculas. Es el mismo que valida el
     * motor (422 si no cumple) y el CHECK `document_sequence_serie_format`
     * (mig 223). El front lo repite en `lib/documents/serie.ts`.
     */
    public const SERIE_PATTERN = '/^[A-Z]{2}$/';

    /**
     * Doctypes con serie fiscal y la clave de `register.data` donde la caja
     * guarda su serie SIFEN. Es también la lista de documentos FISCALES de la
     * caja: los que heredan su timbrado y su punto de expedición (el resto
     * —cotización, orden, merma…— tiene serie vacía).
     *
     * Sumar un documento fiscal (ND, remisión) es sumar una entrada acá: la
     * lectura de la caja, el filtro SQL de la serie vigente y la validación del
     * panel salen de este mapa.
     */
    public const SERIE_CONFIG_KEYS = [
        'factura'      => 'registerInvoiceSerie',
        'nota_credito' => 'registerCreditNoteSerie',
    ];

    public readonly string $auth;
    public readonly string $prefix;
    /** Serie SIFEN (`dSerieNum`), '' = sin serie. Siempre en mayúsculas. */
    public readonly string $serie;

    public function __construct(?string $auth = null, ?string $prefix = null, ?string $serie = null)
    {
        $this->auth   = trim((string) $auth);
        $this->prefix = trim((string) $prefix);
        // Sin punto de expedición no hay serie fiscal a la que la serie SIFEN
        // pueda pertenecer: se ignora. Si no, una caja sin punto con una serie
        // cargada abriría una secuencia ('', '', 'AA') mientras la venta congela
        // la serie en NULL — dos lectores con identidades distintas para el
        // mismo documento. El panel ya rechaza guardarla así; esto cubre lo que
        // llegue por otro camino.
        $this->serie  = $this->prefix === '' ? '' : self::normalizeSerie($serie);
    }

    /** Documento sin serie fiscal (no lleva timbrado ni punto de expedición). */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Serie tal como se guarda: sin espacios y en mayúsculas. NO valida — un
     * valor que no cumple el formato sale igual y lo rechaza quien corresponde
     * (el panel al guardar, el CHECK de la base, el mapper antes de emitir).
     * Esto corre también en caminos de emisión que por §53 no pueden lanzar.
     */
    public static function normalizeSerie(mixed $serie): string
    {
        return strtoupper(trim((string) ($serie ?? '')));
    }

    /**
     * Serie SIFEN leída de la CONFIGURACIÓN de la caja (`register.data`), para
     * los caminos de emisión. Una serie con otra forma NO puede rechazar una
     * venta ya emitida (context/08 §53): se descarta (queda "sin serie") y se
     * deja constancia en el log. El panel no permite guardarla así; esto cubre
     * datos que llegaron por otro camino.
     */
    public static function serieFromConfig(mixed $raw, string $where): string
    {
        $serie = self::normalizeSerie($raw);
        if (!self::isValidSerie($serie)) {
            error_log('[DocumentSeries] serie inválida en la configuración de la caja (' . $where . '): '
                . json_encode($serie) . ' — se emite sin serie.');
            return '';
        }
        return $serie;
    }

    /** ¿Es una serie emitible? Vacía cuenta como válida: es "sin serie". */
    public static function isValidSerie(string $serie): bool
    {
        return $serie === '' || preg_match(self::SERIE_PATTERN, $serie) === 1;
    }

    /** ¿El doctype lleva serie fiscal (timbrado, punto y serie SIFEN)? */
    public static function isFiscalDocType(string $docType): bool
    {
        return array_key_exists($docType, self::SERIE_CONFIG_KEYS);
    }

    /**
     * Serie VIGENTE de una caja para un tipo de documento, leída de su
     * configuración fiscal (`register.data`, JSONB de la mig 26 — el único
     * lugar donde una persona edita el timbrado, el punto de expedición y la
     * serie; todo lo demás deriva).
     *
     * OJO: esto es el estado ACTUAL de la caja. Para una venta ya emitida hay
     * que usar la serie CONGELADA en la transacción (`fromFrozen()`), no esta:
     * si el admin cambió el punto entre la emisión offline y el sync, avanzar
     * la serie vigente con un número de la serie vieja es exactamente el bug
     * que la mig 209 corrige.
     *
     * `$docType` elige la serie SIFEN: el timbrado y el punto son de la caja,
     * pero la serie es de cada talonario (`SERIE_CONFIG_KEYS`). Un doctype no
     * fiscal devuelve `none()` — no hereda el timbrado de la caja.
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
    public static function forRegister(string $registerId, string $companyId, string $docType): self
    {
        if ($registerId === '' || $companyId === '' || !self::isFiscalDocType($docType)) {
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
            self::serieFromConfig($row[self::SERIE_CONFIG_KEYS[$docType]] ?? '', 'caja ' . $registerId . ', ' . $docType),
        );
    }

    /**
     * Serie con la que se EMITIÓ un documento, desde lo congelado en su fila
     * de `transaction` (`invoiceauth` de la mig 145, `invoiceprefix` de la
     * mig 209 e `invoiceserie` de la mig 223).
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
            (string) ($tx['invoiceserie']  ?? ''),
        );
    }

    /**
     * Serie congelada de una venta YA PERSISTIDA, por id de transacción.
     *
     * Lee de `transaction_registry` y no de `transaction`: el registry es la
     * tabla NO particionada que sostiene las unicidades globales (mig 156),
     * tiene `transactionid` como PK —un solo lookup, sin recorrer particiones—
     * y desde las migs 209/223 lleva las tres partes de la serie. Lo mantiene
     * en sync el trigger AFTER INSERT, que ya corrió dentro de la transacción
     * de la venta.
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
            'SELECT invoiceauth, invoiceprefix, invoiceserie FROM transaction_registry
              WHERE transactionid = ? AND companyid = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!(is_array($row) || $row instanceof \ArrayAccess)) {
            return self::none();
        }

        return self::fromFrozen($row);
    }

    /** ¿Tiene timbrado, punto de expedición o serie declarados? */
    public function isFiscal(): bool
    {
        return $this->auth !== '' || $this->prefix !== '' || $this->serie !== '';
    }

    /**
     * Etiqueta legible para logs y mensajes de error: `12345678/001-001` o
     * `12345678/001-001/AA`. NO es una clave: la identidad en BD son las
     * columnas, no este string.
     */
    public function label(): string
    {
        if (!$this->isFiscal()) {
            return '(sin serie fiscal)';
        }

        return $this->auth . '/' . $this->prefix . ($this->serie !== '' ? '/' . $this->serie : '');
    }

    /**
     * Predicado SQL "esta fila de `document_sequence` es la serie VIGENTE de su
     * caja": timbrado, punto y serie de la fila iguales a los que la caja tiene
     * configurados hoy para ESE doctype. Los doctypes no fiscales comparan
     * contra la serie vacía.
     *
     * Existe porque desde la mig 209 una caja tiene una fila por serie que usó
     * en su vida, y los listados (panel de cajas, anchos de impresión) tienen
     * que quedarse con la vigente — no con la que Postgres devuelva última. El
     * predicado estaba copiado en dos servicios; con la serie por doctype
     * (mig 223) cada copia tendría que repetir el mapa de claves, así que vive
     * acá, al lado de `SERIE_CONFIG_KEYS`, y ninguna copia puede divergir.
     *
     * Los alias son identificadores del caller (nunca input de usuario); se
     * validan igual para que un typo falle en voz alta.
     *
     * @param string $seq alias de `document_sequence` en la query
     * @param string $reg alias de `register` (JOIN por scopeid + companyid)
     */
    public static function vigenteSqlPredicate(string $seq = 's', string $reg = 'r'): string
    {
        foreach ([$seq, $reg] as $alias) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
                throw new \InvalidArgumentException('Alias SQL inválido: ' . $alias);
            }
        }

        $fiscal = implode(', ', array_map(
            static fn (string $d): string => "'" . $d . "'",
            array_keys(self::SERIE_CONFIG_KEYS)
        ));

        $serieCases = '';
        foreach (self::SERIE_CONFIG_KEYS as $docType => $key) {
            $serieCases .= " WHEN '{$docType}' THEN COALESCE(NULLIF(UPPER(TRIM({$reg}.data ->> '{$key}')), ''), '')";
        }

        return "{$seq}.invoiceauth = CASE WHEN {$seq}.doctype IN ({$fiscal})"
            . " THEN COALESCE(NULLIF(TRIM({$reg}.data ->> 'registerInvoiceAuth'), ''), '') ELSE '' END"
            . " AND {$seq}.prefix = CASE WHEN {$seq}.doctype IN ({$fiscal})"
            . " THEN COALESCE(NULLIF(TRIM({$reg}.data ->> 'registerInvoicePrefix'), ''), '') ELSE '' END"
            . " AND {$seq}.serie = CASE {$seq}.doctype{$serieCases} ELSE '' END";
    }
}
