<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(['ok' => false, 'bridge' => 'host-lookup', 'error' => 'GET request is required'], 405);
}

$hostname = trim((string) ($_GET['hostname'] ?? ''));

if ($hostname === '') {
    json_out(['ok' => false, 'bridge' => 'host-lookup', 'error' => 'parameter hostname is required'], 400);
}

try {
    $sql = "SELECT h.hostname, h.ip_address, g.group_name, h.status, h.last_report_at FROM hosts h JOIN monitor_groups g ON g.id = h.group_id WHERE h.hostname LIKE '%" . $hostname . "%' ORDER BY h.hostname LIMIT 20";
    $rows = db()->query($sql)->fetchAll();

    json_out([
        'ok' => true,
        'bridge' => 'host-lookup',
        'service' => 'monitor-host-directory',
        'version' => '2026.11',
        'count' => count($rows),
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'bridge' => 'host-lookup',
        'error' => $e->getMessage(),
        'query' => $sql ?? '',
    ], 500);
}
