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
 * ── `attendanceAllowPin`: en positivo, y APAGADO por default ───────────────
 *
 * Decisión del owner (2026-09-18) que INVIERTE el default anterior. La clave se
 * llamaba `attendanceFaceOnly` justamente para que "ausente" significara "el
 * código está disponible", que era el default de la F1. Ahora el default es el
 * contrario: **marcar con código está apagado salvo que el comercio lo prenda**.
 *
 * Con el default invertido, el nombre en negativo dejó de comprar nada y pasó a
 * costar: `attendanceFaceOnly` ausente = false = código disponible es
 * exactamente lo que YA NO queremos. En positivo, ausente vale false y false es
 * el default correcto — sin backfill y sin migración.
 *
 * El cambio de semántica es seguro porque todavía no hay comercios usando
 * marcación: nadie se queda sin el código de un día para el otro. El que lo
 * quiera lo prende en Ajustes ("Permitir marcar con código").
 *
 * Vive en `config.settingObj`, junto al resto de los flags de comportamiento
 * del comercio. Sin migración: una clave más en un JSONB que ya existe.
 *
 * ── Por qué el default es el rostro ────────────────────────────────────────
 *
 * El código se presta. Es la misma falla que motivó invertir el modelo hacia el
 * dispositivo del comercio (context/83 §0): alguien le pasa su código a un
 * compañero y ficha sin estar. El rostro no se presta. La contracara, asumida:
 * quien todavía no registró su rostro no puede marcar hasta que se lo
 * registren, o hasta que el comercio prenda el código.
 *
 * Cache por request: `mark()` la consulta una vez por marcación y es inmutable
 * dentro de una request.
 */
final class AttendanceSettings
{
    /** @var array<string, bool> companyId => allowPin */
    private static array $cache = [];

    /** El comercio habilitó marcar con código. Por default, NO. */
    public static function allowPin(string $companyId): bool
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

        return self::$cache[$companyId] = self::allowPinFromSettingObj(is_array($obj) ? $obj : []);
    }

    /**
     * La MISMA lectura, para quien ya tiene el `settingObj` decodificado en la
     * mano (`/v1/screens?resource=context` lo decodifica para el logo). Existe
     * para no pagar una segunda query, no para tener una segunda semántica.
     *
     * @param array<string,mixed> $obj
     */
    public static function allowPinFromSettingObj(array $obj): bool
    {
        $v = $obj['attendanceAllowPin'] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null) {
            return false; // ausente = el default: solo rostro
        }
        return in_array(strtolower(trim((string) $v)), ['1', 't', 'true', 'yes', 'on'], true);
    }

    /** Solo para tests y para el CRUD de Ajustes, que reescribe la config. */
    public static function forget(string $companyId): void
    {
        unset(self::$cache[$companyId]);
    }
}
