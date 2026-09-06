<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Cliente HTTP de Factomate — motor real de facturación electrónica para
 * Paraguay/SIFEN (el proveedor correcto, no Automate — ver pivot en
 * context/28-facturacion-electronica-plan.md). Mismo estilo que
 * api/lib/Billing/Payments/DlocalGoProvider.php (cURL directo, sin librería
 * HTTP externa, timeouts explícitos).
 *
 * Reglas de la guía real de integración (fuente:
 * /Users/xstian/Dropbox/Automate/Agent/context/09-integracion-factomate.md,
 * escrita desde una implementación en producción) que NO se pueden violar
 * — cada una costó horas de debugging ajeno:
 *
 *   a) El header `phonenumber` va en TODAS las llamadas autenticadas. Sin
 *      él, `/api/sincro/config` revienta con "The given header was not
 *      found". Este cliente lo agrega también en `token()` (el primer
 *      paso, antes de tener bearer) porque la guía dice "todas" sin
 *      excepción explícita y no hay downside en mandarlo de más — pero OJO:
 *      esto es una EXTRAPOLACIÓN nuestra, la guía solo confirma la falla
 *      concreta en sincro/config. Sin verificar contra la API real.
 *
 *   b) NUNCA Content-Type en un GET, ni en un POST sin body. Varios
 *      endpoints — especialmente los que devuelven binario — responden
 *      "500 An error has occurred" si lo reciben: ASP.NET Web API intenta
 *      deserializar un body inexistente y explota.
 *
 * Auth en dos pasos, ninguno de los dos es el bearer final:
 *   POST /Token                    usuario+contraseña        → bearer 15 min, 1 uso
 *   POST /api/account/PhoneLogin   bearer de /Token + phonenumber → bearer 24 h (éste se cachea)
 *
 * El spec de Factomate no tipa las respuestas más allá de lo que documenta
 * la guía, así que el parseo es defensivo (variantes de casing/nesting) y
 * se devuelve el payload crudo (`raw`) para que el caller pueda ajustar sin
 * tener que volver a pegarle a la API para adivinar.
 *
 * Logging (guía §7 — es la única forma práctica de destrabar un rechazo de
 * Factomate, cuyos mensajes de error son engañosos): el payload saliente y
 * el body de error completo van a error_log. La contraseña SIEMPRE se
 * redacta antes de loguear el payload de /Token; el bearer nunca se loguea
 * en ningún punto (no forma parte del body, solo del header Authorization,
 * que este cliente no serializa a los logs).
 */
final class FactomateProvider implements EInvoiceProvider
{
    private const CONNECT_TIMEOUT = 5;
    private const TOTAL_TIMEOUT   = 20;

    /**
     * Host de test hardcodeado como último fallback (el de la guía) si ni
     * siquiera la constante de simple.config.php está definida — no debería
     * pasar en un boot normal, pero evita un warning de PHP en vez de un
     * error claro si alguna vez falta el include.
     */
    private const FALLBACK_TEST_URL = 'https://facturadordev.automate.com.py';

    private function baseUrl(string $environment): string
    {
        $constant = $environment === 'prod' ? 'FACTOMATE_BASE_URL_PROD' : 'FACTOMATE_BASE_URL_TEST';
        $url = defined($constant) ? (string) constant($constant) : '';

        if ($url === '') {
            if ($environment === 'prod') {
                // Nunca fallback silencioso de prod a test (ni viceversa):
                // mandar facturas de prueba a producción, o al revés, es
                // exactamente el tipo de bug que no se detecta hasta que ya
                // es tarde.
                throw new \RuntimeException(
                    'FACTOMATE_BASE_URL_PROD no está configurada — no se puede operar en entorno de producción.'
                );
            }
            $url = self::FALLBACK_TEST_URL;
        }

        return rtrim($url, '/');
    }

    // ── F0 ───────────────────────────────────────────────────────────────

