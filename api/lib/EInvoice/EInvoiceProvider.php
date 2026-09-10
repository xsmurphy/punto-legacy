<?php
declare(strict_types=1);

namespace Punto\Api\EInvoice;

/**
 * Contrato de un motor de facturación electrónica.
 *
 * Hoy lo implementa uno solo —`FePyProvider`, el motor de Punto— y sigue
 * siendo una interfaz por el mismo criterio que los proveedores de pago en
 * `api/lib/Billing/Payments/`: `EInvoiceService` habla contra el contrato y no
 * contra una clase concreta, así que sumar o cambiar de motor no obliga a
 * tocar el camino de emisión, que es el que factura.
 *
 * ── Qué está acá y qué NO ────────────────────────────────────────────────
 *
 * Solo la superficie de OPERACIÓN sobre documentos: emitir, cancelar, traer
 * los artefactos (KuDE y XML firmado), reconciliar el estado fiscal y leer los
 * catálogos del emisor. El ALTA del emisor (crear el tenant, subir el
 * certificado, cargar el CSC) NO está acá a propósito: es un flujo con la
 * forma de cada motor —qué recursos crea, en qué orden, con qué credencial— y
 * declararlo en la interfaz obligaba a que el próximo motor calzara en los
 * pasos del anterior. Vive en `EInvoiceProvisioningService`.
 *
 * ── Por qué cada método recibe identidad y entorno ───────────────────────
 *
 * `$tenantRef` y `$environment` van EXPLÍCITOS en cada llamada, no como estado
 * del objeto: `EInvoiceService` construye una sola instancia del motor y la
 * reusa entre companies dentro del mismo request/worker (el drainer del outbox
 * y la reconciliación iteran sobre todos los tenants). Si el entorno viviera
 * como propiedad, una company en 'prod' podría heredar el que dejó seteado la
 * anterior. Sin estado, no hay fuga posible.
 */
interface EInvoiceProvider
{
    /**
     * Datos del emisor tal como los tiene el motor (RUC, razón social,
     * timbrado, entorno). Shape CRUDO: los consumidores lo leen con casing
     * flexible.
     *
     * @throws \RuntimeException
     */
    public function userInfo(string $environment, string $tenantRef, string $bearer): array;

    /**
     * ¿El emisor está en condiciones de emitir? Certificado vigente, CSC
     * cargado, RUC habilitado, numeración disponible.
     *
     * Está en el contrato porque la pregunta es del DOMINIO, no de un motor:
     * el panel tiene que poder decirle al comercio qué le falta ANTES de que
     * SIFEN le rechace una factura. `unverifiable` es lo que ningún motor
     * puede afirmar sin emitir, y se muestra como advertencia.
     *
     * @return array{ready:bool,checks:array<int,array{check:string,ok:bool,detail:string}>,unverifiable:array<int,string>}
     * @throws \RuntimeException
     */
    public function readiness(string $tenantRef, string $bearer): array;

    /**
     * Actualiza los datos ACCESORIOS del emisor — los que cambian con el
     * tiempo y el motor necesita para componer el KuDE (hoy: el logo del
     * comercio).
     *
     * No es el alta ni toca lo que define fiscalmente al emisor (RUC, razón
     * social, timbrado): eso se fija al darlo de alta.
     *
     * @param array<string,mixed> $fields Solo las claves que se quieren pisar.
     * @throws \RuntimeException
     */
    public function patchTenant(string $environment, string $tenantRef, string $bearer, array $fields): array;

    /**
     * Timbrados del emisor.
     *
     * @throws \RuntimeException
     */
    public function stamps(string $environment, string $tenantRef, string $bearer): array;

    /**
     * Códigos de medios de pago que acepta el motor, para mapear contra los
     * medios de pago de Punto.
     *
     * @throws \RuntimeException
     */
    public function paymentMethods(string $environment, string $tenantRef, string $bearer): array;

    /**
     * Emite el documento. El payload ya viene armado por el mapper.
     *
     * @throws \RuntimeException|\LogicException
     */
    public function issue(string $environment, string $tenantRef, string $bearer, array $payload): array;

    /**
     * Evento de cancelación del documento identificado por su CDC.
     *
     * @throws \RuntimeException|\LogicException
     */
    public function cancel(string $environment, string $tenantRef, string $bearer, string $cdc, string $reason): array;

    /**
     * Bytes del KuDE (PDF) del documento, tal como lo renderiza el motor.
     *
     * Punto NO dibuja el KuDE. Se intentó (renderer propio, `context/73`) y se
     * revirtió: un segundo renderer del mismo documento fiscal solo agrega una
     * versión que puede divergir de la que se firmó.
     *
     * @throws \RuntimeException|\LogicException
     */
    public function kude(string $environment, string $tenantRef, string $bearer, string $cdc): string;

    /**
     * Bytes del XML FIRMADO del documento — el documento fiscal de verdad.
     *
     * Está en la interfaz porque su conservación es obligación del EMISOR, o
     * sea de Punto y no del motor: cualquier motor que se sume tiene que poder
     * entregarlo. Se pide POR CDC y no por una URL que venga en la respuesta
     * de emisión, que expira.
     *
     * @throws \RuntimeException|\LogicException
     */
    public function xml(string $environment, string $tenantRef, string $bearer, string $cdc): string;

    /**
     * Consulta del padrón por RUC, para el alta de clientes.
     *
     * @throws \RuntimeException|\LogicException
     */
    public function clientByRuc(string $environment, string $tenantRef, string $bearer, string $ruc): array;

    /**
     * Reconsulta del documento — ÚNICA fuente real del estado FISCAL.
     *
     * CRÍTICO: que la emisión haya devuelto un CDC y `success` NO significa
     * que SIFEN aceptó el documento. Se comprobó con un CDC válido que terminó
     * `Rechazado` (código 1002, duplicado), y el KuDE se descargaba igual. El
     * único campo que dice si la factura vale es `sifen_status`, derivado de
     * esta respuesta (ver `EInvoiceService::reconcile()`).
     *
     * `$documentRef` es la llave con la que ESTE motor reconcilia el documento,
     * persistida en `einvoice_document.provider_number` al emitir.
     *
     * @throws \RuntimeException
     */
    public function getBulk(string $environment, string $tenantRef, string $bearer, string $documentRef): array;
}
