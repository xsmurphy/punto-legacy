<?php
declare(strict_types=1);

namespace Punto\Api\Items;

use Punto\Api\Storage\S3Client;

/**
 * ItemImageService — galería de imágenes por item.
 *
 * Constraint: máx 5 imágenes por item, enforce a nivel servicio (no DB).
 * Procesa la imagen al subir: resize a max 1024x1024, convierte a JPEG q85.
 * Sube a S3-compatible (DO Spaces), URL pública directa, persiste metadata
 * en item_image.
 *
 * objectKey schema: items/<companyId>/<itemId>/<imageId>.jpg
 */
final class ItemImageService
{
    public const MAX_PER_ITEM = 5;
    public const MAX_INPUT_BYTES = 8 * 1024 * 1024; // 8 MB pre-resize
    public const MAX_DIMENSION = 1024;              // px (largo o ancho)
    public const JPEG_QUALITY = 85;

    private $db;
    private S3Client $s3;

    public function __construct($db, S3Client $s3)
    {
        $this->db = $db;
        $this->s3 = $s3;
    }

    /** Lista imágenes de un item ordenadas por sort. */
    public function listForItem(string $itemId, string $companyId): array
    {
        $rs = $this->db->Execute(
            'SELECT imageId, url, objectKey, width, height, sizeBytes, mime, sort, created_at
               FROM item_image
              WHERE itemId = ? AND companyId = ?
              ORDER BY sort ASC, created_at ASC',
            [$itemId, $companyId]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $r) {
            $out[] = [
                'imageId'   => $r['imageid']   ?? $r['imageId']   ?? null,
                'url'       => $r['url']       ?? null,
                'objectKey' => $r['objectkey'] ?? $r['objectKey'] ?? null,
                'width'     => isset($r['width'])     ? (int) $r['width']     : null,
                'height'    => isset($r['height'])    ? (int) $r['height']    : null,
                'sizeBytes' => isset($r['sizebytes']) ? (int) $r['sizebytes'] : (isset($r['sizeBytes']) ? (int) $r['sizeBytes'] : null),
                'mime'      => $r['mime'] ?? 'image/jpeg',
                'sort'      => (int) ($r['sort'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Sube una imagen nueva. $file es el array de $_FILES['image'].
     * Retorna la imagen creada o lanza RuntimeException con mensaje legible.
     */
    public function upload(string $itemId, string $companyId, array $file): array
    {
        $this->assertCapacity($itemId, $companyId);
        $this->validateUpload($file);

        // Cargar la imagen con GD para resize/recompress.
        $data = file_get_contents($file['tmp_name']);
        if ($data === false) throw new \RuntimeException('No se pudo leer el archivo');
        $src = @imagecreatefromstring($data);
        if ($src === false) throw new \RuntimeException('Archivo no es una imagen válida');

        [$origW, $origH] = [imagesx($src), imagesy($src)];
        [$newW, $newH]   = $this->scaleDown($origW, $origH, self::MAX_DIMENSION);

        if ($newW !== $origW || $newH !== $origH) {
            $dst = imagecreatetruecolor($newW, $newH);
            // Fondo blanco para preservar areas transparentes al convertir a JPEG.
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            imagedestroy($src);
            $src = $dst;
        }

        // Convertir a JPEG en memoria.
        ob_start();
        imagejpeg($src, null, self::JPEG_QUALITY);
        $jpegData = (string) ob_get_clean();
        imagedestroy($src);

        // Insert primero — necesitamos el imageId para el objectKey.
        $imageId   = $this->generateUuid();
        $objectKey = "items/$companyId/$itemId/$imageId.jpg";

        // Subir a S3 — si falla, no insertamos nada.
        $url = $this->s3->put($objectKey, $jpegData, 'image/jpeg', true);

        $sort = $this->nextSort($itemId, $companyId);
        $ok = $this->db->Execute(
            'INSERT INTO item_image (imageId, itemId, companyId, objectKey, url, width, height, sizeBytes, mime, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$imageId, $itemId, $companyId, $objectKey, $url, $newW, $newH, strlen($jpegData), 'image/jpeg', $sort]
        );
        if ($ok === false) {
            // Rollback: borrar de S3.
            try { $this->s3->delete($objectKey); } catch (\Throwable $e) { /* best-effort */ }
            throw new \RuntimeException('No se pudo guardar la imagen en la BD');
        }

        return [
            'imageId'   => $imageId,
            'url'       => $url,
            'objectKey' => $objectKey,
            'width'     => $newW,
            'height'    => $newH,
            'sizeBytes' => strlen($jpegData),
            'mime'      => 'image/jpeg',
            'sort'      => $sort,
        ];
    }

    public function delete(string $itemId, string $companyId, string $imageId): void
    {
        $rs = $this->db->Execute(
            'SELECT objectKey FROM item_image WHERE imageId = ? AND itemId = ? AND companyId = ? LIMIT 1',
            [$imageId, $itemId, $companyId]
        );
        if ($rs === false || $rs->EOF) throw new \RuntimeException('Imagen no encontrada');
        $objectKey = (string) ($rs->fields['objectkey'] ?? $rs->fields['objectKey'] ?? '');

        $this->db->Execute(
            'DELETE FROM item_image WHERE imageId = ? AND itemId = ? AND companyId = ?',
            [$imageId, $itemId, $companyId]
        );
        // Best-effort S3 delete — si falla, el row ya está borrado y queda huérfano (cleanup nocturno).
        try { $this->s3->delete($objectKey); } catch (\Throwable $e) { /* swallow */ }
    }

    /** Reordena las imágenes — el array recibido define el orden final. */
    public function reorder(string $itemId, string $companyId, array $imageIds): void
    {
        foreach ($imageIds as $i => $id) {
            $this->db->Execute(
                'UPDATE item_image SET sort = ? WHERE imageId = ? AND itemId = ? AND companyId = ?',
                [$i, $id, $itemId, $companyId]
            );
        }
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function assertCapacity(string $itemId, string $companyId): void
    {
        $rs = $this->db->Execute(
            'SELECT COUNT(*) AS n FROM item_image WHERE itemId = ? AND companyId = ?',
            [$itemId, $companyId]
        );
        $n = ($rs !== false && !$rs->EOF) ? (int) $rs->fields['n'] : 0;
        if ($n >= self::MAX_PER_ITEM) {
            throw new \RuntimeException('Máximo ' . self::MAX_PER_ITEM . ' imágenes por artículo');
        }
    }

    private function validateUpload(array $file): void
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Archivo no recibido');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException('Archivo vacío o > 8 MB');
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) throw new \RuntimeException('No es una imagen válida');
        $mime = (string) ($info['mime'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new \RuntimeException('Formato no soportado (JPG/PNG/WEBP/GIF)');
        }
    }

    /** @return array{0:int,1:int} */
    private function scaleDown(int $w, int $h, int $max): array
    {
        if ($w <= $max && $h <= $max) return [$w, $h];
        $ratio = $w / $h;
        if ($w >= $h) {
            return [$max, (int) round($max / $ratio)];
        }
        return [(int) round($max * $ratio), $max];
    }

    private function nextSort(string $itemId, string $companyId): int
    {
        $rs = $this->db->Execute(
            'SELECT COALESCE(MAX(sort), -1) + 1 AS next_sort FROM item_image WHERE itemId = ? AND companyId = ?',
            [$itemId, $companyId]
        );
        return ($rs !== false && !$rs->EOF) ? (int) ($rs->fields['next_sort'] ?? 0) : 0;
    }

    private function generateUuid(): string
    {
        // UUID v4 simple.
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
