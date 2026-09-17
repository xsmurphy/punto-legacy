<?php
/**
 * REST canónico — Legajo de empleados (RRHH F0, context/83 §3 y §7).
 *
 *   GET    /v1/employees                                  → { employees: [...] }
 *          filtros: q, state=active|terminated|all, outletId, includeArchived=1
 *   GET    /v1/employees?id=<uuid>                        → detalle
 *   GET    /v1/employees?id=<uuid>&resource=attachments   → { attachments: [...] }
 *   GET    /v1/employees?resource=attachment&attachmentId=<uuid>
 *                                                         → el archivo (binario)
 *   POST   /v1/employees                                  → crea (201)
 *   POST   /v1/employees?id=<uuid>&action=terminate       → EGRESO (fecha + motivo)
 *   POST   /v1/employees?id=<uuid>&resource=attachments   → sube un adjunto (multipart)
 *   PUT    /v1/employees?id=<uuid>                        → update parcial
 *   DELETE /v1/employees?id=<uuid>                        → archiva la fila
 *   DELETE /v1/employees?id=<uuid>&resource=attachments&attachmentId=<uuid>
 *
 *   POST   /v1/employees?id=<uuid>&action=face-start      → habilita al quiosco a
 *                                                           registrar SU rostro (TTL corto)
 *   POST   /v1/employees?id=<uuid>&action=face-cancel     → cierra esa habilitación
 *   DELETE /v1/employees?id=<uuid>&resource=face          → borra el rostro registrado
 *   GET    /v1/employees?id=<uuid>&resource=face-photo    → la foto de registro (binario)
 *
 * ── Por qué el rostro se GOBIERNA desde acá y se CAPTURA en el quiosco ──────
 *
 * El registro del rostro está partido entre dos realms a propósito (RRHH F2,
 * context/83 D5). Habilitarlo es un acto sobre el LEGAJO —lo hace alguien con
 * `hr.employees.manage`, desde la ficha de una persona concreta— y por eso vive
 * en este archivo, con el mismo gate que el resto del legajo. Capturar es un
 * acto del QUIOSCO, y vive en `attendance.php` con el Bearer del device.
 *
 * Lo que compra esa partición es lo único que hace que el reconocimiento facial
 * no reconstruya el problema que vino a resolver: **el quiosco nunca elige a
 * quién enrola**. Si el que sabe un PIN pudiera registrar su cara bajo el nombre
 * de otro, tendríamos el mismo préstamo de identidad de siempre, pero avalado
 * por la cara todos los días siguientes.
 *
 * ── Realm: `panel` y solo `panel` ───────────────────────────────────────────
 *
 * Ni `pos-app` ni `api`. El legajo tiene sueldos, documentos de identidad y
 * contratos escaneados: el token de la caja es del DISPOSITIVO y es eterno
 * (se emite al parear, mucho antes de que haya un operador), así que una
 * tablet extraviada no puede ser una puerta a los datos personales del
 * equipo. Y una API key es una credencial de integración: exponerle el legajo
 * sería sacar del comercio justo lo que más cuesta explicar si se filtra.
 *
 * ── Permisos ────────────────────────────────────────────────────────────────
 *
 * `hr.employees.view` para leer, `hr.employees.manage` para escribir. Familia
 * propia y no `contacts.user.*`: ver el comentario en `PermissionCatalog`.
 *
 * ── Dos bajas distintas ─────────────────────────────────────────────────────
 *
 * `action=terminate` es el EGRESO (escribe la fecha, el legajo queda como
 * historial). `DELETE` archiva la fila, que es otra cosa: el legajo cargado
 * por error. No están en el mismo verbo a propósito — que dar de baja a
 * alguien y borrar un error tipeen igual es cómo se pierde un historial.
 *
 * Auditoría y realtime: automáticos. Toda mutación bajo `/v1/` que pase por
 * `apiAuthTenant()` se audita con el actor resuelto y publica su evento de
 * invalidación (`api/bootstrap.php`, §52). Acá no se registra nada a mano.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = (string) $ctx['companyId'];
$userId    = (string) ($ctx['userId'] ?? '');

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id']       ?? null;
$resource = $_GET['resource'] ?? null;
$action   = $_GET['action']   ?? ($_POST['action'] ?? null);

/**
 * El cliente S3 del legajo. Uno solo para los tres servicios que archivan cosas
 * de un empleado: adjuntos, fotos de marcación y foto de registro del rostro.
 */
$storage = static function (): \Punto\Api\Storage\S3Client {
    return new \Punto\Api\Storage\S3Client(
        S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET, S3_KEY_PREFIX
    );
};

/**
 * El service del rostro se le INYECTA al del legajo, y no es un detalle: es lo
 * que hace que el egreso borre la biometría (D5). Sin esta línea, dar de baja a
 * alguien dejaría su cara viva en la base y en el bucket.
 */
$faces = new \Punto\Api\Hr\EmployeeFaceService($storage());
$svc   = new \Punto\Api\Hr\EmployeeService($faces);

