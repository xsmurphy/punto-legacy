<?php

declare(strict_types=1);

namespace Punto\Api\Notifications;

use Punto\Api\EInvoice\EInvoiceService;
use Punto\Api\Support\TenantLocale;

/**
 * Cuerpo del email de entrega del KuDE — E1/E2 de
 * `context/57-entrega-digital-del-kude.md` (builder del `entitytype`
 * `einvoice_document`).
 *
 * D6 — VAN LOS DOS, adjunto Y link, y no es redundancia:
 *
 *   - El ADJUNTO es la razón de existir del canal email: es lo que el
 *     comprador archiva y le pasa a su contador, sin depender de que un
 *     servidor siga en pie dentro de tres años.
 *   - El LINK al portal es el único que dice la verdad HOY. El PDF queda
 *     congelado en el momento del envío; si el comercio después anula el
 *     documento, el archivo en la bandeja del cliente sigue afirmando que la
 *     factura vale. Por eso el cuerpo dice explícitamente que el estado
 *     vigente vive en el portal.
 *
 * SIN MONTOS. El email identifica el documento (número, CDC, fecha) y adjunta
 * la representación gráfica; los importes ya están adentro del KuDE, con el
 * formato fiscal correcto. Repetirlos en el cuerpo agrega una segunda fuente
 * que puede discrepar (redondeo, moneda, descuentos) sobre un documento
 * fiscal — el peor lugar donde tener dos versiones de un número.
 *
 * EL PDF SE BAJA AL ENVIAR, no se persiste: mismo criterio que el resto del
 * módulo. Si `getkude` todavía no lo tiene listo, la excepción sube y el
 * outbox reintenta — nunca se marca error, la factura ya se emitió.
 */