    public function token(string $environment, string $phone, string $username, string $password): array
    {
        // VERIFICADO contra la API real de DEV (2026-09-04, R0 de remisión
        // electrónica): el grant de password OAuth2 con
        // `application/x-www-form-urlencoded` y campos
        // `grant_type=password&username=...&password=...` devuelve
        // `access_token` correctamente. La hipótesis original (patrón
        // ASP.NET Identity/OWIN para un endpoint llamado "/Token") era
        // correcta y este comentario deja de ser una suposición flageada.
        $raw = $this->requestForm('/Token', [
            'grant_type' => 'password',
            'username'   => $username,
            'password'   => $password,
        ], $phone, $environment);

        $token = $this->extractToken($raw);
        if ($token === null) {
            $keys = implode(', ', array_keys($raw));
            throw new \RuntimeException("Factomate /Token no devolvió un token reconocible (claves recibidas: $keys)");
        }

        return ['token' => $token, 'expiresAt' => $this->extractExpiry($raw), 'raw' => $raw];
    }

    public function phoneLogin(string $environment, string $phone, string $tokenStep1): array
    {
        // Toda la información de este paso va en headers (Authorization con el
        // bearer de /Token + phonenumber), pero el body vacío NO puede omitirse:
        // sin Content-Length, IIS responde 411 Length Required antes de que la
        // request llegue a la aplicación (verificado contra la API real el
        // 2026-07-30). Se manda `{}` explícito, que sí funciona.
        $raw = $this->request('POST', '/api/account/PhoneLogin', [], $tokenStep1, $phone, $environment);

        $token = $this->extractToken($raw);
        if ($token === null) {
            $keys = implode(', ', array_keys($raw));
            throw new \RuntimeException("Factomate PhoneLogin no devolvió un token reconocible (claves recibidas: $keys)");
        }

        return ['token' => $token, 'expiresAt' => $this->extractExpiry($raw), 'raw' => $raw];
    }

    public function userInfo(string $environment, string $phone, string $bearer): array
    {
        return $this->request('GET', '/api/account/GetUserInfo', null, $bearer, $phone, $environment);
    }

    public function sincroConfig(string $environment, string $phone, string $bearer): array
    {
        // POST con body vacío `{}`: la guía no documenta campos de entrada
        // para este endpoint (es un "traeme mi config vigente", no un
        // formulario), pero mandarlo como POST-sin-body se arriesga al
        // mismo 500 de deserialización que un GET con Content-Type — un
        // body `{}` explícito es la forma más segura de cumplir "es un
        // POST" sin violar la regla (b).
        return $this->request('POST', '/api/sincro/config', [], $bearer, $phone, $environment);
    }

    /**
     * Timbrados del emisor. ESTA es la fuente real, no `sincro/config`:
     * verificado contra la API (2026-07-30), sincro/config devuelve
     * `stamps: []` aun con timbrado vigente cargado, mientras que este
     * endpoint devuelve el `Items[]` completo — `Id` (el que va en
     * `branch.branchDocumentTypes[0].id` al emitir), `Stablishment`,
     * `ExpeditionPoint`, `StampNumber`, `CurrentNumber` y `Serie`.
     */
    public function stamps(string $environment, string $phone, string $bearer): array
    {
        return $this->request('GET', '/api/BranchDocumentType/Get', null, $bearer, $phone, $environment);
    }

    public function paymentMethods(string $environment, string $phone, string $bearer): array
    {
        // Ojo al consumir: el código que espera SIFEN en `paymentMethodCode` es
        // `Identifier`, no `Id` (hoy coinciden en el emisor de prueba, pero son
        // campos distintos). 1=Efectivo, 2=Cheque, 3=Tarjeta de crédito, …
        return $this->request('GET', '/api/PaymentMethod/get', null, $bearer, $phone, $environment);
    }

    // ── F1/F2/F3 — sin implementar en F0 ────────────────────────────────