/** Gate de lectura. */
$requireView = static function (): void {
    if (!hasPermission('hr.employees.view')) {
        apiError('No tenés permiso para esta acción (requiere: hr.employees.view)', 403);
    }
};

/** Gate de escritura. */
$requireManage = static function (): void {
    if (!hasPermission('hr.employees.manage')) {
        apiError('No tenés permiso para esta acción (requiere: hr.employees.manage)', 403);
    }
};

/** Adjuntos: el service necesita el cliente S3, que se arma igual que en el resto del proyecto. */
$attachments = static function () use ($storage): \Punto\Api\Hr\EmployeeAttachmentService {
    return new \Punto\Api\Hr\EmployeeAttachmentService($storage());
};

/**
 * Avisa a los quioscos que algo del rostro cambió.
 *
 * El publish automático de `apiAuthTenant()` corre AL AUTENTICAR, o sea ANTES
 * de que este handler escriba: un dispositivo que reaccione a ese primer evento
 * lee el estado viejo. Por eso se publica otra vez, explícitamente, DESPUÉS del
 * write — mismo criterio que documenta `/v1/register-lease` en `bootstrap.php`.
 *
 * Dos eventos de invalidación por la misma operación no molestan: invalidar es
 * idempotente. Lo que no se puede es no tener el segundo.
 *
 * El canal es del COMERCIO entero, no de una sucursal (no existe canal por
 * sucursal). Cada quiosco vuelve a preguntar y el servidor le contesta lo que
 * le corresponde a SU sucursal — el alcance lo resuelve la lectura, no el
 * transporte.
 */
$publishFaceChange = static function (string $employeeId) use ($companyId): void {
    realtimePublish('employee', 'update', $employeeId, 'all', $companyId);
};

// ── Descarga de un adjunto ─────────────────────────────────────────────────
//
// Va ANTES del resto porque no responde el envelope JSON: devuelve el archivo.
// Los objetos son privados en S3 (ver EmployeeAttachmentService), así que este
// endpoint es el único camino y por eso chequea permiso y tenant antes de
// tocar el bucket.
if ($resource === 'attachment') {
    if ($method !== 'GET') {
        apiError('Method not allowed', 405);
    }
    $requireView();
    $attachmentId = trim((string) ($_GET['attachmentId'] ?? ''));
    if ($attachmentId === '') {
        apiError('Falta attachmentId', 422);
    }
    try {
        $file = $attachments()->download($attachmentId, $companyId);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    if ($file === null) {
        apiError('Adjunto no encontrado', 404);
    }

    http_response_code(200);
    header('Content-Type: ' . $file['mime']);
    header('Content-Length: ' . strlen($file['body']));
    // `attachment`: es documentación del legajo, se baja. El nombre ya viene
    // saneado del service (viaja en esta cabecera).
    header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
    // Documento personal: que no quede en ningún caché intermedio.
    header('Cache-Control: private, no-store');
    echo $file['body'];
    exit;
}

// ── La foto con la que se registró el rostro ───────────────────────────────
//
// Va acá arriba por lo mismo que la de los adjuntos: devuelve el archivo, no el
// envelope JSON. Existe para que quien revisa una marcación flageada pueda ver
// contra QUIÉN se estaba comparando — sin eso, "no reconoció" es una afirmación
// que nadie puede auditar.
if ($resource === 'face-photo') {
    if ($method !== 'GET') {
        apiError('Method not allowed', 405);
    }
    $requireView();
    if ($id === null) {
        apiError('id es requerido', 422);
    }
    $body = $faces->photo($companyId, (string) $id);
    if ($body === null) {
        apiError('Esta persona no tiene una foto registrada', 404);
    }

    http_response_code(200);
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($body));
    // Dato personal: que no quede en ningún caché intermedio.
    header('Cache-Control: private, no-store');
    echo $body;
    exit;
}

// ── Sub-recurso: el rostro registrado ──────────────────────────────────────
//
// Solo se BORRA desde el panel. No hay PUT ni POST del vector acá: el rostro lo
// captura el quiosco (`attendance.php`), y aceptar un vector por esta puerta
// sería permitir que alguien escriba la cara de otro sin pasar por la cámara.
if ($id !== null && $resource === 'face') {
    if ($method !== 'DELETE') {
        apiError('Method not allowed for /employees/face', 405);
    }
    $requireManage();
    $faces->deleteFor($companyId, (string) $id);
    $publishFaceChange((string) $id);
    apiOk(['deleted' => true, 'id' => $id]);
}