final class KudeEmailBuilder
{
    /**
     * @return array{subject:string,html:string,fromName:string,replyTo:string,attachments:list<array{filename:string,content:string}>}
     * @throws NotificationSkipped si el documento ya no se debe entregar (anulado, reemplazado, sin emitir).
     * @throws \RuntimeException   si el KuDE todavía no está disponible (reintentable).
     */
    public function build(string $companyId, string $docId): array
    {
        $doc = ncmExecute(
            'SELECT d.einvoicedocid, d.transactionid, d.status, d.cdc, d.document_number,
                    d.issued_at, d.sifen_status, d.superseded_by, d.cancelled_at,
                    d.numbering_mismatch,
                    -- El punto de expedición vive en la TRANSACCIÓN, no en el
                    -- outbox: `document_number` guarda el correlativo pelado
                    -- ("0000620") y el número fiscal completo es
                    -- (punto, correlativo). Sin esto el email decía "0000620"
                    -- y el KuDE adjunto "001-002-0000620" — dos números para
                    -- el mismo documento.
                    t.invoiceprefix AS invoice_prefix
               FROM einvoice_document d
               LEFT JOIN transaction t ON t.transactionid = d.transactionid
              WHERE d.einvoicedocid = ? AND d.companyid = ?',
            [$docId, $companyId]
        );
        if (!$doc) {
            throw new NotificationSkipped('El documento electrónico ya no existe.');
        }

        // Guardas de ESTADO, revalidadas al enviar y no sólo al encolar: entre
        // el encolado y la salida pueden pasar días (backoff paciente), y en
        // ese rato el comercio puede haber anulado o reemitido el documento.
        if (($doc['superseded_by'] ?? null) !== null) {
            throw new NotificationSkipped('El documento fue reemplazado por una reemisión — no se entrega.');
        }
        if ((string) ($doc['status'] ?? '') === 'cancelled' || ($doc['cancelled_at'] ?? null) !== null) {
            throw new NotificationSkipped('El documento fue anulado — no se entrega.');
        }
        // Guard de numeración (mig 204), revalidado acá por la MISMA razón que
        // los dos de arriba: el encolado ya lo chequea
        // (`EInvoiceService::enqueueKudeEmail`), pero entre encolar y mandar
        // pasan días y el flag puede aparecer después (una reconciliación
        // tardía, un reproceso). Si el CDC del documento no es el del
        // comprobante que se le entregó al cliente, el mail le llevaría la
        // factura de otra operación — y este es el único canal que no
        // requiere ninguna acción suya para llegarle.
        if (trim((string) ($doc['numbering_mismatch'] ?? '')) !== '') {
            throw new NotificationSkipped(
                'El número del documento electrónico no coincide con el del comprobante entregado — no se entrega.'
            );
        }
        $cdc = trim((string) ($doc['cdc'] ?? ''));
        if ($cdc === '') {
            throw new NotificationSkipped('El documento no tiene CDC — nunca se emitió.');
        }

        $svc = new EInvoiceService();

        // El adjunto es el KuDE que renderiza el motor de facturación
        // electrónica — el mismo archivo, byte por byte, que el comprador se
        // baja del portal y que el cajero le entrega desde la caja.
        //
        // Reintentable a propósito: entre que el documento se aprueba y el PDF
        // termina de generarse pasan segundos, así que si todavía no está la
        // excepción sube y el backoff del outbox cubre exactamente ese caso.
        $pdf = $svc->kude($companyId, $docId);

        $commerce  = $this->commerce($companyId);
        $number    = trim((string) ($doc['document_number'] ?? ''));
        // Mismo formateador que el ticket, el KuDE y el listado (mig 159/209):
        // el número que lee el comprador tiene que ser IDÉNTICO en todas las
        // superficies.
        $number    = $number !== ''
            ? \Punto\Api\Documents\DocumentNumber::format(
                $number,
                (string) ($doc['invoice_prefix'] ?? '')
              )
            : '';
        $label     = $number !== '' ? $number : $cdc;
        $issuedAt  = $this->tenantDate($companyId, (string) ($doc['issued_at'] ?? ''));
        $portalUrl = $svc->portalUrl($companyId, (string) ($doc['transactionid'] ?? ''));

        return [
            'subject'     => $this->subject($commerce['name'], $number, $cdc),
            'html'        => $this->html($commerce, $label, $cdc, $issuedAt, $portalUrl),
            'fromName'    => $commerce['name'],
            'replyTo'     => $commerce['email'],
            'attachments' => [[
                'filename' => $this->fileName($number, $cdc),
                'content'  => $pdf,
            ]],
        ];
    }

    /** Asunto: identifica el documento y quién lo emite, sin adjetivos. */
    private function subject(string $commerceName, string $number, string $cdc): string
    {
        $doc = $number !== '' ? 'Factura electrónica ' . $number : 'Factura electrónica';
        if ($number === '') {
            $doc .= ' ' . substr($cdc, -8);
        }

        return $commerceName !== '' ? $doc . ' — ' . $commerceName : $doc;
    }

    /** Nombre del adjunto: reconocible en la bandeja y en la carpeta del contador. */
    private function fileName(string $number, string $cdc): string
    {
        $base = $number !== '' ? $number : $cdc;
        $base = preg_replace('/[^A-Za-z0-9\-]+/', '-', $base) ?? '';
        $base = trim($base, '-');

        return 'factura-' . ($base !== '' ? $base : 'electronica') . '.pdf';
    }

