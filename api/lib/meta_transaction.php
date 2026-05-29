<?php
/**
 * Helpers para `transactionDetails` dentro de la columna `meta` (jsonb) de `transaction`.
 *
 * Post-migración PG, `transactionDetails` (y `tags`) se guardan como JSON-strings DENTRO
 * de `meta` → `{"transactionDetails":"<json>","tags":"<json>"}` (ver §22.6). Estos helpers
 * leen/escriben `transactionDetails` PRESERVANDO las demás keys de meta (tags, etc.).
 *
 * Usan `$db->Execute` DIRECTO (no `ncmExecute`): ncmExecute aplica `_flattenJsonb`, que
 * mergea las keys de meta en el row y ELIMINA la key `meta` → acá necesitamos el meta
 * crudo para hacer read-modify-write sin perder keys.
 */

/** Lee el meta crudo (array asociativo) de una transacción. [] si no existe la fila. */
function txMetaRead(string $transactionId, string $companyId, ?int $type = null): array
{
    global $db;
    $sql    = 'SELECT meta FROM transaction WHERE transactionId = ? AND companyId = ?';
    $params = [$transactionId, $companyId];
    if ($type !== null) {
        $sql .= ' AND transactionType = ?';
        $params[] = $type;
    }
    $sql .= ' LIMIT 1';

    $res = $db->Execute($sql, $params);
    if ($res === false || $res->EOF) {
        return [];
    }
    return json_decode($res->fields['meta'] ?? '{}', true) ?: [];
}

/** Extrae el array de items de transactionDetails desde un meta array (maneja string o array). */
function txDetailsFromMeta(array $meta): array
{
    $d = $meta['transactionDetails'] ?? null;
    if (is_string($d)) {
        $d = json_decode($d, true);
    }
    return is_array($d) ? $d : [];
}

/**
 * Escribe transactionDetails dentro de meta (read-modify-write; preserva tags y demás keys).
 *
 * @param array $details  El array de items a guardar (se json_encode como string en meta).
 * @return bool false si la UPDATE falló.
 */
function txDetailsWrite(string $transactionId, string $companyId, array $details, ?int $type = null): bool
{
    global $db;
    $meta = txMetaRead($transactionId, $companyId, $type);
    $meta['transactionDetails'] = json_encode($details);

    $sql    = 'UPDATE transaction SET meta = ? WHERE transactionId = ? AND companyId = ?';
    $params = [json_encode($meta), $transactionId, $companyId];
    if ($type !== null) {
        $sql .= ' AND transactionType = ?';
        $params[] = $type;
    }

    return $db->Execute($sql, $params) !== false;
}
