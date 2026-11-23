<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$groups = $pdo->query('SELECT id, group_name, color FROM monitor_groups ORDER BY sort_order')->fetchAll();
$groupId = query_int('group');
$validGroupIds = array_map(static fn (array $group): int => (int) $group['id'], $groups);
if ($groupId > 0 && !in_array($groupId, $validGroupIds, true)) {
    $groupId = 0;
}

$periodOptions = [7 => '近 7 日', 30 => '近 30 日', 90 => '近 90 日'];
$period = query_int('period', 7);
if (!isset($periodOptions[$period])) {
    $period = 7;
}

$referenceAt = (string) $pdo->query('SELECT MAX(sampled_at) FROM metric_samples')->fetchColumn();
$referenceAt = $referenceAt !== '' ? $referenceAt : date('Y-m-d H:i:s');
$referenceDay = date('Y-m-d', strtotime($referenceAt));
$startDay = date('Y-m-d', strtotime($referenceDay . ' -' . ($period - 1) . ' days'));
$startAt = $startDay . ' 00:00:00';
$endAt = $referenceDay . ' 23:59:59';
$groupName = '全部分组';
if ($groupId > 0) {
    foreach ($groups as $group) {
        if ((int) $group['id'] === $groupId) {
            $groupName = $group['group_name'];
            break;
        }
    }
}

$hostSql = 'SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN status IN (\'在线\', \'警告\') THEN 1 ELSE 0 END), 0) AS available, COALESCE(SUM(CASE WHEN status = \'离线\' THEN 1 ELSE 0 END), 0) AS offline FROM hosts';
$hostParams = [];
if ($groupId > 0) {
    $hostSql .= ' WHERE group_id = ?';
    $hostParams[] = $groupId;
}
$hostStmt = $pdo->prepare($hostSql);
$hostStmt->execute($hostParams);
$hostSummary = $hostStmt->fetch();
$availability = (int) $hostSummary['total'] > 0 ? (int) $hostSummary['available'] / (int) $hostSummary['total'] * 100 : 0;

