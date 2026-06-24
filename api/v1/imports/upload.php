<?php
/**
 * POST /v1/imports/upload
 *
 * Recibe un archivo CSV (multipart/form-data, campo 'file').
 * Guarda en /tmp/punto-imports/, genera ImportSession Redis y devuelve
 * {sessionId, columns, rowCount, sample, filename}.
 *
 * El cliente convierte XLSX→CSV antes de enviar (client-side, SheetJS).
 * Tamaño máximo: 10 MB. Filas máximas: 2000.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Method not allowed', 405);
}

if (empty($_FILES['file'])) {
    apiError('No se recibió ningún archivo', 400);
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    apiError('Error al subir el archivo: código ' . $file['error'], 400);
}

if ($file['size'] > 10 * 1024 * 1024) {
    apiError('El archivo no puede superar 10 MB', 422);
}

$originalName = (string) ($file['name'] ?? 'upload.csv');
$ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt', 'tsv'], true)) {
    apiError('Solo se aceptan archivos CSV/TSV/TXT (el cliente debe convertir XLSX antes de subir)', 422);
}

$tmpDir = '/tmp/punto-imports';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0700, true);
}

$sessionUuid = bin2hex(random_bytes(16));
$csvPath     = $tmpDir . '/' . $sessionUuid . '.csv';

if (!move_uploaded_file($file['tmp_name'], $csvPath)) {
    apiError('No se pudo guardar el archivo temporal', 500);
}

$fileContents = (string) file_get_contents($csvPath);

$sample8k  = substr($fileContents, 0, 8192);
$delimiter = substr_count($sample8k, ';') > substr_count($sample8k, ',') ? ';' : ',';

$lines = preg_split("/\r\n|\n|\r/", $fileContents) ?: [];
while (count($lines) && trim((string) end($lines)) === '') array_pop($lines);

if (count($lines) < 2) {
    @unlink($csvPath);
    apiError('El archivo no tiene filas de datos', 422);
}

$headerLine = (string) array_shift($lines);
$columns    = str_getcsv($headerLine, $delimiter);
$columns    = array_map('trim', $columns);

$rowCount = count($lines);
if ($rowCount > 2000) {
    @unlink($csvPath);
    apiError('El archivo supera el máximo de 2000 filas', 422);
}

$sampleRows = [];
foreach (array_slice($lines, 0, 5) as $line) {
    if (trim($line) === '') continue;
    $sampleRows[] = str_getcsv((string) $line, $delimiter);
}

$sessionId = \Punto\Api\Imports\ImportSession::create(
    $companyId,
    $userId,
    $csvPath,
    $originalName,
    $columns,
    $rowCount,
    $sampleRows
);

apiOk([
    'sessionId' => $sessionId,
    'columns'   => $columns,
    'rowCount'  => $rowCount,
    'sample'    => $sampleRows,
    'filename'  => $originalName,
]);
