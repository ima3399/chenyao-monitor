<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$user = current_user();

$hostTotal = (int) $pdo->query('SELECT COUNT(*) FROM hosts')->fetchColumn();
$hostOnline = (int) $pdo->query("SELECT COUNT(*) FROM hosts WHERE status = '在线'")->fetchColumn();
$hostWarning = (int) $pdo->query("SELECT COUNT(*) FROM hosts WHERE status = '警告'")->fetchColumn();
$hostOffline = (int) $pdo->query("SELECT COUNT(*) FROM hosts WHERE status = '离线'")->fetchColumn();
$hostMaintenance = (int) $pdo->query("SELECT COUNT(*) FROM hosts WHERE status = '维护'")->fetchColumn();
$hostAvailable = $hostOnline + $hostWarning;

$activeAlerts = (int) $pdo->query("SELECT COUNT(*) FROM alerts WHERE status IN ('活跃', '已确认')")->fetchColumn();
$criticalAlerts = (int) $pdo->query("SELECT COUNT(*) FROM alerts WHERE severity = '严重' AND status IN ('活跃', '已确认')")->fetchColumn();
$openTickets = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('待处理', '处理中')")->fetchColumn();
$urgentTickets = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE priority = '紧急' AND status IN ('待处理', '处理中')")->fetchColumn();
$agentTotal = (int) $pdo->query('SELECT COUNT(*) FROM agents')->fetchColumn();
$agentRunning = (int) $pdo->query("SELECT COUNT(*) FROM agents WHERE status = '运行中'")->fetchColumn();
$agentAttention = $agentTotal - $agentRunning;

$cpuAvg = (float) $pdo->query("SELECT COALESCE(AVG(value), 0) FROM host_metrics WHERE metric_code = 'CPU_USAGE' AND status != '未知'")->fetchColumn();
$memAvg = (float) $pdo->query("SELECT COALESCE(AVG(value), 0) FROM host_metrics WHERE metric_code = 'MEMORY_USAGE' AND status != '未知'")->fetchColumn();
$diskAvg = (float) $pdo->query("SELECT COALESCE(AVG(value), 0) FROM host_metrics WHERE metric_code = 'DISK_USAGE' AND status != '未知'")->fetchColumn();
$lastCollected = (string) $pdo->query('SELECT MAX(collected_at) FROM host_metrics')->fetchColumn();

