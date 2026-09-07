<?php

declare(strict_types=1);

namespace Punto\Api\EInvoice;

use Punto\Api\Storage\S3Client;
use Punto\Api\Support\TenantLocale;

/**
 * KuDE PROPIO — K1/K2/K3 de `context/73-kude-propio.md`.
 *
 * Punto arma el PDF del KuDE en vez de servir el que renderiza Factomate. El
 * render vive en Next (`/api/kude/render`, @react-pdf/renderer); ACÁ vive todo
 * lo demás: los datos, el caché y —sobre todo— la decisión.
 *
 * ── La cadena, en un solo lugar ─────────────────────────────────────────────
 *
 *   caché S3 → render propio (+ cachear) → fallback a `getkude` de Factomate
 *
 * El fallback es la razón por la que esto puede salir a producción el día que
 * se escribe: si el render propio falla por CUALQUIER motivo (Next caído, env
 * sin configurar, un dato del documento que no esperábamos, un timeout), el
 * comprador igual recibe SU factura — la de siempre. Nunca al revés: nunca se
 * sirve el de Factomate si el propio salió bien, y el fallo queda en
 * `error_log` para que la paridad (K4) se mida con datos reales y no con fe.
 *
 * ── Qué NO decide esta clase ────────────────────────────────────────────────
 *
 * El gate fiscal. Quién puede ver un KuDE (documento emitido y aprobado por
 * SIFEN) lo sigue decidiendo `EInvoiceService::portalKude()`/`sendKude()`.
 * Acá se llega con el permiso ya resuelto.
 *
 * ── Los datos NO se inventan ────────────────────────────────────────────────
 *
 * El contenido sale de lo que se emitió: `request_payload` es el documento que
 * se le mandó a Factomate (mismos ítems, mismos totales que fueron al XML
 * firmado) y `provider_response` trae el CDC y el `DCarQR` (campo J002 — la
 * cadena del QR fiscal, MT §13.8). El QR se genera DESDE esa cadena; un QR
 * armado por nosotros con otra URL sería un KuDE inválido (`context/73`,
 * arquitecturas rechazadas). Lo que falta —porque no lo tenemos— viaja como
 * `null` y el template lo omite: preferimos un campo ausente a uno inventado.
 */
final class KudeService
{
    /**
     * Versión del TEMPLATE, no de esta clase. Entra en la clave del caché:
     * subirla invalida todos los PDF cacheados sin borrar nada (los viejos
     * quedan huérfanos y se limpian aparte). Espejo obligatorio de
     * `KUDE_TEMPLATE_VERSION` en `frontend/lib/kude/types.ts`.
     */
    public const TEMPLATE_VERSION = 1;

    /** Timeout del render. Generoso: un PDF con logo remoto puede tardar. */
    private const RENDER_TIMEOUT_SECONDS = 15;

    /**
     * PDF del KuDE. NUNCA lanza por culpa del render propio: si algo sale
     * mal cae al `$fallback` (Factomate), que es el camino que ya estaba en
     * producción. Solo propaga lo que lance el fallback.
     *
     * @param callable():string $fallback camino Factomate (`EInvoiceService::kude()`).
     */
    public function pdf(string $companyId, string $docId, callable $fallback): string
    {
        try {
            $doc = $this->document($companyId, $docId);
            $cdc = (string) ($doc['cdc'] ?? '');
            if ($cdc === '') {
                // Sin CDC no hay documento emitido ni clave de caché posible.
                // El fallback tira el error legible que el endpoint traduce.
                return $fallback();
            }

            $cached = $this->cacheGet($companyId, $cdc);
            if ($cached !== null) {
                return $cached;
            }

            $pdf = $this->render($this->payload($companyId, $doc));
            $this->cachePut($companyId, $cdc, $pdf);

            return $pdf;
        } catch (\Throwable $e) {
            // El único lugar del que nos vamos a enterar mientras dure la
            // paridad. Con el CDC adelante para poder reproducirlo.
            error_log('[KudeService] render propio falló para ' . $docId . ' — se sirve el de Factomate: ' . $e->getMessage());

            return $fallback();
        }
    }

    // ── K3 — archivo del XML firmado ────────────────────────────────────

