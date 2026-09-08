<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Alta del emisor en FE-PY (el motor propio).
 *
 * Es mucho más corta que la de Factomate porque el alta de FE-PY es UNA sola
 * llamada: `POST /v1/tenants` lleva RUC, razón social, timbrado,
 * establecimientos y actividades económicas juntos. No hay `CreateExternal` +
 * `PUT /Tenant` + `POST /Activity` + `POST /BranchDocumentType` encadenados,
 * ni usuario que crear, ni contraseña generada que guardar en el vault.
 *
 * Lo que SÍ se conserva idéntico:
 *
 *   - El FORMULARIO y sus validaciones (`EInvoiceProvisioningService::
 *     validateForm()`), la lectura del RUC/razón social desde
 *     `company.config` (`companyFiscal()`) y la de los timbrados desde las
 *     CAJAS (`registerStamps()`). Son piezas del dominio de Punto, no del
 *     proveedor — ver el comentario en ese archivo.
 *   - El protocolo de CHECKPOINTS sobre `einvoice_account.provisioning`, con
 *     claves propias (`fepyTenantCreated`, `fepyCscApplied`,
 *     `fepyCertUploaded`) para que las de Factomate no se pisen si un emisor
 *     alguna vez cambia de motor. Un alta que se corta a la mitad se retoma
 *     donde quedó: `POST /v1/tenants` no es idempotente por header (su
 *     `Idempotency-Key` solo está cableada en emisión y eventos), así que un
 *     reintento a ciegas dependería de interpretar el 409 por RUC duplicado
 *     que devuelven — el checkpoint es más barato y más seguro.
 *   - Que el CERTIFICADO y el CSC salgan de la CUSTODIA (`FiscalSecretStore`),
 *     no de un formulario. Es lo que vuelve útil la custodia y no un
 *     depósito: reprovisionar —o migrar de proveedor, que es exactamente lo
 *     que este archivo hace— deja de exigirle al comercio que vuelva a buscar
 *     el `.pfx`.
 *
 * ── Lo que este camino NO puede resolver solo ────────────────────────────
 *
 *  1. **Los códigos geográficos del establecimiento.** FE-PY exige
 *     `departamento`/`distrito`/`ciudad` numéricos de SIFEN con sus
 *     descripciones. Punto guarda la dirección de la sucursal como texto
 *     libre y no tiene esos códigos. Se leen de `einvoice_account.fiscal`
 *     (`establecimientos[]`, que la pantalla fiscal tiene que empezar a
 *     pedir) y, si no están, el alta se CORTA con un mensaje que dice qué
 *     falta. No hay default: un "Asunción/Capital" cableado le declararía a
 *     la SET un domicilio que nadie verificó, y encima rompería la regla de
 *     que nada se hardcodea a Paraguay.
 *
 *  2. **Un solo timbrado por emisor.** En FE-PY el timbrado es del TENANT.
 *     Punto modela un timbrado POR CAJA (`context/29`). Mientras todas las
 *     cajas compartan número de timbrado —que es el caso normal: lo que
 *     cambia por caja es el punto de expedición, no el timbrado— el modelo
 *     encaja. Si difieren, esto corta con un error explícito en vez de
 *     elegir uno: emitir con el timbrado de otra caja es exactamente el
 *     escenario de numeración duplicada que `context/29` existe para impedir.
 */
final class FePyProvisioningService
{
    private FePyProvider $provider;
    private FePySession $session;

    public function __construct(?FePyProvider $provider = null)
    {
        $this->provider = $provider ?? new FePyProvider();
        $this->session  = new FePySession();
    }

