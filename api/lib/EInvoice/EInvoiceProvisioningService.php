<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * F7 — provisioning white-label del emisor (context/28 §Onboarding).
 *
 * El comercio NO re-tipea nada que Punto ya tenga: el RUC y la razón social
 * salen de Configuración del negocio (companyFiscal) y los timbrados de las
 * CAJAS — cada caja es un punto de expedición (context/29 §1) y su timbrado
 * se administra en la caja (registerStamps). El formulario de facturación
 * electrónica solo pide lo que no existe en otro lado: actividad económica,
 * tipo de contribuyente, email de facturación y CSC. Punto crea el emisor en
 * Factomate con su credencial ADMIN; el comercio nunca ve una credencial de
 * Factomate — ni sabe que existe: la cadena de auth por-tenant sale del
 * bearer admin vía PhoneLogin (ver FactomateSession).
 *
 * Alta compuesta REANUDABLE: Factomate no tiene transacción del lado de
 * ellos (manual §2.6, compensación manual) y `CreateExternal` NO es
 * idempotente — un reintento a ciegas tras un timeout puede crear el emisor
 * dos veces. Por eso cada paso persiste su checkpoint en
 * `einvoice_account.provisioning` y `provision()` retoma desde el primer
 * paso que falte:
 *
 *   1. createExternal      → factomate_tenant_id/user_id + credencial al vault
 *   2. PUT /api/Tenant     → datos fiscales (tipo contribuyente, CSC, textos)
 *   3. POST /api/Activity  → actividad económica SIFEN
 *   4. POST /api/BranchDocumentType → UN timbrado POR CAJA (FC + NC),
 *      checkpoint por caja + mapa registerId → ids (la emisión usa el
 *      timbrado de la caja de la venta — EInvoiceService::stampForDocument)
 *   5. certificado         → si hay uno en custodia y el proveedor todavía no
 *      lo tiene, se re-sube solo (ver ensureCertApplied)
 *   6. sync del timbrado   → stamp cache + status 'ok'
 *
 * La CONTRASEÑA del paso 1 se devuelve una sola vez y no es recuperable
 * (manual §2.4): la escritura al vault es lo INMEDIATO siguiente a la
 * respuesta, y si esa escritura falla el error incluye tenantId/userId
 * (nunca la contraseña) para poder pedir un reseteo a Factomate.
 *
 * ── Custodia del certificado y del CSC (owner, 2026-09-06) ─────────────────
 *
 * La regla anterior era que el `.pfx` y el `CSCProduccion` pasaran al
 * proveedor y se descartaran. El owner la revirtió por LOCK-IN: pedirle el
 * certificado de nuevo a cada comercio para migrar de proveedor equivale a no
 * poder migrar nunca. Ahora se guardan CIFRADOS, en `FiscalSecretStore` —
 * que es la única puerta a esas columnas y audita cada lectura en
 * `tenant_audit`. Ver context/28 §Custodia y la mig 195.
 *
 * Lo que NO cambió: ni el certificado, ni su contraseña, ni el secreto del CSC
 * vuelven al frontend ni tocan un log. En `fiscal` sigue quedando el espejo
 * del formulario SIN secretos, para re-mostrar en la UI y reanudar.
 */
final class EInvoiceProvisioningService
{
    private EInvoiceProvider $provider;
    private FactomateSession $session;

    public function __construct(?EInvoiceProvider $provider = null)
    {
        $this->provider = $provider ?? new FactomateProvider();
        $this->session  = new FactomateSession($this->provider);
    }

    /** Entorno donde se provisionan los emisores nuevos — global, no elección del tenant. */
    public static function defaultEnvironment(): string
    {
        $env = defined('EINVOICE_DEFAULT_ENVIRONMENT') ? (string) EINVOICE_DEFAULT_ENVIRONMENT : 'test';
        return $env === 'prod' ? 'prod' : 'test';
    }

