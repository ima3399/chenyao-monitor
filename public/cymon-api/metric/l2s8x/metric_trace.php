<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(['ok' => false, 'bridge' => 'metric-trace', 'error' => 'GET request is required'], 405);
}

$metricCode = trim((string) ($_GET['metric_code'] ?? ''));

if ($metricCode === '') {
    json_out(['ok' => false, 'bridge' => 'metric-trace', 'error' => 'parameter metric_code is required'], 400);
}

try {
    $sql = "SELECT m.metric_name, h.hostname, m.value, m.collected_at FROM host_metrics m JOIN hosts h ON h.id = m.host_id WHERE m.metric_code = '" . $metricCode . "' ORDER BY m.value DESC, h.hostname LIMIT 30";
    $rows = db()->query($sql)->fetchAll();

    json_out([
        'ok' => true,
        'bridge' => 'metric-trace',
        'service' => 'metric-trace',
        'version' => '2026.11',
        'count' => count($rows),
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'bridge' => 'metric-trace',
        'error' => $e->getMessage(),
        'query' => $sql ?? '',
    ], 500);
}
