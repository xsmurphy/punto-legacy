<?php
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

class RegisterService
{
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
}
