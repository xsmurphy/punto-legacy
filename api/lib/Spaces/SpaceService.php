<?php
declare(strict_types=1);

namespace Punto\Api\Spaces;

/**
 * SpaceService — CRUD de espacios (space, mig 80) + estado derivado para el
 * plano operativo del POS (context/15-espacios-module-plan.md F0+F1).
 *
 * Un "espacio" es genérico según el rubro: mesa (gastronomía), silla de
 * atención (peluquería), habitación (hostal/hotel). El estado de un espacio
 * NUNCA se edita a mano — se deriva de `space_session` (y en el futuro de
 * `reservation`, F4). Ver listWithState().
 */
final class SpaceService
{
    /** Escenografía sin identidad operativa — se eliminan hard, no soft (ver delete()). */
    public const DECOR_SHAPES = ['bar', 'decor_wall', 'decor_plant'];

    /** @var mixed */
    private $db;
    private SpaceSectorService $sectors;

    public function __construct($db)
    {
        $this->db      = $db;
        $this->sectors = new SpaceSectorService($db);
    }

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function list(string $companyId, string $outletId, ?string $sectorId = null): array
    {
        $where  = ['companyid = ?', 'outletid = ?'];
        $params = [$companyId, $outletId];
        if ($sectorId !== null && $sectorId !== '') {
            $where[]  = 'sectorid = ?';
            $params[] = $sectorId;
        }
        $rs = $this->db->Execute(
            'SELECT * FROM space WHERE ' . implode(' AND ', $where) . ' ORDER BY sort ASC, name ASC',
            $params
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function find(string $companyId, string $id): ?array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM space WHERE tableid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    /**
     * Resuelve el outlet + sector efectivos de una creación. Todo espacio
     * SIEMPRE pertenece a un sector (space.sectorid NOT NULL, mig 82) — acá
     * es donde se garantiza esa invariante para las dos entradas posibles:
     *
     * - Si viene sectorId, el outlet SIEMPRE sale del sector (no del body)
     *   — evita el mismatch "sectorId de una sucursal + outletId de otra"
     *   que rompía con "sectorId inválido para este outlet/tenant" (bug
     *   reportado en prod: el front no reseteaba el filtro de sector al
     *   cambiar de sucursal).
     * - Si NO viene sectorId, el outlet sale de $outletScope/body y se usa
     *   el sector default del outlet (SpaceSectorService::ensureDefaultSector,
     *   crea "Salón" si el outlet no tiene ninguno).
     *
     * Para pos-app, $outletScope acota el resultado al outlet del device —
     * un sector de otra sucursal es un 404/422 claro, no un outlet distinto
     * silencioso.
     *
     * @return array{0:string,1:string} [outletId, sectorId] — sectorId NUNCA null
     */
    private function resolveOutletAndSector(string $companyId, ?string $sectorId, string $bodyOutletId, ?string $outletScope): array
    {
        if ($sectorId === null || $sectorId === '') {
            $outletId = $outletScope ?? $bodyOutletId;
            if ($outletId === '') {
                throw new \InvalidArgumentException('outletId requerido');
            }
            $defaultSectorId = $this->sectors->ensureDefaultSector($companyId, $outletId);
            return [$outletId, $defaultSectorId];
        }

        $sector = ncmExecute(
            'SELECT sectorid, outletid FROM space_sector WHERE sectorid = ? AND companyid = ? LIMIT 1',
            [$sectorId, $companyId]
        );
        if (!$sector) {
            throw new \InvalidArgumentException('sectorId inválido para este tenant');
        }
        $sectorOutletId = (string) $sector['outletid'];
        if ($outletScope !== null && $sectorOutletId !== $outletScope) {
            throw new \InvalidArgumentException('sectorId no pertenece al outlet del device');
        }
        return [$sectorOutletId, $sectorId];
    }

    /**
     * @param array{outletId:string, sectorId?:string, name:string, seats?:int,
     *              shape?:string, sort?:int} $data
     * @return string tableId
     */
    public function create(string $companyId, array $data, ?string $outletScope = null): string
    {
        $sectorId = !empty($data['sectorId']) ? (string) $data['sectorId'] : null;
        $name     = trim((string) ($data['name'] ?? ''));
        $seats    = (int) ($data['seats'] ?? 4);
        $shape    = $this->validateShape((string) ($data['shape'] ?? 'square'));
        $sort     = (int) ($data['sort'] ?? 0);

        if ($name === '') {
            throw new \InvalidArgumentException('name requerido');
        }

        [$outletId, $sectorId] = $this->resolveOutletAndSector($companyId, $sectorId, (string) ($data['outletId'] ?? ''), $outletScope);

        $outlet = ncmExecute('SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1', [$outletId, $companyId]);
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }

        $rs = $this->db->Execute(
            'INSERT INTO space (tableid, companyid, outletid, sectorid, name, seats, shape, sort)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?)
             RETURNING tableid',
            [$companyId, $outletId, $sectorId, $name, $seats, $shape, $sort]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('No se pudo crear el espacio');
        }
        $id = (string) ($rs->fields['tableid'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('No se pudo crear el espacio');
        }
        $this->publish($companyId, $outletId);
        return $id;
    }

    /**
     * Alta rápida de N espacios numerados para la grilla default. Devuelve
     * los tableId creados en orden.
     *
     * @return list<string>
     */
    public function bulkCreate(string $companyId, string $bodyOutletId, int $count, ?string $sectorId = null, ?string $outletScope = null): array
    {
        if ($count < 1 || $count > 200) {
            throw new \InvalidArgumentException('count debe estar entre 1 y 200');
        }

        [$outletId, $sectorId] = $this->resolveOutletAndSector($companyId, $sectorId, $bodyOutletId, $outletScope);

        $outlet = ncmExecute('SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1', [$outletId, $companyId]);
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }

        // Arranca la numeración después del máximo nombre numérico existente
        // en el outlet (no del sector) — evita colisiones visuales de número
        // entre sectores distintos del mismo local.
        $maxRow = ncmExecute(
            "SELECT COALESCE(MAX(name::int), 0) AS maxnum
               FROM space
              WHERE companyid = ? AND outletid = ? AND name ~ '^[0-9]+$'",
            [$companyId, $outletId]
        );
        $start = (int) ($maxRow['maxnum'] ?? 0);

        $ids = [];
        $this->db->StartTrans();
        for ($i = 1; $i <= $count; $i++) {
            $name = (string) ($start + $i);
            $rs = $this->db->Execute(
                'INSERT INTO space (tableid, companyid, outletid, sectorid, name, seats, shape, sort)
                 VALUES (gen_random_uuid(), ?, ?, ?, ?, 4, \'square\', ?)
                 RETURNING tableid',
                [$companyId, $outletId, $sectorId, $name, $start + $i]
            );
            if ($rs === false || $rs->EOF) {
                $this->db->FailTrans();
                break;
            }
            $ids[] = (string) $rs->fields['tableid'];
        }
        $failed = $this->db->HasFailedTrans();
        $this->db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudieron crear los espacios');
        }
        $this->publish($companyId, $outletId);
        return $ids;
    }

    public function update(string $companyId, string $id, array $data): array
    {
        $existing = $this->find($companyId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Espacio no encontrado');
        }

        $name     = array_key_exists('name', $data) ? trim((string) $data['name']) : $existing['name'];
        $seats    = array_key_exists('seats', $data) ? (int) $data['seats'] : $existing['seats'];
        $shape    = array_key_exists('shape', $data) ? $this->validateShape((string) $data['shape']) : $existing['shape'];
        $sort     = array_key_exists('sort', $data) ? (int) $data['sort'] : $existing['sort'];
        $status   = array_key_exists('status', $data) ? (int) $data['status'] : $existing['status'];
        // sectorId NUNCA se limpia a null — space.sectorid es NOT NULL (mig
        // 82). Si el body manda sectorId vacío, es un request malformado, no
        // "sacale el sector".
        $sectorId = array_key_exists('sectorId', $data) ? (string) $data['sectorId'] : (string) $existing['sectorId'];

        if ($name === '') {
            throw new \InvalidArgumentException('name no puede quedar vacío');
        }
        if ($sectorId === '') {
            throw new \InvalidArgumentException('sectorId requerido — el espacio siempre pertenece a un sector');
        }
        // Mismo bug que el mismatch de create/bulkCreate: un sector de otra
        // sucursal que la del espacio (datos legacy con huérfanos ya
        // corregidos por la mig 82, pero un sectorId explícito del body
        // puede seguir siendo de otro outlet). Error claro, no un 422 genérico.
        $this->assertSectorBelongs($companyId, (string) $existing['outletId'], $sectorId);

        $ok = $this->db->Execute(
            'UPDATE space SET name = ?, seats = ?, shape = ?, sort = ?, status = ?, sectorid = ?
              WHERE tableid = ? AND companyid = ?',
            [$name, $seats, $shape, $sort, $status, $sectorId, $id, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo actualizar el espacio');
        }
        $this->publish($companyId, (string) $existing['outletId']);
        return $this->find($companyId, $id) ?? [];
    }

    public function delete(string $companyId, string $id): void
    {
        $existing = $this->find($companyId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Espacio no encontrado');
        }
        if (in_array((string) $existing['shape'], self::DECOR_SHAPES, true)) {
            // Decorativo (barra/pared/planta) = escenografía sin identidad
            // operativa: nunca abre space_session ni recibe pos_order, así que
            // no hay historia que preservar → hard-delete real. El soft-delete
            // lo devolvía en cada list() (que no filtra status) y el editor de
            // layout lo "revivía" tras cada refetch (bug 2026-07-19).
            $ok = $this->db->Execute(
                'DELETE FROM space WHERE tableid = ? AND companyid = ?',
                [$id, $companyId]
            );
            if ($ok === false) {
                throw new \RuntimeException('No se pudo eliminar el bloque decorativo');
            }
        } else {
            // Espacio real: deshabilitar (status=0), no hard-delete —
            // space_session y pos_order lo referencian históricamente
            // (reportes/rotación).
            $ok = $this->db->Execute(
                'UPDATE space SET status = 0 WHERE tableid = ? AND companyid = ?',
                [$id, $companyId]
            );
            if ($ok === false) {
                throw new \RuntimeException('No se pudo deshabilitar el espacio');
            }
        }
        $this->publish($companyId, (string) $existing['outletId']);
    }

    // ------------------------------------------------------------------
    // Layout
    // ------------------------------------------------------------------

    /**
     * Guarda posición/tamaño/rotación de un batch de espacios del editor de
     * layout. Atómico (TX) — todo o nada, todas scopeadas a companyId+outletId
     * (defensa contra que el body incluya un tableId de otro tenant).
     *
     * @param list<array{tableId:string, posX:float|null, posY:float|null,
     *                    width:float|null, height:float|null, rotation?:int,
     *                    shape?:string}> $positions
     */
    public function saveLayout(string $companyId, string $outletId, array $positions): void
    {
        if ($positions === []) {
            return;
        }
        // Validar shape ANTES de abrir la TX — un elemento malformado (string
        // en vez de objeto) no debe dejar una transacción a medio abrir.
        foreach ($positions as $pos) {
            if (!is_array($pos)) {
                throw new \InvalidArgumentException('Cada posición del layout debe ser un objeto {tableId, posX, posY, ...}');
            }
        }

        $this->db->StartTrans();
        foreach ($positions as $pos) {
            $tableId = (string) ($pos['tableId'] ?? '');
            if ($tableId === '') {
                $this->db->FailTrans();
                break;
            }
            $posX     = isset($pos['posX']) && $pos['posX'] !== null ? (float) $pos['posX'] : null;
            $posY     = isset($pos['posY']) && $pos['posY'] !== null ? (float) $pos['posY'] : null;
            $width    = isset($pos['width']) && $pos['width'] !== null ? (float) $pos['width'] : null;
            $height   = isset($pos['height']) && $pos['height'] !== null ? (float) $pos['height'] : null;
            $rotation = isset($pos['rotation']) ? ((int) $pos['rotation']) % 360 : 0;
            if ($rotation < 0) $rotation += 360;

            $params = [$posX, $posY, $width, $height, $rotation, $tableId, $companyId, $outletId];
            $shapeSql = '';
            if (isset($pos['shape'])) {
                $shapeSql = 'shape = ?, ';
                array_splice($params, 4, 0, [$this->validateShape((string) $pos['shape'])]);
            }

            $ok = $this->db->Execute(
                "UPDATE space SET posx = ?, posy = ?, width = ?, height = ?, {$shapeSql}rotation = ?
                  WHERE tableid = ? AND companyid = ? AND outletid = ?",
                $params
            );
            if ($ok === false) {
                $this->db->FailTrans();
                break;
            }
        }
        $failed = $this->db->HasFailedTrans();
        $this->db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo guardar el layout (transacción abortada)');
        }
        $this->publish($companyId, $outletId);
    }

    // ------------------------------------------------------------------
    // Plano operativo
    // ------------------------------------------------------------------

    /**
     * Espacios del outlet con estado derivado + info de la sesión activa (si
     * hay). El estado NUNCA se persiste — se calcula acá:
     *   - status=0                        → 'disabled'
     *   - sesión activa status=open       → 'occupied'
     *   - sesión activa status=bill_requested → 'bill_requested'
     *   - sino                            → 'free'
     * (reservas se suman en F4 — quedaría 'reserved' entre free y occupied)
     *
     * @return array<int,array<string,mixed>>
     */
    public function listWithState(string $companyId, string $outletId): array
    {
        $rs = $this->db->Execute(
            "SELECT t.*,
                    ts.sessionid          AS session_sessionid,
                    ts.status             AS session_status,
                    ts.guests             AS session_guests,
                    ts.waiterid           AS session_waiterid,
                    ts.alias              AS session_alias,
                    ts.opened_at          AS session_opened_at,
                    COALESCE(oc.ordercount, 0) AS order_count
               FROM space t
          LEFT JOIN space_session ts
                 ON ts.tableid = t.tableid
                AND ts.status IN ('open','bill_requested')
          LEFT JOIN (
                    SELECT po.spacesessionid, COUNT(*) AS ordercount
                      FROM pos_order po
                     WHERE po.companyid = ?
                       AND po.status NOT IN ('closed','cancelled')
                       AND po.spacesessionid IS NOT NULL
                  GROUP BY po.spacesessionid
                 ) oc ON oc.spacesessionid = ts.sessionid
              WHERE t.companyid = ? AND t.outletid = ?
              ORDER BY t.sort ASC, t.name ASC",
            [$companyId, $companyId, $outletId]
        );
        if ($rs === false) return [];

        $out = [];
        foreach ($rs->GetRows() as $row) {
            $table = $this->present($row);

            if ((int) ($row['status'] ?? 1) === 0) {
                $state = 'disabled';
            } elseif (($row['session_status'] ?? null) === 'bill_requested') {
                $state = 'bill_requested';
            } elseif (($row['session_status'] ?? null) === 'open') {
                $state = 'occupied';
            } else {
                $state = 'free';
            }

            $table['state'] = $state;
            $table['session'] = $row['session_sessionid'] ? [
                'id'        => (string) $row['session_sessionid'],
                'status'    => (string) $row['session_status'],
                'guests'    => isset($row['session_guests']) ? (int) $row['session_guests'] : null,
                'waiterId'  => $row['session_waiterid'] ?? null,
                // El alias viaja en el payload del MAPA (no solo en el detalle)
                // porque su razón de ser es que el mozo reconozca el espacio de un
                // vistazo — si hay que abrir el diálogo para leerlo, no sirve.
                'alias'     => $row['session_alias'] ?? null,
                'openedAt'  => $row['session_opened_at'] ?? null,
                'orderCount' => (int) ($row['order_count'] ?? 0),
            ] : null;

            $out[] = $table;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function validateShape(string $shape): string
    {
        $valid = ['square', 'round', 'rect', 'bar', 'decor_wall', 'decor_plant'];
        if (!in_array($shape, $valid, true)) {
            throw new \InvalidArgumentException('shape inválido: ' . $shape);
        }
        return $shape;
    }

    private function assertSectorBelongs(string $companyId, string $outletId, string $sectorId): void
    {
        $sector = ncmExecute(
            'SELECT sectorid, outletid FROM space_sector WHERE sectorid = ? AND companyid = ? LIMIT 1',
            [$sectorId, $companyId]
        );
        if (!$sector) {
            throw new \InvalidArgumentException('sectorId inválido para este tenant');
        }
        if ((string) $sector['outletid'] !== $outletId) {
            // Error específico (no el 422 genérico "inválido para este
            // outlet/tenant") — datos legacy (espacios creados sin sector antes
            // de la mig 82) podían tener un sectorId de OTRA sucursal; con la
            // invariante NOT NULL esos huérfanos ya se corrigieron, pero un
            // sectorId explícito del body sigue pudiendo apuntar a otro outlet.
            throw new \InvalidArgumentException('El sector pertenece a otra sucursal');
        }
    }

    private function publish(string $companyId, string $outletId): void
    {
        wsPublish($companyId . ':spaces:' . $outletId, 'space:state', ['outletId' => $outletId]);
        realtimePublish('space', 'update');
    }

    private function present(array $row): array
    {
        return [
            'id'        => (string) ($row['tableid'] ?? ''),
            'companyId' => (string) ($row['companyid'] ?? ''),
            'outletId'  => (string) ($row['outletid'] ?? ''),
            'sectorId'  => $row['sectorid'] ?? null,
            'name'      => (string) ($row['name'] ?? ''),
            'seats'     => (int) ($row['seats'] ?? 4),
            'shape'     => (string) ($row['shape'] ?? 'square'),
            'posX'      => isset($row['posx']) ? (float) $row['posx'] : null,
            'posY'      => isset($row['posy']) ? (float) $row['posy'] : null,
            'width'     => isset($row['width']) ? (float) $row['width'] : null,
            'height'    => isset($row['height']) ? (float) $row['height'] : null,
            'rotation'  => (int) ($row['rotation'] ?? 0),
            'status'    => (int) ($row['status'] ?? 1),
            'sort'      => (int) ($row['sort'] ?? 0),
            'createdAt' => $row['created_at'] ?? null,
        ];
    }
}
