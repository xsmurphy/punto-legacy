<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

use Punto\Api\Documents\DocumentNumber;
use Punto\Api\Sales\SaleType;

/**
 * De qué número sigue cada caja — resuelto, no tipeado a ciegas.
 *
 * ── El problema que cierra ───────────────────────────────────────────────
 *
 * Hasta hoy, dar de alta el emisor electrónico terminaba con una pantalla que
 * le pedía al comercio el "próximo número de factura" de cada caja, sin
 * decirle de dónde sacarlo. El owner tuvo que averiguarlo FUERA del sistema
 * (era el 615). Y el dato existía: `EInvoiceService::assertNumberingCoherence()`
 * ya lee el último número usado del talonario en el emisor — pero lo usa para
 * CORTAR la emisión con un mensaje que manda a Sucursales → Cajas, o sea para
 * retar al usuario en vez de para configurar.
 *
 * Este servicio invierte eso: el mismo dato se lee al CONFIGURAR y deja la
 * secuencia (`document_sequence`) apuntando al número correcto sola.
 *
 * ── Emisor nuevo vs. MIGRACIÓN (la distinción del brief) ─────────────────
 *
 * No se adivina: se CLASIFICA por la fuente que responde, en orden de
 * autoridad, y solo se pregunta cuando ninguna responde.
 *
 *   1. `emitter`  — el talonario del lado del emisor tiene historia
 *                   (`CurrentNumber` > 0). Es la fuente más fuerte porque es
 *                   contra ella que SIFEN va a chocar. Próximo = ese + 1.
 *                   Solo Factomate lo expone; ver el punto FE-PY abajo.
 *   2. `history`  — ya vendimos NOSOTROS con esta caja. La secuencia local
 *                   es la verdad y solo hay que asegurarse de que no haya
 *                   quedado atrás de lo emitido.
 *   3. `sequence` — la secuencia ya está movida de su valor inicial: alguien
 *                   la cargó a mano (el panel, `create_register`, el alta de
 *                   la caja). Es una respuesta explícita ya dada; repreguntar
 *                   sería desconfiar de lo que el propio comercio cargó.
 *   4. `operator` — el comercio ya contestó la pregunta de abajo alguna vez.
 *                   Se recuerda en `provisioning.numberingAnswers` para no
 *                   volver a preguntar lo mismo en cada visita a la pantalla.
 *   5. Ninguna    — `needsAnswer`. Este es el caso del owner: un emisor
 *                   recién creado cuyo contador arranca en 1 contra un
 *                   talonario de papel que venía en 614. Ese número NO existe
 *                   en ningún sistema, ni nuestro ni de ellos. Se PREGUNTA
 *                   una vez, y "es un talonario nuevo" (cero) es una
 *                   respuesta válida y explícita, no un default silencioso.
 *
 * Lo que este servicio NO hace nunca: inventar un número, ni escribir uno
 * MENOR que lo ya emitido. Todo lo que escribe pasa por
 * `DocumentNumber::advanceTo()`, que es un `GREATEST` en la base — o sea que
 * la secuencia solo puede AVANZAR. Una respuesta equivocada por lo bajo se
 * corrige sola contra el piso; una equivocada por lo alto salta un número,
 * que es legal y barato. La asimetría es deliberada: saltarse un correlativo
 * se explica, duplicar una factura se multa por cada documento (`context/29`
 * §2).
 *
 * ── FE-PY no expone contador, y está bien ────────────────────────────────
 *
 * `FePyProvider::stamps()` devuelve `CurrentNumber => null` a propósito: su
 * contador es por (tipo, establecimiento, punto) y no lo publica. Con FE-PY
 * la fuente 1 simplemente no responde y se cae a 2/3/4/5 — que es el
 * comportamiento correcto, no una degradación: en FE-PY el emisor es NUESTRO
 * motor y quien es dueño de la numeración es Punto (decisión del owner
 * 2026-09-07), así que el número no puede venir de él.
 *
 * ── Grano: la FACTURA ────────────────────────────────────────────────────
 *
 * Solo `factura`. La cotización no lleva timbrado y la nota de crédito hoy la
 * numera el proveedor (`SaleToFePyMapper::resolveDocumentNumber()` omite el
 * campo para el tipo 5) — eso es un slice aparte y meterlo acá agrandaría el
 * riesgo de un cambio que toca numeración fiscal.
 */