    /**
     * Cuerpo HTML.
     *
     * Estilos inline y layout con tablas: es lo único que renderiza igual en
     * un webmail, un cliente de escritorio y un teléfono. Nada de CSS externo,
     * flex ni grid — Outlook no los soporta.
     *
     * SÍ lleva imágenes (el logo del comercio arriba y el de Punto al pie),
     * remotas y con `alt` que dice lo mismo en texto: muchos clientes las
     * bloquean por defecto, así que el email tiene que leerse completo sin
     * cargar una sola. Por eso el nombre del comercio también va escrito, no
     * solo dibujado en su logo.
     *
     * La jerarquía es deliberada: arriba manda el COMERCIO, porque el
     * comprador le compró a ese negocio; Punto va al pie, chico, igual que en
     * el portal público (`/factura/{token}`).
     *
     * @param array{name:string,email:string,logoUrl:string} $commerce
     */
    private function html(
        array $commerce,
        string $docLabel,
        string $cdc,
        string $issuedAt,
        ?string $portalUrl
    ): string {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $commerceName = trim($commerce['name']);
        $emisor       = $commerceName !== '' ? $e($commerceName) : 'el comercio';

        // ── Encabezado: la marca del comercio ─────────────────────────────
        $header = '';
        if (trim($commerce['logoUrl']) !== '') {
            $header .= '<img src="' . $e($commerce['logoUrl']) . '" alt="' . $e($commerceName) . '"'
                     . ' height="48" style="display:block;max-height:48px;border:0;margin:0 0 10px">';
        }
        if ($commerceName !== '') {
            $header .= '<div style="font-size:16px;font-weight:bold;color:#111">' . $e($commerceName) . '</div>';
        }

        // ── Datos del documento ───────────────────────────────────────────
        $row = static function (string $k, string $v, bool $mono = false) use ($e): string {
            $style = 'font-size:14px;color:#111;padding:3px 0'
                   . ($mono ? ';font-family:Consolas,Menlo,monospace;font-size:12px;word-break:break-all' : '');

            return '<tr>'
                 . '<td style="font-size:14px;color:#666;padding:3px 16px 3px 0;white-space:nowrap;vertical-align:top">'
                 . $e($k) . '</td>'
                 . '<td style="' . $style . '">' . $e($v) . '</td>'
                 . '</tr>';
        };

        $rows = $row('Documento', $docLabel);
        if ($issuedAt !== '') {
            $rows .= $row('Fecha de emisión', $issuedAt);
        }
        $rows .= $row('CDC', $cdc, true);

        // ── Portal ────────────────────────────────────────────────────────
        // El link va SIEMPRE que exista, y con la explicación de para qué
        // sirve: sin ese párrafo el lector asume que el PDF es la última
        // palabra, que es justo lo que D6 quiere evitar.
        $portal = '';
        if ($portalUrl !== null && $portalUrl !== '') {
            $portal = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 0">'
                    . '<tr><td style="background:#111;border-radius:6px">'
                    . '<a href="' . $e($portalUrl) . '"'
                    . ' style="display:inline-block;padding:11px 20px;font-size:14px;font-weight:bold;'
                    . 'color:#fff;text-decoration:none">Ver el documento en línea</a>'
                    . '</td></tr></table>'
                    . '<p style="margin:12px 0 0;color:#666;font-size:13px;line-height:1.5">'
                    . 'El archivo adjunto es una copia del momento del envío. El estado vigente del '
                    . 'documento —incluida una eventual anulación— se consulta siempre en ese enlace.'
                    . '</p>';
        }

        // ── Pie de Punto ──────────────────────────────────────────────────
        // Mismo criterio que el portal: canal de marca legítimo (lo abre gente
        // que probablemente no nos conoce) pero SIEMPRE debajo del comercio.
        $appUrl   = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        $puntoPie = '<a href="https://www.punto.la" style="color:#666;text-decoration:none;font-size:12px">';
        if ($appUrl !== '') {
            $puntoPie .= '<img src="' . $e($appUrl . '/logos/logo_bg_light.png') . '" alt="Punto"'
                       . ' height="16" style="height:16px;border:0;vertical-align:middle;margin-right:6px">';
        }
        $puntoPie .= 'Usamos www.punto.la</a>';

        return '<div style="background:#f4f4f5;padding:24px 12px;'
             . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,Helvetica,sans-serif">'
             . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
             . ' style="max-width:560px;margin:0 auto">'
             . '<tr><td style="background:#fff;border-radius:10px;padding:28px 26px">'
             . $header
             . '<h1 style="margin:18px 0 0;font-size:19px;line-height:1.3;color:#111">'
             . 'Factura electrónica ' . $e($docLabel)
             . '</h1>'
             . '<p style="margin:10px 0 0;font-size:14px;line-height:1.5;color:#444">'
             . 'Adjuntamos tu factura electrónica emitida por ' . $emisor . '.'
             . '</p>'
             . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
             . ' style="margin:18px 0 0;width:100%">' . $rows . '</table>'
             . $portal
             . '<p style="margin:22px 0 0;padding:16px 0 0;border-top:1px solid #eee;'
             . 'color:#666;font-size:13px;line-height:1.5">'
             . 'Si tenés alguna consulta sobre esta factura, respondé a este correo: llega directo a '
             . $emisor . '.'
             . '</p>'
             . '</td></tr>'
             . '<tr><td align="center" style="padding:16px 0 0">' . $puntoPie . '</td></tr>'
             . '</table>'
             . '</div>';
    }

