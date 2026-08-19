<?php
declare(strict_types=1);
namespace Punto\Api\Services;

use Punto\Api\Documents\DocumentNumber;
use Punto\Api\Sales\SaleType;

/**
 * RegisterAdminService — CRUD de cajas desde el panel de administración.
 *
 * El servicio existente RegisterService maneja la sesión de caja del POS
 * (setSession, docNumbers, hotkeys). Este servicio cubre la gestión admin:
 * listar, crear, editar y eliminar cajas.
 */
final class RegisterAdminService
{
    public function __construct(
        private readonly string $companyId,
    ) {}

    /**
     * Lista todas las cajas del tenant con JOIN a outlet para outletName.
     */
    /**
     * Documentos con numeración propia por caja. Hoy solo se emite 'factura';
     * en PY la NC, la ND y la remisión llevan timbrado y rango propios y se
     * suman acá cuando se implementen (ver update()).
     */
    private const DOC_TYPES = ['factura', 'cotizacion'];

    /**
     * Próximos números de la caja, leídos de `document_sequence` (context/37).
     *
     * NUNCA devuelve vacío: la secuencia es NOT NULL DEFAULT 1 y las cajas se
     * siembran al crearse (mig 127 sembró las existentes). Si por lo que fuera
     * faltara la fila, el fallback es 1 — que es exactamente lo que asignaría
     * el asignador. Antes esto leía un "piso" opcional de `register.data` y
     * devolvía '' cuando no había ninguno: el panel mostraba el campo en blanco
     * y el número real recién existía al facturar.
     *
     * @return array<string,array<string,int|null>> registerId → docType → next
     */
    private function numberingByRegister(): array
    {
        $rs = ncmExecute(
            'SELECT s.scopeid, s.doctype, s.nextnumber, s.rangeto
               FROM document_sequence s
              WHERE s.companyid = ? AND s.scopetype = ?',
            [$this->companyId, DocumentNumber::SCOPE_REGISTER],
            false,
            true  // forceObj → recordset
        );

        $out = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f  = $rs->fields;
                $id = (string) ($f['scopeid'] ?? '');
                $dt = (string) ($f['doctype'] ?? '');
                if ($id === '' || !in_array($dt, self::DOC_TYPES, true)) {
                    $rs->MoveNext();
                    continue;
                }
                $out[$id][$dt] = [
                    'next'    => (int) ($f['nextnumber'] ?? 1),
                    'rangeTo' => $f['rangeto'] === null ? null : (int) $f['rangeto'],
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    public function listAll(): array
    {
        $seq = $this->numberingByRegister();

        $rs = ncmExecute(
            'SELECT r.registerId, r.registerName, r.outletId, o.outletName, r.registerStatus, r.data
               FROM register r
               JOIN outlet o ON o.outletId = r.outletId AND o.companyId = r.companyId
              WHERE r.companyId = ?
              ORDER BY o.outletName ASC, r.registerName ASC',
            [$this->companyId],
            false,
            true  // forceObj → recordset
        );
        $out = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                // Timbrado de la caja (mig 26 lo demoteó a `data` JSONB): la
                // caja ES el punto de expedición (context/29 §1), así que su
                // timbrado se administra acá — facturación electrónica lo LEE
                // de la caja, nunca lo pide de nuevo.
                //
                // OJO: `$rs->fields` viene de Query::flattenJsonb, que mergea
                // `data` al nivel de la fila y HACE unset de la columna
                // (Query.php:57). `$f['data']` es null acá, así que el
                // json_decode que había devolvía [] y TODO el timbrado se leía
                // vacío — el guardado funcionaba, pero el form se reabría en
                // blanco y parecía que no había guardado (reporte del tester
                // 2026-08-04). Mismo bug silencioso que parked-sales/pinhash
                // (2026-07-30); el patrón correcto ya estaba en
                // api/v1/register.php:110 — leer las claves ya aplanadas.
                $regId = (string)($f['registerId'] ?? $f['registerid'] ?? '');
                $out[] = [
                    'id'         => $regId,
                    'name'       => (string)($f['registerName']  ?? $f['registername']  ?? ''),
                    'outletId'   => (string)($f['outletId']      ?? $f['outletid']      ?? ''),
                    'outletName' => (string)($f['outletName']    ?? $f['outletname']    ?? ''),
                    'status'     => (bool)($f['registerStatus']  ?? $f['registerstatus'] ?? false),
                    // Control de caja a ciegas: el cajero opera el turno sin
                    // ver montos acumulados (dashboard y arqueo sin totales).
                    // Vive en data JSONB y SOLO se edita desde el panel — el
                    // PUT ?resource=config del device lo ignora por whitelist.
                    'blindControl' => (bool)($f['registerBlindControl'] ?? false),
                    'fiscal'     => [
                        'invoiceAuth'           => (string) ($f['registerInvoiceAuth'] ?? ''),
                        // "EEE-PPP" — establecimiento y punto de expedición.
                        'invoicePrefix'         => (string) ($f['registerInvoicePrefix'] ?? ''),
                        'invoiceAuthStart'      => (string) ($f['registerInvoiceAuthStart'] ?? ''),
                        'invoiceAuthExpiration' => (string) ($f['registerInvoiceAuthExpiration'] ?? ''),
                    ],
                    // Próximo número de cada documento, de `document_sequence`.
                    // Siempre presente — es el número que la caja va a emitir.
                    'numbering' => [
                        'factura'    => (string) ($seq[$regId]['factura']['next'] ?? 1),
                        'cotizacion' => (string) ($seq[$regId]['cotizacion']['next'] ?? 1),
                    ],
                    // Fin del rango autorizado del timbrado. Null = sin techo
                    // declarado; el asignador solo corta cuando hay uno.
                    'range' => [
                        'facturaTo' => $seq[$regId]['factura']['rangeTo'] ?? null,
                    ],
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    /**
     * Crea una caja.
     *
     * `$extra` acepta los mismos `fiscal` / `numbering` / `range` que update():
     * el timbrado y el número desde el que arranca la caja son parte del ALTA,
     * no un segundo paso. Una caja creada sin ellos igual queda con secuencia
     * en 1 — nunca sin numeración.
     */
    public function create(string $outletId, string $name, array $extra = []): array
    {
        $name     = trim($name);
        $outletId = trim($outletId);

        if ($name === '' || $outletId === '') {
            apiError('outletId y name son requeridos', 422);
        }

        // Guard: outlet pertenece al tenant
        $outlet = ncmExecute(
            'SELECT 1 FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',
            [$outletId, $this->companyId]
        );
        if (!$outlet) {
            apiError('Sucursal no encontrada', 422);
        }

        // Guard: nombre único en el outlet (solo entre activas)
        $dup = ncmExecute(
            'SELECT 1 FROM register
              WHERE companyId = ? AND outletId = ?
                AND LOWER(registerName) = LOWER(?) AND registerStatus = TRUE
              LIMIT 1',
            [$this->companyId, $outletId, $name]
        );
        if ($dup) {
            apiError('Ya existe una caja con ese nombre en la sucursal', 409);
        }

        // Caja + secuencias + timbrado en UNA transacción: el timbrado se
        // valida recién en update(), y un `apiError` ahí corta el request. Sin
        // la transacción quedaría una caja creada a medias, sin el timbrado que
        // el operador acaba de cargar y sin forma de darse cuenta.
        global $db;
        $db->StartTrans();

        $row = ncmExecute(
            'INSERT INTO register (registerId, companyId, outletId, registerName, registerStatus)
             VALUES (gen_random_uuid(), ?, ?, ?, TRUE)
             RETURNING registerId',
            [$this->companyId, $outletId, $name]
        );

        $id = (string)($row['registerId'] ?? $row['registerid'] ?? '');

        // Secuencias de la caja recién creada. Se siembran SIEMPRE, con o sin
        // datos del alta: `document_sequence` es de dónde sale el próximo
        // número y una caja sin fila ahí le haría mostrar al panel un 1 que no
        // está respaldado por nada.
        foreach (self::DOC_TYPES as $docType) {
            $this->seedSequence($id, $docType, null, null, false, null);
        }

        // El resto del alta reusa update(): mismas validaciones de timbrado,
        // mismo invariante de "no pisar un número emitido" (trivial en una caja
        // nueva, pero no se duplica la regla).
        $fields = array_intersect_key($extra, array_flip(['fiscal', 'numbering', 'range', 'blindControl']));
        if ($fields !== []) {
            $this->update($id, $fields);
        }

        $db->CompleteTrans();

        realtimePublish('register', 'create', $id);
        return ['id' => $id, 'name' => $name];
    }

    /**
     * Actualiza nombre y/o status de una caja.
     */
    public function update(string $id, array $fields): array
    {
        // Guard: caja existe y pertenece al tenant
        $reg = ncmExecute(
            'SELECT registerId, outletId, registerName, registerStatus, data FROM register
              WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$id, $this->companyId]
        );
        if (!$reg) {
            apiError('Caja no encontrada', 404);
        }
        // `data` viene aplanado por Query::flattenJsonb (la columna se hace
        // unset), así que el prefijo actual se lee como clave de la fila.
        $currentPrefix = (string) ($reg['registerInvoicePrefix'] ?? '');
        $currentAuth   = (string) ($reg['registerInvoiceAuth'] ?? '');
        $currentStatus = (bool) ($reg['registerStatus'] ?? $reg['registerstatus'] ?? false);

        $setParts  = [];
        $params    = [];

        if (array_key_exists('name', $fields)) {
            $name = trim((string)$fields['name']);
            if ($name === '') {
                apiError('El nombre no puede estar vacío', 422);
            }
            // Guard: nombre único en el outlet (excluyendo la caja actual)
            $dup = ncmExecute(
                'SELECT 1 FROM register
                  WHERE companyId = ? AND outletId = ?
                    AND LOWER(registerName) = LOWER(?) AND registerStatus = TRUE
                    AND registerId != ?
                  LIMIT 1',
                [$this->companyId, $reg['outletId'] ?? $reg['outletid'], $name, $id]
            );
            if ($dup) {
                apiError('Ya existe una caja con ese nombre en la sucursal', 409);
            }
            $setParts[] = 'registerName = ?';
            $params[]   = $name;
        }

        if (array_key_exists('status', $fields)) {
            $setParts[] = 'registerStatus = ?';
            $params[]   = (bool) $fields['status'];
        }

        // Timbrado de la caja — merge sobre `data` JSONB (mig 26). La caja es
        // el punto de expedición: este es EL lugar donde vive el timbrado
        // (número, EEE-PPP, vigencia); facturación electrónica y la
        // numeración fiscal (context/29 §4.2) lo leen de acá.
        $fiscalPatch = [];

        // Control de caja a ciegas (boolean, panel-only). Comparte el patch
        // JSONB de `data` con el timbrado.
        if (array_key_exists('blindControl', $fields)) {
            $fiscalPatch['registerBlindControl'] = (bool) $fields['blindControl'];
        }

        if (is_array($fields['fiscal'] ?? null)) {
            $fc = $fields['fiscal'];
            if (array_key_exists('invoiceAuth', $fc)) {
                $auth = trim((string) $fc['invoiceAuth']);
                if ($auth !== '' && !preg_match('/^\d+$/', $auth)) {
                    apiError('El número de timbrado debe ser numérico', 422);
                }
                $fiscalPatch['registerInvoiceAuth'] = $auth === '' ? null : (int) $auth;
            }
            if (array_key_exists('invoicePrefix', $fc)) {
                $prefix = trim((string) $fc['invoicePrefix']);
                if ($prefix !== '' && !preg_match('/^\d{3}-\d{3}$/', $prefix)) {
                    apiError('Establecimiento y punto de expedición van como EEE-PPP (ej. 001-001)', 422);
                }

                // ── Un mismo par (timbrado, punto de expedición), UNA caja ──
                // El EEE-PPP identifica el punto de expedición ante la SET,
                // pero la numeración correlativa es POR TALONARIO, y un
                // talonario es un timbrado. Dos cajas SÍ pueden compartir el
                // mismo EEE-PPP si tienen timbrados distintos (dos talonarios
                // independientes). Lo que no puede repetirse es el PAR
                // completo: dos cajas con el MISMO timbrado y el MISMO
                // EEE-PPP llevan la misma secuencia y, en cuanto las dos
                // emiten offline sin verse, salen dos facturas con el mismo
                // número. Es un documento duplicado, no un choque de datos —
                // de ahí el 409 duro y no un aviso. El guard vive en
                // assertExpeditionPointFree() más abajo, sobre el par
                // efectivo (timbrado + prefix), no solo el prefix.
                //
                // El scope es la COMPANY (el RUC es de la empresa), no la
                // sucursal: el establecimiento ya viaja en los primeros tres
                // dígitos. Solo cuentan las cajas activas — una caja dada de
                // baja conserva su historial pero no emite.
                $fiscalPatch['registerInvoicePrefix'] = $prefix === '' ? null : $prefix;
            }
            foreach (['invoiceAuthStart' => 'registerInvoiceAuthStart', 'invoiceAuthExpiration' => 'registerInvoiceAuthExpiration'] as $in => $key) {
                if (array_key_exists($in, $fc)) {
                    $v = trim((string) $fc[$in]);
                    if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                        apiError('Las fechas del timbrado van como YYYY-MM-DD', 422);
                    }
                    $fiscalPatch[$key] = $v === '' ? null : $v;
                }
            }
        }

        // ── Numeración por documento ────────────────────────────────────────
        // Un timbrado no siempre arranca en 1: la SET puede autorizar un rango
        // que empieza en, por ejemplo, 2336. Esto es lo que mueve el próximo
        // número de la secuencia (`document_sequence.nextnumber`) a ese valor.
        //
        // Se guarda por TIPO de documento porque en PY la nota de crédito, la
        // nota de débito y la remisión llevan timbrado y rango PROPIOS: un
        // contador único habría que rehacerlo al implementarlas.
        //
        // Invariante (decisión del owner): NUNCA puede pisar un número ya
        // emitido — sería una factura duplicada. Se valida contra `transaction`
        // y contra los arriendos pendientes antes de mover la secuencia.
        $numbering = [];
        if (array_key_exists('numbering', $fields) && is_array($fields['numbering'])) {
            foreach ($fields['numbering'] as $docType => $raw) {
                if (!in_array($docType, self::DOC_TYPES, true)) {
                    apiError('Tipo de documento desconocido: ' . (string) $docType, 422);
                }
                $n = trim((string) $raw);
                if ($n === '') {
                    // Vacío ya NO significa "sin numeración": la secuencia
                    // siempre tiene un próximo número. Un campo en blanco es
                    // simplemente "no lo toques".
                    continue;
                }
                if (!preg_match('/^\d+$/', $n) || (int) $n < 1) {
                    apiError('La numeración debe ser un entero positivo', 422);
                }
                $n = (int) $n;

                // Cada documento valida contra SU fuente: factura y cotización
                // comparten la columna `invoiceNo` pero se distinguen por
                // transactionType, así que sus numeraciones son independientes
                // y un mismo número puede existir en las dos sin conflicto.
                // Por eso el match explícito y no un chequeo genérico.
                // Los tipos salen del enum, NO de literales: SaleType es la
                // fuente de verdad (SaleInput::fromPayload valida contra él).
                // Un [0, 3] hardcodeado acá sería una segunda copia del mapeo
                // que se desincroniza en silencio.
                $txTypes = match ($docType) {
                    'factura'    => [SaleType::Cashsale->value, SaleType::Creditsale->value],
                    'cotizacion' => [SaleType::Quote->value],
                    default      => [],
                };
                if ($txTypes !== []) {
                    $ph   = implode(',', array_fill(0, count($txTypes), '?'));
                    $used = ncmExecute(
                        "SELECT 1 FROM transaction
                          WHERE registerid = ? AND companyid = ? AND invoiceno = ?
                            AND transactiontype IN ($ph) LIMIT 1",
                        array_merge([$id, $this->companyId, $n], $txTypes)
                    );
                    if ($used) {
                        apiError(
                            'Ya existe un documento con el número ' . $n . ' en esta caja: no se puede duplicar',
                            409
                        );
                    }
                }

                // Solo la FACTURA se arrienda: `numbering_lease` existe para
                // que el POS offline reserve números de venta. Aplicar este
                // chequeo a la cotización daría un falso positivo (rechazaría
                // la cotización 500 porque existe la factura 500).
                if ($docType === 'factura') {
                    // Un número ya arrendado está reservado para una venta
                    // offline que todavía no sincronizó: el índice único de
                    // numbering_lease lo rechazaría igual, pero recién al
                    // emitir y con un error de constraint. Mejor acá.
                    $leased = ncmExecute(
                        'SELECT 1 FROM "numbering_lease"
                          WHERE registerid = ? AND companyid = ? AND invoiceno = ? LIMIT 1',
                        [$id, $this->companyId, $n]
                    );
                    if ($leased) {
                        apiError(
                            'El número ' . $n . ' ya está reservado por una venta pendiente de sincronizar',
                            409
                        );
                    }
                }
                $numbering[$docType] = $n;
            }
        }

        // Fin del rango autorizado del timbrado. Es lo que le permite al
        // asignador cortar la emisión al agotarse en vez de emitir fuera de
        // timbrado (F4, context/37). Se guarda en la secuencia de la factura:
        // el rango es del timbrado, no de la caja entera.
        $rangeTo = null;
        $rangeToTouched = false;
        if (array_key_exists('range', $fields) && is_array($fields['range'])
            && array_key_exists('facturaTo', $fields['range'])) {
            $rangeToTouched = true;
            $rt = trim((string) $fields['range']['facturaTo']);
            if ($rt !== '') {
                if (!preg_match('/^\d+$/', $rt) || (int) $rt < 1) {
                    apiError('El fin del rango debe ser un entero positivo', 422);
                }
                $rangeTo = (int) $rt;
                $from = $numbering['factura'] ?? null;
                if ($from !== null && $rangeTo < $from) {
                    apiError('El fin del rango no puede ser menor que la próxima factura', 422);
                }
            }
        }

        // ── Un mismo par (timbrado, punto de expedición), UNA caja ──────────
        // Se valida sobre el estado RESULTANTE del update, no sobre el
        // guardado, y solo cuando ese estado cambia. Chequear el par guardado
        // en cada save dejaba encerrada justamente a la caja con el
        // duplicado: el 409 bloqueaba la edición que venía a resolverlo.
        //
        // Un duplicado preexistente tampoco frena un cambio ajeno (renombrar,
        // caja a ciegas): se exige que el par esté libre al ASIGNARLO o al
        // reactivar una caja que vuelve a emitir con el par que tenía.
        //
        // El timbrado entra en la comparación igual que el prefix: si el
        // request cambia solo el timbrado (deja el mismo EEE-PPP), también
        // puede crear una colisión con otra caja que ya tenga ese par — no
        // alcanza con mirar $prefixChanged.
        $effectivePrefix = array_key_exists('registerInvoicePrefix', $fiscalPatch)
            ? (string) ($fiscalPatch['registerInvoicePrefix'] ?? '')
            : $currentPrefix;
        $effectiveAuth = array_key_exists('registerInvoiceAuth', $fiscalPatch)
            ? (string) ($fiscalPatch['registerInvoiceAuth'] ?? '')
            : $currentAuth;
        $willBeActive = array_key_exists('status', $fields)
            ? (bool) $fields['status']
            : $currentStatus;

        $prefixChanged = $effectivePrefix !== $currentPrefix;
        $authChanged   = $effectiveAuth !== $currentAuth;
        $reactivating  = $willBeActive && !$currentStatus;

        if ($willBeActive && $effectivePrefix !== '' && ($prefixChanged || $authChanged || $reactivating)) {
            $this->assertExpeditionPointFree($id, $effectiveAuth, $effectivePrefix);
        }

        if (empty($setParts) && empty($fiscalPatch) && $numbering === [] && !$rangeToTouched) {
            return ['ok' => true];
        }

        global $db;
        if (!empty($setParts)) {
            $colParams   = $params;
            $colParams[] = $id;
            $colParams[] = $this->companyId;
            $db->Execute(
                'UPDATE register SET ' . implode(', ', $setParts) .
                ' WHERE registerId = ? AND companyId = ?',
                $colParams
            );
        }
        if (!empty($fiscalPatch)) {
            // `||` mergea claves; las que vienen null quedan null adentro del
            // JSONB (semántica "borrado" para estos campos — los lectores
            // hacen `?? ''`).
            $db->Execute(
                "UPDATE register SET data = COALESCE(data, '{}'::jsonb) || ?::jsonb
                  WHERE registerId = ? AND companyId = ?",
                [json_encode($fiscalPatch), $id, $this->companyId]
            );
        }

        // El prefijo EEE-PPP viaja a la secuencia de la factura: el asignador
        // lo necesita para componer el número fiscal completo sin volver a
        // leer la caja.
        $prefix = array_key_exists('registerInvoicePrefix', $fiscalPatch)
            ? $fiscalPatch['registerInvoicePrefix']
            : null;

        // La secuencia de la FACTURA se toca si cambió cualquiera de las tres
        // cosas que viven en ella: el próximo número, el techo del rango o el
        // prefijo del timbrado. Los tres llegan por caminos distintos del
        // request, de ahí el OR — un cambio de solo prefijo también tiene que
        // bajar a la secuencia.
        if (isset($numbering['factura']) || $rangeToTouched || $prefix !== null) {
            $this->seedSequence(
                $id,
                'factura',
                $numbering['factura'] ?? null,
                $rangeTo,
                $rangeToTouched,
                $prefix,
            );
        }
        // La cotización no lleva timbrado propio: solo su contador.
        if (isset($numbering['cotizacion'])) {
            $this->seedSequence($id, 'cotizacion', $numbering['cotizacion'], null, false, null);
        }

        realtimePublish('register', 'update', $id);
        return ['ok' => true];
    }

    /**
     * Corta si otra caja activa del tenant ya usa el mismo PAR (timbrado,
     * punto de expedición).
     *
     * El EEE-PPP identifica el punto de expedición ante la SET, pero la
     * numeración correlativa es POR TALONARIO, y un talonario es un timbrado.
     * Dos cajas SÍ pueden compartir el mismo EEE-PPP si tienen timbrados
     * distintos — son dos talonarios independientes, cada uno con su propia
     * secuencia. Lo que no puede repetirse es el PAR completo: dos cajas con
     * el MISMO timbrado y el MISMO EEE-PPP llevan la misma secuencia y, en
     * cuanto las dos emiten — y con el POS offline emiten sin verse — salen
     * dos facturas distintas con el mismo número. Es un documento duplicado,
     * no un choque de datos: de ahí el 409 duro y no un aviso.
     *
     * Scope COMPANY, no sucursal: el RUC es de la empresa y el establecimiento
     * ya viaja en los primeros tres dígitos. Solo cuentan las cajas ACTIVAS —
     * una caja dada de baja conserva su historial pero no emite.
     *
     * Sin timbrado efectivo no hay par que validar: una caja sin timbrado no
     * emite, así que no hay colisión fiscal posible.
     */
    private function assertExpeditionPointFree(string $registerId, string $auth, string $prefix): void
    {
        if ($auth === '') {
            return;
        }
        $clash = ncmExecute(
            "SELECT registerName FROM register
              WHERE companyId = ? AND registerId != ? AND registerStatus = TRUE
                AND data ->> 'registerInvoiceAuth' = ?
                AND data ->> 'registerInvoicePrefix' = ?
              LIMIT 1",
            [$this->companyId, $registerId, $auth, $prefix]
        );
        if (!$clash) {
            return;
        }
        $other = (string) ($clash['registerName'] ?? $clash['registername'] ?? '');
        apiError(
            'El punto de expedición ' . $prefix . ' ya está en uso con el mismo timbrado por la caja "' .
            $other . '". Dos cajas con el mismo timbrado y el mismo punto de expedición emitirían ' .
            'facturas con el mismo número: usá otro punto de expedición o un timbrado distinto.',
            409
        );
    }

    /**
     * Crea o mueve la secuencia de un documento de esta caja.
     *
     * `$next === null` deja el contador donde está (solo se está tocando el
     * rango o el prefijo). Nunca se llama desde el camino de emisión: mover un
     * contador es una operación de administración y ya viene validada contra lo
     * realmente emitido por el caller.
     */
    private function seedSequence(
        string $registerId,
        string $docType,
        ?int $next,
        ?int $rangeTo,
        bool $rangeTouched,
        ?string $prefix,
    ): void {
        global $db;

        // El SET del rango se arma en PHP y no con un placeholder booleano:
        // PDO bindea `false` como '' / 0 y PG rechaza `CASE WHEN 0` ("argument
        // of CASE/WHEN must be type boolean"). Un update que no trae rango no
        // puede borrar el techo del timbrado, así que la columna ni aparece.
        // `rangefrom` es documental (desde dónde autorizó la SET) y solo se
        // mueve cuando el admin declara el rango. Si se moviera con cada cambio
        // de `nextnumber`, un ajuste del contador a mitad del talonario
        // reescribiría el inicio del rango con un dato falso.
        $rangeSet    = $rangeTouched ? 'rangefrom = ?::bigint, rangeto = ?::bigint,' : '';
        $rangeParams = $rangeTouched ? [$next, $rangeTo] : [];

        // Casts explícitos en todos los placeholders: sin ellos PG no puede
        // inferir el tipo de un `?` dentro de COALESCE y aborta con "could not
        // determine data type of parameter".
        $db->Execute(
            'INSERT INTO document_sequence
                 (companyid, doctype, scopetype, scopeid, nextnumber, rangefrom, rangeto, prefix)
             VALUES (?, ?, ?, ?, COALESCE(?::bigint, 1), ?::bigint, ?::bigint, ?::varchar)
             ON CONFLICT (companyid, doctype, scopetype, scopeid) DO UPDATE SET
                 nextnumber = COALESCE(?::bigint, document_sequence.nextnumber),
                 ' . $rangeSet . '
                 prefix     = COALESCE(?::varchar, document_sequence.prefix),
                 updated_at = now()',
            array_merge(
                [
                    $this->companyId, $docType, DocumentNumber::SCOPE_REGISTER, $registerId,
                    $next, $next, $rangeTo, $prefix,
                    $next,
                ],
                $rangeParams,
                [$prefix]
            )
        );
    }

    /**
     * Elimina una caja.
     * - Bloquea si hay devices activos apuntando a esta caja.
     * - Soft delete si tiene transacciones; hard delete si no.
     */
    public function delete(string $id): array
    {
        // Guard: caja existe y pertenece al tenant
        $reg = ncmExecute(
            'SELECT registerId FROM register WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$id, $this->companyId]
        );
        if (!$reg) {
            apiError('Caja no encontrada', 404);
        }

        // Guard: devices activos — status=1 en tabla device
        $devRow = ncmExecute(
            'SELECT COUNT(*)::int AS cnt FROM device WHERE registerId = ? AND status = 1',
            [$id]
        );
        $devCount = (int)($devRow['cnt'] ?? 0);
        if ($devCount > 0) {
            // apiError solo acepta 2 args — respuesta manual para incluir payload extra
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => "Hay {$devCount} dispositivo(s) usando esta caja como caja activa", 'devices' => $devCount]);
            exit;
        }

        // ¿Tiene transacciones?
        $txRow = ncmExecute(
            'SELECT 1 FROM transaction WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$id, $this->companyId]
        );

        if ($txRow) {
            // Soft delete: preserva históricos
            global $db;
            $db->Execute(
                'UPDATE register SET registerStatus = FALSE WHERE registerId = ? AND companyId = ?',
                [$id, $this->companyId]
            );
            realtimePublish('register', 'update', $id);
            return ['deleted' => 'soft', 'reason' => 'has_transactions'];
        }

        // Hard delete
        global $db;
        $db->Execute(
            'DELETE FROM register WHERE registerId = ? AND companyId = ?',
            [$id, $this->companyId]
        );
        realtimePublish('register', 'delete', $id);
        return ['deleted' => 'hard'];
    }
}