    /**
     * Guarda el XML FIRMADO del documento en S3 (`fiscal-xml/{company}/{cdc}.xml`).
     *
     * Por qué: el XML es el documento fiscal de verdad (el KuDE es auxiliar),
     * su conservación es obligación del emisor, y hoy vive solo en Factomate.
     * D5 de `context/73`: cada artefacto que podamos custodiar, lo custodiamos.
     *
     * BEST-EFFORT ABSOLUTO. Lo llama la reconciliación, en el mismo punto
     * donde nace la entrega por email: ni un fallo de red ni uno de S3 pueden
     * tirar abajo la corrida que escribe el estado fiscal de todos los
     * tenants, ni impedir que la factura le llegue al comprador. Si falla,
     * queda el log y se pierde ESTA pasada — no hay reintento: la
     * reconciliación no vuelve a mirar un documento ya sellado, y montar una
     * cola propia para esto no se justifica todavía (se anota como pendiente
     * en `context/73`).
     */
    public function archiveSignedXml(string $companyId, string $docId): void
    {
        try {
            $doc = $this->document($companyId, $docId);
            $cdc = (string) ($doc['cdc'] ?? '');
            $url = $this->providerField($doc, 'XmlUrl');
            if ($cdc === '' || $url === null || $url === '') {
                return;
            }

            $xml = $this->httpGet($url);
            if ($xml === null || $xml === '') {
                return;
            }

            $this->s3()->put(
                'fiscal-xml/' . $companyId . '/' . $cdc . '.xml',
                $xml,
                'application/xml',
                false // PRIVADO: es el documento fiscal del comercio.
            );
        } catch (\Throwable $e) {
            error_log('[KudeService] no se pudo archivar el XML de ' . $docId . ': ' . $e->getMessage());
        }
    }

    // ── Caché ───────────────────────────────────────────────────────────

    /**
     * Clave por CDC + versión de template: el CDC identifica al documento
     * fiscal (no el id interno, que puede repetirse conceptualmente entre
     * reemisiones) y la versión permite cambiar el diseño sin servir mezcla.
     */
    private function cacheKey(string $companyId, string $cdc): string
    {
        return 'kude/' . $companyId . '/' . $cdc . '-v' . self::TEMPLATE_VERSION . '.pdf';
    }

    /** Miss silencioso ante cualquier problema: el caché nunca bloquea. */
    private function cacheGet(string $companyId, string $cdc): ?string
    {
        try {
            $pdf = $this->s3()->get($this->cacheKey($companyId, $cdc));

            return ($pdf !== null && $pdf !== '') ? $pdf : null;
        } catch (\Throwable $e) {
            error_log('[KudeService] caché ilegible para ' . $cdc . ': ' . $e->getMessage());

            return null;
        }
    }

    /** Best-effort: si no se pudo cachear, el PDF ya está hecho igual. */
    private function cachePut(string $companyId, string $cdc, string $pdf): void
    {
        try {
            // PRIVADO. Un KuDE public-read sería una factura de un comercio
            // accesible con solo adivinar la URL; el acceso lo gobierna el
            // token del portal, no la oscuridad de la clave.
            $this->s3()->put($this->cacheKey($companyId, $cdc), $pdf, 'application/pdf', false);
        } catch (\Throwable $e) {
            error_log('[KudeService] no se pudo cachear el KuDE de ' . $cdc . ': ' . $e->getMessage());
        }
    }

    private function s3(): S3Client
    {
        return new S3Client(
            defined('S3_ENDPOINT')   ? S3_ENDPOINT   : '',
            defined('S3_REGION')     ? S3_REGION     : 'us-east-1',
            defined('S3_BUCKET')     ? S3_BUCKET     : '',
            defined('S3_KEY')        ? S3_KEY        : '',
            defined('S3_SECRET')     ? S3_SECRET     : '',
            defined('S3_KEY_PREFIX') ? S3_KEY_PREFIX : ''
        );
    }

    // ── Render remoto (Next) ────────────────────────────────────────────

