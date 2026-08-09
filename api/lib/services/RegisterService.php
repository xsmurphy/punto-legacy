<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)

/**
 * RegisterService — sesión de caja (register) del POS (Slice 10).
 *
 * Lógica portada de app/action.php:
 *   setSession (L1976) — fija el sessionId de la caja (mecanismo single-session-por-caja)
 *
 * El front genera un sessionId aleatorio al abrir caja y lo persiste acá; en paralelo
 * se hace un broadcast WS (event 'checkSession') al canal de la caja para que otras
 * pestañas/dispositivos de la MISMA caja detecten que su sesión quedó obsoleta y se
 * bloqueen. El broadcast vive en el endpoint (side-effect), no en el servicio.
 *
 * NOTA — checkSession (action.php L2000) NO se porta: es dead code. El front
 * (ncmAuth.checkSession) sólo bindea un listener WS, nunca llama ese endpoint HTTP.
 * Se eliminará al vaciar action.php.
 *
 * Gotchas PG: identificadores sin comillas (§22.5), registerId/companyId bindeados
 * (el legacy interpolaba el WHERE sin comillas).
 */

final class RegisterService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /**
     * Persiste el sessionId de una caja. sessionId es BIGINT en PG.
     *
     * @return bool true si no hubo error de BD.
     */
    public function setSession(string $registerId, string $companyId, int $sessionId): bool
    {
        global $db;
        $res = $db->Execute(
            'UPDATE register SET sessionId = ? WHERE registerId = ? AND companyId = ?',
            [$sessionId, $registerId, $companyId]
        );
        return $res !== false;
    }

    /**
     * Numeración de documentos de la caja (docsNum, load.php L3796).
     *
     * invoiceNo/ticketNo salen directo del contador guardado en la caja. Los demás
     * (return, schedule, order, quote) usan nextDocNumber(): el mayor entre el contador
     * guardado y el último invoiceNo realmente usado para ese tipo de documento.
     *
     * @return array shape legacy {registerId, invoiceNo, ticketNo, returnNo, scheduleNo, orderNo, quoteNo} o [] si la caja no existe.
     */
    public function docNumbers(string $registerId, string $companyId): array
    {
        $register = ncmExecute(
            'SELECT * FROM register WHERE registerStatus = TRUE AND registerId = ? AND companyId = ? LIMIT 1',
            [$registerId, $companyId],
            false
        );
        if (!$register) {
            return [];
        }

        return [
            'registerId' => $register['registerId'],
            'invoiceNo'  => (int) ($register['registerInvoiceNumber'] ?? 0),
            'ticketNo'   => (int) ($register['registerTicketNumber'] ?? 0),
            'returnNo'   => $this->nextDocNumber((int) ($register['registerReturnNumber'] ?? 0), 6, $companyId, $registerId),
            'scheduleNo' => $this->nextDocNumber((int) ($register['registerScheduleNumber'] ?? 0), 13, $companyId, $registerId),
            'orderNo'    => $this->nextDocNumber((int) ($register['registerPedidoNumber'] ?? 0), 12, $companyId, $registerId),
            'quoteNo'    => $this->nextDocNumber((int) ($register['registerQuoteNumber'] ?? 0), 9, $companyId, $registerId),
        ];
    }

    /**
     * Grilla de accesos directos (hotkeys) de la caja, desde register.data->'hotkeys'.
     * Espejo del JSON que el legacy guarda en ncmHotKeys: [{itemId, position, color, isCategory}].
     *
     * @return array lista de hotkeys, o [] si la caja no existe o no tiene config.
     */
    public function getHotkeys(string $registerId, string $companyId): array
    {
        $row = ncmExecute(
            "SELECT data->'hotkeys' AS hotkeys FROM register
              WHERE registerId = ? AND companyId = ? LIMIT 1",
            [$registerId, $companyId],
            false
        );
        if (!$row || empty($row['hotkeys'])) {
            return [];
        }
        $decoded = json_decode((string) $row['hotkeys'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persiste la grilla de hotkeys en register.data.hotkeys (jsonb_set, crea data si falta).
     * registerId/companyId SIEMPRE del JWT — nunca del request. El array ya viene validado
     * y normalizado por el endpoint.
     *
     * @param array $hotkeys lista de {itemId, position, color, isCategory}.
     * @return bool true si no hubo error de BD.
     */
    public function saveHotkeys(string $registerId, string $companyId, array $hotkeys): bool
    {
        global $db;
        $json = json_encode(array_values($hotkeys), JSON_UNESCAPED_UNICODE);
        $res = $db->Execute(
            "UPDATE register
                SET data = jsonb_set(COALESCE(data, '{}'::jsonb), '{hotkeys}', ?::jsonb, true)
              WHERE registerId = ? AND companyId = ?",
            [$json, $registerId, $companyId]
        );
        return $res !== false;
    }

    /**
     * Vigencia del timbrado de la caja.
     *
     * Devuelve `null` si se puede facturar, o el mensaje de error si NO.
     *
     * Regla del owner (2026-08-08): el timbrado se configura por caja y tiene
     * vencimiento; con el timbrado vencido NO se puede facturar. Un documento
     * emitido con timbrado vencido es inválido ante la SET, así que el corte
     * tiene que ser duro — no un aviso que el cajero pueda saltear.
     *
     * Una caja SIN vencimiento cargado no se bloquea: hay comercios que operan
     * sin numeración fiscal (ver el filtro de `TransactionsService::registerInfo`)
     * y bloquearlos por un campo vacío sería romperles la caja.
     *
     * La comparación es por FECHA local del tenant, no por timestamp UTC: el
     * timbrado vence al terminar su último día, y con TZ del servidor un
     * comercio en Asunción quedaría bloqueado unas horas antes de tiempo.
     */
    public function invoiceAuthError(string $registerId, string $companyId): ?string
    {
        $row = ncmExecute(
            'SELECT data FROM register WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$registerId, $companyId]
        );
        if (!$row) {
            return null;   // sin caja no hay nada que validar acá
        }
        // Query::flattenJsonb aplana `data` y BORRA la columna: la clave se lee
        // ya aplanada, `$row['data']` sería null (mismo patrón que lease.php).
        $expiration = trim((string) ($row['registerInvoiceAuthExpiration'] ?? ''));
        if ($expiration === '') {
            return null;   // caja sin timbrado cargado — no aplica
        }

        // date() ya corre en la TZ del tenant: data.php hace
        // date_default_timezone_set(settingTimeZone) en el boot.
        if ($expiration >= date('Y-m-d')) {
            return null;
        }

        return 'El timbrado de esta caja venció el ' . $expiration
             . '. Actualizalo en Sucursales → Cajas para poder seguir facturando.';
    }

    /**
     * Config general del POS por caja, desde register.data->'posConfig'.
     * Blob libre de toggles; el endpoint mergea con defaults antes de devolver.
     */
    public function getConfig(string $registerId, string $companyId): array
    {
        // OJO con el alias: `config` (igual que `data` y `meta`) es una de las
        // columnas que el wrapper APLANA — Query::flattenJsonb decodifica su
        // contenido al nivel de la fila y BORRA la clave. Con `AS config` esta
        // función devolvía SIEMPRE [] y todos los toggles del POS se leían en su
        // default, ignorando lo guardado (bug 2026-07-30). El alias `posconfig`
        // no está en esa lista y sobrevive intacto.
        $row = ncmExecute(
            "SELECT data->'posConfig' AS posconfig FROM register
              WHERE registerId = ? AND companyId = ? LIMIT 1",
            [$registerId, $companyId],
            false
        );
        if (!$row || empty($row['posconfig'])) {
            return [];
        }
        $decoded = json_decode((string) $row['posconfig'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persiste el blob completo en register.data.posConfig (jsonb_set).
     * El merge con el estado previo lo hace el endpoint antes de invocar esto.
     */
    public function saveConfig(string $registerId, string $companyId, array $config): bool
    {
        global $db;
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        $res = $db->Execute(
            "UPDATE register
                SET data = jsonb_set(COALESCE(data, '{}'::jsonb), '{posConfig}', ?::jsonb, true)
              WHERE registerId = ? AND companyId = ?",
            [$json, $registerId, $companyId]
        );
        return $res !== false;
    }

    /**
     * Mayor entre el contador guardado en la caja y el último invoiceNo realmente usado
     * para $type. Corrige el bug PG del legacy getNextDocNumber()/getValue(): interpolaban
     * companyId (UUID) sin comillas en el WHERE → roto en PG. Acá va todo bindeado.
     */
    private function nextDocNumber(int $number, int $type, string $companyId, string $registerId): int
    {
        $row = ncmExecute(
            'SELECT invoiceNo FROM transaction
              WHERE companyId = ? AND registerId = ?
                AND invoiceNo IS NOT NULL AND invoiceNo > 0
                AND transactionType = ?
              ORDER BY transactionDate DESC LIMIT 1',
            [$companyId, $registerId, $type],
            false
        );
        $lastUsed = $row ? (int) $row['invoiceNo'] : 0;
        return $lastUsed > $number ? $lastUsed : $number;
    }
}