    /**
     * Crea (o retoma) el emisor en FE-PY. Idempotente por checkpoint.
     *
     * @param array<string,mixed> $form Mismo shape que el alta de Factomate.
     * @return array<string,mixed> La cuenta (`EInvoiceService::getAccount`).
     * @throws \RuntimeException con mensaje en castellano apto para el operador.
     */
    public function provision(string $companyId, array $form): array
    {
        // El env del MOTOR PROPIO no es el default global de FE (ese gobierna
        // a Factomate y sigue en 'test' hasta que existan sus credenciales de
        // prod). FE-PY nació validado EN PRODUCCIÓN: sus emisores se crean
        // con env 'prod' salvo override explícito en platform_config
        // (integration.fepy.env) — hallazgo del 2026-09-08: el alta por
        // defaultEnvironment() creó un tenant 'test' que hubo que purgar.
        require_once __DIR__ . '/../Admin/PlatformConfig.php';
        $cfg = \PlatformConfig::get('integration.fepy', []);
        $environment = in_array(($cfg['env'] ?? ''), ['test', 'prod'], true) ? $cfg['env'] : 'prod';

        // El BORRADOR crudo se persiste ANTES de cualquier throw — mismo
        // motivo que en el camino de Factomate (incidente Balloon Party
        // 2026-09-06): un alta que falla en la validación no puede tirar todo
        // lo que el comercio tipeó.
        $this->upsertFiscal($companyId, $environment, EInvoiceProvisioningService::stripSecrets($form));

        $fiscal = EInvoiceProvisioningService::validateForm($form);
        $this->upsertFiscal($companyId, $environment, EInvoiceProvisioningService::stripSecrets($fiscal));

        try {
            $company = EInvoiceProvisioningService::companyFiscal($companyId);
            $stamps  = EInvoiceProvisioningService::registerStamps($companyId);

            $this->ensureTenantCreated($companyId, $environment, $fiscal, $company, $stamps, $form);
            $this->ensureCscApplied($companyId, $environment, $fiscal);
            $this->ensureCertApplied($companyId, $environment);

            // Verificación final: se relee el emisor del motor y se persiste
            // `emitter` + `stamp` + `status`. Reusa `testConnection()`, que es
            // provider-agnóstico desde el factory — llama a `userInfo()` y
            // `stamps()`, que FePyProvider sí responde.
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
     * Sube el certificado en custodia al emisor. Punto de entrada público
     * porque la pantalla fiscal lo llama por separado cuando el comercio
     * carga un `.pfx` nuevo sobre un emisor ya creado.
     */
    public function uploadCert(string $companyId, string $certBase64, string $certPassword): array
    {
        if (trim($certBase64) === '' || $certPassword === '') {
            throw new \RuntimeException('Falta el archivo del certificado o su contraseña.');
        }

        [$tenantRef, $environment] = $this->session->identity($companyId);
        $this->pushCertificate($companyId, $environment, $tenantRef, $certBase64, $certPassword);

        // El ORDEN importa y es el mismo que en Factomate: primero el
        // proveedor, después la custodia. Guardar un `.pfx` que el motor
        // rechazó (contraseña equivocada, certificado vencido, RUC que no es
        // el del emisor) dejaría la UI diciendo "cargado" sobre algo que no
        // sirve para firmar.
        FiscalSecretStore::storeCertificate($companyId, $certBase64, $certPassword);
        EInvoiceProvisioningService::mergeProvisioning($companyId, ['fepyCertUploaded' => true]);

        return (new EInvoiceService())->getAccount($companyId);
    }

    /** Guarda el CSC en custodia y lo aplica al emisor. */
    public function saveCsc(string $companyId, string $cscId, string $cscSecret): array
    {
        $cscId = trim($cscId);
        if ($cscId === '' || $cscSecret === '') {
            throw new \RuntimeException('Falta el identificador o el código del CSC.');
        }

        [$tenantRef, $environment] = $this->session->identity($companyId);
        $this->provider->setCsc($environment, $tenantRef, $this->session->getBearer($companyId), $cscId, $cscSecret);

        FiscalSecretStore::storeCscSecret($companyId, $cscSecret);
        EInvoiceProvisioningService::mergeProvisioning($companyId, ['fepyCscApplied' => true]);

        return (new EInvoiceService())->getAccount($companyId);
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
        if (EInvoiceProvisioningService::checkpoint($companyId, 'fepyTenantCreated')) {
            return;
        }

        $timbrado = self::singleStampNumber($stamps);

        $payload = [
            // `externalId`: nuestro companyId. FE-PY lo guarda y lo indexa
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
            // para siempre. Sale de EINVOICE_DEFAULT_ENVIRONMENT, que es
            // global de Punto y no elección del comercio.
            'env'             => $environment,
        ];

        $created = $this->provider->createTenant($environment, $this->session->getBearer($companyId), $payload);

        ncmExecute(
            'UPDATE einvoice_account SET provider_tenant_ref = ?, updated_at = now() WHERE companyid = ?',
            [$created['tenantRef'], $companyId]
        );
        EInvoiceProvisioningService::mergeProvisioning($companyId, ['fepyTenantCreated' => true]);
    }

    /**
     * CSC desde la custodia. Es OPCIONAL: sin él el emisor se crea igual y
     * puede firmar en test — lo que no puede es producir el QR válido del
     * KuDE en producción. Por eso no aborta el alta, avisa por `last_error`.
     */
    private function ensureCscApplied(string $companyId, string $environment, array $fiscal): void
    {
        if (EInvoiceProvisioningService::checkpoint($companyId, 'fepyCscApplied')) {
            return;
        }

        $cscId = trim((string) ($fiscal['cscId'] ?? ''));
        $secret = $fiscal['cscSecret'] ?? null;
        if (!is_string($secret) || $secret === '') {
            // El formulario no lo trajo: se busca en custodia (un
            // reprovisioning no vuelve a pedirlo).
            $secret = FiscalSecretStore::readCscSecret($companyId, 'provisioning fepy');
        }
        if ($cscId === '' || !is_string($secret) || $secret === '') {
            return; // Nada que aplicar. No es un error.
        }

        [$tenantRef] = $this->session->identity($companyId);
        $this->provider->setCsc($environment, $tenantRef, $this->session->getBearer($companyId), $cscId, $secret);

        FiscalSecretStore::storeCscSecret($companyId, $secret);
        EInvoiceProvisioningService::mergeProvisioning($companyId, ['fepyCscApplied' => true]);
    }

    /**
     * Certificado desde la custodia. Igual que en Factomate: NUNCA aborta el
     * provisioning —un emisor sin certificado sigue siendo un emisor
     * válidamente creado, y el comercio lo sube después desde la pantalla— y
     * lo que falla se avisa por `last_error`.
     */
    private function ensureCertApplied(string $companyId, string $environment): void
    {
        if (EInvoiceProvisioningService::checkpoint($companyId, 'fepyCertUploaded')) {
            return;
        }

        $stored = FiscalSecretStore::readCertificate($companyId, 'provisioning fepy');
        if ($stored === null) {
            return;
        }

        [$tenantRef] = $this->session->identity($companyId);
        try {
            $this->pushCertificate($companyId, $environment, $tenantRef, $stored['certBase64'], $stored['certPassword']);
            EInvoiceProvisioningService::mergeProvisioning($companyId, ['fepyCertUploaded' => true]);
        } catch (\RuntimeException $e) {
            error_log('[FePyProvisioning] no se pudo aplicar el certificado en custodia: ' . $e->getMessage());
            ncmExecute(
                'UPDATE einvoice_account SET last_error = ?, updated_at = now() WHERE companyid = ?',
                [mb_substr('El certificado en custodia no se pudo aplicar: ' . $e->getMessage(), 0, 500), $companyId]
            );
        }
    }

    /**
     * Traduce el certificado de la custodia (base64, que es como lo guarda
     * `FiscalSecretStore`) a los BYTES que espera el multipart de FE-PY.
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

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Upsert de la fila local con `provider = 'fepy'`. Es el checkpoint raíz:
     * existe ANTES de la primera llamada al motor, así que un alta cortada se
     * puede retomar. Un `status = 'ok'` nunca se degrada — mismo criterio que
     * el upsert de Factomate.
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

        // El factory cachea `provider` por request: sin esto, el resto de
        // ESTE mismo request seguiría creyendo que la cuenta es de Factomate.
        EInvoiceProviderFactory::forget($companyId);
    }

    /**
     * El timbrado del emisor, que en FE-PY es UNO SOLO para todo el tenant.
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
            foreach (['direccion', 'departamento', 'departamentoDescripcion', 'distrito', 'distritoDescripcion', 'ciudad', 'ciudadDescripcion'] as $field) {
                if (trim((string) ($row[$field] ?? '')) === '') {
                    $missing[] = $field;
                }
            }
            if ($missing !== []) {
                $faltan[] = $code . ' (falta ' . implode(', ', $missing) . ')';
                continue;
            }

            $out[] = [
                'codigo'                  => $code,
                'direccion'               => (string) $row['direccion'],
                'numeroCasa'              => (string) ($row['numeroCasa'] ?? '0'),
                'departamento'            => (int) $row['departamento'],
                'departamentoDescripcion' => (string) $row['departamentoDescripcion'],
                'distrito'                => (int) $row['distrito'],
                'distritoDescripcion'     => (string) $row['distritoDescripcion'],
                'ciudad'                  => (int) $row['ciudad'],
                'ciudadDescripcion'       => (string) $row['ciudadDescripcion'],
                'telefono'                => (string) ($row['telefono'] ?? ''),
                'denominacion'            => (string) ($row['denominacion'] ?? ''),
            ];
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
     * principal), igual que en la constancia de RUC y que en el camino de
     * Factomate: `normalizeActivities()` ya lo garantiza.
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
}