    /**
     * POST /api/electronicDocument/Bulk. El body va ENVUELTO en
     * `{"ElectronicDocuments": [$payload]}` — sin eso Factomate responde
     * `400 "La propiedad ElectronicDocuments se encuentra vacia"` (verificado
     * contra la API real, 2026-07-30). `$payload` es el documento SUELTO que
     * arma `SaleToInvoiceMapper::build()` — el wrapping es responsabilidad de
     * este método, no del mapper.
     *
     * CRÍTICO — leer antes de tocar el flujo de emisión: un `Success: true`
     * acá NO significa que SIFEN aceptó el documento. Se comprobó hoy
     * (2026-07-30) una emisión con CDC válido y `Success: true` que terminó
     * `Rechazado` por SIFEN con código 1002 (documento duplicado) — y el
     * KuDE se pudo descargar igual para ese documento rechazado. Ni el CDC
     * ni el PDF prueban validez fiscal. El único campo que sí lo dice es
     * `sifen_status`, poblado por `reconcile()` vía `getBulk()` más abajo —
     * este `issue()` solo confirma que Factomate ACEPTÓ el envío a SIFEN,
     * no que SIFEN lo aprobó.
     *
     * Devuelve también el `Id` raíz del bulk (crítico: es la llave de
     * reconciliación con getBulk(), el caller lo persiste en
     * `einvoice_document.provider_number`), `DCarQR` (link del QR de
     * ekuatia — va impreso en el KuDE) y `XmlUrl`.
     */
    public function issue(string $environment, string $phone, string $bearer, array $payload): array
    {
        // request() ya loguea el payload saliente completo a error_log (guía
        // §7) — es la única forma práctica de destrabar un rechazo, porque un
        // FormatException numérico de Factomate puede aparecer como un
        // mensaje de negocio que no tiene nada que ver (ej. "Para la
        // operacion a CREDITO debe ingresar Informaciones de Credito" por un
        // campo mal tipado). El bearer nunca se loguea: va solo en el header
        // Authorization, que request()/exec() no serializan a los logs.
        $body = ['ElectronicDocuments' => [$payload]];
        $raw = $this->request('POST', '/api/electronicDocument/Bulk', $body, $bearer, $phone, $environment);

        $items = $raw['Items'] ?? $raw['items'] ?? [];
        $first = is_array($items) && isset($items[0]) && is_array($items[0]) ? $items[0] : [];

        return [
            'cdc'            => $first['CDC'] ?? $first['cdc'] ?? null,
            // NO existe 'DocumentNumber' en la respuesta real — el número va
            // dentro del CDC. Este campo queda deprecated a null; se mantiene
            // en el array de retorno solo para no romper callers existentes.
            'documentNumber' => null,
            'success'        => (bool) ($first['Success'] ?? $first['success'] ?? false),
            // 'StatusMessage' tampoco existe — el mensaje real está en
            // StatusString (y Error). Verificado contra la API real (2026-07-30).
            'statusMessage'  => $first['StatusString'] ?? $first['statusString'] ?? $first['Error'] ?? $first['error'] ?? null,
            // Id raíz del bulk — llave de reconciliación, ver getBulk().
            'bulkId'         => $raw['Id'] ?? $raw['id'] ?? null,
            'dCarQR'         => $first['DCarQR'] ?? $first['dCarQR'] ?? null,
            'xmlUrl'         => $first['XmlUrl'] ?? $first['xmlUrl'] ?? null,
            'raw'            => $raw,
        ];
    }

