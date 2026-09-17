<?php
/**
 * REST canónico — Marcación de asistencia (RRHH F1, context/83 §4 y §7).
 *
 *   POST   /v1/attendance                       ← realm `pos-app` (el QUIOSCO)
 *          multipart: employeeId, markPinHash, kind=in|out, markedAt,
 *                     noPhotoReason?, faceOutcome?, photo (archivo)
 *          header:    X-Punto-Op-Id  (idempotencia de la cola del POS)
 *
 *   GET    /v1/attendance?resource=faces&modelVersion=<m>  ← realm `pos-app`
 *          → { modelVersion, faces, enrollment }
 *   POST   /v1/attendance?action=face-enroll               ← realm `pos-app`
 *          multipart: employeeId, modelVersion, samples (JSON), photo
 *
 *   GET    /v1/attendance?from=&to=[&employeeId=][&outletId=][&needsReview=1]
 *                                              ← realm `panel`
 *          → { marks, employees, totals }
 *   GET    /v1/attendance?resource=photo&id=<uuid>   → la foto (binario)
 *   POST   /v1/attendance?id=<uuid>&action=review    → marcada como revisada
 *
 * ── Reemplaza al verificador del QR (§0 del plan) ───────────────────────────
 *
 * Este archivo hacía otra cosa: validaba un token `md5(companyId . outletId)`
 * impreso en un QR del local y hacía TOGGLE sobre la tabla `attendance`. Muere
 * con esta fase, y no por gusto: el token era derivable (dos ids que el propio
 * empleado conoce), así que probaba exactamente nada sobre la presencia física,
 * y el PIN desde el celular propio se presta — que es la falla que motivó
 * invertir el modelo hacia el dispositivo DEL COMERCIO.
 *
 * ── El quiosco no tiene sesión de operador, y es a propósito ────────────────
 *
 * El resto de `/api/pos/*` que escribe exige la afirmación de operador
 * (`X-Operator-Token`, el PIN del lockscreen). Acá NO: el quiosco es del
 * comercio y atiende a gente que en su mayoría no tiene usuario del sistema
 * (cocina, limpieza — D2 del plan). Pedir un PIN de operador para que un
 * cocinero marque su entrada obligaría a inventarle una credencial, que es
 * justo lo que el modelo de `employee` evita.
 *
 * Lo que autentica es el BEARER DEL DEVICE: esto solo lo puede escribir una
 * tablet pareada del comercio. Quién marcó lo dice su PIN de marcación propio
 * (`employee.markpinhash`) y, sobre todo, la FOTO del momento.
 *
 * ── Sin gate de módulo en el alta, y también a propósito ────────────────────
 *
 * El módulo `rrhh` gobierna las SUPERFICIES: si está apagado, el bootstrap no
 * le baja empleados a la caja y el panel no muestra el reporte. Pero una
 * marcación que ya ocurrió no se rechaza porque un interruptor cambió mientras
 * esperaba en la cola del dispositivo: eso la dejaría `failed`, trabando el
 * canal, con un botón de descartar al lado de un hecho real (misma clase de
 * problema que la D8 de context/34 §F7).
 *
 * ── Realms, y por qué el legajo no se toca desde acá ────────────────────────
 *
 * El reporte es `panel` + `hr.attendance.view`; revisar es `hr.attendance.review`.
 * El alta es `pos-app` y solo `pos-app`. Un device NO puede leer el reporte:
 * traería nombres, horarios y tardanzas de todo el equipo a una tablet del
 * mostrador (mismo criterio que `employees.php`).
 *
 * Auditoría y realtime: automáticos, como toda mutación bajo `/v1/` que pase
 * por `apiAuthTenant()` (`api/bootstrap.php` §52). La entity derivada del path
 * es `attendance`.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Auth/OperatorContext.php';

use Punto\Api\Auth\OperatorContext;
use Punto\App\Helpers\Date;

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = (string) $ctx['companyId'];
$realm     = (string) ($ctx['realm'] ?? '');
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource  = $_GET['resource'] ?? null;
$action    = $_GET['action']   ?? null;
$id        = $_GET['id']       ?? null;

/** El service con S3: la foto de evidencia se archiva privada, como el legajo. */
$svc = new \Punto\Api\Hr\AttendanceService(
    new \Punto\Api\Storage\S3Client(S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET, S3_KEY_PREFIX)
);

/** Realm `panel` y nada más. Ver el docblock. */
$requirePanel = static function () use ($realm): void {
    if ($realm !== 'panel') {
        apiError('Este recurso solo está disponible desde el panel', 403);
    }
};