final class NumberingAdvisor
{
    /** El talonario del emisor tiene historia — la fuente más fuerte. */
    public const SOURCE_EMITTER = 'emitter';
    /** Ya vendimos nosotros con esta caja. */
    public const SOURCE_HISTORY = 'history';
    /** La secuencia ya está cargada por encima de su valor inicial. */
    public const SOURCE_SEQUENCE = 'sequence';
    /** El comercio ya contestó la pregunta. */
    public const SOURCE_OPERATOR = 'operator';

    /** Clave del bucket de respuestas dentro de `einvoice_account.provisioning`. */
    private const ANSWERS_BUCKET = 'numberingAnswers';

    private const DOC_TYPE = 'factura';

    /**
     * Estado de la numeración de cada caja activa con timbrado.
     *
     * @param bool $allowRemote Si se puede pagar una llamada al emisor para
     *        leer el contador del talonario. `false` (default) usa SOLO el
     *        caché `provisioning.stampDetails`, porque esta lectura la hacen
     *        una pantalla y una tool del agente y no pueden costar una
     *        llamada HTTP por render. `true` lo usa el provisioning, que
     *        corre una vez y necesita el dato real.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function survey(string $companyId, bool $allowRemote = false): array
    {
        $answers = self::answers($companyId);
        $stampMap = self::stampMap($companyId);

        $rows = [];
        // `data` viene aplanado por Query::flattenJsonb (la columna se hace
        // unset y sus claves se mergean a la fila) — mismo patrón, y mismo bug
        // evitado, que `EInvoiceProvisioningService::registerStamps()`.
        $rs = ncmExecute(
            'SELECT r.registerId, r.registerName, r.data, o.outletName
               FROM register r
          LEFT JOIN outlet o ON o.outletId = r.outletId AND o.companyId = r.companyId
              WHERE r.companyId = ? AND r.registerStatus = TRUE
              ORDER BY r.registerName ASC',
            [$companyId],
            false,
            true
        );

        // ncmExecute con forceObj devuelve un RECORDSET, no un array: se itera
        // con while(!$rs->EOF) o queda vacío siempre (footgun de CLAUDE.md).
        if (!$rs || !is_object($rs)) {
            return [];
        }

        while (!$rs->EOF) {
            $f = $rs->fields;
            $registerId = (string) ($f['registerid'] ?? $f['registerId'] ?? '');
            $auth   = trim((string) ($f['registerInvoiceAuth'] ?? ''));
            $prefix = trim((string) ($f['registerInvoicePrefix'] ?? ''));

            // Una caja sin timbrado NI punto de expedición no emite factura:
            // no tiene numeración fiscal que resolver. Mismo criterio que
            // `registerStamps()`, que la saltea en vez de marcarla incompleta.
            if ($registerId === '' || ($auth === '' && $prefix === '')) {
                $rs->MoveNext();
                continue;
            }

            $rows[] = self::surveyRegister(
                $companyId,
                $registerId,
                (string) ($f['registername'] ?? $f['registerName'] ?? ''),
                (string) ($f['outletname'] ?? $f['outletName'] ?? ''),
                $auth,
                $prefix,
                $answers,
                $stampMap,
                $allowRemote
            );
            $rs->MoveNext();
        }
        $rs->Close();

        return $rows;
    }

    /**
     * Aplica lo que se puede DERIVAR y devuelve qué quedó por preguntar.
     *
     * Es lo que corre al terminar el alta del emisor: la promesa de "al
     * configurar la FE, la numeración de la caja queda lista".
     *
     * Nunca lanza. Un fallo leyendo el contador del emisor no puede tumbar un
     * alta que ya se completó del otro lado — la consecuencia de no aplicar es
     * que la pantalla pregunta, que es el estado anterior y es seguro.
     *
     * @return array{applied:array<int,array<string,mixed>>,pending:array<int,array<string,mixed>>}
     */
    public static function applyDerived(string $companyId): array
    {
        try {
            $survey = self::survey($companyId, true);
        } catch (\Throwable $e) {
            error_log('[NumberingAdvisor] survey ' . $companyId . ': ' . $e->getMessage());
            return ['applied' => [], 'pending' => []];
        }

        $applied = [];
        $pending = [];
        foreach ($survey as $row) {
            if (!empty($row['needsAnswer'])) {
                $pending[] = $row;
                continue;
            }
            $proposal = $row['proposal'] ?? null;
            if (!is_int($proposal) || $proposal <= (int) $row['current']) {
                continue;   // la secuencia ya está donde tiene que estar
            }
            try {
                // `advanceTo` toma el ÚLTIMO emitido, no el próximo: se le
                // pasa `proposal - 1` y el `GREATEST` de la base garantiza que
                // esto nunca baje un contador.
                DocumentNumber::advanceTo(
                    self::DOC_TYPE,
                    DocumentNumber::SCOPE_REGISTER,
                    (string) $row['registerId'],
                    $companyId,
                    $proposal - 1
                );
                $row['current'] = $proposal;
                $applied[] = $row;
            } catch (\Throwable $e) {
                error_log('[NumberingAdvisor] advanceTo ' . $row['registerId'] . ': ' . $e->getMessage());
            }
        }

        return ['applied' => $applied, 'pending' => $pending];
    }

