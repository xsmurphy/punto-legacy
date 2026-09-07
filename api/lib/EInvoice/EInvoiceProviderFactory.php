<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Elige el proveedor de facturación electrónica de UNA company.
 *
 * El cutover Factomate → FE-PY (motor propio) es POR TENANT, no global: el
 * dato es `einvoice_account.provider`, que existe desde la mig 92 y hasta hoy
 * nadie leía. Factomate queda intacto como plan B y sigue siendo el default,
 * así que un comercio sin fila —o con una fila anterior a esto— no cambia de
 * comportamiento por este archivo.
 *
 * ── Por qué es un factory y no una inyección en el constructor ───────────
 *
 * `EInvoiceService` NO es un servicio por company: se construye una vez y se
 * usa para varias (el drainer del outbox y la reconciliación iteran sobre
 * todos los tenants dentro del mismo proceso). Resolver el proveedor en el
 * constructor haría que la company que entra primero le fije el proveedor a
 * las que vienen detrás — la misma clase de fuga que el docblock de
 * `EInvoiceProvider` explica para `$environment`. Por eso la resolución es
 * por llamada y va cacheada por companyId, no por instancia.
 *
 * La caché es por REQUEST (estática, sin TTL): el proveedor de una company no
 * cambia a mitad de un drenaje, y si cambia, el proceso siguiente lo lee.
 */
final class EInvoiceProviderFactory
{
    public const PROVIDER_FACTOMATE = 'factomate';
    public const PROVIDER_FEPY      = 'fepy';

    /** @var array<string,string> companyId => provider */
    private static array $providerCache = [];

    /** @var array<string,EInvoiceProvider> provider => instancia (son stateless, se reusan) */
    private static array $instances = [];

    /**
     * Proveedor con el que NACEN los emisores nuevos, desde
     * `EINVOICE_DEFAULT_PROVIDER`. ÚNICA fuente de verdad de ese default:
     * `EInvoiceProvisioningService::targetProvider()` lo consulta acá en vez
     * de volver a leer la env var. Tener el default en dos lugares es cómo
     * terminan divergiendo — el alta creando emisores en un motor y la
     * emisión buscándolos en el otro.
     *
     * Un valor desconocido cae a Factomate en vez de lanzar: es configuración
     * de infra nuestra, no un dato del comercio, y dejar a todos los tenants
     * sin poder configurar la facturación por una letra de más en Coolify
     * sería peor que seguir con el proveedor de siempre. El caso peligroso
     * —una FILA con un proveedor desconocido— sí lanza, en `instance()`.
     */
    public static function defaultName(): string
    {
        $default = defined('EINVOICE_DEFAULT_PROVIDER')
            ? strtolower(trim((string) constant('EINVOICE_DEFAULT_PROVIDER')))
            : self::PROVIDER_FACTOMATE;

        return $default === self::PROVIDER_FEPY ? self::PROVIDER_FEPY : self::PROVIDER_FACTOMATE;
    }

    /**
     * Nombre del proveedor de una company: el de SU fila, siempre que la
     * tenga. Un emisor no cambia de motor porque se flipeó una env var.
     *
     * Sin fila (o con la columna vacía, que el schema no permite pero un
     * `SELECT` defensivo contempla) cae al default de arriba. No puede
     * disparar un cambio de motor por accidente: el provisioning escribe la
     * fila con su `provider` explícito ANTES de que exista un documento que
     * emitir, así que para cuando esto se consulta en el camino de emisión
     * el dato real siempre está.
     */
    public static function nameFor(string $companyId): string
    {
        if (isset(self::$providerCache[$companyId])) {
            return self::$providerCache[$companyId];
        }

        $row = ncmExecute('SELECT provider FROM einvoice_account WHERE companyid = ?', [$companyId]);
        // Ojo wrapper: ncmExecute devuelve un objeto array-like
        // (CaseInsensitiveArray), NO un array — `is_array()` acá descartaría
        // la fila real. Mismo footgun que costó una custodia en
        // FiscalSecretStore::readCertificate (2026-09-07).
        $row = $row ?: [];
        $provider = strtolower(trim((string) ($row['provider'] ?? '')));
        if ($provider === '') {
            $provider = self::defaultName();
        }

        self::$providerCache[$companyId] = $provider;
        return $provider;
    }

    public static function isFePy(string $companyId): bool
    {
        return self::nameFor($companyId) === self::PROVIDER_FEPY;
    }

    /**
     * Instancia del proveedor de esa company.
     *
     * Un valor DESCONOCIDO en la columna lanza en vez de caer a Factomate:
     * una company marcada con un proveedor que este deploy no conoce no puede
     * terminar emitiendo por otro motor, con otro timbrado y otra numeración,
     * sin que nadie se entere. El outbox lo muestra como error legible.
     */
    public static function for(string $companyId): EInvoiceProvider
    {
        return self::instance(self::nameFor($companyId));
    }

    /** @throws \RuntimeException si el nombre no corresponde a ningún proveedor implementado. */
    public static function instance(string $provider): EInvoiceProvider
    {
        if (isset(self::$instances[$provider])) {
            return self::$instances[$provider];
        }

        $instance = match ($provider) {
            self::PROVIDER_FACTOMATE => new FactomateProvider(),
            self::PROVIDER_FEPY      => new FePyProvider(),
            default => throw new \RuntimeException(
                "La cuenta de facturación electrónica está marcada con un proveedor desconocido ('$provider') — " .
                'no se emite hasta corregirlo.'
            ),
        };

        self::$instances[$provider] = $instance;
        return $instance;
    }

    /**
     * Resuelve credenciales e identidad del emisor para esa company. Es la
     * otra mitad del cutover: sin esto, `EInvoiceService` seguiría pidiéndole
     * un bearer a `FactomateSession` (que intentaría el login de Factomate)
     * para una cuenta que vive en FE-PY.
     */
    public static function sessionFor(string $companyId): EInvoiceSession
    {
        return self::isFePy($companyId)
            ? new FePySession()
            : new FactomateSession(self::instance(self::PROVIDER_FACTOMATE));
    }

    /**
     * Olvida lo cacheado de una company (o de todas). Lo usa el provisioning
     * después de escribir `provider`: dentro del MISMO request, la caché de
     * arriba todavía diría el proveedor viejo.
     */
    public static function forget(?string $companyId = null): void
    {
        if ($companyId === null) {
            self::$providerCache = [];
            return;
        }
        unset(self::$providerCache[$companyId]);
    }
}
