<?php
declare(strict_types=1);

namespace Punto\Api\PaymentMethods;

use Punto\Api\Modules\ModuleChannels;

/**
 * Catálogo de pasarelas de pago (PSP) que cobran con QR desde la caja.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 *
 * Bancard fue el primer PSP y quedó cableado por nombre en tres lugares
 * distintos: el toggle del módulo (`ModulesService`), la resolución de canales
 * del bootstrap del POS (`api/v1/bootstrap.php`) y la provisión del medio de
 * pago (`PaymentMethodService::ensureQrMethod`). Sumar un segundo PSP
 * (uPay — `context/50-upay.md`) con ese diseño obliga a duplicar los tres.
 *
 * Este catálogo es la ÚNICA fuente de verdad de "qué pasarelas de QR existen y
 * con qué medio de pago cobra cada una". Los tres call-sites lo recorren en vez
 * de nombrar pasarelas.
 *
 * ── Cómo se agrega una pasarela nueva ───────────────────────────────────────
 *
 *   1. Una entrada acá (ver el shape abajo).
 *   2. La key del módulo en `ModulesService::NATIVE_KEYS` (+ `CONFIG_KEYS` si
 *      tiene canales configurables) y sus canales en `ModuleChannels`.
 *   3. Un adapter en el front — `frontend/lib/payments/psp/<provider>.ts`
 *      registrado en `frontend/lib/payments/psp/index.ts`.
 *
 * Nada más: la provisión del medio de pago, el filtrado del botón en el POS, el
 * dialog de cobro y el polling de confirmación ya son genéricos.
 *
 * ── Shape de una entrada ────────────────────────────────────────────────────
 *
 *   module         key del módulo en `company` (flat key + `moduleData[key]`)
 *   channel        sub-key del canal QR dentro de `moduleData[module]`
 *                  (su estado inicial lo declara `ModuleChannels`, no acá)
 *   systemKey      discriminante del medio de pago (`taxonomyExtra.systemKey`)
 *   methodName     nombre del medio de pago que se provisiona / adopta
 *   code           atajo de teclado en la grilla del POS
 *   color          color del borde en la grilla (paleta de color-palette.ts)
 *   label          nombre para el cajero (título del dialog de cobro)
 *
 * ── Por qué Bancard conserva `systemKey = 'qr'` ─────────────────────────────
 *
 * Es la identidad histórica del medio: todos los tenants con el módulo activo
 * ya tienen una fila de taxonomía llamada "QR" con ese systemKey, y las ventas
 * viejas guardaron su `taxonomyId` en `transactionPaymentType[].type`. Cambiar
 * el systemKey (o renombrar el medio) partiría la serie del reporte en dos
 * buckets a mitad de la historia. La separación por PSP no la da el systemKey
 * sino que cada pasarela tenga SU PROPIA fila de taxonomía — ver el docblock de
 * `PaymentMethodService::ensurePspMethod()`.
 */
final class PspCatalog
{
    /**
     * Pasarelas con canal de cobro por QR, indexadas por provider.
     *
     * @var array<string, array{module:string,channel:string,systemKey:string,methodName:string,code:string,color:string,label:string}>
     */
    private const QR_PROVIDERS = [
        'bancard' => [
            'module'         => 'bancard',
            'channel'        => 'qr',
            'systemKey'      => 'qr',
            'methodName'     => 'QR',
            'code'           => 'Q',
            'color'          => 'indigo',
            'label'          => 'QR Bancard',
        ],

        // Próxima pasarela (bloqueada por credenciales, ver context/50-upay.md):
        //
        // 'upay' => [
        //     'module'         => 'upay',
        //     'channel'        => 'qr',
        //     'systemKey'      => 'upayQr',
        //     'methodName'     => 'uPay',
        //     'code'           => 'U',
        //     'color'          => 'teal',
        //     'label'          => 'QR uPay',
        // ],
    ];

    /**
     * Todas las pasarelas de QR.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function qrProviders(): array
    {
        return self::QR_PROVIDERS;
    }

    /** Una pasarela por provider, o null si no existe. */
    public static function qrProvider(string $provider): ?array
    {
        return self::QR_PROVIDERS[$provider] ?? null;
    }

    /**
     * Pasarelas que dependen de un módulo dado (normalmente una).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function qrProvidersForModule(string $moduleKey): array
    {
        $out = [];
        foreach (self::QR_PROVIDERS as $provider => $psp) {
            if ($psp['module'] === $moduleKey) {
                $out[$provider] = $psp;
            }
        }
        return $out;
    }

    /**
     * Resuelve si el canal QR de una pasarela está prendido para un tenant.
     *
     * Atajo tipado sobre `ModuleChannels::on()` — el estado inicial del canal
     * lo declara ese archivo, acá no se duplica.
     *
     * @param bool  $moduleOn  estado del módulo (flat key de `company`)
     * @param array $moduleCfg entry de `moduleData[module]` decodificado
     */
    public static function qrChannelOn(array $psp, bool $moduleOn, array $moduleCfg): bool
    {
        return ModuleChannels::on(
            (string) $psp['module'],
            (string) $psp['channel'],
            $moduleOn,
            $moduleCfg
        );
    }
}
