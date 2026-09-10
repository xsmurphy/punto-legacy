<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * F7 — alta del emisor de facturación electrónica (context/28 §Onboarding).
 *
 * El comercio NO re-tipea nada que Punto ya tenga: el RUC y la razón social
 * salen de Configuración del negocio (companyFiscal) y los timbrados de las
 * CAJAS — cada caja es un punto de expedición (context/29 §1) y su timbrado
 * se administra en la caja (registerStamps). El formulario de facturación
 * electrónica solo pide lo que no existe en otro lado: actividad económica,
 * tipo de contribuyente, régimen, establecimientos con sus códigos SIFEN,
 * email de facturación y CSC.
 *
 * ── Por qué el nombre es agnóstico y la implementación no ─────────────────
 *
 * Hoy hay un solo motor (FE-PY) y esta clase habla directamente con él. El
 * nombre NO lo dice a propósito: los endpoints (`api/v1/einvoice.php`,
 * `api/v1/ai/execute.php`) piden "dar de alta el emisor", no "dar de alta el
 * emisor en tal motor". Es la misma razón por la que se conserva la interfaz
 * `EInvoiceProvider` con una sola implementación — el día que haya un segundo
 * motor, lo que cambia es el cuerpo de estos métodos, no quién los llama.
 *
 * ── El alta es UNA llamada, pero igual lleva checkpoints ──────────────────
 *
 * `POST /v1/tenants` lleva RUC, razón social, timbrado, establecimientos y
 * actividades económicas juntos. Aun así cada paso persiste su checkpoint en
 * `einvoice_account.provisioning` y `provision()` retoma desde el primero que
 * falte: el alta no es idempotente por header (la `Idempotency-Key` del motor
 * solo está cableada en emisión y eventos), así que un reintento a ciegas
 * dependería de interpretar el 409 por RUC duplicado. El checkpoint es más
 * barato y más seguro.
 *
 *   1. `POST /v1/tenants`  → `provider_tenant_ref` + `fepyTenantCreated`
 *   2. CSC                 → `fepyCscApplied` (opcional: sin él se emite en
 *      test, lo que no se puede es producir el QR válido del KuDE en prod)
 *   3. certificado         → `fepyCertUploaded`, desde la custodia
 *   4. verificación final  → `testConnection()` persiste emitter/stamp/status
 *
 * Las CLAVES de checkpoint conservan el prefijo `fepy` porque son DATO
 * PERSISTIDO de emisores que ya están dados de alta: renombrarlas haría que
 * cada uno de ellos se creyera sin provisionar y volviera a intentar el alta
 * contra un RUC que el motor ya tiene.
 *
 * ── Custodia del certificado y del CSC (owner, 2026-09-06) ────────────────
 *
 * La regla anterior era que el `.pfx` y el `CSCProduccion` pasaran al motor y
 * se descartaran. El owner la revirtió por LOCK-IN: pedirle el certificado de
 * nuevo a cada comercio para migrar de motor equivale a no poder migrar
 * nunca. Ahora se guardan CIFRADOS, en `FiscalSecretStore` — que es la única
 * puerta a esas columnas y audita cada lectura en `tenant_audit`. Ver
 * context/28 §Custodia y la mig 195.
 *
 * Lo que NO cambió: ni el certificado, ni su contraseña, ni el secreto del CSC
 * vuelven al frontend ni tocan un log. En `fiscal` sigue quedando el espejo
 * del formulario SIN secretos, para re-mostrar en la UI y reanudar.
 *
 * ── Lo que este camino NO puede resolver solo ─────────────────────────────
 *
 *  1. **Los códigos geográficos del establecimiento.** El motor exige
 *     `departamento`/`distrito`/`ciudad` numéricos de SIFEN con sus
 *     descripciones. Punto guarda la dirección de la sucursal como texto
 *     libre y no tiene esos códigos. Se leen de `einvoice_account.fiscal`
 *     (`establecimientos[]`, que la pantalla fiscal pide) y, si no están, el
 *     alta se CORTA con un mensaje que dice qué falta. No hay default: un
 *     "Asunción/Capital" cableado le declararía a la SET un domicilio que
 *     nadie verificó, y encima rompería la regla de que nada se hardcodea a
 *     Paraguay.
 *
 *  2. **Un solo timbrado por emisor.** Para el motor el timbrado es del
 *     TENANT. Punto modela un timbrado POR CAJA (`context/29`). Mientras
 *     todas las cajas compartan número de timbrado —que es el caso normal: lo
 *     que cambia por caja es el punto de expedición, no el timbrado— el
 *     modelo encaja. Si difieren, esto corta con un error explícito en vez de
 *     elegir uno: emitir con el timbrado de otra caja es exactamente el
 *     escenario de numeración duplicada que `context/29` existe para impedir.
 */
