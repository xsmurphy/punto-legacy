<?php

/**
 * /api/v1/admin/help-kb.php — base de conocimiento de Punto AI (realm /admin).
 * Ver context/82-base-de-conocimiento-punto-ai.md R1.
 *
 * Gateado por adminMiddleware(). NO apiMiddleware.
 *
 * GET → { documents, status } — la pantalla muestra las dos cosas juntas, así
 *        que van en una sola llamada (mismo criterio que ai-config.php).
 *        Con ?slug=... devuelve además el texto completo de ese documento,
 *        que es lo que la pantalla necesita para editarlo.
 *
 * POST body {action, ...}:
 *   action=upsertDocument  {slug?, title, body, rubros[], isactive?} → crea o reemplaza + indexa
 *   action=toggleDocument  {slug, isactive}   → lo saca de las búsquedas sin borrarlo
 *   action=deleteDocument  {slug}
 *   action=reindexDocument {slug}
 *   action=reindexAll
 *
 * Cada carga, reemplazo y baja queda en `admin_audit` — igual que el resto de
 * las acciones administrativas. La auditoría vive en la base de los TENANTS
 * (adminAudit usa $db), no en la del RAG: quién tocó qué es historia del
 * realm /admin, no contenido del índice.
 *
 * ⚠ Lo que se carga acá no se publica, pero se lo puede leer cualquier
 * comercio a través del bot (context/82 D3). No es un cajón de notas internas.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';
require_once __DIR__ . '/../../lib/Ai/HelpKbService.php';

use Punto\Api\Ai\HelpEmbedder;
use Punto\Api\Ai\HelpKbService;
use Punto\Api\Ai\HelpKbUnavailable;

adminMiddleware();
adminRequireRole('owner'); // el contenido que el bot le responde a TODOS los tenants — bucket owner-only

$svc    = new HelpKbService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Traduce la caída de la base del RAG a un mensaje accionable, sin nombres de
 * host, variables ni códigos (context/14 §8: nada técnico en pantalla). El
 * detalle va al log, que es donde lo mira quien puede actuar.
 */
function helpKbUnavailable(HelpKbUnavailable $e): never
{
    if ($e->notConfigured) {
        apiError('Todavía no está creada la base de conocimiento.', 503);
    }
    error_log('[help-kb] base de conocimiento no disponible: ' . $e->getMessage());
    apiError('La base de conocimiento no está disponible en este momento. Probá de nuevo en unos minutos.', 503);
}

if ($method === 'GET') {
    // Sin base creada todavía, el GET responde 200 con el índice vacío y
    // `configured=false`. Un 503 acá pintaría la pantalla como rota, y no lo
    // está: es el estado normal hasta que la base exista en Coolify. Lo que la
    // pantalla necesita es poder decirlo, no un error.
    if (!$svc->isConfigured()) {
        apiOk([
            'documents' => [],
            'status'    => [
                'configured'       => false,
                'model'            => $svc->model(),
                'chunks'           => 0,
                'indexedDocuments' => 0,
                'models'           => [],
                'needsReindexAll'  => false,
                'failedDocuments'  => 0,
                'providerReady'    => HelpEmbedder::isConfigured(),
            ],
        ]);
    }

    try {
        $status               = $svc->status();
        $status['configured'] = true;
        $payload = [
            'documents' => $svc->listDocuments(),
            'status'    => $status,
        ];
        // `?slug[]=x` llega como array y un `(string)` sobre eso LANZA.
        $slug = is_string($_GET['slug'] ?? null) ? trim($_GET['slug']) : '';
        if ($slug !== '') {
            $payload['document'] = $svc->getDocument($slug);
        }
        apiOk($payload);
    } catch (HelpKbUnavailable $e) {
        helpKbUnavailable($e);
    }
}

if ($method === 'POST') {
    $body  = (string) file_get_contents('php://input');
    $input = json_decode($body, true);
    if (!is_array($input)) {
        // adminMiddleware() ya pudo haber consumido el body hacia $_POST.
        $input = is_array($_POST) ? $_POST : [];
    }
    if (!$input) {
        apiError('Body JSON inválido', 400);
    }

    /**
     * Lee un campo de texto del body. `is_string` antes de castear porque en
     * PHP 8 un `(string)` sobre un array LANZA, y ese 500 puede arrastrar
     * detalle técnico a la respuesta. Un body malformado sale por el 422 de
     * siempre.
     */
    $str = static fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';

    $action   = $str('action');
    $adminId  = defined('ADMIN_AUTHED_ID') ? (string) ADMIN_AUTHED_ID : null;

    try {
        if ($action === 'upsertDocument') {
            $result = $svc->upsertDocument($input, $adminId);
            if (!$result['ok']) {
                apiError($result['error'] ?? 'error', $result['code'] ?? 422);
            }
            adminAudit(
                $result['created'] ? 'createHelpDoc' : 'replaceHelpDoc',
                'helpDoc',
                $result['slug'] ?? null,
                (string) ($input['title'] ?? ''),
                [
                    'rubros'   => $result['document']['rubros'] ?? [],
                    'isActive' => $result['document']['isActive'] ?? true,
                    'chunks'   => $result['index']['chunks'] ?? 0,
                    'indexOk'  => $result['index']['ok'] ?? false,
                ]
            );
            apiOk($result);
        }

        if ($action === 'toggleDocument') {
            $slug     = $str('slug');
            $isActive = (bool) ($input['isactive'] ?? false);
            $result   = $svc->toggleDocument($slug, $isActive);
            if (!$result['ok']) {
                apiError($result['error'] ?? 'error', $result['code'] ?? 422);
            }
            adminAudit('toggleHelpDoc', 'helpDoc', $result['slug'], null, ['isActive' => $isActive]);
            apiOk($result);
        }

        if ($action === 'deleteDocument') {
            $slug   = $str('slug');
            $result = $svc->deleteDocument($slug);
            if (!$result['ok']) {
                apiError($result['error'] ?? 'error', $result['code'] ?? 422);
            }
            adminAudit('deleteHelpDoc', 'helpDoc', $result['slug'], $result['title'] ?? null);
            apiOk($result);
        }

        if ($action === 'reindexDocument') {
            $slug   = $str('slug');
            $result = $svc->reindexBySlug($slug);
            if (isset($result['code'])) {
                apiError($result['error'] ?? 'error', (int) $result['code']);
            }
            apiOk($result);
        }

        if ($action === 'reindexAll') {
            apiOk($svc->reindexAll());
        }
    } catch (HelpKbUnavailable $e) {
        helpKbUnavailable($e);
    }

    apiError('Acción no soportada', 422);
}

apiError('Método no permitido', 405);
