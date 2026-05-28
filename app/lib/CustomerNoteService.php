<?php
/**
 * CustomerNoteService — notas de cliente del POS (slice 5 del desacople de /app).
 *
 * Portado de app/action.php (customerNote 896): inserta una fila en `contactNote`.
 * $db->Insert parametrizado, scopeado por companyId (tenant). El customerId viene
 * del request; la identidad (companyId) del JWT en la API.
 */

class CustomerNoteService
{
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
