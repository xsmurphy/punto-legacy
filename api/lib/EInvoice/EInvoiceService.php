<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

use Punto\Api\Notifications\NotificationOutbox;

/**
 * Orquestación de la facturación electrónica del comercio: verificar el
 * emisor, emitir, reconciliar el estado fiscal contra SIFEN, anular y reemitir
 * — todo sobre el outbox `einvoice_document`
 * (context/28-facturacion-electronica-plan.md).
 *
 * Habla contra `EInvoiceProvider`, nunca contra un motor concreto.
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
    /**
     * Proveedor INYECTADO. Solo lo setean los arneses, que simulan la API
     * para poder ejercitar el camino real sin emitir documentos fiscales de
     * verdad. Cuando está, gana sobre el factory — si no, un arnés no podría
     * simular nada.
     *
     * En producción es null y el motor lo elige `providerFor()` POR COMPANY
     * (`einvoice_account.provider`). No puede ser una propiedad resuelta en el
     * constructor: esta clase se construye una vez y se usa para varias
     * companies (drenaje del outbox, reconciliación), así que la primera le
     * fijaría el motor a todas las
     * demás — la misma fuga que el docblock de `EInvoiceProvider` explica
     * para `$environment`.
     */
    private ?EInvoiceProvider $injectedProvider;

    public function __construct(?EInvoiceProvider $provider = null)
    {
        $this->injectedProvider = $provider;
    }

    /** Proveedor de esta company (o el inyectado por el arnés). */
    private function providerFor(string $companyId): EInvoiceProvider
    {
        return $this->injectedProvider ?? EInvoiceProviderFactory::for($companyId);
    }

    /** Credenciales de esta company (la sesión que le corresponde a su motor). */
    private function sessionFor(string $companyId): EInvoiceSession
    {
        return EInvoiceProviderFactory::sessionFor($companyId);
    }

    /** Shape estable aunque no haya cuenta configurada — el frontend no rama por null. */
    /**
     * Estado de la cuenta para el PANEL del comercio. White-label (F7): acá
     * NUNCA sale una credencial del motor — ni usuario, ni teléfono, ni el
     * nombre del motor como dato prominente. El comercio ve su estado fiscal
     * (datos del emisor, timbrado, certificado), no la integración.
     */
    public function getAccount(string $companyId): array
    {
        $row = ncmExecute(
            'SELECT provider, provider_tenant_ref, environment, status, emitter, stamp, stamp_synced_at,
                    last_check_at, last_error, fiscal, provisioning,
                    config AS account_config
               FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );

        if (!$row) {
            return [
                'configured'     => false,
                'provisioned'    => false,
                'status'         => 'unconfigured',
                'fiscal'         => [],
                'certUploaded'   => false,
                'certStored'     => false,
                'certUploadedAt' => null,
                'cscStored'      => false,
                'cscUpdatedAt'   => null,
                'emitter'        => [],
                'stamp'          => [],
                'stampSyncedAt'  => null,
                'lastCheckAt'    => null,
                'lastError'      => null,
                'config'         => [],
            ];
        }

        $provisioning = $this->decodeJsonb($row['provisioning'] ?? null);
        // Custodia del certificado y del CSC (mig 195): lo ÚNICO que sale de
        // esas columnas hacia afuera es si hay algo guardado y desde cuándo.
        // El contenido no vuelve al frontend nunca — se lee solo server-side
        // por FiscalSecretStore, que audita cada lectura.
        $secrets = FiscalSecretStore::status($companyId);

        return [
            'configured'   => true,
            // provisioned = el emisor existe del lado del MOTOR. La UI decide
            // con esto si muestra el formulario de alta o el estado.
            //
            // Se mira SOLO `provider_tenant_ref`, la referencia del motor
            // vigente. Antes había un fallback al id del motor anterior y eso
            // dejaba la pantalla SIN botón de alta para un emisor que todavía
            // no estaba dado de alta acá (Balloon Party, 2026-09-08).
            'provisioned'  => trim((string) ($row['provider_tenant_ref'] ?? '')) !== '',
            'status'       => (string) ($row['status'] ?? 'unconfigured'),
            // Espejo del formulario legal (sin secretos — ver
            // EInvoiceProvisioningService::stripSecrets).
            'fiscal'       => $this->decodeJsonb($row['fiscal'] ?? null),
            // `certUploaded` = el PROVEEDOR lo tiene. `certStored` = PUNTO lo
            // tiene en custodia. Son cosas distintas y las dos importan: el
            // comercio puede borrar la custodia sin dejar de facturar.
            'certUploaded'   => !empty($provisioning['certUploaded']),
            'certStored'     => $secrets['certStored'],
            'certUploadedAt' => $secrets['certUploadedAt'],
            'cscStored'      => $secrets['cscStored'],
            'cscUpdatedAt'   => $secrets['cscUpdatedAt'],
            'emitter'      => $this->decodeJsonb($row['emitter'] ?? null),
            // Timbrado vigente cacheado de BranchDocumentType/Get — la
            // fuente real del correlativo (lo lleva el proveedor).
            'stamp'         => $this->decodeJsonb($row['stamp'] ?? null),
            'stampSyncedAt' => $row['stamp_synced_at'] ?? null,
            'lastCheckAt'   => $row['last_check_at'] ?? null,
            'lastError'     => $row['last_error'] ?? null,
            'config'        => $this->decodeJsonb($row['account_config'] ?? null),
        ];
    }

    /**
     * Config de emisión del comercio (autoIssue, onlyWithTaxId,
     * paymentMethodMap, defaultPaymentMethodCode). F7: las CREDENCIALES ya
     * no son input del usuario — el alta del emisor la hace
     * EInvoiceProvisioningService con la credencial admin de Punto; este
     * método solo toca `config`.
     *
     * `$config` se MERGEA clave por clave sobre la guardada (`null` borra la
     * clave): la pantalla tiene varias secciones que guardan por separado, y
     * un PUT destructivo hacía que tocar un switch de emisión borrara el
     * `paymentMethodMap` entero.
     *
     * @throws \RuntimeException si la cuenta no existe todavía.
     */
    public function saveConfig(string $companyId, array $config): array
    {
        // `config AS account_config`: flattenJsonb aplana toda columna llamada
        // `config` y la vuelve inutilizable (ver nota en context/28 §Schema).
        $existing = ncmExecute(
            'SELECT config AS account_config FROM einvoice_account WHERE companyid = ?',
            [$companyId]
        );
        if (!$existing) {
            throw new \RuntimeException('Completá primero los datos del emisor.');
        }

        $merged = $this->mergeConfig($this->decodeJsonb($existing['account_config'] ?? null), $config);
        ncmExecute(
            'UPDATE einvoice_account SET config = ?::jsonb, updated_at = now() WHERE companyid = ?',
            [json_encode($merged, JSON_UNESCAPED_UNICODE), $companyId]
        );

        return $this->getAccount($companyId);
    }

    /**
     * Verifica que el emisor esté LISTO para facturar y baja su estado real.
     *
     * La verificación ES el readiness del motor: los chequeos que su API
     * valida (tenant activo, RUC con DV, certificado y su vigencia, CSC,
     * numeración) más `unverifiable` —lo que SIFEN recién valida al emitir—,
     * que se muestra como advertencia y no como error.
     *
     * Nunca deja la excepción escapar sin persistir el intento: el operador
     * tiene que ver "Error de autenticación" en vez de un 500 mudo.
     */
    public function testConnection(string $companyId): array
    {
        $account = ncmExecute('SELECT companyid FROM einvoice_account WHERE companyid = ?', [$companyId]);
        if (!$account) {
            throw new \RuntimeException('Configurá la facturación electrónica antes de probar la conexión.');
        }

        try {
            $bearer = $this->sessionFor($companyId)->getBearer($companyId);
            [$tenantRef, $environment] = $this->emitterIdentity($companyId);

            $provider  = $this->providerFor($companyId);
            $readiness = $provider->readiness($tenantRef, $bearer);
            $emitter   = $provider->userInfo($environment, $tenantRef, $bearer);

            // ── El ambiente lo manda el MOTOR, no nuestra columna ───────
            //
            // `einvoice_account.environment` es una COPIA, y una copia se
            // desincroniza: la de Balloon Party decía `test` mientras el
            // tenant en FE-PY estaba en `prod` y emitía facturas fiscales de
            // verdad (2026-09-09). Nadie lo notó porque ese campo NO elige el
            // host, así que la divergencia no rompía la emisión: solo le
            // mentía al comercio en pantalla sobre si sus documentos valían.
            //
            // El `env` del tenant es el único que decide contra qué SIFEN se
            // emite, así que se lee de ahí y se baja a nuestra fila en cada
            // verificación. No se elimina la columna porque el bootstrap y las
            // pantallas la leen sin poder llamar al motor; queda como
            // PROYECCIÓN derivada, con un solo escritor.
            $remoteEnv = trim((string) ($emitter['env'] ?? ''));
            if ($remoteEnv !== '' && $remoteEnv !== $environment) {
                error_log(sprintf(
                    '[EInvoiceService] ambiente desincronizado en %s: local=%s motor=%s — se adopta el del motor',
                    $companyId,
                    $environment,
                    $remoteEnv
                ));
                ncmExecute(
                    'UPDATE einvoice_account SET environment = ?, updated_at = now() WHERE companyid = ?',
                    [$remoteEnv, $companyId]
                );
                $environment = $remoteEnv;
            }

            // El logo del comercio va en el KuDE, que dibuja el motor. Acá es
            // donde CONVERGE: un emisor que ya existía cuando se subió el
            // logo, o que lo cambió, lo refleja en la próxima verificación.
            // Best-effort — ver `syncEmitterLogo()`.
            $this->syncEmitterLogo($companyId, $emitter);

            if (!$readiness['ready']) {
                $failed = array_values(array_filter($readiness['checks'], fn ($c) => empty($c['ok'])));
                $message = 'El emisor todavía no está listo: '
                    . implode('; ', array_map(
                        fn ($c) => (string) ($c['check'] ?? '?') . ' — ' . (string) ($c['detail'] ?? ''),
                        $failed
                    ));
                ncmExecute(
                    "UPDATE einvoice_account
                        SET status = 'auth_error', emitter = ?::jsonb, last_check_at = now(), last_error = ?, updated_at = now()
                      WHERE companyid = ?",
                    [json_encode($emitter + ['readiness' => $readiness], JSON_UNESCAPED_UNICODE), $message, $companyId]
                );
                return ['status' => 'auth_error', 'emitter' => $emitter, 'stamp' => [], 'lastError' => $message];
            }

            $stamp = $this->extractStamp($provider->stamps($environment, $tenantRef, $bearer));
            ncmExecute(
                "UPDATE einvoice_account
                    SET status = 'ok', emitter = ?::jsonb, stamp = ?::jsonb, stamp_synced_at = now(),
                        last_check_at = now(), last_error = NULL, updated_at = now()
                  WHERE companyid = ?",
                [
                    json_encode($emitter + ['readiness' => $readiness], JSON_UNESCAPED_UNICODE),
                    json_encode($stamp ?? [], JSON_UNESCAPED_UNICODE),
                    $companyId,
                ]
            );

            return ['status' => 'ok', 'emitter' => $emitter, 'stamp' => $stamp ?? [], 'lastError' => null];
        } catch (\Throwable $e) {
            // `$e->getMessage()` nunca incluye la API key ni un secreto del
            // emisor (el cliente HTTP los tacha antes de que toquen un log o
            // una excepción) — seguro de persistir en `last_error`, que es
            // visible en el panel.
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
     * Empuja el LOGO del comercio al emisor del motor.
     *
     * ── Por qué el motor necesita el logo ────────────────────────────────
     *
     * El KuDE lo dibuja el motor (Punto no lo renderiza — ver `kude()`), así
     * que el logo del comercio tiene que estar de SU lado o el documento que
     * recibe el comprador sale sin marca. FE-PY lo guarda en `logo_url` del
     * tenant y lo compone al renderizar.
     *
     * ── De dónde sale ────────────────────────────────────────────────────
     *
     * De `company.config->settingObj`: `logoUrl` (la URL de S3 que escribe
     * `SettingsService::uploadLogo()`), gateado por `hasLogo` y con
     * cache-bust por `logoUploadedAt`. Es la MISMA resolución que hace
     * `KudeEmailBuilder::commerce()` para el logo del email — la ruta
     * derivada del companyId que usa el legacy apunta a un archivo que no
     * existe y no se usa acá.
     *
     * El cache-bust importa doblemente: sin él, cambiar el logo dejaría al
     * motor sirviendo el anterior desde su propia caché, y además la URL no
     * cambiaría, con lo cual el guard de "¿hace falta actualizar?" de abajo
     * nunca detectaría el cambio.
     *
     * ── Dónde converge ───────────────────────────────────────────────────
     *
     * Se llama en TRES puntos y por eso un emisor viejo también converge:
     * al dar de alta el emisor (para que nazca con logo), al subir un logo
     * nuevo (para que se vea enseguida) y en cada verificación de la cuenta
     * (la red que atrapa a los que ya existían, y el reintento de las veces
     * que el motor estaba caído).
     *
     * IDEMPOTENTE: compara contra lo que el motor ya tiene y no llama si no
     * cambió. Sin esto, cada verificación sería un PATCH inútil.
     *
     * BEST-EFFORT ABSOLUTO: no lanza NUNCA. Un logo es cosmética del
     * comprobante; que el motor esté caído o rechace la URL no puede tumbar
     * la verificación de la cuenta, ni el alta del emisor, ni —mucho menos—
     * la carga de un logo desde Ajustes.
     *
     * El valor que se empuja es el que dice la BD, TAMBIÉN cuando está vacío:
     * si el comercio borró su logo, el KuDE tiene que dejar de mostrarlo. No
     * es un caso de "falta el dato" — `hasLogo` es una bandera explícita.
     *
     * @param array<string,mixed> $emitter Tenant ya leído, si el caller lo tiene
     *                                     (evita un GET de más). Vacío = se consulta.
     */
    public function syncEmitterLogo(string $companyId, array $emitter = []): void
    {
        try {
            $provider = $this->providerFor($companyId);
            $bearer   = $this->sessionFor($companyId)->getBearer($companyId);
            // Lanza si el emisor no está dado de alta — lo absorbe el catch:
            // sin tenant no hay a quién mandarle el logo, y no es un error.
            [$tenantRef, $environment] = $this->emitterIdentity($companyId);

            $desired = $this->emitterLogoUrl($companyId);

            if ($emitter === []) {
                $emitter = $provider->userInfo($environment, $tenantRef, $bearer);
            }
            $current = trim((string) ($emitter['logoUrl'] ?? $emitter['logo_url'] ?? ''));
            if ($current === $desired) {
                return;
            }

            $provider->patchTenant($environment, $tenantRef, $bearer, ['logoUrl' => $desired]);
        } catch (\Throwable $e) {
            error_log('[EInvoiceService] no se pudo sincronizar el logo del emisor de ' . $companyId . ': ' . $e->getMessage());
        }
    }

    /**
     * URL pública del logo del comercio, con cache-bust, o '' si no cargó uno.
     *
     * Sale de `company.config->settingObj` y se gatea por `hasLogo`, igual que
     * `KudeEmailBuilder::commerce()`: las dos superficies muestran el logo del
     * MISMO comercio en el MISMO documento, así que resolverlo distinto sería
     * que el KuDE y el mail que lo lleva adjunto mostraran marcas diferentes.
     * NO se usa la ruta derivada del companyId del legacy: apunta a un archivo
     * que no existe.
     */
    private function emitterLogoUrl(string $companyId): string
    {
        $row = ncmExecute(
            "SELECT config->>'settingObj' AS setting_obj FROM company WHERE companyId = ?",
            [$companyId]
        );
        $obj = json_decode((string) ($row['setting_obj'] ?? ''), true);
        $obj = is_array($obj) ? $obj : [];

        if (empty($obj['hasLogo'])) {
            return '';
        }

        return (string) (self::companyLogoUrl($obj['logoUrl'] ?? null, $obj['logoUploadedAt'] ?? null) ?? '');
    }

    /**
     * Códigos de medio de pago de SIFEN, normalizados a
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

        $bearer = $this->sessionFor($companyId)->getBearer($companyId);
        [$tenantRef, $environment] = $this->emitterIdentity($companyId);
        return $this->normalizePaymentMethods($this->providerFor($companyId)->paymentMethods($environment, $tenantRef, $bearer));
    }

    /**
     * Datos del contribuyente según el padrón que ve el emisor (F3). Devuelve
     * el payload CRUDO: la normalización a un shape de contacto de Punto vive
     * en `Contacts\TaxpayerLookupService`, que es quien decide qué hacer
     * cuando la respuesta no trae nada usable.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException si la cuenta no está conectada (status != 'ok').
     */
    /**
     * ¿El comercio tiene el emisor operativo? Pregunta barata y SIN
     * excepción, para los callers que tienen una alternativa válida cuando la
     * respuesta es que no.
     *
     * Existe por `TaxpayerLookupService`: "todavía no hay cuenta de FE" es el
     * estado NORMAL de un comercio que está justo por crearla, y descubrirlo
     * cachando la excepción de `clientByRuc()` dejaba una línea de error_log
     * por cada consulta de RUC. Un estado esperado no es un error.
     */
    public function isConnected(string $companyId): bool
    {
        $row = ncmExecute('SELECT status FROM einvoice_account WHERE companyid = ?', [$companyId]);
        return $row && (string) ($row['status'] ?? '') === 'ok';
    }

    public function clientByRuc(string $companyId, string $ruc): array
    {
        if (!$this->isConnected($companyId)) {
            throw new \RuntimeException('La cuenta de facturación electrónica no está conectada.');
        }

        $bearer = $this->sessionFor($companyId)->getBearer($companyId);
        [$tenantRef, $environment] = $this->emitterIdentity($companyId);
        return $this->providerFor($companyId)->clientByRuc($environment, $tenantRef, $bearer, $ruc);
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
     * Identidad del emisor ante el motor + su environment.
     *
     * Cómo se identifica un emisor es cosa de cada motor, así que lo resuelve
     * su sesión: en FE-PY es el UUID del tenant, que va en el path de todas
     * sus rutas.
     *
     * @return array{0: string, 1: string} [$tenantRef, $environment]
     * @throws \RuntimeException si falta (cuenta a medio provisionar).
     */
    private function emitterIdentity(string $companyId): array
    {
        return $this->sessionFor($companyId)->identity($companyId);
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
        // lógicos: un timbrado dado de baja se marca `Deleted` en vez de
        // borrarse, y facturar contra un timbrado dado de baja es
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
            "SELECT einvoicedocid, doctype, status, cdc, document_number, error_message, issued_at, attempts,
                    sifen_status, sifen_result, superseded_by, cancelled_at, numbering_mismatch
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
                    // El id hace falta para descargar el KuDE
                    // (`/v1/einvoice?resource=kude&id=`): sin él, quien
                    // consume esta lista sabe que la factura existe pero no
                    // puede pedir su PDF.
                    'id'             => (string) ($f['einvoicedocid'] ?? ''),
                    'doctype'        => (string) ($f['doctype'] ?? ''),
                    'status'         => (string) ($f['status'] ?? ''),
                    'cdc'            => $f['cdc'] ?? null,
                    'documentNumber' => $f['document_number'] ?? null,
                    'errorMessage'   => $f['error_message'] ?? null,
                    'issuedAt'       => $f['issued_at'] ?? null,
                    'attempts'       => (int) ($f['attempts'] ?? 0),
                    // Veredicto FISCAL, que es otra pregunta que `status`:
                    // `status` es el outbox de Punto (¿se mandó?),
                    // `sifenVerdict` es si SIFEN lo aceptó. Hay un caso
                    // registrado de un documento `issued` con CDC válido que
                    // SIFEN rechazó después. Ninguna pantalla puede decir
                    // "emitida" mirando solo `status`.
                    'sifenVerdict'   => self::sifenVerdict($f['sifen_status'] ?? null),
                    // POR QUÉ lo rechazó SIFEN. Solo viaja en el rechazo: en un
                    // aprobado `sifen_result` trae el acuse de éxito y mostrarlo
                    // como "motivo" confunde. Existe porque el motivo RUTEA al
                    // arreglo (context/28 §F7 R2): "RUC inválido" manda a la
                    // ficha del cliente, "timbrado vencido" a la config del
                    // emisor. Mismo tratamiento que el listado de ventas
                    // (`Reports\TransactionsService`), que ya lo mostraba — el
                    // detalle era la única superficie que no lo tenía.
                    'sifenReason'    => self::sifenVerdict($f['sifen_status'] ?? null) === 'rejected'
                        ? self::sifenReason($f['sifen_result'] ?? null)
                        : null,
                    'supersededBy'   => $f['superseded_by'] ?? null,
                    // Por qué NO se le puede entregar el KuDE, o null si sí.
                    // Lo calcula el MISMO predicado que aplica el endpoint de
                    // la caja — la pantalla no reimplementa la regla, solo la
                    // muestra. Sin esto la caja ofrecía descargar lo que el
                    // endpoint después rechaza con 409.
                    //
                    // OJO: esto es la política de la CAJA y del EMAIL
                    // (`cancelledBlocks` por default), NO la del portal, que
                    // deja pasar la anulada a propósito. Cablear el portal a
                    // este campo reintroduce ese bug.
                    'deliveryBlocker' => self::deliveryBlockerForRow($f),
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
     * seguro reintentar solo — la emisión ya pudo haber salido).
     * `status: 'stuck'` es un filtro SINTÉTICO del panel (no
     * existe en la BD): `sending` con `updated_at` de más de 15 minutos —
     * umbral arbitrario pero generoso (la emisión real tarda segundos, no
     * minutos) para no marcar como trabado un documento que el drainer
     * está procesando en este instante.
     *
     * `status: 'rejected'` es el otro filtro sintético: `sifen_status =
     * 'Rechazado'`. No es un valor de `status` — el outbox de un documento
     * rechazado por SIFEN dice 'issued', porque el envío salió bien; lo que
     * falló es el veredicto FISCAL, que es el que vale.
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
        } elseif ($status === 'rejected') {
            // Segundo filtro SINTÉTICO (como 'stuck'): el rechazo NO vive en
            // `status` —el outbox dice 'issued', se mandó bien— sino en el
            // estado FISCAL. Es el filtro que el comercio necesita: "mostrame
            // lo que SIFEN no me aceptó".
            //
            // Por CONTENIDO y no por igualdad: `sifen_status` no es un enum
            // cerrado (guarda el `dEstResField` de SIFEN cuando está, y si no
            // el StatusString libre del proveedor — ej. "FinalizadoERROR").
            // Con `= 'Rechazado'` el filtro dejaba afuera documentos que la
            // UI SÍ pinta como rechazados. Mismo criterio que `sifenVerdict()`
            // en `frontend/lib/einvoice/sifen-status.ts` — si cambia uno,
            // cambia el otro.
            $where[] = "(d.sifen_status ILIKE '%rechaz%' OR d.sifen_status ILIKE '%error%')";
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
                    d.sifen_status, d.sifen_checked_at, d.superseded_by, d.numbering_mismatch,
                    -- El bulk crudo pesa y solo se usa para sacar el motivo del
                    -- RECHAZO: para un documento aprobado no se trae.
                    CASE WHEN d.sifen_status IS NOT NULL AND d.sifen_status NOT ILIKE '%aprobad%'
                         THEN d.sifen_result END AS sifen_result,
                    t.transactionTotal AS total, t.transactionCurrency AS currency,
                    -- Para RUTEAR el arreglo de un rechazo (N2, context/28 §F7):
                    -- el motivo manda a la ficha del cliente o al timbrado de la
                    -- caja, y sin estos dos ids el panel solo podría describir
                    -- el camino en vez de linkearlo.
                    t.customerId AS contact_id, t.outletId AS outlet_id,
                    c.contactName AS client_name,
                    -- Casilla del cliente: la usa el diálogo de reenvío para
                    -- venir precargada (D8 de context/57). Editable ahí mismo,
                    -- porque el destino puede ser otro (el contador).
                    c.contactEmail AS client_email,
                    -- Estado de la ENTREGA digital (context/57 E4). Derivado del
                    -- outbox de notificaciones, no de una columna nueva en
                    -- `einvoice_document`: el documento no cambia porque se haya
                    -- mandado un mail, y duplicar el dato acá crearía dos verdades.
                    ne.sent_at   AS email_sent_at,
                    ne.pending   AS email_pending,
                    ne.failed    AS email_failed
               FROM einvoice_document d
               LEFT JOIN transaction t ON t.transactionId = d.transactionid AND t.companyId = d.companyid
               LEFT JOIN contact c ON c.contactId = t.customerId AND c.companyId = d.companyid
               LEFT JOIN LATERAL (
                    SELECT max(n.sent_at) FILTER (WHERE n.status = 'sent')      AS sent_at,
                           count(*)       FILTER (WHERE n.status = 'pending')   AS pending,
                           count(*)       FILTER (WHERE n.status = 'error')     AS failed
                      FROM notification_outbox n
                     WHERE n.companyid  = d.companyid
                       AND n.entitytype = 'einvoice_document'
                       AND n.entityid   = d.einvoicedocid
                       AND n.channel    = 'email'
               ) ne ON true
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
                    // Motivo del veredicto de SIFEN (típicamente el del
                    // RECHAZO). Sin esto la UI podía decir "rechazado" sin
                    // decir por qué — inaccionable para el comercio.
                    'sifenReason'    => self::sifenReason($f['sifen_result'] ?? null),
                    // Documento REEMPLAZADO por una reemisión (mig 201): sigue
                    // en el listado como registro, pero ya no es accionable —
                    // el panel lo pinta "Reemplazado" y no le ofrece reemitir.
                    'supersededBy'   => $f['superseded_by'] ?? null,
                    // Guard de numeración (mig 204): el documento se emitió,
                    // pero el CDC devuelto NO describe el comprobante que se
                    // imprimió. No es un error reintentable —el documento ya
                    // existe en SIFEN— así que viaja aparte de `errorMessage`:
                    // la acción que corresponde es humana, no un retry.
                    'numberingMismatch' => $f['numbering_mismatch'] ?? null,
                    'total'          => $f['total'] !== null ? (float) $f['total'] : null,
                    'currency'       => $f['currency'] ?? null,
                    'contactId'      => $f['contact_id'] ?? null,
                    'outletId'       => $f['outlet_id'] ?? null,
                    'clientName'     => $f['client_name'] ?? null,
                    'clientEmail'    => $f['client_email'] ?? null,
                    // Entrega digital (context/57): cuándo salió el último
                    // email, si hay uno en cola, y si alguno agotó los intentos.
                    'emailSentAt'    => $f['email_sent_at'] ?? null,
                    'emailPending'   => (int) ($f['email_pending'] ?? 0) > 0,
                    'emailFailed'    => (int) ($f['email_failed'] ?? 0) > 0,
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

    // ── F6 — portal de consulta del cliente final ───────────────────────

    /**
     * URL pública del portal para una venta, o `null` si esa venta no tiene
     * documento electrónico (comercio sin FE, autoIssue apagado, venta que no
     * se encoló). Se imprime en el comprobante — ver el bloque `fe_py` de las
     * plantillas de impresión.
     *
     * El token es una función de (company, transacción), así que esta URL es
     * estable y existe desde que la venta se registra: se puede imprimir sin
     * esperar a que la emisión termine. Si el comprador entra antes de que el
     * documento esté emitido, el portal le muestra "en proceso".
     */
    public function portalUrl(string $companyId, string $transactionId): ?string
    {
        $doc = ncmExecute(
            'SELECT einvoicedocid FROM einvoice_document WHERE companyid = ? AND transactionid = ? LIMIT 1',
            [$companyId, $transactionId]
        );
        if (!$doc) {
            return null;
        }

        $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        if ($base === '') {
            return null; // sin APP_URL no hay link imprimible — no se inventa un dominio
        }

        try {
            return $base . '/factura/' . PortalToken::sign($companyId, $transactionId);
        } catch (\RuntimeException $e) {
            // Sin APP_ENCRYPTION_KEY no se puede firmar. No es motivo para
            // romper la venta: se imprime el comprobante sin link.
            error_log('[EInvoiceService] portalUrl: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Datos que ve el COMPRADOR en el portal público. Devuelve `null` si esa
     * venta no tiene documento — el endpoint lo traduce a 404.
     *
     * Qué se expone y qué no: solo lo que ya está impreso en su comprobante o
     * en el KuDE (comercio emisor, fecha, total, CDC, estado fiscal). NUNCA
     * datos internos del outbox — `error_message` puede citar credenciales o
     * respuestas crudas del proveedor, `attempts`/`next_retry_at` son
     * operación del comercio, y el nombre del cliente no se devuelve porque
     * quien tiene el link no necesariamente es el titular (un ticket se
     * pierde, se fotografía, se reenvía).
     *
     * @return array<string,mixed>|null
     */
    public function portalDocument(string $companyId, string $transactionId): ?array
    {
        $row = ncmExecute(
            "SELECT d.einvoicedocid, d.doctype, d.status, d.cdc, d.document_number, d.issued_at,
                    d.cancelled_at, d.sifen_status, d.provider_response, d.numbering_mismatch,
                    t.transactionTotal AS total, t.transactionDiscount AS discount,
                    t.transactionCurrency AS currency, t.transactionDate AS sale_date,
                    COALESCE(NULLIF(co.config->>'settingName', ''), co.config->>'companyName') AS company_name,
                    co.config->'settingObj'->>'logoUrl'         AS logo_url,
                    co.config->'settingObj'->>'logoUploadedAt'  AS logo_stamp
               FROM einvoice_document d
               LEFT JOIN transaction t ON t.transactionId = d.transactionid AND t.companyId = d.companyid
               LEFT JOIN company co ON co.companyId = d.companyid
              WHERE d.companyid = ? AND d.transactionid = ?
                -- El documento ACTIVO de la venta: si hubo una reemisión por
                -- rechazo (mig 201), el reemplazado sigue existiendo como
                -- registro y el comprador nunca tiene que ver ese.
                AND d.superseded_by IS NULL
              ORDER BY d.created_at DESC LIMIT 1",
            [$companyId, $transactionId]
        );
        if (!$row) {
            return null;
        }

        $status = (string) ($row['status'] ?? '');

        // Link al QR de ekuatía (consulta pública del DE en SIFEN). Viene en la
        // respuesta cruda de `/Bulk` — es el mismo link que imprime el KuDE, así
        // que dárselo al comprador no expone nada nuevo. La lectura del shape
        // vive en UN solo lugar (`extractQrUrl`), compartida con la impresión.
        $qrUrl = self::extractQrUrl($this->decodeJsonb($row['provider_response'] ?? null));

        // Guard de numeración (mig 204): si el CDC devuelto no describe esta
        // venta, ni el CDC ni el QR salen al comprador. Un código que lo manda
        // a consultar OTRO documento en el portal de la SET es peor que no
        // darle ninguno — el comprador no tiene cómo detectar la diferencia.
        //
        // En variables LOCALES, no reescribiendo `$row`: la fila viene del
        // wrapper de BD (recordset con acceso case-insensitive, ver
        // `app/Database/Query.php`), y mutarla ahí depende de que el wrapper
        // implemente escritura — además de dejar una fila que ya no coincide
        // con lo que la BD tiene.
        $numberingMismatch = trim((string) ($row['numbering_mismatch'] ?? ''));
        $cdcForBuyer       = $row['cdc'] ?? null;
        if ($numberingMismatch !== '') {
            $cdcForBuyer = null;
            $qrUrl       = null;
        }

        return [
            'status'         => $status,
            'doctype'        => (string) ($row['doctype'] ?? ''),
            'companyName'    => $row['company_name'] ?? null,
            // Logo del COMERCIO, de donde vive DE VERDAD: `settingObj.logoUrl`
            // en la config, que es la URL pública de S3 que escribe
            // `SettingsService::uploadLogo()` — con su `logoUploadedAt` como
            // cache-bust, igual que lo consume el panel.
            //
            // La primera versión de este campo usaba
            // `/assets/80-80/0/<enc(id)>.jpg`, la ruta que arma `data.php`.
            // Eso es LEGACY y no es donde está el archivo: el portal quedaba
            // pidiendo una imagen inexistente. `null` cuando el comercio no
            // subió ninguno, y ahí el portal muestra su nombre.
            'companyLogo'    => self::companyLogoUrl($row['logo_url'] ?? null, $row['logo_stamp'] ?? null),
            // Ya filtrado por el guard de numeración de arriba.
            'cdc'            => $cdcForBuyer,
            'documentNumber' => $row['document_number'] ?? null,
            'issuedAt'       => $row['issued_at'] ?? null,
            'cancelledAt'    => $row['cancelled_at'] ?? null,
            'saleDate'       => $row['sale_date'] ?? null,
            // Mismo neto que declara el documento (ver buildSaleArrayForMapper):
            // el comprador tiene que ver lo que pagó, no el bruto de lista.
            'total'          => $row['total'] !== null
                ? (float) $row['total'] - (float) ($row['discount'] ?? 0)
                : null,
            // La moneda del DOCUMENTO, con la del comercio como respaldo.
            //
            // Antes iba `$row['currency']` crudo, y una venta sin moneda
            // explícita dejaba el portal mostrando "500.00": sin código ISO,
            // sin bandera y con dos decimales que el guaraní no tiene. El
            // formateador del front sí sabe qué monedas no llevan decimales,
            // pero no puede saberlo si no le llega ninguna.
            //
            // `resolveCurrency()` NO inventa: si la venta no la trae, usa la
            // configurada del tenant, que es la que esa venta usó de hecho.
            // Es el mismo resolver que ya alimenta el mapper del documento
            // electrónico, así que el portal y el XML dicen lo mismo.
            'currency'       => self::resolveCurrency($companyId, $row['currency'] ?? null),
            // El estado fiscal sí (el comprador tiene derecho a saber si su
            // comprobante vale), el MOTIVO del rechazo NO: es un diagnóstico
            // operativo del comercio (timbrado vencido, documento duplicado,
            // RUC no habilitado) e inaccionable para el comprador, que no
            // puede hacer nada con un código de SIFEN. Se expone solo en el
            // panel — ver documents()/`sifenReason`.
            'sifenStatus'    => $row['sifen_status'] ?? null,
            'qrUrl'          => $qrUrl,
            // Veredicto FISCAL, para que el portal no tenga que reimplementar
            // la clasificación. 'approved' | 'rejected' | 'pending'.
            'sifenVerdict'   => self::sifenVerdict($row['sifen_status'] ?? null),
            // El KuDE se ofrece solo si el documento se emitió Y SIFEN no lo
            // rechazó.
            //
            // Antes esto miraba SOLO `status`, que en un rechazo sigue siendo
            // 'issued' — o sea que el portal le ofrecía al comprador descargar
            // el PDF de una factura RECHAZADA. Es exactamente el caso que este
            // módulo documenta desde julio: el KuDE del rechazo real del
            // 2026-07-30 se descargaba igual, y ni el CDC ni el PDF prueban
            // validez fiscal. Un PDF con pinta de factura circulando por una
            // que SIFEN no aceptó es el peor documento posible en una disputa.
            //
            // 'pending' tampoco descarga: entregar un comprobante antes de
            // saber si vale contradice el criterio del owner (correcto le gana
            // a rápido, `context/28` §R5b) y el mercado tolera la espera.
            //
            // El guard de numeración (mig 204) lo bloquea por el mismo
            // motivo: el KuDE es el PDF del documento DEL PROVEEDOR, así que
            // si su número no es el que el comprador tiene impreso en su
            // ticket, ese PDF le entrega un comprobante que no reconoce como
            // suyo. El gate REAL está en `portalKude()`, abajo.
            'kudeAvailable'  => ($status === 'issued' || $status === 'cancelled')
                && $numberingMismatch === ''
                && self::sifenVerdict($row['sifen_status'] ?? null) === 'approved',
        ];
    }

    /**
     * Veredicto FISCAL a partir de `sifen_status`. 'approved' | 'rejected' |
     * 'pending'.
     *
     * `sifen_status` NO es un enum cerrado: la reconciliación guarda el
     * `dEstResField` de SIFEN ("Aprobado"/"Rechazado") cuando está, y si no cae
     * al `StatusString` libre del proveedor ("Exitoso", "FinalizadoERROR"). Por
     * eso se clasifica por CONTENIDO y no por igualdad exacta.
     *
     * DOS ESPEJOS que hay que mover juntos si esto cambia:
     *   - `documents()`, rama `status === 'rejected'` (el mismo criterio en SQL).
     *   - `frontend/lib/einvoice/sifen-status.ts` (`sifenVerdict`, el panel).
     * Si divergen, una superficie pinta como rechazado lo que otra no filtra.
     */
    private static function sifenVerdict(?string $sifenStatus): string
    {
        $s = mb_strtolower(trim((string) $sifenStatus));
        if ($s === '') {
            return 'pending';
        }
        if (str_contains($s, 'rechaz') || str_contains($s, 'error')) {
            return 'rejected';
        }
        if (str_contains($s, 'aprobad') || str_contains($s, 'exitoso')) {
            return 'approved';
        }
        return 'pending';
    }

    // ── Entrega digital del KuDE (context/57) ───────────────────────────

    /**
     * ¿SIFEN dijo "Aprobado"? MÁS ESTRICTO que `sifenVerdict()` a propósito, y
     * la diferencia es la decisión central del plan (D3 de context/57).
     *
     * `sifenVerdict()` también da 'approved' con "Exitoso", que es el
     * `StatusString` del PROVEEDOR, no el veredicto de SIFEN — sirve para
     * pintar la pantalla, no para decidir que se le manda un documento fiscal
     * al comprador. Está comprobado contra DEV (context/28 §CRÍTICO) que un
     * documento con `Success: true`, CDC válido y KuDE descargable puede
     * haber sido RECHAZADO por SIFEN. El único campo que dice la verdad es el
     * `dEstResField` de SIFEN, y sólo con él se dispara el envío automático.
     *
     * El reenvío MANUAL (D8) sí usa el criterio ancho: ahí hay un operador
     * mirando la pantalla que ya dice "Aprobado por SIFEN", y negarle la
     * acción sobre lo que la propia UI le afirma sería incoherente.
     */
    private static function isSifenApproved(?string $sifenStatus): bool
    {
        return str_contains(mb_strtolower(trim((string) $sifenStatus)), 'aprobad');
    }

    /**
     * Encola la entrega del KuDE por email — E2 de context/57. BEST-EFFORT:
     * nunca lanza. Lo llama la reconciliación, y una notificación que no se
     * pudo encolar no puede tirar abajo la corrida que está escribiendo el
     * estado fiscal de todos los tenants.
     *
     * D7 — SIN EMAIL NO PASA NADA. Si el cliente de la venta no tiene casilla
     * cargada (o la venta es a consumidor final, sin cliente), no se encola y
     * no es un error: el canal digital es opcional y el ticket con QR al
     * portal ya cumplió la obligación de puesta a disposición (context/49).
     */
    private function enqueueKudeEmail(string $companyId, string $docId): void
    {
        try {
            // Guard de numeración (mig 204) — ANTES de resolver la casilla.
            //
            // Este es el canal más peligroso para la falla que el guard
            // detecta: se dispara solo, con la aprobación de SIFEN, y no
            // necesita que el comprador haga nada. Si el CDC del documento no
            // es el del comprobante que se le entregó, el mail le lleva la
            // factura de OTRA operación a la casilla del cliente equivocado.
            //
            // Y la aprobación de SIFEN no lo cubre: SIFEN valida su propio
            // registro, no nuestro invariante de que el número emitido sea el
            // que salió impreso en el ticket. Un documento puede estar
            // perfectamente aprobado Y tener el número cambiado.
            $flagged = ncmExecute(
                'SELECT numbering_mismatch FROM einvoice_document WHERE einvoicedocid = ? AND companyid = ?',
                [$docId, $companyId]
            );
            if ($flagged && trim((string) ($flagged['numbering_mismatch'] ?? '')) !== '') {
                error_log('[EInvoiceService] KuDE NO enviado por email (' . $docId .
                    '): el CDC no coincide con el comprobante impreso');
                return;
            }

            $email = $this->saleContactEmail($companyId, $docId);
            if ($email === '') {
                return;
            }

            NotificationOutbox::enqueue(
                $companyId,
                NotificationOutbox::ENTITY_EINVOICE_DOCUMENT,
                $docId,
                NotificationOutbox::CHANNEL_EMAIL,
                $email,
                ['origin' => 'sifen-approved']
            );
        } catch (\Throwable $e) {
            error_log('[EInvoiceService] no se pudo encolar el KuDE de ' . $docId . ': ' . $e->getMessage());
        }
    }

    /**
     * Reenvío MANUAL desde el panel (D8 de context/57): a la misma dirección o
     * a otra ("mandámelo a la del contador"). También cubre al cliente que
     * cargó su email DESPUÉS de la venta, para quien el envío automático nunca
     * se encoló.
     *
     * A diferencia de `enqueueKudeEmail()`, acá los motivos SÍ suben como
     * excepción: hay un operador esperando saber si su click hizo algo.
     *
     * No manda nada en el acto — encola. El envío real lo hace el drainer en
     * la próxima corrida (≤5 min), que es lo que le da reintento y trazabilidad;
     * mandar inline dejaría el fallo sin cola y sin registro.
     *
     * @param string $recipient vacío = la casilla del cliente de la venta.
     * @return array{queued:bool,recipient:string} `queued:false` = ya había un envío encolado a esa dirección.
     * @throws \RuntimeException con el motivo listo para mostrarle al operador.
     */
    /**
     * ¿Este documento se le puede ENTREGAR al comprador? `null` si sí; si no,
     * el CÓDIGO del motivo.
     *
     * ── Por qué es un código y no un mensaje ────────────────────────────────
     *
     * El predicado es UNO solo y las AUDIENCIAS son tres. Al comprador no se
     * le dice el motivo (R1 de `context/28` §F7: la discrepancia de numeración
     * o el rechazo de SIFEN son compliance ENTRE el comercio y su proveedor, y
     * el comprador no puede hacer nada con eso); al operador sí, porque está
     * mirando la pantalla y tiene que poder actuar. Devolver el código deja
     * que cada canal escriba para SU lector sin duplicar la REGLA.
     *
     * ── Por qué existe ──────────────────────────────────────────────────────
     *
     * Estas cinco condiciones vivían copiadas en `sendKude()` (email) y
     * `portalKude()` (portal del cliente) — los dos canales que le entregan el
     * PDF al comprador. `resource=kude` del endpoint NO las tenía y estaba
     * bien: era la descarga INTERNA del panel, el comercio mirando su propio
     * documento.
     *
     * Cuando la caja pasó a poder descargar el KuDE (2026-09-09) apareció un
     * TERCER canal de entrega —el mostrador, donde el PDF se lo lleva el
     * comprador en la mano— y una tercera copia de la regla habría sido la que
     * se olvida de actualizar. El caso peor es el que documenta la mig 204:
     * entregarle al cliente, en mano, el KuDE con el número de OTRA venta.
     *
     * ── Por qué la ANULACIÓN es política del canal y no de la regla ─────────
     *
     * Un documento anulado NO se entrega como comprobante de una venta viva
     * (email y caja lo cortan), pero el portal del cliente SÍ lo ofrece a
     * propósito: ahí el comprador va a buscar el documento de una operación
     * que ya pasó, y la pantalla le muestra el aviso de anulación AL LADO del
     * botón (`kudeAvailable` incluye `cancelled` explícitamente y
     * `factura/[token]/page.tsx` pinta "Este documento fue anulado el …").
     * Negárselo ahí sería sacarle el registro de lo que se le anuló.
     *
     * Va como parámetro y no como "el portal ignora ese código": si el canal
     * salteara el código DESPUÉS, el corto-circuito de este método ya habría
     * devuelto 'cancelled' y se habría saltado los chequeos que vienen abajo —
     * un documento anulado Y rechazado por SIFEN habría pasado al comprador.
     *
     * @return string|null 'not_found' | 'numbering_mismatch' | 'superseded' |
     *                     'cancelled' | 'not_issued' | 'sifen_rejected' |
     *                     'sifen_pending'
     */
    public function kudeDeliveryBlocker(string $companyId, string $docId, bool $cancelledBlocks = true): ?string
    {
        $doc = ncmExecute(
            'SELECT status, cdc, sifen_status, superseded_by, cancelled_at, numbering_mismatch
               FROM einvoice_document
              WHERE einvoicedocid = ? AND companyid = ?',
            [$docId, $companyId]
        );
        if (!$doc) {
            return 'not_found';
        }
        return self::deliveryBlockerForRow($doc, $cancelledBlocks);
    }

    /**
     * La regla, sobre una FILA ya leída. Existe separada de
     * `kudeDeliveryBlocker()` para que `documentsForTransaction()` —que ya trae
     * las filas— pueda etiquetar cada documento sin una query por documento, y
     * sobre todo sin reimplementar el predicado (que es como se llega a que la
     * pantalla ofrezca descargar lo que el endpoint después rechaza).
     *
     * La fila necesita: status, cdc, sifen_status, superseded_by, cancelled_at,
     * numbering_mismatch.
     */
    private static function deliveryBlockerForRow(array|\ArrayAccess $row, bool $cancelledBlocks = true): ?string
    {
        // Guard de numeración (mig 204) PRIMERO y por separado del veredicto:
        // SIFEN valida SU registro, no nuestro invariante de que el número sea
        // el que salió impreso, así que un documento aprobado puede igual
        // tener el número de otra operación.
        if (trim((string) ($row['numbering_mismatch'] ?? '')) !== '') {
            return 'numbering_mismatch';
        }
        if (($row['superseded_by'] ?? null) !== null) {
            return 'superseded';
        }
        if ($cancelledBlocks
            && ((string) ($row['status'] ?? '') === 'cancelled' || ($row['cancelled_at'] ?? null) !== null)) {
            return 'cancelled';
        }
        if (trim((string) ($row['cdc'] ?? '')) === '') {
            return 'not_issued';
        }
        $verdict = self::sifenVerdict($row['sifen_status'] ?? null);
        if ($verdict !== 'approved') {
            return $verdict === 'rejected' ? 'sifen_rejected' : 'sifen_pending';
        }
        return null;
    }

    /**
     * KuDE para entregarle al cliente DESDE LA CAJA (realm `pos-app`).
     *
     * Hermano de `portalKude()`: mismo gate de entrega, distinto lector. Acá
     * el motivo SÍ se dice completo — del otro lado hay un cajero, que es
     * personal del comercio y necesita saber por qué no puede entregar el
     * documento; el comprador nunca ve estos textos.
     *
     * No se llama a `kude()` directo desde el endpoint justamente para que
     * este gate no sea opcional: la regla vive en el servicio, no en la puerta.
     *
     * @throws \RuntimeException con el motivo (el endpoint lo traduce a 409).
     */
    public function posKude(string $companyId, string $docId): string
    {
        $blocker = $this->kudeDeliveryBlocker($companyId, $docId);
        if ($blocker !== null) {
            throw new \RuntimeException(match ($blocker) {
                'not_found'          => 'Documento no encontrado.',
                'numbering_mismatch' => 'El número del documento electrónico no coincide con el del comprobante '
                    . 'que se le entregó al cliente: entregarlo sería darle la factura de otra operación. '
                    . 'Avisá en el comercio para que revisen la numeración de la caja.',
                'superseded'         => 'Este documento fue reemplazado por una reemisión. El vigente es el otro.',
                'cancelled'          => 'El documento está anulado: no se puede entregar como comprobante válido.',
                'not_issued'         => 'El documento todavía no se emitió — no hay KuDE que entregar.',
                'sifen_rejected'     => 'SIFEN rechazó este documento, así que no se puede entregar como factura.',
                default              => 'SIFEN todavía no aprobó este documento. Vas a poder entregarlo cuando figure como aprobado.',
            });
        }
        return $this->kude($companyId, $docId);
    }

    public function sendKude(string $companyId, string $docId, string $recipient = '', ?string $userId = null): array
    {
        // El PREDICADO es compartido con el portal y con la caja
        // (`kudeDeliveryBlocker()`); acá se traduce a los textos del OPERADOR,
        // que es quien mira esta pantalla y puede actuar. Los mensajes son los
        // mismos de siempre, palabra por palabra: lo que se unificó es la
        // regla, no el copy.
        //
        // Sobre el guard de numeración (mig 204): el email es el canal MÁS
        // peligroso para esa falla porque no requiere ninguna acción del
        // comprador — un KuDE con el CDC de otra venta le llega solo. Y sobre
        // el veredicto: se corta ACÁ y no en el drainer, mandarle al comprador
        // una factura que SIFEN todavía no aceptó es exactamente lo que evita
        // el D3 de context/57.
        $blocker = $this->kudeDeliveryBlocker($companyId, $docId);
        if ($blocker !== null) {
            throw new \RuntimeException(match ($blocker) {
                'not_found'          => 'Documento no encontrado.',
                'numbering_mismatch' =>
                    'El número del documento electrónico no coincide con el del comprobante que se le entregó al ' .
                    'cliente, así que no se le puede enviar: recibiría la factura de otra operación. ' .
                    'Revisá la numeración de la caja con el proveedor de facturación electrónica antes de enviarlo.',
                'superseded'         => 'Este documento fue reemplazado por una reemisión. Enviá el documento vigente de esa venta.',
                'cancelled'          => 'El documento está anulado: no se le puede enviar al cliente como comprobante válido.',
                'not_issued'         => 'El documento todavía no se emitió — no hay KuDE que enviar.',
                // El rechazo tiene texto propio desde que el predicado lo
                // distingue: mandarlo al mensaje de "todavía no aprobó" ponía
                // al operador a esperar un veredicto que ya llegó y fue que no.
                'sifen_rejected'     => 'SIFEN rechazó este documento: no se le puede enviar al cliente como factura.',
                default              => 'SIFEN todavía no aprobó este documento. Se envía recién cuando figura como aprobado.',
            });
        }

        $recipient = trim($recipient);
        if ($recipient === '') {
            $recipient = $this->saleContactEmail($companyId, $docId);
        }
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Indicá un email válido: el cliente de esta venta no tiene casilla cargada.');
        }

        $queued = NotificationOutbox::enqueue(
            $companyId,
            NotificationOutbox::ENTITY_EINVOICE_DOCUMENT,
            $docId,
            NotificationOutbox::CHANNEL_EMAIL,
            $recipient,
            ['origin' => 'manual', 'userId' => $userId],
            rearmSent: true
        );

        return ['queued' => $queued, 'recipient' => $recipient];
    }

    /**
     * Casilla del cliente de la VENTA que originó el documento. '' si la venta
     * no tiene cliente (consumidor final) o el cliente no tiene email — que es
     * un estado normal, no un error (D7).
     */
    private function saleContactEmail(string $companyId, string $docId): string
    {
        $row = ncmExecute(
            'SELECT c.contactEmail AS email
               FROM einvoice_document d
               JOIN transaction t ON t.transactionId = d.transactionid AND t.companyId = d.companyid
               JOIN contact c     ON c.contactId = t.customerId AND c.companyId = d.companyid
              WHERE d.einvoicedocid = ? AND d.companyid = ?',
            [$docId, $companyId]
        );

        $email = trim((string) ($row['email'] ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * KuDE de una venta para el portal público — resuelve el documento por
     * `transactionid` (el token del portal no conoce el id del documento) y
     * aplica el gate de entrega compartido antes de generar el PDF.
     *
     * @throws \RuntimeException
     */
    public function portalKude(string $companyId, string $transactionId): string
    {
        $doc = ncmExecute(
            'SELECT einvoicedocid FROM einvoice_document
              WHERE companyid = ? AND transactionid = ? AND superseded_by IS NULL
              ORDER BY created_at DESC LIMIT 1',
            [$companyId, $transactionId]
        );
        if (!$doc) {
            throw new \RuntimeException('No hay documento electrónico para esta venta.');
        }
        $docId = (string) $doc['einvoicedocid'];

        // El gate REAL de "¿este PDF puede salir?" vive acá, no en el flag
        // `kudeAvailable` de `portalDocument()`: ese flag existe para que la
        // página no pinte un botón inútil, pero la URL del PDF es adivinable
        // por cualquiera que tenga el token del portal. Un chequeo que solo
        // vive en la UI no es un chequeo.
        //
        // El PREDICADO es el compartido (`kudeDeliveryBlocker()`), el mismo que
        // usan el email y la caja. Lo que es propio de este canal es el COPY:
        // al comprador se le dice el ESTADO, nunca el motivo (R1 de
        // `context/28` §F7) — la discrepancia de numeración o el rechazo de
        // SIFEN son un problema de compliance entre el comercio y su proveedor,
        // y el comprador no puede hacer nada con esa información.
        //
        // Al pasar al predicado compartido este canal se volvió MÁS estricto en
        // dos casos que antes solo cubría de rebote (`kude()` valida emisión):
        // un documento ANULADO y uno sin CDC ya no bajan. Es la dirección
        // segura: los dos son "no es un comprobante que este comprador pueda
        // usar".
        // `cancelledBlocks: false` — la anulación NO corta en ESTE canal, y es
        // deliberado: `kudeAvailable` la incluye y la pantalla muestra el aviso
        // "Este documento fue anulado el …" junto al botón de descarga. El
        // comprador viene a buscar el registro de una operación pasada.
        // Bloquearlo acá dejaba el botón visible y el endpoint respondiendo 409:
        // el comprador abría una pestaña con un JSON crudo.
        $blocker = $this->kudeDeliveryBlocker($companyId, $docId, cancelledBlocks: false);
        if ($blocker !== null) {
            throw new \RuntimeException(match ($blocker) {
                'not_found'   => 'No hay documento electrónico para esta venta.',
                'not_issued', 'sifen_pending' => 'Tu factura todavía se está emitiendo. Volvé a intentar en unos minutos.',
                default       => 'El comercio está regularizando esta factura. Vas a poder descargarla cuando esté lista.',
            });
        }

        return $this->kude($companyId, $docId);
    }

    /**
     * Vuelve a poner un documento `error` en `pending` con `next_retry_at =
     * now()` para que el drainer lo tome en la próxima corrida. SOLO desde
     * `error` — reintentar un `issued` emitiría el documento fiscal DOS
     * VECES: no existe "reemitir", cada envío crea un documento nuevo. El
     * UPDATE con `WHERE status = 'error'` es el guard
     * real (no solo una validación previa) — mismo patrón CAS que el resto
     * del outbox, cierra la ventana de una request concurrente.
     *
     * @throws \RuntimeException si el documento no existe, no pertenece a
     *         la company, o no está en `error`.
     */
    public function retry(string $companyId, string $docId): array
    {
        $updated = ncmExecute(
            // `attempts = 0` NO es cosmético: desde que el drainer corta en
            // `attempts < MAX_RETRY_ATTEMPTS`, un documento que agotó sus ocho
            // intentos automáticos quedaría fuera de su alcance y este
            // reintento manual sería un no-op silencioso — el peor resultado
            // posible para un botón que el comercio aprieta esperando algo.
            // Una persona decidiendo reintentar reabre la ventana entera: es
            // exactamente la intervención humana que el corte pedía.
            "UPDATE einvoice_document
                SET status = 'pending', attempts = 0, next_retry_at = now(), updated_at = now()
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
        // si el motor está caído igual queda en cola para el drainer del cron.
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
     * "Corregir y emitir de nuevo" — N2 de context/28 §F7. Reemplaza un
     * documento RECHAZADO por SIFEN con uno NUEVO, y deja el rechazado como
     * registro.
     *
     * NO es un reintento y no puede serlo: `retry()` reencola la misma fila y
     * solo desde `error`; un rechazado está `issued` (el envío salió bien, lo
     * que falló es el veredicto fiscal) y volver a mandarlo emitiría el
     * documento fiscal DOS VECES — no existe "reemitir": cada envío crea un
     * documento nuevo.
     *
     * QUÉ SE CORRIGE, y esto es la línea que no se cruza: NADA de lo económico.
     * Este método no recibe ni un monto ni un ítem. Lo que se corrige es la
     * METADATA FISCAL —RUC/identidad del receptor, datos del emisor, timbrado
     * de la caja— y se corrige EN SU PANTALLA (ficha del cliente, Sucursales →
     * Cajas, Ajustes → Facturación electrónica) ANTES de llamar acá. La
     * reemisión no toca un payload: encola un documento nuevo que el drainer
     * reconstruye por el camino normal (`buildSaleArrayForMapper`, con el
     * timbrado que inyecta el motor) leyendo los datos YA corregidos. Editar montos o
     * ítems de una venta ya cobrada para que SIFEN acepte es falsear un
     * comprobante.
     *
     * El rechazado NO se borra ni se marca `cancelled` (`cancel()` va contra un
     * documento que SIFEN ACEPTÓ, es otra cosa): queda con `superseded_by`
     * apuntando al nuevo. SIFEN también lo tiene — borrarlo de nuestro lado
     * sería perder la trazabilidad.
     *
     * CONCURRENCIA: el guard real es el `WHERE superseded_by IS NULL` del
     * UPDATE (mismo patrón CAS que el resto del outbox), no la validación
     * previa. Dos clicks, o dos operadores, y el segundo recibe "ya fue
     * reemitido" en vez de encolar un tercer documento. El orden UPDATE →
     * INSERT tampoco es negociable: la fila vieja tiene que salir del índice
     * único parcial (mig 201) antes de que entre la nueva, por eso el id del
     * documento nuevo se pide ANTES y la FK de `superseded_by` es diferida.
     *
     * @param string|null $actorUserId usuario del panel que dispara la acción,
     *        para la auditoría. Se pasa explícito desde el endpoint (que ya lo
     *        resolvió en `apiAuthTenant`) en vez de leerlo de una constante
     *        global; si viene null se cae a AUTHED_USER_ID.
     * @throws \RuntimeException si el documento no existe, no es de la
     *         company, no está rechazado por SIFEN, o ya fue reemitido.
     */
    public function reissue(string $companyId, string $docId, ?string $actorUserId = null): array
    {
        global $db;

        $doc = ncmExecute(
            'SELECT einvoicedocid, transactionid, doctype, status, sifen_status, sifen_result, superseded_by
               FROM einvoice_document
              WHERE einvoicedocid = ? AND companyid = ?',
            [$docId, $companyId]
        );
        if (!$doc) {
            throw new \RuntimeException('Documento no encontrado.');
        }

        if (($doc['superseded_by'] ?? null) !== null) {
            throw new \RuntimeException(
                'Este documento ya fue reemitido: hay un documento nuevo en su lugar. Actualizá el listado para verlo.'
            );
        }

        // MISMO criterio que `sifenVerdict()` (el único del PHP, espejado en
        // `documents()` y en el front) — no se inventa un cuarto.
        if (self::sifenVerdict($doc['sifen_status'] ?? null) !== 'rejected') {
            throw new \RuntimeException(
                'Solo se puede emitir de nuevo un documento RECHAZADO por SIFEN. '
                . 'Un documento aprobado ya vale, y uno sin confirmar todavía puede terminar aprobado — '
                . 'emitir otro en cualquiera de esos dos casos duplicaría el documento fiscal.'
            );
        }

        if ((string) ($doc['status'] ?? '') === 'cancelled') {
            // Rechazado y además anulado: la anulación es la última decisión
            // del comercio sobre ese comprobante. Si igual quiere facturar la
            // venta, es una emisión nueva desde la venta, no una corrección de
            // este documento.
            throw new \RuntimeException('El documento está anulado — la reemisión es para rechazos de SIFEN, no para anulaciones.');
        }

        $transactionId = (string) $doc['transactionid'];
        $doctype       = (string) $doc['doctype'];
        $sifenReason   = self::sifenReason($doc['sifen_result'] ?? null);

        $db->StartTrans();

        // El id del documento nuevo se genera ANTES de insertarlo (patrón
        // `SELECT gen_random_uuid()` del repo) porque el puntero se escribe
        // primero — ver el comentario de concurrencia arriba y la mig 201.
        $newId = (string) $db->GetOne('SELECT gen_random_uuid()');

        $superseded = ncmExecute(
            'UPDATE einvoice_document
                SET superseded_by = ?, updated_at = now()
              WHERE einvoicedocid = ? AND companyid = ? AND superseded_by IS NULL
              RETURNING einvoicedocid',
            [$newId, $docId, $companyId]
        );
        if (!$superseded) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('Este documento ya fue reemitido — no se encoló uno nuevo.');
        }

        // Arranca en `pending`, el estado inicial normal del outbox: de acá en
        // adelante es un documento como cualquier otro (drainer, backoff,
        // reconciliación). Sin `ON CONFLICT`: acá una colisión NO es un
        // reintento idempotente sino una carrera perdida, y tiene que abortar
        // la transacción entera en vez de dejar el documento viejo marcado
        // como reemplazado por uno que nunca se insertó.
        ncmExecute(
            "INSERT INTO einvoice_document (einvoicedocid, companyid, transactionid, doctype, status)
             VALUES (?, ?, ?, ?, 'pending')",
            [$newId, $companyId, $transactionId, $doctype]
        );

        if (!$db->CompleteTrans()) {
            throw new \RuntimeException(
                'No se pudo emitir de nuevo: ' . ($db->FirstError() ?: 'la operación no se completó.')
            );
        }

        $this->auditReissue($companyId, $docId, $newId, $sifenReason, $actorUserId);

        // Best-effort, mismo criterio que `retry()`: si el motor está caído el
        // documento queda en cola para el drainer del cron. La venta no se toca.
        $this->tryIssueInline($companyId, $transactionId, $doctype);

        return $this->documentById($companyId, $newId);
    }

    /**
     * Fila propia en `tenant_audit` para la reemisión. `apiAuthTenant()` ya
     * audita el POST genérico, pero ahí el único dato es el id del documento
     * VIEJO (`?id=`): cuál fue el reemplazo y por qué motivo de SIFEN se
     * reemitió no quedarían en ningún lado, y esos son justamente los dos
     * datos que hacen falta para reconstruir qué pasó con un documento fiscal.
     *
     * Best-effort (`tenantAudit` nunca lanza) — mismo criterio que el resto del
     * módulo: la auditoría no puede tumbar la operación ya cometida.
     */
    private function auditReissue(
        string $companyId,
        string $oldDocId,
        string $newDocId,
        ?string $sifenReason,
        ?string $actorUserId
    ): void {
        if (!function_exists('tenantAudit')) {
            error_log(sprintf(
                '[EInvoiceService] reemisión %s → %s de la company %s SIN auditar (sin bootstrap).',
                $oldDocId,
                $newDocId,
                $companyId
            ));
            return;
        }

        $realm    = defined('AUTHED_REALM') ? (string) AUTHED_REALM : 'panel';
        $userId   = $actorUserId !== null && $actorUserId !== ''
            ? $actorUserId
            : (defined('AUTHED_USER_ID') && AUTHED_USER_ID !== '' ? (string) AUTHED_USER_ID : null);
        $outletId = defined('OUTLET_ID') && OUTLET_ID !== '' ? (string) OUTLET_ID : null;
        $deviceId = defined('AUTHED_DEVICE_ID') && AUTHED_DEVICE_ID !== '' ? (string) AUTHED_DEVICE_ID : null;

        // Mismo embudo que apiAuthTenant()/FiscalSecretStore: bajo `pos-app` la
        // fila queda a nombre del operador del PIN, no de la terminal.
        $actor = \Punto\Api\Auth\AuditActor::resolve(
            $realm,
            $companyId,
            $userId,
            $deviceId,
            [
                'reason'        => 'reemisión por rechazo de SIFEN',
                'replacedDocId' => $oldDocId,
                'newDocId'      => $newDocId,
                'sifenReason'   => $sifenReason,
            ]
        );

        tenantAudit(
            [
                'companyId' => $companyId,
                'userId'    => $actor['userId'],
                'outletId'  => $outletId,
                'realm'     => $realm,
            ],
            'POST',
            '/einvoice/reissue',
            $oldDocId,
            $actor['meta']
        );
    }

    /**
     * Anula un documento fiscal ya emitido — evento de cancelación ante SIFEN.
     * SOLO desde `issued`: no tiene sentido anular algo que nunca se emitió
     * (`error`/`pending`, usar retry o dejar que expire) ni algo ya
     * `cancelled`.
     *
     * Es irreversible y sale hacia afuera del sistema (SIFEN) — por eso el
     * motivo es obligatorio. La ventana de tiempo para cancelar la fija la
     * SET; acá solo se exige no-vacío, y si el motor rechaza por esa razón el
     * mensaje vuelve tal cual al panel.
     *
     * @throws \RuntimeException si el documento no existe/no pertenece a la
     *         company, no está `issued`, el motivo viene vacío, o el motor
     *         rechaza la cancelación.
     */
    public function cancel(string $companyId, string $docId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('El motivo de la cancelación es obligatorio.');
        }

        $doc = ncmExecute(
            'SELECT cdc, sifen_status FROM einvoice_document WHERE einvoicedocid = ? AND companyid = ? AND status = ?',
            [$docId, $companyId, 'issued']
        );
        if (!$doc) {
            throw new \RuntimeException('No se puede cancelar: el documento no existe o no está emitido.');
        }
        // Un RECHAZADO no se cancela: no tiene efecto fiscal — no hay nada que
        // anular ante SIFEN, y el camino correcto es corregir y emitir de
        // nuevo (N2). Confirmado en vivo contra el motor propio (409, regla
        // "solo aprobados son cancelables"). Un Pendiente sí se intenta: la
        // anulación de una venta no puede esperar el veredicto, y si el motor
        // la rechaza el error vuelve legible.
        $sifen = (string) ($doc['sifen_status'] ?? '');
        if ($sifen !== '' && (stripos($sifen, 'rechaz') !== false || stripos($sifen, 'error') !== false)) {
            throw new \RuntimeException(
                'Este documento fue RECHAZADO por SIFEN: no tiene efecto fiscal y no se cancela — usá "Corregir y emitir de nuevo".'
            );
        }
        $cdc = (string) ($doc['cdc'] ?? '');
        if ($cdc === '') {
            // No debería pasar (issued siempre tiene CDC) pero sin CDC no hay
            // nada que mandarle al motor — mejor un error claro que un 500.
            throw new \RuntimeException('El documento emitido no tiene CDC registrado — no se puede cancelar.');
        }

        $bearer = $this->sessionFor($companyId)->getBearer($companyId);
        [$tenantRef, $environment] = $this->emitterIdentity($companyId);

        $result = $this->providerFor($companyId)->cancel($environment, $tenantRef, $bearer, $cdc, $reason);
        if (empty($result['success'])) {
            $msg = (string) ($result['message'] ?? 'El motor de facturación electrónica rechazó la cancelación sin motivo reconocible.');
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
     * Bytes del PDF (KuDE) de un documento emitido, tal como lo renderiza el
     * motor de facturación electrónica.
     *
     * El PDF es OPCIONAL — si el motor falla (ej. el KuDE todavía no terminó
     * de generarse, ver `FePyProvider::kude`), la excepción sube tal cual para
     * que el endpoint la traduzca a un error visible con botón de reintento;
     * NUNCA se marca el documento como error por esto — la factura ya se
     * emitió igual.
     *
     * El KuDE lo dibuja el motor y no Punto. Se intentó lo contrario —un
     * renderer A4 propio, `context/73`— y se revirtió: el motivo que lo
     * justificaba era no depender de un TERCERO, y el motor de hoy es propio.
     * Un segundo renderer del mismo documento fiscal solo agrega una versión
     * que puede divergir de la que se firmó.
     *
     * El gate fiscal NO vive acá: es de los canales de ENTREGA al comprador
     * (`portalKude()`, `sendKude()`, `posKude()`), que lo aplican con el
     * predicado compartido `kudeDeliveryBlocker()`. Este método trae el PDF y
     * nada más — llamarlo directo desde una superficie nueva de entrega es
     * saltearse el gate.
     *
     * @throws \RuntimeException si el documento no existe/no pertenece a la
     *         company, no tiene CDC (nunca se emitió), o el motor falla.
     */
    public function kude(string $companyId, string $docId): string
    {
        $bearer = $this->sessionFor($companyId)->getBearer($companyId);
        [$tenantRef, $environment] = $this->emitterIdentity($companyId);

        return $this->providerFor($companyId)->kude(
            $environment,
            $tenantRef,
            $bearer,
            $this->issuedCdc($companyId, $docId)
        );
    }

    /**
     * Guarda el XML FIRMADO del documento en S3 (`fiscal-xml/{company}/{cdc}.xml`).
     *
     * Por qué: el XML es el documento fiscal de verdad (el KuDE es apenas su
     * representación gráfica), su conservación es obligación del EMISOR, y
     * fuera de acá vive solo del lado del motor.
     *
     * Se baja con `EInvoiceProvider::xml()` —un GET por CDC— y no desde una
     * URL que venga en la respuesta de emisión: una URL prefirmada expira, y
     * la respuesta que la traía era la del proveedor anterior. El GET por CDC
     * sigue sirviendo el mismo documento meses después.
     *
     * BEST-EFFORT ABSOLUTO. Lo llama la reconciliación, en el mismo punto
     * donde nace la entrega por email: ni un fallo de red ni uno de S3 pueden
     * tirar abajo la corrida que escribe el estado fiscal de todos los
     * tenants, ni impedir que la factura le llegue al comprador. Si falla,
     * queda el log y se pierde ESTA pasada — no hay reintento: la
     * reconciliación no vuelve a mirar un documento ya sellado.
     */
    public function archiveSignedXml(string $companyId, string $docId): void
    {
        try {
            $cdc = $this->issuedCdc($companyId, $docId);

            $bearer = $this->sessionFor($companyId)->getBearer($companyId);
            [$tenantRef, $environment] = $this->emitterIdentity($companyId);
            $xml = $this->providerFor($companyId)->xml($environment, $tenantRef, $bearer, $cdc);
            if (trim($xml) === '') {
                return;
            }

            $this->fiscalStorage()->put(
                'fiscal-xml/' . $companyId . '/' . $cdc . '.xml',
                $xml,
                'application/xml',
                false // PRIVADO: es el documento fiscal del comercio.
            );
        } catch (\Throwable $e) {
            error_log('[EInvoiceService] no se pudo archivar el XML de ' . $docId . ': ' . $e->getMessage());
        }
    }

    /**
     * CDC de un documento EMITIDO de esta company. Único lugar que valida las
     * dos precondiciones de cualquier lectura de artefacto fiscal (el
     * documento es del tenant, y existe en SIFEN): sin CDC no hay documento
     * que pedirle al motor.
     *
     * @throws \RuntimeException con mensaje apto para mostrarle al operador.
     */
    private function issuedCdc(string $companyId, string $docId): string
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

        return $cdc;
    }

    /** Bucket donde Punto custodia los documentos fiscales del comercio. */
    private function fiscalStorage(): \Punto\Api\Storage\S3Client
    {
        return new \Punto\Api\Storage\S3Client(
            defined('S3_ENDPOINT')   ? S3_ENDPOINT   : '',
            defined('S3_REGION')     ? S3_REGION     : 'us-east-1',
            defined('S3_BUCKET')     ? S3_BUCKET     : '',
            defined('S3_KEY')        ? S3_KEY        : '',
            defined('S3_SECRET')     ? S3_SECRET     : '',
            defined('S3_KEY_PREFIX') ? S3_KEY_PREFIX : ''
        );
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
        return $this->reconcilePending($companyId, $limit);
    }

    /**
     * Misma reconciliación que `reconcile()`, pero CROSS-TENANT — es la que
     * corre el cron (`maintenance.php?job=einvoice-reconcile`), que no tiene
     * sesión de panel y por lo tanto no tiene un `companyId` del que colgarse.
     *
     * Sin este job `sifen_status` queda NULL para siempre salvo que alguien
     * apriete "Reconciliar con SIFEN" a mano: el comercio no se entera nunca
     * de un rechazo asíncrono. Mismo molde que `drain()`: se selecciona por
     * fila trayendo su `companyid` y se resuelve el tenant al procesarla.
     *
     * @return array{checked:int,updated:int}
     */
    public function reconcileAll(int $limit = 50): array
    {
        return $this->reconcilePending(null, $limit);
    }

    /**
     * Motor único de reconciliación. `$companyId === null` = corrida del cron
     * sobre todos los tenants; con company = la del panel.
     *
     * La diferencia de contrato entre las dos entradas es qué pasa cuando un
     * TENANT no resuelve credenciales (cuenta a medio provisionar, auth
     * caída): con company la excepción sube (el operador apretó un botón y
     * tiene que ver el error), sin company se loguea y se sigue con el resto
     * (un tenant roto no puede dejar sin reconciliar a los demás).
     *
     * @return array{checked:int,updated:int}
     */
    private function reconcilePending(?string $companyId, int $limit): array
    {
        $limit = min(200, max(1, $limit));

        $companyFilter = $companyId !== null ? 'companyid = ? AND' : '';
        $params        = $companyId !== null ? [$companyId, $limit] : [$limit];

        $rs = ncmExecute(
            // ── La llave de reconciliación es COALESCE(provider_number, cdc) ──
            //
            // Antes el WHERE exigía `provider_number IS NOT NULL` y ese filtro
            // fabricaba huérfanos: la factura 001-002-0000615 quedó `issued`
            // con CDC y sin `provider_number`, y como esta query la salteaba
            // NUNCA se volvía a mirar — no se podía consultar su estado en
            // SIFEN, ni bajar su KuDE, ni cancelarla. Y no era un documento sin
            // llave: `provider_number` es un CACHÉ de la llave con la que el
            // motor reconcilia, que en FE-PY ES el CDC (mig 214). O sea que la
            // llave estaba ahí al lado, sin copiar.
            //
            // Colgar la reconciliación de la copia y no del original es lo que
            // convertía una escritura incompleta en un documento perdido para
            // siempre. Ahora se lee el original cuando falta la copia, y
            // `reconcileDocument()` completa la copia al pasar.
            "SELECT einvoicedocid, companyid, COALESCE(provider_number, cdc) AS reconcile_key, provider_number
               FROM einvoice_document
              WHERE $companyFilter status = 'issued' AND COALESCE(provider_number, cdc) IS NOT NULL
                AND (sifen_checked_at IS NULL OR sifen_checked_at < now() - interval '10 minutes')
                -- Aprobado/Rechazado son ESTADOS FINALES: una vez que SIFEN se
                -- expidió no cambia, así que re-consultarlos es gasto puro. Se
                -- siguen consultando los que no tienen estado todavía y los
                -- transitorios: verificado contra la API real (2026-07-30) que un
                -- documento recién emitido pasa varios segundos en 'Pendiente'
                -- (con Success:false, que NO es un rechazo) antes de resolverse.
                AND (sifen_status IS NULL OR sifen_status NOT IN ('Aprobado', 'Rechazado'))
                -- Un documento REEMPLAZADO (mig 201) ya no se persigue: su
                -- veredicto fiscal quedó cerrado el día que el comercio emitió
                -- el reemplazo. Sin esto, un rechazo cuyo `sifen_status` es un
                -- string libre del proveedor ('FinalizadoERROR', que no está en
                -- la lista de finales de arriba) vuelve a consultarse en cada
                -- corrida para siempre, gastando cupo del LIMIT global que
                -- comparten todos los tenants.
                AND superseded_by IS NULL
              ORDER BY issued_at ASC NULLS LAST
              LIMIT ?",
            $params,
            false,
            true
        );

        // Agrupado por tenant: el bearer y el login/environment se resuelven UNA
        // vez por company, no una vez por documento.
        $byCompany = [];
        $checked   = 0;
        if ($rs !== false) {
            while (!$rs->EOF) {
                $cid = (string) $rs->fields['companyid'];
                $byCompany[$cid][] = [
                    'id'     => (string) $rs->fields['einvoicedocid'],
                    'bulkId' => (string) $rs->fields['reconcile_key'],
                    // Para completar el caché cuando esté vacío — ver reconcileDocument().
                    'cached' => trim((string) ($rs->fields['provider_number'] ?? '')) !== '',
                ];
                $checked++;
                $rs->MoveNext();
            }
        }

        if ($byCompany === []) {
            return ['checked' => 0, 'updated' => 0];
        }

        // El cron SELLA los intentos fallidos (`sifen_checked_at = now()` sin
        // tocar el estado): el LIMIT es global y el orden es por antigüedad,
        // así que un tenant con la cuenta caída y más documentos que el limit
        // se comía TODAS las corridas y ningún otro tenant se reconciliaba
        // nunca. Sellar lo manda al fondo de la cola y lo reintenta en la
        // corrida siguiente (el WHERE ya exige 10 minutos de antigüedad).
        // El panel NO sella: el operador que apreta el botón dos veces
        // seguidas porque se le cayó la red tiene que poder reintentar ya.
        $seal = $companyId === null;

        $updated = 0;
        foreach ($byCompany as $cid => $docs) {
            try {
                $bearer = $this->sessionFor((string) $cid)->getBearer((string) $cid);
                [$tenantRef, $environment] = $this->emitterIdentity((string) $cid);
            } catch (\Throwable $e) {
                if ($companyId !== null) {
                    throw $e;
                }
                error_log('[EInvoiceService] reconcile: credenciales no resueltas para company ' . $cid . ': ' . $e->getMessage());
                // Todo el tenant queda sellado de una: si no hay credenciales
                // no hay documento suyo que se pueda consultar en esta corrida.
                $this->sealCheckedAt(array_column($docs, 'id'));
                continue;
            }

            foreach ($docs as $doc) {
                if ($this->reconcileDocument((string) $cid, $environment, $tenantRef, $bearer, $doc['id'], $doc['bulkId'], $seal, (bool) $doc['cached'])) {
                    $updated++;
                }
            }
        }

        return ['checked' => $checked, 'updated' => $updated];
    }

    /**
     * Consulta el bulk de UN documento y persiste el estado fiscal. Nunca
     * lanza: ni un fallo de red/API ni uno de BD pueden tirar abajo la
     * corrida entera (el UPDATE también va adentro del try — con
     * `DB_THROW_ON_ERROR` una sola fila mal formada abortaba todo lo demás).
     *
     * @param bool $seal marcar `sifen_checked_at` aunque el intento falle — ver reconcilePending().
     * @param bool $keyCached false cuando la fila llegó acá por su `cdc` porque
     *        `provider_number` estaba vacío: se completa de paso, así el caché
     *        deja de faltar en vez de faltar para siempre (caso de la 615).
     * @return bool true si se escribió `sifen_status`.
     */
    private function reconcileDocument(string $companyId, string $environment, string $tenantRef, string $bearer, string $docId, string $bulkId, bool $seal, bool $keyCached = true): bool
    {
        try {
            if (!$keyCached && $bulkId !== '') {
                // Auto-reparación, antes de salir a la red: si el intento
                // remoto falla, la copia igual quedó completa.
                ncmExecute(
                    'UPDATE einvoice_document SET provider_number = ? WHERE einvoicedocid = ? AND provider_number IS NULL',
                    [$bulkId, $docId]
                );
            }

            $bulk = $this->providerFor($companyId)->getBulk($environment, $tenantRef, $bearer, $bulkId);

            $sifenStatus = self::sifenStatusFromBulk($bulk);
            if ($sifenStatus !== null) {
                // `sifen_status` es VARCHAR(20) (mig 95) y el fallback guarda
                // el StatusString LIBRE del proveedor: uno más largo tiraba
                // 22001 y, como ese documento vuelve a salir primero en cada
                // corrida, envenenaba las siguientes para todos los tenants.
                $sifenStatus = mb_substr($sifenStatus, 0, 20);
            }

            // El QR se RECUPERA acá si la emisión no lo trajo.
            //
            // FE-PY empezó a devolver `qrUrl` (el `dCarQR` del XML firmado)
            // después de que estos documentos se emitieran, así que su
            // `provider_response` guardado no lo tiene y el bloque `fe_qr` del
            // ticket sale en blanco al reimprimir. La reconsulta sí lo trae:
            // se fusiona en el `provider_response` existente en vez de
            // pisarlo, porque ahí vive lo que devolvió la EMISIÓN y eso es
            // registro, no caché.
            //
            // `jsonb ||` es merge superficial: solo agrega la clave nueva. Si
            // el documento ya la tenía, el valor entrante es el mismo.
            $freshQr = self::extractQrUrl($bulk);
            $qrPatch = $freshQr !== null
                ? json_encode(['qrUrl' => $freshQr], JSON_UNESCAPED_UNICODE)
                : null;

            ncmExecute(
                "UPDATE einvoice_document
                    SET sifen_status = ?, sifen_result = ?::jsonb, sifen_checked_at = now(),
                        provider_response = CASE
                            WHEN ?::jsonb IS NULL THEN provider_response
                            ELSE COALESCE(provider_response, '{}'::jsonb) || ?::jsonb
                        END
                  WHERE einvoicedocid = ?",
                [
                    $sifenStatus,
                    json_encode($bulk, JSON_UNESCAPED_UNICODE),
                    $qrPatch,
                    $qrPatch,
                    $docId,
                ]
            );

            // E2 de context/57 — ACÁ es donde nace la entrega digital del KuDE,
            // y no al cerrar la venta (D3). El `WHERE` de reconcilePending()
            // sólo trae documentos que todavía NO están en un estado final, así
            // que llegar hasta acá con "Aprobado" ES la transición: no hace
            // falta comparar contra el valor anterior. Y si igual se repitiera,
            // la UNIQUE del outbox de notificaciones lo absorbe.
            if (self::isSifenApproved($sifenStatus)) {
                // ANTES del email, y best-effort las dos. El XML firmado es el
                // documento fiscal de verdad y fuera de acá vive solo del lado
                // del motor; archivarlo es conservación del EMISOR, no una
                // optimización. `archiveSignedXml()` no lanza: si falla, la
                // entrega sigue su curso igual.
                $this->archiveSignedXml($companyId, $docId);
                $this->enqueueKudeEmail($companyId, $docId);
            }

            return true;
        } catch (\Throwable $e) {
            error_log('[EInvoiceService] reconcile falló para ' . $docId . ': ' . $e->getMessage());
            if ($seal) {
                $this->sealCheckedAt([$docId]);
            }
            return false;
        }
    }

    /**
     * Marca los documentos como "consultados recién" SIN tocar su estado
     * fiscal: sacarlos de la cola de esta corrida es lo único que hace. Es
     * best-effort — si esto también falla, la corrida sigue.
     *
     * @param list<string> $docIds
     */
    private function sealCheckedAt(array $docIds): void
    {
        $docIds = array_values(array_filter($docIds));
        if ($docIds === []) {
            return;
        }
        try {
            $ph = implode(',', array_fill(0, count($docIds), '?'));
            ncmExecute("UPDATE einvoice_document SET sifen_checked_at = now() WHERE einvoicedocid IN ($ph)", $docIds);
        } catch (\Throwable $e) {
            error_log('[EInvoiceService] reconcile: no se pudo sellar sifen_checked_at: ' . $e->getMessage());
        }
    }

    /**
     * Estado FISCAL a partir del bulk crudo de `getBulk/{id}`.
     *
     * Parseo verificado con un rechazo real (2026-07-30). dEstResField
     * ("Aprobado"/"Rechazado") es la fuente más confiable cuando está
     * presente; StatusString/Success son el fallback cuando SIFEN
     * todavía no devolvió el detalle anidado.
     */
    private static function sifenStatusFromBulk(array $bulk): ?string
    {
        $item = self::bulkFirstItem($bulk);

        $rProtDe = self::bulkProtDe($item);
        $dEstRes = is_array($rProtDe) ? ($rProtDe['dEstResField'] ?? null) : null;

        $statusString = (string) ($item['StatusString'] ?? $item['statusString'] ?? '');
        $success = $item['Success'] ?? $item['success'] ?? null;

        if (is_string($dEstRes) && $dEstRes !== '') {
            return $dEstRes; // "Aprobado" | "Rechazado"
        }
        if ($statusString !== '') {
            return $statusString; // ej. "Exitoso" | "FinalizadoERROR"
        }
        if ($success !== null) {
            return $success ? 'Aprobado' : 'Rechazado';
        }
        return null;
    }

    /** Primer `Items[0]` del bulk (ambos casings), o `[]` si el shape no es el esperado. */
    private static function bulkFirstItem(array $bulk): array
    {
        $items = $bulk['Items'] ?? $bulk['items'] ?? [];
        return is_array($items) && isset($items[0]) && is_array($items[0]) ? $items[0] : [];
    }

    /**
     * Nodo `rProtDeField` — el protocolo de respuesta de SIFEN dentro del item
     * del bulk. Es el mismo nodo del que sale `dEstResField`, y donde vive el
     * motivo del rechazo. El casing de `rRetEnviDe` varía según la respuesta.
     */
    private static function bulkProtDe(array $item): ?array
    {
        $sifenResult = $item['SifenResult'] ?? $item['sifenResult'] ?? null;
        if (!is_array($sifenResult)) {
            return null;
        }
        $rProtDe = $sifenResult['rRetEnviDe']['rProtDeField'] ?? $sifenResult['rretEnviDe']['rProtDeField'] ?? null;
        return is_array($rProtDe) ? $rProtDe : null;
    }

    /**
     * Motivo LEGIBLE del veredicto de SIFEN, parseado desde el jsonb
     * `sifen_result` (el bulk crudo que guardó la reconciliación).
     *
     * El motivo vive en `gResProc` dentro de `rProtDeField`: una lista de
     * `{dCodRes, dMsgRes}` (el rechazo real de 2026-07-30 fue "1002 —
     * documento duplicado"). La forma exacta y el casing varían entre
     * respuestas, así que todo el camino se navega defensivamente: ante
     * cualquier shape inesperado devuelve `null` — un listado NO se cae por
     * un jsonb raro.
     *
     * Público y estático a propósito: `TransactionsService` muestra el mismo
     * motivo en el listado de ventas y no puede duplicar este parseo (ni
     * construir el servicio entero, que abre sesión contra el proveedor).
     *
     * @param mixed $sifenResult jsonb crudo (string) o ya decodificado (array).
     */
    public static function sifenReason(mixed $sifenResult): ?string
    {
        if (is_string($sifenResult)) {
            $sifenResult = json_decode($sifenResult, true);
        }
        if (!is_array($sifenResult)) {
            return null;
        }

        $rProtDe = self::bulkProtDe(self::bulkFirstItem($sifenResult));
        if ($rProtDe === null) {
            return null;
        }

        $res = $rProtDe['gResProc'] ?? $rProtDe['gResProcField'] ?? null;
        if (is_array($res) && !array_is_list($res)) {
            $res = [$res]; // un solo motivo puede venir como objeto suelto
        }
        if (!is_array($res)) {
            return null;
        }

        $parts = [];
        foreach ($res as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $code = $entry['dCodRes'] ?? $entry['dCodResField'] ?? $entry['dcodres'] ?? null;
            $msg  = $entry['dMsgRes'] ?? $entry['dMsgResField'] ?? $entry['dmsgres'] ?? null;
            $code = is_scalar($code) ? trim((string) $code) : '';
            $msg  = is_scalar($msg) ? trim((string) $msg) : '';
            if ($code === '' && $msg === '') {
                continue;
            }
            $parts[] = $code !== '' && $msg !== '' ? "$code — $msg" : ($msg !== '' ? $msg : $code);
        }

        return $parts === [] ? null : implode(' · ', $parts);
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
                    d.sifen_status, d.sifen_checked_at, d.sifen_result, d.superseded_by,
                    t.transactionTotal AS total, t.transactionCurrency AS currency,
                    t.customerId AS contact_id, t.outletId AS outlet_id,
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
            'sifenReason'    => self::sifenReason($rs['sifen_result'] ?? null),
            'supersededBy'   => $rs['superseded_by'] ?? null,
            'total'          => $rs['total'] !== null ? (float) $rs['total'] : null,
            'currency'       => $rs['currency'] ?? null,
            'contactId'      => $rs['contact_id'] ?? null,
            'outletId'       => $rs['outlet_id'] ?? null,
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
                    // flattenJsonb ya trajo contactCI desde `data`. Desde mig 125 este
                    // campo también guarda pasaporte/cédula extranjera/diplomático/
                    // identificación tributaria (ver ContactService::ID_TYPE_*) — "tiene
                    // contactCI" sigue siendo el chequeo correcto: "tiene ALGÚN
                    // documento", sea cual sea su tipo.
                    $ci  = trim((string) ($contact['contactCI'] ?? ''));
                    $hasTaxId = $tin !== '' || $ci !== '';
                }
            }
            if (!$hasTaxId) {
                return; // config exige RUC/CI y el cliente no tiene — no se encola
            }
        }

        // Nota de crédito: solo tiene sentido si la venta original tiene una
        // factura electrónica EMITIDA que corregir. Si no la tiene (comercio que
        // conectó FE después de esa venta, o factura que quedó en error), no se
        // encola nada: dejar el documento encolado lo mandaría a `error` en cada
        // pasada del drainer sin que nadie pueda resolverlo.
        if ($doctype === 'NC' && !$this->parentInvoiceIsIssued($companyId, $transactionId)) {
            return;
        }

        // El predicado `WHERE superseded_by IS NULL` NO es opcional: desde la
        // mig 201 el índice de idempotencia es PARCIAL (solo los documentos
        // ACTIVOS — una reemisión deja la fila vieja como registro), y
        // Postgres exige repetir el predicado del índice parcial en el
        // conflict_target para poder inferirlo. Sin eso: "no unique or
        // exclusion constraint matching the ON CONFLICT specification" en cada
        // venta. La garantía es la misma de siempre: una venta encolada dos
        // veces no duplica el documento.
        $this->enqueueDocument($companyId, $transactionId, $doctype);
    }

    /**
     * El INSERT del outbox, en UN solo lugar.
     *
     * Lo comparten el encolado automático de la venta y la emisión A PEDIDO
     * (`issueForSaleOnDemand`). El predicado `WHERE superseded_by IS NULL` NO
     * es opcional: desde la mig 201 el índice de idempotencia es PARCIAL (solo
     * los documentos ACTIVOS — una reemisión deja la fila vieja como registro)
     * y Postgres exige repetir el predicado del índice parcial en el
     * conflict_target para poder inferirlo. Sin eso: "no unique or exclusion
     * constraint matching the ON CONFLICT specification" en cada venta.
     *
     * La garantía es la de siempre, y ahora vale también para el botón manual:
     * una venta encolada dos veces no duplica el documento.
     */
    private function enqueueDocument(string $companyId, string $transactionId, string $doctype): void
    {
        ncmExecute(
            "INSERT INTO einvoice_document (companyid, transactionid, doctype, status)
             VALUES (?, ?, ?, 'pending')
             ON CONFLICT (companyid, transactionid, doctype) WHERE superseded_by IS NULL DO NOTHING",
            [$companyId, $transactionId, $doctype]
        );
    }

    /**
     * Emite la factura electrónica de una venta YA HECHA, a pedido.
     *
     * ── Por qué existe ───────────────────────────────────────────────────
     *
     * Hasta el 2026-09-08 el ÚNICO momento en que una venta podía entrar al
     * outbox era el instante de guardarla, y ese camino tiene tres salidas
     * SILENCIOSAS (`enqueueForSale`): cuenta no conectada, `autoIssue`
     * apagado, y `onlyWithTaxId` con un cliente sin RUC/CI. Una venta que
     * caía en cualquiera de las tres quedaba sin factura electrónica PARA
     * SIEMPRE: `retry()` sale de `status='error'` y `reissue()` exige un
     * documento rechazado por SIFEN — las dos necesitan una fila que nunca se
     * creó.
     *
     * El efecto real, reportado por el owner: hizo una venta con `autoIssue`
     * apagado, el ticket se imprimió con su número, y ese número quedó
     * consumido sin documento electrónico. La venta siguiente lleva el
     * número que sigue, así que el talonario ante la SET arranca con un hueco
     * que el comercio no puede cerrar por ningún medio.
     *
     * ── Qué gates saltea y cuáles NO ─────────────────────────────────────
     *
     * Saltea `autoIssue` y `onlyWithTaxId`: son política de AUTOMATIZACIÓN
     * ("¿facturo solo?", "¿facturo también sin RUC?"), y una emisión pedida a
     * mano ya es la respuesta a esas dos preguntas. Respetarlas acá haría que
     * el botón no hiciera nada, en silencio, que es el bug que viene a cerrar.
     *
     * NO saltea nada fiscal. La cuenta tiene que estar conectada (`status =
     * 'ok'`), el tipo de venta tiene que ser facturable, y el documento sale
     * por el MISMO camino de emisión que el automático — así que la
     * divergencia de numeración se detecta igual (queda en
     * `numbering_mismatch` y bloquea la entrega) y el timbrado lo inyecta el
     * motor igual. El número que viaja es el que la venta
     * ya tiene congelado: por eso esto cierra el hueco en vez de abrir otro.
     *
     * @return array{status:string,docId:string,message:string}
     * @throws \RuntimeException con el mensaje que ve el comercio.
     */
    public function issueForSaleOnDemand(string $companyId, string $transactionId): array
    {
        $tx = ncmExecute(
            'SELECT transactionType FROM transaction WHERE transactionId = ? AND companyId = ?',
            [$transactionId, $companyId]
        );
        if (!$tx) {
            throw new \RuntimeException('La venta no existe.');
        }

        // Mismo mapeo que `SaleService::enqueueElectronicInvoice()`, contra
        // `Punto\Api\Sales\SaleType`: 0 = contado, 3 = crédito. Cualquier
        // otro tipo (compra, presupuesto, pago de crédito) no es una venta que
        // se facture, y decirlo es mejor que encolar algo que el mapper
        // rechazaría después.
        $doctype = match ((int) ($tx['transactionType'] ?? -1)) {
            0 => 'FC',
            3 => 'FCR',
            default => null,
        };
        if ($doctype === null) {
            throw new \RuntimeException(
                'Esta transacción no es una venta al contado ni a crédito, así que no lleva factura electrónica.'
            );
        }

        $account = ncmExecute('SELECT status FROM einvoice_account WHERE companyid = ?', [$companyId]);
        if (!$account || (string) ($account['status'] ?? '') !== 'ok') {
            throw new \RuntimeException(
                'La facturación electrónica no está conectada. Verificá la conexión en Configuración → '
                . 'Facturación electrónica y volvé a intentar.'
            );
        }

        $existing = ncmExecute(
            "SELECT einvoicedocid, status FROM einvoice_document
              WHERE companyid = ? AND transactionid = ? AND doctype = ? AND superseded_by IS NULL
              LIMIT 1",
            [$companyId, $transactionId, $doctype]
        );
        if ($existing && (string) ($existing['status'] ?? '') === 'issued') {
            throw new \RuntimeException('Esta venta ya tiene su factura electrónica emitida.');
        }

        // Idempotente: si ya había una fila en `pending`/`error`, el ON
        // CONFLICT no la duplica y seguimos al intento de emisión — que es
        // exactamente lo que el comercio pidió al apretar el botón.
        $this->enqueueDocument($companyId, $transactionId, $doctype);

        $doc = ncmExecute(
            "SELECT einvoicedocid, status FROM einvoice_document
              WHERE companyid = ? AND transactionid = ? AND doctype = ? AND superseded_by IS NULL
              LIMIT 1",
            [$companyId, $transactionId, $doctype]
        );
        if (!$doc) {
            throw new \RuntimeException('No se pudo encolar el documento. Volvé a intentar.');
        }
        $docId = (string) $doc['einvoicedocid'];

        // CAS antes de emitir — mismo patrón que `tryIssueInline()` y que el
        // drainer: si el cron ya tomó esta fila, no se emite dos veces. Se
        // acepta también `error` porque un documento que falló es justamente
        // el que el comercio está reintentando a mano.
        $claimed = ncmExecute(
            "UPDATE einvoice_document SET status = 'sending', updated_at = now()
              WHERE einvoicedocid = ? AND status IN ('pending', 'error')
              RETURNING einvoicedocid",
            [$docId]
        );
        if (!$claimed) {
            return [
                'status'  => 'in_progress',
                'docId'   => $docId,
                'message' => 'El documento ya se está emitiendo. Actualizá en unos segundos para ver el resultado.',
            ];
        }

        $this->issueClaimedDocument($docId, $companyId);

        $after = ncmExecute(
            'SELECT status, error_message FROM einvoice_document WHERE einvoicedocid = ?',
            [$docId]
        );
        $status = (string) ($after['status'] ?? 'error');

        return [
            'status'  => $status,
            'docId'   => $docId,
            'message' => $status === 'issued'
                ? 'Factura electrónica emitida.'
                : ('No se pudo emitir: ' . (string) ($after['error_message'] ?? 'error desconocido')),
        ];
    }

    /**
     * true si la venta original de una devolución tiene una factura electrónica
     * emitida. `$transactionId` es el de la DEVOLUCIÓN — la original sale de
     * `transaction_link` (mig 115, kind='return' — reemplaza `transactionParentId`).
     */
    private function parentInvoiceIsIssued(string $companyId, string $transactionId): bool
    {
        $origins  = (new \Punto\Api\Services\TransactionLinkService())->listOriginIds($companyId, $transactionId, 'return');
        $parentId = $origins[0] ?? '';
        if ($parentId === '') {
            return false;
        }

        $doc = ncmExecute(
            // `superseded_by IS NULL`: una factura que se reemitió por rechazo
            // (mig 201) NO es la factura de esa venta — la de verdad es su
            // reemplazo. Colgar la NC del documento reemplazado la ataría a un
            // comprobante que SIFEN no aceptó.
            "SELECT einvoicedocid FROM einvoice_document
              WHERE companyid = ? AND transactionid = ? AND status = 'issued' AND cdc IS NOT NULL
                AND superseded_by IS NULL
              LIMIT 1",
            [$companyId, $parentId]
        );
        return (bool) $doc;
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
                -- El corte de reintentos automáticos. Sin esto el backoff
                -- capeaba el INTERVALO y no la CANTIDAD: pasado el octavo
                -- intento la fila se reintentaba cada 4h20 para siempre, y el
                -- proveedor nos reportó documentos viejos golpeando su API
                -- todo el día (2026-09-09). `retry()` sigue pudiendo
                -- reactivarla a mano: resetea `attempts` a 0 y reabre la
                -- ventana entera.
                AND attempts < " . self::MAX_RETRY_ATTEMPTS . "
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
     * cualquier fallo (mapeo, red, rechazo del motor) se persiste como
     * `error` con backoff, para que el drainer reintente después.
     *
     * @return bool true si quedó `issued`.
     */
    private function issueClaimedDocument(string $docId, string $companyId): bool
    {
        try {
            $doc = ncmExecute(
                'SELECT transactionid, doctype, attempts, security_code, provider_txn_id FROM einvoice_document WHERE einvoicedocid = ?',
                [$docId]
            );
            if (!$doc) {
                return false;
            }
            $transactionId = (string) $doc['transactionid'];
            $doctype       = (string) $doc['doctype'];
            $attempts      = (int) ($doc['attempts'] ?? 0);
            // La llave de recuperación (mig 217). Write-once: si está, este
            // documento YA salió alguna vez hacia el motor, aunque nuestra fila
            // diga `error` y aunque `retry()` haya reseteado los intentos.
            $storedTxnId   = trim((string) ($doc['provider_txn_id'] ?? ''));
            // securityCode CONGELADO para este documento: se genera una sola
            // vez y se reusa en todos los reintentos. Ver ensureSecurityCode().
            $securityCode  = $this->ensureSecurityCode($docId, $doc['security_code'] ?? null);

            $account = ncmExecute(
                // `emitter` (userInfo cacheado) trae el RUC del emisor — lo usa
                // el guard de CDC de abajo para comprobar que el documento que
                // volvió es de ESTE contribuyente. Sale de acá y no de
                // `company.config` para no sumar una query por documento: es el
                // mismo RUC con el que el emisor está dado de alta.
                'SELECT status, environment, stamp, provisioning, emitter, config AS account_config
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

            // Timbrado de la CAJA de la venta (F7): cada caja es un punto de
            // expedición (context/29 §1), así que el documento sale con el
            // timbrado de la caja que vendió — no con uno global. El mapa
            $config = $this->decodeJsonb($account['account_config'] ?? null);

            // issuedDate = la fecha de la OPERACIÓN, no la del envío.
            //
            // Acá había un `date()` del momento de emitir. El outbox se drena
            // asincrónicamente y una venta cobrada sin red se drena cuando la
            // tablet reconecta: con `date()` el documento electrónico
            // declaraba una fecha distinta de la del ticket que el cliente ya
            // tiene en la mano. El invariante del repo es que las dimensiones
            // de una transacción son las del momento en que se OPERÓ, y la
            // fecha es una de ellas.
            //
            // Zona: `transactionDate` es timestamptz y se formatea con
            // `TenantClock::atInstant()`, que lo lee en el reloj del TENANT
            // sin depender de la TZ del proceso — el drenaje puede correr en
            // un cron que nunca pasó por `TenantClock::apply()`. El formato
            // que espera el motor es naive `YYYY-MM-DDTHH:MM:SS` en hora
            // local, mismo criterio que la fecha de firma de la cancelación.
            $issuedDate = $this->issuedDateFor($companyId, $sale);

            // Inicializado ANTES del try: `persistIssued()` lo necesita
            // después para verificar el CDC, y llegar ahí con una variable
            // inexistente es un TypeError, no un null silencioso.
            $point = ['establecimiento' => '', 'punto' => ''];

            try {
                // El par establecimiento/punto de la CAJA que vendió
                // (`context/29`: cada caja es un punto de expedición). Lanza
                // si la caja no lo tiene cargado — fail-closed: nunca el punto
                // de expedición de otra caja.
                $point = $this->fePyPointForDocument($companyId, $transactionId);
                // La divergencia de numeración no se PREVIENE consultando el
                // talonario del motor antes de emitir: se DETECTA sobre el CDC
                // devuelto y queda en `numbering_mismatch` (mig 204). Es la
                // única forma honesta — entre la consulta y la emisión hay una
                // ventana, y el número lo termina fijando el motor.
                $sale['securityCode'] = $securityCode;
                $payload = (new SaleToFePyMapper())->build($sale, $point, $config, $issuedDate, $docId);
            } catch (\RuntimeException $e) {
                // Regla fiscal violada o dato faltante — NUNCA se manda al
                // proveedor para que rebote, se marca error directo con el
                // motivo en castellano.
                $this->markError($docId, $attempts, $e->getMessage());
                return false;
            }

            // `request_payload` archiva lo que se le manda al proveedor, y el
            // panel lo muestra. La clave reservada de idempotencia de FE-PY
            // (`__idempotencyKey`) NO es parte del documento —viaja como
            // header y su valor es el propio `einvoicedocid`, o sea la PK de
            // esta misma fila— así que se saca antes de archivar en vez de
            // ensuciar el payload fiscal con un campo que no existe.
            $archived = $payload;
            unset($archived[FePyProvider::IDEMPOTENCY_PAYLOAD_KEY]);
            ncmExecute('UPDATE einvoice_document SET request_payload = ?::jsonb WHERE einvoicedocid = ?', [
                json_encode($archived, JSON_UNESCAPED_UNICODE), $docId,
            ]);

            $bearer = $this->sessionFor($companyId)->getBearer($companyId);
            [$tenantRef, $environment] = $this->emitterIdentity($companyId);

            // ── PASO DE RECUPERACIÓN: ¿este documento ya existe allá? ──────
            //
            // Va ANTES de todo reintento y después de armar el payload, que es
            // de donde salen el número y el tipo con los que se lo busca.
            // Emitir sin preguntar es como se emite dos veces el mismo
            // documento fiscal. Si el motor no contesta, la excepción sube y
            // la fila queda en `error` para la corrida siguiente: un "no sé"
            // NUNCA se interpreta como "no existe".
            //
            // Un fallo de la CONSULTA consume un intento del presupuesto de
            // ocho, igual que un fallo de emisión. Es deliberado y no es
            // gratis: si el endpoint de recuperación está caído, el documento
            // agota sus intentos sin haberse llegado a emitir y necesita un
            // `retry()` humano (que resetea `attempts` y —al no tocar
            // `provider_txn_id`— deja la recuperación igual de armada). Se
            // elige ese fallo y no el otro: no contar estos intentos deja al
            // drainer girando sobre el mismo documento indefinidamente, que es
            // exactamente el incidente que el corte de reintentos se agregó a
            // resolver el 2026-09-09 (el proveedor nos reportó documentos
            // viejos golpeando su API todo el día). Quedar quieto y visible en
            // `error` degrada mejor que no parar nunca.
            if ($attempts > 0 || $storedTxnId !== '') {
                $vigente = $this->lookupIssuedDocument(
                    $companyId, $environment, $tenantRef, $bearer, $storedTxnId, $payload, $point
                );
                if ($vigente !== null) {
                    return $this->adoptRecoveredDocument(
                        $companyId, $account, $point, $sale, $doctype, $config, $vigente, $docId, $attempts
                    );
                }
            }

            $result = $this->providerFor($companyId)->issue($environment, $tenantRef, $bearer, $payload);
            $txnId  = isset($result['txnId']) ? trim((string) $result['txnId']) : '';

            if (empty($result['success']) || empty($result['cdc'])) {
                $reason = (string) ($result['statusMessage'] ?? 'El proveedor rechazó el documento sin motivo reconocible.');
                ncmExecute(
                    // `provider_txn_id` se escribe EN EL MISMO UPDATE que marca
                    // `error`, y con COALESCE: es la única llave con la que
                    // después se puede averiguar si este documento igual quedó
                    // emitido del otro lado. Si se pierde acá, se perdió para
                    // siempre — es lo que pasó con la NC nº 2 (mig 217).
                    "UPDATE einvoice_document
                        SET status = 'error', attempts = attempts + 1, error_message = ?,
                            provider_response = ?::jsonb, provider_txn_id = COALESCE(provider_txn_id, ?),
                            next_retry_at = ?, updated_at = now()
                      WHERE einvoicedocid = ?",
                    [
                        $reason,
                        json_encode($result['raw'] ?? [], JSON_UNESCAPED_UNICODE),
                        $txnId !== '' ? $txnId : null,
                        $this->nextRetryAt($attempts + 1),
                        $docId,
                    ]
                );
                return false;
            }

            return $this->persistIssued($companyId, $account, $point, $sale, $doctype, $config, $result, $docId);
        } catch (\Throwable $e) {
            // Nunca dejar la excepción escapar — el caller (SaleService post-commit,
            // o el endpoint de drain) no puede fallar porque el proveedor esté caído.
            error_log('[EInvoiceService] issueClaimedDocument ' . $docId . ': ' . $e->getMessage());
            try {
                $attempts = (int) (ncmExecute('SELECT attempts FROM einvoice_document WHERE einvoicedocid = ?', [$docId])['attempts'] ?? 0);
                // Un error HTTP NO prueba que el documento no se haya creado.
                // Si su cuerpo trae el identificador de la transacción, se
                // guarda junto con el error: es el hilo de la recuperación.
                $failedTxnId = null;
                if ($e instanceof FePyHttpException) {
                    $candidate = trim((string) ($e->body['txnId'] ?? ''));
                    $failedTxnId = $candidate !== '' ? $candidate : null;
                }
                $this->markError($docId, $attempts, $e->getMessage(), $failedTxnId);
            } catch (\Throwable $inner) {
                // Ni siquiera se pudo persistir el error — se loguea y se abandona,
                // el documento queda 'sending' hasta revisión manual (caso extremo).
                error_log('[EInvoiceService] no se pudo persistir el error de ' . $docId . ': ' . $inner->getMessage());
            }
            return false;
        }
    }

    /**
     * ¿El documento que estamos por emitir YA existe del lado del motor?
     *
     * Este método es la diferencia entre "reintentar" y "emitir dos veces el
     * mismo documento fiscal". Un documento puede estar emitido allá y `error`
     * acá — pasó dos veces en producción el 2026-09-10 — y hasta hoy el
     * drainer lo reintentaba a ciegas confiando en que la `Idempotency-Key` lo
     * frenara. No lo frena cuando el payload cambió: ante otro body el motor
     * devuelve 409, no deduplica, y el documento queda irrecuperable.
     *
     * ── El orden, y por qué ──────────────────────────────────────────────
     *
     *   1. Por `txnId` (mig 217) cuando lo tenemos. Es la llave EXACTA de la
     *      transacción de emisión, existe aunque no haya CDC, y no depende de
     *      que el número que mandamos sea el que quedó.
     *   2. Por número + tipo como fallback, para cuando se perdió la respuesta
     *      HTTP entera y nunca hubo `txnId`. El tipo va SIEMPRE: sin él la
     *      búsqueda cruza una factura con una nota de crédito del mismo
     *      número.
     *
     * ── Lo que NO hace ───────────────────────────────────────────────────
     *
     * No elige entre `intentos`. Quién es el documento vigente lo decide el
     * único parcial de la base del motor (`WHERE estado NOT IN
     * ('rechazado','error')`); reimplementar ese criterio acá sería una
     * segunda versión, peor, de una garantía que ya existe.
     *
     * Y no traga los errores: si el motor no contesta, la excepción SUBE. Un
     * "no sé" tratado como "no existe" es exactamente cómo se emite dos veces.
     *
     * ── El único punto ciego, dicho con todas las letras ──────────────────
     *
     * Devolver `null` significa "no hay documento vigente" en TODOS los casos
     * MENOS uno: cuando el documento no tiene `txnId` guardado NI número
     * propio (kill-switch `legacyAutoNumbering`), no hay con qué preguntar y
     * el `null` es "no pude averiguarlo", no "no existe". Ese caso queda
     * apoyado solamente en la `Idempotency-Key` —mismo body ⇒ replay— que es
     * la protección que había antes de este método para todos los casos. Se
     * loguea al pasar para que no se confunda con una consulta que dio
     * negativo. Hoy es un camino angosto: la numeración propia es la vigente
     * y el kill-switch quedó inerte (ver sección (D) del arnés).
     *
     * @param array<string,mixed> $payload Payload ya armado — de ahí salen número y tipo.
     * @param array{establecimiento:string,punto:string} $point
     * @return array<string,mixed>|null El documento vigente, o null si no hay ninguno
     *         (y sólo entonces es seguro emitir).
     */
    private function lookupIssuedDocument(
        string $companyId,
        string $environment,
        string $tenantRef,
        string $bearer,
        string $storedTxnId,
        array $payload,
        array $point
    ): ?array {
        $provider = $this->providerFor($companyId);

        if ($storedTxnId !== '') {
            $found = $provider->lookupByTxn($environment, $tenantRef, $bearer, $storedTxnId);
            if (($found['vigente'] ?? null) !== null) {
                return $found['vigente'];
            }
            // `vigente: null` por txnId ya es una respuesta completa: esa
            // transacción no dejó documento vigente. Se sigue igual al fallback
            // por número porque un intento ANTERIOR —cuyo txnId nunca llegó a
            // guardarse— sí pudo haberlo dejado.
        }

        $numero = trim((string) ($payload['numero'] ?? ''));
        $tipo   = (int) ($payload['tipoDocumento'] ?? 0);
        $est    = trim((string) ($point['establecimiento'] ?? ''));
        $punto  = trim((string) ($point['punto'] ?? ''));

        if ($numero === '' || $tipo <= 0 || $est === '' || $punto === '') {
            // Sin número propio no hay nada por qué preguntar: es el caso del
            // kill-switch `legacyAutoNumbering`, donde el correlativo lo pone
            // el motor y nosotros no sabemos cuál pidió el intento anterior.
            // Ahí la única protección sigue siendo la Idempotency-Key (mismo
            // body ⇒ misma key ⇒ replay), y se deja constancia.
            error_log(
                '[EInvoiceService] recuperación sin llave: el documento no tiene txnId guardado ni número propio ' .
                'que consultar — se reintenta apoyado sólo en la clave de idempotencia.'
            );
            return null;
        }

        $found = $provider->lookupByNumber($environment, $tenantRef, $bearer, $tipo, $est, $punto, $numero);
        return ($found['vigente'] ?? null) !== null ? $found['vigente'] : null;
    }

    /**
     * ADOPTA un documento que el motor ya tenía: lo reconcilia con nuestra
     * fila en vez de emitirlo de nuevo.
     *
     * Dos desenlaces, y ninguno de los dos vuelve a emitir:
     *
     *   - **Con CDC** — el documento es fiscal, exista el veredicto de SIFEN o
     *     no. Se persiste `issued` por el camino normal (`persistIssued()`, con
     *     su guard de CDC y su `numbering_mismatch`), y si el motor ya trajo el
     *     veredicto se guarda en el mismo UPDATE. Incluye el caso RECHAZADO:
     *     un rechazado queda `issued` + `sifen_status='Rechazado'`, que es
     *     exactamente el estado desde el que el comercio puede corregir la
     *     metadata fiscal y REEMITIR (`reissue()`). Reintentar un rechazo
     *     idéntico sólo repite el rechazo y crea otra fila del lado de ellos.
     *   - **Sin CDC** — el motor tiene una transacción para este documento pero
     *     no llegó a haber documento fiscal. Queda en `error` con el motivo del
     *     motor, sin reemitir en esta corrida: fail-closed. Si de verdad no
     *     existe nada vigente, la consulta habría devuelto `vigente: null` y ni
     *     siquiera estaríamos acá.
     *
     * @param array<string,mixed> $vigente Documento vigente tal como lo devolvió el motor.
     */
    private function adoptRecoveredDocument(
        string $companyId,
        $account,
        array $point,
        array $sale,
        string $doctype,
        array $config,
        array $vigente,
        string $docId,
        int $attempts
    ): bool {
        $cdc    = trim((string) ($vigente['cdc'] ?? ''));
        $txnId  = trim((string) ($vigente['txnId'] ?? ''));
        $estado = strtolower(trim((string) ($vigente['estado'] ?? '')));

        if ($cdc === '') {
            $motivo = trim((string) ($vigente['errorMessage'] ?? ''));
            $this->markError(
                $docId,
                $attempts,
                'El motor ya tiene una emisión para este documento (estado: ' . ($estado !== '' ? $estado : 'desconocido') . ')' .
                ' pero sin CDC, así que NO se reemite para no duplicarlo' . ($motivo !== '' ? ': ' . $motivo : '.'),
                $txnId !== '' ? $txnId : null,
                // El documento crudo del motor queda archivado: este es
                // justamente el caso que termina en una inspección manual, y
                // el mensaje de 500 chars no alcanza para diagnosticarlo.
                $vigente
            );
            return false;
        }

        error_log(
            '[EInvoiceService] documento RECUPERADO sin reemitir ' . $docId .
            ' — el motor ya lo tenía emitido (estado=' . ($estado !== '' ? $estado : '?') . ', cdc=' . $cdc . ').'
        );

        // El shape de bulk es el MISMO que devuelve la reconsulta, así que el
        // estado fiscal se deriva con el traductor que ya existe en vez de
        // inventar una segunda lectura del veredicto de SIFEN.
        $bulk = FePyProvider::toBulkShape($vigente);

        $adopted = $this->persistIssued(
            $companyId,
            $account,
            $point,
            $sale,
            $doctype,
            $config,
            [
                'cdc'            => $cdc,
                'documentNumber' => isset($vigente['numero']) ? (string) $vigente['numero'] : null,
                'txnId'          => $txnId !== '' ? $txnId : null,
                // En FE-PY la llave de reconciliación ES el CDC (mig 214).
                'bulkId'         => $cdc,
                'raw'            => $vigente,
            ],
            $docId,
            $bulk
        );

        // Un documento adoptado que ya viene APROBADO no vuelve a pasar por la
        // reconciliación (su WHERE excluye los estados finales), así que los
        // efectos que cuelgan de esa transición tienen que dispararse acá o no
        // se disparan nunca: el XML firmado no se archiva y el cliente no
        // recibe su KuDE. Las dos son best-effort y no lanzan.
        if ($adopted && self::isSifenApproved(self::sifenStatusFromBulk($bulk))) {
            $this->archiveSignedXml($companyId, $docId);
            $this->enqueueKudeEmail($companyId, $docId);
        }

        return $adopted;
    }

    /**
     * Persiste un documento que el proveedor YA emitió. Extraído tal cual de
     * `issueClaimedDocument()` (mig 206) para que los dos proveedores
     * compartan el cierre: el guard del CDC, el `numbering_mismatch` y el
     * UPDATE a `issued` no dependen de quién firmó.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $sale
     * @param array<string,mixed> $stamp Vacío para FE-PY (no tiene catálogo de timbrados).
     * @param array<string,mixed> $result Respuesta normalizada de `issue()`.
     * @param array<string,mixed>|null $bulk Estado FISCAL ya conocido, en el shape de
     *        `getBulk()`. Solo lo trae la RECUPERACIÓN (`adoptRecoveredDocument()`),
     *        que consulta un documento que el motor ya resolvió: si el veredicto de
     *        SIFEN se sabe, se persiste en el MISMO UPDATE que marca `issued` en vez
     *        de esperar a que la reconciliación lo descubra dentro de diez minutos.
     *        En la emisión normal es null — ahí el veredicto todavía no existe.
     */
    private function persistIssued(
        string $companyId,
        $account,
        array $point,
        array $sale,
        string $doctype,
        array $config,
        array $result,
        string $docId,
        ?array $bulk = null
    ): bool {
            // ── GUARD: ¿el CDC que volvió describe la venta que imprimimos? ──
            //
            // El documento ya existe en SIFEN, así que esto NO puede marcarlo
            // `error` (lo haría elegible para retry(), y reintentar un emitido
            // lo duplica — misma trampa que documentó la mig 201). Queda
            // `issued` + la discrepancia anotada en `numbering_mismatch`
            // (mig 204), en el MISMO UPDATE: nunca hay una ventana en la que
            // el documento esté issued y sin marcar.
            $mismatch = $this->cdcMismatchFor(
                $companyId, $account, $point, $sale, $doctype, $config, (string) $result['cdc']
            );

            // provider_number cachea la llave con la que el motor reconcilia
            // este documento (ver reconcile() y el COMMENT de la mig 214). En
            // FE-PY es el CDC, porque su reconsulta es por CDC.
            $txnId = isset($result['txnId']) ? trim((string) $result['txnId']) : '';
            $sifenStatus = $bulk !== null ? self::sifenStatusFromBulk($bulk) : null;
            if ($sifenStatus !== null) {
                // `sifen_status` es VARCHAR(20) (mig 95) — mismo recorte que en
                // la reconciliación, por la misma razón: el fallback guarda un
                // string libre del motor.
                $sifenStatus = mb_substr($sifenStatus, 0, 20);
            }
            $sifenResult = $bulk !== null ? json_encode($bulk, JSON_UNESCAPED_UNICODE) : null;

            ncmExecute(
                // Tres cosas que van juntas o no van: el CDC que hace fiscal al
                // documento, el `txnId` con el que se lo recupera (write-once,
                // mig 217) y —cuando la recuperación ya lo trajo— el veredicto
                // de SIFEN. Los parámetros nulos son no-ops por COALESCE, así
                // que la emisión normal escribe exactamente lo de siempre.
                "UPDATE einvoice_document
                    SET status = 'issued', cdc = ?, document_number = ?, provider_number = ?, provider_response = ?::jsonb,
                        numbering_mismatch = ?, provider_txn_id = COALESCE(provider_txn_id, ?),
                        sifen_status = COALESCE(?, sifen_status),
                        sifen_result = COALESCE(?::jsonb, sifen_result),
                        sifen_checked_at = CASE WHEN ?::text IS NULL THEN sifen_checked_at ELSE now() END,
                        issued_at = now(), updated_at = now()
                  WHERE einvoicedocid = ?",
                [
                    (string) $result['cdc'],
                    $result['documentNumber'] !== null ? (string) $result['documentNumber'] : null,
                    $result['bulkId'] !== null ? (string) $result['bulkId'] : null,
                    json_encode($result['raw'] ?? [], JSON_UNESCAPED_UNICODE),
                    $mismatch,
                    $txnId !== '' ? $txnId : null,
                    $sifenStatus,
                    $sifenResult,
                    $sifenStatus,
                    $docId,
                ]
            );

            if ($mismatch !== null) {
                // Alto y claro en el log: es una violación de un invariante
                // fiscal, no un error de red que se reintenta solo.
                error_log('[EInvoiceService] CDC no coincide con la venta ' . $docId . ': ' . $mismatch);
            }

        return true;
    }

    /**
     * Establecimiento y punto de expedición con los que sale UN documento.
     *
     * El dato NO viene del motor: el timbrado es del TENANT y el par
     * `establecimiento`/`punto` va POR documento, así que sale de la CAJA que
     * vendió (`register.registerInvoicePrefix`, formato `EEE-PPP`)
     * — que es exactamente el modelo de `context/29`: cada caja es un punto de
     * expedición.
     *
     * FAIL-CLOSED: una caja sin
     * prefijo cargado NO cae al de otra caja. Dos cajas emitiendo contra el
     * mismo punto de expedición es el escenario de facturas duplicadas que el
     * modelo por-caja existe para impedir.
     *
     * ── El punto CONGELADO es la fuente, tenga caja o no (2026-09-09) ──
     * Hasta hoy, el congelado solo se miraba cuando `transaction.registerId`
     * estaba cargado, y una NOTA DE CRÉDITO emitida desde el PANEL —que no
     * tiene caja— caía a "el prefijo de la primera caja activa por nombre".
     * Eso es adivinar un punto de expedición: la NC salía a SIFEN declarando
     * el punto de una caja elegida por orden alfabético, sin relación con el
     * documento que corregía.
     *
     * Ese fallback se ELIMINA. La NC hereda la caja de la factura que corrige
     * y congela su serie en su propia fila (`ReturnService::create()`), así que
     * `invoicePrefix` está poblado exactamente igual que en una venta y este
     * método lo lee sin preguntar por la caja. La lectura de la caja queda solo
     * como fallback para las filas ANTERIORES a la mig 209, que no tienen el
     * dato congelado.
     *
     * @return array{establecimiento:string,punto:string}
     * @throws \RuntimeException si no se puede determinar sin adivinar.
     */
    private function fePyPointForDocument(string $companyId, string $transactionId): array
    {
        $tx = ncmExecute(
            'SELECT t.registerId, t.invoicePrefix, r.registerName, r.data
               FROM transaction t
          LEFT JOIN register r ON r.registerId = t.registerId AND r.companyId = t.companyId
              WHERE t.transactionId = ? AND t.companyId = ?',
            [$transactionId, $companyId]
        );
        $tx = $tx ?: [];

        // El punto CONGELADO manda sobre el vigente (mig 209). Este es el
        // lector más consecuente de todos: es el punto de expedición con el
        // que el documento sale a SIFEN. Leer el vivo significaba que, si el
        // admin cambiaba el punto entre la operación y la emisión —o mientras
        // el documento esperaba en el outbox—, el documento se declaraba
        // contra un punto distinto del que el comprobante ya llevaba impreso,
        // con el correlativo de la serie vieja.
        //
        // `data` viene aplanado por Query::flattenJsonb, así que
        // `registerInvoicePrefix` llega como clave de la fila. Mismo patrón
        // —y mismo bug evitado— que `registerStamps()`.
        $prefix = trim((string) ($tx['invoicePrefix'] ?? ''));
        if ($prefix === '') {
            $prefix = trim((string) ($tx['registerInvoicePrefix'] ?? ''));
        }
        if (preg_match('/^(\d{3})-(\d{3})$/', $prefix, $m) === 1) {
            return ['establecimiento' => $m[1], 'punto' => $m[2]];
        }

        // Fail-CLOSED. Sin punto congelado ni caja de la que leerlo no hay
        // forma de saber contra qué punto de expedición se declara este
        // documento, y ninguna de las respuestas posibles es adivinable: un
        // punto equivocado es una declaración falsa ante la SET, y encima
        // pisaría la numeración de la caja que sí lo tiene asignado.
        $registerName = trim((string) ($tx['registerName'] ?? ''));
        throw new \RuntimeException(sprintf(
            'El documento no tiene punto de expedición (formato EEE-PPP): %s. Cargalo en Sucursales → Cajas y '
            . 'volvé a emitir. No se emite con el punto de expedición de otra caja.',
            $registerName !== ''
                ? 'la caja "' . $registerName . '" no lo tiene cargado'
                : 'ni el documento lo tiene congelado ni tiene caja de la que heredarlo'
        ));
    }

    /**
     * Resuelve el timbrado con el que se emite UN documento. Tres casos, y la
     * diferencia entre el segundo y el tercero es FISCAL, no cosmética:
     *
     *   1. La caja de la venta está en el mapa del provisioning → su timbrado.
     *   2. No hay mapa (cuenta manual de F0) o la venta no tiene caja (NC
     *      emitida desde el panel) → el stamp cacheado global. Es el fallback
     *      HISTÓRICO y sigue siendo válido: no hay otra caja a la que robarle
     *      el punto de expedición.
     *   3. HAY mapa y la venta SÍ tiene caja, pero esa caja no está en él
     *      (caja sin timbrado) → **ERROR, nunca el fallback**. Hasta
     *      2026-09-06 este caso caía en silencio al stamp global: una venta de
     *      la "Segunda Caja" se emitía con el punto de expedición de la
     *      principal — dos cajas alimentando la misma numeración, que es
     *      exactamente el escenario de facturas duplicadas que el modelo
     *      por-caja de `context/29` existe para impedir (auditoría
     *      2026-09-06). El error es legible y cae en `markError`, así el
     *      comercio ve QUÉ caja le falta timbrar en vez de emitir mal.
     *
     * Devuelve el shape que espera SaleToFePyMapper ('Id').
     *
     * @param array|\ArrayAccess $account Fila de einvoice_account (con provisioning y stamp).
     * @return array<string,mixed>
     * @throws \RuntimeException caso 3 — caja conocida sin timbrado en el mapa.
     */
    /**
     * `securityCode` estable del documento: los 9 dígitos del componente 10
     * del CDC, congelados en la fila del outbox en el PRIMER intento.
     *
     * Por qué no se genera en el mapper (donde estaba): el mapper corre una
     * vez por INTENTO. Si el envío se corta por timeout después de que el
     * motor ya creó el documento, el reintento salía con otro
     * securityCode y por lo tanto con OTRO CDC para la misma venta — que es
     * el rechazo 1002 de SIFEN por duplicado, con el agravante de que el
     * primer documento igual quedó emitido.
     *
     * Una reemisión (mig 201) es una fila NUEVA del outbox: llega acá con
     * `security_code` en NULL y genera el suyo, que es lo correcto — es otro
     * documento fiscal, no el mismo.
     */
    private function ensureSecurityCode(string $docId, mixed $stored): string
    {
        $code = trim((string) ($stored ?? ''));
        if (preg_match('/^\d{9}$/', $code) === 1) {
            return $code;
        }

        $code = Cdc::securityCode();
        // `security_code IS NULL` en el WHERE: si dos drenajes corrieran a la
        // vez sobre la misma fila, el segundo no pisa el código del primero.
        // Se relee para devolver el que efectivamente quedó guardado.
        ncmExecute(
            'UPDATE einvoice_document SET security_code = ?, updated_at = now()
              WHERE einvoicedocid = ? AND security_code IS NULL',
            [$code, $docId]
        );
        $row = ncmExecute('SELECT security_code FROM einvoice_document WHERE einvoicedocid = ?', [$docId]);
        $persisted = trim((string) ($row['security_code'] ?? ''));

        return preg_match('/^\d{9}$/', $persisted) === 1 ? $persisted : $code;
    }

    /**
     * Verificación ESTRUCTURAL del CDC devuelto contra la venta que se emitió.
     * Devuelve la descripción de la discrepancia, o null si todo coincide.
     *
     * Nunca lanza: se llama DESPUÉS de que el documento salió, y no poder
     * verificar no puede convertir una emisión exitosa en un fallo. Si algo
     * del propio chequeo revienta, se registra como "no se pudo verificar",
     * que es información distinta de "no coincide" y sin embargo igual de
     * visible — el silencio es el único resultado inaceptable acá.
     *
     * ── Qué se compara y qué NO ──────────────────────────────────────────
     *
     * SÍ: número, RUC del emisor, establecimiento y punto de expedición.
     * Son estables, inequívocos y los cuatro salen de datos que tenemos
     * congelados. El NÚMERO es el que importa de verdad: es el único que el
     * proveedor podría reescribir por su cuenta, y es justo el que el cliente
     * ya tiene impreso.
     *
     * NO la FECHA, a propósito. El CDC lleva la fecha de emisión y el
     * documento puede cruzar la medianoche entre que lo mandamos y que el
     * proveedor lo procesa (o diferir por zona horaria). Compararla haría que
     * una venta de las 23:59 marcara discrepancia y dejara de imprimir un CDC
     * perfectamente válido. Un falso positivo acá SUPRIME el CDC de un
     * comprobante bueno, así que el chequeo se limita a lo que no puede dar
     * falsos positivos. `Cdc::assertMatchesSale()` sí sabe comparar la fecha —
     * se usa en el arnés y para diagnóstico manual, no en este camino.
     *
     * Tampoco el tipo de documento: nuestro doctype interno ('FC'/'FCR'/'NC')
     * no es el código de dos dígitos de la SET, y mapearlo acá crearía una
     * segunda tabla de equivalencias que puede divergir de la del mapper.
     *
     * @param array<string,mixed> $stamp  Timbrado con el que se emitió ('Id').
     * @param array<string,mixed> $sale   Venta reconstruida (fiscalNumber).
     * @param array<string,mixed> $config Config de la cuenta.
     */
    private function cdcMismatchFor(
        string $companyId,
        $account,
        array $point,
        array $sale,
        string $doctype,
        array $config,
        string $cdc
    ): ?string {
        try {
            $expected = [];

            // TODO documento que emitimos lleva número PROPIO, así que el
            // guard exige siempre los cuatro componentes del CDC.
            //
            // La NOTA DE CRÉDITO ya NO está exceptuada (context/40 F3).
            // Mientras la numeraba el motor, exigirle el número al CDC habría
            // marcado discrepancia en todas. Ahora la numeramos nosotros, así
            // que la NC entra al guard más importante del pipeline: si vuelve
            // un CDC con OTRO número, queda `issued` con `numbering_mismatch`
            // y su CDC/QR no se imprimen ni se publican en el portal — antes
            // ese cambio de número pasaba sin que nadie se enterara.
            //
            // Tampoco queda el kill-switch `legacyAutoNumbering`, que
            // desactivaba esta comprobación entera: era de cuando el proveedor
            // numeraba, y hoy Punto es dueño de la numeración fiscal.
            $number = is_numeric($sale['fiscalNumber'] ?? null) ? (int) $sale['fiscalNumber'] : 0;
            if ($number > 0) {
                $expected['number'] = $number;
            }

            // RUC del emisor, sin DV (el CDC lo lleva en un componente aparte).
            $emitter = $this->decodeJsonb($account['emitter'] ?? null);
            $ruc     = trim((string) ($emitter['Ruc'] ?? $emitter['ruc'] ?? ''));
            if ($ruc !== '') {
                $expected['ruc'] = explode('-', $ruc)[0];
            }

            // Establecimiento y punto de expedición con los que se emitió:
            // salen de la CAJA de la venta (`fePyPointForDocument()`), que es
            // el punto de expedición según `context/29`.
            //
            // Es una lectura LOCAL y sin red, a diferencia de la que había
            // acá antes —una consulta al talonario del proveedor anterior—.
            // Eso importa más de lo que parece: aquel camino podía fallar por
            // un corte transitorio y dejaba el CDC sin verificar en esos dos
            // componentes, justo mientras el proveedor estaba inestable. Ahora
            // el dato ya está en memoria, así que el guard verifica SIEMPRE
            // los cuatro componentes.
            $establishment = trim((string) ($point['establecimiento'] ?? ''));
            $expedition    = trim((string) ($point['punto'] ?? ''));
            if ($establishment !== '') {
                $expected['establishment'] = $establishment;
            }
            if ($expedition !== '') {
                $expected['expeditionPoint'] = $expedition;
            }

            $problems = Cdc::assertMatchesSale($cdc, $expected);
            if ($problems === []) {
                return null;
            }

            return 'El documento electrónico no coincide con el comprobante que se imprimió: '
                . implode('; ', $problems)
                . '. El CDC y el QR no se imprimen ni se publican en el portal hasta que esto se resuelva.';
        } catch (\Throwable $e) {
            // NO se marca el documento. Llegar acá significa que el chequeo
            // se rompió, no que haya encontrado algo: marcar sobre una falla
            // propia haría que un bug o un dato faltante nuestro suprimiera
            // el CDC de una factura correcta, de forma permanente y sin
            // camino de reversión (nada vuelve a evaluar este flag).
            //
            // Es una decisión consciente sobre CUÁL error preferir. Marcar de
            // más rompe facturas buenas a la primera intermitencia; marcar de
            // menos deja pasar un caso solo si la discrepancia REAL coincide
            // con una falla del verificador, que además queda logueada acá.
            error_log('[EInvoiceService] no se pudo verificar el CDC de la venta (documento NO marcado): '
                . $e->getMessage());

            return null;
        }
    }

    /**
     * `DCarQR` — el link del QR de ekuatía, ya armado y firmado con su hash
     * por el emisor. Es lo que imprime el KuDE y lo que se le muestra al
     * comprador en el portal.
     *
     * ÚNICO lugar del código que sabe dónde vive ese dato dentro de la
     * respuesta cruda de `/Bulk`. Antes el bucle estaba inline en el portal;
     * cuando la impresión necesitó el mismo valor, copiarlo habría dejado dos
     * lecturas de un shape que no controlamos, que divergen en cuanto el
     * proveedor cambie una mayúscula.
     *
     * Función PURA sobre el JSONB ya decodificado: los dos llamadores
     * (`publicDocument()` y `TransactionDetailService`) ya traen la fila por
     * otros motivos, así que esto no agrega ni una query.
     *
     * @param mixed $providerResponse `provider_response` decodificado (o el crudo).
     */
    public static function extractQrUrl(mixed $providerResponse): ?string
    {
        if (is_string($providerResponse)) {
            $providerResponse = json_decode($providerResponse, true);
        }
        if (!is_array($providerResponse)) {
            return null;
        }

        // FE-PY lo devuelve PLANO, en `qrUrl` (su commit e424023): es el
        // `dCarQR` exacto extraído del XML firmado, ya desescapado y listo para
        // codificar en el QR. Se lee PRIMERO porque es el motor vigente.
        //
        // No se recalcula por nuestra cuenta y no es un detalle de comodidad:
        // la cadena lleva `cHashQR` —un hash con el CSC— además de
        // `DigestValue` e `IdCSC`. Reimplementarlo sería una segunda versión
        // del mismo dato firmado, y el día que difieran imprimiríamos un QR
        // que no valida contra el documento que el emisor firmó.
        $flat = $providerResponse['qrUrl'] ?? $providerResponse['dCarQR'] ?? null;
        if (is_string($flat) && trim($flat) !== '') {
            return trim($flat);
        }

        // Shape anidado del proveedor anterior. Se conserva para los documentos
        // ya emitidos con él, cuyo `provider_response` quedó guardado así.
        foreach ((array) ($providerResponse['Items'] ?? $providerResponse['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qr = $item['DCarQR'] ?? $item['dCarQR'] ?? null;
            if (is_string($qr) && trim($qr) !== '') {
                return trim($qr);
            }
        }

        return null;
    }

    /**
     * Lo que se puede IMPRIMIR de una venta: el CDC y el QR de ekuatía, o null
     * si esta venta no tiene documento electrónico imprimible.
     *
     * Único lugar que define qué significa "imprimible", y son cuatro
     * condiciones que tienen que darse juntas:
     *
     *   - `status='issued'` — el documento salió (antes de eso no hay CDC).
     *   - `superseded_by IS NULL` — no es una emisión reemplazada (mig 201);
     *     el comprobante vigente es el que la sucede.
     *   - `numbering_mismatch IS NULL` — el guard de numeración (mig 204) no
     *     lo marcó; si lo marcó, su CDC describe OTRO documento.
     *   - `cdc IS NOT NULL` — defensivo.
     *
     * Existe como UN método porque el predicado es el mismo para todos los
     * consumidores (impresión de hoja, rollo, reimpresión desde el panel) y
     * repetirlo en cada query es cómo se termina con una superficie que
     * imprime un CDC que otra ya considera inválido. Es exactamente la clase
     * de regla que no puede vivir en el call-site.
     *
     * @return array{cdc: string, qrUrl: ?string}|null
     */
    public function printableDocumentFor(string $companyId, string $transactionId): ?array
    {
        $row = ncmExecute(
            "SELECT cdc, sifen_status, provider_response FROM einvoice_document
              WHERE companyid = ? AND transactionid = ? AND status = 'issued'
                AND superseded_by IS NULL
                AND numbering_mismatch IS NULL
                AND cdc IS NOT NULL
              ORDER BY issued_at DESC NULLS LAST LIMIT 1",
            [$companyId, $transactionId]
        );
        if (!$row || trim((string) ($row['cdc'] ?? '')) === '') {
            return null;
        }

        // UN RECHAZADO NO SE IMPRIME.
        //
        // Tener CDC no prueba nada: el motor lo calcula al GENERAR el XML y el
        // QR se arma al FIRMARLO, los dos antes de que el documento salga hacia
        // SIFEN. Un rechazado llega acá con las dos cosas pobladas (confirmado
        // por el equipo de FE-PY, 2026-09-10), así que filtrar por "hay cdc"
        // —que es lo que hacía esta consulta— deja pasar un documento sin
        // ningún efecto fiscal y lo imprime en el ticket como si valiera.
        //
        // El PENDIENTE sí se imprime, y no es una concesión: el ticket sale en
        // el mostrador segundos después de la venta, cuando SIFEN todavía no
        // contestó. Exigir el veredicto dejaría sin CDC al caso NORMAL. Por eso
        // la regla es excluir el rechazo, no exigir la aprobación.
        //
        // Mismo predicado que usa la entrega del KuDE (`deliveryBlockerForRow`):
        // el criterio de "esto tiene efecto fiscal" vive en UN solo lugar.
        if (self::sifenVerdict($row['sifen_status'] ?? null) === 'rejected') {
            return null;
        }

        return [
            'cdc'   => (string) $row['cdc'],
            'qrUrl' => self::extractQrUrl($this->decodeJsonb($row['provider_response'] ?? null)),
        ];
    }

    /**
     * Atajo: solo el link del QR de ekuatía de la venta. Misma definición de
     * "imprimible" que `printableDocumentFor()`, del que sale — un QR que
     * apunta a otro documento es peor que no tener QR.
     */
    public function qrUrlFor(string $companyId, string $transactionId): ?string
    {
        return $this->printableDocumentFor($companyId, $transactionId)['qrUrl'] ?? null;
    }

    /**
     * Escribe UNA hoja de `einvoice_account.provisioning`, en el camino
     * `<bucket>.<key>`, sin leer-modificar-escribir.
     *
     * Por qué no el `mergeProvisioning()` de EInvoiceProvisioningService (que
     * hace `provisioning || ?::jsonb`): ese merge es **superficial**, así que
     * para agregar un timbrado al caché hay que mandar el sub-objeto ENTERO,
     * y el sub-objeto entero se arma en PHP desde una foto de `provisioning`
     * leída al empezar a procesar el documento. Dos `drain()` concurrentes
     * sobre la misma company y timbrados distintos leen dos fotos, cada una
     * sin la entrada de la otra, y el segundo UPDATE borra lo que cacheó el
     * primero. No es un riesgo fiscal —el pre-flight es idempotente y volver
     * a verificarlo es seguro— pero anula el "una vez por timbrado" y hace
     * que un mal momento del motor reaparezca como error de emisión.
     *
     * Acá el valor se calcula DENTRO de la misma sentencia: el `||` solo
     * garantiza que el bucket exista (preservándolo si ya estaba, porque
     * `jsonb_set` no crea niveles intermedios) y el `jsonb_set` toca
     * únicamente la hoja. Un solo statement, sin ventana entre lectura y
     * escritura.
     *
     * @param string $bucket Clave de primer nivel ('numberingPreflight', 'stampDetails').
     * @param string $key    Clave de segundo nivel (el id del timbrado).
     * @param mixed  $value  Valor de la hoja, serializable a JSON.
     */
    private function setProvisioningLeaf(string $companyId, string $bucket, string $key, mixed $value): void
    {
        ncmExecute(
            "UPDATE einvoice_account
                SET provisioning = jsonb_set(
                      COALESCE(provisioning, '{}'::jsonb)
                        || jsonb_build_object(?::text, COALESCE(provisioning -> ?::text, '{}'::jsonb)),
                      ARRAY[?::text, ?::text],
                      ?::jsonb,
                      true
                    ),
                    updated_at = now()
              WHERE companyid = ?",
            [$bucket, $bucket, $bucket, $key, json_encode($value, JSON_UNESCAPED_UNICODE), $companyId]
        );
    }

    /**
     * Reintentos automáticos antes de plantarse. Ocho intentos con backoff
     * exponencial son ~8h30 de ventana: si el motor rechazó todo ese tiempo,
     * el problema pide una persona, no más reintentos ciegos.
     */
    private const MAX_RETRY_ATTEMPTS = 8;

    private function markError(string $docId, int $attemptsBefore, string $message, ?string $txnId = null, ?array $providerResponse = null): void
    {
        ncmExecute(
            // El `txnId` viaja en el MISMO UPDATE que marca el error, y con
            // COALESCE para que sea write-once (mig 217): es la llave con la
            // que después se averigua si el documento igual quedó emitido del
            // otro lado. Escribirlo aparte abriría una ventana en la que la
            // fila dice `error` y no tiene con qué recuperarse.
            // `provider_response` sólo se pisa cuando el caller trae algo que
            // valga la pena guardar (hoy: el documento crudo que devolvió la
            // recuperación). Con null se conserva lo que ya estaba — un error
            // de red no puede borrar la respuesta del intento que sí llegó.
            "UPDATE einvoice_document
                SET status = 'error', attempts = attempts + 1, error_message = ?,
                    provider_txn_id = COALESCE(provider_txn_id, ?),
                    provider_response = COALESCE(?::jsonb, provider_response),
                    next_retry_at = ?, updated_at = now()
              WHERE einvoicedocid = ?",
            [
                mb_substr($message, 0, 500),
                ($txnId !== null && trim($txnId) !== '') ? trim($txnId) : null,
                $providerResponse !== null ? json_encode($providerResponse, JSON_UNESCAPED_UNICODE) : null,
                $this->nextRetryAt($attemptsBefore + 1),
                $docId,
            ]
        );
    }

    /**
     * Backoff exponencial: 2^attempts minutos hasta el intento 8, y después
     * SE PLANTA.
     *
     * El `min($attempts, 8)` de antes capeaba el INTERVALO, no la cantidad de
     * intentos: a partir del octavo reintentaba cada 4h20 para siempre. El
     * docblock ya decía "a partir de ahí queda en error visible sin más
     * reintento automático" — la intención estaba bien, el código hacía otra
     * cosa.
     *
     * No era teórico: el proveedor nos reportó (2026-09-09) decenas de
     * intentos repitiéndose todo el día sobre documentos viejos, incluido un
     * número que ya había sido emitido, aprobado y anulado. Cada reintento
     * crea una fila del otro lado.
     *
     * El corte NO se hace dejando `next_retry_at` en NULL: esa columna es NOT
     * NULL y el UPDATE reventaría (verificado contra el esquema de producción
     * antes de shippear). Se hace en el WHERE del drainer, que ahora exige
     * `attempts < MAX_RETRY_ATTEMPTS` — la fila conserva su fecha, queda
     * quieta y visible en `error`, y el estado sigue siendo legible.
     *
     * No es un abandono: `retry()` la vuelve a poner en `pending` con
     * `next_retry_at = now()` cuando una persona decide reintentarla, que es
     * justo lo que hace falta cuando el motor rechazó ocho veces seguidas.
     */
    private function nextRetryAt(int $attempts): string
    {
        $capped = min($attempts, self::MAX_RETRY_ATTEMPTS);
        return date('c', time() + (2 ** $capped) * 60);
    }

    /**
     * Bruto de una línea de venta (`meta.transactionDetails`), para decidir
     * si es facturable Y para declararla en el documento — un solo cálculo,
     * dos usos (ver comentario en `buildSaleArrayForMapper`). Congelada
     * (F2a): `taxNet + taxAmount`, que ya aplicó descuento y modo
     * incluido/añadido. Sin congelar (venta pre-F2a): `total - totalDiscount`,
     * que solo es correcto en modo "incluido" pero es el único dato
     * disponible para esas ventas viejas.
     */
    private static function lineNetForSale(array $sD): float
    {
        if (isset($sD['taxNet'], $sD['taxAmount'])) {
            return (float) $sD['taxNet'] + (float) $sD['taxAmount'];
        }
        return (float) ($sD['total'] ?? 0) - (float) ($sD['totalDiscount'] ?? 0);
    }

    /**
     * Valida que una tasa (congelada o resuelta del catálogo) sea una de las
     * que SIFEN admite en el DE paraguayo (10|5|0) — el `match` que antes
     * era "de dónde sale la tasa" pasa a ser esto: un guard de formato del
     * documento fiscal, no la fuente. `exempt` siempre entra como 0.
     *
     * Un solo lugar para las dos líneas de invocación (factura y nota de
     * crédito) — evitar duplicar el mensaje/las tasas válidas por doctype.
     */
    private function assertSifenRate(float $rate, string $kind, string $itemLabel): int
    {
        if ($kind === 'exempt') {
            return 0;
        }
        $normalized = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
        return match ($normalized) {
            '10' => 10,
            '5'  => 5,
            '0'  => 0,
            default => throw new \RuntimeException(
                "No se puede facturar: el ítem \"{$itemLabel}\" tiene una tasa de IVA congelada de {$rate}%, "
                . 'y SIFEN (Paraguay) solo admite 10%, 5% o exentas en el documento electrónico — '
                . 'revisá la tasa del comercio para este ítem.'
            ),
        };
    }

    /**
     * Resuelve el `taxRate` (10|5|0) de cada línea facturable. F3a: la
     * fuente primaria es lo CONGELADO por línea al vender/devolver
     * (`taxRate`/`taxKind` en `$lines`, puestos por `SaleService::
     * enrichWithTaxes()` — F2a) — el documento fiscal declara lo que se
     * vendió, no lo que el catálogo dice hoy.
     *
     * El catálogo (`item.taxId` → `tax.rate`/`tax.kind`, con `tax.name`
     * como último fallback legacy — mismo criterio que
     * `TaxService::deriveRateKindFromName`) solo se consulta para líneas
     * SIN congelado: ventas anteriores al deploy de F2a, que no tienen
     * `taxRate` en `meta.transactionDetails`. Si todas las líneas están
     * congeladas no se ejecuta ninguna query.
     *
     * @param array<int,array<string,mixed>> $lines líneas ya filtradas (facturables), cada
     *        una con `itemId` y opcionalmente `taxRate`/`taxKind` congelados
     * @return array<string,int> itemId => taxRate SIFEN (10|5|0)
     */
    private function resolveTaxRatesForItems(string $companyId, array $lines): array
    {
        $resolved = [];
        $missingIds = [];

        foreach ($lines as $sD) {
            $itemId = (string) $sD['itemId'];
            $label  = (string) ($sD['name'] ?? $sD['itemName'] ?? $itemId);
            $rate   = $sD['taxRate'] ?? null;
            $kind   = $sD['taxKind'] ?? null;

            if ($rate !== null && $kind !== null) {
                $resolved[$itemId] = $this->assertSifenRate((float) $rate, (string) $kind, $label);
                continue;
            }

            $missingIds[$itemId] = $label;
        }

        if ($missingIds === []) {
            return $resolved;
        }

        // Fallback SOLO para líneas sin congelado (ventas pre-F2a).
        $itemIds = array_keys($missingIds);
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $rs = ncmExecute(
            "SELECT item.itemId AS itemId, item.itemName AS itemName,
                    tax.rate AS taxRate, tax.kind AS taxKind, tax.name AS taxName
               FROM item
               LEFT JOIN tax ON tax.taxId = item.taxId AND tax.companyId = item.companyId
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

        while (!$rs->EOF) {
            $itemId = (string) $rs->fields['itemid'];
            $itemName = (string) ($rs->fields['itemname'] ?? $itemId);
            $rawRate = $rs->fields['taxrate'] ?? null;
            $rawKind = $rs->fields['taxkind'] ?? null;
            $rawName = $rs->fields['taxname'] ?? null;

            if ($rawRate !== null && $rawKind !== null) {
                $rate = (float) $rawRate;
                $kind = (string) $rawKind;
            } elseif ($rawName !== null && trim((string) $rawName) !== '') {
                // Legacy: sin columnas tipadas en `tax` (fila no migrada por
                // la mig 120), se parsea el label — mismo criterio que
                // TaxService::deriveRateKindFromName.
                [$rate, $kind] = \Punto\Api\Taxes\TaxService::deriveRateKindFromName((string) $rawName);
            } else {
                throw new \RuntimeException(
                    "No se puede facturar: el ítem \"{$itemName}\" no tiene un impuesto configurado."
                );
            }

            $resolved[$itemId] = $this->assertSifenRate($rate, $kind, $itemName);
            $rs->MoveNext();
        }

        foreach ($itemIds as $itemId) {
            if (!array_key_exists($itemId, $resolved)) {
                $label = $missingIds[$itemId];
                throw new \RuntimeException(
                    "No se puede facturar: no se encontró el ítem \"{$label}\" (o fue borrado) para resolver su impuesto."
                );
            }
        }

        return $resolved;
    }

    /**
     * Reconstruye el shape `$sale` que espera `SaleToFePyMapper::build()`
     * a partir de la venta persistida. Devuelve null si la transacción no
     * existe (no debería pasar — el outbox se encola desde una venta recién
     * insertada — pero es defensivo ante una fila borrada/corrupta).
     */
    /**
     * ¿Cada ítem es un SERVICIO? Alimenta el `transactionTypeCode` del
     * documento (1 mercadería / 2 servicios / 3 mixto), que estaba fijo en
     * "servicios" para todas las ventas de todos los rubros.
     *
     * El dato es el `itemKind` del catálogo (mig 15): `servicio` y
     * `servicio_sesiones` son los dos kinds que Punto modela como
     * prestación; todo lo demás —producto, producción, combo, giftcard,
     * descuento— se declara como mercadería.
     *
     * Se lee del catálogo ACTUAL y no de un congelado en la venta porque no
     * hay congelado: `meta.transactionDetails` no guarda el kind. Es una
     * imprecisión acotada y conocida (si un ítem cambia de kind, una NC vieja
     * podría declarar distinto que su factura); congelarlo requiere tocar el
     * shape que persiste la venta y no entra en este lote.
     *
     * Un ítem borrado no aparece en el resultado y el caller lo cuenta como
     * mercadería, que es el default correcto para la mayoría del catálogo.
     *
     * @param array<int,string> $itemIds
     * @return array<string,bool> itemId => esServicio
     */
    private function resolveServiceFlagsForItems(string $companyId, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter($itemIds, static fn ($id): bool => (string) $id !== '')));
        if ($itemIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $rs = ncmExecute(
            "SELECT itemId, itemKind FROM item
              WHERE companyId = ? AND itemId IN ($placeholders)",
            array_merge([$companyId], $itemIds),
            false,
            true
        );
        if ($rs === false) {
            // No se pudo leer el catálogo: no se aborta la emisión por esto.
            // Todo cae a mercadería, que es el default del mapper.
            error_log('[EInvoice] no se pudieron leer los kinds de los ítems — transactionTypeCode cae a mercadería.');
            return [];
        }

        $flags = [];
        while (!$rs->EOF) {
            $kind = (string) ($rs->fields['itemkind'] ?? '');
            $flags[(string) $rs->fields['itemid']] = in_array($kind, ['servicio', 'servicio_sesiones'], true);
            $rs->MoveNext();
        }
        $rs->Close();

        return $flags;
    }

    /**
     * `issuedDate` del documento: la fecha en que se OPERÓ la venta, en el
     * reloj del tenant y en formato naive `YYYY-MM-DDTHH:MM:SS`.
     *
     * Si la venta no trae fecha (fila corrupta), cae a "ahora" — un documento
     * sin fecha no se puede emitir y es preferible declarar el momento del
     * envío que abortar la facturación de una venta ya cobrada.
     *
     * @param array<string,mixed> $sale
     */
    private function issuedDateFor(string $companyId, array $sale): string
    {
        $raw = trim((string) ($sale['transactionDate'] ?? ''));
        $ts  = $raw !== '' ? strtotime($raw) : false;
        if ($ts === false) {
            error_log("[EInvoice] venta sin transactionDate utilizable ('$raw') — issuedDate cae al momento del envío.");
            $ts = time();
        }
        return str_replace(' ', 'T', \Punto\Api\Support\TenantClock::atInstant($companyId, $ts));
    }

    private function buildSaleArrayForMapper(string $companyId, string $transactionId, string $doctype): ?array
    {
        if ($doctype === 'NC') {
            return $this->buildCreditNoteArrayForMapper($companyId, $transactionId);
        }

        $tx = ncmExecute(
            // invoiceNo + invoiceAuth (mig 145): el correlativo y el timbrado
            // CONGELADOS al emitir la venta. Son los que salieron impresos en
            // el ticket y los que viajan al documento electrónico — el
            // comprobante impreso es la representación impresa de la factura
            // electrónica, tienen que llevar el MISMO número.
            // transactionDate: la fecha de la OPERACIÓN, que es la que
            // declara el documento (ver issuedDateFor()). Sin ella, una venta
            // drenada tarde declaraba la fecha del envío.
            "SELECT transactionType, transactionTotal, transactionDiscount, transactionCurrency, transactionDueDate,
                    customerId, transactionPaymentType, invoiceNo, invoiceAuth, transactionDate, meta
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

        // Filtra ítems facturables ANTES de resolver tasas, para no pedir la tasa
        // de líneas que ni van a entrar al documento (inCredit, gift card, cantidad/total inválidos).
        // Usa la MISMA fórmula de bruto que después arma `$items` (self::lineNetForSale)
        // — antes de F3a el filtro y el armado de líneas usaban fórmulas distintas
        // (`total - totalDiscount` acá, `taxNet + taxAmount` allá para congeladas),
        // lo que podía incluir/excluir una línea con el número equivocado en modo
        // "añadido" (donde las dos fórmulas divergen). Un solo cálculo, dos usos.
        $billableDetail = [];
        foreach ($saleDetail as $sD) {
            if (empty($sD['itemId']) || ($sD['type'] ?? '') === 'giftcard') {
                continue;
            }
            // Canje de voucher (context/36, decisión 5): la línea lleva el
            // total BRUTO como registro de lo entregado, pero esa plata NO
            // está en transactionTotal — el vale ya se cobró (y devengó su
            // IVA) en la venta que lo emitió. Mismo criterio que el motor de
            // impuestos (enrichWithTaxes la fuerza exenta y la excluye de los
            // buckets del Libro Ventas): el DE de ESTA venta no la declara.
            // Sin este filtro, la línea entraba a $items y a Σ(total) y el
            // documento declaraba ingresos que nunca se cobraron — toda venta
            // con vale quedaba infacturable o, peor, facturada de más.
            if (is_array($sD['voucher'] ?? null)) {
                continue;
            }
            $count = (float) ($sD['count'] ?? 0);
            $lineNet = self::lineNetForSale($sD);
            if ($count <= 0 || $lineNet == 0.0) {
                continue;
            }
            $billableDetail[] = $sD;
        }

        $taxRateByItemId = $this->resolveTaxRatesForItems($companyId, $billableDetail);
        $serviceByItemId = $this->resolveServiceFlagsForItems(
            $companyId,
            array_map(static fn (array $sD): string => (string) $sD['itemId'], $billableDetail)
        );

        $items = [];
        foreach ($billableDetail as $sD) {
            $itemId = (string) $sD['itemId'];
            $count = (float) ($sD['count'] ?? 0);
            $lineNet = self::lineNetForSale($sD);
            $items[] = [
                'description' => (string) ($sD['name'] ?? ''),
                'quantity'    => $count,
                // `round($lineNet / $count, 8)` NO cerraba: el payload no
                // lleva total por ítem y SIFEN lo recalcula multiplicando, así
                // que 10.000 en 3 unidades declaraba 9.999,99999999. El
                // unitario definitivo lo deriva el mapper de total/quantity en
                // los decimales de la moneda, partiendo la línea si hace falta
                // (SaleFiscalRules::fiscalLines). Acá va como referencia;
                // el dato que manda es `total`.
                'unitPrice'   => round($lineNet / $count, 8),
                'total'       => $lineNet,
                'taxRate'     => $taxRateByItemId[$itemId],
                'isService'   => $serviceByItemId[$itemId] ?? false,
            ];
        }

        // Total del documento = Σ de líneas facturables SIEMPRE — congeladas
        // o no —, así nunca diverge de lo que `$items` declara línea por línea
        // (el DE se rechaza si no cierra). Antes de este fix, el
        // fallback pre-F2a sumaba `transactionTotal - transactionDiscount`
        // por separado, que podía divergir de Σ($items) en ventas con líneas
        // mixtas frozen/no-frozen. Ojo: esto deja AFUERA lo que
        // `transactionTotal`/`transactionDiscount` sí suman y
        // `$billableDetail` excluye (giftcard, inCredit, líneas con count<=0
        // o neto 0) — para esas ventas el total del DE es menor al de la
        // venta completa, que es correcto (un DE fiscal no declara
        // giftcards ni movimientos in-credit), pero si algún día una línea
        // excluida SÍ debiera facturarse, este total no lo va a reflejar y
        // hay que revisar el filtro de arriba, no este cálculo.
        if ($items !== []) {
            $total = 0.0;
            foreach ($items as $item) {
                $total += (float) $item['total'];
            }
        } else {
            // Sin líneas facturables (venta 100% giftcard/inCredit, caso
            // degenerado): no hay de dónde sumar, se cae al total de
            // transacción como estaba antes de F3a.
            $total = (float) ($tx['transactionTotal'] ?? 0) - (float) ($tx['transactionDiscount'] ?? 0);
        }

        $client = $this->resolveClient($companyId, $tx['customerId'] ?? null);

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
            'transactionDate'     => (string) ($tx['transactionDate'] ?? ''),
            // `transactionCurrency` es nullable en el schema. El `?? 'PYG'` que
            // había acá NO era un default inocente: convertía "no sé en qué
            // moneda se vendió" en "se vendió en guaraníes", justo antes de
            // declarar los montos ante el fisco. Ahora un NULL se propaga como
            // moneda vacía y el mapper corta con un error explícito — que es lo
            // que corresponde: la moneda de un documento fiscal se sabe o no se
            // emite. Si el dato falta, se resuelve por el país del comercio,
            // nunca por un código cableado.
            'currency'            => self::resolveCurrency($companyId, $tx['transactionCurrency'] ?? null),
            'operationCondition'  => $operationCondition,
            'items'               => $items,
            'client'              => $client,
            'payments'            => $paymentLines,
            // Número y timbrado del EMISOR (nosotros). Se pasan crudos: la
            // regla de qué se manda como número vive en UN solo lugar, en el
            // mapper. La coherencia contra lo que el motor terminó asignando
            // se verifica DESPUÉS, sobre el CDC devuelto (`cdcMismatchFor()`).
            'fiscalNumber'        => isset($tx['invoiceNo']) && is_numeric($tx['invoiceNo']) ? (int) $tx['invoiceNo'] : null,
            'fiscalAuth'          => trim((string) ($tx['invoiceAuth'] ?? '')),
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

    /**
     * Receptor del documento a partir del contacto de la transacción. Tres
     * casos fiscales (ver SaleToFePyMapper::buildClient): contribuyente con
     * RUC, persona física con documento (CI paraguaya o extranjero), o
     * innominado (consumidor final).
     *
     * `idType` (Tabla 3 SET — ver ContactService::ID_TYPE_*) viaja siempre en
     * el shape devuelto: el valor persistido en `contactIdType`, o inferido
     * con la MISMA regla que ContactService::presentRow() para contactos de
     * antes de esta feature (ContactService::inferIdType() — single source
     * of truth, no se duplica la regla acá). El mapper lo traduce al código
     * de SIFEN (catálogo distinto, ver su tabla de traducción).
     *
     * @return array<string,mixed>
     */
    /**
     * Moneda del documento electrónico, sin inventarla.
     *
     * `transaction.transactionCurrency` es nullable: las ventas viejas y las
     * que no declaran moneda llegan acá en NULL. El `?? 'PYG'` que había en los
     * dos call-sites enmascaraba ese NULL — le ponía "guaraníes" a una venta de
     * moneda desconocida y seguía de largo hacia el envío a SIFEN.
     *
     * Orden correcto: lo que la venta declaró; si no declaró nada, la moneda
     * que corresponde al PAÍS del comercio; y si eso tampoco se puede resolver,
     * cadena vacía para que SaleToFePyMapper aborte con un mensaje claro.
     * Nunca un código de moneda cableado.
     *
     * (Que SIFEN sea paraguayo no justifica el default: el resto del pipeline
     * ya está gateado por país. Lo que se corrige acá es el enmascaramiento del
     * NULL, que también tapaba ventas en moneda extranjera de un tenant PY.)
     */
    /**
     * URL pública del logo del comercio, o null si no cargó ninguno.
     *
     * El cache-bust (`?v=<timestamp>`) es el mismo que usa el panel: sin él,
     * un comercio que cambia su logo sigue viendo el viejo en el portal de sus
     * clientes hasta que expire el caché del navegador.
     */
    private static function companyLogoUrl(mixed $url, mixed $stamp): ?string
    {
        $url = trim((string) ($url ?? ''));
        if ($url === '') {
            return null;
        }
        $stamp = trim((string) ($stamp ?? ''));
        return $stamp !== '' ? $url . '?v=' . $stamp : $url;
    }

    private static function resolveCurrency(string $companyId, mixed $stored): string
    {
        $currency = strtoupper(trim((string) ($stored ?? '')));
        if ($currency !== '') {
            return $currency;
        }

        return (string) (\Punto\Api\Support\TenantLocale::currencyCode($companyId) ?? '');
    }

    private function resolveClient(string $companyId, mixed $clientId): array
    {
        if ($clientId === null || $clientId === '') {
            return ['nature' => 'innominado', 'idType' => \Punto\Api\Contacts\ContactService::ID_TYPE_SIN_NOMBRE];
        }

        $contact = ncmExecute(
            'SELECT contactTIN, contactName, contactIdType, contactEmail, contactPhone, data
               FROM contact WHERE contactId = ? AND companyId = ?',
            [$clientId, $companyId]
        );
        if (!$contact) {
            return ['nature' => 'innominado', 'idType' => \Punto\Api\Contacts\ContactService::ID_TYPE_SIN_NOMBRE];
        }

        $tin  = trim((string) ($contact['contactTIN'] ?? ''));
        $ci   = trim((string) ($contact['contactCI'] ?? '')); // flattenJsonb ya trajo contactCI desde `data`
        $name = trim((string) ($contact['contactName'] ?? ''));

        // Datos de contacto del receptor. El documento los declara SIEMPRE
        // (aunque vacíos), y hasta ahora salían vacíos incluso teniéndolos
        // cargados porque este resolver no los devolvía: el mapper los leía
        // de un shape que nunca los traía. `contactAddress` vive en el JSONB
        // `data` (mig 25) y llega aplanado por seleccionar `data`;
        // contactEmail/contactPhone siguen siendo columnas.
        //
        // El teléfono va SIN '+' — convención de storage del repo (mig 67).
        // El ltrim es defensivo: filas anteriores a esa migración podrían
        // tenerlo, y el '+' viajando a SIFEN es un dato mal declarado.
        $contactBits = [
            'address' => trim((string) ($contact['contactAddress'] ?? '')),
            'email'   => trim((string) ($contact['contactEmail'] ?? '')),
            'phone'   => ltrim(trim((string) ($contact['contactPhone'] ?? '')), '+'),
        ];

        $storedIdType = $contact['contactIdType'] ?? null;
        $idType = $storedIdType !== null
            ? (int) $storedIdType
            : \Punto\Api\Contacts\ContactService::inferIdType($tin !== '' ? $tin : null, $ci !== '' ? $ci : null);

        // idType=15 (SIN NOMBRE) es innominado EXPLÍCITO — aunque el contacto
        // tenga algo cargado en contactCI, declarar el código 15 manda.
        if ($idType === \Punto\Api\Contacts\ContactService::ID_TYPE_SIN_NOMBRE) {
            return $contactBits + ['nature' => 'innominado', 'idType' => $idType, 'name' => $name !== '' ? $name : 'Consumidor final'];
        }

        if ($tin !== '') {
            return $contactBits + ['nature' => 'contribuyente', 'ruc' => $tin, 'ci' => $ci !== '' ? $ci : null, 'idType' => $idType, 'name' => $name];
        }
        if ($ci !== '') {
            return $contactBits + ['nature' => 'fisica', 'ci' => $ci, 'idType' => $idType, 'name' => $name];
        }
        return $contactBits + ['nature' => 'innominado', 'idType' => \Punto\Api\Contacts\ContactService::ID_TYPE_SIN_NOMBRE, 'name' => $name !== '' ? $name : 'Consumidor final'];
    }

    /**
     * Shape `$sale` de una NOTA DE CRÉDITO (doctype 'NC') a partir de una
     * devolución (`transaction.transactionType = 6`).
     *
     * Diferencias estructurales con una venta, y por qué:
     *
     * - **Los ítems salen de `itemSold`, no de `meta.transactionDetails`.**
     *   `ReturnService::create` inserta la devolución con `meta = '{}'` — el
     *   detalle vive solo en las filas de `itemSold`, con signo negativo.
     * - **Todos los montos van en valor absoluto.** La devolución se persiste
     *   en negativo (para que sume correctamente en los reportes), pero el
     *   documento fiscal declara importes positivos: es la nota de crédito la
     *   que resta, no el signo de sus líneas.
     * - **Neto, igual que la factura**: `itemSoldTotal` es el bruto de la línea
     *   e `itemSoldDiscount` su descuento — se declara la resta, que es lo que
     *   el cliente había pagado y por lo tanto lo que se le acredita.
     * - **Sin bloque de pagos**: una nota de crédito no cobra nada. La
     *   devolución del dinero (efectivo o crédito en cuenta) es un movimiento
     *   de caja de Punto, no una forma de pago del documento.
     * - **Referencia obligatoria a la factura original**: sin el CDC de la
     *   factura que se está corrigiendo, la nota de crédito no existe para
     *   SIFEN. Si la venta original nunca se facturó electrónicamente, no hay
     *   nada que corregir — se falla con un mensaje explícito en vez de emitir
     *   una nota huérfana.
     *
     * @throws \RuntimeException con el motivo por el que no se puede emitir.
     */
    private function buildCreditNoteArrayForMapper(string $companyId, string $transactionId): ?array
    {
        // invoiceNo + invoiceAuth: la serie y el correlativo PROPIOS de la
        // nota de crédito, congelados por `ReturnService::create()` en la fila
        // de la devolución (context/40 F3, 2026-09-09). Antes no se leían
        // porque la NC la numeraba el proveedor.
        $tx = ncmExecute(
            'SELECT transactionTotal, transactionDiscount, transactionCurrency,
                    customerId, transactionDate, invoiceNo, invoiceAuth
               FROM transaction WHERE transactionId = ? AND companyId = ?',
            [$transactionId, $companyId]
        );
        if (!$tx) {
            return null;
        }

        // mig 115: transactionParentId dropeada — el origen de la devolución
        // (venta original) vive en transaction_link, kind='return'.
        $origins  = (new \Punto\Api\Services\TransactionLinkService())->listOriginIds($companyId, $transactionId, 'return');
        $parentId = $origins[0] ?? '';
        if ($parentId === '') {
            throw new \RuntimeException(
                'La devolución no está vinculada a una venta original — no se puede emitir la nota de crédito.'
            );
        }

        // Factura original: la que la nota de crédito corrige. Solo sirve una
        // EMITIDA (una en error/pendiente no tiene CDC, y una ya cancelada no
        // tiene nada que corregir).
        $parentDoc = ncmExecute(
            "SELECT cdc FROM einvoice_document
              WHERE companyid = ? AND transactionid = ? AND status = 'issued' AND cdc IS NOT NULL
              ORDER BY issued_at DESC LIMIT 1",
            [$companyId, $parentId]
        );
        $parentCdc = $parentDoc ? trim((string) ($parentDoc['cdc'] ?? '')) : '';
        if ($parentCdc === '') {
            throw new \RuntimeException(
                'La venta original no tiene una factura electrónica emitida — no hay documento que corregir '
                . 'con una nota de crédito.'
            );
        }

        $rs = ncmExecute(
            // itemSold sin comillas a propósito: la tabla se creó SIN quotes
            // (db-schema-postgres.sql) → Postgres la plegó a minúsculas
            // (itemsold). Citarla como "itemSold" exige match exacto de
            // mayúsculas y Postgres tira "relation does not exist" (mismo
            // bug documentado en detalle en ReturnService::create()).
            'SELECT s.itemId AS itemId, s.itemSoldUnits AS units, s.itemSoldTotal AS lineTotal,
                    s.itemSoldDiscount AS lineDiscount, item.itemName AS itemName
               FROM itemSold s
               JOIN item ON item.itemId = s.itemId
              WHERE s.transactionId = ? AND item.companyId = ?',
            [$transactionId, $companyId],
            false,
            true
        );
        if ($rs === false) {
            throw new \RuntimeException('No se pudieron leer los ítems de la devolución — no se emite la nota de crédito.');
        }

        $lines = [];
        while (!$rs->EOF) {
            $lines[] = [
                'itemId'   => (string) $rs->fields['itemid'],
                'name'     => (string) ($rs->fields['itemname'] ?? ''),
                'quantity' => abs((float) $rs->fields['units']),
                'net'      => abs((float) $rs->fields['linetotal']) - abs((float) ($rs->fields['linediscount'] ?? 0)),
            ];
            $rs->MoveNext();
        }
        $rs->Close();

        $billable = array_values(array_filter(
            $lines,
            static fn (array $l): bool => $l['quantity'] > 0 && $l['net'] != 0.0
        ));
        if ($billable === []) {
            throw new \RuntimeException('La devolución no tiene ítems con importe — no se emite la nota de crédito.');
        }

        // F3a: `itemSold` (de donde salen `$billable`) no persiste taxRate/taxKind
        // por línea — el congelado real vive en `meta.transactionDetails` de la
        // VENTA ORIGINAL ($parentId, ya resuelta arriba vía transaction_link). Se
        // lee ese detalle y se arma un mapa itemId => [taxRate, taxKind] para que
        // la nota de crédito declare la MISMA tasa que declaró la factura que
        // corrige — nunca la del catálogo actual, que pudo cambiar desde la venta.
        $originalTaxByItemId = [];
        $originalTx = ncmExecute(
            'SELECT meta FROM transaction WHERE transactionId = ? AND companyId = ?',
            [$parentId, $companyId]
        );
        if ($originalTx) {
            $originalDetailRaw = $originalTx['transactionDetails'] ?? null;
            $originalDetail = is_string($originalDetailRaw) ? json_decode($originalDetailRaw, true) : (is_array($originalDetailRaw) ? $originalDetailRaw : []);
            $originalDetail = is_array($originalDetail) ? $originalDetail : [];
            foreach ($originalDetail as $sD) {
                $id = (string) ($sD['itemId'] ?? '');
                if ($id !== '' && isset($sD['taxRate'], $sD['taxKind'])) {
                    $originalTaxByItemId[$id] = ['taxRate' => $sD['taxRate'], 'taxKind' => $sD['taxKind']];
                }
            }
        }

        // resolveTaxRatesForItems espera la clave `itemId` (shape del detalle de
        // venta) + opcionalmente `taxRate`/`taxKind` congelados de la venta original.
        $billableWithTax = array_map(
            static function (array $line) use ($originalTaxByItemId): array {
                $frozen = $originalTaxByItemId[$line['itemId']] ?? null;
                if ($frozen !== null) {
                    $line['taxRate'] = $frozen['taxRate'];
                    $line['taxKind'] = $frozen['taxKind'];
                }
                return $line;
            },
            $billable
        );
        $taxRateByItemId = $this->resolveTaxRatesForItems($companyId, $billableWithTax);
        $serviceByItemId = $this->resolveServiceFlagsForItems(
            $companyId,
            array_map(static fn (array $l): string => $l['itemId'], $billable)
        );

        $items = [];
        foreach ($billable as $line) {
            $items[] = [
                'description' => $line['name'],
                'quantity'    => $line['quantity'],
                // El unitario definitivo lo deriva el mapper de total/quantity
                // en los decimales de la moneda (ver SaleFiscalRules::fiscalLines()):
                // acá se
                // manda como referencia, el que manda es `total`.
                'unitPrice'   => round($line['net'] / $line['quantity'], 8),
                'total'       => $line['net'],
                'taxRate'     => $taxRateByItemId[$line['itemId']],
                'isService'   => $serviceByItemId[$line['itemId']] ?? false,
            ];
        }

        // El total sale de las líneas, no de `transactionTotal`: la devolución
        // guarda el bruto y el descuento en columnas separadas igual que la
        // venta, y acá ya se declaró el neto por línea.
        $total = 0.0;
        foreach ($items as $item) {
            $total += (float) $item['total'];
        }

        return [
            'documentType'       => 5, // Nota de crédito (guía §"Enviar DE – Tipo 5/6").
            'associatedCdc'      => $parentCdc,
            'total'              => $total,
            // Fecha de la DEVOLUCIÓN (la operación que la NC documenta), no
            // la del envío — mismo criterio que la factura.
            'transactionDate'    => (string) ($tx['transactionDate'] ?? ''),
            // Mismo criterio que en la venta: sin inventar 'PYG'. Ver resolveCurrency().
            'currency'           => self::resolveCurrency($companyId, $tx['transactionCurrency'] ?? null),
            'operationCondition' => 0,
            'items'              => $items,
            'client'             => $this->resolveClient($companyId, $tx['customerId'] ?? null),
            'payments'           => [],
            // Mismas claves y misma semántica que en la venta: el correlativo
            // y el timbrado del EMISOR (nosotros). La regla de qué se manda
            // como `numero` vive en un solo lugar (los `resolveDocumentNumber`
            // de los mappers) y la coherencia del timbrado la valida
            // `assertNumberingCoherence()`, que ya no exceptúa a la NC.
            'fiscalNumber'       => isset($tx['invoiceNo']) && is_numeric($tx['invoiceNo']) ? (int) $tx['invoiceNo'] : null,
            'fiscalAuth'         => trim((string) ($tx['invoiceAuth'] ?? '')),
        ];
    }
}
