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
 * poder cambiar de proveedor sin tocar EInvoiceService. El camino legacy
 * (sendFE/consultFE, ElectronicInvoiceService, /v1/electronic_invoice.php)
 * se retiró entero en F4 — esta interfaz es el único acceso a un proveedor
 * de facturación electrónica.
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
     * GET /api/electronicDocument/getBulk/{id} (F2) — ÚNICA fuente real de
     * reconciliación del estado FISCAL. Verificado contra la API real
     * (2026-07-30): `GET /api/ElectronicDocument/GetAll` devuelve
     * `Items: []` incluso después de emitir — es un no-op silencioso, no
     * sirve para esto. `$bulkId` es el `Id` raíz que devolvió
     * `POST /Bulk` al emitir (persistido en `einvoice_document.provider_number`).
     *
     * CRÍTICO: un CDC con `Success: true` en la respuesta de `/Bulk` NO
     * significa que SIFEN aceptó el documento — se comprobó hoy con un CDC
     * válido que terminó `Rechazado` (código 1002, duplicado). El KuDE se
     * descarga igual para un documento rechazado. El único campo que dice
     * si la factura vale es `sifen_status`, derivado de esta respuesta
     * (ver EInvoiceService::reconcile()).
     * @throws \RuntimeException
     */
    public function getBulk(string $environment, string $phone, string $bearer, string $bulkId): array;

    // ── F7 — provisioning white-label ────────────────────────────────────
    // El comercio nunca ve Factomate: Punto crea el emisor con su credencial
    // ADMIN (env, ver FactomateSession::getAdminBearer) y de ahí en adelante
    // opera con el usuario del tenant que devolvió el alta. Manual de
    // referencia: ~/Downloads/manual-tenant-abm (§2 CreateExternal, §3 PUT
    // Tenant, §4 Activity, §5 BranchDocumentType, §7 UploadCert).

    /**
     * POST /api/Tenant/CreateExternal — alta compuesta (usuario + tenant +
     * rol + vínculo). Requiere un bearer de usuario SIN tenant (el admin
     * global de Punto). Devuelve
     * ['tenantId','userId','email','password','raw'] — la contraseña viaja
     * UNA sola vez y no es recuperable después (manual §2.4): el caller la
     * persiste en el vault como paso inmediato siguiente.
     * @param array{razonSocial:string,nombreFantasia:?string,email:string,ruc:string} $data
     * @throws \RuntimeException
     */
    public function createExternal(string $environment, string $adminLogin, string $adminBearer, array $data): array;

    /**
     * PUT /api/Tenant — datos fiscales del emisor (manual §3). Con el bearer
     * del USUARIO del tenant (su rol Administrador alcanza).
     * @throws \RuntimeException
     */
    public function updateTenant(string $environment, string $phone, string $bearer, array $tenant): array;

    /**
     * POST /api/Activity — actividad económica SIFEN del emisor (manual §4).
     * @throws \RuntimeException
     */
    public function createActivity(string $environment, string $phone, string $bearer, int $tenantId, int $identifier, string $name): array;

    /**
     * POST /api/BranchDocumentType — alta del timbrado (manual §5).
     * `TenantId` NO viaja: Factomate lo fuerza server-side al del usuario
     * autenticado, por eso este método exige el bearer del tenant.
     * @throws \RuntimeException
     */
    public function createStamp(string $environment, string $phone, string $bearer, array $stamp): array;

    /**
     * POST /api/Tenant/{id}/UploadCert — certificado de firma (manual §7).
     * SOLO el usuario propio del tenant puede subirlo (§7.2 — el admin
     * global recibe 403), por eso va con el bearer del tenant. El caller
     * NUNCA persiste ni loguea `certBase64`/`certPassword` (regla del plan).
     * @throws \RuntimeException
     */
    public function uploadCert(string $environment, string $phone, string $bearer, int $tenantId, string $certBase64, string $certPassword): array;

    /**
     * GET /api/Consulta/Get?tenantId=&description= — prueba de humo REAL del
     * certificado: consulta de RUC contra SIFEN usando el certificado del
     * emisor como cert cliente TLS (manual §7.5). 200 = certificado y CSC
     * operativos; error = "Error de Certificado o CSC".
     * @throws \RuntimeException
     */
    public function testSet(string $environment, string $phone, string $bearer, int $tenantId, string $ruc): array;
}
