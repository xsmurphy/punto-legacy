<?php
declare(strict_types=1);

namespace Punto\Api\Settings;

/**
 * StockCountSettings — las preferencias de CONTEO de stock del comercio.
 *
 * Tres claves que gobiernan cómo se cuenta, resueltas en un solo lugar porque
 * las consumen superficies distintas (`InventoryCountService`, el endpoint
 * `/v1/inventory_count`, el bootstrap que baja a la caja) y las tres tienen
 * que resolverlas igual:
 *
 *   `stockCountBlind`       el operador NO ve el stock teórico mientras cuenta
 *                           (D2). Vive en `config.settingObj`, junto al resto
 *                           de los flags del comercio.
 *   `stockCountRecordOnly`  al finalizar, el conteo NO toca el stock: las
 *                           diferencias quedan registradas y nada más (D9).
 *                           Mismo lugar — son hermanos y el dueño los ve
 *                           juntos en Ajustes.
 *   `stockCountLists`       las listas fijas que arma el dueño (D3): qué se
 *                           cuenta en cada turno. Clave top-level de `config`
 *                           con el array serializado como JSON, mismo trato
 *                           que `settingObj`/`settingSocialMedia`.
 *
 * ── Por qué `recordOnly` y no `applyAdjustment` ─────────────────────────────
 *
 * Porque el default de D1 es que el conteo SÍ ajusta, y un flag ausente en el
 * JSONB vale falso. Un `stockCountApplyAdjustment` ausente —el estado de todos
 * los comercios que existen hoy— significaría "no ajustes", que es exactamente
 * lo contrario de la decisión del owner. Nombrado en negativo, el comercio que
 * nunca tocó nada arranca en el default correcto sin backfill de datos.
 *
 * ── Por qué las listas fijas NO son una entidad propia ──────────────────────
 *
 * Una lista es un nombre y un conjunto de ítems que el dueño edita de vez en
 * cuando; no tiene ciclo de vida, ni permisos propios, ni nada que consultar
 * por sí misma. Lo que sí necesita historia es el conteo, y eso ya está
 * resuelto: cada sesión SNAPSHOTEA en `inventory_count.scope` (mig 158) la
 * lista con la que se abrió, así que editar o borrar una lista no reescribe
 * lo que se contó el mes pasado.
 *
 * ── Blind es una regla de SERVIDOR, no de UI ────────────────────────────────
 *
 * El precedente es `drawerBlind`, que no viaja al cliente: el backend devuelve
 * un resumen recortado (`drawerBlindSummary()` en `api/v1/drawer.php`). Acá
 * igual — el esperado no se manda, en vez de mandarse y pedirle a la pantalla
 * que no lo pinte. Un flag que el cliente tiene que respetar es un flag que se
 * evade abriendo las devtools.
 *
 * Cache por request: el conteo consulta estas claves varias veces en la misma
 * llamada (armar la sesión, redactar la respuesta, decidir el ajuste) y son
 * inmutables dentro de una request.
 */
final class StockCountSettings
{
    /** @var array<string, self> companyId => instancia */
    private static array $cache = [];

    private function __construct(
        private readonly bool $blind,
        private readonly bool $recordOnly,
        /** @var list<array{id: string, name: string, itemIds: list<string>}> */
        private readonly array $lists,
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
            "SELECT config->>'settingObj'      AS so,
                    config->>'stockCountLists' AS lists
               FROM company WHERE companyId = ? LIMIT 1",
            [$companyId],
            false,
            true
        );

        $so    = null;
        $lists = null;
        if ($rs && is_object($rs) && !$rs->EOF) {
            $so    = $rs->fields['so']    ?? null;
            $lists = $rs->fields['lists'] ?? null;
            $rs->Close();
        }

        $obj = json_decode((string) ($so ?? ''), true);
        if (!is_array($obj)) {
            $obj = [];
        }

        return self::$cache[$companyId] = new self(
            self::truthy($obj['stockCountBlind'] ?? null),
            self::truthy($obj['stockCountRecordOnly'] ?? null),
            self::decodeLists($lists),
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

    /** Al finalizar, el conteo no escribe en el ledger — queda como registro (D9). */
    public function recordOnly(): bool
    {
        return $this->recordOnly;
    }

    /** @return list<array{id: string, name: string, itemIds: list<string>}> */
    public function lists(): array
    {
        return $this->lists;
    }

    /** @return array{id: string, name: string, itemIds: list<string>}|null */
    public function findList(string $listId): ?array
    {
        foreach ($this->lists as $l) {
            if ($l['id'] === $listId) {
                return $l;
            }
        }
        return null;
    }

    /**
     * Normaliza lo que haya en la config a la forma que el resto del sistema
     * espera. Una lista sin id, sin nombre o sin ítems se descarta ACÁ y no
     * más adelante: una lista rota que llega a la caja es un conteo que el
     * cajero abre y no puede cerrar, sin nadie que le explique por qué.
     *
     * Es también el validador del CRUD de Ajustes — un solo normalizador para
     * la escritura y la lectura, así lo que se guarda es exactamente lo que se
     * va a leer.
     *
     * @return list<array{id: string, name: string, itemIds: list<string>}>
     */
    public static function decodeLists(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $out  = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id   = trim((string) ($row['id'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($id === '' || $name === '' || isset($seen[$id])) {
                continue;
            }

            $itemIds = [];
            foreach ((array) ($row['itemIds'] ?? []) as $itemId) {
                $itemId = trim((string) $itemId);
                if ($itemId !== '' && !in_array($itemId, $itemIds, true)) {
                    $itemIds[] = $itemId;
                }
            }
            if ($itemIds === []) {
                continue;
            }

            $seen[$id] = true;
            $out[] = ['id' => $id, 'name' => mb_substr($name, 0, 60), 'itemIds' => $itemIds];
        }

        return $out;
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