// ── Sub-recurso: adjuntos del legajo ───────────────────────────────────────
if ($id !== null && $resource === 'attachments') {
    $svcAtt = $attachments();

    if ($method === 'GET') {
        $requireView();
        apiOk(['attachments' => $svcAtt->listFor((string) $id, $companyId)]);
    }

    if ($method === 'POST') {
        $requireManage();
        if (empty($_FILES['file']['tmp_name'])) {
            apiError('Falta el archivo', 422);
        }
        try {
            $att = $svcAtt->upload(
                (string) $id,
                $companyId,
                $_FILES['file'],
                isset($_POST['label']) ? (string) $_POST['label'] : null,
                $userId
            );
            apiOk(['attachment' => $att], 201);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
    }

    if ($method === 'DELETE') {
        $requireManage();
        $attachmentId = trim((string) ($_GET['attachmentId'] ?? ''));
        if ($attachmentId === '') {
            apiError('Falta attachmentId', 422);
        }
        try {
            $svcAtt->delete($attachmentId, $companyId);
            apiOk(['deleted' => true, 'id' => $attachmentId]);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
    }

    apiError('Method not allowed for /employees/attachments', 405);
}

// ── Recurso principal ───────────────────────────────────────────────────────
switch ($method) {
    case 'GET':
        $requireView();

        if ($id !== null) {
            $employee = $svc->find((string) $id, $companyId);
            if ($employee === null) {
                apiError('Empleado no encontrado', 404);
            }
            apiOk($employee);
        }

        $state = (string) ($_GET['state'] ?? 'all');
        if (!in_array($state, \Punto\Api\Hr\EmployeeService::STATES, true)) {
            $state = 'all';
        }
        apiOk(['employees' => $svc->list($companyId, [
            'q'               => $_GET['q']        ?? null,
            'state'           => $state,
            'outletId'        => $_GET['outletId'] ?? null,
            'includeArchived' => ($_GET['includeArchived'] ?? '') === '1',
        ])]);
        break;

    case 'POST':
        $requireManage();

        // EGRESO. Acción explícita y no un PUT con `endDate`: registrar la
        // salida de alguien no se hace de costado en una edición de campos.
        if ($action === 'terminate') {
            if ($id === null) {
                apiError('id es requerido', 422);
            }
            try {
                $terminated = $svc->terminate(
                    (string) $id,
                    $companyId,
                    isset($_POST['endDate']) ? (string) $_POST['endDate'] : null,
                    isset($_POST['endReason']) ? (string) $_POST['endReason'] : null,
                    $userId
                );
                // El egreso acaba de borrar la biometría: los quioscos tienen
                // que soltar la copia que cachearon, y cuanto antes.
                $publishFaceChange((string) $id);
                apiOk($terminated);
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
        }

        // ── Registro del rostro: habilitar y cancelar ──
        //
        // Habilitar no guarda ninguna cara: abre una ventana corta para que el
        // quiosco de la sucursal de esa persona la pueda capturar. Es un
        // PERMISO, y por eso vence solo — una ventana olvidada deja de ofrecerse
        // sin que nadie tenga que acordarse de cerrarla.
        if ($action === 'face-start' || $action === 'face-cancel') {
            if ($id === null) {
                apiError('id es requerido', 422);
            }
            try {
                if ($action === 'face-cancel') {
                    $faces->cancelEnrollment($companyId, (string) $id);
                    $publishFaceChange((string) $id);
                    apiOk(['enrollment' => null]);
                }
                $enrollment = $faces->openEnrollment($companyId, (string) $id, $userId);
                // DESPUÉS del write: ver el docblock de `$publishFaceChange`.
                // Es lo que hace que el quiosco se entere sin que nadie lo toque.
                $publishFaceChange((string) $id);
                apiOk(['enrollment' => $enrollment]);
            } catch (\RuntimeException $e) {
                apiError($e->getMessage(), 422);
            }
        }

        $payload = $_POST;
        // Igual que en el PUT: quién registra el consentimiento biométrico sale
        // de la sesión, nunca del cuerpo de la request.
        $payload['actorId'] = $userId;
        try {
            $employee = $svc->create($companyId, $payload, $userId);
            apiOk($employee, 201);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        $requireManage();
        if ($id === null) {
            apiError('id es requerido para PUT', 422);
        }
        $patch = $_POST;
        // El tenant y la identidad de la fila NUNCA salen del payload.
        unset($patch['id'], $patch['employeeId'], $patch['companyId'], $patch['status'], $patch['actorId']);
        // Quién registra el consentimiento biométrico lo decide la sesión, no
        // el cuerpo de la request.
        $patch['actorId'] = $userId;
        if (count($patch) <= 1) {
            apiError('Patch vacío', 422);
        }
        try {
            $updated = $svc->update((string) $id, $companyId, $patch, $userId);
            // Un cambio en el legajo mueve lo que el quiosco tiene cacheado: el
            // PIN de marcación, y —si se retiró el consentimiento— el rostro,
            // que el service acaba de borrar. Ver `$publishFaceChange`.
            $publishFaceChange((string) $id);
            apiOk($updated);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'DELETE':
        $requireManage();
        if ($id === null) {
            apiError('id es requerido para DELETE', 422);
        }
        try {
            $svc->archive((string) $id, $companyId);
            $publishFaceChange((string) $id);
            apiOk(['archived' => true, 'id' => $id]);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}