/**
 * El device tiene que ser una CAJA, no cualquier aparato pareado.
 *
 * Una pantalla de cliente, un KDS o la estación de impresión autentican con el
 * MISMO realm (`device.module`). Solo una caja es un quiosco — mismo
 * discriminante que usa `unlock-pin.php` y el roster del bootstrap.
 *
 * Se extrajo acá porque ahora lo necesitan tres caminos (marcar, bajar rostros,
 * registrar un rostro) y tres copias de un gate son tres lugares donde el día
 * que cambie alguien se olvida de uno.
 */
$requireKiosk = static function () use ($ctx, $realm): void {
    if ($realm !== 'pos-app') {
        apiError('Esta acción se hace desde el dispositivo del comercio', 403);
    }
    if ((string) ($ctx['module'] ?? 'pos') !== 'pos') {
        apiError('Este dispositivo no puede registrar marcaciones', 403);
    }
};

/** El service del rostro comparte el S3 con el de la marcación. */
$faces = new \Punto\Api\Hr\EmployeeFaceService(
    new \Punto\Api\Storage\S3Client(S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET, S3_KEY_PREFIX)
);

// ── Rostros que este quiosco puede reconocer ───────────────────────────────
//
// Se sirve por un endpoint propio y NO dentro del bootstrap del POS, que es
// donde baja el roster de PINes de la F1. Tres razones, y ninguna es de estilo:
//
//   1. El bootstrap lo pide TODA caja al arrancar. Los vectores solo los
//      necesita la pantalla de marcación, y sumarlos ahí sería carga muerta en
//      cada apertura de turno de cada comercio, use o no la marcación.
//   2. La biometría se manda a quien la va a usar, cuando la va a usar. Bajarla
//      "por las dudas" a cada dispositivo es exactamente lo que la D5 evita.
//   3. Cambia con otra frecuencia: un rostro se registra una vez y el roster de
//      PINes se toca seguido.
//
// El alcance sale del CONTEXTO DEL DEVICE (su sucursal), nunca del query string:
// dejar que el cliente pida "los rostros de la sucursal X" sería dejarle elegir
// contra quién compara.
if ($method === 'GET' && $resource === 'faces') {
    $requireKiosk();

    // La versión del modelo SÍ la declara el cliente, y tiene que ser así: es
    // SU modelo el que va a comparar. El servidor no la interpreta, la usa para
    // no mandarle vectores de otra versión — que no fallarían, darían números
    // sin sentido.
    $modelVersion = trim((string) ($_GET['modelVersion'] ?? ''));
    $outletId     = (string) ($ctx['outletId'] ?? '');

    apiOk([
        'modelVersion' => $modelVersion,
        'faces'        => $faces->facesForOutlet($companyId, $outletId, $modelVersion),
        // La ventana de registro abierta desde el panel, si le toca a este
        // quiosco. Viaja con los rostros y no por un endpoint aparte porque es
        // la misma pregunta —"¿qué tengo que hacer con la cámara?"— y una sola
        // respuesta no puede quedar desfasada de sí misma.
        'enrollment'   => $faces->pendingEnrollment($companyId, $outletId),
    ]);
}