final class EInvoiceProvisioningService
{
    private FePyProvider $provider;
    private FePySession $session;

    public function __construct(?FePyProvider $provider = null)
    {
        $this->provider = $provider ?? new FePyProvider();
        $this->session  = new FePySession();
    }

    /**
     * Crea (o retoma) el emisor. Idempotente por checkpoint.
     *
     * @param array<string,mixed> $form Ver validateForm() para el shape.
     * @return array<string,mixed> La cuenta (`EInvoiceService::getAccount`).
     * @throws \RuntimeException con mensaje en castellano apto para el operador.
     */
    public function provision(string $companyId, array $form): array
    {
        $environment = self::environment();

        // El BORRADOR crudo se persiste ANTES de cualquier throw — validación,
        // lo que sea. Sin esto, un alta que fallaba en la validación tiraba
        // TODO lo tipeado: el usuario recargaba la página y el formulario
        // volvía vacío (incidente Balloon Party 2026-09-06, donde el fallo ni
        // siquiera era de este form sino del país en blanco). El shape crudo
        // del form ES el shape que hidrata el formulario
        // (`initial={account.fiscal}`), así que la reanudación funciona igual
        // que con el fiscal validado; el upsert de más abajo lo reescribe
        // normalizado apenas la validación pasa.
        $this->upsertFiscal($companyId, $environment, self::stripSecrets($form));

        $fiscal = self::validateForm($form);
        $this->upsertFiscal($companyId, $environment, self::stripSecrets($fiscal));

        try {
            $company = self::companyFiscal($companyId);
            $stamps  = self::registerStamps($companyId);

            $this->ensureTenantCreated($companyId, $environment, $fiscal, $company, $stamps, $form);
            $this->ensureCscApplied($companyId, $environment, $fiscal);
            $this->ensureCertApplied($companyId, $environment);

            // Verificación final: se relee el emisor del motor y se persiste
            // `emitter` + `stamp` + `status`. Reusa `testConnection()`, que
            // resuelve el motor por el factory y llama a `userInfo()` y
            // `stamps()` — y que además empuja el LOGO del comercio al emisor
            // (`syncEmitterLogo()`), así que el que recién nace ya sale con la
            // marca en su KuDE sin que este método tenga que ocuparse.
            $svc = new EInvoiceService();
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
     * Certificado de firma: se sube al motor y —desde el cambio de decisión de
     * 2026-09-06— queda además en custodia cifrada (`FiscalSecretStore`), para
     * poder reconfigurar la emisión sin volver a pedírselo al comercio. Nunca
     * se loguea ni vuelve al frontend.
     *
     * Punto de entrada público porque la pantalla fiscal lo llama por separado
     * cuando el comercio carga un `.pfx` nuevo sobre un emisor ya creado.
     *
     * @throws \RuntimeException
     */
    public function uploadCert(string $companyId, string $certBase64, string $certPassword): array
    {
        if (trim($certBase64) === '' || $certPassword === '') {
            throw new \RuntimeException('Falta el archivo del certificado o su contraseña.');
        }

        [$tenantRef, $environment] = $this->session->identity($companyId);
        $this->pushCertificate($companyId, $environment, $tenantRef, $certBase64, $certPassword);

        // El ORDEN importa: primero el motor, después la custodia. Guardar un
        // `.pfx` que el motor rechazó (contraseña equivocada, certificado
        // vencido, RUC que no es el del emisor) dejaría la UI diciendo
        // "cargado" sobre algo que no sirve para firmar.
        FiscalSecretStore::storeCertificate($companyId, $certBase64, $certPassword);
        self::mergeProvisioning($companyId, ['fepyCertUploaded' => true]);

        return $this->refreshReadiness($companyId);
    }

    /**
     * Borra el certificado que Punto tiene en custodia. Es SU certificado: si
     * lo pide, se borra.
     *
     * NO lo quita del motor — el comercio sigue facturando igual (ver
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
     * ninguna forma de cargarlo (`ensureCscApplied` ya está checkpointeado y
     * no vuelve a correr).
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

        [$tenantRef, $environment] = $this->session->identity($companyId);
        $this->provider->setCsc($environment, $tenantRef, $this->session->getBearer($companyId), $cscId, $cscSecret);

        // El emisor ya lo tiene: el id se persiste en el espejo del formulario
        // igual que cualquier otro dato no secreto del alta.
        $fiscal = $this->accountFiscal($companyId);
        $fiscal['cscId'] = $cscId;
        ncmExecute(
            'UPDATE einvoice_account SET fiscal = ?::jsonb, updated_at = now() WHERE companyid = ?',
            [json_encode(self::stripSecrets($fiscal), JSON_UNESCAPED_UNICODE), $companyId]
        );

        FiscalSecretStore::storeCscSecret($companyId, $cscSecret);
        self::mergeProvisioning($companyId, ['fepyCscApplied' => true]);

        return $this->refreshReadiness($companyId);
    }

    /**
     * Estado del certificado del emisor, para la prueba que la pantalla fiscal
     * ofrece antes de emitir.
     *
     * No es una consulta al motor: su verificación del certificado ocurre AL
     * SUBIRLO (parsea el PKCS#12, rechaza vencidos y exige que el RUC de
     * adentro sea el del emisor), así que lo que se responde es el resultado
     * de esa validación, que es el dato real. Preguntar de nuevo no agrega
     * información y sí una llamada que puede fallar por su lado.
     */
    public function testSet(string $companyId): array
    {
        $hasCert = self::checkpoint($companyId, 'fepyCertUploaded');

        return [
            'ok' => $hasCert,
            'message' => $hasCert
                ? 'El certificado está cargado y fue validado por el motor de facturación al subirlo.'
                : 'Todavía no hay certificado cargado en el motor de facturación.',
        ];
    }

    /**
     * Vuelve a preguntarle al motor si el emisor está listo, y persiste esa
     * respuesta.
     *
     * Existe porque `last_error` y `status` son una FOTO del último chequeo, y
     * cargar un certificado o un CSC cambia justamente lo que esa foto estaba
     * retratando. Sin esto, el comercio sube el `.pfx`, la pantalla lo marca
     * "Cargado" y al lado sigue el cartel "No hay certificado cargado" del
     * chequeo anterior — le pasó al owner el 2026-09-08 con tres minutos de
     * diferencia entre el error y la carga que lo resolvía. Verificado en la
     * fila: los dos checkpoints en `true` y el `last_error` de antes intacto.
     *
     * Se RECHEQUEA en vez de limpiar el error a mano: el motor es la
     * autoridad sobre si el emisor puede emitir, y blanquear el campo sería
     * afirmar que está listo sin haberlo preguntado — que es el mismo error de
     * base, al revés.
     *
     * NO propaga la falla. El secreto YA se aplicó del otro lado y ya se
     * guardó en custodia: si el rechequeo no sale (el motor caído, un timeout),
     * la carga fue igual de exitosa y hacerla fallar acá mandaría al comercio a
     * subir de nuevo un certificado que el motor ya tiene. Queda el estado
     * anterior, que es exactamente lo que había antes de este arreglo.
     */
    private function refreshReadiness(string $companyId): array
    {
        $svc = new EInvoiceService();
        try {
            $svc->testConnection($companyId);
        } catch (\Throwable $e) {
            error_log('[EInvoiceProvisioning] rechequeo tras aplicar un secreto: ' . $e->getMessage());
        }
        return $svc->getAccount($companyId);
    }

    // ── Pasos (cada uno con su checkpoint) ───────────────────────────────

    /**
     * `POST /v1/tenants`. Persiste el UUID devuelto en `provider_tenant_ref`
     * (mig 206) ANTES de marcar el checkpoint: si el proceso muere entre
     * ambas cosas, el reintento vuelve a crear el tenant y el 409 por RUC
     * duplicado lo delata — mientras que marcar el checkpoint sin la
     * referencia dejaría una cuenta que se cree provisionada y no puede
     * emitir.
     *
     * @param array<string,mixed> $fiscal
     * @param array{ruc:string,razonSocial:string,nombreFantasia:string} $company
     * @param array<int,array<string,mixed>> $stamps Timbrados por caja (`registerStamps`).
     * @param array<string,mixed> $form Crudo — de ahí salen los establecimientos con códigos SIFEN.
     */
    private function ensureTenantCreated(
        string $companyId,
        string $environment,
        array $fiscal,
        array $company,
        array $stamps,
        array $form
    ): void {
        if (self::checkpoint($companyId, 'fepyTenantCreated')) {
            return;
        }

        $timbrado = self::singleStampNumber($stamps);

        $payload = [
            // `externalId`: nuestro companyId. El motor lo guarda y lo indexa
            // pero NO expone búsqueda por él, así que no reemplaza a
            // `provider_tenant_ref` — sirve para que soporte pueda cruzar un
            // emisor de su lado con un comercio del nuestro sin preguntar.
            'externalId'      => $companyId,
            'ruc'             => $company['ruc'],
            // Razón social del padrón, NUNCA el nombre de fantasía: SIFEN la
            // valida contra el RUC (ver companyFiscal()).
            'razonSocial'     => $company['razonSocial'],
            'nombreFantasia'  => $company['nombreFantasia'],
            'timbradoNumero'  => $timbrado['numero'],
            'timbradoFecha'   => $timbrado['fechaInicio'],
            // 1 = persona física, 2 = persona jurídica (rango que acepta su
            // Zod). El formulario ya lo pide; sin él no se adivina.
            'tipoContribuyente' => self::requireInt($fiscal['taxpayerType'] ?? null, 1, 2, 'tipo de contribuyente'),
            'tipoRegimen'       => self::requireInt($fiscal['regimeId'] ?? null, 1, 15, 'régimen tributario'),
            'establecimientos'  => self::establecimientos($form, $fiscal, $stamps),
            'actividadesEconomicas' => self::actividades($fiscal),
            // `env` decide contra qué SIFEN firma el emisor y se fija ACÁ,
            // para siempre. Sale de la configuración de plataforma, que es
            // global de Punto y no elección del comercio (ver environment()).
            'env'             => $environment,
        ];

        $created = $this->provider->createTenant($environment, $this->session->getBearer($companyId), $payload);

        ncmExecute(
            'UPDATE einvoice_account SET provider_tenant_ref = ?, updated_at = now() WHERE companyid = ?',
            [$created['tenantRef'], $companyId]
        );
        self::mergeProvisioning($companyId, ['fepyTenantCreated' => true]);

    }

    /**
     * CSC desde el formulario o desde la custodia. Es OPCIONAL: sin él el
     * emisor se crea igual y puede firmar en test — lo que no puede es
     * producir el QR válido del KuDE en producción. Por eso no aborta el alta.
     */
    private function ensureCscApplied(string $companyId, string $environment, array $fiscal): void
    {
        if (self::checkpoint($companyId, 'fepyCscApplied')) {
            return;
        }

        $cscId = trim((string) ($fiscal['cscId'] ?? ''));
        $secret = $fiscal['cscSecret'] ?? null;
        if (!is_string($secret) || $secret === '') {
            // El formulario no lo trajo: se busca en custodia (un
            // reprovisioning no vuelve a pedirlo).
            $secret = FiscalSecretStore::readCscSecret(
                $companyId,
                'Aplicar el CSC del emisor (alta o reanudación del provisioning)'
            );
        }
        if ($cscId === '' || !is_string($secret) || $secret === '') {
            return; // Nada que aplicar. No es un error.
        }

        [$tenantRef] = $this->session->identity($companyId);
        $this->provider->setCsc($environment, $tenantRef, $this->session->getBearer($companyId), $cscId, $secret);

        FiscalSecretStore::storeCscSecret($companyId, $secret);
        self::mergeProvisioning($companyId, ['fepyCscApplied' => true]);
    }

    /**
     * Certificado desde la custodia. Es lo que vuelve ÚTIL la custodia y no
     * solo un depósito: reprovisionar un emisor deja de exigirle al comercio
     * que vaya a buscar el `.pfx` otra vez.
     *
     * NUNCA aborta el provisioning —un emisor sin certificado sigue siendo un
     * emisor válidamente creado, y el comercio lo sube después desde la
     * pantalla— y lo que falla se avisa por `last_error`.
     */
    private function ensureCertApplied(string $companyId, string $environment): void
    {
        if (self::checkpoint($companyId, 'fepyCertUploaded')) {
            return;
        }

        $stored = FiscalSecretStore::readCertificate(
            $companyId,
            'Re-aplicar el certificado de firma al emisor durante el provisioning'
        );
        if ($stored === null) {
            return;
        }

        [$tenantRef] = $this->session->identity($companyId);
        try {
            $this->pushCertificate($companyId, $environment, $tenantRef, $stored['certBase64'], $stored['certPassword']);
            self::mergeProvisioning($companyId, ['fepyCertUploaded' => true]);
        } catch (\RuntimeException $e) {
            error_log('[EInvoiceProvisioning] no se pudo aplicar el certificado en custodia: ' . $e->getMessage());
            ncmExecute(
                'UPDATE einvoice_account SET last_error = ?, updated_at = now() WHERE companyid = ?',
                [mb_substr('El certificado en custodia no se pudo aplicar: ' . $e->getMessage(), 0, 500), $companyId]
            );
        }
    }

    /**
     * Traduce el certificado de la custodia (base64, que es como lo guarda
     * `FiscalSecretStore`) a los BYTES que espera el multipart del motor.
     *
     * El `.p12` decodificado vive solo en la variable de esta llamada: no se
     * escribe a disco (por eso el multipart se arma a mano y no con CURLFile)
     * ni se loguea.
     */
    private function pushCertificate(string $companyId, string $environment, string $tenantRef, string $certBase64, string $certPassword): void
    {
        $binary = base64_decode(trim($certBase64), true);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('El certificado guardado no se pudo decodificar — volvé a subir el archivo .pfx.');
        }

        $this->provider->uploadCertificate(
            $environment,
            $tenantRef,
            $this->session->getBearer($companyId),
            $binary,
            $certPassword
        );
    }

    // ── Helpers del alta ─────────────────────────────────────────────────

    /**
     * Entorno donde se provisionan los emisores nuevos — global, no elección
     * del tenant.
     *
     * El default es `prod` porque el motor nació validado EN PRODUCCIÓN; el
     * override explícito vive en `platform_config` (`integration.fepy.env`).
     * Hallazgo del 2026-09-08: cuando esto salía del default global de FE, el
     * alta creó un tenant 'test' que hubo que purgar.
     */
    private static function environment(): string
    {
        require_once __DIR__ . '/../Admin/PlatformConfig.php';
        $cfg = \PlatformConfig::get('integration.fepy', []);

        return in_array(($cfg['env'] ?? ''), ['test', 'prod'], true) ? (string) $cfg['env'] : 'prod';
    }

    /**
     * Upsert de la fila local. Es el checkpoint raíz: existe ANTES de la
     * primera llamada al motor, así que un alta cortada se puede retomar. Un
     * `status = 'ok'` nunca se degrada — reintentar el form de un emisor ya
     * provisionado actualiza sus datos, no lo devuelve a "alta en proceso".
     *
     * @param array<string,mixed> $fiscal Ya sin secretos.
     */
    private function upsertFiscal(string $companyId, string $environment, array $fiscal): void
    {
        $json = json_encode($fiscal, JSON_UNESCAPED_UNICODE);

        $existing = ncmExecute('SELECT companyid FROM einvoice_account WHERE companyid = ?', [$companyId]);
        if (!$existing) {
            ncmExecute(
                "INSERT INTO einvoice_account (companyid, provider, environment, status, fiscal)
                 VALUES (?, 'fepy', ?, 'provisioning', ?::jsonb)",
                [$companyId, $environment, $json]
            );
        } else {
            ncmExecute(
                "UPDATE einvoice_account
                    SET provider = 'fepy',
                        fiscal = ?::jsonb,
                        status = CASE WHEN status = 'ok' THEN 'ok' ELSE 'provisioning' END,
                        updated_at = now()
                  WHERE companyid = ?",
                [$json, $companyId]
            );
        }

        // El factory cachea `provider` por request: sin esto, el resto de ESTE
        // mismo request seguiría con lo que la caché resolvió antes de que la
        // fila existiera.
        EInvoiceProviderFactory::forget($companyId);
    }

    /**
     * El timbrado del emisor, que para el motor es UNO SOLO para todo el
     * tenant.
     *
     * Punto lo modela por caja. Mientras todas las cajas compartan número
     * —el caso normal: lo que cambia por caja es el punto de expedición— hay
     * un único timbrado y esto lo devuelve. Si difieren, corta: elegir uno
     * haría que las cajas del otro timbrado emitieran contra un talonario que
     * no es el suyo.
     *
     * @param array<int,array<string,mixed>> $stamps
     * @return array{numero:string,fechaInicio:string}
     */
    private static function singleStampNumber(array $stamps): array
    {
        $byNumber = [];
        foreach ($stamps as $stamp) {
            $numero = trim((string) ($stamp['numero'] ?? ''));
            if ($numero === '') {
                continue;
            }
            $byNumber[$numero] = trim((string) ($stamp['fechaInicio'] ?? ''));
        }

        if ($byNumber === []) {
            throw new \RuntimeException(
                'Ninguna caja tiene timbrado cargado. Cargá el timbrado de al menos una caja en la sección Timbrados por caja.'
            );
        }
        if (count($byNumber) > 1) {
            throw new \RuntimeException(
                'Las cajas tienen timbrados distintos (' . implode(', ', array_keys($byNumber)) . ') y este motor de ' .
                'facturación admite un solo timbrado por emisor. Unificá el número de timbrado de las cajas — lo que ' .
                'cambia por caja es el punto de expedición, no el timbrado.'
            );
        }

        $numero = (string) array_key_first($byNumber);
        $fecha  = $byNumber[$numero];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
            throw new \RuntimeException(
                "La fecha de inicio del timbrado $numero no tiene el formato AAAA-MM-DD — corregila en la caja."
            );
        }

        return ['numero' => $numero, 'fechaInicio' => $fecha];
    }

