<?php
declare(strict_types=1);

namespace Punto\Api\Context;

/**
 * Identidad de la request autenticada. Inmutable.
 *
 * Reemplaza el dual-source de identidad que arrastra el legacy: las constantes
 * globales COMPANY_ID / OUTLET_ID / USER_ID / REGISTER_ID que define `data.php`
 * NO deben filtrarse a la lógica de negocio nueva — los servicios reciben este
 * objeto por DI y leen los ids de acá.
 *
 * Mientras coexistan con código legacy (manageStock, sendAuditoria, etc.) que
 * lee las constantes globales, ambos deben estar set y representar el mismo
 * tenant — el bootstrap garantiza esa coherencia.
 */
final class TenantContext
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $outletId,
        public readonly string $userId,
        public readonly string $registerId,
        public readonly string $roleId,
        /** Vacío salvo realm pos-app (ver apiAuthTenant()) — "qué device es
         *  este request", ej. para que DrawerService::close() libere su
         *  propia tenencia de register_lease al cerrar caja (context/29 §4.4). */
        public readonly string $deviceId = '',
    ) {
        if ($companyId === '' || $outletId === '' || $userId === '') {
            throw new \InvalidArgumentException('TenantContext: companyId/outletId/userId no pueden ser vacíos');
        }
    }

    /** Crea desde el array que devuelve apiAuthTenant() — útil en endpoints. */
    public static function fromAuth(array $ctx): self
    {
        return new self(
            companyId:  (string) ($ctx['companyId']  ?? ''),
            outletId:   (string) ($ctx['outletId']   ?? ''),
            userId:     (string) ($ctx['userId']     ?? ''),
            registerId: (string) ($ctx['registerId'] ?? ''),
            roleId:     (string) ($ctx['roleId']     ?? ''),
            deviceId:   (string) ($ctx['deviceId']   ?? ''),
        );
    }
}
