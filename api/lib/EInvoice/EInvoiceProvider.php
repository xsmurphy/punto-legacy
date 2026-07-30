<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Contrato de un proveedor de facturación electrónica.
 *
 * Reescrita en el pivot Automate → Factomate (2026-07-28, ver
 * context/28-facturacion-electronica-plan.md): Automate no era el motor
 * real, era otro cliente de Factomate igual que Punto va a serlo. El login
 * de una sola llamada que tenía esta interfaz no aplica — Factomate
 * autentica en DOS pasos encadenados (Token → PhoneLogin, ver
 * FactomateProvider/FactomateSession) y exige el header `phonenumber` en
 * TODAS las llamadas autenticadas, por eso cada método lo recibe explícito
 * en vez de asumirlo cacheado dentro del cliente.
 *
 * `$environment` ('test'|'prod') también va explícito en cada llamada, no
 * es estado del provider: EInvoiceService construye una sola instancia de
 * FactomateProvider reusada entre companies dentro del mismo
 * request/worker — si el environment viviera como propiedad del objeto,
 * una company en 'prod' podría terminar heredando el environment que dejó
 * seteado la company anterior en el mismo request. Sin estado, no hay
 * fuga posible.
 *
 * Sigue detrás de una interfaz (mismo criterio que los proveedores de pago
 * en api/lib/Billing/Payments/) para no casar el módulo a Factomate y
 * poder retirar el camino legacy (sendFE/consultFE,
 * ElectronicInvoiceService) entero en F4 sin tocar EInvoiceService.
 *
 * F0 sólo implementa token/phoneLogin/userInfo/sincroConfig/paymentMethods
 * (conexión de cuenta + lectura del timbrado). Las firmas de F1/F2/F3
 * (issue/cancel/kude/clientByRuc) quedan declaradas acá para que el
 * contrato quede cerrado de una — evita que F1 tenga que romper la interfaz
 * ya usada por EInvoiceService.
 */
interface EInvoiceProvider
{
    /**
     * POST /Token — usuario+contraseña → bearer de 15 min de UN SOLO USO.
     * Nunca se cachea (ver guía §2): solo sirve para probar que se conoce
     * la contraseña y obtener el bearer que consume phoneLogin().
     * Devuelve ['token' => string, 'expiresAt' => ?string, 'raw' => array].
     * @throws \RuntimeException en credenciales inválidas o error de red.
     */
    public function token(string $environment, string $phone, string $username, string $password): array;

    /**
     * POST /api/account/PhoneLogin — bearer de token() + header phonenumber
     * → bearer de 24 h, que sí se cachea (FactomateSession::getBearer).
     * Devuelve ['token' => string, 'expiresAt' => ?string, 'raw' => array].
     * @throws \RuntimeException
     */
    public function phoneLogin(string $environment, string $phone, string $tokenStep1): array;

    /**
     * GET /api/account/GetUserInfo — datos del emisor autenticado (razón
     * social, RUC, etc.) — payload crudo, sin tipar (el spec no documenta
     * el shape).
     * @throws \RuntimeException
     */
    public function userInfo(string $environment, string $phone, string $bearer): array;

    /**
     * POST /api/sincro/config — trae el timbrado vigente en `stamps[0]`.
     * El timbrado NO se crea por API, solo se lee (se provisiona del lado
     * de Factomate antes de conectar la cuenta).
     * @throws \RuntimeException
     */
    public function sincroConfig(string $environment, string $phone, string $bearer): array;

    /**
     * GET /api/PaymentMethod/get — códigos de medios de pago soportados,
     * para mapear contra los medios de pago de Punto (F3).
     * @throws \RuntimeException
     */
    /**
     * Timbrados del emisor (`GET /api/BranchDocumentType/Get`). Fuente REAL del
     * timbrado: `sincroConfig()` devuelve `stamps: []` aun con timbrado vigente.
     */
    public function stamps(string $environment, string $phone, string $bearer): array;

    public function paymentMethods(string $environment, string $phone, string $bearer): array;

    // ── F1/F2/F3 ─────────────────────────────────────────────────────────
    // Firmas cerradas ahora para que el contrato no cambie después; la
    // implementación en FactomateProvider tira LogicException hasta la fase
    // correspondiente.

    /** @throws \RuntimeException|\LogicException POST /api/electronicDocument/Bulk (F1). */
    public function issue(string $environment, string $phone, string $bearer, array $payload): array;

    /** @throws \RuntimeException|\LogicException POST /api/electronicDocument/event (F1/F2). */
    public function cancel(string $environment, string $phone, string $bearer, string $cdc, string $reason): array;

    /** @throws \RuntimeException|\LogicException GET /api/electronicDocument/getkude/{cdc} (F1/F2). */
    public function kude(string $environment, string $phone, string $bearer, string $cdc): string;

    /** @throws \RuntimeException|\LogicException GET /api/Client/getbyruc/{ruc} (F3). */
    public function clientByRuc(string $environment, string $phone, string $bearer, string $ruc): array;

    /**
     * GET /api/ElectronicDocument/GetAll (F2) — reconciliación del estado
     * FISCAL real: un CDC devuelto por /Bulk no garantiza aceptación
     * definitiva, SIFEN puede rechazar minutos después. SIN VERIFICAR: la
     * guía menciona el endpoint pero no documenta parámetros de filtro ni
     * paginación — se pide sin parámetros y EInvoiceService::reconcile()
     * matchea por CDC en memoria sobre el payload crudo devuelto acá.
     * @throws \RuntimeException
     */
    public function getAllDocuments(string $environment, string $phone, string $bearer): array;
}