$seriesStmt = $pdo->query("
    SELECT sampled_at, metric_code, ROUND(AVG(value), 2) AS avg_value
    FROM metric_samples
    WHERE metric_code IN ('CPU_USAGE', 'MEMORY_USAGE')
      AND sampled_at >= datetime('2026-11-10 10:00:00', '-11 hours')
    GROUP BY sampled_at, metric_code
    ORDER BY sampled_at ASC, metric_code ASC
");
$seriesRaw = $seriesStmt->fetchAll();
$labels = [];
$cpuSeries = [];
$memSeries = [];
$seriesByTime = [];
foreach ($seriesRaw as $row) {
    $time = (string) $row['sampled_at'];
    $seriesByTime[$time][$row['metric_code']] = (float) $row['avg_value'];
}
foreach ($seriesByTime as $time => $values) {
    $labels[] = date('H:i', strtotime($time));
    $cpuSeries[] = isset($values['CPU_USAGE']) ? round($values['CPU_USAGE'], 1) : null;
    $memSeries[] = isset($values['MEMORY_USAGE']) ? round($values['MEMORY_USAGE'], 1) : null;
}
$cpuPeakValues = array_values(array_filter($cpuSeries, static fn ($value) => $value !== null));
$memPeakValues = array_values(array_filter($memSeries, static fn ($value) => $value !== null));
$cpuPeak = $cpuPeakValues ? max($cpuPeakValues) : 0;
$memPeak = $memPeakValues ? max($memPeakValues) : 0;
$chartConfig = [
    'labels' => $labels,
    'series' => [
        ['name' => 'CPU 使用率', 'color' => '#f0a94b', 'values' => $cpuSeries],
        ['name' => '内存使用率', 'color' => '#2dd4bf', 'values' => $memSeries],
    ],
    'min' => 0,
    'max' => 100,
    'unit' => '%',
    'thresholds' => [
        ['value' => 85, 'label' => 'CPU 预警线 85%', 'color' => 'rgba(255, 107, 107, 0.42)'],
    ],
];

$severityRows = $pdo->query("SELECT severity, COUNT(*) AS count FROM alerts GROUP BY severity")->fetchAll();
$severityCounts = ['严重' => 0, '警告' => 0, '提示' => 0];
foreach ($severityRows as $row) {
    $severityCounts[$row['severity']] = (int) $row['count'];
}
$alertTotal = array_sum($severityCounts);
$donutSegments = [];
$degree = 0.0;
$severityColors = ['严重' => '#ff6b6b', '警告' => '#f0a94b', '提示' => '#6ea8fe'];
foreach ($severityCounts as $severity => $count) {
    $span = $alertTotal > 0 ? $count / $alertTotal * 360 : 0;
    if ($span > 0) {
        $donutSegments[] = $severityColors[$severity] . ' ' . round($degree, 2) . 'deg ' . round($degree + $span, 2) . 'deg';
    }
    $degree += $span;
}
$donutStyle = $donutSegments
    ? 'background: conic-gradient(' . implode(', ', $donutSegments) . ');'
    : 'background: conic-gradient(#25303c 0deg 360deg);';

$hostRows = $pdo->query("
    SELECT h.id, h.hostname, h.ip_address, h.status, h.last_report_at, h.agent_version,
           g.group_name, g.color,
           cpu.value AS cpu_value,
           mem.value AS mem_value,
           disk.value AS disk_value,
           a.status AS agent_status
    FROM hosts h
    JOIN monitor_groups g ON g.id = h.group_id
    LEFT JOIN host_metrics cpu ON cpu.host_id = h.id AND cpu.metric_code = 'CPU_USAGE'
    LEFT JOIN host_metrics mem ON mem.host_id = h.id AND mem.metric_code = 'MEMORY_USAGE'
    LEFT JOIN host_metrics disk ON disk.host_id = h.id AND disk.metric_code = 'DISK_USAGE'
    LEFT JOIN agents a ON a.host_id = h.id
")->fetchAll();
$hostPriority = ['离线' => 0, '警告' => 1, '维护' => 2, '在线' => 3];
usort($hostRows, static function (array $a, array $b) use ($hostPriority): int {
    $priorityCompare = ($hostPriority[$a['status']] ?? 9) <=> ($hostPriority[$b['status']] ?? 9);
    if ($priorityCompare !== 0) {
        return $priorityCompare;
    }
    return (float) $b['cpu_value'] <=> (float) $a['cpu_value'];
});
$focusHosts = array_slice($hostRows, 0, 6);

$latestAlerts = $pdo->query("
    SELECT a.id, a.alert_no, a.title, a.severity, a.status, a.started_at, h.hostname
    FROM alerts a
    JOIN hosts h ON h.id = a.host_id
    ORDER BY a.started_at DESC
    LIMIT 5
")->fetchAll();

$queueTickets = $pdo->query("
    SELECT ticket_no, title, priority, status, assignee, updated_at
    FROM tickets
    WHERE status IN ('待处理', '处理中')
    ORDER BY CASE priority WHEN '紧急' THEN 1 WHEN '高' THEN 2 WHEN '中' THEN 3 ELSE 4 END, updated_at DESC
    LIMIT 4
")->fetchAll();

$events = $pdo->query('SELECT * FROM system_events ORDER BY created_at DESC LIMIT 5')->fetchAll();

$hour = (int) date('G');
$greeting = $hour < 11 ? '上午好' : ($hour < 14 ? '中午好' : ($hour < 18 ? '下午好' : '晚上好'));

$pageTitle = '工作台';
$active = 'dashboard';
require __DIR__ . '/partials/header.php';
?>
<div class="welcome-banner">
    <div class="welcome-main">
        <span class="welcome-eyebrow"><i class="pulse-dot"></i> <?= e(APP_ZONE) ?> · 运行态势实时更新</span>
        <h2><?= e($greeting) ?>，<?= e($user['real_name']) ?></h2>
        <p>今天是 <?= e(date('Y年n月j日')) ?>，目前有 <?= $activeAlerts ?> 条告警待处置，<?= $openTickets ?> 个事件工单正在跟进。</p>
    </div>
    <div class="welcome-side">
        <a class="btn btn-ghost" href="/reports.php"><?= icon_svg('reports', 15) ?> 查看运行报表</a>
        <a class="btn btn-primary" href="/tickets.php?new=1"><?= icon_svg('plus', 15) ?> 新建事件工单</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card tone-amber">
        <div class="stat-top">
            <span class="stat-label">纳管主机</span>
            <span class="stat-icon"><?= icon_svg('hosts', 17) ?></span>
        </div>
        <div class="stat-value"><?= $hostTotal ?><small>台</small></div>
        <div class="stat-foot"><?= $hostOnline ?> 在线 · <?= $hostWarning ?> 告警 · <?= $hostMaintenance ?> 维护</div>
    </div>
    <div class="stat-card tone-teal">
        <div class="stat-top">
            <span class="stat-label">主机可用率</span>
            <span class="stat-icon"><?= icon_svg('activity', 17) ?></span>
        </div>
        <div class="stat-value"><?= percent($hostAvailable, $hostTotal) ?></div>
        <div class="stat-foot"><span class="tone-up"><?= $hostAvailable ?>/<?= $hostTotal ?></span> 台可用 · <?= $hostOffline ?> 台离线</div>
    </div>
    <div class="stat-card tone-red">
        <div class="stat-top">
            <span class="stat-label">未恢复告警</span>
            <span class="stat-icon"><?= icon_svg('alerts', 17) ?></span>
        </div>
        <div class="stat-value"><?= $activeAlerts ?><small>条</small></div>
        <div class="stat-foot"><span class="tone-down"><?= $criticalAlerts ?> 条严重</span> · 需优先处置</div>
    </div>
    <div class="stat-card tone-blue">
        <div class="stat-top">
            <span class="stat-label">事件工单</span>
            <span class="stat-icon"><?= icon_svg('tickets', 17) ?></span>
        </div>
        <div class="stat-value"><?= $openTickets ?><small>个</small></div>
        <div class="stat-foot"><?= $urgentTickets ?> 个紧急 · 平均响应 18 分钟</div>
    </div>
</div>

<div class="dashboard-grid">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('metrics', 16) ?></span> 近 12 小时资源趋势</h3>
                <p class="panel-sub">全量在线主机 CPU 与内存使用率小时均值 · 最后采集 <?= e(fmt_time($lastCollected)) ?></p>
            </div>
            <div class="panel-actions">
                <div class="legend">
                    <span class="legend-item" style="color:#f0a94b"><i class="legend-dot"></i>CPU</span>
                    <span class="legend-item" style="color:#2dd4bf"><i class="legend-dot"></i>内存</span>
                </div>
                <a class="btn btn-sm btn-ghost" href="/metrics.php"><?= icon_svg('arrow', 13) ?> 指标分析</a>
            </div>
        </div>
        <div class="panel-body">
            <div class="chart-wrap">
                <canvas class="chart-canvas" data-chart="<?= e(json_encode($chartConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" aria-label="资源趋势图"></canvas>
            </div>
            <div class="chart-summary">
                <div class="chart-summary-item"><span>当前 CPU 均值</span><strong><?= number_format($cpuAvg, 1) ?>%</strong></div>
                <div class="chart-summary-item"><span>当前内存均值</span><strong><?= number_format($memAvg, 1) ?>%</strong></div>
                <div class="chart-summary-item"><span>12 小时 CPU 峰值</span><strong><?= number_format((float) $cpuPeak, 1) ?>%</strong></div>
                <div class="chart-summary-item"><span>12 小时内存峰值</span><strong><?= number_format((float) $memPeak, 1) ?>%</strong></div>
            </div>
        </div>
    </section>

    <section class="panel donut-panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('shield', 16) ?></span> 告警等级分布</h3>
                <p class="panel-sub">当前统计周期内的全部告警</p>
            </div>
            <a class="link-action" href="/alerts.php">全部记录 <?= icon_svg('chevron', 11) ?></a>
        </div>
        <div class="donut-body">
            <div class="donut" style="<?= e($donutStyle) ?>">
                <div class="donut-center">
                    <strong><?= $alertTotal ?></strong>
                    <span>告警总数</span>
                </div>
            </div>
            <div class="severity-list">
                <div class="severity-item is-danger">
                    <span><i class="dot"></i>严重</span>
                    <strong><?= $severityCounts['严重'] ?></strong>
                </div>
                <div class="severity-item is-warning">
                    <span><i class="dot"></i>警告</span>
                    <strong><?= $severityCounts['警告'] ?></strong>
                </div>
                <div class="severity-item is-info">
                    <span><i class="dot"></i>提示</span>
                    <strong><?= $severityCounts['提示'] ?></strong>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="dashboard-grid">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('server', 16) ?></span> 重点关注主机</h3>
                <p class="panel-sub">按运行状态与 CPU 水位排序</p>
            </div>
            <div class="panel-actions">
                <a class="btn btn-sm btn-ghost" href="/hosts.php">全部主机 <?= icon_svg('arrow', 13) ?></a>
            </div>
        </div>
        <div class="panel-body is-flush">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>主机</th>
                            <th>分组</th>
                            <th>CPU</th>
                            <th>内存</th>
                            <th>状态</th>
                            <th>最后上报</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($focusHosts as $host): ?>
                        <?php
                        $cpu = (float) $host['cpu_value'];
                        $cpuTone = $cpu >= 90 ? 'is-danger' : ($cpu >= 75 ? 'is-warning' : '');
                        ?>
                        <tr>
                            <td>
                                <div class="host-cell">
                                    <span class="host-avatar"><?= e(substr($host['hostname'], -2)) ?></span>
                                    <span class="host-cell-body">
                                        <strong><?= e($host['hostname']) ?></strong>
                                        <span><?= e($host['ip_address']) ?></span>
                                    </span>
                                </div>
                            </td>
                            <td><span class="group-chip"><i style="background:<?= e($host['color']) ?>"></i><?= e($host['group_name']) ?></span></td>
                            <td>
                                <div class="mini-health">
                                    <div class="mini-health-bar"><i class="<?= $cpuTone ?>" style="width:<?= min(100, $cpu) ?>%"></i></div>
                                    <span><?= number_format($cpu, 1) ?>%</span>
                                </div>
                            </td>
                            <td><?= number_format((float) $host['mem_value'], 1) ?>%</td>
                            <td><span class="badge badge-<?= e(host_status_class($host['status'])) ?>"><?= e($host['status']) ?></span></td>
                            <td class="muted nowrap"><?= e(relative_time($host['last_report_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('alerts', 16) ?></span> 最新告警</h3>
                <p class="panel-sub">按触发时间倒序</p>
            </div>
            <a class="link-action" href="/alerts.php">更多 <?= icon_svg('chevron', 11) ?></a>
        </div>
        <div class="panel-body">
            <div class="alert-mini-list">
                <?php foreach ($latestAlerts as $alert): ?>
                    <div class="alert-mini">
                        <span class="alert-mini-dot <?= $alert['severity'] === '严重' ? 'is-danger' : ($alert['severity'] === '提示' ? 'is-info' : '') ?>"></span>
                        <div class="alert-mini-body">
                            <div class="alert-mini-title">
                                <strong><?= e($alert['title']) ?></strong>
                                <time><?= e(relative_time($alert['started_at'])) ?></time>
                            </div>
                            <div class="alert-mini-meta">
                                <span><?= e($alert['hostname']) ?></span>
                                <span class="badge badge-<?= e(alert_status_class($alert['status'])) ?> no-dot"><?= e($alert['status']) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>

<div class="dashboard-grid equal">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('tickets', 16) ?></span> 工单处置队列</h3>
                <p class="panel-sub">待处理与处理中的优先事项</p>
            </div>
            <a class="link-action" href="/tickets.php">工单中心 <?= icon_svg('chevron', 11) ?></a>
        </div>
        <div class="panel-body is-flush">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>工单</th>
                            <th>优先级</th>
                            <th>责任人</th>
                            <th>状态</th>
                            <th>更新</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$queueTickets): ?>
                        <tr><td colspan="5"><div class="empty-state">当前没有待处理工单</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($queueTickets as $ticket): ?>
                        <tr>
                            <td>
                                <a class="table-primary" href="/tickets.php?ticket=<?= e(urlencode($ticket['ticket_no'])) ?>"><?= e($ticket['title']) ?></a>
                                <span class="row-sub"><?= e($ticket['ticket_no']) ?></span>
                            </td>
                            <td><span class="badge badge-<?= e(priority_class($ticket['priority'])) ?>"><?= e($ticket['priority']) ?></span></td>
                            <td><?= e($ticket['assignee']) ?></td>
                            <td><span class="badge badge-<?= e(ticket_status_class($ticket['status'])) ?> no-dot"><?= e($ticket['status']) ?></span></td>
                            <td class="muted nowrap"><?= e(relative_time($ticket['updated_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('activity', 16) ?></span> 运行动态</h3>
                <p class="panel-sub">平台最近的关键操作与事件</p>
            </div>
        </div>
        <div class="panel-body">
            <ul class="activity-list">
                <?php foreach ($events as $index => $event): ?>
                    <li class="activity-item <?= $index === 0 ? 'is-amber' : ($event['event_type'] === 'agent' ? 'is-teal' : ($event['event_type'] === 'alert' ? 'is-red' : '')) ?>">
                        <div class="activity-title"><?= e($event['title']) ?></div>
                        <div class="activity-meta"><?= e($event['actor']) ?> · <?= e(relative_time($event['created_at'])) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
