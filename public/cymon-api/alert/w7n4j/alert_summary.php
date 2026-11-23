<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(['ok' => false, 'bridge' => 'monitor-alert-summary', 'error' => 'GET request is required'], 405);
}

$policyCode = trim((string) ($_GET['policy_code'] ?? ''));

if ($policyCode === '') {
    json_out(['ok' => false, 'bridge' => 'monitor-alert-summary', 'error' => 'parameter policy_code is required'], 400);
}

try {
    $sql = "SELECT a.severity AS level, COUNT(*) AS alert_count, MAX(a.started_at) AS latest_at FROM alerts a JOIN alert_policies p ON p.id = a.policy_id WHERE p.policy_code = '" . $policyCode . "' GROUP BY a.severity ORDER BY alert_count DESC";
    $rows = db()->query($sql)->fetchAll();

    json_out([
        'ok' => true,
        'bridge' => 'monitor-alert-summary',
        'service' => 'alert-summary',
        'version' => '2026.11',
        'count' => count($rows),
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'bridge' => 'monitor-alert-summary',
        'error' => $e->getMessage(),
        'query' => $sql ?? '',
    ], 500);
}