// ── Registro del rostro desde el quiosco ───────────────────────────────────
//
// Lo AUTORIZA el panel (`employees.php`, acción `face-start`) y lo CAPTURA acá.
// Este endpoint no elige a quién enrola: recibe un `employeeId` y el service lo
// contrasta contra la ventana vigente, que dice a quién y en qué sucursal. Un
// device comprometido no puede darse de alta como nadie.
//
// A diferencia de marcar, esto NO es fail-open y no debe serlo: un registro
// fallido no deja a nadie sin poder fichar —el código sigue estando— y en cambio
// un vector malo reconoce mal todos los días hasta que alguien lo note.
if ($method === 'POST' && $action === 'face-enroll') {
    $requireKiosk();

    $samplesRaw = (string) ($_POST['samples'] ?? '');
    $samples    = json_decode($samplesRaw, true);
    if (!is_array($samples)) {
        apiError('Las capturas no llegaron completas. Volvé a intentar', 422);
    }

    try {
        apiOk(['face' => $faces->enroll(
            $companyId,
            (string) ($_POST['employeeId']   ?? ''),
            $samples,
            (string) ($_POST['modelVersion'] ?? ''),
            (string) ($ctx['outletId']       ?? ''),
            !empty($_FILES['photo']['tmp_name']) ? $_FILES['photo'] : null
        )], 201);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
}

// ── ALTA desde el quiosco ──────────────────────────────────────────────────
if ($method === 'POST' && $action !== 'review') {
    $requireKiosk();

    $opId = trim((string) ($_SERVER['HTTP_X_PUNTO_OP_ID'] ?? ''));
    if ($opId === '' || strlen($opId) > 64) {
        apiError('Falta el header X-Punto-Op-Id (o es inválido)', 400);
    }

    // La SUCURSAL, la CAJA y el APARATO salen del contexto del dispositivo,
    // NUNCA del body — misma convención que `inventory_count.php`. Acá importa
    // el doble: la marcación es la prueba de que alguien estuvo EN un lugar, y
    // dejar que el cliente nombre ese lugar la vacía de contenido.
    try {
        $result = $svc->mark(
            $companyId,
            [
                'opId'          => $opId,
                'employeeId'    => (string) ($_POST['employeeId']    ?? ''),
                'markPinHash'   => (string) ($_POST['markPinHash']   ?? ''),
                'kind'          => (string) ($_POST['kind']          ?? ''),
                'markedAt'      => (string) ($_POST['markedAt']      ?? ''),
                'method'        => (string) ($_POST['method']        ?? 'pin'),
                'noPhotoReason' => (string) ($_POST['noPhotoReason'] ?? ''),
                // Qué pasó con la cámara desde el punto de vista del
                // reconocimiento (F2). Solo puede AGREGAR un motivo de revisión;
                // nunca rechaza la marcación.
                'faceOutcome'   => (string) ($_POST['faceOutcome']   ?? 'none'),
                'outletId'      => (string) ($ctx['outletId']   ?? ''),
                'registerId'    => (string) ($ctx['registerId'] ?? ''),
                'deviceId'      => (string) ($ctx['deviceId']   ?? ''),
            ],
            !empty($_FILES['photo']['tmp_name']) ? $_FILES['photo'] : null
        );
    } catch (\RuntimeException $e) {
        // 422: el cliente mandó algo que no describe un hecho registrable. NO
        // es transitorio, así que la cola del POS lo deja visible en vez de
        // martillar el servidor con el mismo payload.
        apiError($e->getMessage(), 422);
    }

    apiOk([
        'mark' => $result['mark'],
        // Que el reenvío haya encontrado su propia fila no es un error y no se
        // reporta como tal: para la cola es un éxito, que es exactamente lo que
        // la idempotencia promete.
        'duplicate' => $result['duplicate'],
    ], $result['duplicate'] ? 200 : 201);
}

// ── Revisión de una marcación flageada ─────────────────────────────────────
if ($method === 'POST' && $action === 'review') {
    $requirePanel();
    OperatorContext::requirePermission($ctx, 'hr.attendance.review');
    if ($id === null) {
        apiError('id es requerido', 422);
    }
    try {
        apiOk(['mark' => $svc->review((string) $id, $companyId, (string) ($ctx['userId'] ?? ''))]);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
}

// ── La foto de una marcación ───────────────────────────────────────────────
//
// Va antes del reporte porque no responde el envelope JSON: devuelve el
// archivo. El objeto es PRIVADO en S3, así que este endpoint es el único
// camino — y por eso chequea tenant y permiso antes de tocar el bucket.
if ($method === 'GET' && $resource === 'photo') {
    $requirePanel();
    OperatorContext::requirePermission($ctx, 'hr.attendance.view');
    if ($id === null) {
        apiError('id es requerido', 422);
    }
    $body = $svc->photo((string) $id, $companyId);
    if ($body === null) {
        apiError('Esa marcación no tiene foto disponible', 404);
    }

    http_response_code(200);
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($body));
    // Dato personal: que no quede en ningún caché intermedio.
    header('Cache-Control: private, no-store');
    echo $body;
    exit;
}

// ── El reporte ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $requirePanel();
    OperatorContext::requirePermission($ctx, 'hr.attendance.view');

    // Una fecha sola en `to` significa el FINAL de ese día (ver
    // `Date::reportRange`) — sin eso, pedir hasta hoy perdía todo lo marcado
    // hoy después de medianoche.
    [$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
    if (!$rangeOk) {
        apiError('Formato de fecha inválido', 422);
    }

    apiOk($svc->report($companyId, $from, $to, [
        'employeeId'  => $_GET['employeeId'] ?? null,
        'outletId'    => $_GET['outletId']   ?? null,
        'needsReview' => ($_GET['needsReview'] ?? '') === '1',
    ]));
}

apiError('Method not allowed', 405);
