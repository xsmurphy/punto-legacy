<?php
declare(strict_types=1);

namespace Punto\Api\Hr;

use Punto\Api\Storage\S3Client;

/**
 * Adjuntos del legajo — contrato, cédula, constancias (`employee_attachment`,
 * mig 229).
 *
 * ── Por qué PRIVADOS y no `publicRead` como el resto ────────────────────────
 *
 * Las imágenes de ítems y el logo del comercio se suben con ACL pública
 * porque son cosas que el comercio muestra. Un contrato o una cédula son
 * datos personales de un tercero: con ACL pública, la URL del objeto los
 * entrega a cualquiera que la tenga, sin sesión y sin permiso. Se suben
 * privados y se bajan por GET firmado desde el endpoint, que recién ahí
 * chequea tenant y permiso — mismo mecanismo que el archivo del XML fiscal
 * (`EInvoiceService::fiscalStorage()`).
 *
 * Consecuencia que ordena el diseño: la fila guarda el `objectkey`, NUNCA una
 * URL. La URL de un objeto privado caduca, así que persistirla sería guardar
 * algo que deja de funcionar.
 *
 * ── El archivo NO se procesa ────────────────────────────────────────────────
 *
 * A diferencia de `ItemImageService`, acá no hay resize ni recompresión a
 * JPEG: un contrato escaneado es un documento, y recomprimirlo degrada
 * justamente lo que se archiva (la letra chica, la firma). Se guarda el byte
 * que subió el comercio.
 *
 * El tipo real sale de `finfo`, no del Content-Type que declara el cliente:
 * el declarado lo elige quien sube.
 */
final class EmployeeAttachmentService
{
    public const MAX_PER_EMPLOYEE = 20;
    public const MAX_BYTES = 10 * 1024 * 1024; // 10 MB

