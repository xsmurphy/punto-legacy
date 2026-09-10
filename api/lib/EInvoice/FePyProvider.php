<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Cliente HTTP de FE-PY — el motor de facturación electrónica de Punto
 * (`facturacionelectronicapy-xmlgen` envuelto en una API multi-tenant,
 * Fastify + Zod + Postgres). Única implementación de `EInvoiceProvider`.
 *
 * Mismo estilo que el resto de los clientes del repo (DlocalGoProvider):
 * cURL directo, sin librería HTTP externa, timeouts explícitos, parseo
 * defensivo, y el payload saliente a `error_log` salvo cuando lleva secretos.
 *
 * ── Los tres parámetros que lleva toda la interfaz ───────────────────────
 *
 *   $environment  → NO elige host. Test/prod es el campo `env` del TENANT
 *                   (se fija al darlo de alta y decide contra qué SIFEN se
 *                   firma), no un par de hosts distintos. Acá solo sirve
 *                   para el mensaje de error. Por eso FEPY_BASE_URL es una
 *                   sola constante.
 *   $tenantRef    → el UUID v7 del emisor
 *                   (`einvoice_account.provider_tenant_ref`, mig 206). Va en
 *                   el path de todas las rutas.
 *   $bearer       → la **API key de company** (`cmp_` + 32 hex). NO es un
 *                   token de sesión: no expira, no se renueva, no se cachea.
 *                   La resuelve `FePySession` desde la configuración.
 *
 * ── Métodos nativos, fuera de la interfaz ────────────────────────────────
 *
 * `createTenant`, `uploadCertificate`, `setCsc`, `patchTenant` y `readiness`
 * son el ALTA y el mantenimiento del emisor, que no están en
 * `EInvoiceProvider` porque tienen la forma de ESTE motor (ver el docblock de
 * la interfaz). Los consume `EInvoiceProvisioningService`.
 *
 * ── Diferencias de comportamiento que hay que tener presentes ────────────
 *
 *  a) **La numeración.** Punto manda el correlativo congelado de la venta y
 *     FE-PY lo respeta, sincronizando su secuencia hacia arriba (contrato
 *     actualizado 2026-09-07); omitirlo activa su contador propio. La
 *     divergencia, si igual ocurriera, NO se tapa: el CDC que vuelve la
 *     delata y `EInvoiceService::cdcMismatchFor()` la anota en
 *     `numbering_mismatch` (mig 204).
 *
 *  b) **Un 502 NO significa "no se emitió".** Cuando el envío a SIFEN falla,
 *     FE-PY ya persistió el documento (con CDC) y encoló un reintento
 *     propio, y recién entonces responde 502. Reintentar a ciegas duplica.
 *     Por eso `issue()` manda `Idempotency-Key` SIEMPRE (ver más abajo) y
 *     por eso un 502 se reporta como error reintentable: el reintento con la
 *     misma key es un no-op del lado de ellos.
 *
 *  c) **Idempotencia REAL, y su filo.** FE-PY cachea la
 *     respuesta por `(company, Idempotency-Key)` durante 24 h y responde 409
 *     si la misma key llega con OTRO body. La key deriva del `einvoicedocid`
 *     —la fila del outbox, que es la unidad que se reintenta— MÁS la huella
 *     del body que se está mandando. Ver `idempotencyKey()`: la key
 *     puramente estable por documento envenenaba el documento entero ante
 *     cualquier deploy que cambiara el payload.
 *
 *  c2) **`txnId`: el hilo con el que se recupera un huérfano.** La emisión lo
 *     devuelve SIEMPRE, tenga o no CDC. Se persiste en
 *     `einvoice_document.provider_txn_id` (mig 217) en el mismo UPDATE que
 *     fija `issued`/`error`, y con él `lookupByTxn()` pregunta si el
 *     documento ya existe del otro lado ANTES de reintentar.
 *
 *  d) **El `montoTotal` que devuelven NO es el total fiscal**: lo calculan
 *     como Σ(cantidad × precioUnitario) sin IVA ni descuentos ("Simplificado
 *     para el MVP", su `de.service.ts`). No se usa para nada acá.
 */
final class FePyProvider implements EInvoiceProvider
{
    private const CONNECT_TIMEOUT = 5;
    /**
     * 45 s y no los 20 de un cliente HTTP cualquiera: la emisión es SÍNCRONA
     * hasta el veredicto de SIFEN — manda el lote y hace polling con backoff
     * [2,3,5,8,12] s (~30 s) antes de contestar `pendiente`. Con 20 s el
     * timeout nuestro cortaría la conexión JUSTO en la ventana en la que el
     * documento ya está emitido, que es el peor momento posible para cortar.
     */
    private const TOTAL_TIMEOUT = 45;

    /** Clave reservada que `issue()` saca del payload — ver el docblock de issue(). */
    public const IDEMPOTENCY_PAYLOAD_KEY = '__idempotencyKey';

    /**
     * Medios de pago. FE-PY no expone catálogo: los códigos son la Tabla 22
     * de SIFEN, fijos por normativa, y su motor los valida contra esa misma
     * lista (`constants.service.ts`). Se sirve acá para que la pantalla de
     * mapeo de medios de pago del panel funcione igual con los dos
     * proveedores — la alternativa (tirar LogicException) dejaría al comercio
     * sin poder mapear sus medios de pago por una diferencia de transporte.
     *
     * Shape `Items[]` con `Identifier`/`Description`: es el que
     * `EInvoiceService::normalizePaymentMethods()` ya parsea.
     */
    private const SIFEN_PAYMENT_METHODS = [
        1 => 'Efectivo',
        2 => 'Cheque',
        3 => 'Tarjeta de crédito',
        4 => 'Tarjeta de débito',
        5 => 'Transferencia',
        6 => 'Giro',
        7 => 'Billetera electrónica',
        8 => 'Tarjeta empresarial',
        9 => 'Vale',
        10 => 'Retención',
        11 => 'Pago por anticipo',
        12 => 'Valor fiscal',
        13 => 'Valor comercial',
        14 => 'Compensación',
        15 => 'Permuta',
        16 => 'Pago bancario',
        17 => 'Pago móvil',
        18 => 'Donación',
        19 => 'Promoción',
        20 => 'Consumo interno',
        21 => 'Pago electrónico',
        99 => 'Otro',
    ];

    private function baseUrl(): string
    {
        // platform_config primero (mismo criterio que la key — ver
        // FePySession::getBearer), env de fallback.
        require_once __DIR__ . '/../Admin/PlatformConfig.php';
        $cfg = \PlatformConfig::get('integration.fepy', []);
        $url = is_array($cfg) ? trim((string) ($cfg['baseUrl'] ?? '')) : '';
        if ($url === '') {
            $url = defined('FEPY_BASE_URL') ? trim((string) constant('FEPY_BASE_URL')) : '';
        }
        if ($url === '') {
            // Nunca un fallback a localhost ni a un host adivinado: en
            // producción sería mandar documentos fiscales a la nada (o, peor,
            // a otro sitio) y enterarse tarde. Mismo criterio que el guard de
            // por entorno.
            throw new \RuntimeException(
                'FEPY_BASE_URL no está configurada — no se puede operar contra el motor propio de facturación electrónica.'
            );
        }
        return rtrim($url, '/');
    }

    /**
     * Datos del emisor — `GET /v1/tenants/{ref}`.
     *
     * Se devuelve el shape CRUDO de FE-PY (`ruc`, `razonSocial`,
     * `nombreFantasia`, `timbradoNumero`, `env`, `status`, …). Alcanza porque
     * los dos consumidores lo leen con casing flexible: el panel muestra la
     * razón social y `EInvoiceService::cdcMismatchFor()` busca `Ruc`/`ruc`
     * para comprobar que el CDC devuelto es de ESTE contribuyente — la
     * segunda es un guard fiscal y por eso la clave `ruc` no se toca.
     *
     * OJO: el `ruc` de FE-PY viene CON dígito verificador (`80069563-1`); el
     * guard del CDC ya hace `explode('-')`.
     */
    public function userInfo(string $environment, string $tenantRef, string $bearer): array
    {
        return $this->request('GET', '/v1/tenants/' . rawurlencode($tenantRef), null, $bearer);
    }

    /**
     * GET /v1/tenants/{ref}/readiness — la lista de chequeos del emisor
     * (tenant activo, RUC con DV valido, certificado y su vigencia, CSC,
     * numeración) más `unverifiable`: lo que SIFEN recién valida al emitir
     * (fecha exacta del timbrado, habilitación del RUC). Es el GATE del
     * cutover: no se flipea un tenant a este motor sin `ready === true`,
     * y el "Verificar estado" del wizard muestra estos checks tal cual.
     *
     * @return array{ready:bool,checks:array<int,array{check:string,ok:bool,detail:string}>,unverifiable:array<int,string>}
     */
    public function readiness(string $tenantRef, string $bearer): array
    {
        $raw = $this->request('GET', '/v1/tenants/' . rawurlencode($tenantRef) . '/readiness', null, $bearer);
        return [
            'ready'        => !empty($raw['ready']),
            'checks'       => is_array($raw['checks'] ?? null) ? $raw['checks'] : [],
            'unverifiable' => is_array($raw['unverifiable'] ?? null) ? $raw['unverifiable'] : [],
        ];
    }

    /**
     * Timbrado del emisor, en el shape `Items[]` con `StampNumber` que
     * `EInvoiceService::extractStamp()` ya parsea.
     *
     * El timbrado es **del TENANT**, no una fila por punto de expedición:
     * vive en `tenants.timbradoNumero/timbradoFecha/timbradoVencimiento` y el
     * servicio de emisión lo inyecta en el bloque `params` de cada documento
     * leyéndolo de ahí — el caller no puede mandarlo ni pisarlo.
     * Consecuencias:
     *
     *   - No existe un `Id` de timbrado; se devuelve vacío.
     *   - `Stablishment`/`ExpeditionPoint` van vacíos: el emisor tiene N
     *     establecimientos y el punto se elige POR DOCUMENTO, así que no hay
     *     un par único que declarar acá. El del documento sale de la CAJA que
     *     vendió (`context/29`).
     *   - `CurrentNumber` va null: el contador es por (tipo, est, punto) y no
     *     se expone. Por eso la divergencia de numeración se DETECTA sobre el
     *     CDC devuelto en vez de prevenirse con un pre-flight.
     */
    public function stamps(string $environment, string $tenantRef, string $bearer): array
    {
        $tenant = $this->userInfo($environment, $tenantRef, $bearer);

        $stampNumber = trim((string) ($tenant['timbradoNumero'] ?? ''));
        if ($stampNumber === '') {
            return ['Items' => []];
        }

        return ['Items' => [[
            'Id'              => '',
            'StampNumber'     => $stampNumber,
            'StampDate'       => (string) ($tenant['timbradoFecha'] ?? ''),
            'ExpirationDate'  => (string) ($tenant['timbradoVencimiento'] ?? ''),
            'Stablishment'    => '',
            'ExpeditionPoint' => '',
            'CurrentNumber'   => null,
            'Serie'           => '',
            'Deleted'         => false,
        ]]];
    }

    /** Catálogo fijo de la Tabla 22 de SIFEN — ver SIFEN_PAYMENT_METHODS. */
    public function paymentMethods(string $environment, string $tenantRef, string $bearer): array
    {
        $items = [];
        foreach (self::SIFEN_PAYMENT_METHODS as $code => $name) {
            $items[] = ['Identifier' => $code, 'Description' => $name];
        }
        return ['Items' => $items];
    }

    // ── Emisión y ciclo de vida del documento ────────────────────────────

    /**
     * `POST /v1/tenants/{ref}/de` — emisión.
     *
     * El payload lo arma `SaleToFePyMapper` y ya viene en el JSON del motor
     * xmlgen (`data`, SIFEN v150). Acá NO se envuelve en nada: a diferencia
     * El body ES el documento, sin envoltorio.
     *
     * ── La `Idempotency-Key` ─────────────────────────────────────────────
     *
     * Viaja como header y su semilla sale de una clave reservada del payload
     * (`__idempotencyKey`, el `einvoicedocid`), que este método QUITA antes
     * de serializar. Es feo y es a propósito: `EInvoiceProvider::issue()` no
     * tiene un parámetro donde meterla y cambiarle la firma a la interfaz por
     * un detalle de transporte de UN motor era peor.
     *
     * La key final la arma `idempotencyKey()` con esa semilla MÁS la huella
     * del body — leer su docblock antes de tocar nada acá.
     *
     * SIN key el reintento de un timeout emite DOS veces. Por eso no hay
     * camino sin ella: si el mapper no la puso, esto lanza.
     *
     * @return array{cdc:?string,documentNumber:?string,txnId:?string,success:bool,statusMessage:?string,bulkId:?string,dCarQR:?string,xmlUrl:?string,raw:array}
     */
    public function issue(string $environment, string $tenantRef, string $bearer, array $payload): array
    {
        $documentRef = trim((string) ($payload[self::IDEMPOTENCY_PAYLOAD_KEY] ?? ''));
        unset($payload[self::IDEMPOTENCY_PAYLOAD_KEY]);
        if (strlen($documentRef) < 8) {
            throw new \RuntimeException(
                'El documento no trae clave de idempotencia (einvoicedocid) — no se emite sin ella: ' .
                'un reintento tras un timeout emitiría el documento fiscal dos veces.'
            );
        }

        $raw = $this->request(
            'POST',
            '/v1/tenants/' . rawurlencode($tenantRef) . '/de',
            $payload,
            $bearer,
            ['Idempotency-Key: ' . self::idempotencyKey($documentRef, $payload)]
        );

        $cdc    = self::stringOrNull($raw['cdc'] ?? null);
        $estado = strtolower(trim((string) ($raw['estado'] ?? '')));

        // `success` = "el proveedor aceptó el documento y lo mandó a SIFEN",
        // NO "SIFEN lo aprobó" — ese es el motivo por el que existe
        // `sifen_status` como columna aparte. Un `pendiente`
        // con CDC es un éxito de emisión: el documento ya existe, tiene
        // número fiscal y SIFEN todavía está procesando el lote. Tratarlo
        // como error lo dejaría elegible para retry() y lo duplicaría.
        $success = $cdc !== null && $cdc !== '' && !in_array($estado, ['rechazado', 'error'], true);

        $sifen   = is_array($raw['sifen'] ?? null) ? $raw['sifen'] : [];
        $message = null;
        if (!$success) {
            $code = trim((string) ($sifen['codigoRespuesta'] ?? ''));
            $msg  = trim((string) ($sifen['mensaje'] ?? ''));
            $message = trim($code !== '' && $msg !== '' ? "$code — $msg" : ($msg !== '' ? $msg : $code));
            if ($message === '') {
                $message = $estado !== ''
                    ? "El motor de facturación devolvió el documento en estado '$estado' sin motivo reconocible."
                    : 'El motor de facturación no devolvió CDC ni motivo reconocible.';
            }
        }

        return [
            'cdc' => $cdc,
            // El número lo asigna FE-PY (ver punto (a) del docblock de la
            // clase). Se guarda tal cual devolvió: es el que quedó en el CDC
            // y en SIFEN, y es contra el que se detecta la divergencia con
            // el correlativo impreso en el ticket.
            'documentNumber' => self::stringOrNull($raw['numero'] ?? null),
            // El identificador de la TRANSACCIÓN de emisión. Viene con CDC y
            // viene sin CDC, y es lo único que permite volver a encontrar el
            // documento cuando la emisión terminó mal (mig 217). Se devuelve
            // siempre, incluso en el camino de error de abajo: el caller lo
            // persiste antes de marcar la fila.
            'txnId'          => self::stringOrNull($raw['txnId'] ?? null),
            'success'        => $success,
            'statusMessage'  => $message,
            // Llave de reconciliación de ESTE proveedor: el CDC. FE-PY no
            // tiene lectura por su txnId interno — la reconsulta es por CDC.
            // Ver el COMMENT de provider_number en la mig 206.
            'bulkId'         => $cdc,
            // FE-PY no devuelve el link del QR en la respuesta: va DENTRO del
            // XML firmado (`dCarQR`). El KuDE propio (context/73) lo saca de
            // ahí; acá no se inventa.
            'dCarQR'         => null,
            'xmlUrl'         => self::stringOrNull($raw['xmlUrl'] ?? null),
            'raw'            => $raw,
        ];
    }

    /**
     * `POST /v1/tenants/{ref}/eventos/cancelacion` — evento de cancelación.
     *
     * El motivo tiene mínimo 10 y máximo 500 caracteres en su Zod. NO se
     * rellena ni se trunca a ciegas por abajo: el motivo de una cancelación
     * es un dato que queda ante SIFEN, y completarlo con relleno para que
     * pase la validación es fabricarle contenido a un acto fiscal. Se corta
     * antes con un mensaje que el operador entiende. Por arriba sí se trunca:
     * ahí no se inventa nada, se recorta lo que el operador escribió de más.
     *
     * Reglas de negocio que aplica FE-PY y conviene conocer: solo cancela
     * documentos en estado `aprobado` (409 si no), y una segunda cancelación
     * del mismo CDC también es 409.
     */
    public function cancel(string $environment, string $tenantRef, string $bearer, string $cdc, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new \RuntimeException(
                'El motivo de la cancelación tiene que tener al menos 10 caracteres — es el texto que queda registrado ante SIFEN.'
            );
        }

        $raw = $this->request(
            'POST',
            '/v1/tenants/' . rawurlencode($tenantRef) . '/eventos/cancelacion',
            ['cdc' => $cdc, 'motivo' => mb_substr($reason, 0, 500)],
            $bearer,
            // Misma idempotencia que la emisión: cancelar dos veces por un
            // timeout es un 409 del lado de ellos, pero con la key es un
            // replay limpio de la respuesta original.
            ['Idempotency-Key: cancel-' . $cdc]
        );

        $estado = strtolower(trim((string) ($raw['estado'] ?? '')));

        return [
            // `pendiente`/`enviado`/`aprobado` son todos "la cancelación
            // entró"; solo `rechazado`/`error` la niegan. El estado fiscal
            // definitivo lo resuelve la reconciliación, igual que en la
            // emisión.
            'success' => $estado !== '' && !in_array($estado, ['rechazado', 'error'], true),
            'message' => self::stringOrNull($raw['sifenMensaje'] ?? null)
                ?? self::stringOrNull($raw['sifenCodigoRespuesta'] ?? null)
                ?? ($estado !== '' ? "Estado del evento: $estado" : null),
            'raw'     => $raw,
        ];
    }

    /**
     * `GET /v1/tenants/{ref}/de/{cdc}/kude` — bytes del PDF.
     *
     * Con backoff por una razón física: entre que el documento se acepta y
     * el PDF termina de generarse pasan segundos.
     * Se reintenta SOLO ante 5xx; un 4xx no se reintenta.
     *
     * El 404 de este endpoint es AMBIGUO y por eso se traduce: FE-PY
     * responde 404 tanto cuando el CDC no existe como cuando el PDF no se
     * generó porque su `ENABLE_KUDE` está apagado (necesita un runtime Java).
     * Lo segundo es un problema de configuración del motor, no del documento
     * — y con el mensaje crudo ("KUDE not available…") el operador no puede
     * distinguirlo. Nota: hoy el PDF que se ENTREGA es el propio
     * (`EInvoiceService::kudePdf`, context/73 K2); esto es el fallback.
     */
    public function kude(string $environment, string $tenantRef, string $bearer, string $cdc): string
    {
        return $this->getBinaryWithRetry(
            '/v1/tenants/' . rawurlencode($tenantRef) . '/de/' . rawurlencode($cdc) . '/kude',
            $bearer,
            'KuDE'
        );
    }

    /**
     * `GET /v1/tenants/{ref}/de/{cdc}/xml` — el XML FIRMADO, en bytes.
     *
     * Es el insumo de `EInvoiceService::archiveSignedXml()`: el XML firmado
     * es el documento fiscal de verdad y conservarlo es obligación del
     * EMISOR. Sale de un GET por CDC, sin URLs prefirmadas que expiran.
     */
    public function xml(string $environment, string $tenantRef, string $bearer, string $cdc): string
    {
        return $this->getBinaryWithRetry(
            '/v1/tenants/' . rawurlencode($tenantRef) . '/de/' . rawurlencode($cdc) . '/xml',
            $bearer,
            'XML firmado'
        );
    }

    /**
     * Consulta del padrón — `GET /v1/tenants/{ref}/consulta/ruc/{ruc}`.
     *
     * FE-PY devuelve `{ruc, response}` donde `response` es el payload CRUDO
     * de SIFEN (con prefijos `ns2:` sin normalizar). El consumidor
     * (`Contacts\TaxpayerLookupService::fromEmitter()`) parsea a la
     * defensiva y trata un shape inesperado como "no encontrado", cayendo al
     * padrón público — así que un shape distinto degrada, no rompe el alta
     * del cliente. Se devuelve crudo en vez de adivinar una normalización.
     */
    public function clientByRuc(string $environment, string $tenantRef, string $bearer, string $ruc): array
    {
        $doc = explode('-', trim($ruc))[0];
        return $this->request(
            'GET',
            '/v1/tenants/' . rawurlencode($tenantRef) . '/consulta/ruc/' . rawurlencode($doc),
            null,
            $bearer
        );
    }

    /**
     * Reconciliación del estado FISCAL. `$bulkId` acá es el **CDC** (ver el
     * COMMENT de `provider_number` en la mig 206).
     *
     * Se usa la RECONSULTA (`POST …/de/{cdc}/consulta`), no la lectura
     * (`GET …/de/{cdc}`): la reconsulta le pregunta a SIFEN de verdad y
     * actualiza el estado del lado de ellos; el GET devuelve lo último que
     * quedó guardado. Un documento que salió `pendiente` porque SIFEN no
     * contestó dentro de los ~30 s del polling se quedaría `pendiente` para
     * siempre si solo lo leyéramos.
     *
     * Fallback al GET ante 4xx: la reconsulta responde 400 cuando el motor
     * corre con `ENABLE_SIFEN=false` (entorno de pruebas sin firma). En ese
     * caso el GET igual dice en qué estado quedó el documento, que es más
     * útil que no reconciliar nada. Un 5xx NO cae al fallback: es un fallo
     * transitorio y la corrida siguiente lo reintenta.
     *
     * ── La traducción ────────────────────────────────────────────────────
     *
     * La respuesta nativa se traduce al shape que el resto de la aplicación
     * ya lee. Es deliberado: `EInvoiceService::sifenStatusFromBulk()` y
     * `sifenReason()` son estáticas, las consume también `TransactionsService`
     * para pintar el motivo del rechazo en el listado de ventas, y —lo que
     * decide— el jsonb `sifen_result` ya persistido tiene ese shape en las
     * filas existentes. Traducir en el adapter deja UNA sola forma de leer el
     * estado fiscal en toda la aplicación; hacerlo en el parseo la duplicaría
     * para siempre, incluso para las filas ya guardadas. El payload nativo se
     * conserva íntegro bajo la clave `FePy` para no perder trazabilidad.
     */
    public function getBulk(string $environment, string $tenantRef, string $bearer, string $bulkId): array
    {
        $base = '/v1/tenants/' . rawurlencode($tenantRef) . '/de/' . rawurlencode($bulkId);

        try {
            $raw = $this->request('POST', $base . '/consulta', [], $bearer);
        } catch (FePyHttpException $e) {
            if ($e->statusCode >= 500) {
                throw $e;
            }
            error_log("[FePy] reconsulta no disponible (HTTP {$e->statusCode}) — se lee el estado guardado.");
            $raw = $this->request('GET', $base, null, $bearer);
        }

        return self::toBulkShape($raw);
    }

    /**
     * Traduce la respuesta de un documento de FE-PY al shape de bulk que
     * parsea EInvoiceService. Pública y estática para poder ejercitarla desde
     * un arnés sin levantar HTTP.
     *
     * Mapeo de estados: FE-PY expone `pendiente|aprobado|rechazado|error` en
     * el borde (su enum interno tiene además `generando|firmando|enviando`,
     * que no salen por la API). `dEstResField` se llena SOLO con un veredicto
     * definitivo —"Aprobado"/"Rechazado"— porque es el campo que
     * `isSifenApproved()` mira para disparar la entrega del KuDE por email
     * (context/57 E2): poner ahí un "pendiente" invitaría a que cualquier
     * comparación laxa mande el mail de una factura que SIFEN todavía no
     * aprobó. Un `pendiente` viaja por `StatusString`, que es informativo.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function toBulkShape(array $raw): array
    {
        $estado = strtolower(trim((string) ($raw['estado'] ?? '')));
        $sifen  = is_array($raw['sifen'] ?? null) ? $raw['sifen'] : [];

        $verdict = null;
        if ($estado === 'aprobado') {
            $verdict = 'Aprobado';
        } elseif ($estado === 'rechazado') {
            $verdict = 'Rechazado';
        }

        $prot = [];
        if ($verdict !== null) {
            $prot['dEstResField'] = $verdict;
        }
        $protocol = trim((string) ($sifen['protocoloAutorizacion'] ?? ''));
        if ($protocol !== '') {
            $prot['dProtAutField'] = $protocol;
        }
        $code = trim((string) ($sifen['codigoRespuesta'] ?? ''));
        $msg  = trim((string) ($sifen['mensaje'] ?? ''));
        if ($code !== '' || $msg !== '') {
            // `gResProcField` con `dCodResField`/`dMsgResField`: son las
            // claves que `EInvoiceService::sifenReason()` busca para armar el
            // motivo legible ("1002 — documento duplicado").
            $prot['gResProcField'] = [[
                'dCodResField' => $code,
                'dMsgResField' => $msg,
            ]];
        }

        $item = [
            'Success'      => $estado === 'aprobado',
            // Estado crudo como fallback informativo. `sifen_status` es
            // VARCHAR(20) (mig 95) y estos valores miden 9 como mucho.
            'StatusString' => $estado !== '' ? $estado : '',
        ];
        if ($prot !== []) {
            $item['SifenResult'] = ['rRetEnviDe' => ['rProtDeField' => $prot]];
        }

        return [
            'Items' => [$item],
            // Payload nativo íntegro: la traducción de arriba es lossy a
            // propósito (solo lo que el módulo lee) y perder el original
            // haría indiagnosticable cualquier caso raro.
            'FePy'  => $raw,
        ];
    }

    /**
     * RECUPERACIÓN por el identificador de la transacción de emisión —
     * `GET /v1/tenants/{ref}/de/txn/{txnId}`.
     *
     * Es la lectura que faltaba. La reconsulta (`getBulk`) va por CDC, y
     * justo el documento que hay que recuperar es el que puede no tenerlo:
     * el POST de emisión devuelve `txnId` SIEMPRE, con CDC o sin él.
     */
    public function lookupByTxn(string $environment, string $tenantRef, string $bearer, string $txnId): array
    {
        $txnId = trim($txnId);
        if ($txnId === '') {
            throw new \RuntimeException('No se puede recuperar un documento sin el identificador de su transacción de emisión.');
        }

        return self::toLookupShape($this->lookupRequest(
            '/v1/tenants/' . rawurlencode($tenantRef) . '/de/txn/' . rawurlencode($txnId),
            $bearer
        ));
    }

    /**
     * RECUPERACIÓN por número — `GET /v1/tenants/{ref}/de/numero/{est}/{punto}/{numero}?tipoDocumento=N`.
     *
     * El fallback para cuando se perdió la respuesta HTTP entera: sin `txnId`
     * lo único que identifica al documento es la serie con la que se lo iba a
     * emitir, que es un dato NUESTRO y siempre está.
     *
     * `tipoDocumento` va SIEMPRE en la query y no tiene default — el índice de
     * ellos incluye el tipo, así que omitirlo cruza una factura con una nota
     * de crédito del mismo número, que pueden coexistir vigentes.
     */
    public function lookupByNumber(
        string $environment,
        string $tenantRef,
        string $bearer,
        int $documentType,
        string $establishment,
        string $expeditionPoint,
        string $number
    ): array {
        $establishment   = trim($establishment);
        $expeditionPoint = trim($expeditionPoint);
        $number          = trim($number);
        if ($documentType <= 0 || $establishment === '' || $expeditionPoint === '' || $number === '') {
            throw new \RuntimeException(
                'No se puede recuperar un documento por número sin tipo, establecimiento, punto de expedición y número completos — ' .
                'una búsqueda incompleta devolvería el documento equivocado.'
            );
        }

        $path = '/v1/tenants/' . rawurlencode($tenantRef) . '/de/numero/'
            . rawurlencode($establishment) . '/' . rawurlencode($expeditionPoint) . '/' . rawurlencode($number)
            . '?tipoDocumento=' . rawurlencode((string) $documentType);

        return self::toLookupShape($this->lookupRequest($path, $bearer));
    }

    /**
     * GET de recuperación. El 404 NO es un error: es la respuesta "acá no hay
     * nada con ese identificador", que es exactamente lo que el caller
     * necesita saber para poder emitir. Cualquier otro fallo SÍ sube — un 500
     * o un timeout significan "no sé", y tratar un "no sé" como "no existe"
     * es el camino directo a emitir el documento dos veces.
     *
     * @return array<string,mixed>
     */
    private function lookupRequest(string $path, string $bearer): array
    {
        try {
            return $this->exec($path, 'GET', ['Accept: application/json', 'Authorization: Bearer ' . $bearer], null);
        } catch (FePyHttpException $e) {
            if ($e->statusCode === 404) {
                return ['vigente' => null, 'intentos' => []];
            }
            throw $e;
        }
    }

    /**
     * Normaliza la respuesta de recuperación al shape del contrato.
     *
     * Lo único que hace es garantizar las dos claves y sus tipos: el
     * `vigente` se pasa TAL CUAL. Elegir nosotros entre `intentos` cuál es el
     * documento bueno sería reimplementar —peor— el único parcial que ellos
     * ya tienen en la base; `intentos` queda disponible para diagnóstico y
     * nada más.
     *
     * @param array<string,mixed> $raw
     * @return array{vigente:?array<string,mixed>,intentos:list<array<string,mixed>>}
     */
    private static function toLookupShape(array $raw): array
    {
        $vigente = $raw['vigente'] ?? null;
        $intentos = $raw['intentos'] ?? [];

        return [
            'vigente'  => is_array($vigente) && $vigente !== [] ? $vigente : null,
            'intentos' => is_array($intentos) ? array_values(array_filter($intentos, 'is_array')) : [],
        ];
    }

    /**
     * La `Idempotency-Key` de una emisión: identidad del documento MÁS huella
     * del body.
     *
     * ── Por qué no alcanza con el `einvoicedocid` a secas ─────────────────
     *
     * Era eso hasta hoy, y esa estabilidad pura envenenó un documento fiscal
     * real. La nota de crédito nº 2 (2026-09-10) se emitió con el payload de
     * ANTES de que existiera la serie propia de NC; en el medio se deployó, el
     * payload pasó a llevar `numero`, y cada reintento chocó contra el body
     * cacheado: *"Idempotency-Key was reused with a different request body"*,
     * 409, ocho veces, hasta agotar los intentos. El documento existía del
     * otro lado y del nuestro decía `error`. Con una key estable por
     * documento, CUALQUIER deploy que toque el mapper envenena a todo
     * documento en vuelo — y el mapper se toca seguido, porque es donde vive
     * la regla fiscal.
     *
     * ── Por qué agregar la huella NO reabre la doble emisión ──────────────
     *
     * Porque la key nunca fue lo que evita la doble emisión ante un cambio de
     * payload: ante otro body el motor RECHAZA (409), no deduplica. Lo que
     * evita es reintentar el MISMO body cuando se perdió la respuesta —
     * timeout, 502, proceso muerto— y eso se conserva intacto: mismo body,
     * misma huella, misma key, replay de la respuesta cacheada.
     *
     * El caso "body distinto" lo cubre ahora el paso de RECUPERACIÓN
     * (`lookupByTxn`/`lookupByNumber`, que `EInvoiceService` corre antes de
     * todo reintento): si el documento ya existe del otro lado no se emite, y
     * punto. Con eso "consultar antes de reemitir" deja de depender de la key,
     * que vuelve a ser lo que siempre debió ser — protección de transporte, no
     * garantía fiscal.
     *
     * ── El largo ──────────────────────────────────────────────────────────
     *
     * 36 caracteres, con forma de UUID: los primeros 24 del `einvoicedocid`
     * (que lo dejan greppable contra nuestra fila en los logs de ellos) más 12
     * hex de huella en el lugar del nodo. Se respeta ese largo a propósito —
     * es el único que está PROBADO contra el motor en producción; su Zod
     * declara mínimo 8 y no tenemos su máximo documentado, así que no se
     * inventa una key más larga para averiguarlo con documentos fiscales.
     * Colisionar exige que dos UUID v4 compartan sus primeros 20 dígitos hex.
     *
     * Pública y estática por el mismo motivo que `toBulkShape()`: es una
     * función determinística que el arnés tiene que poder fijar sin levantar
     * HTTP — y lo que hay que fijar acá es justamente que dos payloads
     * distintos no comparten key.
     *
     * @param array<string,mixed> $payload El body EXACTO que se va a mandar, ya sin la clave reservada.
     */
    public static function idempotencyKey(string $documentRef, array $payload): string
    {
        $fingerprint = substr(hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE)), 0, 12);

        // Forma canónica `8-4-4-4-12`: los primeros 24 chars terminan en el
        // guión y el nodo son los 12 últimos, que es donde entra la huella.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $documentRef) === 1) {
            return substr($documentRef, 0, 24) . $fingerprint;
        }

        // El id del documento no tiene forma de UUID (no debería pasar: es una
        // PK `gen_random_uuid()`). Se concatena y listo — sigue siendo
        // determinística por (documento, body), que es lo único que importa.
        return $documentRef . '-' . $fingerprint;
    }

    // ── Provisioning: lo nativo de FE-PY ─────────────────────────────────

    /**
     * `POST /v1/tenants` — alta del emisor. Devuelve el UUID del tenant, que
     * es lo único que hay que persistir (`provider_tenant_ref`, mig 206).
     *
     * El alta es UNA sola llamada que ya lleva timbrado, establecimientos y
     * actividades económicas. Y no devuelve credenciales de usuario — no hay
     * usuario, la auth es la API key de company.
     *
     * NO es idempotente por header (su `Idempotency-Key` solo está cableada
     * en emisión y eventos), pero SÍ lo es por regla de negocio: un segundo
     * alta con el mismo `(company, ruc)` responde 409. El caller igual
     * checkpointea (`fepyTenantCreated`) para no depender de interpretar un
     * 409 ajeno.
     *
     * @param array<string,mixed> $tenant Body ya armado por EInvoiceProvisioningService.
     * @return array{tenantRef:string,raw:array}
     */
    public function createTenant(string $environment, string $bearer, array $tenant): array
    {
        $raw = $this->request('POST', '/v1/tenants', $tenant, $bearer);

        $ref = trim((string) ($raw['id'] ?? ''));
        if ($ref === '') {
            $keys = implode(', ', array_keys($raw));
            throw new \RuntimeException(
                "El alta del emisor no devolvió el identificador del tenant (claves: $keys) — no se puede continuar el provisioning."
            );
        }

        return ['tenantRef' => $ref, 'raw' => $raw];
    }

    /**
     * `PATCH /v1/tenants/{ref}` — actualización PARCIAL del emisor.
     *
     * PATCH y no PUT: los datos que definen fiscalmente al emisor (RUC,
     * razón social, timbrado) se fijan en el alta y no se tocan desde acá.
     * Esto sirve para lo accesorio que SÍ cambia con el tiempo y que el motor
     * necesita para dibujar el KuDE — hoy, el logo del comercio.
     *
     * @param array<string,mixed> $fields Solo las claves que se quieren pisar.
     */
    public function patchTenant(string $environment, string $tenantRef, string $bearer, array $fields): array
    {
        return $this->request('PATCH', '/v1/tenants/' . rawurlencode($tenantRef), $fields, $bearer);
    }

    /**
     * `POST /v1/tenants/{ref}/cert` — certificado de firma, **multipart**
     * (partes literales `file` y `password`), no JSON en base64.
     *
     * El `.p12` y su contraseña PASAN y no se persisten ni se loguean acá —
     * la custodia cifrada es de `FiscalSecretStore`, que es de donde el
     * provisioning los saca. El multipart se arma a mano (CURLFile escribiría
     * el certificado a un archivo temporal del disco).
     *
     * FE-PY valida antes de aceptar: parsea el PKCS#12, rechaza vencidos y
     * —esto es lo importante— exige que el RUC DENTRO del certificado sea el
     * del tenant. Un cert de otra empresa no entra.
     *
     * @param string $certBinary Bytes del `.p12` (NO base64).
     */
    public function uploadCertificate(string $environment, string $tenantRef, string $bearer, string $certBinary, string $certPassword): array
    {
        $path = '/v1/tenants/' . rawurlencode($tenantRef) . '/cert';
        error_log("[FePy] POST $path body=<certificado redactado>");

        $boundary = '----PuntoFePy' . bin2hex(random_bytes(16));
        $eol = "\r\n";
        $body = '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="file"; filename="cert.p12"' . $eol
            . 'Content-Type: application/x-pkcs12' . $eol . $eol
            . $certBinary . $eol
            . '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="password"' . $eol . $eol
            . $certPassword . $eol
            . '--' . $boundary . '--' . $eol;

        return $this->exec($path, 'POST', [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearer,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ], $body, [$certPassword]);
    }

    /**
     * `PUT /v1/tenants/{ref}/csc` — código de seguridad del contribuyente.
     * PUT y no POST: es un upsert (uno solo por tenant).
     *
     * El CSC es un SECRETO de SIFEN (firma el QR del KuDE), así que esta
     * request NO pasa por el log de body — mismo criterio que
     * el resto de los cuerpos con secretos, donde un leak así queda latente
     * hasta que alguien carga un valor de verdad. También se declara para la
     * RESPUESTA: si el motor lo eco-ea al rechazarlo, se tacha antes de que
     * toque un log o `last_error` (que es visible en el panel).
     */
    public function setCsc(string $environment, string $tenantRef, string $bearer, string $cscId, string $cscSecret): array
    {
        $path = '/v1/tenants/' . rawurlencode($tenantRef) . '/csc';
        error_log("[FePy] PUT $path body={\"cscId\":\"$cscId\",\"csc\":\"<redactado>\"}");

        return $this->exec($path, 'PUT', [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearer,
            'Content-Type: application/json',
        ], json_encode(['cscId' => $cscId, 'csc' => $cscSecret], JSON_UNESCAPED_UNICODE), [$cscSecret]);
    }

    // ── Internals ────────────────────────────────────────────────────────

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Request JSON. `$jsonBody === null` → sin Content-Type y sin body.
     *
     * @param array<int,string> $extraHeaders
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $jsonBody, string $bearer, array $extraHeaders = []): array
    {
        $headers = array_merge(['Accept: application/json', 'Authorization: Bearer ' . $bearer], $extraHeaders);

        $body = null;
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            // `{}` a mano y NO JSON_FORCE_OBJECT: ese flag convierte TODA
            // lista en objeto con claves numéricas y destruiría `items[]` /
            // `condicion.entregas[]`, con un rechazo del motor que no
            // menciona la causa real.
            $body = $jsonBody === [] ? '{}' : json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
            error_log("[FePy] $method $path body=$body");
        }

        return $this->exec($path, $method, $headers, $body);
    }

    /**
     * @param array<int,string> $headers
     * @param array<int,string> $secrets Valores que no pueden aparecer en un log ni en un mensaje de error.
     * @return array<string,mixed>
     * @throws FePyHttpException
     */
    private function exec(string $path, string $method, array $headers, ?string $body, array $secrets = []): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            // 599 = error de red, tratado como reintentable (mismo criterio
            // que un 5xx). Ojo con la emisión: un timeout NO prueba que el
            // documento no se creó — por eso `issue()` va siempre con
            // Idempotency-Key y el reintento es seguro.
            throw new FePyHttpException("FE-PY $method $path — error de red: $err", 599);
        }

        $json = json_decode((string) $resp, true);
        $json = is_array($json) ? $json : [];

        if ($code < 200 || $code >= 300) {
            $safeResp = self::scrub((string) $resp, $secrets);
            error_log("[FePy] $method $path failed HTTP $code: $safeResp");
            throw new FePyHttpException("El motor de facturación rechazó la operación: " . self::readableError($json, $code, $secrets), $code, $json);
        }

        return $json;
    }

    /**
     * Mensaje LEGIBLE de un error de FE-PY, apto para `last_error` /
     * `einvoice_document.error_message` (los dos se muestran en el panel).
     *
     * Su envoltorio es uniforme: `{error:{code,message,details}}`. Lo que
     * importa acá es `details`, porque en un 422 lleva la lista de reglas
     * incumplidas y esa lista ES el diagnóstico — sin ella el operador ve
     * "validation_error" y nadie sabe qué campo falló. Vienen en DOS shapes
     * distintos bajo el mismo `code`: array de strings cuando las produjo el
     * motor xmlgen (reglas de negocio SIFEN), y array de objetos
     * `{path,message}` cuando las produjo el Zod de la request. Se manejan
     * los dos; cualquier otra cosa se ignora en vez de imprimir un `Array`.
     *
     * @param array<string,mixed> $json
     * @param array<int,string> $secrets
     */
    private static function readableError(array $json, int $code, array $secrets): string
    {
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $message = trim((string) ($error['message'] ?? ''));

        $parts = [];
        $details = $error['details'] ?? null;
        if (is_array($details)) {
            foreach ($details as $detail) {
                if (is_string($detail) && trim($detail) !== '') {
                    $parts[] = trim($detail);
                } elseif (is_array($detail)) {
                    $path = $detail['path'] ?? null;
                    $path = is_array($path) ? implode('.', array_map('strval', $path)) : (string) ($path ?? '');
                    $dMsg = trim((string) ($detail['message'] ?? ''));
                    if ($dMsg !== '') {
                        $parts[] = $path !== '' ? "$path: $dMsg" : $dMsg;
                    }
                }
                if (count($parts) >= 5) {
                    break; // el panel muestra un mensaje, no un log.
                }
            }
        }

        $out = $message !== '' ? $message : "HTTP $code sin mensaje reconocible";
        if ($parts !== []) {
            $out .= ' — ' . implode(' · ', $parts);
        }

        return mb_substr(self::scrub($out, $secrets), 0, 300);
    }

    /**
     * Tacha secretos de un texto que va a un log o a un mensaje de error. Se
     * ignoran los strings muy cortos: tachar 2 caracteres reemplazaría
     * fragmentos al azar y volvería el error ilegible sin proteger nada.
     *
     * @param array<int,string> $secrets
     */
    private static function scrub(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4 && str_contains($text, $secret)) {
                $text = str_replace($secret, '<redactado>', $text);
            }
        }
        return $text;
    }

    /**
     * GET de binario con backoff lineal (1 s, 2 s, 3 s) SOLO ante 5xx/red.
     * Un 4xx se tira de una: el CDC no existe o el recurso no se generó, y
     * reintentar no lo va a cambiar.
     */
    private function getBinaryWithRetry(string $path, string $bearer, string $what): string
    {
        $maxAttempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->execBinary($path, $bearer, $what);
            } catch (FePyHttpException $e) {
                if ($e->statusCode < 500) {
                    throw $e;
                }
                $lastError = $e;
                if ($attempt < $maxAttempts) {
                    sleep($attempt);
                }
            }
        }

        throw $lastError ?? new \RuntimeException("FE-PY GET $path falló sin excepción capturada (no debería pasar).");
    }

    private function execBinary(string $path, string $bearer, string $what): string
    {
        $ch = curl_init($this->baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $bearer],
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
        ]);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new FePyHttpException("FE-PY GET $path — error de red: $err", 599);
        }

        if ($code < 200 || $code >= 300) {
            $snippet = mb_substr((string) $resp, 0, 300);
            error_log("[FePy] GET $path failed HTTP $code: $snippet");
            if ($code === 404) {
                // Ver el docblock de kude(): este 404 es ambiguo y el mensaje
                // crudo del motor no le sirve a nadie del lado del comercio.
                throw new FePyHttpException(
                    "El $what todavía no está disponible para este documento. " .
                    'Si el problema persiste, puede ser que el motor de facturación no lo tenga habilitado.',
                    404
                );
            }
            throw new FePyHttpException("No se pudo obtener el $what del documento (HTTP $code).", $code);
        }

        return (string) $resp;
    }
}

/**
 * Excepción con status HTTP explícito. Extiende `RuntimeException` para que
 * todos los `catch (\RuntimeException)` del módulo —que son los que marcan el
 * documento en `error` con mensaje legible— la sigan atrapando; el status
 * code está para las decisiones que sí lo necesitan (reintentar 5xx, caer al
 * GET ante un 4xx en la reconsulta).
 */
final class FePyHttpException extends \RuntimeException
{
    /**
     * @param array<string,mixed> $body Cuerpo JSON decodificado de la respuesta
     *        de error, tal cual vino. Se conserva porque un error NO prueba que
     *        el documento no se haya creado (ver punto (b) del docblock de la
     *        clase): si la respuesta trae un `txnId`, ése es el hilo con el que
     *        después se lo recupera. Vacío cuando no había JSON parseable.
     */
    public function __construct(string $message, public readonly int $statusCode, public readonly array $body = [])
    {
        parent::__construct($message);
    }
}
