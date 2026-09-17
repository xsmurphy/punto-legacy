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

$svc = new \Punto\Api\Hr\EmployeeService();

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
$attachments = static function (): \Punto\Api\Hr\EmployeeAttachmentService {
    return new \Punto\Api\Hr\EmployeeAttachmentService(
        new \Punto\Api\Storage\S3Client(S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET, S3_KEY_PREFIX)
    );
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
                apiOk($svc->terminate(
                    (string) $id,
                    $companyId,
                    isset($_POST['endDate']) ? (string) $_POST['endDate'] : null,
                    isset($_POST['endReason']) ? (string) $_POST['endReason'] : null,
                    $userId
                ));
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
            apiOk($svc->update((string) $id, $companyId, $patch, $userId));
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
            apiOk(['archived' => true, 'id' => $id]);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}