    /**
     * `establecimientos[]` del alta.
     *
     * Los CÓDIGOS de establecimiento salen de las cajas (`EEE` del prefijo
     * `EEE-PPP`), que es la fuente correcta y no hay que pedírsela a nadie.
     * Lo que Punto NO tiene son los códigos geográficos de SIFEN
     * (departamento/distrito/ciudad) ni la dirección estructurada: se leen de
     * `fiscal.establecimientos` / `form.establecimientos`, indexados por
     * código, y si falta el del establecimiento que las cajas declaran, se
     * corta nombrándolo. Ver el punto 1 del docblock de la clase.
     *
     * @param array<string,mixed> $form
     * @param array<string,mixed> $fiscal
     * @param array<int,array<string,mixed>> $stamps
     * @return array<int,array<string,mixed>>
     */
    private static function establecimientos(array $form, array $fiscal, array $stamps): array
    {
        $declared = [];
        foreach ([$form['establecimientos'] ?? null, $fiscal['establecimientos'] ?? null] as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($source as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = str_pad(trim((string) ($row['codigo'] ?? '')), 3, '0', STR_PAD_LEFT);
                if ($code !== '000') {
                    $declared[$code] = $row;
                }
            }
        }

        $codes = [];
        foreach ($stamps as $stamp) {
            $code = trim((string) ($stamp['establecimiento'] ?? ''));
            if ($code !== '') {
                $codes[str_pad($code, 3, '0', STR_PAD_LEFT)] = true;
            }
        }
        if ($codes === []) {
            throw new \RuntimeException(
                'Ninguna caja declara un establecimiento (el EEE de EEE-PPP) — no se puede dar de alta el emisor.'
            );
        }

        $out = [];
        $faltan = [];
        foreach (array_keys($codes) as $code) {
            $row = $declared[$code] ?? null;
            if (!is_array($row)) {
                $faltan[] = $code;
                continue;
            }
            $missing = [];
            // `telefono` está en la lista por SIFEN, no por el motor: el
            // emisor se crea sin él, pero termina en el XML como `dTelEmi`,
            // cuyo XSD exige 6 caracteres mínimo. Sin esto el alta sale
            // "exitosa" y la PRIMERA VENTA REAL muere con un error de
            // validación XSD que no dice nada del alta — le pasó al owner el
            // 2026-09-08. Un dato que el documento fiscal necesita se pide al
            // dar de alta, no se descubre facturando.
            foreach (['direccion', 'telefono', 'departamento', 'departamentoDescripcion', 'distrito', 'distritoDescripcion', 'ciudad', 'ciudadDescripcion'] as $field) {
                if (trim((string) ($row[$field] ?? '')) === '') {
                    $missing[] = $field;
                }
            }
            if ($missing !== []) {
                $faltan[] = $code . ' (falta ' . implode(', ', $missing) . ')';
                continue;
            }

            $establecimiento = [
                'codigo'                  => $code,
                'direccion'               => (string) $row['direccion'],
                // "0" es la convención de SIFEN para "sin número", no un
                // default inventado: la dirección sin altura existe y el campo
                // no admite vacío.
                'numeroCasa'              => trim((string) ($row['numeroCasa'] ?? '')) !== ''
                    ? trim((string) $row['numeroCasa'])
                    : '0',
                'departamento'            => (int) $row['departamento'],
                'departamentoDescripcion' => (string) $row['departamentoDescripcion'],
                'distrito'                => (int) $row['distrito'],
                'distritoDescripcion'     => (string) $row['distritoDescripcion'],
                'ciudad'                  => (int) $row['ciudad'],
                'ciudadDescripcion'       => (string) $row['ciudadDescripcion'],
                'telefono'                => (string) ($row['telefono'] ?? ''),
                // El email del establecimiento es OPCIONAL para el motor pero
                // el formulario lo pide, así que si vino viaja: es la casilla
                // que la SET publica para ESE local, y no tiene por qué ser la
                // de facturación del emisor. Vacío no se manda — su Zod lo
                // valida como email y un string vacío rebota el alta entera.
                'email'                   => trim((string) ($row['email'] ?? '')),
                'denominacion'            => (string) ($row['denominacion'] ?? ''),
            ];
            if ($establecimiento['email'] === '') {
                unset($establecimiento['email']);
            }
            $out[] = $establecimiento;
        }

        if ($faltan !== []) {
            throw new \RuntimeException(
                'Faltan los datos fiscales del establecimiento ' . implode('; ', $faltan) . '. ' .
                'Cargá dirección y los códigos de departamento, distrito y ciudad de SIFEN en la pantalla de ' .
                'facturación electrónica — no se dan de alta con valores por defecto.'
            );
        }

        return $out;
    }