    /**
     * La respuesta del comercio: "el último número que emití con este
     * talonario fue el N". `$lastIssued = 0` es la respuesta explícita
     * "talonario nuevo, arrancá en 1" — se registra igual, para no volver a
     * preguntar lo que ya se contestó.
     *
     * @return array<string,mixed> La fila del survey, ya recalculada.
     * @throws \RuntimeException con el mensaje que ve el comercio.
     */
    public static function applyLastIssued(string $companyId, string $registerId, int $lastIssued): array
    {
        if ($lastIssued < 0) {
            throw new \RuntimeException('El último número emitido no puede ser negativo.');
        }
        if ($lastIssued > 9_999_999) {
            throw new \RuntimeException(
                'El último número emitido excede los 7 dígitos que admite un documento electrónico.'
            );
        }

        $rows = self::survey($companyId, false);
        $row = null;
        foreach ($rows as $candidate) {
            if ((string) $candidate['registerId'] === $registerId) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            throw new \RuntimeException(
                'Esa caja no existe, está dada de baja o no tiene timbrado cargado — no tiene numeración fiscal que configurar.'
            );
        }

        // La respuesta se registra ANTES de mover el contador: si el UPDATE de
        // la secuencia fallara, el estado seguro es "ya preguntamos" con el
        // contador viejo (que nunca pisa nada), no "no preguntamos" sobre un
        // contador ya movido.
        self::rememberAnswer($companyId, $registerId, $lastIssued);

        if ($lastIssued >= 1) {
            DocumentNumber::advanceTo(
                self::DOC_TYPE,
                DocumentNumber::SCOPE_REGISTER,
                $registerId,
                $companyId,
                $lastIssued
            );
        }

        $after = self::survey($companyId, false);
        foreach ($after as $candidate) {
            if ((string) $candidate['registerId'] === $registerId) {
                // `adjusted` cuenta la verdad incómoda: la respuesta era MENOR
                // que el piso y el `GREATEST` la levantó. Se informa en vez de
                // aceptarla en silencio — el comercio tiene que saber que su
                // caja no arranca donde dijo, y por qué.
                $candidate['adjusted'] = $lastIssued >= 1 && ($lastIssued + 1) < (int) $candidate['current'];
                return $candidate;
            }
        }

        return $row;
    }

    // ── Interno ──────────────────────────────────────────────────────────

