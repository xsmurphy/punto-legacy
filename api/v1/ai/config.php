<?php
/**
 * GET /v1/ai/config
 *
 * Devuelve un map capability → { model, creditsperktoken } desde ai_model_config
 * WHERE enabled. El route handler del agente lo consulta antes de cada llamada
 * para elegir el modelo configurado.
 *
 * Auth: realms `panel` y `pos-app`. GET only.
 * Si la tabla no existe (deploy sin mig 43) devuelve {} sin romper.
 */

require_once __DIR__ . '/../../bootstrap.php';

// `pos-app` (context/59 F2): el BFF del asistente de la caja necesita el mismo
// map capability→modelo que el del panel para elegir con qué modelo responder.
// No hay dato del tenant acá, es configuración de la plataforma.
apiAuthTenant(['panel', 'pos-app']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Method not allowed', 405);
}

$out = [];

try {
    $rs = ncmExecute(
        'SELECT capability, model, creditsperktoken FROM ai_model_config WHERE enabled = true',
        [],
        false,
        true
    );
    if ($rs && is_object($rs)) {
        while (!$rs->EOF) {
            $f = $rs->fields;
            $out[(string) $f['capability']] = [
                'model'            => (string) $f['model'],
                'creditsperktoken' => (float) ($f['creditsperktoken'] ?? 1),
            ];
            $rs->MoveNext();
        }
    }
} catch (\Throwable $e) {
    error_log('[ai/config] ' . $e->getMessage());
}

apiOk($out);