    /** Tipo real (finfo) → extensión del objeto en S3. */
    private const ALLOWED_MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/heic'      => 'heic',
    ];

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private S3Client $s3;

    public function __construct(S3Client $s3)
    {
        $this->s3 = $s3;
    }

    /** @return array<int, array<string,mixed>> */
    public function listFor(string $employeeId, string $companyId): array
    {
        if (!preg_match(self::UUID_RE, $employeeId)) {
            return [];
        }
        $rs = ncmExecute(
            'SELECT attachmentid, filename, mime, sizebytes, label, createdat
               FROM employee_attachment
              WHERE contactid = ? AND companyid = ?
              ORDER BY createdat DESC',
            [$employeeId, $companyId],
            false,
            true
        );
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $this->shape($rs->fields);
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $rows;
    }

    /**
     * Sube un adjunto. `$file` es el array de `$_FILES[...]`.
     *
     * @return array<string,mixed> el adjunto creado
     */
    public function upload(
        string $employeeId,
        string $companyId,
        array $file,
        ?string $label = null,
        ?string $actorId = null
    ): array {
        $this->assertEmployee($employeeId, $companyId);
        $mime = $this->validate($file, $employeeId, $companyId);

        $data = file_get_contents((string) $file['tmp_name']);
        if ($data === false || $data === '') {
            throw new \RuntimeException('No se pudo leer el archivo');
        }

        $attachmentId = self::uuid();
        $objectKey = sprintf(
            'employees/%s/%s/%s.%s',
            $companyId,
            $employeeId,
            $attachmentId,
            self::ALLOWED_MIMES[$mime]
        );

        // publicRead = false: ver el docblock de la clase.
        $this->s3->put($objectKey, $data, $mime, false);

        // El objeto ya está en S3. Si el INSERT falla, ese objeto queda
        // huérfano y nadie lo puede alcanzar (el único índice es esta fila),
        // así que se borra antes de propagar el error: un bucket que acumula
        // documentos personales inalcanzables es peor que el fallo.
        try {
            $ok = ncmInsert([
                'records' => [
                    'attachmentid' => $attachmentId,
                    'companyid'    => $companyId,
                    'contactid'   => $employeeId,
                    'objectkey'    => $objectKey,
                    'filename'     => self::safeFilename($file['name'] ?? 'archivo'),
                    'mime'         => $mime,
                    'sizebytes'    => (int) $file['size'],
                    'label'        => self::labelOrNull($label),
                    'createdby'    => self::uuidOrNull($actorId),
                ],
                'table' => 'employee_attachment',
            ]);
            if (!$ok) {
                throw new \RuntimeException('No se pudo guardar el adjunto');
            }
        } catch (\Throwable $e) {
            try {
                $this->s3->delete($objectKey);
            } catch (\Throwable) {
                // El borrado de limpieza no puede tapar el error original.
            }
            throw $e;
        }

        $row = $this->find($attachmentId, $companyId);
        if ($row === null) {
            throw new \RuntimeException('Adjunto guardado pero no se pudo leer de vuelta');
        }
        return $row;
    }

    /**
     * Bytes del adjunto para servirlo. Devuelve null si no existe en el
     * tenant; lanza si el objeto no está en S3 (es una inconsistencia real,
     * no un 404 del usuario).
     *
     * @return array{filename:string,mime:string,body:string}|null
     */
    public function download(string $attachmentId, string $companyId): ?array
    {
        $row = $this->findRaw($attachmentId, $companyId);
        if ($row === null) {
            return null;
        }
        $body = $this->s3->get((string) $row['objectkey']);
        if ($body === null) {
            throw new \RuntimeException('El archivo ya no está disponible');
        }
        return [
            'filename' => (string) $row['filename'],
            'mime'     => (string) $row['mime'],
            'body'     => $body,
        ];
    }

    /**
     * Borra el adjunto. Acá sí es borrado FÍSICO y no archivado: es un
     * documento personal de un empleado y "borrar" tiene que borrar — dejarlo
     * en S3 con la fila oculta sería decir que se eliminó algo que sigue ahí.
     */
    public function delete(string $attachmentId, string $companyId): void
    {
        $row = $this->findRaw($attachmentId, $companyId);
        if ($row === null) {
            throw new \RuntimeException('Adjunto no encontrado');
        }

        // Primero la fila: si S3 falla, el archivo queda inalcanzable igual y
        // se puede reintentar. Al revés —S3 primero— un fallo del DELETE
        // dejaría una fila que promete un archivo que ya no existe.
        ncmExecute(
            'DELETE FROM employee_attachment WHERE attachmentid = ? AND companyid = ?',
            [$attachmentId, $companyId]
        );
        $this->s3->delete((string) $row['objectkey']);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function find(string $attachmentId, string $companyId): ?array
    {
        $row = $this->findRaw($attachmentId, $companyId);
        return $row ? $this->shape($row) : null;
    }

    private function findRaw(string $attachmentId, string $companyId)
    {
        if (!preg_match(self::UUID_RE, $attachmentId)) {
            return null;
        }
        return ncmExecute(
            'SELECT * FROM employee_attachment WHERE attachmentid = ? AND companyid = ? LIMIT 1',
            [$attachmentId, $companyId]
        ) ?: null;
    }

    /** El legajo tiene que existir EN ESTE comercio antes de colgarle nada. */
    private function assertEmployee(string $employeeId, string $companyId): void
    {
        if (!preg_match(self::UUID_RE, $employeeId)) {
            throw new \RuntimeException('Empleado no encontrado');
        }
        $row = ncmExecute(
            'SELECT contactid FROM employee WHERE contactid = ? AND companyid = ? LIMIT 1',
            [$employeeId, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('Empleado no encontrado');
        }
    }

    /** @return string el mime REAL, ya validado */
    private function validate(array $file, string $employeeId, string $companyId): string
    {
        if (empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException('Archivo no recibido');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new \RuntimeException('El archivo está vacío');
        }
        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException('El archivo supera los 10 MB');
        }

        $count = ncmExecute(
            'SELECT COUNT(*) AS n FROM employee_attachment WHERE contactid = ? AND companyid = ?',
            [$employeeId, $companyId]
        );
        if ($count && (int) $count['n'] >= self::MAX_PER_EMPLOYEE) {
            throw new \RuntimeException('El legajo ya tiene el máximo de archivos adjuntos');
        }

        // El tipo lo dice el CONTENIDO, no el header que mandó el cliente.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file((string) $file['tmp_name']);
        if (!isset(self::ALLOWED_MIMES[$mime])) {
            throw new \RuntimeException('Formato no soportado (PDF, JPG, PNG, WEBP o HEIC)');
        }
        return $mime;
    }

    private function shape($f): array
    {
        return [
            'id'        => (string) $f['attachmentid'],
            'filename'  => (string) $f['filename'],
            'mime'      => (string) $f['mime'],
            'sizeBytes' => (int) $f['sizebytes'],
            'label'     => ($f['label'] ?? null) !== null && (string) $f['label'] !== ''
                ? (string) $f['label']
                : null,
            'createdAt' => $f['createdat'] ?? null,
        ];
    }

    /**
     * El nombre original solo se usa para devolvérselo al comercio cuando
     * descarga. Se limpia igual: viaja en `Content-Disposition`, donde un
     * salto de línea o una comilla permitirían inyectar cabeceras.
     */
    private static function safeFilename(mixed $name): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '', (string) $name) ?? '';
        $clean = trim(str_replace(['..', '/', '\\'], '', $clean));
        if ($clean === '') {
            $clean = 'archivo';
        }
        return mb_substr($clean, 0, 120);
    }

    private static function labelOrNull(?string $label): ?string
    {
        $s = trim((string) $label);
        return $s === '' ? null : mb_substr($s, 0, 120);
    }

    private static function uuidOrNull(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return ($s !== '' && preg_match(self::UUID_RE, $s)) ? $s : null;
    }

    private static function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
