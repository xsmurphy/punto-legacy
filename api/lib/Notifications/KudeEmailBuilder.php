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
            'SELECT einvoicedocid, transactionid, status, cdc, document_number,
                    issued_at, sifen_status, superseded_by, cancelled_at, numbering_mismatch
               FROM einvoice_document
              WHERE einvoicedocid = ? AND companyid = ?',
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

        // KuDE PROPIO (K2 de context/73): `kudePdf()` es el punto de decisión
        // —caché, render propio, fallback a Factomate— y el adjunto del email
        // es una de las superficies que tenía que moverse.
        //
        // Reintentable a propósito: si NI el render propio NI Factomate tienen
        // el PDF todavía, la excepción sube y el backoff del outbox cubre
        // exactamente ese caso.
        $pdf = $svc->kudePdf($companyId, $docId);

        $commerce  = $this->commerce($companyId);
        $number    = trim((string) ($doc['document_number'] ?? ''));
        $label     = $number !== '' ? $number : $cdc;
        $issuedAt  = $this->tenantDate($companyId, (string) ($doc['issued_at'] ?? ''));
        $portalUrl = $svc->portalUrl($companyId, (string) ($doc['transactionid'] ?? ''));

        return [
            'subject'     => $this->subject($commerce['name'], $number, $cdc),
            'html'        => $this->html($commerce['name'], $label, $cdc, $issuedAt, $portalUrl),
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
     * Cuerpo HTML. Sin CSS externo ni imágenes: los clientes de correo los
     * bloquean o los reescriben, y este email tiene que leerse igual en un
     * webmail, en un cliente de escritorio y en un teléfono. Estilos mínimos
     * inline, todo el contenido en texto.
     */
    private function html(
        string $commerceName,
        string $docLabel,
        string $cdc,
        string $issuedAt,
        ?string $portalUrl
    ): string {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $emisor = $commerceName !== '' ? $e($commerceName) : 'el comercio';

        $rows = '<p style="margin:0 0 4px"><strong>Documento:</strong> ' . $e($docLabel) . '</p>';
        if ($issuedAt !== '') {
            $rows .= '<p style="margin:0 0 4px"><strong>Fecha de emisión:</strong> ' . $e($issuedAt) . '</p>';
        }
        $rows .= '<p style="margin:0 0 4px"><strong>CDC:</strong> '
               . '<span style="font-family:monospace;font-size:13px">' . $e($cdc) . '</span></p>';

        // El link va SIEMPRE que exista, y con la explicación de para qué
        // sirve: sin ese párrafo el lector asume que el PDF es la última
        // palabra, que es justo lo que D6 quiere evitar.
        $portal = '';
        if ($portalUrl !== null && $portalUrl !== '') {
            $portal = '<p style="margin:16px 0 0">'
                    . '<a href="' . $e($portalUrl) . '">Ver el documento en línea</a>'
                    . '</p>'
                    . '<p style="margin:8px 0 0;color:#555;font-size:13px">'
                    . 'El archivo adjunto es una copia del momento del envío. El estado vigente del '
                    . 'documento —incluida una eventual anulación— se consulta siempre en ese enlace.'
                    . '</p>';
        }

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222">'
             . '<p style="margin:0 0 12px">Hola:</p>'
             . '<p style="margin:0 0 12px">Adjuntamos tu factura electrónica emitida por ' . $emisor . '.</p>'
             . $rows
             . $portal
             . '<p style="margin:16px 0 0;color:#555;font-size:13px">'
             . 'Si tenés alguna consulta sobre esta factura, respondé a este correo: llega directo a ' . $emisor . '.'
             . '</p>'
             . '</div>';
    }

    /**
     * Nombre y casilla del COMERCIO. La casilla es la regla dura del
     * `reply_to` (ver `EmailAdapter`): primero la configurada en los ajustes
     * (`settingEmail`), y si está vacía la del dueño registrado — mismo
     * criterio y mismo predicado de rol que `PlanLifecycleService::ownerEmail()`,
     * que es el único lugar del repo que sabe distinguir el rol de dueño.
     *
     * @return array{name:string,email:string}
     */
    private function commerce(string $companyId): array
    {
        $row = ncmExecute(
            "SELECT coalesce(nullif(config->>'settingName', ''), nullif(config->>'companyName', ''), '') AS name,
                    coalesce(nullif(config->>'settingEmail', ''), '') AS email
               FROM company WHERE companyId = ?",
            [$companyId]
        );

        $name  = trim((string) ($row['name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = $this->ownerEmail($companyId);
        }

        return ['name' => $name, 'email' => $email];
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
