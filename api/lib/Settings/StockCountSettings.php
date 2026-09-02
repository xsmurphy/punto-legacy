<?php
declare(strict_types=1);

namespace Punto\Api\Settings;

/**
 * StockCountSettings — las preferencias de CONTEO de stock del comercio.
 *
 * Hoy una sola clave, `stockCountBlind` (D2 de context/63): el operador no ve
 * el stock teórico mientras cuenta. Vive en `config.settingObj`, junto al
 * resto de los flags del comercio.
 *
 * Existe como clase propia y no como una lectura suelta dentro del servicio
 * porque la consumen superficies distintas —`InventoryCountService` al armar
 * el detalle, el listado del panel al publicar la diferencia— y las dos tienen
 * que resolverla igual. Es el lugar donde van a entrar sus hermanas.
 *
 * ── Blind es una regla de SERVIDOR, no de UI ────────────────────────────────
 *
 * El precedente es `drawerBlind`, que no viaja al cliente: el backend devuelve
 * un resumen recortado (`drawerBlindSummary()` en `api/v1/drawer.php`). Acá
 * igual — el esperado no se manda, en vez de mandarse y pedirle a la pantalla
 * que no lo pinte. Un flag que el cliente tiene que respetar es un flag que se
 * evade abriendo las devtools.
 *
 * Cache por request: el conteo consulta esta clave varias veces en la misma
 * llamada (redactar el detalle, redactar el listado) y es inmutable dentro de
 * una request.
 */
final class StockCountSettings
{
    /** @var array<string, self> companyId => instancia */
    private static array $cache = [];

    private function __construct(
        private readonly bool $blind,
    ) {
    }

    public static function forCompany(string $companyId): self
    {
        if (isset(self::$cache[$companyId])) {
            return self::$cache[$companyId];
        }

        // Una sola lectura: `->>` devuelve texto y es el acceso confiable a
        // `config` (leer la columna entera por el wrapper devuelve un valor no
        // usable — ver readSettingObj() en SettingsService).
        $rs = ncmExecute(
            "SELECT config->>'settingObj' AS so FROM company WHERE companyId = ? LIMIT 1",
            [$companyId],
            false,
            true
        );

        $so = null;
        if ($rs && is_object($rs) && !$rs->EOF) {
            $so = $rs->fields['so'] ?? null;
            $rs->Close();
        }

        $obj = json_decode((string) ($so ?? ''), true);
        if (!is_array($obj)) {
            $obj = [];
        }

        return self::$cache[$companyId] = new self(
            self::truthy($obj['stockCountBlind'] ?? null),
        );
    }

    /** Solo para tests y para el CRUD de Ajustes, que reescribe la config. */
    public static function forget(string $companyId): void
    {
        unset(self::$cache[$companyId]);
    }

    /** El operador no ve el stock teórico mientras cuenta (D2). */
    public function blind(): bool
    {
        return $this->blind;
    }

    /** Mismo criterio que SettingsService::truthy() — los flags viajan como 0/1, bool o texto. */
    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower((string) $v);
        return in_array($s, ['1', 't', 'true', 'yes', 'on'], true)
            || (is_numeric($s) && (float) $s > 0);
    }
}
