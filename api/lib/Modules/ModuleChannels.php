<?php
declare(strict_types=1);

namespace Punto\Api\Modules;

/**
 * Canales de los módulos que se habilitan de a uno.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 *
 * Un módulo "paraguas" (Bancard) prende varias integraciones distintas: el QR
 * de pago y el terminal físico de la caja. Cada una se habilita por separado en
 * `moduleData[<module>][<channel>]`, y la pregunta "¿está prendido el canal X?"
 * se respondía en TRES lugares con la misma expresión copiada:
 *
 *   - `ModulesService::list()`      — lo que edita el dialog de config del panel
 *   - `ModulesService::updateConfig()` — qué claves acepta el guardado
 *   - `api/v1/bootstrap.php`        — los booleans que baja el POS
 *
 * (más `PspCatalog::qrChannelOn()`, que tenía su propio `channelDefault`).
 * Cuatro copias del default es cuatro formas de que se desincronicen. Este
 * declarador es la ÚNICA fuente de verdad de "qué canales tiene un módulo y en
 * qué estado nace cada uno".
 *
 * ── El default es OPT-IN (false) a propósito ────────────────────────────────
 *
 * Hasta 2026-08-24 el default era `true`: prender "Bancard" daba por
 * configurados los dos canales aunque nadie hubiera tocado nunca la config. El
 * efecto visible era que el campo "IP POS Bancard" aparecía en POS → Ajustes en
 * tenants que jamás tuvieron un terminal físico. Un canal es una integración
 * con hardware o con un tercero: nace apagado y se enciende a mano en la config
 * del módulo. El toggle del módulo sigue apagando todos los canales de una.
 *
 * ── Cómo se agrega un canal ─────────────────────────────────────────────────
 *
 *   1. Una entrada en `CHANNELS[<module>]` acá.
 *   2. El switch en el dialog de config del panel
 *      (`frontend/components/modules/module-config-dialog.tsx`).
 *   3. Quien lo consuma (bootstrap del POS, servicio, etc.) llama a `on()`.
 *
 * `updateConfig()` y `list()` ya recorren este declarador: no se tocan.
 */
final class ModuleChannels
{
    /**
     * Canales por módulo, en orden de presentación, con su estado inicial.
     *
     * @var array<string, array<string, bool>>
     */
    private const CHANNELS = [
        'bancard' => [
            // QR de pago (ePagos / BANCARD_QR_API).
            'qr'  => false,
            // Terminal físico (Caja POS Android por LAN). Habilita el campo de
            // IP por caja en POS → Ajustes.
            'pos' => false,
        ],
    ];

    /**
     * Nombres de canal declarados por un módulo, en orden.
     *
     * @return list<string>
     */
    public static function names(string $module): array
    {
        return array_keys(self::CHANNELS[$module] ?? []);
    }

    /** Estado inicial declarado de un canal (false si no está declarado). */
    public static function channelDefault(string $module, string $channel): bool
    {
        return (bool) (self::CHANNELS[$module][$channel] ?? false);
    }

    /**
     * Valor guardado de cada canal con el default aplicado, SIN gatear por el
     * estado del módulo. Es lo que el panel muestra y edita en el dialog de
     * config: apagar el módulo no debe borrar de la vista qué canales eligió
     * el tenant.
     *
     * @param array $moduleCfg entry de `moduleData[<module>]` decodificado
     * @return array<string, bool>
     */
    public static function values(string $module, array $moduleCfg): array
    {
        $out = [];
        foreach (self::CHANNELS[$module] ?? [] as $channel => $default) {
            $out[$channel] = array_key_exists($channel, $moduleCfg)
                ? filter_var($moduleCfg[$channel], FILTER_VALIDATE_BOOLEAN)
                : (bool) $default;
        }
        return $out;
    }

    /**
     * ¿El canal opera? Módulo activo Y canal habilitado. Ésta es la pregunta
     * que hacen el bootstrap del POS y la provisión de medios de pago — nunca
     * recombinen `moduleOn` con la config por su cuenta.
     *
     * @param bool  $moduleOn  estado del módulo (flat key de `company`)
     * @param array $moduleCfg entry de `moduleData[<module>]` decodificado
     */
    public static function on(string $module, string $channel, bool $moduleOn, array $moduleCfg): bool
    {
        if (!$moduleOn) {
            return false;
        }
        if (!array_key_exists($channel, $moduleCfg)) {
            return self::channelDefault($module, $channel);
        }
        return filter_var($moduleCfg[$channel], FILTER_VALIDATE_BOOLEAN);
    }
}
