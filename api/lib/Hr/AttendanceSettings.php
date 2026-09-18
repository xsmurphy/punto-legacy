<?php
declare(strict_types=1);

namespace Punto\Api\Hr;

/**
 * AttendanceSettings — cómo quiere el comercio que se identifique quien marca.
 *
 * Hoy es UNA clave, y aun así tiene archivo propio: la interpretan DOS
 * superficies que no se hablan entre sí —el alta de la marcación
 * (`AttendanceService::mark()`) y el contexto que baja al reloj
 * (`/v1/screens?resource=context`)— y las dos tienen que leerla igual. Con la
 * lectura repetida en cada una, el día que cambie el default el reloj y el
 * servidor opinan distinto y el resultado es una marcación que la pantalla
 * ofrece y el servidor rechaza.
 *
 * ── `attendanceFaceOnly`: el nombre está en negativo a propósito ────────────
 *
 * El default del owner es que el CÓDIGO esté disponible (es como funcionó
 * desde la F1), y un flag ausente en el JSONB vale falso. Un
 * `attendanceAllowPin` ausente —el estado de todos los comercios que existen
 * hoy— significaría "no se puede marcar con código", o sea apagarle la
 * marcación por código a todo el parque el día del deploy. Es la misma regla
 * que documenta `StockCountSettings` (`stockCountRecordOnly`), y acá además
 * evita el backfill que la mig 213 tuvo que hacer por nombrar en positivo.
 *
 * Vive en `config.settingObj`, junto al resto de los flags de comportamiento
 * del comercio. Sin migración: una clave más en un JSONB que ya existe.
 *
 * ── Por qué el interruptor existe ──────────────────────────────────────────
 *
 * El código se presta. Es la misma falla que motivó invertir el modelo hacia el
 * dispositivo del comercio (context/83 §0), solo que el PIN de respaldo la
 * dejaba viva: alguien le pasa su código a un compañero y ficha sin estar. El
 * rostro no se presta. El comercio que prioriza eso sobre la comodidad apaga el
 * código y asume la contracara: quien todavía no registró su rostro no puede
 * marcar hasta que se lo registren.
 *
 * Cache por request: `mark()` la consulta una vez por marcación y es inmutable
 * dentro de una request.
 */
final class AttendanceSettings
{
    /** @var array<string, bool> companyId => faceOnly */
    private static array $cache = [];

    /** El comercio exige el ROSTRO: marcar con código está apagado. */
    public static function faceOnly(string $companyId): bool
    {
        if (isset(self::$cache[$companyId])) {
            return self::$cache[$companyId];
        }

        // `->>` devuelve texto y es el acceso confiable a `config` (leer la
        // columna entera por el wrapper devuelve un valor no usable — ver
        // readSettingObj() en SettingsService).
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

        return self::$cache[$companyId] = self::faceOnlyFromSettingObj(is_array($obj) ? $obj : []);
    }

    /**
     * La MISMA lectura, para quien ya tiene el `settingObj` decodificado en la
     * mano (`/v1/screens?resource=context` lo decodifica para el logo). Existe
     * para no pagar una segunda query, no para tener una segunda semántica.
     *
     * @param array<string,mixed> $obj
     */
    public static function faceOnlyFromSettingObj(array $obj): bool
    {
        $v = $obj['attendanceFaceOnly'] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null) {
            return false; // ausente = el default: el código está disponible
        }
        return in_array(strtolower(trim((string) $v)), ['1', 't', 'true', 'yes', 'on'], true);
    }

    /** Solo para tests y para el CRUD de Ajustes, que reescribe la config. */
    public static function forget(string $companyId): void
    {
        unset(self::$cache[$companyId]);
    }
}
