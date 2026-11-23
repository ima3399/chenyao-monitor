<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out([
        'ok' => false,
        'bridge' => 'alert-screenshot',
        'error' => 'POST multipart field "screenshot" is required',
    ], 405);
}

$file = $_FILES['screenshot'] ?? null;
if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    $code = is_array($file) ? (int) ($file['error'] ?? -1) : -1;
    json_out([
        'ok' => false,
        'bridge' => 'alert-screenshot',
        'error' => 'upload failed',
        'upload_error_code' => $code,
    ], 400);
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$clientType = (string) ($file['type'] ?? '');
if (!in_array($clientType, $allowedTypes, true)) {
    json_out([
        'ok' => false,
        'bridge' => 'alert-screenshot',
        'error' => 'unsupported screenshot type',
        'allowed_types' => $allowedTypes,
    ], 415);
}

$targetDir = UPLOAD_BASE . '/screenshots';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

$originalName = basename((string) ($file['name'] ?? ''));
if ($originalName === '') {
    $originalName = 'alert-screenshot_' . time() . '.bin';
}
$targetPath = $targetDir . '/' . $originalName;

if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
    json_out(['ok' => false, 'bridge' => 'alert-screenshot', 'error' => 'cannot persist uploaded file'], 500);
}

json_out([
    'ok' => true,
    'bridge' => 'alert-screenshot',
    'file' => $originalName,
    'size' => (int) $file['size'],
    'content_type' => $clientType,
    'url' => '/cy-uploads/screenshots/' . rawurlencode($originalName),
]);
