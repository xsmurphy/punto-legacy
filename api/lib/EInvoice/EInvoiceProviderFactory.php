<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Resuelve el motor de facturación electrónica de UNA company.
 *
 * Hoy hay UNO: FE-PY, el motor propio. El factory no desaparece por eso, y no
 * es ceremonia: `einvoice_account.provider` es una columna multi-proveedor
 * desde la mig 92, y este archivo es el único lugar que la interpreta. Un
 * valor que este deploy no conozca LANZA (ver `instance()`) en vez de caer a
 * un default — una company marcada con un motor desconocido no puede terminar
 * emitiendo por otro, con otro timbrado y otra numeración, sin que nadie se
 * entere.
 *
 * ── Por qué es un factory y no una inyección en el constructor ───────────
 *
 * `EInvoiceService` NO es un servicio por company: se construye una vez y se
 * usa para varias (el drainer del outbox y la reconciliación iteran sobre
 * todos los tenants dentro del mismo proceso). Resolver el motor en el
 * constructor haría que la company que entra primero se lo fije a las que
 * vienen detrás — la misma clase de fuga que el docblock de
 * `EInvoiceProvider` explica para `$environment`. Por eso la resolución es por
 * llamada y va cacheada por companyId, no por instancia.
 *
 * La caché es por REQUEST (estática, sin TTL): el motor de una company no
 * cambia a mitad de un drenaje, y si cambia, el proceso siguiente lo lee.
 */
final class EInvoiceProviderFactory
{
    public const PROVIDER_FEPY = 'fepy';

    /** @var array<string,string> companyId => provider */
    private static array $providerCache = [];

    /** @var array<string,EInvoiceProvider> provider => instancia (son stateless, se reusan) */
    private static array $instances = [];

    /**
     * Motor con el que NACEN los emisores nuevos. ÚNICA fuente de verdad de
     * ese default: el provisioning lo consulta acá en vez de leer la env var
     * por su cuenta. Tenerlo en dos lugares es cómo terminan divergiendo — el
     * alta creando emisores en un motor y la emisión buscándolos en el otro.
     */
    public static function defaultName(): string
    {
        return self::PROVIDER_FEPY;
    }

    /**
     * Motor de una company: el de SU fila, siempre que la tenga. Un emisor no
     * cambia de motor porque se flipeó una env var.
     *
     * Sin fila (o con la columna vacía, que el schema no permite pero un
     * `SELECT` defensivo contempla) cae al default de arriba. No puede
     * disparar un cambio de motor por accidente: el provisioning escribe la
     * fila con su `provider` explícito ANTES de que exista un documento que
     * emitir, así que para cuando esto se consulta en el camino de emisión el
     * dato real siempre está.
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

    /** Motor de esa company. */
    public static function for(string $companyId): EInvoiceProvider
    {
        return self::instance(self::nameFor($companyId));
    }

    /** @throws \RuntimeException si el nombre no corresponde a ningún motor implementado. */
    public static function instance(string $provider): EInvoiceProvider
    {
        if (isset(self::$instances[$provider])) {
            return self::$instances[$provider];
        }

        $instance = match ($provider) {
            self::PROVIDER_FEPY => new FePyProvider(),
            default => throw new \RuntimeException(
                "La cuenta de facturación electrónica está marcada con un motor desconocido ('$provider') — " .
                'no se emite hasta corregirlo.'
            ),
        };

        self::$instances[$provider] = $instance;
        return $instance;
    }

    /**
     * Resuelve credenciales e identidad del emisor para esa company. Es la
     * otra mitad de la resolución por tenant: el motor sabe QUÉ endpoints
     * hablar, la sesión sabe CON QUÉ credencial.
     *
     * Pasa por `nameFor()` —y no devuelve `FePySession` a secas— para que una
     * fila con un motor desconocido falle acá también, y no solo en el camino
     * de emisión.
     */
    public static function sessionFor(string $companyId): EInvoiceSession
    {
        return match (self::nameFor($companyId)) {
            self::PROVIDER_FEPY => new FePySession(),
            default => throw new \RuntimeException(
                'La cuenta de facturación electrónica está marcada con un motor desconocido — ' .
                'no se puede resolver su credencial.'
            ),
        };
    }

    /**
     * Olvida lo cacheado de una company (o de todas). Lo usa el provisioning
     * después de escribir `provider`: dentro del MISMO request, la caché de
     * arriba todavía diría el valor viejo.
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
