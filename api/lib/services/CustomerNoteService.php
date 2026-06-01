<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)
/**
 * CustomerNoteService — notas de cliente del POS (slice 5 del desacople de /app).
 *
 * Portado de app/action.php (customerNote 896): inserta una fila en `contactNote`.
 * $db->Insert parametrizado, scopeado por companyId (tenant). El customerId viene
 * del request; la identidad (companyId) del JWT en la API.
 */

final class CustomerNoteService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /** Agrega una nota de texto a un cliente. */
    public function add(string $companyId, string $customerId, string $text): array
    {
        global $db;
        $ok = $db->Insert('contactNote', [
            'contactNoteText' => $text,
            'customerId'      => $customerId,
            'companyId'       => $companyId,
        ]);
        return ['ok' => $ok !== false];
    }
}