    /**
     * POST al route interno de Next. Autenticación por clave compartida
     * (`INTERNAL_RENDER_KEY`): este endpoint no lo llama un browser ni tiene
     * sesión de tenant — lo llama el backend PHP con los datos ya resueltos.
     *
     * FALLA CERRADO: sin la env no se intenta siquiera. Es a propósito —
     * un route de render sin clave es un render abierto a internet, y con un
     * payload que dice qué imprimir.
     *
     * @param array<string,mixed> $payload
     * @throws \RuntimeException si no hay configuración o el render falla.
     */
    private function render(array $payload): string
    {
        $key = trim((string) ($_ENV['INTERNAL_RENDER_KEY'] ?? getenv('INTERNAL_RENDER_KEY') ?: ''));
        if ($key === '') {
            throw new \RuntimeException('INTERNAL_RENDER_KEY no configurada — no se llama al renderer.');
        }

        $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        if ($base === '') {
            throw new \RuntimeException('APP_URL no configurada — no se sabe dónde vive el renderer.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('No se pudo serializar el documento para el renderer.');
        }

        $ch = curl_init($base . '/api/kude/render');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Internal-Key: ' . $key,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::RENDER_TIMEOUT_SECONDS,
        ]);
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException(
                "El renderer respondió HTTP $status: " . ($err !== '' ? $err : mb_substr((string) $response, 0, 300))
            );
        }

        $pdf = (string) $response;
        // Un PDF empieza con "%PDF-". Si Next devolvió 200 con un HTML de
        // error (pasa con un middleware que redirige), servirlo sería mandarle
        // al comprador un archivo roto que dice ser su factura.
        if (!str_starts_with($pdf, '%PDF-')) {
            throw new \RuntimeException('El renderer no devolvió un PDF.');
        }

        return $pdf;
    }

    // ── Datos del documento ─────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     * @throws \RuntimeException si el documento no existe o no es del tenant.
     */
    private function document(string $companyId, string $docId): array
    {
        $row = ncmExecute(
            'SELECT einvoicedocid, transactionid, doctype, cdc, document_number, punto_number,
                    issued_at, request_payload, provider_response
               FROM einvoice_document
              WHERE einvoicedocid = ? AND companyid = ?',
            [$docId, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('Documento no encontrado.');
        }

        // La fila viene como `CaseInsensitiveArray` (wrapper del DB layer); se
        // aplana a array nativo porque de acá en adelante viaja a JSON. Las
        // claves quedan tal cual las proyecta el SELECT — todas lowercase.
        if ($row instanceof \CaseInsensitiveArray) {
            return $row->toArray();
        }

        return (array) $row;
    }

    /**
     * Payload del renderer. Todo lo que el template necesita, ya resuelto:
     * el template no consulta nada ni decide nada de negocio.
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private function payload(string $companyId, array $doc): array
    {
        $request = $this->decodeJson($doc['request_payload'] ?? null);
        $company = $this->company($companyId);
        $stamp   = $this->stamp($companyId, (string) ($doc['transactionid'] ?? ''));

        $items = [];
        foreach ((array) ($request['electronicDocumentItems'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $qty       = (float) ($raw['quantity'] ?? 0);
            $unitPrice = (float) ($raw['unitPriceWithTax'] ?? 0);
            $rate      = (int) ($raw['taxRate'] ?? 0);
            // El total de la línea no viaja en el payload de Factomate: SIFEN
            // lo deriva de cantidad x precio unitario con IVA. Se deriva igual
            // acá, con el mismo criterio.
            $lineTotal = round($qty * $unitPrice);

            $items[] = [
                'description' => (string) ($raw['description'] ?? ''),
                'quantity'    => $qty,
                'unitPrice'   => $unitPrice,
                'taxRate'     => $rate,
                'total'       => $lineTotal,
            ];
        }

        $client = (array) ($request['client'] ?? []);

        return [
            'templateVersion' => self::TEMPLATE_VERSION,
            'document' => [
                // Denominación del §13.4: es del TIPO fiscal del documento, no
                // del nombre que el tenant le puso a su doctype.
                'title'      => $this->denomination((string) ($doc['doctype'] ?? 'FC')),
                'number'     => $this->documentNumber($doc),
                'cdc'        => (string) ($doc['cdc'] ?? ''),
                'issuedAt'   => $this->tenantDateTime($companyId, (string) ($doc['issued_at'] ?? '')),
                'condition'  => ((int) ($request['operationCondition'] ?? 0)) === 1 ? 'Crédito' : 'Contado',
                'currency'   => (string) ($request['currencyTypeCode'] ?? ($company['currency'] ?? '')),
                // Tipo de cambio: el emisor solo factura en moneda local hoy
                // (el mapper aborta cualquier otra), así que no hay cambio que
                // declarar. Se manda explícito para que el template no adivine.
                'exchangeRate' => null,
                // J002 tal cual lo devolvió la emisión. Es el QR fiscal.
                'qrData'     => $this->providerField($doc, 'DCarQR'),
            ],
            'emitter' => [
                // RAZÓN SOCIAL, jamás el nombre comercial: es el dato que la
                // SET valida contra el padrón. Si falta, va vacío y se ve —
                // caer al nombre de fantasía sería falsear el emisor.
                'name'       => $company['billingName'],
                'tradeName'  => $company['name'],
                'ruc'        => $company['ruc'],
                'address'    => $company['address'],
                'city'       => $company['city'],
                'phone'      => $company['phone'],
                'email'      => $company['email'],
                // Actividad económica (D131): Punto no la tiene cargada en
                // ningún lado todavía. Va null y el template omite la línea.
                'activity'   => null,
                'logoUrl'    => $company['logoUrl'],
                'stamp'      => $stamp,
            ],
            'receiver' => [
                'name'        => trim((string) ($client['businessName'] ?? '')),
                'ruc'         => $this->nullIfEmpty((string) ($client['ruc'] ?? '')),
                'documentId'  => $this->nullIfEmpty((string) ($client['identityDocumentNumber'] ?? '')),
                'address'     => $this->nullIfEmpty((string) ($client['address'] ?? '')),
            ],
            'items'  => $items,
            'totals' => $this->totals($items, (float) ($request['total'] ?? 0)),
            // Formato de los montos del TENANT. Nada hardcodeado: el separador
            // y los decimales salen de sus ajustes.
            'format' => [
                'thousand' => $company['thousand'],
                'decimal'  => $company['decimalSeparator'],
                'decimals' => $company['decimals'],
                'currency' => $company['currency'],
            ],
        ];
    }

    /**
     * Liquidación del IVA por tasa (§13.4, sección Totales). El IVA se
     * calcula por línea y se suma —igual que en el mapper que armó el
     * documento— para que el KuDE declare exactamente lo mismo que el XML.
     *
     * @param list<array{taxRate:int,total:float}> $items
     * @return array<string,float>
     */
    private function totals(array $items, float $documentTotal): array
    {
        $base = [0 => 0.0, 5 => 0.0, 10 => 0.0];
        $iva  = [0 => 0.0, 5 => 0.0, 10 => 0.0];

        foreach ($items as $item) {
            $rate = (int) $item['taxRate'];
            if (!array_key_exists($rate, $base)) {
                continue;
            }
            $total = (float) $item['total'];
            $base[$rate] += $total;
            $iva[$rate]  += $rate > 0 ? round($total * $rate / (100 + $rate)) : 0.0;
        }

        return [
            'exempt'    => $base[0],
            'taxed5'    => $base[5],
            'taxed10'   => $base[10],
            'iva5'      => $iva[5],
            'iva10'     => $iva[10],
            'ivaTotal'  => $iva[5] + $iva[10],
            // El total del DOCUMENTO manda sobre la suma de líneas: es el que
            // se emitió y el que SIFEN tiene. Si difirieran, el que vale es
            // este (y el mapper ya aborta cuando difieren más de 1).
            'total'     => $documentTotal,
        ];
    }

    /** Denominación oficial del KuDE según el tipo de documento emitido. */
    private function denomination(string $doctype): string
    {
        return $doctype === 'NC'
            ? 'KuDE de Nota de Crédito Electrónica'
            : 'KuDE de Factura Electrónica';
    }

    /**
     * Número FISCAL del documento. El correlativo lo asigna la SET y vuelve
     * en `document_number` (`context/28`): el interno de Punto
     * (`punto_number`) no es el número del comprobante y solo se usa si el
     * proveedor no devolvió nada, para no dejar el campo en blanco.
     *
     * @param array<string,mixed> $doc
     */
    private function documentNumber(array $doc): string
    {
        $fiscal = trim((string) ($doc['document_number'] ?? ''));

        return $fiscal !== '' ? $fiscal : trim((string) ($doc['punto_number'] ?? ''));
    }

    /**
     * Timbrado con el que se emitió: sale de la CAJA de la venta, que es el
     * punto de expedición (`context/29`). No se toma el de la cuenta ni el
     * "primero que aparezca": un timbrado equivocado en el KuDE es un dato
     * fiscal falso. Si no se puede resolver, va null y el template omite el
     * bloque en vez de imprimir cualquier cosa.
     *
     * @return array{number:string,start:string,prefix:string}|null
     */
    private function stamp(string $companyId, string $transactionId): ?array
    {
        if ($transactionId === '') {
            return null;
        }

        $row = ncmExecute(
            'SELECT r.data
               FROM transaction t
               JOIN register r ON r.registerId = t.registerId AND r.companyId = t.companyId
              WHERE t.transactionId = ? AND t.companyId = ?',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return null;
        }

        // `data` viene aplanado sobre la fila por Query::flattenJsonb.
        $number = trim((string) ($row['registerInvoiceAuth'] ?? ''));
        $prefix = trim((string) ($row['registerInvoicePrefix'] ?? ''));
        $start  = trim((string) ($row['registerInvoiceAuthStart'] ?? ''));

        if ($number === '') {
            return null;
        }

        return ['number' => $number, 'start' => $start, 'prefix' => $prefix];
    }

    /**
     * Datos del emisor y formato de moneda del tenant.
     *
     * @return array<string,mixed>
     */
    private function company(string $companyId): array
    {
        $row = ncmExecute(
            "SELECT config->>'settingBillingName'       AS billing_name,
                    config->>'settingName'              AS name,
                    config->>'settingRUC'               AS ruc,
                    config->>'settingAddress'           AS address,
                    config->>'settingCity'              AS city,
                    config->>'settingPhone'             AS phone,
                    config->>'settingEmail'             AS email,
                    config->>'settingCurrency'          AS currency,
                    config->>'settingThousandSeparator' AS thousand_separator,
                    config->>'settingDecimal'           AS decimal_flag,
                    config->>'settingObj'               AS setting_obj
               FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );

        $obj = json_decode((string) ($row['setting_obj'] ?? ''), true);
        $obj = is_array($obj) ? $obj : [];

        // 'comma' = 1.234,56 al revés (1,234.56). Mismo par de convenciones
        // que usa el resto del producto; sin default hardcodeado a un país.
        $commaThousand = (string) ($row['thousand_separator'] ?? 'dot') === 'comma';

        return [
            'billingName'      => trim((string) ($row['billing_name'] ?? '')),
            'name'             => trim((string) ($row['name'] ?? '')),
            'ruc'              => trim((string) ($row['ruc'] ?? '')),
            'address'          => trim((string) ($row['address'] ?? '')),
            'city'             => trim((string) ($row['city'] ?? '')),
            'phone'            => trim((string) ($row['phone'] ?? '')),
            'email'            => trim((string) ($row['email'] ?? '')),
            'currency'         => trim((string) ($row['currency'] ?? '')),
            'thousand'         => $commaThousand ? ',' : '.',
            'decimalSeparator' => $commaThousand ? '.' : ',',
            'decimals'         => ((string) ($row['decimal_flag'] ?? '') === 'yes') ? 2 : 0,
            'logoUrl'          => !empty($obj['hasLogo']) && !empty($obj['logoUrl'])
                ? (string) $obj['logoUrl']
                : null,
        ];
    }

    /**
     * Campo suelto de la respuesta cruda de `/Bulk` (`Items[0]`). MISMA
     * extracción que `EInvoiceService::portalDocument()` usa para `qrUrl`:
     * tolerante al casing porque el proveedor no es consistente.
     *
     * @param array<string,mixed> $doc
     */
    private function providerField(array $doc, string $field): ?string
    {
        $raw = $this->decodeJson($doc['provider_response'] ?? null);
        $lower = lcfirst($field);

        foreach ((array) ($raw['Items'] ?? $raw['items'] ?? []) as $item) {
            if (is_array($item) && !empty($item[$field] ?? $item[$lower] ?? null)) {
                return (string) ($item[$field] ?? $item[$lower]);
            }
        }

        return null;
    }

    /** GET simple sin credenciales — para la URL del XML que da el proveedor. */
    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }

        return (string) $body;
    }

    /** Fecha en la zona del TENANT, nunca la del servidor. */
    private function tenantDateTime(string $companyId, string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        try {
            $tz = TenantLocale::timezone($companyId);

            return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone($tz))
                ->format('d/m/Y H:i');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
