<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Orquestación de F0: conectar/probar la cuenta de Factomate de un
 * comercio y leer el timbrado vigente. F1 agrega enqueue/drain/cancel/retry
 * sobre `einvoice_document` — no viven acá todavía (ver
 * context/28-facturacion-electronica-plan.md).
 *
 * Nota sobre `einvoice_account.config`: es una columna JSONB real (no el
 * patrón legacy de `data`/`meta`/`config` que Query::flattenJsonb() aplana
 * automáticamente en TODA fila leída por ncmExecute — ver
 * api/lib/App/Database/Query.php:52). Si se selecciona la columna con su
 * nombre literal `config`, flattenJsonb la de-estructura y la borra del
 * resultado. Por eso el SELECT la alias-ea (`config AS account_config`) —
 * evita pisar el helper compartido (1035+ callers) por una colisión de
 * nombre de una tabla nueva. `stamp`/`emitter` no colisionan con esos
 * nombres mágicos, así que no necesitan alias.
 */
final class EInvoiceService
{
    private EInvoiceProvider $provider;
    private FactomateSession $session;

    public function __construct(?EInvoiceProvider $provider = null)
    {
        $this->provider = $provider ?? new FactomateProvider();
        $this->session  = new FactomateSession($this->provider);
    }

    /** Shape estable aunque no haya cuenta configurada — el frontend no rama por null. */
    public function getAccount(string $companyId): array
    {
        $row = ncmExecute(
            'SELECT provider, username, phone_enc, environment, status, emitter, stamp, stamp_synced_at,
                    last_check_at, last_error, config AS account_config
               FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );

        if (!$row) {
            return [
                'configured'    => false,
                'provider'      => 'factomate',
                'username'      => '',
                'phone'         => '',
                'environment'   => 'test',
                'status'        => 'unconfigured',
                'emitter'       => [],
                'stamp'         => [],
                'stampSyncedAt' => null,
                'lastCheckAt'   => null,
                'lastError'     => null,
                'config'        => [],
            ];
        }

        return [
            'configured'  => true,
            'provider'    => (string) ($row['provider'] ?? 'factomate'),
            'username'    => (string) ($row['username'] ?? ''),
            // Se devuelve DESCIFRADO (a diferencia de la contraseña, que
            // nunca vuelve): el teléfono no es secreto en sí mismo — es un
            // factor de auth (header phonenumber) igual que el usuario, no
            // una clave que desbloquea la cuenta por sí sola. Cifrarlo en
            // BD protege un dump/backup; el operador autenticado de su
            // propia cuenta ya lo conoce, y necesita verlo para poder
            // editar el resto sin tener que re-tipearlo cada vez.
            'phone'       => $this->tryDecryptPhone($row['phone_enc'] ?? null) ?? '',
            'environment' => (string) ($row['environment'] ?? 'test'),
            'status'      => (string) ($row['status'] ?? 'unconfigured'),
            'emitter'     => $this->decodeJsonb($row['emitter'] ?? null),
            // Timbrado vigente cacheado de sincro/config — se LEE, no se
            // crea (ver FactomateProvider::sincroConfig).
            'stamp'         => $this->decodeJsonb($row['stamp'] ?? null),
            'stampSyncedAt' => $row['stamp_synced_at'] ?? null,
            'lastCheckAt'   => $row['last_check_at'] ?? null,
            'lastError'     => $row['last_error'] ?? null,
            'config'        => $this->decodeJsonb($row['account_config'] ?? null),
        ];
    }