    /**
     * Nombre y casilla del COMERCIO. La casilla es la regla dura del
     * `reply_to` (ver `EmailAdapter`): primero la configurada en los ajustes
     * (`settingEmail`), y si está vacía la del dueño registrado — mismo
     * criterio y mismo predicado de rol que `PlanLifecycleService::ownerEmail()`,
     * que es el único lugar del repo que sabe distinguir el rol de dueño.
     *
     * El LOGO sale de `settingObj.logoUrl` — la URL de S3 que escribe
     * `SettingsService::uploadLogo()`, la misma que usan el portal público y
     * el KuDE propio. No se deriva del companyId a mano: esa ruta legacy de
     * `data.php` apunta a un archivo que no existe (bug del portal,
     * 2026-09-09). Se respeta `hasLogo`: sin él la clave puede traer una URL
     * vieja de un logo ya borrado.
     *
     * @return array{name:string,email:string,logoUrl:string}
     */
    private function commerce(string $companyId): array
    {
        $row = ncmExecute(
            "SELECT coalesce(nullif(config->>'settingName', ''), nullif(config->>'companyName', ''), '') AS name,
                    coalesce(nullif(config->>'settingEmail', ''), '') AS email,
                    config->>'settingObj' AS setting_obj
               FROM company WHERE companyId = ?",
            [$companyId]
        );

        $name  = trim((string) ($row['name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = $this->ownerEmail($companyId);
        }

        $obj = json_decode((string) ($row['setting_obj'] ?? ''), true);
        $obj = is_array($obj) ? $obj : [];

        $logoUrl = (!empty($obj['hasLogo']) && !empty($obj['logoUrl']))
            ? trim((string) $obj['logoUrl'])
            : '';
        // Cache-bust con el mismo sello que usa el portal: sin esto, cambiar
        // el logo deja a los clientes de correo sirviendo el anterior.
        $stamp = trim((string) ($obj['logoUploadedAt'] ?? ''));
        if ($logoUrl !== '' && $stamp !== '') {
            $logoUrl .= (str_contains($logoUrl, '?') ? '&' : '?') . 'v=' . rawurlencode($stamp);
        }

        return ['name' => $name, 'email' => $email, 'logoUrl' => $logoUrl];
    }

    /** Casilla del dueño registrado, fallback del `reply_to`. '' si no hay. */
    private function ownerEmail(string $companyId): string
    {
        require_once __DIR__ . '/../Auth/RoleService.php';

        $row = ncmExecute(
            "SELECT c.contactemail
               FROM contact c
              WHERE c.companyid = ?
                AND c.type = 0
                AND coalesce(c.contactemail, '') <> ''
                AND " . \RoleService::ownerRoleSql('c') . "
              ORDER BY (coalesce(c.main, '') = 'true') DESC, c.contactid
              LIMIT 1",
            [$companyId]
        );

        $email = trim((string) ($row['contactemail'] ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** Fecha en la zona del TENANT (nunca la del servidor). '' si no se puede. */
    private function tenantDate(string $companyId, string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        try {
            $tz = TenantLocale::timezone($companyId);
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));

            return $dt->setTimezone(new \DateTimeZone($tz))->format('d/m/Y H:i');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
