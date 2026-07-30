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

        $existing = ncmExecute(
            'SELECT username, phone_enc, environment FROM einvoice_account WHERE companyid = ?',
            [$companyId]
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
            $sincro  = $this->provider->sincroConfig($environment, $phone, $bearer);
            $stamp   = $this->extractStamp($sincro);

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

    /** @throws \RuntimeException si la cuenta no está conectada (status != 'ok'). */
    public function paymentMethods(string $companyId): array
    {
        $row = ncmExecute('SELECT status FROM einvoice_account WHERE companyid = ?', [$companyId]);
        if (!$row || (string) ($row['status'] ?? '') !== 'ok') {
            throw new \RuntimeException('La cuenta de facturación electrónica no está conectada.');
        }

        $bearer = $this->session->getBearer($companyId);
        [$phone, $environment] = $this->phoneAndEnvironment($companyId);
        return $this->provider->paymentMethods($environment, $phone, $bearer);
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

    private function decodeJsonb(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) ($value ?? '{}'), true);
        return is_array($decoded) ? $decoded : [];
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

            $mapper = new SaleToInvoiceMapper();
            try {
                $payload = $mapper->build($sale, $stamp, $config);
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

            ncmExecute(
                "UPDATE einvoice_document
                    SET status = 'issued', cdc = ?, document_number = ?, provider_response = ?::jsonb,
                        issued_at = now(), updated_at = now()
                  WHERE einvoicedocid = ?",
                [
                    (string) $result['cdc'],
                    $result['documentNumber'] !== null ? (string) $result['documentNumber'] : null,
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

        // Medio de pago: la venta puede tener varios pagos, pero el mapper (F1)
        // solo soporta UN paymentMethodCode por documento con el total completo
        // (ver SaleToInvoiceMapper::buildPayment) — se toma el primer pago no-balance
        // (cash/card real) como representativo. SIN VERIFICAR: pagos mixtos
        // (ej. mitad efectivo, mitad tarjeta) se facturan igual con un solo código.
        $paymentMethod = '';
        foreach ($payments as $pay) {
            $t = (string) ($pay['type'] ?? '');
            if (!in_array($t, ['points', 'storeCredit', 'giftcard'], true) && $t !== '') {
                $paymentMethod = $t;
                break;
            }
        }

        $sale = [
            'total'               => $total,
            'currency'            => (string) ($tx['transactionCurrency'] ?? 'PYG'),
            'operationCondition'  => $operationCondition,
            'items'               => $items,
            'client'              => $client,
            'paymentMethod'       => $paymentMethod,
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