$alertWhere = 'a.started_at >= ? AND a.started_at <= ?';
$alertParams = [$startAt, $endAt];
if ($groupId > 0) {
    $alertWhere .= ' AND h.group_id = ?';
    $alertParams[] = $groupId;
}
$alertStmt = $pdo->prepare("
    SELECT COUNT(*) AS total,
           COALESCE(SUM(CASE WHEN a.status = '已恢复' THEN 1 ELSE 0 END), 0) AS resolved,
           COALESCE(SUM(CASE WHEN a.status IN ('活跃', '已确认') THEN 1 ELSE 0 END), 0) AS unresolved,
           COALESCE(SUM(CASE WHEN a.severity = '严重' THEN 1 ELSE 0 END), 0) AS critical
    FROM alerts a JOIN hosts h ON h.id = a.host_id
    WHERE {$alertWhere}
");
$alertStmt->execute($alertParams);
$alertSummary = $alertStmt->fetch();
$resolutionRate = (int) $alertSummary['total'] > 0 ? (int) $alertSummary['resolved'] / (int) $alertSummary['total'] * 100 : 0;

$severityStmt = $pdo->prepare("
    SELECT a.severity, COUNT(*) AS count
    FROM alerts a JOIN hosts h ON h.id = a.host_id
    WHERE {$alertWhere}
    GROUP BY a.severity
");
$severityStmt->execute($alertParams);
$severityCounts = ['严重' => 0, '警告' => 0, '提示' => 0];
foreach ($severityStmt->fetchAll() as $row) {
    $severityCounts[$row['severity']] = (int) $row['count'];
}

$ticketSummaryStmt = $pdo->prepare("
    SELECT COUNT(*) AS total,
           COALESCE(SUM(CASE WHEN status IN ('已解决', '已关闭') THEN 1 ELSE 0 END), 0) AS done,
           COALESCE(SUM(CASE WHEN status IN ('待处理', '处理中') THEN 1 ELSE 0 END), 0) AS open_count
    FROM tickets WHERE created_at >= ? AND created_at <= ?
");
$ticketSummaryStmt->execute([$startAt, $endAt]);
$ticketSummary = $ticketSummaryStmt->fetch();
$ticketDoneRate = (int) $ticketSummary['total'] > 0 ? (int) $ticketSummary['done'] / (int) $ticketSummary['total'] * 100 : 0;

$agentStmt = $pdo->prepare("
    SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN a.status = '运行中' THEN 1 ELSE 0 END), 0) AS running
    FROM agents a JOIN hosts h ON h.id = a.host_id
    " . ($groupId > 0 ? 'WHERE h.group_id = ?' : ''));
$agentStmt->execute($groupId > 0 ? [$groupId] : []);
$agentSummary = $agentStmt->fetch();

$dayLabels = [];
$dayKeys = [];
for ($index = $period - 1; $index >= 0; $index--) {
    $timestamp = strtotime($referenceDay . ' -' . $index . ' days');
    $key = date('Y-m-d', $timestamp);
    $dayKeys[] = $key;
    $dayLabels[] = date('m-d', $timestamp);
}
$alertTrendStmt = $pdo->prepare("
    SELECT date(a.started_at) AS day, COUNT(*) AS count
    FROM alerts a JOIN hosts h ON h.id = a.host_id
    WHERE {$alertWhere}
    GROUP BY date(a.started_at)
");
$alertTrendStmt->execute($alertParams);
$alertTrendMap = [];
foreach ($alertTrendStmt->fetchAll() as $row) {
    $alertTrendMap[$row['day']] = (int) $row['count'];
}
$alertTrend = [];
foreach ($dayKeys as $day) {
    $alertTrend[] = $alertTrendMap[$day] ?? 0;
}

$ticketTrendStmt = $pdo->prepare("
    SELECT date(created_at) AS day, COUNT(*) AS count
    FROM tickets
    WHERE created_at >= ? AND created_at <= ?
    GROUP BY date(created_at)
");
$ticketTrendStmt->execute([$startAt, $endAt]);
$ticketTrendMap = [];
foreach ($ticketTrendStmt->fetchAll() as $row) {
    $ticketTrendMap[$row['day']] = (int) $row['count'];
}
$ticketTrend = [];
foreach ($dayKeys as $day) {
    $ticketTrend[] = $ticketTrendMap[$day] ?? 0;
}

$chartConfig = [
    'labels' => $dayLabels,
    'series' => [
        ['name' => '告警', 'color' => '#f0a94b', 'values' => $alertTrend],
        ['name' => '工单', 'color' => '#2dd4bf', 'values' => $ticketTrend],
    ],
    'min' => 0,
    'max' => null,
    'unit' => '条',
];
$totalAlertsInPeriod = array_sum($alertTrend);
$totalTicketsInPeriod = array_sum($ticketTrend);

$reviewSql = "
    SELECT g.id, g.group_name, g.color, g.owner,
           COUNT(DISTINCT h.id) AS host_count,
           COALESCE(SUM(CASE WHEN h.status IN ('在线', '警告') THEN 1 ELSE 0 END), 0) AS available_count,
           (SELECT COUNT(*) FROM alerts a2 JOIN hosts h2 ON h2.id = a2.host_id
            WHERE h2.group_id = g.id AND a2.status IN ('活跃', '已确认')) AS open_alerts,
           (SELECT COUNT(*) FROM agents ag JOIN hosts h3 ON h3.id = ag.host_id
            WHERE h3.group_id = g.id AND ag.status = '运行中') AS running_agents
    FROM monitor_groups g
    LEFT JOIN hosts h ON h.group_id = g.id
";
if ($groupId > 0) {
    $reviewSql .= ' WHERE g.id = ?';
}
$reviewSql .= ' GROUP BY g.id ORDER BY g.sort_order';
$reviewStmt = $pdo->prepare($reviewSql);
$reviewStmt->execute($groupId > 0 ? [$groupId] : []);
$groupOverview = $reviewStmt->fetchAll();

$donutResolved = (int) $alertSummary['resolved'];
$donutUnresolved = (int) $alertSummary['unresolved'];
$donutTotal = $donutResolved + $donutUnresolved;
$resolvedDegrees = $donutTotal > 0 ? $donutResolved / $donutTotal * 360 : 0;
$donutStyle = $donutTotal > 0
    ? 'background: conic-gradient(var(--teal) 0deg ' . round($resolvedDegrees, 2) . 'deg, var(--amber) ' . round($resolvedDegrees, 2) . 'deg 360deg);'
    : 'background: conic-gradient(#25303c 0deg 360deg);';

$exportQuery = $groupId > 0 ? '&amp;group=' . $groupId : '';
$pageTitle = '报表中心';
$active = 'reports';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <div class="page-eyebrow">Operations Reporting</div>
        <h1 class="page-title">报表中心</h1>
        <p class="page-sub">汇总主机可用性、告警处置、工单效率与采集覆盖，形成可导出的运行报表。</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="/export.php?type=hosts<?= $exportQuery ?>"><?= icon_svg('download', 15) ?> 导出资产</a>
        <a class="btn btn-primary" href="/export.php?type=alerts<?= $exportQuery ?>"><?= icon_svg('download', 15) ?> 导出告警</a>
    </div>
</div>

<div class="filter-card">
    <form class="filter-form" method="get" action="/reports.php">
        <span class="filter-meta"><?= icon_svg('calendar', 13) ?> 报告周期</span>
        <select class="select" name="period" data-auto-submit aria-label="报告周期">
            <?php foreach ($periodOptions as $days => $label): ?>
                <option value="<?= (int) $days ?>"<?= $period === $days ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="filter-meta"><?= icon_svg('layers', 13) ?> 监控分组</span>
        <select class="select" name="group" data-auto-submit aria-label="监控分组">
            <option value="0">全部分组</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>"<?= $groupId === (int) $group['id'] ? ' selected' : '' ?>><?= e($group['group_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <div class="filter-meta"><?= e($startDay) ?> 至 <?= e($referenceDay) ?> · <?= e($groupName) ?></div>
</div>

<div class="report-grid">
    <article class="report-card">
        <span class="report-card-icon"><?= icon_svg('hosts', 16) ?></span>
        <h3>主机健康报表</h3>
        <p>资产可用性、资源水位与状态汇总，支撑容量与健康度复核。</p>
        <div class="report-card-foot">
            <span><?= (int) $hostSummary['available'] ?>/<?= (int) $hostSummary['total'] ?> 台可用</span>
            <a href="/export.php?type=hosts<?= $exportQuery ?>">导出 <?= icon_svg('arrow', 11) ?></a>
        </div>
    </article>
    <article class="report-card blue">
        <span class="report-card-icon"><?= icon_svg('alerts', 16) ?></span>
        <h3>告警统计报表</h3>
        <p>告警级别、命中策略、确认与恢复情况，用于复盘告警质量。</p>
        <div class="report-card-foot">
            <span><?= (int) $alertSummary['total'] ?> 条告警</span>
            <a href="/export.php?type=alerts<?= $exportQuery ?>">导出 <?= icon_svg('arrow', 11) ?></a>
        </div>
    </article>
    <article class="report-card teal">
        <span class="report-card-icon"><?= icon_svg('tickets', 16) ?></span>
        <h3>工单效率报表</h3>
        <p>事件分类、优先级与处理状态，支持响应效率与责任分布分析。</p>
        <div class="report-card-foot">
            <span><?= (int) $ticketSummary['total'] ?> 个工单</span>
            <a href="/export.php?type=tickets">导出 <?= icon_svg('arrow', 11) ?></a>
        </div>
    </article>
    <article class="report-card purple">
        <span class="report-card-icon"><?= icon_svg('agents', 16) ?></span>
        <h3>采集覆盖报表</h3>
        <p>代理版本、心跳与采集范围覆盖情况，辅助采集链路治理。</p>
        <div class="report-card-foot">
            <span><?= (int) $agentSummary['running'] ?>/<?= (int) $agentSummary['total'] ?> 个运行中</span>
            <a href="/export.php?type=agents">导出 <?= icon_svg('arrow', 11) ?></a>
        </div>
    </article>
</div>

<div class="dashboard-grid">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('metrics', 16) ?></span> 告警与工单趋势</h3>
                <p class="panel-sub"><?= e($periodOptions[$period]) ?> · 按自然日汇总 · <?= e($groupName) ?></p>
            </div>
            <div class="legend">
                <span class="legend-item" style="color:#f0a94b"><i class="legend-dot"></i>告警</span>
                <span class="legend-item" style="color:#2dd4bf"><i class="legend-dot"></i>工单</span>
            </div>
        </div>
        <div class="panel-body">
            <div class="chart-wrap is-tall">
                <canvas class="chart-canvas" data-chart="<?= e(json_encode($chartConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" aria-label="告警与工单趋势图"></canvas>
            </div>
            <div class="chart-summary">
                <div class="chart-summary-item"><span>周期内告警</span><strong><?= $totalAlertsInPeriod ?> 条</strong></div>
                <div class="chart-summary-item"><span>周期内工单</span><strong><?= $totalTicketsInPeriod ?> 个</strong></div>
                <div class="chart-summary-item"><span>主机可用率</span><strong><?= number_format($availability, 1) ?>%</strong></div>
                <div class="chart-summary-item"><span>采集运行中</span><strong><?= (int) $agentSummary['running'] ?> 个</strong></div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('activity', 16) ?></span> 处置效率</h3>
                <p class="panel-sub">当前周期内的告警与工单完成情况</p>
            </div>
        </div>
        <div class="panel-body">
            <div class="donut-body" style="padding:4px 0 18px;flex-direction:row;gap:22px">
                <div class="donut" style="<?= e($donutStyle) ?>;width:118px;height:118px">
                    <div class="donut-center">
                        <strong style="font-size:24px"><?= $resolutionRate ?>%</strong>
                        <span>告警恢复率</span>
                    </div>
                </div>
                <div style="flex:1;min-width:130px">
                    <dl class="definition-list">
                        <div><dt>告警总数</dt><dd><?= (int) $alertSummary['total'] ?> 条</dd></div>
                        <div><dt>已恢复</dt><dd class="text-teal"><?= $donutResolved ?> 条</dd></div>
                        <div><dt>未恢复</dt><dd class="text-amber"><?= $donutUnresolved ?> 条</dd></div>
                        <div><dt>工单完成率</dt><dd><?= $ticketDoneRate ?>%</dd></div>
                    </dl>
                </div>
            </div>
            <h4 style="margin:5px 0 10px;font-size:10.5px;color:var(--faint)">告警级别分布</h4>
            <div class="report-summary">
                <?php
                $maxSeverity = max(1, ...array_values($severityCounts));
                foreach (['严重' => ['var(--red)', 'danger'], '警告' => ['var(--amber)', 'warning'], '提示' => ['var(--blue)', 'info']] as $label => [$color, $tone]):
                    $count = $severityCounts[$label];
                ?>
                    <div class="report-bar-row">
                        <span class="report-bar-label"><span class="badge badge-<?= e($tone) ?> no-dot"><?= e($label) ?></span></span>
                        <span class="report-bar-track"><i style="width:<?= number_format($count / $maxSeverity * 100, 1) ?>%;--bar-color:<?= e($color) ?>"></i></span>
                        <span class="report-bar-value"><?= $count ?> 条</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>

<section class="panel mt-16">
    <div class="panel-head">
        <div>
            <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('layers', 16) ?></span> 分组运行概览</h3>
            <p class="panel-sub">按监控分组汇总主机可用性、未恢复告警与代理运行状态</p>
        </div>
        <span class="filter-meta"><?= e($groupName) ?> · <?= count($groupOverview) ?> 个分组</span>
    </div>
    <div class="panel-body is-flush">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>监控分组</th>
                        <th>负责人</th>
                        <th>主机数量</th>
                        <th>可用主机</th>
                        <th>未恢复告警</th>
                        <th>运行中代理</th>
                        <th>代理覆盖</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groupOverview as $group): ?>
                    <tr>
                        <td><span class="group-chip"><i style="background:<?= e($group['color']) ?>"></i><?= e($group['group_name']) ?></span></td>
                        <td><?= e($group['owner']) ?></td>
                        <td><?= (int) $group['host_count'] ?> 台</td>
                        <td><?= (int) $group['available_count'] ?> 台</td>
                        <td class="<?= (int) $group['open_alerts'] > 0 ? 'text-amber' : '' ?>"><?= (int) $group['open_alerts'] ?> 条</td>
                        <td><?= (int) $group['running_agents'] ?> 个</td>
                        <td><?= percent((int) $group['running_agents'], (int) $group['host_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pager">
            <span>数据范围 <?= e($startDay) ?> 至 <?= e($referenceDay) ?> · 采集小时聚合</span>
            <span class="muted">导出的 CSV 使用 UTF-8 编码</span>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
