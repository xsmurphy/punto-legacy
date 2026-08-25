<?php
declare(strict_types=1);

namespace Punto\Api\Contacts;

/**
 * Lookup de un RUC contra el padrón de contribuyentes (F3 de facturación
 * electrónica, context/28-facturacion-electronica-plan.md).
 *
 * Por qué en el backend: la consulta la hacía el navegador del cajero contra
 * `turuc.com.py` (fetch directo en `components/register/customer-dialog.tsx`).
 * Eso ataba el alta de clientes a que el navegador pudiera hablar con un
 * tercero, dejaba un dominio hardcodeado en el frontend y no era reusable
 * desde el panel. Acá el servidor consulta, normaliza y devuelve un shape
 * estable — el frontend no sabe de dónde salieron los datos.
 *
 * Dos fuentes, en orden:
 *
 *   1. **Factomate** (`GET /api/Client/getbyruc/{ruc}`) si el comercio tiene
 *      cuenta de facturación electrónica conectada. Es la autoritativa: es el
 *      padrón que ve el propio emisor, así que si difiere del público, gana
 *      esta — es la que va a validar SIFEN al emitir.
 *   2. **Padrón público** (`TAXPAYER_LOOKUP_URL`) para todo comercio sin FE
 *      conectada, o cuando Factomate no encuentra el RUC. Solo se consulta si
 *      el comercio es del MISMO país que ese padrón — un padrón nacional no
 *      sabe nada de los contribuyentes de otro país.
 *
 * Nunca lanza por un fallo de la fuente: un padrón caído no puede impedir dar
 * de alta un cliente a mano. Devuelve `null` = "no se encontró", y el caller
 * decide (el frontend muestra "RUC no encontrado" y sigue).
 */
final class TaxpayerLookupService
{
    private const CONNECT_TIMEOUT = 5;
    private const TOTAL_TIMEOUT   = 10;

    /**
     * @return array{ruc:string,name:string,status:?string,source:string}|null
     */
    public function lookup(string $companyId, string $ruc): ?array
    {
        $ruc = trim($ruc);
        if ($ruc === '') {
            return null;
        }

        $fromProvider = $this->fromFactomate($companyId, $ruc);
        if ($fromProvider !== null) {
            return $fromProvider;
        }

        return $this->fromPublicRegistry($companyId, $ruc);
    }

    /**
     * @return array{ruc:string,name:string,status:?string,source:string}|null
     */
    private function fromFactomate(string $companyId, string $ruc): ?array
    {
        try {
            $raw = (new \Punto\Api\EInvoice\EInvoiceService())->clientByRuc($companyId, $ruc);
        } catch (\Throwable $e) {
            // Cuenta sin conectar, credencial vencida, endpoint que no existe:
            // todo cae al padrón público. Se loguea porque un 404 sistemático
            // acá significa que la ruta de la guía está mal (sigue SIN
            // VERIFICAR contra la API real — ver FactomateProvider::clientByRuc).
            error_log('[TaxpayerLookup] factomate: ' . $e->getMessage());
            return null;
        }

        // Shape sin verificar: se desenvuelve el contenedor si viene y se
        // prueban los casings plausibles, igual criterio que
        // EInvoiceService::normalizePaymentMethods.
        $row = $raw;
        foreach (['Item', 'item', 'Data', 'data', 'Result', 'result', 'Client', 'client'] as $wrapper) {
            if (isset($raw[$wrapper]) && is_array($raw[$wrapper])) {
                $row = $raw[$wrapper];
                break;
            }
        }
        if (isset($row[0]) && is_array($row[0])) {
            $row = $row[0];
        }

        $name = $this->firstString($row, ['BusinessName', 'businessName', 'RazonSocial', 'razonSocial', 'Name', 'name', 'FantasyName', 'fantasyName']);
        if ($name === null) {
            return null;
        }

        return [
            'ruc'    => $this->firstString($row, ['Ruc', 'ruc', 'RUC']) ?? $ruc,
            'name'   => $name,
            'status' => $this->firstString($row, ['Status', 'status', 'Estado', 'estado']),
            'source' => 'factomate',
        ];
    }

    /**
     * Padrón público. El endpoint espera el número SIN dígito verificador
     * (`7659394`, no `7659394-0`) y devuelve el RUC completo con DV — por eso
     * se manda el documento pelado y se toma el `ruc` de la respuesta.
     *
     * @return array{ruc:string,name:string,status:?string,source:string}|null
     */
    private function fromPublicRegistry(string $companyId, string $ruc): ?array
    {
        // El padrón sale del PAÍS del comercio, no de una constante global: es
        // un servicio por país, y el de Paraguay no sabe nada de un RUC
        // chileno. Antes la URL estaba cableada en `simple.config.php`, así que
        // el identificador tributario del cliente de un comercio extranjero se
        // mandaba igual al padrón paraguayo — consulta inútil y una fuga de
        // dato tributario hacia un servicio de otro país.
        $tenantCountry = \Punto\Api\Support\TenantLocale::country($companyId);
        if ($tenantCountry === null) {
            return null;
        }

        // Override de despliegue: si el entorno define un padrón, manda sobre
        // el del catálogo, pero SOLO para el país que ese padrón atiende
        // (mismo gate que TinService con Marangatu). Sin `TAXPAYER_LOOKUP_URL`
        // seteada no pasa nada: se usa el del catálogo.
        $envUrl     = defined('TAXPAYER_LOOKUP_URL') ? trim((string) TAXPAYER_LOOKUP_URL) : '';
        $envCountry = defined('TAXPAYER_LOOKUP_COUNTRY') ? trim((string) TAXPAYER_LOOKUP_COUNTRY) : '';
        if ($envUrl !== '' && $envCountry !== '') {
            $baseUrl = $envCountry === $tenantCountry ? $envUrl : '';
        } else {
            $baseUrl = (string) (\Punto\Api\Support\CountryDefaults::taxpayerRegistryUrl($tenantCountry) ?? '');
        }

        if ($baseUrl === '') {
            // Degradación VISIBLE: el lookup por padrón no existe para este
            // país, pero la función no muere en silencio — el caller sigue con
            // la fuente del proveedor de facturación electrónica y acá queda
            // registrado por qué no hubo consulta pública.
            error_log(sprintf(
                '[TaxpayerLookup] sin padrón público para el país "%s" (company %s); se usa solo la fuente de FE',
                $tenantCountry,
                $companyId
            ));
            return null;
        }

        $doc = preg_replace('/\D/', '', explode('-', $ruc)[0]) ?? '';
        if ($doc === '') {
            return null;
        }

        $ch = curl_init(rtrim($baseUrl, '/') . '/' . rawurlencode($doc));
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            if ($err !== '') {
                error_log("[TaxpayerLookup] padrón: $err");
            }
            return null;
        }

        $json = json_decode($body, true);
        $row  = is_array($json) && is_array($json['data'] ?? null) ? $json['data'] : (is_array($json) ? $json : []);

        $name = $this->firstString($row, ['razonSocial', 'RazonSocial', 'nombre', 'name']);
        if ($name === null) {
            return null;
        }

        return [
            'ruc'    => $this->firstString($row, ['ruc', 'RUC']) ?? $ruc,
            'name'   => $name,
            'status' => $this->firstString($row, ['estado', 'Estado', 'status']),
            'source' => 'padron',
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     */
    private function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') {
                return trim($row[$key]);
            }
        }
        return null;
    }
}
