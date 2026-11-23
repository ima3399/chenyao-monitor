<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out([
        'ok' => false,
        'bridge' => 'agent-package-upload',
        'error' => 'POST multipart field "agent" is required',
    ], 405);
}

$file = $_FILES['agent'] ?? null;
if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    $code = is_array($file) ? (int) ($file['error'] ?? -1) : -1;
    json_out([
        'ok' => false,
        'bridge' => 'agent-package-upload',
        'error' => 'upload failed',
        'upload_error_code' => $code,
    ], 400);
}

$targetDir = UPLOAD_BASE . '/agents';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

$originalName = basename((string) ($file['name'] ?? ''));
if ($originalName === '') {
    $originalName = 'agent-package.bin';
}
$targetPath = $targetDir . '/' . $originalName;

if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
    json_out(['ok' => false, 'bridge' => 'agent-package-upload', 'error' => 'cannot persist uploaded file'], 500);
}

json_out([
    'ok' => true,
    'bridge' => 'agent-package-upload',
    'file' => $originalName,
    'size' => (int) $file['size'],
    'content_type' => (string) ($file['type'] ?? ''),
    'url' => '/cy-uploads/agents/' . rawurlencode($originalName),
]);
