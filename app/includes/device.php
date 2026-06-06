<?php
/**
 * Device helper para el módulo /app (device pairing model — §28).
 *
 * Registra un device row en la tabla `device` cada vez que un admin loguea
 * desde /app. El deviceId retornado se incluye en el JWT como `did`, lo que
 * permite que el panel del tenant revoque dispositivos específicos sin
 * afectar otros (caso de uso: empleado renunciado se lleva la app logueada
 * en su celular particular).
 *
 * Dependencias: $db global (db.php cargado).
 *
 * Política de re-uso: cada login crea una row nueva. NO intentamos detectar
 * "el mismo dispositivo" porque userAgent + IP no son únicos (cajeros en el
 * mismo WiFi tienen la misma IP; mismo navegador en distintas máquinas).
 * Las rows viejas quedan con `lastSeenAt` antiguo y eventualmente las puede
 * limpiar un cron (TODO futuro) o el admin desde la UI.
 */

function deviceRegister(string $companyId, string $userId, string $outletId, string $registerId): ?string
{
    global $db;
    if (!isset($db) || !is_object($db)) {
        // BD no disponible — emitir JWT sin did (compat con flow legacy)
        error_log('[device.php] BD no disponible al registrar device — JWT sin did');
        return null;
    }

    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strlen($userAgent) > 1000) {
        $userAgent = substr($userAgent, 0, 1000);
    }
    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    // X-Forwarded-For puede tener varias IPs — quedarnos con la primera
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    // Validar IP: PG INET es estricto, una IP malformada rompería el INSERT
    $ipValue = (filter_var($ip, FILTER_VALIDATE_IP) !== false) ? $ip : null;

    try {
        // INSERT … RETURNING deviceId. AutoExecute con RETURNING * lo soporta.
        $record = [
            'companyId'  => $companyId,
            'userId'     => $userId,
            'outletId'   => $outletId   ?: null,
            'registerId' => $registerId ?: null,
            'userAgent'  => $userAgent  ?: null,
            'ipFirst'    => $ipValue,
            'ipLast'     => $ipValue,
            'lastSeenAt' => date('Y-m-d H:i:s'),
            'status'     => 1,
        ];
        $ok = $db->Insert('device', $record);
        if (!$ok) {
            error_log('[device.php] INSERT device falló: ' . ($db->ErrorMsg() ?? 'unknown'));
            return null;
        }
        // Para PG con RETURNING, Insert_ID() devuelve el UUID generado
        $deviceId = (string)$db->Insert_ID();
        if ($deviceId === '' || $deviceId === '0') {
            // Fallback: SELECT del row más reciente del mismo userId+companyId
            $r = $db->Execute(
                "SELECT deviceId FROM device WHERE companyId = ? AND userId = ? ORDER BY createdAt DESC LIMIT 1",
                [$companyId, $userId]
            );
            $deviceId = ($r && !$r->EOF) ? (string)($r->fields['deviceid'] ?? $r->fields['deviceId'] ?? '') : '';
        }
        return $deviceId !== '' ? $deviceId : null;
    } catch (\Throwable $e) {
        error_log('[device.php] excepción registrando device: ' . $e->getMessage());
        return null;
    }
}

// deviceTouch() — pendiente. Se agregará cuando se enganche al endpoint que
// haga "heartbeat" del device (ej. fetchs load real, no ping). Por ahora el
// lastSeenAt sólo se setea al login.