    public function cancel(string $environment, string $phone, string $bearer, string $cdc, string $reason): array
    {
        // signDate: hora LOCAL de Asunción, formato naive (sin zona) — guía
        // §6. Mandarlo en UTC lo hace leer 3-4 h en el futuro para el parser
        // de SIFEN y arriesga rechazo. Punto ya guarda timestamps naive en
        // la TZ del tenant en toda la BD (transaction.transactionDate, etc,
        // ver context/04-modelo-de-dominio.md) — se reusa ese mismo criterio
        // acá en vez de inventar una conversión de zona horaria propia:
        // `date_default_timezone_set` del proceso PHP YA está en la TZ de
        // Asunción (bootstrap del proyecto), así que `date('Y-m-d\TH:i:s')`
        // sin sufijo de zona es exactamente el naive-local que pide la guía.
        $signDate = date('Y-m-d\TH:i:s');

        // SIN VERIFICAR contra la API real: la guía documenta los 4 campos
        // (typeCode/documentId/motivo/signDate) pero no el nombre exacto de
        // la clave del motivo ni si "documentId" es literal. Se usa
        // `documentId` porque así lo nombra la guía §6, y `reason` como
        // nombre de campo más probable dado el resto del payload en inglés
        // (ej. `ammount`, `taxRate`) — si Factomate espera otra clave
        // (`comment`/`motivo`/`description`), este es el primer sospechoso.
        // VERIFICADO contra la API real (2026-07-30) y contra la implementación
        // de referencia (efatech.client.ts:154): el body va envuelto en
        // `eventDetails: []`, igual que la emisión va envuelta en
        // `ElectronicDocuments: []`. Suelto no llega a procesarse.
        $payload = [
            'eventDetails' => [[
                'typeCode'   => 1,
                'documentId' => $cdc,
                'reason'     => $reason,
                'signDate'   => $signDate,
            ]],
        ];

        $raw = $this->request('POST', '/api/electronicDocument/event', $payload, $bearer, $phone, $environment);

        // Parseo defensivo del resultado (mismo criterio que issue()): no hay
        // spec tipado de la respuesta de /event, así que se buscan las
        // claves más probables y se devuelve `raw` siempre para que el
        // caller pueda inspeccionar lo que realmente vino.
        $success = $raw['success'] ?? $raw['Success'] ?? null;
        return [
            'success' => $success === null ? true : (bool) $success, // 2xx sin campo explícito = éxito
            'message' => $raw['message'] ?? $raw['Message'] ?? $raw['StatusMessage'] ?? null,
            'raw'     => $raw,
        ];
    }