    /**
     * @param array<string,int> $answers
     * @param array<string,array<string,mixed>> $stampMap
     * @return array<string,mixed>
     */
    private static function surveyRegister(
        string $companyId,
        string $registerId,
        string $registerName,
        string $outletName,
        string $auth,
        string $prefix,
        array $answers,
        array $stampMap,
        bool $allowRemote
    ): array {
        $current = DocumentNumber::peek(self::DOC_TYPE, DocumentNumber::SCOPE_REGISTER, $registerId, $companyId);
        $ourMax  = self::ourLastIssued($companyId, $registerId);
        $remote  = self::remoteLastIssued($companyId, $registerId, $stampMap, $allowRemote);

        // El PISO: por debajo de esto no se puede escribir sin duplicar un
        // documento. Es la unión de todo lo que ya existe — nuestro historial,
        // lo reservado por ventas offline sin sincronizar, y lo que el emisor
        // dice haber emitido.
        $floor = 1;
        if ($ourMax > 0) {
            $floor = max($floor, $ourMax + 1);
        }
        if ($remote !== null && $remote > 0) {
            $floor = max($floor, $remote + 1);
        }
        $floor = max($floor, $current);

        $source = null;
        $proposal = null;
        $needsAnswer = false;

        if ($remote !== null && $remote > 0) {
            $source = self::SOURCE_EMITTER;
            $proposal = max($floor, $remote + 1);
        } elseif ($ourMax > 0) {
            $source = self::SOURCE_HISTORY;
            $proposal = max($floor, $ourMax + 1);
        } elseif ($current > 1) {
            $source = self::SOURCE_SEQUENCE;
            $proposal = $current;
        } elseif (array_key_exists($registerId, $answers)) {
            $source = self::SOURCE_OPERATOR;
            $proposal = max($floor, $answers[$registerId] + 1);
        } else {
            $needsAnswer = true;
        }

        return [
            'registerId'    => $registerId,
            'registerName'  => $registerName,
            'outletName'    => $outletName,
            'invoiceAuth'   => $auth,
            'invoicePrefix' => $prefix,
            /** Lo que la caja va a emitir HOY si nadie toca nada. */
            'current'       => $current,
            /** Mínimo seguro: por debajo se duplicaría un documento. */
            'floor'         => $floor,
            /** Lo que se aplicaría. `null` cuando hay que preguntar. */
            'proposal'      => $proposal,
            'source'        => $source,
            'needsAnswer'   => $needsAnswer,
            'detail'        => self::detail($source, $needsAnswer, $current, $proposal, $auth),
            'question'      => $needsAnswer ? self::question($registerName, $auth, $prefix) : '',
        ];
    }

    /**
     * Último correlativo de factura que emitió ESTA caja, según nuestra base.
     *
     * Se mira la caja entera y no solo el timbrado vigente, igual que el guard
     * de duplicados de `RegisterAdminService::update()`: la unicidad de
     * `invoiceNo` por caja (mig 145) es sobre el entero, así que reusar un
     * número de un talonario anterior choca aunque el timbrado sea otro.
     *
     * Entra también `numbering_lease`: un número reservado por una venta
     * offline que todavía no sincronizó ya está IMPRESO en un ticket. Pisarlo
     * sería duplicar un documento que el cliente ya se llevó.
     */
    private static function ourLastIssued(string $companyId, string $registerId): int
    {
        $types = [SaleType::Cashsale->value, SaleType::Creditsale->value];
        $ph = implode(',', array_fill(0, count($types), '?'));

        $tx = ncmExecute(
            "SELECT MAX(invoiceno) AS m FROM transaction
              WHERE registerid = ? AND companyid = ? AND invoiceno IS NOT NULL
                AND transactiontype IN ($ph)",
            array_merge([$registerId, $companyId], $types)
        );
        $max = (int) ($tx['m'] ?? 0);

        $leased = ncmExecute(
            'SELECT MAX(invoiceno) AS m FROM "numbering_lease"
              WHERE registerid = ? AND companyid = ?',
            [$registerId, $companyId]
        );

        return max($max, (int) ($leased['m'] ?? 0));
    }

    /**
     * `CurrentNumber` del talonario del lado del emisor, o `null` cuando no
     * hay de dónde leerlo.
     *
     * `null` NO es un error: con FE-PY es lo normal (su contador no se
     * publica, ver el docblock de la clase) y con Factomate significa que el
     * caché está frío y el caller no autorizó pagar la llamada.
     */
    private static function remoteLastIssued(
        string $companyId,
        string $registerId,
        array $stampMap,
        bool $allowRemote
    ): ?int {
        $stampId = (string) ($stampMap[$registerId]['fc'] ?? '');
        if ($stampId === '') {
            return null;
        }

        try {
            $details = (new EInvoiceService())->remoteStampDetails($companyId, $stampId, $allowRemote);
        } catch (\Throwable $e) {
            // Leer el emisor es una MEJORA de esta lectura, no su razón de
            // ser: sin el dato se cae a nuestro historial o a la pregunta.
            error_log('[NumberingAdvisor] remoteStampDetails ' . $stampId . ': ' . $e->getMessage());
            return null;
        }

        if ($details === null || !is_numeric($details['CurrentNumber'] ?? null)) {
            return null;
        }

        return (int) $details['CurrentNumber'];
    }

