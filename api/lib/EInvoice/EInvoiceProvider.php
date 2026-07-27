<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Contrato de un proveedor de facturación electrónica.
 *
 * Va detrás de una interfaz (mismo criterio que los proveedores de pago en
 * api/lib/Billing/Payments/) para que el módulo no quede casado a Automate
 * y el camino legacy (sendFE/consultFE, ElectronicInvoiceService) se pueda
 * retirar entero en F4 sin tocar EInvoiceService.
 *
 * F0 sólo implementa login/me/paymentMethods (conexión de cuenta). Las
 * firmas de F1 (issue/cancel/pdf/lookupCustomer) quedan declaradas acá
 * para que la interfaz quede cerrada de una — evita que F1 tenga que
 * romper el contrato ya usado por EInvoiceService.
 */
interface EInvoiceProvider
{
    /**
     * Login con usuario/contraseña. Devuelve ['token' => string, 'expiresAt' => ?string, 'raw' => array].
     * @throws \RuntimeException en credenciales inválidas o error de red.
     */
    public function login(string $username, string $password): array;

    /**
     * Datos del emisor autenticado (razón social, RUC, etc.) — payload crudo,
     * sin tipar (el spec de Automate no documenta el shape).
     * @throws \RuntimeException
     */
    public function me(string $token): array;

    /**
     * Códigos de medios de pago soportados por el proveedor, para mapear
     * contra los medios de pago de Punto (F3).
     * @throws \RuntimeException
     */
    public function paymentMethods(string $token): array;

    // ── F1 ───────────────────────────────────────────────────────────────
    // Firmas cerradas ahora para que el contrato no cambie después; la
    // implementación en AutomateProvider tira LogicException hasta F1.

    /** @throws \RuntimeException|\LogicException */
    public function issue(string $token, array $payload): array;

    /** @throws \RuntimeException|\LogicException */
    public function cancel(string $token, string $cdc, string $reason): array;

    /** @throws \RuntimeException|\LogicException */
    public function pdf(string $token, string $cdc): string;

    /** @throws \RuntimeException|\LogicException */
    public function lookupCustomer(string $token, string $taxId): array;
}