    /**
     * `actividadesEconomicas[]`. El ORDEN es el dato (la primera es la
     * principal), igual que en la constancia de RUC:
     * `normalizeActivities()` ya lo garantiza.
     *
     * @param array<string,mixed> $fiscal
     * @return array<int,array{codigo:string,descripcion:string}>
     */
    private static function actividades(array $fiscal): array
    {
        $out = [];
        foreach ((array) ($fiscal['actividades'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            // `codigo` como STRING: su Zod pide `z.string().min(1)`, y un
            // entero lo rechaza.
            $out[] = [
                'codigo'      => (string) ($row['codigo'] ?? ''),
                'descripcion' => (string) ($row['nombre'] ?? ''),
            ];
        }
        if ($out === []) {
            throw new \RuntimeException('La actividad económica principal (código y descripción) es obligatoria.');
        }
        return $out;
    }

    /** Entero obligatorio dentro de un rango, con mensaje que nombra el campo. */
    private static function requireInt(mixed $value, int $min, int $max, string $what): int
    {
        if (!is_numeric($value)) {
            throw new \RuntimeException("Falta el $what — es obligatorio para dar de alta el emisor.");
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            throw new \RuntimeException("El $what ($int) está fuera del rango admitido ($min a $max).");
        }
        return $int;
    }

    // ── El FORMULARIO fiscal ─────────────────────────────────────────────
    //
    // `validateForm()` y sus normalizadores son PÚBLICOS porque son el
    // contrato del formulario, no mecánica del alta: los endpoints los
    // nombran como la validación semántica del payload (`api/v1/einvoice.php`)
    // y el arnés `einvoice_provision_form_test.php` los ejercita sin base de
    // datos. Lo que valida es el formulario del COMERCIO —no lo que un motor
    // pide— y por eso sobrevive intacto a un cambio de motor.

    /**
     * Valida y normaliza el formulario legal. Los mensajes nombran el campo
     * como lo ve el comercio, no como lo llama el motor.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public static function validateForm(array $form): array
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

        return [
            'email'         => $email,
            'taxpayerType'  => isset($form['taxpayerType']) && is_numeric($form['taxpayerType']) ? (int) $form['taxpayerType'] : null,
            'regimeId'      => isset($form['regimeId']) && is_numeric($form['regimeId']) ? (int) $form['regimeId'] : null,
            'actividades'   => self::normalizeActivities($form),
            'establecimientos' => self::normalizeEstablecimientos($form),
            'cscId'         => trim((string) ($form['cscId'] ?? '')),
            'cscSecret'     => (string) ($form['cscSecret'] ?? ''),
            'infoAdicional' => trim((string) ($form['infoAdicional'] ?? '')),
        ];
    }

    /**
     * Establecimientos fiscales declarados en el formulario (dirección
     * estructurada + códigos geográficos de SIFEN), indexados por su código
     * EEE — el mismo que las CAJAS declaran en su punto de expedición.
     *
     * Lo que devuelve `validateForm()` es lo que se persiste en
     * `einvoice_account.fiscal` —el espejo que hidrata la pantalla al reanudar
     * un alta—, y es una WHITELIST: la clave que no nombra, desaparece. Hasta
     * 2026-09-08 no nombraba `establecimientos`: el borrador crudo se guardaba
     * bien y el upsert siguiente, con el fiscal normalizado, lo BORRABA. El
     * comercio tipeaba dirección y códigos geográficos, el alta cortaba por
     * cualquier otro motivo, y al volver a la pantalla no estaban más.
     *
     * NO valida obligatoriedad: cuáles establecimientos hacen falta lo sabe
     * quien lee los timbrados de las cajas (`establecimientos()`), que corta
     * nombrando el que falta. Acá se normaliza y se conserva, nada más — un
     * formulario a medias tiene que poder guardarse, que es justamente el bug
     * que esto cierra.
     *
     * Los códigos geográficos quedan como INT o `null`, nunca como string
     * vacío disfrazado de número: `null` es "el comercio todavía no lo
     * cargó", y es lo que la pantalla vuelve a mostrar vacío.
     *
     * @param array<string,mixed> $form
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeEstablecimientos(array $form): array
    {
        $raw = $form['establecimientos'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $out    = [];
        $vistos = [];
        foreach ($raw as $fila) {
            if (!is_array($fila)) {
                continue;
            }
            $codigo = trim((string) ($fila['codigo'] ?? ''));
            if ($codigo === '') {
                continue; // Sin código no se puede atar a ninguna caja.
            }
            $codigo = str_pad($codigo, 3, '0', STR_PAD_LEFT);
            if (in_array($codigo, $vistos, true)) {
                continue;
            }
            $vistos[] = $codigo;

            $out[] = [
                'codigo'                  => $codigo,
                'direccion'               => trim((string) ($fila['direccion'] ?? '')),
                'numeroCasa'              => trim((string) ($fila['numeroCasa'] ?? '')),
                'departamento'            => self::intOrNull($fila['departamento'] ?? null),
                'departamentoDescripcion' => trim((string) ($fila['departamentoDescripcion'] ?? '')),
                'distrito'                => self::intOrNull($fila['distrito'] ?? null),
                'distritoDescripcion'     => trim((string) ($fila['distritoDescripcion'] ?? '')),
                'ciudad'                  => self::intOrNull($fila['ciudad'] ?? null),
                'ciudadDescripcion'       => trim((string) ($fila['ciudadDescripcion'] ?? '')),
                'telefono'                => trim((string) ($fila['telefono'] ?? '')),
                'email'                   => trim((string) ($fila['email'] ?? '')),
                'denominacion'            => trim((string) ($fila['denominacion'] ?? '')),
            ];
        }

        return $out;
    }

    /** Entero del formulario, o null si el campo vino vacío o no es numérico. */
    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Lista de actividades económicas, normalizada y en orden: la PRIMERA es
     * la principal. El orden ES el dato — no hay bandera aparte, igual que en
     * la constancia de RUC.
     *
     * Retrocompat de ENTRADA: un formulario viejo (o el espejo `fiscal` de una
     * cuenta anterior a 2026-09-06, que se re-manda al reanudar un alta a
     * medias) trae el par suelto `actividadCodigo`/`actividadNombre`; se lee
     * como lista de una. No se migra nada en la base: `fiscal` se reescribe
     * entero en cada guardado, así que la fila queda con el shape nuevo la
     * primera vez que el comercio guarda.
     *
     * Se deduplica por código porque dos filas con el mismo código serían el
     * mismo rubro declarado dos veces en el alta.
     *
     * @param array<string,mixed> $form
     * @return array<int,array{codigo:int,nombre:string}>
     * @throws \RuntimeException
     */
    public static function normalizeActivities(array $form): array
    {
        $raw = [];
        if (isset($form['actividades']) && is_array($form['actividades'])) {
            foreach ($form['actividades'] as $fila) {
                if (is_array($fila)) {
                    $raw[] = $fila;
                }
            }
        }
        if ($raw === []) {
            $raw[] = [
                'codigo' => $form['actividadCodigo'] ?? 0,
                'nombre' => $form['actividadNombre'] ?? '',
            ];
        }

        $actividades = [];
        $vistos = [];
        foreach ($raw as $fila) {
            $codigo = (int) ($fila['codigo'] ?? 0);
            $nombre = trim((string) ($fila['nombre'] ?? ''));
            if ($codigo <= 0 || $nombre === '') {
                throw new \RuntimeException(
                    'Cada actividad económica necesita código y descripción. '
                    . 'Completá las que falten o quitá las filas que sobren.'
                );
            }
            if (in_array($codigo, $vistos, true)) {
                continue;
            }
            $vistos[] = $codigo;
            $actividades[] = ['codigo' => $codigo, 'nombre' => $nombre];
        }

        if ($actividades === []) {
            throw new \RuntimeException('La actividad económica principal (código y descripción) es obligatoria.');
        }

        return $actividades;
    }

    /** @param array<string,mixed> $fiscal */
    public static function stripSecrets(array $fiscal): array
    {
        unset($fiscal['cscSecret']);
        return $fiscal;
    }

    // ── Lo que el comercio NO re-tipea ───────────────────────────────────

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
     * comercial, no lo valida nadie, y un emisor sin nombre comercial no tiene
     * sentido. La asimetría es a propósito.
     *
     * @return array{ruc:string,razonSocial:string,nombreFantasia:string}
     * @throws \RuntimeException si faltan, con el mensaje apuntando a dónde cargarlos.
     */
    private static function companyFiscal(string $companyId): array
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
    private static function registerStamps(string $companyId): array
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

    // ── Protocolo de checkpoints sobre `einvoice_account.provisioning` ───

    private static function checkpoint(string $companyId, string $key): bool
    {
        $row = ncmExecute('SELECT provisioning FROM einvoice_account WHERE companyid = ?', [$companyId]);
        $p = json_decode((string) ($row['provisioning'] ?? '{}'), true);
        return is_array($p) && !empty($p[$key]);
    }

    /** @param array<string,mixed> $patch */
    private static function mergeProvisioning(string $companyId, array $patch): void
    {
        ncmExecute(
            "UPDATE einvoice_account
                SET provisioning = COALESCE(provisioning, '{}'::jsonb) || ?::jsonb, updated_at = now()
              WHERE companyid = ?",
            [json_encode($patch), $companyId]
        );
    }

    /** @return array<string,mixed> */
    private function accountFiscal(string $companyId): array
    {
        $row = ncmExecute('SELECT fiscal FROM einvoice_account WHERE companyid = ?', [$companyId]);
        $f = json_decode((string) ($row['fiscal'] ?? '{}'), true);
        return is_array($f) ? $f : [];
    }
}
