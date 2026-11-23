<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$type = q('type', 'hosts');
$allowedTypes = ['hosts', 'alerts', 'tickets', 'agents', 'metrics'];
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(404);
    exit('报表类型不存在。');
}

$groupId = query_int('group');
$metricCode = q('metric');
$filename = 'chenyao-monitor-' . $type . '-' . date('Ymd-His') . '.csv';

$safeCell = static function ($value): string {
    $text = (string) $value;
    if ($text !== '' && str_contains('=+-@', $text[0])) {
        return "'" . $text;
    }
    return $text;
};

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

if ($type === 'hosts') {
    fputcsv($output, ['主机名', 'IP地址', '监控分组', '运行状态', '操作系统', 'CPU核数', '内存GB', '磁盘GB', '机房位置', '环境', 'CPU使用率', '内存使用率', '磁盘使用率', '代理状态', '最后上报时间']);
    $sql = "
        SELECT h.hostname, h.ip_address, g.group_name, h.status, h.os_name, h.cpu_cores, h.memory_gb, h.disk_gb,
               h.location, h.environment, cpu.value AS cpu_value, mem.value AS mem_value, disk.value AS disk_value,
               a.status AS agent_status, h.last_report_at
        FROM hosts h
        JOIN monitor_groups g ON g.id = h.group_id
        LEFT JOIN host_metrics cpu ON cpu.host_id = h.id AND cpu.metric_code = 'CPU_USAGE'
        LEFT JOIN host_metrics mem ON mem.host_id = h.id AND mem.metric_code = 'MEMORY_USAGE'
        LEFT JOIN host_metrics disk ON disk.host_id = h.id AND disk.metric_code = 'DISK_USAGE'
        LEFT JOIN agents a ON a.host_id = h.id
    ";
    $params = [];
    if ($groupId > 0) {
        $sql .= ' WHERE h.group_id = ?';
        $params[] = $groupId;
    }
    $sql .= ' ORDER BY g.sort_order, h.hostname';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        fputcsv($output, array_map($safeCell, [
            $row['hostname'], $row['ip_address'], $row['group_name'], $row['status'], $row['os_name'],
            $row['cpu_cores'], $row['memory_gb'], $row['disk_gb'], $row['location'], $row['environment'],
            number_format((float) $row['cpu_value'], 1) . '%',
            number_format((float) $row['mem_value'], 1) . '%',
            number_format((float) $row['disk_value'], 1) . '%',
            $row['agent_status'] ?: '未注册', $row['last_report_at'],
        ]));
    }
} elseif ($type === 'alerts') {
    fputcsv($output, ['告警编号', '告警标题', '主机', '监控分组', '告警级别', '命中策略', '策略编号', '当前值', '阈值', '状态', '触发时间', '确认人', '确认时间', '恢复人', '恢复时间']);
    $sql = "
        SELECT a.*, h.hostname, g.group_name, p.policy_name, p.policy_code
        FROM alerts a
        JOIN hosts h ON h.id = a.host_id
        JOIN monitor_groups g ON g.id = h.group_id
        JOIN alert_policies p ON p.id = a.policy_id
    ";
    $params = [];
    if ($groupId > 0) {
        $sql .= ' WHERE h.group_id = ?';
        $params[] = $groupId;
    }
    $sql .= ' ORDER BY a.started_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        fputcsv($output, array_map($safeCell, [
            $row['alert_no'], $row['title'], $row['hostname'], $row['group_name'], $row['severity'],
            $row['policy_name'], $row['policy_code'], $row['current_value'], $row['threshold_value'], $row['status'],
            $row['started_at'], $row['acknowledged_by'], $row['acknowledged_at'], $row['resolved_by'], $row['resolved_at'],
        ]));
    }
} elseif ($type === 'tickets') {
    fputcsv($output, ['工单编号', '工单标题', '问题分类', '优先级', '状态', '来源', '责任人', '报告人', '关联告警', '关联主机', '创建时间', '最近更新', '恢复时间']);
    $stmt = $pdo->query("
        SELECT t.*, a.alert_no, h.hostname
        FROM tickets t
        LEFT JOIN alerts a ON a.id = t.alert_id
        LEFT JOIN hosts h ON h.id = a.host_id
        ORDER BY t.created_at DESC
    ");
    foreach ($stmt->fetchAll() as $row) {
        fputcsv($output, array_map($safeCell, [
            $row['ticket_no'], $row['title'], $row['category'], $row['priority'], $row['status'], $row['source'],
            $row['assignee'], $row['reporter'], $row['alert_no'], $row['hostname'], $row['created_at'], $row['updated_at'], $row['resolved_at'],
        ]));
    }
} elseif ($type === 'agents') {
    fputcsv($output, ['代理编号', '主机', 'IP地址', '监控分组', '代理版本', '部署方式', '平台', '状态', '采集范围', '最近心跳', '注册时间', '令牌掩码']);
    $stmt = $pdo->query("
        SELECT a.*, h.hostname, g.group_name
        FROM agents a
        JOIN hosts h ON h.id = a.host_id
        JOIN monitor_groups g ON g.id = h.group_id
        ORDER BY h.hostname
    ");
    foreach ($stmt->fetchAll() as $row) {
        fputcsv($output, array_map($safeCell, [
            $row['agent_id'], $row['hostname'], $row['ip_address'], $row['group_name'], 'v' . $row['version'],
            $row['install_type'], $row['platform'], $row['status'], $row['collector_scope'],
            $row['last_heartbeat_at'], $row['registered_at'], $row['token_hint'],
        ]));
    }
} else {
    fputcsv($output, ['指标名称', '指标编码', '主机', 'IP地址', '监控分组', '当前值', '单位', '预警阈值', '严重阈值', '状态', '采集时间']);
    $knownMetrics = $pdo->query('SELECT metric_code FROM metric_catalog')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($metricCode, $knownMetrics, true)) {
        $metricCode = '';
    }
    $sql = "
        SELECT hm.metric_name, hm.metric_code, h.hostname, h.ip_address, g.group_name,
               hm.value, hm.unit, hm.warn_threshold, hm.critical_threshold, hm.status, hm.collected_at
        FROM host_metrics hm
        JOIN hosts h ON h.id = hm.host_id
        JOIN monitor_groups g ON g.id = h.group_id
    ";
    $params = [];
    if ($metricCode !== '') {
        $sql .= ' WHERE hm.metric_code = ?';
        $params[] = $metricCode;
    }
    $sql .= ' ORDER BY hm.metric_code, hm.value DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        fputcsv($output, array_map($safeCell, [
            $row['metric_name'], $row['metric_code'], $row['hostname'], $row['ip_address'], $row['group_name'],
            $row['value'], $row['unit'], $row['warn_threshold'], $row['critical_threshold'], $row['status'], $row['collected_at'],
        ]));
    }
}

fclose($output);
exit;
