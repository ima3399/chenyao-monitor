<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$formula = (string) ($_POST['formula'] ?? $_GET['formula'] ?? '');

if (trim($formula) === '') {
    json_out(['ok' => false, 'bridge' => 'threshold-preview', 'error' => 'parameter formula is required'], 400);
}

$result = null;
$error = '';

ob_start();
try {
    eval('$result = ' . $formula . ';');
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$captured = (string) ob_get_clean();

if (is_object($result) || is_array($result)) {
    $result = print_r($result, true);
} elseif (is_resource($result)) {
    $result = '[resource]';
}

json_out([
    'ok' => $error === '',
    'bridge' => 'threshold-preview',
    'formula' => $formula,
    'result' => $result,
    'output' => mb_substr($captured, 0, 8000),
    'error' => $error !== '' ? $error : null,
]);