    public function kude(string $environment, string $phone, string $bearer, string $cdc): string
    {
        // El KuDE llega tarde: Factomate tarda 3-8 s entre aceptar el /Bulk
        // y terminar de generar el XML firmado + el PDF. Llamar de
        // inmediato devuelve 500. Reintento 3 veces con backoff LINEAL
        // (1 s, 2 s, 3 s) y SOLO ante 5xx — un 4xx (CDC mal formado) no se
        // reintenta, se tira de una (guía §"El KuDE llega tarde" / F2 brief).
        $maxAttempts = 3;
        $lastError   = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->execBinary('/api/electronicDocument/getkude/' . rawurlencode($cdc), $bearer, $phone, $environment);
            } catch (FactomateHttpException $e) {
                if ($e->statusCode < 500) {
                    // 4xx — no reintentar, el CDC está mal formado o no existe.
                    throw $e;
                }
                $lastError = $e;
                if ($attempt < $maxAttempts) {
                    sleep($attempt); // backoff lineal: 1s, 2s, 3s
                }
            }
        }

        throw $lastError ?? new \RuntimeException('Factomate getkude falló sin excepción capturada (no debería pasar).');
    }

    /**
     * GET /api/Client/getbyruc/{ruc} — datos del contribuyente en el padrón que
     * ve el emisor. Se manda el RUC TAL CUAL lo escribió el operador (con o sin
     * dígito verificador): normalizarlo acá sería adivinar, y el DV lo devuelve
     * la propia respuesta.
     *
     * SIN VERIFICAR contra la API real (2026-07-30): ni la ruta ni el shape de
     * la respuesta se probaron todavía — la guía documenta el endpoint pero no
     * el payload. El parseo defensivo vive en
     * `Contacts\TaxpayerLookupService::fromFactomate()`, que trata un shape
     * inesperado como "no encontrado" y cae al padrón público en vez de romper
     * el alta del cliente.
     */
    public function clientByRuc(string $environment, string $phone, string $bearer, string $ruc): array
    {
        return $this->request('GET', '/api/Client/getbyruc/' . rawurlencode($ruc), null, $bearer, $phone, $environment);
    }

    /**
     * GET /api/electronicDocument/getBulk/{id} — fuente REAL de
     * reconciliación fiscal. Verificado contra la API real (2026-07-30):
     * `GET /api/ElectronicDocument/GetAll` devuelve `Items: []` aun después
     * de emitir con éxito — ese endpoint es un no-op silencioso para este
     * propósito, no se usa más. `$bulkId` es el `Id` raíz que devolvió
     * `/Bulk` al emitir (ver issue()), cacheado en
     * `einvoice_document.provider_number`.
     *
     * Parseo del resultado (verificado con un rechazo real, 2026-07-30):
     *   Items[0].SifenResult.rRetEnviDe.rProtDeField.dEstResField      → "Rechazado"/"Aprobado"
     *   Items[0].SifenResult.rRetEnviDe.rProtDeField.gResProcField[]   → { dCodResField, dMsgResField }
     *   Items[0].StatusString                                          → "FinalizadoERROR"/"Exitoso"
     *   Items[0].Success                                               → false cuando SIFEN rechazó
     * Ese parseo vive en EInvoiceService::reconcile(), no acá — este método
     * solo devuelve el payload crudo.
     */
    public function getBulk(string $environment, string $phone, string $bearer, string $bulkId): array
    {
        return $this->request('GET', '/api/electronicDocument/getBulk/' . rawurlencode($bulkId), null, $bearer, $phone, $environment);
    }

    // ── F7 — provisioning white-label ────────────────────────────────────

    /**
     * Alta compuesta del emisor (manual ABM §2). `$adminLogin` viaja como
     * header `phonenumber` — la regla de Factomate es "en TODAS las llamadas
     * autenticadas" y el header es la identidad de login, no un teléfono
     * literal (verificado 2026-07-30: vuelve como `userName`).
     * SIN VERIFICAR contra la API real: si CreateExternal rechaza el header
     * de un usuario sin tenant, probarlo sin `phonenumber` es el primer
     * intento de fix.
     */
    public function createExternal(string $environment, string $adminLogin, string $adminBearer, array $data): array
    {
        $raw = $this->request('POST', '/api/Tenant/CreateExternal', [
            'RazonSocial'    => (string) ($data['razonSocial'] ?? ''),
            'NombreFantasia' => (string) ($data['nombreFantasia'] ?? ''),
            'Email'          => (string) ($data['email'] ?? ''),
            'Ruc'            => (string) ($data['ruc'] ?? ''),
        ], $adminBearer, $adminLogin, $environment);

        if (empty($raw['Success'] ?? $raw['success'] ?? false)) {
            $error = (string) ($raw['Error'] ?? $raw['error'] ?? 'Factomate rechazó el alta del emisor sin motivo.');
            throw new \RuntimeException($error);
        }

        $tenantId = $raw['Id'] ?? $raw['id'] ?? null;
        $password = $raw['Password'] ?? $raw['password'] ?? null;
        if ($tenantId === null || $password === null || $password === '') {
            // Sin tenantId o sin contraseña el alta es inutilizable — y la
            // contraseña NO se puede volver a pedir (manual §2.4). Error
            // ruidoso SIN incluir la contraseña en el mensaje.
            $keys = implode(', ', array_keys($raw));
            throw new \RuntimeException(
                "CreateExternal respondió sin tenantId o sin contraseña (claves: $keys) — revisar con Factomate antes de reintentar."
            );
        }

        return [
            'tenantId' => (int) $tenantId,
            'userId'   => (string) ($raw['UserId'] ?? $raw['userId'] ?? ''),
            'email'    => (string) ($raw['Email'] ?? $raw['email'] ?? ($data['email'] ?? '')),
            'password' => (string) $password,
            'raw'      => $raw,
        ];
    }

    /**
     * `CSCProduccion` es un SECRETO de SIFEN y viaja en este body, así que la
     * request no puede pasar por `request()`, que loguea el body entero. Hasta
     * 2026-09-06 el campo nunca se llenaba (la UI no tenía input de CSC), o
     * sea que el leak estaba latente: en cuanto alguien cargara el CSC, el
     * código con el que se firma el QR del KuDE quedaba en texto plano en el
     * log del backend.
     *
     * Se loguea la misma línea, con el secreto redactado — mismo criterio que
     * `uploadCert()` y que el redactado de `postForm()`.
     */
    public function updateTenant(string $environment, string $phone, string $bearer, array $tenant): array
    {
        $redacted = $tenant;
        if (isset($redacted['CSCProduccion'])) {
            $redacted['CSCProduccion'] = '<redactado>';
        }
        error_log('[Factomate] PUT /api/Tenant body=' . json_encode($redacted, JSON_UNESCAPED_UNICODE));

        // El CSC se declara secreto también para la RESPUESTA: si Factomate lo
        // eco-ea al rechazarlo, se tacha antes del log y antes de `last_error`
        // (que es visible en el panel). Ver exec().
        return $this->requestUnlogged(
            'PUT',
            '/api/Tenant',
            $tenant,
            $bearer,
            $phone,
            $environment,
            [(string) ($tenant['CSCProduccion'] ?? '')]
        );
    }

    public function createActivity(string $environment, string $phone, string $bearer, int $tenantId, int $identifier, string $name): array
    {
        return $this->request('POST', '/api/Activity', [
            'tenantId'   => $tenantId,
            'Identifier' => $identifier,
            'Name'       => $name,
        ], $bearer, $phone, $environment);
    }

    /**
     * Alta del timbrado (manual §5). `DocumentTypeIds` crea un timbrado por
     * tipo de documento — se dan de alta FC (1) y NC (5) juntos, que son los
     * dos tipos que Punto emite (F1 + F3). `CurrentNumber: 1` — el
     * correlativo lo lleva Factomate desde ahí (decisión `number: -1`).
     */
    public function createStamp(string $environment, string $phone, string $bearer, array $stamp): array
    {
        return $this->request('POST', '/api/BranchDocumentType', [
            'DocumentTypeIds' => $stamp['documentTypeIds'] ?? [1, 5],
            'Stablishment'    => (string) ($stamp['stablishment'] ?? '001'),
            'ExpeditionPoint' => (string) ($stamp['expeditionPoint'] ?? '001'),
            'StampNumber'     => (string) ($stamp['stampNumber'] ?? ''),
            'StampDate'       => (string) ($stamp['stampDate'] ?? ''),
            'CurrentNumber'   => (int) ($stamp['currentNumber'] ?? 1),
            'Serie'           => (string) ($stamp['serie'] ?? ''),
        ], $bearer, $phone, $environment);
    }

    /**
     * Certificado de firma (manual §7). El `.pfx` y su contraseña PASAN por
     * acá y no se persisten en ningún lado nuestro — y bajo ninguna
     * circunstancia se loguean: request() loguea el body de los POST, así
     * que este método NO usa request() para el envío, duplica el camino con
     * el log redactado.
     */
    public function uploadCert(string $environment, string $phone, string $bearer, int $tenantId, string $certBase64, string $certPassword): array
    {
        $path = '/api/Tenant/' . $tenantId . '/UploadCert';
        error_log("[Factomate] POST $path body=<certificado redactado>");
        return $this->requestUnlogged(
            'POST',
            $path,
            ['certBase64' => $certBase64, 'certPassword' => $certPassword],
            $bearer,
            $phone,
            $environment,
            // Mismo criterio que updateTenant(): un rechazo que eco-ee la
            // contraseña del certificado no puede terminar en el log.
            [$certPassword]
        );
    }

    public function testSet(string $environment, string $phone, string $bearer, int $tenantId, string $ruc): array
    {
        // El RUC va SIN dígito verificador (manual §7.5.1: split('-')[0]).
        $doc = explode('-', trim($ruc))[0];
        $qs  = http_build_query(['tenantId' => $tenantId, 'description' => $doc]);
        return $this->request('GET', '/api/Consulta/Get?' . $qs, null, $bearer, $phone, $environment);
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Busca el token en las claves más probables de la respuesta. Cubre
     * tanto el shape típico de un endpoint OAuth clásico de ASP.NET
     * (`access_token`) como el de un endpoint custom más simple
     * (`token`/`accessToken`/`jwt`), con y sin envoltorio `data`.
     */
    private function extractToken(array $raw): ?string
    {
        foreach (['access_token', 'token', 'accessToken', 'jwt'] as $key) {
            if (!empty($raw[$key]) && is_string($raw[$key])) {
                return $raw[$key];
            }
        }
        $data = $raw['data'] ?? null;
        if (is_array($data)) {
            foreach (['access_token', 'token', 'accessToken', 'jwt'] as $key) {
                if (!empty($data[$key]) && is_string($data[$key])) {
                    return $data[$key];
                }
            }
        }
        return null;
    }

    private function extractExpiry(array $raw): ?string
    {
        foreach (['expiresAt', 'expires_at', 'expiration'] as $key) {
            if (!empty($raw[$key]) && is_string($raw[$key])) {
                return $raw[$key];
            }
        }
        // El endpoint OAuth clásico de ASP.NET Identity (que asumimos para
        // /Token, ver token()) devuelve `expires_in` en SEGUNDOS relativos,
        // no un timestamp absoluto — se convierte acá a ISO 8601 absoluto
        // para que FactomateSession no tenga que distinguir los dos shapes.
        if (isset($raw['expires_in']) && is_numeric($raw['expires_in'])) {
            return date('c', time() + (int) $raw['expires_in']);
        }
        // Sin campo de expiración reconocible: FactomateSession asume el
        // default de 24 h documentado en la guía cuando esto es null.
        return null;
    }

    /**
     * POST /Token — único endpoint que usa form-urlencoded en vez de JSON
     * (ver comentario de suposición en token()).
     */
    private function requestForm(string $path, array $formFields, string $phone, string $environment): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'phonenumber: ' . $phone,
        ];

        // Payload saliente a error_log (guía §7) — password SIEMPRE redactado.
        $redacted = $formFields;
        if (isset($redacted['password'])) {
            $redacted['password'] = '***';
        }
        error_log('[Factomate] POST ' . $path . ' body=' . json_encode($redacted, JSON_UNESCAPED_UNICODE));

        return $this->exec($path, 'POST', $headers, http_build_query($formFields), $environment);
    }

    /**
     * Request JSON genérico. `$jsonBody === null` → sin Content-Type y sin
     * body (GET, o POST sin campos como phoneLogin). `$jsonBody !== null`
     * → Content-Type + body JSON.
     *
     * El array vacío se serializa a `{}` a mano y NO con JSON_FORCE_OBJECT:
     * ese flag convierte TODA lista en objeto con claves numéricas, lo que
     * destruiría los payloads de F1 (`items[]`, `payments[]` de /Bulk) de
     * una forma que Factomate reportaría como un error de negocio sin
     * relación con la causa real (guía §7).
     */
    private function request(string $method, string $path, ?array $jsonBody, ?string $bearer, ?string $phone, string $environment): array
    {
        $headers = ['Accept: application/json'];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        if ($phone !== null) {
            $headers[] = 'phonenumber: ' . $phone;
        }

        $body = null;
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = $jsonBody === [] ? '{}' : json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
            error_log("[Factomate] $method $path body=$body");
        }

        return $this->exec($path, $method, $headers, $body, $environment);
    }

    /**
     * Igual que request() pero SIN loguear el body: para payloads que llevan
     * secretos que no pueden tocar un log ni redactados a medias (el
     * certificado de firma y su contraseña — ver uploadCert()). El caller es
     * responsable de loguear una línea redactada si quiere traza.
     */
    private function requestUnlogged(string $method, string $path, array $jsonBody, string $bearer, string $phone, string $environment, array $secrets = []): array
    {
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $bearer,
            'phonenumber: ' . $phone,
            'Content-Type: application/json',
        ];
        $body = $jsonBody === [] ? '{}' : json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        return $this->exec($path, $method, $headers, $body, $environment, $secrets);
    }

    /**
     * @param array<int,string> $secrets Valores literales que NO pueden aparecer
     *        en un log ni en un mensaje de error, aunque los devuelva el
     *        proveedor. Ver el comentario del camino de error.
     */
    private function exec(string $path, string $method, array $headers, ?string $body, string $environment, array $secrets = []): array
    {
        $ch = curl_init($this->baseUrl($environment) . $path);

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
            throw new \RuntimeException("Factomate $method $path — error de red: $err");
        }

        $json = json_decode((string) $resp, true);
        // Doble-encode: algunos endpoints (PhoneLogin, verificado contra la API
        // real el 2026-07-30) devuelven un STRING JSON que a su vez contiene el
        // JSON útil — `"{\"access_token\":...}"`. Un solo decode deja un string,
        // no un array, y el parseo posterior encontraba [] y fallaba con
        // "no devolvió un token reconocible". Se desenvuelve una vez más.
        if (is_string($json)) {
            $json = json_decode($json, true);
        }
        $json = is_array($json) ? $json : [];

        if ($code < 200 || $code >= 300) {
            // Body de error COMPLETO a error_log (guía §7): es la única
            // forma práctica de destrabar un rechazo, porque los mensajes
            // de error de Factomate son engañosos (un FormatException
            // numérico aparece como un mensaje de negocio que no tiene
            // nada que ver).
            //
            // Antes esto se logueaba crudo, con el argumento de que la
            // RESPUESTA de Factomate nunca contiene lo que mandamos. Es un
            // supuesto sobre el formato de error de un tercero, y desde que
            // `updateTenant()` manda el `CSCProduccion` por acá el costo de
            // que sea falso es un secreto de SIFEN en texto plano en el log:
            // una API REST que rechaza un campo y lo eco-ea en el mensaje
            // ("CSCProduccion inválido: ...") es de lo más común. Los valores
            // que el caller declaró como secretos se tachan antes de que el
            // body toque un log o un mensaje.
            $safeResp = self::scrub((string) $resp, $secrets);
            error_log("[Factomate] $method $path failed HTTP $code: $safeResp");

            // El mensaje que SÍ persiste en einvoice_account.last_error (y
            // es visible en el panel) se sanea doble: whitelist de claves
            // conocidas, truncado a 300, y el mismo tachado — este camino
            // llega al FRONTEND, así que es el más caro de los dos.
            $msg = '';
            foreach (['message', 'error', 'detail'] as $key) {
                if (is_string($json[$key] ?? null) && $json[$key] !== '') {
                    $msg = mb_substr(self::scrub($json[$key], $secrets), 0, 300);
                    break;
                }
            }
            if ($msg === '') {
                $msg = "HTTP $code sin mensaje reconocible";
            }
            throw new \RuntimeException("Factomate $method $path falló (HTTP $code): $msg");
        }

        return $json;
    }

    /**
     * Tacha valores secretos de un texto que va a un log o a un mensaje de
     * error. Se ignoran los strings muy cortos: tachar una cadena de 2
     * caracteres reemplazaría fragmentos al azar de un mensaje legítimo y
     * volvería el error ilegible sin proteger nada real.
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
     * GET binario — usado por kude(). Replica exactamente la regla (b) del
     * cliente JSON (NUNCA Content-Type en un GET) porque este es justo el
     * endpoint que la guía señala como el que revienta con 500 si se la
     * viola (ASP.NET Web API intenta deserializar un body inexistente).
     *
     * Tira FactomateHttpException (con el status code) en vez de
     * RuntimeException genérica — kude() necesita distinguir 4xx (no
     * reintentar) de 5xx (reintentar con backoff).
     */
    private function execBinary(string $path, string $bearer, string $phone, string $environment): string
    {
        $headers = [
            'Authorization: Bearer ' . $bearer,
            'phonenumber: ' . $phone,
            // Sin Accept: application/json — esto es un GET de binario, no
            // tiene sentido pedir JSON, y menos mandar Content-Type (regla b).
        ];

        $ch = curl_init($this->baseUrl($environment) . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
        ]);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            // Error de red — se trata como reintentable (mismo criterio que
            // un 5xx): un timeout/DNS temporal no debería tirar la toalla
            // de una sola vez en un endpoint que sabemos que es lento.
            throw new FactomateHttpException("Factomate GET $path — error de red: $err", 599);
        }

        if ($code < 200 || $code >= 300) {
            // No hay body JSON confiable acá (puede ser el "500 An error
            // has occurred" en texto plano de ASP.NET) — se loguea crudo,
            // truncado, sin intentar json_decode.
            $snippet = mb_substr((string) $resp, 0, 300);
            error_log("[Factomate] GET $path failed HTTP $code: $snippet");
            throw new FactomateHttpException("Factomate GET $path falló (HTTP $code)", $code);
        }

        return (string) $resp;
    }
}

/**
 * Excepción con status code explícito — kude() la usa para decidir si
 * reintenta (5xx) o no (4xx). No extiende RuntimeException con un código
 * genérico porque el caller necesita el status HTTP real, no solo "falló".
 */
final class FactomateHttpException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message);
    }
}