    /** @return array<string,array<string,mixed>> registerId → {fc, nc} */
    private static function stampMap(string $companyId): array
    {
        $prov = self::provisioning($companyId);
        $map = $prov['stampMap'] ?? null;

        return is_array($map) ? $map : [];
    }

    /** @return array<string,int> registerId → último emitido que contestó el comercio */
    private static function answers(string $companyId): array
    {
        $prov = self::provisioning($companyId);
        $raw = $prov[self::ANSWERS_BUCKET] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $registerId => $entry) {
            if (is_array($entry) && is_numeric($entry['lastIssued'] ?? null)) {
                $out[(string) $registerId] = (int) $entry['lastIssued'];
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function provisioning(string $companyId): array
    {
        $row = ncmExecute('SELECT provisioning FROM einvoice_account WHERE companyid = ?', [$companyId]);
        if (!$row) {
            return [];
        }
        $raw = $row['provisioning'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) ($raw ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Guarda la respuesta para no repreguntarla. Read-modify-write del bucket
     * entero — mismo patrón que `stampRegisters` en `ensureStampsCreated()`,
     * porque `mergeProvisioning()` es un merge SHALLOW y pasarle solo la clave
     * nueva borraría las respuestas de las otras cajas.
     *
     * Sin cuenta de facturación no hay dónde guardarla, y tampoco hace falta:
     * el survey solo se muestra desde la pantalla de facturación electrónica.
     */
    private static function rememberAnswer(string $companyId, string $registerId, int $lastIssued): void
    {
        $row = ncmExecute('SELECT 1 FROM einvoice_account WHERE companyid = ? LIMIT 1', [$companyId]);
        if (!$row) {
            return;
        }

        $prov = self::provisioning($companyId);
        $bucket = is_array($prov[self::ANSWERS_BUCKET] ?? null) ? $prov[self::ANSWERS_BUCKET] : [];
        $bucket[$registerId] = ['lastIssued' => $lastIssued, 'at' => date('c')];

        EInvoiceProvisioningService::mergeProvisioning($companyId, [self::ANSWERS_BUCKET => $bucket]);
    }

    private static function detail(
        ?string $source,
        bool $needsAnswer,
        int $current,
        ?int $proposal,
        string $auth
    ): string {
        if ($needsAnswer) {
            return 'No hay de dónde deducir desde qué número sigue esta caja: ni el emisor ni Punto tienen ' .
                'documentos emitidos con este talonario.';
        }

        $n = $proposal ?? $current;

        return match ($source) {
            self::SOURCE_EMITTER => sprintf(
                'El emisor tiene documentos emitidos con el timbrado %s hasta el %d, así que la próxima factura es la %d.',
                $auth !== '' ? $auth : 'de esta caja',
                $n - 1,
                $n
            ),
            self::SOURCE_HISTORY => sprintf(
                'Esta caja ya facturó en Punto hasta el %d, así que la próxima factura es la %d.',
                $n - 1,
                $n
            ),
            self::SOURCE_SEQUENCE => sprintf(
                'La numeración de esta caja ya está cargada: la próxima factura es la %d.',
                $n
            ),
            self::SOURCE_OPERATOR => sprintf(
                'Según lo que se cargó al configurar la facturación electrónica, la próxima factura es la %d.',
                $n
            ),
            default => sprintf('La próxima factura de esta caja es la %d.', $n),
        };
    }

    private static function question(string $registerName, string $auth, string $prefix): string
    {
        $caja = $registerName !== '' ? '"' . $registerName . '"' : 'esta caja';
        $talonario = trim(($auth !== '' ? 'timbrado ' . $auth : '') . ($prefix !== '' ? ' · ' . $prefix : ''));

        return sprintf(
            '¿Cuál fue la ÚLTIMA factura que emitiste con el talonario de la caja %s (%s)? ' .
            'Si es un talonario nuevo y todavía no emitiste ninguna, decilo y arranca en la 1.',
            $caja,
            $talonario !== '' ? $talonario : 'sin timbrado cargado'
        );
    }
}
