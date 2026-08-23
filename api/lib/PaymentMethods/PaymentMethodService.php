<?php
declare(strict_types=1);

namespace Punto\Api\PaymentMethods;

use Punto\Api\Finance\ConfigService;
use Punto\Api\Finance\AccountService;

/**
 * PaymentMethodService — CRUD de medios de pago del tenant.
 *
 * Modelo: los medios de pago viven en la tabla `taxonomy`
 * (taxonomyType='paymentMethod', scoped por companyId). El `taxonomyId` (UUID)
 * es la identidad ESTABLE del método — las ventas nuevas guardan ese UUID como
 * clave del pago, y `finAccountMap` (en company.settingObj) mapea taxonomyId →
 * accountId. Los flags de comportamiento (code, hasChange, requiresIdentifier,
 * identifierLabel/Placeholder, systemKey) se serializan en el JSONB
 * `taxonomyExtra`.
 *
 * Reglas (owner 2026-07-02 — ver context/22):
 *   - Multi-tenant: TODO query filtra por companyId. NUNCA se confía el
 *     companyId del cliente — el endpoint lo resuelve del auth context.
 *   - El método "Efectivo" (taxonomyName == 'Efectivo', case-insensitive) es
 *     el método cash del sistema: siempre resuelve a la cuenta Efectivo
 *     (issystem=true), su accountId no se persiste en finAccountMap, y NO se
 *     puede borrar.
 *   - El mapeo método→cuenta se persiste EXCLUSIVAMENTE vía
 *     ConfigService::update — este servicio nunca escribe finAccountMap directo.
 *   - Borrar un método NO toca finAccountMap ni las ventas históricas (que
 *     resuelven por name vía el backfill path de resolveAccountId). La entrada
 *     huérfana en finAccountMap es inofensiva (se ignora al leer).
 */
final class PaymentMethodService
{
    private $db;
    private ConfigService $config;

    public function __construct($db)
    {
        $this->db     = $db;
        $this->config = new ConfigService();
    }

    /** @return array<int,array<string,mixed>> */
    public function list(string $companyId): array
    {
        $this->ensureSeed($companyId);
        $accountMap = $this->accountIdByMethod($companyId);

        // taxonomyExtra es TEXT (no jsonb): el operador ->> no existe sobre esa
        // columna y Postgres tira "operator does not exist: text ->> unknown",
        // devolviendo $rs===false y vaciando el catálogo entero. El sort por
        // sortOrder se hace en PHP (present() ya decodifica el JSON).
        $rs = $this->db->Execute(
            'SELECT taxonomyId, taxonomyName, taxonomyExtra
               FROM taxonomy
              WHERE companyId = ? AND taxonomyType = ?
              ORDER BY taxonomyName ASC',
            [$companyId, 'paymentMethod']
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->present($row, $accountMap);
        }
        usort($out, function (array $a, array $b): int {
            $sa = $a['sortOrder'] ?? PHP_INT_MAX;
            $sb = $b['sortOrder'] ?? PHP_INT_MAX;
            return $sa <=> $sb ?: strcasecmp((string) $a['name'], (string) $b['name']);
        });
        return $out;
    }

