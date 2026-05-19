<?php

/**
 * CompoundService — gestiona la tabla `toCompound` (componentes de combos/producciones).
 *
 * Un item tipo combo/precombo/comboAddons/production/direct_production
 * tiene N filas en toCompound apuntando a los items "hijos" (insumos o
 * sub-productos) con su cantidad y orden de presentación.
 */
class CompoundService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Elimina todos los componentes de un item.
     */
    public function clear(string $itemId): void
    {
        ncmExecute('DELETE FROM toCompound WHERE itemId = ?', [$itemId]);
    }

    /**
     * Reemplaza los componentes de un item por la lista dada.
     *
     * Estrategia "delete-on-first-valid" (preservada del legacy): el DELETE
     * solo dispara cuando encontramos el primer componente válido. Si todos
     * los compid son inválidos, no toca la tabla — el caller decide.
     *
     * @param string $itemId
     * @param array  $compIds      IDs encriptados (necesitan dec())
     * @param array  $compQtys     Cantidades como strings
     * @param array  $compPres     Flags "preselected" por componente (opcional)
     * @param ?string $preselectedId  ID encriptado del compound preselected (qty = -1)
     * @param bool   $isCombo
     * @param bool   $isProduction
     */
    public function rebuild(
        string $itemId,
        array $compIds,
        array $compQtys,
        array $compPres,
        ?string $preselectedId,
        bool $isCombo,
        bool $isProduction
    ): void {
        if ($preselectedId !== null && $preselectedId !== '') {
            $compIds[]  = $preselectedId;
            $compQtys[] = -1;
        }

        $cleared = false;
        foreach ($compIds as $key => $_) {
            $compid  = $compIds[$key] ?? null;
            $compqty = $compQtys[$key] ?? 0;
            $comppre = $compPres[$key] ?? 0;

            // qty se formatea con precisión distinta para combo (2) vs producción (3).
            $compu = 0;
            if ($isCombo) {
                $compu = formatNumberToInsertDB($compqty, true, 2);
            } elseif ($isProduction) {
                $compu = formatNumberToInsertDB($compqty, true, 3);
            }
            // qty = -1 indica componente "preselected" (cantidad libre en runtime).
            if ($compqty === -1) {
                $compu = $compqty;
            }

            if (validity($compid)) {
                if (!$cleared) {
                    $this->clear($itemId);
                    $cleared = true;
                }
                // toCompoundPreselected es UUID FK → NULL si no es un UUID válido.
                // El legacy mandaba 0 acá y PG lo rechazaba con "invalid input syntax for type uuid".
                $preselected = (is_string($comppre) && preg_match('/^[0-9a-f]{8}-/', $comppre))
                    ? $comppre
                    : null;
                $this->db->AutoExecute('toCompound', [
                    'itemId'                => $itemId,
                    'compoundId'            => dec($compid),
                    'toCompoundQty'         => $compu,
                    'toCompoundOrder'       => $key,
                    'toCompoundPreselected' => $preselected,
                ], 'INSERT');
            }
        }
    }
}