    /**
     * Crea (o retoma) el provisioning del emisor. Idempotente por checkpoint.
     *
     * @param array<string,mixed> $form Ver validateForm() para el shape.
     * @return array<string,mixed> La cuenta (EInvoiceService::getAccount).
     * @throws \RuntimeException con mensaje en castellano apto para el operador.
     */
    public function provision(string $companyId, array $form): array
    {
        $fiscal = $this->validateForm($form);
        $environment = self::defaultEnvironment();

        if (!FactomateSession::hasAdminCredentials($environment)) {
            // Sin credencial admin no hay white-label. Mensaje para el
            // OPERADOR de Punto (es un problema de infra nuestro, no del
            // comercio) — el frontend lo muestra tal cual.
            throw new \RuntimeException(
                'El servicio de facturación electrónica no está disponible en este momento. ' .
                'Contactá a soporte de Punto (credencial de provisioning sin configurar).'
            );
        }

        // Fila local primero: es el checkpoint raíz. El secreto del CSC nunca
        // entra en `fiscal` (pasa directo a Factomate en el paso 2).
        $fiscalJson = json_encode($this->stripSecrets($fiscal), JSON_UNESCAPED_UNICODE);
        $existing = ncmExecute(
            'SELECT companyid, factomate_tenant_id, provisioning, status FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );
        if (!$existing) {
            ncmExecute(
                "INSERT INTO einvoice_account (companyid, provider, environment, status, fiscal)
                 VALUES (?, 'factomate', ?, 'provisioning', ?::jsonb)",
                [$companyId, $environment, $fiscalJson]
            );
        } else {
            ncmExecute(
                "UPDATE einvoice_account
                    SET fiscal = ?::jsonb,
                        status = CASE WHEN status = 'ok' THEN 'ok' ELSE 'provisioning' END,
                        updated_at = now()
                  WHERE companyid = ?",
                [$fiscalJson, $companyId]
            );
        }

        try {
            $company = $this->companyFiscal($companyId);
            $stamps  = $this->registerStamps($companyId);

            $tenantId = $this->ensureTenantCreated($companyId, $fiscal, $company, $environment);
            $bearer   = $this->session->getBearer($companyId);
            $login    = $this->accountLogin($companyId);

            $this->ensureFiscalApplied($companyId, $fiscal, $company, $environment, $login, $bearer, $tenantId);
            $this->ensureActivityCreated($companyId, $fiscal, $environment, $login, $bearer, $tenantId);
            $this->ensureStampsCreated($companyId, $stamps, $environment, $login, $bearer);
            $this->ensureCertApplied($companyId, $environment, $login, $bearer, $tenantId);

            // Paso final: releer el timbrado desde Factomate (fuente real:
            // BranchDocumentType/Get) y marcar la cuenta operativa. Reusa
            // testConnection, que ya persiste emitter/stamp/status/last_error.
            $svc = new EInvoiceService($this->provider);
            $result = $svc->testConnection($companyId);
            if (($result['status'] ?? '') !== 'ok') {
                throw new \RuntimeException(
                    (string) ($result['lastError'] ?? 'El emisor se creó pero la verificación final falló.')
                );
            }
            return $svc->getAccount($companyId);
        } catch (\RuntimeException $e) {
            ncmExecute(
                'UPDATE einvoice_account SET last_error = ?, last_check_at = now(), updated_at = now() WHERE companyid = ?',
                [mb_substr($e->getMessage(), 0, 500), $companyId]
            );
            throw $e;
        }
    }

    /**
     * Certificado de firma: se sube al proveedor y —desde el cambio de
     * decisión de 2026-09-06— queda además en custodia cifrada
     * (`FiscalSecretStore`), para poder reconfigurar la emisión sin volver a
     * pedírselo al comercio. Nunca se loguea ni vuelve al frontend.
     *
     * El orden importa: primero el proveedor, después la custodia. Si
     * Factomate rechaza el `.pfx` (contraseña equivocada, certificado vencido),
     * guardarlo dejaría en custodia un archivo que no sirve para firmar y la
     * UI diría "cargado" sobre algo que no lo está.
     *
     * @throws \RuntimeException
     */
    public function uploadCert(string $companyId, string $certBase64, string $certPassword): array
    {
        if (trim($certBase64) === '' || $certPassword === '') {
            throw new \RuntimeException('Falta el archivo del certificado o su contraseña.');
        }

        [$environment, $tenantId, $login] = $this->requireProvisioned($companyId);
        $bearer = $this->session->getBearer($companyId);

        $raw = $this->provider->uploadCert($environment, $login, $bearer, $tenantId, $certBase64, $certPassword);
        if (empty($raw['Success'] ?? $raw['success'] ?? false)) {
            throw new \RuntimeException((string) ($raw['Error'] ?? $raw['error'] ?? 'Factomate rechazó el certificado.'));
        }

        // El checkpoint del PROVEEDOR se marca pase lo que pase con la
        // custodia: el emisor ya tiene el certificado, y decir lo contrario
        // haría que el comercio lo vuelva a subir sin necesidad (y que un
        // re-provisioning lo re-suba de gusto). Si el vault falla —clave mal
        // configurada, rotación a medias— se pierde la custodia, no el alta.
        $this->mergeProvisioning($companyId, ['certUploaded' => true]);

        try {
            FiscalSecretStore::storeCertificate($companyId, $certBase64, $certPassword);
        } catch (\RuntimeException $e) {
            // Nunca el archivo ni la contraseña en el mensaje: solo el motivo
            // del vault.
            error_log('[EInvoiceProvisioning] el certificado se registró en el emisor pero NO quedó en custodia para company '
                . $companyId . ': ' . $e->getMessage());
            throw new \RuntimeException(
                'El certificado quedó registrado y ya podés emitir, pero Punto no pudo guardarlo cifrado '
                . 'para reconfiguraciones futuras. Avisá a soporte de Punto.'
            );
        }

        return ['uploaded' => true];
    }

    /**
     * Borra el certificado que Punto tiene en custodia. Es SU certificado: si
     * lo pide, se borra.
     *
     * NO lo quita del proveedor — el comercio sigue facturando igual (ver
     * `FiscalSecretStore::deleteCertificate`). Lo que se pierde es la
     * capacidad de reconfigurar la emisión sin volver a pedírselo, y eso es
     * exactamente lo que la UI le advierte antes de confirmar.
     */
    public function deleteCert(string $companyId): array
    {
        FiscalSecretStore::deleteCertificate($companyId);
        return FiscalSecretStore::status($companyId);
    }

    /**
     * Guarda el CSC de producción (SIFEN) y lo aplica al emisor. Existe como
     * acción propia —y no solo como campo del alta— porque el CSC se emite en
     * el Marangatu y suele conseguirse DESPUÉS de que el emisor ya está
     * provisionado: sin esto, un comercio que se dio de alta sin CSC no tenía
     * ninguna forma de cargarlo (`ensureFiscalApplied` ya está checkpointeado
     * y no vuelve a correr).
     *
     * El secreto queda en custodia cifrada; el id, en `fiscal` (no es secreto).
     *
     * @throws \RuntimeException
     */
    public function saveCsc(string $companyId, string $cscId, string $cscSecret): array
    {
        $cscId     = trim($cscId);
        $cscSecret = trim($cscSecret);
        if ($cscId === '' || $cscSecret === '') {
            throw new \RuntimeException('Cargá el identificador del CSC y su código de seguridad.');
        }

        [$environment, $tenantId, $login] = $this->requireProvisioned($companyId);
        $bearer = $this->session->getBearer($companyId);

        $fiscal = $this->accountFiscal($companyId);
        $fiscal['cscId'] = $cscId;

        // Payload COMPLETO del tenant: `PUT /api/Tenant` es un reemplazo, no
        // un patch — mandar solo el CSC borraría RUC y razón social del emisor.
        $tenant = $this->buildTenantPayload(
            $tenantId,
            $this->companyFiscal($companyId),
            $fiscal,
            $cscSecret
        );
        $raw = $this->provider->updateTenant($environment, $login, $bearer, $tenant);
        if (empty($raw['Success'] ?? $raw['success'] ?? true) && !empty($raw['Error'] ?? $raw['error'] ?? '')) {
            throw new \RuntimeException('No se pudo guardar el CSC: ' . (string) ($raw['Error'] ?? $raw['error']));
        }

        // El emisor ya lo tiene: el id se persiste igual, aunque la custodia
        // falle (mismo criterio que uploadCert()).
        ncmExecute(
            'UPDATE einvoice_account SET fiscal = ?::jsonb, updated_at = now() WHERE companyid = ?',
            [json_encode($this->stripSecrets($fiscal), JSON_UNESCAPED_UNICODE), $companyId]
        );

        try {
            FiscalSecretStore::storeCscSecret($companyId, $cscSecret);
        } catch (\RuntimeException $e) {
            error_log('[EInvoiceProvisioning] el CSC se aplicó al emisor pero NO quedó en custodia para company '
                . $companyId . ': ' . $e->getMessage());
            throw new \RuntimeException(
                'El CSC quedó aplicado y ya podés emitir, pero Punto no pudo guardarlo cifrado. '
                . 'Avisá a soporte de Punto.'
            );
        }

        return FiscalSecretStore::status($companyId);
    }

    /**
     * Prueba de humo REAL del certificado: consulta de RUC contra SIFEN con
     * el certificado del emisor como cert cliente TLS (manual §7.5).
     *
     * @throws \RuntimeException con el detalle de Factomate ("Error de Certificado o CSC").
     */
    public function testSet(string $companyId): array
    {
        [$environment, $tenantId, $login] = $this->requireProvisioned($companyId);
        $bearer = $this->session->getBearer($companyId);

        $fiscal = $this->accountFiscal($companyId);
        $ruc = (string) ($fiscal['ruc'] ?? '');
        if ($ruc === '') {
            throw new \RuntimeException('La cuenta no tiene RUC registrado.');
        }

        return $this->provider->testSet($environment, $login, $bearer, $tenantId, $ruc);
    }

    // ── Pasos (cada uno con su checkpoint) ──────────────────────────────

    private function ensureTenantCreated(string $companyId, array $fiscal, array $company, string $environment): int
    {
        $row = ncmExecute('SELECT factomate_tenant_id FROM einvoice_account WHERE companyid = ?', [$companyId]);
        $tenantId = $row['factomate_tenant_id'] ?? null;
        if ($tenantId !== null && (int) $tenantId > 0) {
            return (int) $tenantId; // ya creado — NUNCA repetir CreateExternal
        }

        $adminBearer = $this->session->getAdminBearer($environment);
        $adminLogin  = $this->session->getAdminLogin($environment);

        $created = $this->provider->createExternal($environment, $adminLogin, $adminBearer, [
            'razonSocial'    => $company['razonSocial'],
            'nombreFantasia' => $company['nombreFantasia'],
            'email'          => $fiscal['email'],
            'ruc'            => $company['ruc'],
        ]);

        // ESCRITURA INMEDIATA — la contraseña no se puede volver a pedir.
        // phone_enc guarda la identidad de login del usuario: su email
        // (UserName = Email en CreateExternal, manual §2.2).
        // El `catch (DbQueryException)` NO es decorativo: desde 2026-08-22 un
        // error de SQL sale por excepción y ya NO vuelve como `['error' => msg]`,
        // así que sin él el `if` de abajo —y con él el código FT- de
        // recuperación— quedaba inalcanzable y el operador recibía un 500 pelado
        // sobre un emisor que YA existe en Factomate con una contraseña que no
        // se puede volver a pedir. Es la excepción explícita al criterio general
        // de ncmUpdate (ver su docblock): acá el mensaje lleva información de
        // recuperación irrecuperable de otro modo. No se traga nada — se
        // registra el error de PG y se relanza el mismo RuntimeException.
        $r = null;
        try {
            $r = ncmUpdate([
                'table'       => 'einvoice_account',
                'records'     => [
                    'factomate_tenant_id' => $created['tenantId'],
                    'factomate_user_id'   => $created['userId'],
                    'username'            => $created['email'],
                    'password_enc'        => CredentialVault::encrypt($created['password']),
                    'phone_enc'           => CredentialVault::encrypt($created['email']),
                    'token_enc'           => null,
                    'token_expires_at'    => null,
                ],
                'where'       => 'companyid = ?',
                'whereParams' => [$companyId],
            ]);
        } catch (\Punto\Api\Support\DbQueryException $e) {
            error_log('[EInvoiceProvisioning] escritura local del emisor falló con SQL: '
                . $e->getMessage() . ' | SQLSTATE ' . $e->sqlState());
            $r = ['error' => 'db'];
        }
        if (!is_array($r) || !empty($r['error'])) {
            // El emisor EXISTE en Factomate pero no pudimos guardar su
            // credencial: error ruidoso con los ids (nunca la contraseña)
            // para poder pedir reseteo — manual §2.6, riesgo 1 del plan.
            error_log(sprintf(
                '[EInvoiceProvisioning] CRITICO: CreateExternal ok (tenantId=%d userId=%s) pero fallo la escritura local para company %s',
                $created['tenantId'],
                $created['userId'],
                $companyId
            ));
            throw new \RuntimeException(
                'El emisor se creó pero no se pudo guardar su acceso. NO reintentar el alta: ' .
                'contactá a soporte de Punto con este código: FT-' . $created['tenantId'] . '.'
            );
        }

        $this->mergeProvisioning($companyId, ['tenantCreated' => true]);
        return $created['tenantId'];
    }

    private function ensureFiscalApplied(string $companyId, array $fiscal, array $company, string $environment, string $login, string $bearer, int $tenantId): void
    {
        if ($this->checkpoint($companyId, 'fiscalApplied')) {
            return;
        }

        // El secreto del CSC sale del formulario si el comercio lo tipeó
        // ahora; si no, del que quedó en custodia de un alta anterior. Antes
        // se descartaba después de mandarlo, así que reanudar un provisioning
        // sin re-tipearlo lo mandaba VACÍO y el emisor se quedaba sin CSC en
        // silencio.
        $cscSecret = (string) ($fiscal['cscSecret'] ?? '');
        if ($cscSecret === '') {
            $cscSecret = (string) (FiscalSecretStore::readCscSecret(
                $companyId,
                'Aplicar los datos fiscales del emisor (alta o reanudación del provisioning)'
            ) ?? '');
        }

        $tenant = $this->buildTenantPayload($tenantId, $company, $fiscal, $cscSecret);

        $raw = $this->provider->updateTenant($environment, $login, $bearer, $tenant);
        if (empty($raw['Success'] ?? $raw['success'] ?? true) && !empty($raw['Error'] ?? $raw['error'] ?? '')) {
            throw new \RuntimeException('No se pudieron guardar los datos fiscales: ' . (string) ($raw['Error'] ?? $raw['error']));
        }

        // El paso quedó hecho del lado del emisor: se marca ANTES de tocar la
        // custodia, para que un fallo del vault no haga repetir el PUT.
        $this->mergeProvisioning($companyId, ['fiscalApplied' => true]);

        // Recién ahora se persiste: si el emisor no lo aceptó, no hay nada que
        // custodiar. Y un fallo de custodia no puede tirar el alta — el emisor
        // ya tiene el CSC; lo que se pierde es poder reaplicarlo solo.
        if ((string) ($fiscal['cscSecret'] ?? '') !== '') {
            try {
                FiscalSecretStore::storeCscSecret($companyId, (string) $fiscal['cscSecret']);
            } catch (\RuntimeException $e) {
                error_log('[EInvoiceProvisioning] el CSC se aplicó al emisor pero NO quedó en custodia para company '
                    . $companyId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Payload de `PUT /api/Tenant`. Se arma en un solo lugar porque ese
     * endpoint REEMPLAZA el tenant: cualquier caller que mande un subconjunto
     * (por ejemplo "solo el CSC") le borraría al emisor el RUC y la razón
     * social. Los dos callers —el alta y `saveCsc()`— pasan por acá.
     *
     * @param array{ruc:string,razonSocial:string,nombreFantasia:string} $company
     * @param array<string,mixed> $fiscal
     * @return array<string,mixed>
     */
    private function buildTenantPayload(int $tenantId, array $company, array $fiscal, string $cscSecret): array
    {
        $tenant = [
            'Id'          => $tenantId,
            'Ruc'         => $company['ruc'],
            // Razón social del padrón — NUNCA el nombre de fantasía: SIFEN la
            // valida contra el RUC (ver companyFiscal()).
            'Name'        => $company['razonSocial'],
            'TenancyName' => $company['nombreFantasia'],
        ];
        if (isset($fiscal['taxpayerType'])) {
            $tenant['TaxpayerType'] = (int) $fiscal['taxpayerType'];
        }
        if (isset($fiscal['regimeId'])) {
            $tenant['RegimeId'] = (int) $fiscal['regimeId'];
        }
        // CSC de SIFEN (producción). NO es obligatorio para dar de alta el
        // emisor (manual §2.1: "no requerido en modo desarrollo") pero sí para
        // emitir en producción — el QR del KuDE se firma con él. Por eso el
        // alta lo acepta vacío y la UI lo reclama antes de operar en prod.
        if (!empty($fiscal['cscId'])) {
            $tenant['IdCSCProduccion'] = (string) $fiscal['cscId'];
        }
        if ($cscSecret !== '') {
            $tenant['CSCProduccion'] = $cscSecret;
        }
        if (!empty($fiscal['infoAdicional'])) {
            $tenant['InformacionAdicional'] = (string) $fiscal['infoAdicional'];
        }

        return $tenant;
    }

    /**
     * Re-sube el certificado en custodia si el emisor todavía no lo tiene.
     *
     * Es lo que vuelve ÚTIL la custodia y no solo un depósito: reprovisionar
     * un emisor (o migrarlo mañana a otro proveedor) deja de exigirle al
     * comercio que vaya a buscar el `.pfx` otra vez. Si no hay certificado
     * guardado, no pasa nada — el paso es opcional y el comercio lo sube desde
     * la pantalla cuando quiera.
     *
     * Nunca aborta el provisioning: un emisor SIN certificado sigue siendo un
     * emisor válidamente creado. Lo que falla acá se avisa por `last_error`.
     */
    private function ensureCertApplied(string $companyId, string $environment, string $login, string $bearer, int $tenantId): void
    {
        if ($this->checkpoint($companyId, 'certUploaded')) {
            return;
        }

        $cert = FiscalSecretStore::readCertificate(
            $companyId,
            'Re-aplicar el certificado de firma al emisor durante el provisioning'
        );
        if ($cert === null) {
            return;
        }

        $raw = $this->provider->uploadCert(
            $environment,
            $login,
            $bearer,
            $tenantId,
            $cert['certBase64'],
            $cert['certPassword']
        );
        if (empty($raw['Success'] ?? $raw['success'] ?? false)) {
            // Sin excepción: el mensaje del proveedor puede traer el detalle
            // del certificado, y este paso no es condición del alta.
            error_log('[EInvoiceProvisioning] no se pudo re-aplicar el certificado en custodia para company '
                . $companyId . ': ' . (string) ($raw['Error'] ?? $raw['error'] ?? 'sin detalle'));
            return;
        }

        $this->mergeProvisioning($companyId, ['certUploaded' => true]);
    }

    private function ensureActivityCreated(string $companyId, array $fiscal, string $environment, string $login, string $bearer, int $tenantId): void
    {
        if ($this->checkpoint($companyId, 'activityCreated')) {
            return;
        }

        $raw = $this->provider->createActivity(
            $environment,
            $login,
            $bearer,
            $tenantId,
            (int) $fiscal['actividadCodigo'],
            (string) $fiscal['actividadNombre']
        );
        if (empty($raw['Success'] ?? $raw['success'] ?? true) && !empty($raw['Error'] ?? $raw['error'] ?? '')) {
            throw new \RuntimeException('No se pudo registrar la actividad económica: ' . (string) ($raw['Error'] ?? $raw['error']));
        }

        $this->mergeProvisioning($companyId, ['activityCreated' => true]);
    }

    /**
     * Un timbrado de Factomate POR CAJA: cada caja es un punto de expedición
     * y la emisión usa el timbrado de la caja de la venta (ver
     * EInvoiceService::stampIdForRegister). Checkpoint POR caja
     * (`provisioning.stampRegisters`): agregar una caja nueva y re-provisionar
     * crea solo el timbrado que falta.
     *
     * Tras crear, se releen los timbrados del proveedor y se persiste el mapa
     * registerId → {fc, nc} (ids de BranchDocumentType por tipo de documento)
     * en `provisioning.stampMap`, matcheando por número + EEE-PPP.
     *
     * @param array<int,array<string,string>> $stamps
     */
    private function ensureStampsCreated(string $companyId, array $stamps, string $environment, string $login, string $bearer): void
    {
        $row = ncmExecute('SELECT provisioning FROM einvoice_account WHERE companyid = ?', [$companyId]);
        $prov = json_decode((string) ($row['provisioning'] ?? '{}'), true);
        $done = is_array($prov['stampRegisters'] ?? null) ? $prov['stampRegisters'] : [];

        foreach ($stamps as $stamp) {
            if (!empty($done[$stamp['registerId']])) {
                continue;
            }
            $raw = $this->provider->createStamp($environment, $login, $bearer, [
                // FC (1) + NC (5): los dos tipos que Punto emite. Timbrado SIN
                // sucursal (BranchId opcional, manual §5) — el mapeo
                // sucursales↔outlets queda para una fase posterior.
                'documentTypeIds' => [1, 5],
                'stablishment'    => $stamp['establecimiento'],
                'expeditionPoint' => $stamp['puntoExpedicion'],
                'stampNumber'     => $stamp['numero'],
                'stampDate'       => $stamp['fechaInicio'],
                'currentNumber'   => 1,
                'serie'           => '',
            ]);
            if (empty($raw['Success'] ?? $raw['success'] ?? true) && !empty($raw['Error'] ?? $raw['error'] ?? '')) {
                throw new \RuntimeException(
                    "No se pudo registrar el timbrado de la caja {$stamp['name']}: " . (string) ($raw['Error'] ?? $raw['error'])
                );
            }
            $done[$stamp['registerId']] = true;
            $this->mergeProvisioning($companyId, ['stampRegisters' => $done]);
        }

        // Mapa registerId → ids de timbrado del proveedor, matcheando por
        // número + establecimiento + punto (el POST no devuelve los ids de
        // los BranchDocumentType creados de forma confiable — alta múltiple).
        $remote = $this->provider->stamps($environment, $login, $bearer);
        $items = $remote['Items'] ?? $remote['items'] ?? [];
        $map = [];
        foreach ($stamps as $stamp) {
            foreach ((array) $items as $item) {
                if (!is_array($item) || !empty($item['Deleted'] ?? $item['deleted'] ?? null)) {
                    continue;
                }
                $matches = (string) ($item['StampNumber'] ?? '') === $stamp['numero']
                    && (string) ($item['Stablishment'] ?? '') === $stamp['establecimiento']
                    && (string) ($item['ExpeditionPoint'] ?? '') === $stamp['puntoExpedicion'];
                if (!$matches) {
                    continue;
                }
                $docType = (int) ($item['DocumentTypeId'] ?? 0);
                $key = $docType === 5 ? 'nc' : 'fc';
                $map[$stamp['registerId']][$key] = $item['Id'] ?? null;
            }
        }
        $this->mergeProvisioning($companyId, ['stampMap' => $map]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Valida y normaliza el formulario legal. Los mensajes nombran el campo
     * como lo ve el comercio, no como lo llama Factomate.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    private function validateForm(array $form): array
    {
        // El formulario pide SOLO lo que Punto no tiene en ningún otro lado.
        // RUC y razón social viven en Configuración del negocio
        // (company.config.settingRUC/settingBillingName) y los timbrados en
        // las CAJAS (cada caja es un punto de expedición, context/29 §1) —
        // se leen de ahí, nunca se piden de nuevo (ver companyFiscal() y
        // registerStamps()).
        $email = trim((string) ($form['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Ingresá un email de facturación válido.');
        }

        $actCodigo = (int) ($form['actividadCodigo'] ?? 0);
        $actNombre = trim((string) ($form['actividadNombre'] ?? ''));
        if ($actCodigo <= 0 || $actNombre === '') {
            throw new \RuntimeException('La actividad económica (código y descripción) es obligatoria.');
        }

        return [
            'email'           => $email,
            'taxpayerType'    => isset($form['taxpayerType']) && is_numeric($form['taxpayerType']) ? (int) $form['taxpayerType'] : null,
            'regimeId'        => isset($form['regimeId']) && is_numeric($form['regimeId']) ? (int) $form['regimeId'] : null,
            'actividadCodigo' => $actCodigo,
            'actividadNombre' => $actNombre,
            'cscId'           => trim((string) ($form['cscId'] ?? '')),
            'cscSecret'       => (string) ($form['cscSecret'] ?? ''),
            'infoAdicional'   => trim((string) ($form['infoAdicional'] ?? '')),
        ];
    }

    /**
     * RUC y razón social del emisor — la fuente es `company.config`, un solo
     * lugar por dato (la pantalla de facturación electrónica ahora los edita
     * ahí mismo, pero escribiendo al MISMO destino).
     *
     * ── La razón social NO cae al nombre comercial ──────────────────────────
     *
     * Hasta 2026-09-06 esto hacía `$billing !== '' ? $billing : $name`, y era
     * un bug FISCAL: "Balloon Party" y "BALLOON PARTY S.A." son cosas
     * distintas, y SIFEN valida la razón social contra el padrón del RUC. Con
     * `settingBillingName` vacío, Punto registraba el emisor con el nombre de
     * fantasía y todo lo que saliera de ahí quedaba mal identificado.
     *
     * Un dato fiscal inventado es peor que un formulario incompleto: sin razón
     * social el emisor NO se provisiona. Se pide, no se adivina.
     *
     * `nombreFantasia` sí puede caer a la razón social — es el nombre
     * comercial, no lo valida nadie, y un emisor sin `TenancyName` no tiene
     * sentido. La asimetría es a propósito.
     *
     * @return array{ruc:string,razonSocial:string,nombreFantasia:string}
     * @throws \RuntimeException si faltan, con el mensaje apuntando a dónde cargarlos.
     */
    private function companyFiscal(string $companyId): array
    {
        $row = ncmExecute(
            "SELECT config->>'settingRUC' AS ruc,
                    config->>'settingBillingName' AS billing_name,
                    config->>'settingName' AS name
               FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );
        $ruc     = trim((string) ($row['ruc'] ?? ''));
        $billing = trim((string) ($row['billing_name'] ?? ''));
        $name    = trim((string) ($row['name'] ?? ''));

        if ($ruc === '') {
            throw new \RuntimeException(
                'Falta el RUC del negocio — cargalo arriba, en Datos fiscales, antes de habilitar la facturación electrónica.'
            );
        }
        if ($billing === '') {
            throw new \RuntimeException(
                'Falta la razón social del negocio, tal como figura en el padrón del RUC. '
                . 'No alcanza con el nombre comercial: la SET valida la razón social contra el padrón. '
                . 'Cargala arriba, en Datos fiscales.'
            );
        }

        return [
            'ruc'            => str_replace(' ', '', $ruc),
            'razonSocial'    => $billing,
            'nombreFantasia' => $name !== '' ? $name : $billing,
        ];
    }

    /**
     * Timbrados a provisionar — salen de las CAJAS: cada caja es un punto de
     * expedición (context/29 §1) y su timbrado (número, EEE-PPP, vigencia) se
     * administra en la caja (RegisterAdminService), no acá.
     *
     * @return array<int,array{registerId:string,name:string,numero:string,establecimiento:string,puntoExpedicion:string,fechaInicio:string}>
     * @throws \RuntimeException si ninguna caja activa tiene timbrado completo.
     */
    private function registerStamps(string $companyId): array
    {
        $rs = ncmExecute(
            'SELECT registerId, registerName, data FROM register
              WHERE companyId = ? AND registerStatus = TRUE
              ORDER BY registerName ASC',
            [$companyId],
            false,
            true
        );

        $stamps = [];
        $incomplete = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                // `$rs->fields` ya viene aplanado por Query::flattenJsonb, que
                // mergea `data` a la fila y hace unset de la columna
                // (Query.php:57). El json_decode($f['data']) que había leía
                // null → ninguna caja tenía timbrado → registerStamps() las
                // marcaba TODAS incompletas y el provisioning de FE abortaba
                // aunque estuvieran bien cargadas. Mismo bug que
                // RegisterAdminService::listAll (2026-08-04).
                $auth   = trim((string) ($f['registerInvoiceAuth'] ?? ''));
                $prefix = trim((string) ($f['registerInvoicePrefix'] ?? ''));
                $start  = trim((string) ($f['registerInvoiceAuthStart'] ?? ''));
                $name   = (string) ($f['registername'] ?? $f['registerName'] ?? '');

                if ($auth === '' && $prefix === '') {
                    $rs->MoveNext();
                    continue; // caja sin timbrado — no participa de la FE
                }
                if ($auth === '' || !preg_match('/^(\d{3})-(\d{3})$/', $prefix, $m) || $start === '') {
                    $incomplete[] = $name;
                    $rs->MoveNext();
                    continue;
                }

                $stamps[] = [
                    'registerId'      => (string) ($f['registerid'] ?? $f['registerId'] ?? ''),
                    'name'            => $name,
                    'numero'          => $auth,
                    'establecimiento' => $m[1],
                    'puntoExpedicion' => $m[2],
                    'fechaInicio'     => $start,
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        if ($incomplete !== []) {
            throw new \RuntimeException(
                'Estas cajas tienen el timbrado incompleto (número, establecimiento-punto y fecha de inicio son obligatorios): '
                . implode(', ', $incomplete) . '.'
            );
        }
        if ($stamps === []) {
            throw new \RuntimeException(
                'Ninguna caja tiene timbrado cargado. Cargá el timbrado de al menos una caja en la sección Timbrados por caja.'
            );
        }

        return $stamps;
    }

    /** @param array<string,mixed> $fiscal */
    private function stripSecrets(array $fiscal): array
    {
        unset($fiscal['cscSecret']);
        return $fiscal;
    }

    private function checkpoint(string $companyId, string $key): bool
    {
        $row = ncmExecute('SELECT provisioning FROM einvoice_account WHERE companyid = ?', [$companyId]);
        $p = json_decode((string) ($row['provisioning'] ?? '{}'), true);
        return is_array($p) && !empty($p[$key]);
    }

    /** @param array<string,mixed> $patch */
    private function mergeProvisioning(string $companyId, array $patch): void
    {
        ncmExecute(
            "UPDATE einvoice_account
                SET provisioning = COALESCE(provisioning, '{}'::jsonb) || ?::jsonb, updated_at = now()
              WHERE companyid = ?",
            [json_encode($patch), $companyId]
        );
    }

    /** @return array{0:string,1:int,2:string} [environment, tenantId, login] */
    private function requireProvisioned(string $companyId): array
    {
        $row = ncmExecute(
            'SELECT environment, factomate_tenant_id, phone_enc FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );
        $tenantId = $row['factomate_tenant_id'] ?? null;
        if (!$row || $tenantId === null || (int) $tenantId <= 0) {
            throw new \RuntimeException('Completá primero los datos del emisor.');
        }
        $login = CredentialVault::decrypt((string) ($row['phone_enc'] ?? ''));
        return [(string) ($row['environment'] ?? 'test'), (int) $tenantId, $login];
    }

    /** @return array<string,mixed> */
    private function accountFiscal(string $companyId): array
    {
        $row = ncmExecute('SELECT fiscal FROM einvoice_account WHERE companyid = ?', [$companyId]);
        $f = json_decode((string) ($row['fiscal'] ?? '{}'), true);
        return is_array($f) ? $f : [];
    }

    private function accountLogin(string $companyId): string
    {
        $row = ncmExecute('SELECT phone_enc FROM einvoice_account WHERE companyid = ?', [$companyId]);
        return CredentialVault::decrypt((string) ($row['phone_enc'] ?? ''));
    }
}