    public function find(string $companyId, string $id): ?array
    {
        $accountMap = $this->accountIdByMethod($companyId);
        $rs = $this->db->Execute(
            'SELECT taxonomyId, taxonomyName, taxonomyExtra
               FROM taxonomy
              WHERE taxonomyId = ? AND companyId = ? AND taxonomyType = ?
              LIMIT 1',
            [$id, $companyId, 'paymentMethod']
        );
        if ($rs === false || $rs->EOF) return null;
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row, $accountMap);
    }

    /**
     * @return string el taxonomyId nuevo
     * @throws \RuntimeException
     */
    public function create(string $companyId, array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('name requerido');
        }
        // "Efectivo" es el método cash único del sistema — un segundo row con
        // ese nombre generaría dos métodos undeletable/accountId-locked (ambos
        // matchean isCashName) y rompería la detección 1:1 cash. Bloqueado acá
        // en vez de UNIQUE en `taxonomy` (compartida con otros taxonomyType).
        if ($this->isCashName($name) && $this->hasCashMethod($companyId)) {
            throw new \RuntimeException('Ya existe un medio de pago Efectivo');
        }
        $extra = $this->buildExtra($input, null);

        $rs = $this->db->Execute(
            'INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, taxonomyName, taxonomyExtra)
             VALUES (gen_random_uuid(), ?, ?, ?, ?::jsonb)
             RETURNING taxonomyId',
            [$companyId, 'paymentMethod', $name, json_encode($extra)]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('No se pudo crear el medio de pago');
        }
        $newId = (string) ($rs->fields['taxonomyid'] ?? $rs->fields['taxonomyId'] ?? '');
        if ($newId === '') {
            throw new \RuntimeException('No se pudo crear el medio de pago');
        }

        // El mapeo de cuenta se persiste vía ConfigService (único write path).
        // Se ignora para el método cash (Efectivo).
        if (!$this->isCashName($name) && array_key_exists('accountId', $input)) {
            $this->config->update($companyId, [$newId => $this->normalizeAccountId($input['accountId'])]);
        }
        return $newId;
    }

    public function update(string $companyId, string $id, array $input): void
    {
        $current = $this->find($companyId, $id);
        if ($current === null) {
            throw new \RuntimeException('Medio de pago no encontrado');
        }

        $name = array_key_exists('name', $input)
            ? trim((string) $input['name'])
            : (string) $current['name'];
        if ($name === '') {
            throw new \RuntimeException('name no puede estar vacío');
        }
        // Renombrar OTRO método a "Efectivo" también crearía un segundo cash —
        // mismo guard que create().
        if ($this->isCashName($name) && !$this->isCashName((string) $current['name']) && $this->hasCashMethod($companyId)) {
            throw new \RuntimeException('Ya existe un medio de pago Efectivo');
        }

        $extra = $this->buildExtra($input, $current);

        $ok = $this->db->Execute(
            'UPDATE taxonomy
                SET taxonomyName = ?, taxonomyExtra = ?::jsonb
              WHERE taxonomyId = ? AND companyId = ? AND taxonomyType = ?',
            [$name, json_encode($extra), $id, $companyId, 'paymentMethod']
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo actualizar el medio de pago');
        }

        // Efectivo ignora accountId (cae siempre en cuenta Efectivo del sistema).
        if (!$this->isCashName($name) && array_key_exists('accountId', $input)) {
            $this->config->update($companyId, [$id => $this->normalizeAccountId($input['accountId'])]);
        }
    }

    public function delete(string $companyId, string $id): void
    {
        $existing = $this->find($companyId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Medio de pago no encontrado');
        }
        if ($this->isCashName((string) $existing['name']) || ($existing['systemKey'] ?? null) === 'cash') {
            throw new \RuntimeException('El medio de pago Efectivo no se puede eliminar');
        }
        // El guard de cash va TAMBIÉN en el WHERE (no solo en el pre-check) para
        // cerrar el TOCTOU: si otra request renombra este row a "Efectivo" o le
        // setea systemKey='cash' entre el find() y el DELETE, el WHERE lo excluye
        // atómicamente y no se borra nunca la caja del sistema. taxonomyExtra es
        // TEXT (no jsonb) — ->> no existe sobre esa columna y rompe el DELETE
        // (mismo bug que list()/paymentMethodsFull()); el guard systemKey se
        // hace con LIKE sobre el texto crudo, sin castear a jsonb.
        $ok = $this->db->Execute(
            "DELETE FROM taxonomy
              WHERE taxonomyId = ? AND companyId = ? AND taxonomyType = ?
                AND taxonomyName NOT ILIKE 'efectivo'
                AND COALESCE(taxonomyExtra, '') NOT LIKE '%\"systemKey\":\"cash\"%'",
            [$id, $companyId, 'paymentMethod']
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo eliminar el medio de pago');
        }
        // NO tocamos finAccountMap: la entrada huérfana es inofensiva (se ignora
        // al leer, ConfigService::read solo emite métodos existentes).
    }

    /**
     * Auto-seed idempotente: si el tenant no tiene ningún medio de pago, crea
     * los defaults (mismos que FALLBACK_PAYMENT_METHODS del BFF POS).
     * "Efectivo" DEBE llamarse exactamente así (detección isCash).
     *
     * Incluye Giftcard (systemKey='giftcard'): su flujo especial en pay-dialog
     * (validación + settlement) se dispara por systemKey, así que DEBE existir
     * como método real — si no se seedea, el POS nunca lo vería una vez que
     * ensureSeed corre (el fallback del bootstrap solo aplica con lista vacía).
     */
    public function ensureSeed(string $companyId): void
    {
        $rs = $this->db->Execute(
            'SELECT COUNT(*) AS n FROM taxonomy WHERE companyId = ? AND taxonomyType = ?',
            [$companyId, 'paymentMethod']
        );
        if ($rs === false) return;
        $rows = $rs->GetRows();
        $n = (int) ($rows[0]['n'] ?? 0);
        if ($n > 0) return;

        // Color default por método (keys de la paleta unificada del panel —
        // frontend/lib/ui/color-palette.ts). sortOrder incremental por orden.
        $defaults = [
            ['Efectivo', ['code' => 'A', 'hasChange' => true,  'requiresIdentifier' => false, 'systemKey' => 'cash', 'color' => 'emerald']],
            ['T. Crédito', ['code' => 'S', 'hasChange' => false, 'requiresIdentifier' => true, 'identifierLabel' => 'Nro de operación', 'identifierPlaceholder' => 'Ej. 123456', 'color' => 'sky']],
            ['T. Débito', ['code' => 'D', 'hasChange' => false, 'requiresIdentifier' => true, 'identifierLabel' => 'Nro de operación', 'identifierPlaceholder' => 'Ej. 123456', 'color' => 'violet']],
            ['Giftcard', ['code' => 'G', 'hasChange' => false, 'requiresIdentifier' => true, 'identifierLabel' => 'Código de giftcard', 'identifierPlaceholder' => 'Ej. GC-1234-5678', 'systemKey' => 'giftcard', 'color' => 'amber']],
            // Cheques (F1, context/30): systemKey='check' dispara el prompt de
            // identifier existente en el pay-dialog del POS (nro de cheque) y
            // es el discriminante que usa FinanceLedger para no generar
            // movimiento directo (la plata entra recién al efectivizar).
            ['Cheque', ['code' => 'F', 'hasChange' => false, 'requiresIdentifier' => true, 'identifierLabel' => 'Nro de cheque', 'identifierPlaceholder' => 'Ej. 001234', 'systemKey' => 'check', 'color' => 'rose']],
        ];
        foreach ($defaults as $i => [$name, $extra]) {
            $extra['sortOrder'] = $i;
            $this->db->Execute(
                'INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, taxonomyName, taxonomyExtra)
                 VALUES (gen_random_uuid(), ?, ?, ?, ?::jsonb)',
                [$companyId, 'paymentMethod', $name, json_encode($this->normalizeExtra($extra))]
            );
        }
    }

    /**
     * Provisiona el medio de pago de UNA pasarela de pago (PSP) si el tenant
     * no lo tiene. Lo invoca ModulesService al habilitar el canal QR del
     * módulo de esa pasarela: el cobro con QR del POS se dispara por
     * systemKey, así que sin este row el botón no tendría contra qué
     * registrar el pago.
     *
     * ── Por qué UN medio de pago POR pasarela ───────────────────────────────
     *
     * Hasta 2026-08 había un solo bucket "QR" para todo el tenant, porque
     * había un solo PSP. Con dos pasarelas activas (Bancard + uPay) los dos
     * cobros caerían en el mismo medio: el arqueo no podría separarlos y las
     * liquidaciones no cuadrarían — son dos ventanas de acreditación y dos
     * escalas de comisión distintas.
     *
     * La separación la da la FILA de taxonomía, no el systemKey: la venta
     * persiste `transactionPaymentType[].type = taxonomyId` (ver
     * `frontend/lib/commands/create-sale.ts`) y el rollup diario agrupa por
     * `COALESCE(type, name)` (`rollup_payments_day`, mig 160). Dos filas
     * distintas ⇒ dos buckets distintos, sin tocar el grano del rollup ni
     * agregar columnas.
     *
     * ── Idempotencia y adopción ─────────────────────────────────────────────
     *
     * Si ya hay un método con ese systemKey, no hace nada. Si el comercio YA
     * tiene un método llamado igual (creado a mano antes de que existiera el
     * módulo) se lo ADOPTA — se le pone el systemKey en vez de crear un
     * segundo método homónimo al lado. Saltear sin adoptar dejaba al tenant
     * con el método visible pero sin el flujo del QR.
     *
     * @param string $systemKey  discriminante del medio (`taxonomyExtra.systemKey`)
     * @param string $name       nombre del medio; también el que se adopta si ya existe
     * @param string $code       atajo de teclado en la grilla del POS
     * @param string $color      color del borde (paleta de frontend/lib/ui/color-palette.ts)
     */
    public function ensurePspMethod(
        string $companyId,
        string $systemKey,
        string $name,
        string $code,
        string $color
    ): void {
        // ensureSeed primero: en un tenant sin ningún método, seedear después
        // de insertar el del PSP no correría (ensureSeed aborta si ya hay
        // filas) y el comercio quedaría con el QR y sin Efectivo.
        $this->ensureSeed($companyId);

        $maxSort   = -1;
        $sameName  = null;
        foreach ($this->list($companyId) as $m) {
            if (($m['systemKey'] ?? null) === $systemKey) return;
            $maxSort = max($maxSort, (int) ($m['sortOrder'] ?? -1));
            if (strcasecmp(trim((string) $m['name']), $name) === 0) {
                $sameName = $m;
            }
        }

        if ($sameName !== null) {
            // Un método que ya es de otra pasarela —o de otro flujo del
            // sistema, como Efectivo/Cheque— NO se roba por coincidencia de
            // nombre: reapuntarlo mandaría plata de un medio a otro. Y crear
            // un homónimo tampoco se puede (UNIQUE uq_taxonomy_company_type_name
            // sobre companyId+type+lower(name)), así que se falla explícito.
            // El caller (ModulesService) lo loguea y deja el módulo activo: el
            // POS avisa que falta el medio en vez de cobrar contra el ajeno.
            if (($sameName['systemKey'] ?? null) !== null) {
                throw new \RuntimeException(
                    "El comercio ya tiene un medio de pago \"$name\" reservado para otro flujo "
                    . '(' . (string) $sameName['systemKey'] . "); no se puede provisionar el de la pasarela '$systemKey'."
                );
            }

            // Adopción: systemKey + requiresIdentifier=false (el identificador
            // del cobro por QR es el UID que genera el POS, no algo que el
            // cajero tipee). El resto del extra del comercio se preserva.
            $this->db->Execute(
                "UPDATE taxonomy
                    SET taxonomyExtra = (
                          COALESCE(NULLIF(taxonomyExtra, ''), '{}')::jsonb
                          || jsonb_build_object('systemKey', ?::text, 'requiresIdentifier', false)
                        )::text
                  WHERE taxonomyId = ? AND companyId = ? AND taxonomyType = ?",
                [$systemKey, (string) $sameName['id'], $companyId, 'paymentMethod']
            );
            return;
        }

        // Va al final del orden actual — no se mete arriba de los métodos que
        // el comercio ya ordenó a mano (sortOrder, mig del drag&drop).

        $extra = [
            'code'               => $code,
            'hasChange'          => false,
            'requiresIdentifier' => false,
            'systemKey'          => $systemKey,
            'color'              => $color,
            'sortOrder'          => $maxSort + 1,
        ];
        $this->db->Execute(
            'INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, taxonomyName, taxonomyExtra)
             VALUES (gen_random_uuid(), ?, ?, ?, ?::jsonb)',
            [$companyId, 'paymentMethod', $name, json_encode($this->normalizeExtra($extra))]
        );
    }

    /**
     * Medio de pago del QR de Bancard (systemKey='qr').
     *
     * Wrapper delgado sobre `ensurePspMethod()` — se conserva porque 'qr' es
     * la identidad HISTÓRICA del medio de Bancard: los tenants que ya tienen
     * el módulo activo tienen esa fila, y las ventas viejas guardaron su
     * taxonomyId. Renombrarla o cambiarle el systemKey partiría la serie del
     * reporte en dos buckets a mitad de la historia, así que no se toca.
     */
    public function ensureQrMethod(string $companyId): void
    {
        $psp = PspCatalog::qrProvider('bancard');
        $this->ensurePspMethod(
            $companyId,
            (string) $psp['systemKey'],
            (string) $psp['methodName'],
            (string) $psp['code'],
            (string) $psp['color']
        );
    }

    /**
     * Reordena los medios de pago del tenant: setea sortOrder = índice en cada
     * taxonomyExtra según el orden de $orderedIds. Scopeado por companyId +
     * taxonomyType (NUNCA toca rows de otro tenant ni de otro taxonomyType) y
     * envuelto en transacción.
     *
     * @param string[] $orderedIds taxonomyIds en el orden deseado
     */
    public function reorder(string $companyId, array $orderedIds): void
    {
        // Normalizar entrada: strings no vacíos, sin duplicados.
        $ids = [];
        foreach ($orderedIds as $id) {
            $id = (string) $id;
            if ($id !== '' && !in_array($id, $ids, true)) $ids[] = $id;
        }
        if ($ids === []) {
            throw new \RuntimeException('orderedIds vacío');
        }

        // Set actual de métodos del tenant. Validamos que orderedIds sea una
        // permutación EXACTA de este set — así un cliente no puede reordenar
        // parcialmente (dejaría métodos con sortOrder viejo mezclado) ni colar
        // ids de otro tenant (el set de referencia ya está scopeado a companyId).
        $existing = [];
        foreach ($this->list($companyId) as $m) {
            $existing[(string) $m['id']] = true;
        }
        foreach ($ids as $id) {
            if (!isset($existing[$id])) {
                throw new \RuntimeException('orderedIds contiene un método inexistente o de otro comercio');
            }
        }
        if (count($ids) !== count($existing)) {
            throw new \RuntimeException('orderedIds debe incluir todos los medios de pago del comercio');
        }

        $this->db->StartTrans();
        try {
            $pos = 0;
            foreach ($ids as $id) {
                // jsonb `||` mergea la clave sortOrder sin pisar el resto del extra.
                // taxonomyExtra es TEXT, no jsonb (mismo motivo por el que list()
                // ordena en PHP): hay que castear a jsonb para mergear y volver a
                // text para guardar. NULLIF cubre el string vacío, que '::jsonb'
                // no parsea. El WHERE va scopeado por companyId+type (defensa en
                // profundidad, ya validado arriba). affected=0 ⇒ el row desapareció
                // entre el list() y el update (race) — lo señalamos.
                $affected = $this->db->Execute(
                    "UPDATE taxonomy
                        SET taxonomyExtra = (COALESCE(NULLIF(taxonomyExtra, ''), '{}')::jsonb || jsonb_build_object('sortOrder', ?::int))::text
                      WHERE taxonomyId = ? AND companyId = ? AND taxonomyType = ?",
                    [$pos, $id, $companyId, 'paymentMethod']
                );
                if ($affected === false) {
                    throw new \RuntimeException('Fallo el UPDATE de reorder');
                }
                if ((int) $this->db->Affected_Rows() === 0) {
                    error_log("[payment-methods:reorder] companyId={$companyId} id={$id} no matcheó ninguna fila (race?)");
                }
                $pos++;
            }
        } catch (\Throwable $e) {
            $this->db->FailTrans();
            throw new \RuntimeException('No se pudo reordenar los medios de pago');
        }
        $this->db->CompleteTrans();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Construye el taxonomyExtra a persistir combinando input parcial con el
     * estado actual (para updates parciales). systemKey es inmutable una vez
     * seteado por el seed — no se puede reasignar desde la UI.
     */
    private function buildExtra(array $input, ?array $current): array
    {
        $get = function (string $key, $fallback) use ($input, $current) {
            if (array_key_exists($key, $input)) return $input[$key];
            if ($current !== null && array_key_exists($key, $current)) return $current[$key];
            return $fallback;
        };
        $extra = [
            'code'                  => $this->str($get('code', '')),
            'hasChange'             => $this->bool($get('hasChange', false)),
            'requiresIdentifier'    => $this->bool($get('requiresIdentifier', false)),
            'identifierLabel'       => $this->str($get('identifierLabel', '')),
            'identifierPlaceholder' => $this->str($get('identifierPlaceholder', '')),
            'color'                 => $this->str($get('color', '')),
        ];
        // sortOrder: NO editable en el form del método — lo maneja el endpoint
        // reorder y el seed. Se preserva el valor actual en updates parciales.
        $sortOrder = $current !== null ? $current['sortOrder'] ?? null : null;
        if ($sortOrder !== null) {
            $extra['sortOrder'] = (int) $sortOrder;
        }
        // systemKey: inmutable desde el seed; nunca editable por el cliente.
        $systemKey = $current !== null ? ($current['systemKey'] ?? null) : null;
        if ($systemKey !== null && $systemKey !== '') {
            $extra['systemKey'] = (string) $systemKey;
        }
        return $extra;
    }

    private function normalizeExtra(array $extra): array
    {
        $out = [
            'code'                  => (string) ($extra['code'] ?? ''),
            'hasChange'             => (bool) ($extra['hasChange'] ?? false),
            'requiresIdentifier'    => (bool) ($extra['requiresIdentifier'] ?? false),
            'identifierLabel'       => (string) ($extra['identifierLabel'] ?? ''),
            'identifierPlaceholder' => (string) ($extra['identifierPlaceholder'] ?? ''),
            'color'                 => (string) ($extra['color'] ?? ''),
        ];
        if (isset($extra['sortOrder']) && $extra['sortOrder'] !== '') {
            $out['sortOrder'] = (int) $extra['sortOrder'];
        }
        if (isset($extra['systemKey']) && $extra['systemKey'] !== '') {
            $out['systemKey'] = (string) $extra['systemKey'];
        }
        return $out;
    }

    /**
     * @param array<string,string|null> $accountMap taxonomyId => accountId resuelto
     */
    private function present(array|\CaseInsensitiveArray $row, array $accountMap): array
    {
        $id    = (string) ($row['taxonomyid'] ?? $row['taxonomyId'] ?? '');
        $name  = (string) ($row['taxonomyname'] ?? $row['taxonomyName'] ?? '');
        $extraRaw = $row['taxonomyextra'] ?? $row['taxonomyExtra'] ?? null;
        $extra = is_string($extraRaw) ? (json_decode($extraRaw, true) ?: []) : (is_array($extraRaw) ? $extraRaw : []);

        return [
            'id'                    => $id,
            'name'                  => $name,
            'code'                  => (string) ($extra['code'] ?? ''),
            'hasChange'             => (bool) ($extra['hasChange'] ?? false),
            'requiresIdentifier'    => (bool) ($extra['requiresIdentifier'] ?? false),
            'identifierLabel'       => (string) ($extra['identifierLabel'] ?? ''),
            'identifierPlaceholder' => (string) ($extra['identifierPlaceholder'] ?? ''),
            'color'                 => (string) ($extra['color'] ?? ''),
            'sortOrder'             => isset($extra['sortOrder']) && $extra['sortOrder'] !== '' ? (int) $extra['sortOrder'] : null,
            'systemKey'             => isset($extra['systemKey']) && $extra['systemKey'] !== '' ? (string) $extra['systemKey'] : null,
            'accountId'             => $accountMap[$id] ?? null,
        ];
    }

    /**
     * Devuelve taxonomyId => accountId resuelto (cash → cuenta Efectivo,
     * resto → finAccountMap o null). Reusa ConfigService::read (único lector
     * del map) — no reimplementa el parsing de settingObj.
     *
     * @return array<string,?string>
     */
    private function accountIdByMethod(string $companyId): array
    {
        $out = [];
        foreach ($this->config->read($companyId) as $entry) {
            $out[(string) $entry['methodId']] = $entry['accountId'] ?? null;
        }
        return $out;
    }

    private function normalizeAccountId($v): ?string
    {
        if ($v === null) return null;
        $v = (string) $v;
        return $v === '' ? null : $v;
    }

    private function isCashName(string $name): bool
    {
        return strcasecmp(trim($name), 'Efectivo') === 0;
    }

    /** true si el tenant ya tiene un método de pago llamado "Efectivo". */
    private function hasCashMethod(string $companyId): bool
    {
        $rs = $this->db->Execute(
            "SELECT 1 FROM taxonomy
              WHERE companyId = ? AND taxonomyType = ? AND taxonomyName ILIKE 'efectivo'
              LIMIT 1",
            [$companyId, 'paymentMethod']
        );
        return $rs !== false && !$rs->EOF;
    }

    private function str($v): string
    {
        return $v === null ? '' : trim((string) $v);
    }

    private function bool($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_string($v)) return in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true);
        return (bool) $v;
    }
}