    /**
     * Crea o actualiza la cuenta. $password null/'' = conservar la guardada
     * (el frontend nunca la trae de vuelta, así que "no tocar" es el
     * default cuando el campo viene vacío) — a diferencia de username/phone/
     * environment, que SIEMPRE vienen del form porque getAccount() los
     * devuelve en claro y el frontend los pre-carga.
     *
     * `$config` se MERGEA sobre la config guardada, no la reemplaza: la
     * pantalla tiene varias secciones (conexión, emisión, medios de pago) que
     * guardan por separado, y un PUT destructivo hacía que tocar un switch de
     * emisión borrara el `paymentMethodMap` entero. Mismo criterio que
     * Finance\ConfigService::update (merge no-destructivo sobre settingObj).
     * Para borrar una clave se manda explícitamente en null.
     *
     * Cambiar cualquiera de los cuatro (usuario, contraseña, teléfono,
     * entorno) invalida el bearer cacheado: quedó emitido para una
     * combinación de credenciales o un HOST (test≠prod son hosts distintos)
     * que ya no aplica. Una credencial nueva sin probar no puede quedar
     * mostrando "Conectado".
     *
     * @throws \RuntimeException
     */
    public function saveAccount(
        string $companyId,
        string $username,
        ?string $password,
        string $phone,
        string $environment,
        array $config
    ): array {
        $username = trim($username);
        if ($username === '') {
            throw new \RuntimeException('El usuario de Factomate es obligatorio.');
        }

        if (!in_array($environment, ['test', 'prod'], true)) {
            throw new \RuntimeException('Entorno inválido (esperado: test o prod).');
        }

        // phoneValidateForStorage (api/includes/phone.php) es el helper
        // canónico del proyecto: valida con libphonenumber y normaliza a
        // storage SIN '+' (convención del repo, ver
        // feedback_phone_storage_no_plus) — se reusa en vez de inventar
        // otra normalización acá. require_once defensivo porque no todo
        // caller de EInvoiceService pasa por functions.php (que ya lo
        // incluye) — mismo patrón que ContactService/UsersService.
        require_once dirname(__DIR__, 3) . '/api/includes/phone.php';
        $phoneForStorage = phoneValidateForStorage($phone, 'PY', 'El teléfono del titular no es un número válido.');
        if ($phoneForStorage === null) {
            throw new \RuntimeException('El teléfono del titular es obligatorio.');
        }

        // `config AS account_config`: flattenJsonb aplana toda columna llamada
        // `config` y la vuelve inutilizable (ver nota en context/28 §Schema).
        $existing = ncmExecute(
            'SELECT username, phone_enc, environment, config AS account_config FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );

        $config = $this->mergeConfig(
            $existing ? $this->decodeJsonb($existing['account_config'] ?? null) : [],
            $config
        );

        $passwordChanged = $password !== null && $password !== '';
        $existingPhone    = $existing ? $this->tryDecryptPhone($existing['phone_enc'] ?? null) : null;
        $usernameChanged  = $existing && $username !== (string) ($existing['username'] ?? '');
        $phoneChanged     = $existing && $phoneForStorage !== $existingPhone;
        $environmentChanged = $existing && $environment !== (string) ($existing['environment'] ?? 'test');
        $credentialsChanged = $passwordChanged || $usernameChanged || $phoneChanged || $environmentChanged;

        $configJson = json_encode($config, JSON_UNESCAPED_UNICODE);
        $phoneEnc   = CredentialVault::encrypt($phoneForStorage);

        if (!$existing) {
            if (!$passwordChanged) {
                throw new \RuntimeException('La contraseña es obligatoria para conectar la cuenta por primera vez.');
            }
            ncmExecute(
                "INSERT INTO einvoice_account (companyid, provider, username, password_enc, phone_enc, environment, status, config)
                 VALUES (?, 'factomate', ?, ?, ?, ?, 'unconfigured', ?::jsonb)",
                [$companyId, $username, CredentialVault::encrypt($password), $phoneEnc, $environment, $configJson]
            );
        } elseif ($credentialsChanged) {
            if ($passwordChanged) {
                ncmExecute(
                    "UPDATE einvoice_account
                        SET username = ?, password_enc = ?, phone_enc = ?, environment = ?,
                            token_enc = NULL, token_expires_at = NULL,
                            status = 'unconfigured', last_error = NULL, config = ?::jsonb, updated_at = now()
                      WHERE companyid = ?",
                    [$username, CredentialVault::encrypt($password), $phoneEnc, $environment, $configJson, $companyId]
                );
            } else {
                // Usuario/teléfono/entorno cambiaron pero la contraseña no:
                // se conserva password_enc, se pisa el resto y se descarta
                // el token — vuelve a "sin probar".
                ncmExecute(
                    "UPDATE einvoice_account
                        SET username = ?, phone_enc = ?, environment = ?,
                            token_enc = NULL, token_expires_at = NULL,
                            status = 'unconfigured', last_error = NULL, config = ?::jsonb, updated_at = now()
                      WHERE companyid = ?",
                    [$username, $phoneEnc, $environment, $configJson, $companyId]
                );
            }
        } else {
            // Nada de lo que invalida el bearer cambió (ej. solo se tocó
            // autoIssue/onlyWithTaxId) — se actualiza config sin resetear status.
            ncmExecute(
                'UPDATE einvoice_account SET username = ?, config = ?::jsonb, updated_at = now() WHERE companyid = ?',
                [$username, $configJson, $companyId]
            );
        }

        return $this->getAccount($companyId);
    }

    /**
     * Cadena completa de F0: Token → PhoneLogin → GetUserInfo → sincro/config.
     * Persiste status/emitter/stamp/stamp_synced_at/last_error. Nunca deja
     * la excepción escapar sin persistir el intento: el operador tiene que
     * ver "Error de autenticación" en vez de un 500 mudo.
     *
     * Sin timbrado vigente no se puede facturar — si sincro/config no trae
     * `stamps[0]`, el resultado es 'auth_error' aunque el login haya sido
     * exitoso (no es un error de autenticación real, pero el efecto para
     * el operador es el mismo: la cuenta no está lista para emitir).
     */
    public function testConnection(string $companyId): array
    {
        $account = ncmExecute('SELECT companyid FROM einvoice_account WHERE companyid = ?', [$companyId]);
        if (!$account) {
            throw new \RuntimeException('Conectá la cuenta de Factomate antes de probar la conexión.');
        }

        try {
            $bearer = $this->session->getBearer($companyId);
            [$phone, $environment] = $this->phoneAndEnvironment($companyId);

            $emitter = $this->provider->userInfo($environment, $phone, $bearer);

            // El timbrado NO sale de sincro/config. Verificado contra la API real
            // (2026-07-30): sincro/config devuelve `{tenantId, stamps: []}` — la
            // lista viene VACÍA aun cuando el emisor tiene timbrado vigente. El
            // timbrado real vive en `GET /api/BranchDocumentType/Get`, que además
            // trae todo lo que se necesita: `Id` (el que va en
            // branch.branchDocumentTypes[0].id del payload de emisión),
            // Stablishment, ExpeditionPoint, StampNumber y CurrentNumber.
            //
            // Se consulta sincro/config igual, primero, por si en algún emisor sí
            // viene poblado — pero no se depende de él.
            $stamp = $this->extractStamp($this->provider->sincroConfig($environment, $phone, $bearer));
            if ($stamp === null) {
                $stamp = $this->extractStamp($this->provider->stamps($environment, $phone, $bearer));
            }

            if ($stamp === null) {
                $message = 'Factomate no devolvió un timbrado vigente para esta cuenta — sin timbrado no se puede '
                    . 'facturar. El timbrado se provisiona del lado de Factomate, contactalos para que lo asignen.';
                ncmExecute(
                    "UPDATE einvoice_account
                        SET status = 'auth_error', emitter = ?::jsonb, last_check_at = now(), last_error = ?, updated_at = now()
                      WHERE companyid = ?",
                    [json_encode($emitter, JSON_UNESCAPED_UNICODE), $message, $companyId]
                );
                return ['status' => 'auth_error', 'emitter' => $emitter, 'stamp' => [], 'lastError' => $message];
            }

            ncmExecute(
                "UPDATE einvoice_account
                    SET status = 'ok', emitter = ?::jsonb, stamp = ?::jsonb, stamp_synced_at = now(),
                        last_check_at = now(), last_error = NULL, updated_at = now()
                  WHERE companyid = ?",
                [json_encode($emitter, JSON_UNESCAPED_UNICODE), json_encode($stamp, JSON_UNESCAPED_UNICODE), $companyId]
            );

            return ['status' => 'ok', 'emitter' => $emitter, 'stamp' => $stamp, 'lastError' => null];
        } catch (\Throwable $e) {
            // $e->getMessage() nunca incluye la contraseña ni el bearer
            // (FactomateProvider/FactomateSession no los interpolan en
            // excepciones) — seguro de persistir.
            $message = $e->getMessage();
            ncmExecute(
                "UPDATE einvoice_account
                    SET status = 'auth_error', last_check_at = now(), last_error = ?, updated_at = now()
                  WHERE companyid = ?",
                [$message, $companyId]
            );

            return ['status' => 'auth_error', 'emitter' => [], 'stamp' => [], 'lastError' => $message];
        }
    }

    /**
     * Códigos de medio de pago de Factomate, normalizados a
     * `[{code:int, name:string}]` para que el frontend (y el mapa de F3) no
     * dependan del casing ni del envoltorio crudo de la API.
     *
     * @return array<int,array{code:int,name:string}>
     * @throws \RuntimeException si la cuenta no está conectada (status != 'ok').
     */
    public function paymentMethods(string $companyId): array
    {
        $row = ncmExecute('SELECT status FROM einvoice_account WHERE companyid = ?', [$companyId]);
        if (!$row || (string) ($row['status'] ?? '') !== 'ok') {
            throw new \RuntimeException('La cuenta de facturación electrónica no está conectada.');
        }

        $bearer = $this->session->getBearer($companyId);
        [$phone, $environment] = $this->phoneAndEnvironment($companyId);
        return $this->normalizePaymentMethods($this->provider->paymentMethods($environment, $phone, $bearer));
    }

    /**
     * `GET /api/PaymentMethod/get` no está tipado en la guía y devolvió
     * PascalCase contra la API real (2026-07-30). Se desenvuelve el contenedor
     * (`Items`/`data`) si viene, y de cada fila se toma:
     *
     *   - `code` ← **`Identifier`**, NO `Id`. Es el código que espera SIFEN;
     *     hoy coinciden en el emisor de prueba pero son campos distintos, y
     *     usar `Id` mandaría un medio de pago equivocado en cada factura.
     *   - `name` ← Description/Name/Denomination, lo primero que exista.
     *
     * Mismo criterio defensivo que extractToken()/extractStamp(): probar los
     * casings plausibles en vez de asumir uno.
     *
     * @param array<mixed> $raw
     * @return array<int,array{code:int,name:string}>
     */
    private function normalizePaymentMethods(array $raw): array
    {
        $rows = $raw;
        foreach (['Items', 'items', 'Data', 'data', 'Result', 'result'] as $wrapper) {
            if (isset($raw[$wrapper]) && is_array($raw[$wrapper])) {
                $rows = $raw[$wrapper];
                break;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = null;
            foreach (['Identifier', 'identifier'] as $key) {
                if (isset($row[$key]) && $row[$key] !== '' && is_numeric($row[$key])) {
                    $code = (int) $row[$key];
                    break;
                }
            }
            if ($code === null) {
                continue;
            }
            $name = '';
            foreach (['Description', 'description', 'Name', 'name', 'Denomination', 'denomination'] as $key) {
                if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                    $name = $row[$key];
                    break;
                }
            }
            $out[] = ['code' => $code, 'name' => $name !== '' ? $name : "Código $code"];
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string} [$phone, $environment] descifrados/listos para pasar al provider.
     * @throws \RuntimeException si falta el teléfono (cuenta a medio configurar).
     */
    private function phoneAndEnvironment(string $companyId): array
    {
        $row   = ncmExecute('SELECT phone_enc, environment FROM einvoice_account WHERE companyid = ?', [$companyId]);
        $phone = $this->tryDecryptPhone($row['phone_enc'] ?? null);
        if ($phone === null) {
            throw new \RuntimeException('Falta el teléfono del titular — es obligatorio para autenticar con Factomate.');
        }
        return [$phone, (string) ($row['environment'] ?? 'test')];
    }

    /**
     * `stamps[0]` de la respuesta de sincro/config. El shape no está
     * tipado en la guía (que a veces usa lowercase — "stamps[0]" — y en
     * otro punto documenta PascalCase para otro endpoint — "Items[0].CDC")
     * así que se prueban ambos casings y el envoltorio `data`/`Data`, igual
     * criterio defensivo que extractToken().
     */
    private function extractStamp(array $sincro): ?array
    {
        // `Items` es el envoltorio de BranchDocumentType/Get (la fuente que sí
        // trae el timbrado — ver testConnection). Se descartan los borrados
        // lógicos: Factomate no borra físicamente, marca `Deleted` (§1 del
        // manual de ABM), y facturar contra un timbrado dado de baja es
        // exactamente el error que SIFEN rechaza.
        $items = $sincro['Items'] ?? $sincro['items'] ?? null;
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item) && empty($item['Deleted']) && empty($item['deleted'])) {
                    return $item;
                }
            }
        }

        foreach (['stamps', 'Stamps'] as $key) {
            if (is_array($sincro[$key] ?? null) && is_array($sincro[$key][0] ?? null)) {
                return $sincro[$key][0];
            }
        }
        $wrapped = $sincro['data'] ?? $sincro['Data'] ?? null;
        if (is_array($wrapped)) {
            foreach (['stamps', 'Stamps'] as $key) {
                if (is_array($wrapped[$key] ?? null) && is_array($wrapped[$key][0] ?? null)) {
                    return $wrapped[$key][0];
                }
            }
        }
        return null;
    }

    private function tryDecryptPhone(mixed $enc): ?string
    {
        $enc = (string) ($enc ?? '');
        if ($enc === '') {
            return null;
        }
        try {
            return CredentialVault::decrypt($enc);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * F1 — estado de facturación electrónica de UNA venta (solo lectura, panel).
     * Devuelve lista vacía si nunca se encoló (tenant sin FE, autoIssue off,
     * etc.) — el frontend interpreta lista vacía como "sin documento", no como
     * error. Scopeado por companyId — anti-IDOR, nunca confía en que el
     * transactionId del request pertenezca al tenant sin filtrar.
     */
    public function documentsForTransaction(string $companyId, string $transactionId): array
    {
        $rs = ncmExecute(
            "SELECT doctype, status, cdc, document_number, error_message, issued_at, attempts
               FROM einvoice_document
              WHERE companyid = ? AND transactionid = ?
              ORDER BY created_at DESC",
            [$companyId, $transactionId],
            false,
            true
        );

        $out = [];
        if ($rs !== false) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $out[] = [
                    'doctype'        => (string) ($f['doctype'] ?? ''),
                    'status'         => (string) ($f['status'] ?? ''),
                    'cdc'            => $f['cdc'] ?? null,
                    'documentNumber' => $f['document_number'] ?? null,
                    'errorMessage'   => $f['error_message'] ?? null,
                    'issuedAt'       => $f['issued_at'] ?? null,
                    'attempts'       => (int) ($f['attempts'] ?? 0),
                ];
                $rs->MoveNext();
            }
        }
        return $out;
    }

    // ── F2 — operación de los documentos ya emitidos ────────────────────

    /**
     * Listado paginado de `einvoice_document` para el panel — filtros de
     * rango de fechas (sobre `created_at`), estado, y búsqueda libre por
     * CDC/nombre de cliente. Scopeado SIEMPRE por `$companyId` del contexto
     * (nunca un id del request — aislamiento multi-tenant).
     *
     * El nombre/total del cliente NO vive en `einvoice_document` (solo
     * `transactionid`) — se hace JOIN contra `transaction`/`contact` para
     * poder mostrarlo y para que la búsqueda por nombre de cliente funcione
     * sin tener que desnormalizarlo en el outbox.
     *
     * `$filters` acepta: `from`/`to` (fecha 'Y-m-d', inclusive), `status`
     * (uno de los valores del CHECK de mig 92, o 'stuck' — ver abajo),
     * `search` (CDC parcial o nombre de cliente), `page`/`pageSize`.
     *
     * Documentos trabados en `sending`: si el proceso muere entre que el
     * drainer reclama la fila y persiste el resultado, queda en `sending`
     * para siempre sin que nadie los reintente automáticamente (NO es
     * seguro reintentar solo — la emisión no es idempotente del lado de
     * Factomate). `status: 'stuck'` es un filtro SINTÉTICO del panel (no
     * existe en la BD): `sending` con `updated_at` de más de 15 minutos —
     * umbral arbitrario pero generoso (la emisión real tarda segundos, no
     * minutos) para no marcar como trabado un documento que el drainer
     * está procesando en este instante.
     */
    public function documents(string $companyId, array $filters): array
    {
        $page     = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($filters['pageSize'] ?? 25)));
        $offset   = ($page - 1) * $pageSize;

        $where  = ['d.companyid = ?'];
        $params = [$companyId];

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $where[] = 'd.created_at >= ?::date';
            $params[] = $from;
        }
        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            // +1 día exclusivo — 'to' es inclusive del día completo, no de la medianoche.
            $where[] = "d.created_at < (?::date + interval '1 day')";
            $params[] = $to;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === 'stuck') {
            $where[] = "d.status = 'sending' AND d.updated_at < now() - interval '15 minutes'";
        } elseif ($status !== '') {
            $where[] = 'd.status = ?';
            $params[] = $status;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(d.cdc ILIKE ? OR c.contactName ILIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);

        // Total para paginación — misma condición, sin LIMIT/OFFSET.
        $countRow = ncmExecute(
            "SELECT COUNT(*) AS total
               FROM einvoice_document d
               LEFT JOIN transaction t ON t.transactionId = d.transactionid AND t.companyId = d.companyid
               LEFT JOIN contact c ON c.contactId = t.customerId AND c.companyId = d.companyid
              WHERE $whereSql",
            $params
        );
        $total = (int) ($countRow['total'] ?? 0);

        $rs = ncmExecute(
            "SELECT d.einvoicedocid, d.doctype, d.status, d.cdc, d.document_number, d.error_message,
                    d.issued_at, d.cancelled_at, d.attempts, d.created_at, d.updated_at,
                    d.sifen_status, d.sifen_checked_at,
                    t.transactionTotal AS total, t.transactionCurrency AS currency,
                    c.contactName AS client_name
               FROM einvoice_document d
               LEFT JOIN transaction t ON t.transactionId = d.transactionid AND t.companyId = d.companyid
               LEFT JOIN contact c ON c.contactId = t.customerId AND c.companyId = d.companyid
              WHERE $whereSql
              ORDER BY d.created_at DESC
              LIMIT $pageSize OFFSET $offset",
            $params,
            false,
            true
        );

        $rows = [];
        if ($rs !== false) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $isStuck = (string) ($f['status'] ?? '') === 'sending'
                    && strtotime((string) ($f['updated_at'] ?? '')) < (time() - 15 * 60);
                $rows[] = [
                    'id'             => (string) $f['einvoicedocid'],
                    'doctype'        => (string) ($f['doctype'] ?? ''),
                    'status'         => (string) ($f['status'] ?? ''),
                    'stuck'          => $isStuck,
                    'cdc'            => $f['cdc'] ?? null,
                    'documentNumber' => $f['document_number'] ?? null,
                    'errorMessage'   => $f['error_message'] ?? null,
                    'issuedAt'       => $f['issued_at'] ?? null,
                    'cancelledAt'    => $f['cancelled_at'] ?? null,
                    'attempts'       => (int) ($f['attempts'] ?? 0),
                    'createdAt'      => $f['created_at'] ?? null,
                    'sifenStatus'    => $f['sifen_status'] ?? null,
                    'sifenCheckedAt' => $f['sifen_checked_at'] ?? null,
                    'total'          => $f['total'] !== null ? (float) $f['total'] : null,
                    'currency'       => $f['currency'] ?? null,
                    'clientName'     => $f['client_name'] ?? null,
                ];
                $rs->MoveNext();
            }
        }

        return [
            'items'    => $rows,
            'page'     => $page,
            'pageSize' => $pageSize,
            'total'    => $total,
        ];
    }

    /**
     * Vuelve a poner un documento `error` en `pending` con `next_retry_at =
     * now()` para que el drainer lo tome en la próxima corrida. SOLO desde
     * `error` — reintentar un `issued` emitiría el documento fiscal DOS
     * VECES (Factomate no tiene endpoint de "reemitir", cada /Bulk es un
     * documento nuevo). El UPDATE con `WHERE status = 'error'` es el guard
     * real (no solo una validación previa) — mismo patrón CAS que el resto
     * del outbox, cierra la ventana de una request concurrente.
     *
     * @throws \RuntimeException si el documento no existe, no pertenece a
     *         la company, o no está en `error`.
     */
    public function retry(string $companyId, string $docId): array
    {
        $updated = ncmExecute(
            "UPDATE einvoice_document
                SET status = 'pending', next_retry_at = now(), updated_at = now()
              WHERE einvoicedocid = ? AND companyid = ? AND status = 'error'
              RETURNING einvoicedocid",
            [$docId, $companyId]
        );
        if (!$updated) {
            throw new \RuntimeException(
                'No se puede reintentar: el documento no existe o no está en estado de error.'
            );
        }

        // Reintento inline best-effort (mismo criterio que tryIssueInline):
        // si Factomate está caído igual queda en cola para el drainer del cron.
        $doc = ncmExecute(
            'SELECT transactionid, doctype FROM einvoice_document WHERE einvoicedocid = ?',
            [$docId]
        );
        if ($doc) {
            $this->tryIssueInline($companyId, (string) $doc['transactionid'], (string) $doc['doctype']);
        }

        return $this->documentById($companyId, $docId);
    }

    /**
     * Anula un documento fiscal ya emitido — POST /api/electronicDocument/event
     * (FactomateProvider::cancel). SOLO desde `issued`: no tiene sentido
     * anular algo que nunca se emitió (`error`/`pending`, usar retry o
     * dejar que expire) ni algo ya `cancelled`.
     *
     * Es irreversible y sale hacia afuera del sistema (SIFEN) — por eso el
     * motivo es obligatorio. La validación de largo mínimo/máximo o de
     * ventana de tiempo para cancelar NO está documentada en la guía —
     * SIN VERIFICAR, solo se exige no-vacío acá; si Factomate rechaza por
     * esas razones, el mensaje de error de la API vuelve tal cual al panel.
     *
     * @throws \RuntimeException si el documento no existe/no pertenece a la
     *         company, no está `issued`, el motivo viene vacío, o Factomate
     *         rechaza la cancelación.
     */
    public function cancel(string $companyId, string $docId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('El motivo de la cancelación es obligatorio.');
        }

        $doc = ncmExecute(
            'SELECT cdc FROM einvoice_document WHERE einvoicedocid = ? AND companyid = ? AND status = ?',
            [$docId, $companyId, 'issued']
        );
        if (!$doc) {
            throw new \RuntimeException('No se puede cancelar: el documento no existe o no está emitido.');
        }
        $cdc = (string) ($doc['cdc'] ?? '');
        if ($cdc === '') {
            // No debería pasar (issued siempre tiene CDC) pero sin CDC no hay
            // nada que mandarle a Factomate — mejor un error claro que un 500.
            throw new \RuntimeException('El documento emitido no tiene CDC registrado — no se puede cancelar.');
        }

        $bearer = $this->session->getBearer($companyId);
        [$phone, $environment] = $this->phoneAndEnvironment($companyId);

        $result = $this->provider->cancel($environment, $phone, $bearer, $cdc, $reason);
        if (empty($result['success'])) {
            $msg = (string) ($result['message'] ?? 'Factomate rechazó la cancelación sin motivo reconocible.');
            throw new \RuntimeException($msg);
        }

        ncmExecute(
            "UPDATE einvoice_document
                SET status = 'cancelled', cancelled_at = now(), cancel_reason = ?, updated_at = now()
              WHERE einvoicedocid = ?",
            [mb_substr($reason, 0, 500), $docId]
        );

        return $this->documentById($companyId, $docId);
    }

    /**
     * Bytes del PDF (KuDE) de un documento emitido. El PDF es OPCIONAL — si
     * `getkude` falla en Factomate (ej. el KuDE todavía no terminó de
     * generarse, ver FactomateProvider::kude), la excepción sube tal cual
     * para que el endpoint la traduzca a un error visible con botón de
     * reintento; NUNCA se marca el documento como error por esto — la
     * factura ya se emitió igual.
     *
     * @throws \RuntimeException si el documento no existe/no pertenece a la
     *         company, no tiene CDC (nunca se emitió), o Factomate falla.
     */
    public function kude(string $companyId, string $docId): string
    {
        $doc = ncmExecute(
            'SELECT cdc FROM einvoice_document WHERE einvoicedocid = ? AND companyid = ?',
            [$docId, $companyId]
        );
        if (!$doc) {
            throw new \RuntimeException('Documento no encontrado.');
        }
        $cdc = (string) ($doc['cdc'] ?? '');
        if ($cdc === '') {
            throw new \RuntimeException('El documento todavía no tiene CDC — no se emitió (o falló la emisión).');
        }

        $bearer = $this->session->getBearer($companyId);
        [$phone, $environment] = $this->phoneAndEnvironment($companyId);
        return $this->provider->kude($environment, $phone, $bearer, $cdc);
    }

    /**
     * Reconcilia el estado FISCAL real contra `GET /api/electronicDocument/getBulk/{id}`
     * para documentos `issued` con `provider_number` (el `Id` de bulk cacheado
     * al emitir) que todavía no tienen `sifen_status`, o cuyo último chequeo
     * es viejo.
     *
     * CRÍTICO: SIFEN puede rechazar un DE minutos después de que `/Bulk` ya
     * devolvió un CDC válido y `Success: true` — se comprobó hoy (2026-07-30)
     * un caso real: CDC válido, Success true, rechazo posterior por SIFEN
     * (código 1002, documento duplicado), y el KuDE se pudo descargar igual
     * para ese documento rechazado. Ni el CDC ni el PDF prueban validez
     * fiscal — el ÚNICO campo que dice si la factura vale es `sifen_status`,
     * que este método pobla. `status` (outbox de Punto: ¿se mandó?) y
     * `sifen_status` (fiscal: ¿SIFEN lo aceptó?) son dos cosas distintas —
     * este método SOLO toca `sifen_status`/`sifen_result`/`sifen_checked_at`,
     * nunca `status`.
     *
     * `GET /api/ElectronicDocument/GetAll` NO sirve para esto: verificado
     * contra la API real (2026-07-30), devuelve `Items: []` incluso después
     * de emitir con éxito — reconciliar contra ese endpoint era un no-op
     * silencioso que nunca actualizaba nada. `getBulk/{id}` con el `Id` raíz
     * del bulk es la fuente correcta.
     *
     * @return array{checked:int,updated:int} cuántos documentos se revisaron / cuántos cambiaron sifen_status.
     */
    public function reconcile(string $companyId, int $limit = 50): array
    {
        $limit = min(200, max(1, $limit));

        $rs = ncmExecute(
            "SELECT einvoicedocid, provider_number FROM einvoice_document
              WHERE companyid = ? AND status = 'issued' AND provider_number IS NOT NULL
                AND (sifen_checked_at IS NULL OR sifen_checked_at < now() - interval '10 minutes')
                -- Aprobado/Rechazado son ESTADOS FINALES: una vez que SIFEN se
                -- expidió no cambia, así que re-consultarlos es gasto puro. Se
                -- siguen consultando los que no tienen estado todavía y los
                -- transitorios: verificado contra la API real (2026-07-30) que un
                -- documento recién emitido pasa varios segundos en 'Pendiente'
                -- (con Success:false, que NO es un rechazo) antes de resolverse.
                AND (sifen_status IS NULL OR sifen_status NOT IN ('Aprobado', 'Rechazado'))
              ORDER BY issued_at ASC NULLS LAST
              LIMIT ?",
            [$companyId, $limit],
            false,
            true
        );

        $pending = [];
        if ($rs !== false) {
            while (!$rs->EOF) {
                $pending[] = ['id' => (string) $rs->fields['einvoicedocid'], 'bulkId' => (string) $rs->fields['provider_number']];
                $rs->MoveNext();
            }
        }

        if ($pending === []) {
            return ['checked' => 0, 'updated' => 0];
        }

        $bearer = $this->session->getBearer($companyId);
        [$phone, $environment] = $this->phoneAndEnvironment($companyId);

        $updated = 0;
        foreach ($pending as $doc) {
            try {
                $bulk = $this->provider->getBulk($environment, $phone, $bearer, $doc['bulkId']);
            } catch (\Throwable $e) {
                // Un fallo de red/API en UN documento no puede tirar abajo la
                // corrida entera — se loguea y se sigue con el resto, sin
                // tocar sifen_checked_at (para que se reintente en la próxima).
                error_log('[EInvoiceService] reconcile getBulk falló para ' . $doc['id'] . ': ' . $e->getMessage());
                continue;
            }

            $items = $bulk['Items'] ?? $bulk['items'] ?? [];
            $item = is_array($items) && isset($items[0]) && is_array($items[0]) ? $items[0] : [];

            // Parseo verificado con un rechazo real (2026-07-30). dEstResField
            // ("Aprobado"/"Rechazado") es la fuente más confiable cuando está
            // presente; StatusString/Success son el fallback cuando SIFEN
            // todavía no devolvió el detalle anidado.
            $sifenResult = $item['SifenResult'] ?? $item['sifenResult'] ?? null;
            $dEstRes = null;
            if (is_array($sifenResult)) {
                $rProtDe = $sifenResult['rRetEnviDe']['rProtDeField'] ?? $sifenResult['rretEnviDe']['rProtDeField'] ?? null;
                if (is_array($rProtDe)) {
                    $dEstRes = $rProtDe['dEstResField'] ?? null;
                }
            }

            $statusString = (string) ($item['StatusString'] ?? $item['statusString'] ?? '');
            $success = $item['Success'] ?? $item['success'] ?? null;

            if (is_string($dEstRes) && $dEstRes !== '') {
                $sifenStatus = $dEstRes; // "Aprobado" | "Rechazado"
            } elseif ($statusString !== '') {
                $sifenStatus = $statusString; // ej. "Exitoso" | "FinalizadoERROR"
            } elseif ($success !== null) {
                $sifenStatus = $success ? 'Aprobado' : 'Rechazado';
            } else {
                $sifenStatus = null;
            }

            ncmExecute(
                "UPDATE einvoice_document
                    SET sifen_status = ?, sifen_result = ?::jsonb, sifen_checked_at = now()
                  WHERE einvoicedocid = ?",
                [$sifenStatus, json_encode($bulk, JSON_UNESCAPED_UNICODE), $doc['id']]
            );
            $updated++;
        }

        return ['checked' => count($pending), 'updated' => $updated];
    }

    /** Fila única, scopeada por company, en el mismo shape que `documents()`. @throws \RuntimeException si no existe. */
    private function documentById(string $companyId, string $docId): array
    {
        // documents() no filtra por id — se resuelve acá con una query directa
        // en vez de forzar ese caso al método de listado (que solo prevé
        // search/status/from/to como filtros).
        $rs = ncmExecute(
            "SELECT d.einvoicedocid, d.doctype, d.status, d.cdc, d.document_number, d.error_message,
                    d.issued_at, d.cancelled_at, d.attempts, d.created_at, d.updated_at,
                    d.sifen_status, d.sifen_checked_at,
                    t.transactionTotal AS total, t.transactionCurrency AS currency,
                    c.contactName AS client_name
               FROM einvoice_document d
               LEFT JOIN transaction t ON t.transactionId = d.transactionid AND t.companyId = d.companyid
               LEFT JOIN contact c ON c.contactId = t.customerId AND c.companyId = d.companyid
              WHERE d.einvoicedocid = ? AND d.companyid = ?",
            [$docId, $companyId]
        );
        if (!$rs) {
            throw new \RuntimeException('Documento no encontrado.');
        }
        $isStuck = (string) ($rs['status'] ?? '') === 'sending'
            && strtotime((string) ($rs['updated_at'] ?? '')) < (time() - 15 * 60);
        return [
            'id'             => (string) $rs['einvoicedocid'],
            'doctype'        => (string) ($rs['doctype'] ?? ''),
            'status'         => (string) ($rs['status'] ?? ''),
            'stuck'          => $isStuck,
            'cdc'            => $rs['cdc'] ?? null,
            'documentNumber' => $rs['document_number'] ?? null,
            'errorMessage'   => $rs['error_message'] ?? null,
            'issuedAt'       => $rs['issued_at'] ?? null,
            'cancelledAt'    => $rs['cancelled_at'] ?? null,
            'attempts'       => (int) ($rs['attempts'] ?? 0),
            'createdAt'      => $rs['created_at'] ?? null,
            'sifenStatus'    => $rs['sifen_status'] ?? null,
            'sifenCheckedAt' => $rs['sifen_checked_at'] ?? null,
            'total'          => $rs['total'] !== null ? (float) $rs['total'] : null,
            'currency'       => $rs['currency'] ?? null,
            'clientName'     => $rs['client_name'] ?? null,
        ];
    }

    private function decodeJsonb(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) ($value ?? '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Merge shallow de la config de la cuenta: lo que viene del request pisa
     * clave por clave, y `null` BORRA la clave. Shallow a propósito —
     * `paymentMethodMap` se guarda entero desde su propia sección de la UI, así
     * que un merge profundo dejaría vivos mapeos de métodos ya borrados.
     *
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    private function mergeConfig(array $stored, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if ($value === null) {
                unset($stored[$key]);
                continue;
            }
            $stored[$key] = $value;
        }
        return $stored;
    }

    // ── F1 — outbox de emisión ──────────────────────────────────────────

    /**
     * Encola el documento de una venta DENTRO de la transacción de
     * `SaleService::save()` (antes de CompleteTrans). `ON CONFLICT DO NOTHING`
     * sobre el UNIQUE(companyid, transactionid, doctype) de mig 92 es la
     * idempotencia dura — un reintento de la cola offline con el mismo
     * transactionId no duplica el documento.
     *
     * Silencioso (no lanza) si: no hay cuenta 'ok' para la company, autoIssue
     * está en false, u onlyWithTaxId está en true y el cliente no tiene
     * RUC/CI — en todos esos casos NO hay outbox para esta venta, y eso es
     * el comportamiento correcto (no rompe la venta de un tenant sin FE).
     */
    public function enqueueForSale(string $companyId, string $transactionId, string $doctype, ?string $clientId): void
    {
        $account = ncmExecute(
            'SELECT status, config AS account_config FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );
        if (!$account || (string) ($account['status'] ?? '') !== 'ok') {
            return; // sin cuenta conectada — no hay outbox para este tenant
        }

        $config = $this->decodeJsonb($account['account_config'] ?? null);
        if (array_key_exists('autoIssue', $config) && !$config['autoIssue']) {
            return; // autoIssue desactivado explícitamente
        }

        if (!empty($config['onlyWithTaxId'])) {
            $hasTaxId = false;
            if ($clientId !== null) {
                $contact = ncmExecute(
                    'SELECT contactTIN, data FROM contact WHERE contactId = ? AND companyId = ?',
                    [$clientId, $companyId]
                );
                if ($contact) {
                    $tin = trim((string) ($contact['contactTIN'] ?? ''));
                    $ci  = trim((string) ($contact['contactCI'] ?? '')); // flattenJsonb ya trajo contactCI desde `data`
                    $hasTaxId = $tin !== '' || $ci !== '';
                }
            }
            if (!$hasTaxId) {
                return; // config exige RUC/CI y el cliente no tiene — no se encola
            }
        }

        ncmExecute(
            "INSERT INTO einvoice_document (companyid, transactionid, doctype, status)
             VALUES (?, ?, ?, 'pending')
             ON CONFLICT (companyid, transactionid, doctype) DO NOTHING",
            [$companyId, $transactionId, $doctype]
        );
    }

    /**
     * Intento de emisión inline, POST-COMMIT best-effort (llamado desde
     * SaleService::dispatchNotifications). La venta ya está confirmada;
     * un fallo acá solo deja el documento en `pending`/`error` para que el
     * drainer (cron) lo reintente — nunca afecta la venta.
     */
    public function tryIssueInline(string $companyId, string $transactionId, string $doctype): void
    {
        $doc = ncmExecute(
            "SELECT einvoicedocid FROM einvoice_document
              WHERE companyid = ? AND transactionid = ? AND doctype = ? AND status = 'pending'
              LIMIT 1",
            [$companyId, $transactionId, $doctype]
        );
        if (!$doc) {
            return; // no se encoló (sin cuenta / autoIssue off / etc.) o ya se procesó
        }

        $docId = (string) $doc['einvoicedocid'];
        // CAS pending → sending: si otra request (drainer corriendo en paralelo)
        // ya lo tomó, esta no hace nada — mismo patrón que pos_order/print_job.
        $claimed = ncmExecute(
            "UPDATE einvoice_document SET status = 'sending', updated_at = now()
              WHERE einvoicedocid = ? AND status = 'pending'
              RETURNING einvoicedocid",
            [$docId]
        );
        if (!$claimed) {
            return;
        }

        $this->issueClaimedDocument($docId, $companyId);
    }

    /**
     * Drainer del cron — procesa documentos `pending`/`error` con
     * `next_retry_at` vencido. CAS por fila antes de procesar (evita que dos
     * corridas del cron —o el intento inline y el cron— emitan el mismo
     * documento dos veces).
     *
     * @return array{processed:int,issued:int,errors:int}
     */
    public function drain(int $limit = 20): array
    {
        $processed = 0;
        $issued    = 0;
        $errors    = 0;

        // forceObj=true: SIEMPRE recordset, aunque haya 0/1/N filas — se itera con
        // while(!$rs->EOF), nunca como array plano (convención del repo, ver
        // Query::execute — con forceObj=false una sola fila colapsa a array asociativo
        // y N filas a RecordsetIterator, dos shapes distintos que forceObj evita).
        $rs = ncmExecute(
            "SELECT einvoicedocid, companyid FROM einvoice_document
              WHERE status IN ('pending','error') AND next_retry_at <= now()
              ORDER BY next_retry_at ASC
              LIMIT ?",
            [$limit],
            false,
            true
        );

        $rows = [];
        if ($rs !== false) {
            while (!$rs->EOF) {
                $rows[] = $rs->fields;
                $rs->MoveNext();
            }
        }

        foreach ($rows as $row) {
            $docId     = (string) ($row['einvoicedocid'] ?? '');
            $companyId = (string) ($row['companyid'] ?? '');
            if ($docId === '' || $companyId === '') {
                continue;
            }

            $claimed = ncmExecute(
                "UPDATE einvoice_document SET status = 'sending', updated_at = now()
                  WHERE einvoicedocid = ? AND status IN ('pending','error')
                  RETURNING einvoicedocid",
                [$docId]
            );
            if (!$claimed) {
                continue; // otra corrida ya lo tomó
            }

            $processed++;
            if ($this->issueClaimedDocument($docId, $companyId)) {
                $issued++;
            } else {
                $errors++;
            }
        }

        return ['processed' => $processed, 'issued' => $issued, 'errors' => $errors];
    }

    /**
     * Emite un documento YA reclamado (status='sending'). Nunca lanza —
     * cualquier fallo (mapeo, red, rechazo de Factomate) se persiste como
     * `error` con backoff, para que el drainer reintente después.
     *
     * @return bool true si quedó `issued`.
     */
    private function issueClaimedDocument(string $docId, string $companyId): bool
    {
        try {
            $doc = ncmExecute(
                'SELECT transactionid, doctype, attempts FROM einvoice_document WHERE einvoicedocid = ?',
                [$docId]
            );
            if (!$doc) {
                return false;
            }
            $transactionId = (string) $doc['transactionid'];
            $doctype       = (string) $doc['doctype'];
            $attempts      = (int) ($doc['attempts'] ?? 0);

            $account = ncmExecute(
                'SELECT status, environment, phone_enc, stamp, config AS account_config
                   FROM einvoice_account WHERE companyid = ?',
                [$companyId]
            );
            if (!$account || (string) ($account['status'] ?? '') !== 'ok') {
                $this->markError($docId, $attempts, 'La cuenta de facturación electrónica no está conectada (status != ok).');
                return false;
            }

            $sale = $this->buildSaleArrayForMapper($companyId, $transactionId, $doctype);
            if ($sale === null) {
                $this->markError($docId, $attempts, 'No se pudo reconstruir la venta para facturar (transacción no encontrada).');
                return false;
            }

            $stamp  = $this->decodeJsonb($account['stamp'] ?? null);
            $config = $this->decodeJsonb($account['account_config'] ?? null);

            // issuedDate: naive local de Asunción, mismo criterio que signDate
            // de la cancelación (ver FactomateProvider::cancel) —
            // date_default_timezone_set del proceso PHP ya está en la TZ de
            // Asunción (bootstrap del proyecto).
            $issuedDate = date('Y-m-d\TH:i:s');

            $mapper = new SaleToInvoiceMapper();
            try {
                $payload = $mapper->build($sale, $stamp, $config, $issuedDate);
            } catch (\RuntimeException $e) {
                // Regla fiscal violada o dato faltante — NUNCA se manda a Factomate
                // para que rebote, se marca error directo con el motivo en castellano.
                $this->markError($docId, $attempts, $e->getMessage());
                return false;
            }

            ncmExecute('UPDATE einvoice_document SET request_payload = ?::jsonb WHERE einvoicedocid = ?', [
                json_encode($payload, JSON_UNESCAPED_UNICODE), $docId,
            ]);

            $bearer = $this->session->getBearer($companyId);
            [$phone, $environment] = $this->phoneAndEnvironment($companyId);
            $result = $this->provider->issue($environment, $phone, $bearer, $payload);

            if (empty($result['success']) || empty($result['cdc'])) {
                $reason = (string) ($result['statusMessage'] ?? 'Factomate rechazó el documento sin motivo reconocible.');
                ncmExecute(
                    "UPDATE einvoice_document
                        SET status = 'error', attempts = attempts + 1, error_message = ?,
                            provider_response = ?::jsonb, next_retry_at = ?, updated_at = now()
                      WHERE einvoicedocid = ?",
                    [$reason, json_encode($result['raw'] ?? [], JSON_UNESCAPED_UNICODE), $this->nextRetryAt($attempts + 1), $docId]
                );
                return false;
            }

            // provider_number cachea el `Id` raíz del bulk devuelto por /Bulk —
            // es la llave de reconciliación con getBulk() (ver reconcile() y
            // FactomateProvider::getBulk). Columna heredada de mig 92
            // (nombre genérico pensado para Automate, quedó sin uso tras el
            // pivot a Factomate en mig 95) — se reusa acá en vez de sumar
            // una columna nueva.
            ncmExecute(
                "UPDATE einvoice_document
                    SET status = 'issued', cdc = ?, document_number = ?, provider_number = ?, provider_response = ?::jsonb,
                        issued_at = now(), updated_at = now()
                  WHERE einvoicedocid = ?",
                [
                    (string) $result['cdc'],
                    $result['documentNumber'] !== null ? (string) $result['documentNumber'] : null,
                    $result['bulkId'] !== null ? (string) $result['bulkId'] : null,
                    json_encode($result['raw'] ?? [], JSON_UNESCAPED_UNICODE),
                    $docId,
                ]
            );
            return true;
        } catch (\Throwable $e) {
            // Nunca dejar la excepción escapar — el caller (SaleService post-commit,
            // o el endpoint de drain) no puede fallar porque Factomate esté caído.
            error_log('[EInvoiceService] issueClaimedDocument ' . $docId . ': ' . $e->getMessage());
            try {
                $attempts = (int) (ncmExecute('SELECT attempts FROM einvoice_document WHERE einvoicedocid = ?', [$docId])['attempts'] ?? 0);
                $this->markError($docId, $attempts, $e->getMessage());
            } catch (\Throwable $inner) {
                // Ni siquiera se pudo persistir el error — se loguea y se abandona,
                // el documento queda 'sending' hasta revisión manual (caso extremo).
                error_log('[EInvoiceService] no se pudo persistir el error de ' . $docId . ': ' . $inner->getMessage());
            }
            return false;
        }
    }

    private function markError(string $docId, int $attemptsBefore, string $message): void
    {
        ncmExecute(
            "UPDATE einvoice_document
                SET status = 'error', attempts = attempts + 1, error_message = ?, next_retry_at = ?, updated_at = now()
              WHERE einvoicedocid = ?",
            [mb_substr($message, 0, 500), $this->nextRetryAt($attemptsBefore + 1), $docId]
        );
    }

    /**
     * Backoff exponencial: 2^attempts minutos, cap en 8 intentos (~4h20 la
     * última espera) — a partir de ahí queda en `error` visible sin más
     * reintento automático (el drainer solo toma next_retry_at <= now(), y
     * un backoff más allá de 8 no aporta: si Factomate rechazó 8 veces, hace
     * falta intervención humana, no más reintentos ciegos).
     */
    private function nextRetryAt(int $attempts): string
    {
        $capped = min($attempts, 8);
        $minutes = 2 ** $capped;
        return date('c', time() + $minutes * 60);
    }

    /**
     * Resuelve el `taxRate` (10|5|0) de cada línea facturable a partir de la
     * definición de impuesto del ÍTEM (`item.taxId` → `tax.name`), NUNCA del
     * detalle de venta persistido — el POS moderno (`create-sale.ts`
     * `buildSalePayload`) no manda `tax` por línea, así que derivar la tasa
     * de `tax/neto` da 0 siempre y sub-declara IVA en silencio en un
     * documento fiscal (el bug que este método reemplaza).
     *
     * Trade-off consciente: se usa la tasa VIGENTE del ítem al momento de
     * facturar, no la que regía cuando se vendió — la venta no persiste la
     * tasa aplicada en ningún lado, así que esta es la única fuente
     * disponible. Si el ítem cambió de tasa entre la venta y la emisión
     * (reintento, cola demorada), el documento sale con la tasa nueva. Los
     * cambios de tasa de IVA son eventos regulados y poco frecuentes, así
     * que el riesgo es bajo, pero queda documentado por si algún día importa.
     *
     * Una sola query para todas las líneas (evita N+1 en el path de
     * emisión). Si un ítem no tiene `taxId` configurado, o el valor de
     * `tax.name` no cae en {10,5,0}, tira excepción — está prohibido asumir
     * una tasa o saltear la línea: mejor que el documento quede en `error`
     * y alguien lo revise a que salga con una tasa inventada.
     *
     * @param array<int,array<string,mixed>> $billableDetail líneas ya filtradas (facturables)
     * @return array<string,int> itemId => taxRate (10|5|0)
     */
    private function resolveTaxRatesForItems(string $companyId, array $billableDetail): array
    {
        $itemIds = [];
        foreach ($billableDetail as $sD) {
            $itemIds[(string) $sD['itemId']] = true;
        }
        $itemIds = array_keys($itemIds);
        if ($itemIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $rs = ncmExecute(
            "SELECT item.itemId AS itemId, item.itemName AS itemName, tax.name AS taxValue
               FROM item
               LEFT JOIN tax ON tax.taxId = item.taxId
              WHERE item.itemId IN ({$placeholders}) AND item.companyId = ?",
            [...$itemIds, $companyId],
            false,
            true
        );

        // Un fallo de la query no puede degradar a "sin impuesto": eso emitiría
        // el documento con todo exento, que es exactamente el bug que este
        // método existe para evitar. Sin tasas resueltas, no se factura.
        if ($rs === false) {
            throw new \RuntimeException(
                'No se pudieron leer los impuestos de los ítems de la venta — no se emite el documento.'
            );
        }

        $resolved = [];
        while (!$rs->EOF) {
            $itemId = (string) $rs->fields['itemid'];
            $itemName = (string) ($rs->fields['itemname'] ?? $itemId);
            $rawValue = $rs->fields['taxvalue'] ?? null;

            if ($rawValue === null || $rawValue === '') {
                throw new \RuntimeException(
                    "No se puede facturar: el ítem \"{$itemName}\" no tiene un impuesto configurado."
                );
            }

            $taxRate = match (trim((string) $rawValue)) {
                '10' => 10,
                '5'  => 5,
                '0'  => 0,
                default => throw new \RuntimeException(
                    "No se puede facturar: el ítem \"{$itemName}\" tiene una tasa de impuesto (\"{$rawValue}\") "
                    . 'que no es 10, 5 ni 0 — revisá la configuración de impuestos del ítem.'
                ),
            };

            $resolved[$itemId] = $taxRate;
            $rs->MoveNext();
        }

        foreach ($itemIds as $itemId) {
            if (!array_key_exists($itemId, $resolved)) {
                throw new \RuntimeException(
                    "No se puede facturar: no se encontró el ítem {$itemId} (o fue borrado) para resolver su impuesto."
                );
            }
        }

        return $resolved;
    }

    /**
     * Reconstruye el shape `$sale` que espera `SaleToInvoiceMapper::build()`
     * a partir de la venta persistida. Devuelve null si la transacción no
     * existe (no debería pasar — el outbox se encola desde una venta recién
     * insertada — pero es defensivo ante una fila borrada/corrupta).
     */
    private function buildSaleArrayForMapper(string $companyId, string $transactionId, string $doctype): ?array
    {
        $tx = ncmExecute(
            "SELECT transactionType, transactionTotal, transactionCurrency, transactionDueDate,
                    customerId, transactionPaymentType, meta
               FROM transaction WHERE transactionId = ? AND companyId = ?",
            [$transactionId, $companyId]
        );
        if (!$tx) {
            return null;
        }

        // meta es JSONB con transactionDetails como JSON-STRING adentro (doble-encode,
        // §22.6 — ver SaleService::buildTransactionRecord). flattenJsonb ya mezcló las
        // keys de meta en la fila porque se llama literalmente `meta`.
        $saleDetailRaw = $tx['transactionDetails'] ?? null;
        $saleDetail = is_string($saleDetailRaw) ? json_decode($saleDetailRaw, true) : (is_array($saleDetailRaw) ? $saleDetailRaw : []);
        $saleDetail = is_array($saleDetail) ? $saleDetail : [];

        $paymentsRaw = $tx['transactionPaymentType'] ?? null;
        $payments = is_string($paymentsRaw) ? json_decode($paymentsRaw, true) : (is_array($paymentsRaw) ? $paymentsRaw : []);
        $payments = is_array($payments) ? $payments : [];

        $total = (float) ($tx['transactionTotal'] ?? 0);

        // Filtra ítems facturables ANTES de resolver tasas, para no pedir la tasa
        // de líneas que ni van a entrar al documento (inCredit, gift card, cantidad/total inválidos).
        $billableDetail = [];
        foreach ($saleDetail as $sD) {
            if (empty($sD['itemId']) || ($sD['type'] ?? '') === 'giftcard') {
                continue;
            }
            $count = (float) ($sD['count'] ?? 0);
            $lineTotal = (float) ($sD['total'] ?? 0);
            if ($count <= 0 || $lineTotal == 0.0) {
                continue;
            }
            $billableDetail[] = $sD;
        }

        $taxRateByItemId = $this->resolveTaxRatesForItems($companyId, $billableDetail);

        $items = [];
        foreach ($billableDetail as $sD) {
            $itemId = (string) $sD['itemId'];
            $count = (float) ($sD['count'] ?? 0);
            $lineTotal = (float) ($sD['total'] ?? 0);
            $items[] = [
                'description' => (string) ($sD['name'] ?? ''),
                'quantity'    => $count,
                'unitPrice'   => round($lineTotal / $count, 8),
                'total'       => $lineTotal,
                'taxRate'     => $taxRateByItemId[$itemId],
            ];
        }

        $clientId = $tx['customerId'] ?? null;
        $client = ['nature' => 'innominado'];
        if ($clientId !== null && $clientId !== '') {
            $contact = ncmExecute(
                'SELECT contactTIN, contactName, data FROM contact WHERE contactId = ? AND companyId = ?',
                [$clientId, $companyId]
            );
            if ($contact) {
                $tin = trim((string) ($contact['contactTIN'] ?? ''));
                $ci  = trim((string) ($contact['contactCI'] ?? ''));
                $name = trim((string) ($contact['contactName'] ?? ''));
                if ($tin !== '') {
                    $client = ['nature' => 'contribuyente', 'ruc' => $tin, 'ci' => $ci !== '' ? $ci : null, 'name' => $name];
                } elseif ($ci !== '') {
                    $client = ['nature' => 'fisica', 'ci' => $ci, 'name' => $name];
                } else {
                    $client = ['nature' => 'innominado', 'name' => $name !== '' ? $name : 'Consumidor final'];
                }
            }
        }

        $operationCondition = $doctype === 'FCR' ? 1 : 0;

        // Medios de pago (F3): se declara UNA línea por pago real de la venta
        // — SIFEN admite varias formas de pago en el mismo documento, así que
        // una venta mitad efectivo / mitad tarjeta se declara como es, no
        // colapsada en un solo código.
        //
        // La clave del pago NO es homogénea: las ventas nuevas guardan el
        // taxonomyId del método en `type`, las viejas un slug/nombre legacy.
        // La resolución a taxonomyId es la MISMA que necesita Finanzas, así
        // que se comparte (PaymentMethods\PaymentMethodResolver) en vez de
        // duplicarse acá. `methodId` null = método borrado o clave
        // desconocida: el mapper cae al código por defecto de la cuenta.
        //
        // El monto es el COBRADO, no el entregado: el POS registra el pago por
        // el remanente y el vuelto queda fuera (ver pay-dialog, caso C), así
        // que la suma de las líneas cierra contra el total de la venta.
        $resolver = new \Punto\Api\PaymentMethods\PaymentMethodResolver();
        $paymentLines = [];
        foreach ($payments as $pay) {
            $amount = abs(round((float) ($pay['total'] ?? $pay['price'] ?? 0)));
            if ($amount <= 0) {
                continue;
            }
            $key = trim((string) ($pay['type'] ?? ''));
            if ($key === '') {
                $key = trim((string) ($pay['name'] ?? ''));
            }
            $paymentLines[] = [
                'methodId'  => $key !== '' ? $resolver->resolveMethodId($companyId, $key) : null,
                'methodKey' => $key,
                'amount'    => $amount,
            ];
        }

        $sale = [
            'total'               => $total,
            'currency'            => (string) ($tx['transactionCurrency'] ?? 'PYG'),
            'operationCondition'  => $operationCondition,
            'items'               => $items,
            'client'              => $client,
            'payments'            => $paymentLines,
        ];

        if ($operationCondition === 1) {
            $dueDate = trim((string) ($tx['transactionDueDate'] ?? ''));
            $deadline = null;
            if ($dueDate !== '' && $dueDate !== '0000-00-00' && $dueDate !== '0000-00-00 00:00:00') {
                $ts = strtotime($dueDate);
                $deadline = $ts !== false ? date('Y-m-d', $ts) : null;
            }
            if ($deadline === null) {
                // SIN VERIFICAR: sin fecha de vencimiento en la venta, se asume 30 días
                // desde hoy — no hay forma de saber el plazo real pactado con el cliente.
                $deadline = date('Y-m-d', strtotime('+30 days'));
            }
            $sale['credit'] = ['creditOperationCondition' => 0, 'creditDeadline' => $deadline];
        }

        return $sale;
    }
}
