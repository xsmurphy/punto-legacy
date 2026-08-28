<?php
/**
 * POST /v1/logout — cierra la sesión del panel (revoca la fila `auth_session`
 * del Bearer que llega; además borra la cookie `_jwt_panel` legacy si el browser
 * todavía la tiene).
 *
 * No toca `_jwt` (realm pos-app, device pairing). El POS mantiene su sesión
 * persistente — el operador puede cerrar la del panel sin desparear la caja
 * (ver [[project_pos_dual_session_model]]).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once API_APP_DIR . '/includes/auth_session.php';

if (authResolve(['panel'])) {
    authSessionRevokeBySessionId(AUTHED_SESSION_ID, AUTHED_USER_ID);
}
authClearCookie('_jwt_panel', 'Lax');
apiOk(['ok' => true]);
