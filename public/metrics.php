<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$metricRows = $pdo->query("
    SELECT mc.*, ROUND(AVG(hm.value), 2) AS avg_value, COUNT(hm.id) AS host_count
    FROM metric_catalog mc
    JOIN host_metrics hm ON hm.metric_code = mc.metric_code
    GROUP BY mc.metric_code
    ORDER BY mc.sort_order
")->fetchAll();
$metricMap = [];
foreach ($metricRows as $row) {
    $metricMap[$row['metric_code']] = $row;
}

$metricCode = q('metric', 'CPU_USAGE');
if (!isset($metricMap[$metricCode])) {
    $metricCode = 'CPU_USAGE';
}
$metric = $metricMap[$metricCode];

$rangeOptions = [6 => '6 小时', 12 => '12 小时', 24 => '24 小时'];
$range = query_int('range', 12);
if (!isset($rangeOptions[$range])) {
    $range = 12;
}

$hostList = $pdo->query('SELECT id, hostname, ip_address, status FROM hosts ORDER BY hostname')->fetchAll();
$hostId = query_int('host', 0);
$selectedHost = null;
if ($hostId > 0) {
    $hostStmt = $pdo->prepare('SELECT id, hostname, ip_address, status FROM hosts WHERE id = ?');
    $hostStmt->execute([$hostId]);
    $selectedHost = $hostStmt->fetch() ?: null;
    if (!$selectedHost) {
        $hostId = 0;
    }
}

$scopeWhere = 'hm.metric_code = ?';
$scopeParams = [$metricCode];
if ($hostId > 0) {
    $scopeWhere .= ' AND hm.host_id = ?';
    $scopeParams[] = $hostId;
}

$statStmt = $pdo->prepare("
    SELECT COUNT(*) AS host_count, COALESCE(AVG(hm.value), 0) AS avg_value,
           COALESCE(MAX(hm.value), 0) AS max_value, COALESCE(MIN(hm.value), 0) AS min_value,
           COALESCE(SUM(CASE WHEN hm.status = '警告' THEN 1 ELSE 0 END), 0) AS warning_count,
           COALESCE(SUM(CASE WHEN hm.status = '严重' THEN 1 ELSE 0 END), 0) AS critical_count
    FROM host_metrics hm
    WHERE {$scopeWhere}
");
$statStmt->execute($scopeParams);
$scopeStats = $statStmt->fetch();

$statusStmt = $pdo->prepare("
    SELECT hm.status, COUNT(*) AS count
    FROM host_metrics hm
    WHERE {$scopeWhere}
    GROUP BY hm.status
");
$statusStmt->execute($scopeParams);
$statusCounts = ['正常' => 0, '警告' => 0, '严重' => 0, '未知' => 0];
foreach ($statusStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['count'];
}
$scopeTotal = array_sum($statusCounts);

$sampleSql = "
    SELECT sampled_at, ROUND(AVG(value), 2) AS value
    FROM metric_samples
    WHERE metric_code = ?
";
$sampleParams = [$metricCode];
if ($hostId > 0) {
    $sampleSql .= ' AND host_id = ?';
    $sampleParams[] = $hostId;
}
$sampleSql .= ' GROUP BY sampled_at ORDER BY sampled_at DESC LIMIT ' . $range;
$sampleStmt = $pdo->prepare($sampleSql);
$sampleStmt->execute($sampleParams);
$samples = array_reverse($sampleStmt->fetchAll());

$labels = [];
$values = [];
foreach ($samples as $sample) {
    $labels[] = date('H:i', strtotime((string) $sample['sampled_at']));
    $values[] = (float) $sample['value'];
}
$latestValue = $values ? end($values) : (float) $scopeStats['avg_value'];
$rangeMax = $values ? max($values) : 0.0;
$rangeMin = $values ? min($values) : 0.0;
$rangeAvg = $values ? array_sum($values) / count($values) : 0.0;
$chartColor = $latestValue >= (float) $metric['critical_threshold'] ? '#ff6b6b' : ($latestValue >= (float) $metric['warn_threshold'] ? '#f0a94b' : '#2dd4bf');
$chartConfig = [
    'labels' => $labels,
    'series' => [
        ['name' => $metric['metric_name'], 'color' => $chartColor, 'values' => $values],
    ],
    'min' => 0,
    'max' => $metric['unit'] === '%' ? 100 : null,
    'unit' => $metric['unit'],
    'thresholds' => [
        ['value' => (float) $metric['warn_threshold'], 'label' => '预警 ' . $metric['warn_threshold'] . $metric['unit'], 'color' => 'rgba(240, 169, 75, 0.45)'],
        ['value' => (float) $metric['critical_threshold'], 'label' => '严重 ' . $metric['critical_threshold'] . $metric['unit'], 'color' => 'rgba(255, 107, 107, 0.44)'],
    ],
];

$latestSampleAt = (string) $pdo->query('SELECT MAX(sampled_at) FROM metric_samples')->fetchColumn();
$metricHostStmt = $pdo->prepare("
    SELECT h.id, h.hostname, h.ip_address, h.status AS host_status,
           g.group_name, g.color, hm.value, hm.status, hm.warn_threshold, hm.critical_threshold, hm.collected_at
    FROM host_metrics hm
    JOIN hosts h ON h.id = hm.host_id
    JOIN monitor_groups g ON g.id = h.group_id
    WHERE hm.metric_code = ?
    ORDER BY hm.value DESC
");
$metricHostStmt->execute([$metricCode]);
$metricHosts = $metricHostStmt->fetchAll();

$policyStmt = $pdo->prepare('SELECT policy_code, policy_name, threshold_value, severity, enabled FROM alert_policies WHERE metric_code = ? ORDER BY enabled DESC, threshold_value ASC LIMIT 4');
$policyStmt->execute([$metricCode]);
$relatedPolicies = $policyStmt->fetchAll();

$currentTone = $latestValue >= (float) $metric['critical_threshold'] ? '严重' : ($latestValue >= (float) $metric['warn_threshold'] ? '警告' : '正常');
$scopeLabel = $selectedHost ? $selectedHost['hostname'] : '全部主机';

$pageTitle = '指标监控';
$active = 'metrics';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <div class="page-eyebrow">Metric Observatory</div>
        <h1 class="page-title">指标监控</h1>
        <p class="page-sub">按主机与指标维度查看小时聚合趋势、阈值水位和异常分布。</p>
    </div>
    <div class="page-actions">
        <span class="badge badge-success"><?= icon_svg('refresh', 12) ?> 小时聚合</span>
        <a class="btn btn-sm" href="/export.php?type=metrics&amp;metric=<?= e(urlencode($metricCode)) ?>"><?= icon_svg('download', 14) ?> 导出当前视图</a>
    </div>
</div>

<div class="metric-tile-grid">
    <?php foreach ($metricRows as $tile): ?>
        <?php
        $tileValue = (float) $tile['avg_value'];
        $tileState = metric_value_state($tileValue, (float) $tile['warn_threshold'], (float) $tile['critical_threshold']);
        $tileQuery = build_query(['metric' => $tile['metric_code'], 'range' => $range, 'host' => $hostId ?: '']);
        ?>
        <div class="metric-tile<?= $tile['metric_code'] === $metricCode ? ' is-active' : '' ?>">
            <a href="/metrics.php?<?= e($tileQuery) ?>">
                <div class="metric-tile-head">
                    <span><?= e($tile['metric_name']) ?></span>
                    <?= icon_svg(metric_icon($tile['metric_code']), 15) ?>
                </div>
                <div class="metric-tile-value">
                    <?= $tile['metric_code'] === 'AGENT_HEARTBEAT' ? number_format($tileValue) : number_format($tileValue, 1) ?><small><?= e($tile['unit']) ?></small>
                </div>
                <div class="metric-tile-foot">
                    <span><?= (int) $tile['host_count'] ?> 台主机</span>
                    <span class="badge badge-<?= e(metric_status_class($tileState)) ?> no-dot"><?= e($tileState) ?></span>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="filter-card">
    <form class="filter-form" method="get" action="/metrics.php">
        <input type="hidden" name="metric" value="<?= e($metricCode) ?>">
        <span class="filter-meta"><?= icon_svg('hosts', 13) ?> 观察范围</span>
        <select class="select" name="host" data-auto-submit aria-label="选择主机">
            <option value="0">全部主机（均值）</option>
            <?php foreach ($hostList as $host): ?>
                <option value="<?= (int) $host['id'] ?>"<?= $hostId === (int) $host['id'] ? ' selected' : '' ?>><?= e($host['hostname']) ?> · <?= e($host['ip_address']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="range" data-auto-submit aria-label="时间范围">
            <?php foreach ($rangeOptions as $hours => $label): ?>
                <option value="<?= (int) $hours ?>"<?= $range === $hours ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($selectedHost): ?>
            <a class="link-action" href="/metrics.php?<?= e(build_query(['metric' => $metricCode, 'range' => $range])) ?>">回到全量视图</a>
        <?php endif; ?>
    </form>
    <div class="filter-meta"><?= icon_svg('clock', 13) ?> 采样至 <strong><?= e(fmt_datetime($latestSampleAt, 'm-d H:i')) ?></strong> · 保留 30 天</div>
</div>

<div class="dashboard-grid">
    <section class="panel">
        <div class="panel-head">
            <div class="chart-metric-title">
                <span class="chart-metric-icon"><?= icon_svg(metric_icon($metricCode), 18) ?></span>
                <div>
                    <h3><?= e($metric['metric_name']) ?> · <?= e($scopeLabel) ?></h3>
                    <p><?= e($metric['category']) ?>指标 · 最近 <?= e($rangeOptions[$range]) ?> · <?= count($labels) ?> 个数据点</p>
                </div>
            </div>
            <div class="metric-thresholds">
                <span>当前 <strong><?= number_format($latestValue, 1) ?><?= e($metric['unit']) ?></strong></span>
                <span>预警 <strong><?= e($metric['warn_threshold']) ?><?= e($metric['unit']) ?></strong></span>
                <span>严重 <strong><?= e($metric['critical_threshold']) ?><?= e($metric['unit']) ?></strong></span>
            </div>
        </div>
        <div class="panel-body">
            <div class="time-tabs">
                <?php foreach ($rangeOptions as $hours => $label): ?>
                    <a class="<?= $range === $hours ? 'is-active' : '' ?>" href="/metrics.php?<?= e(build_query(['metric' => $metricCode, 'range' => $hours, 'host' => $hostId ?: ''])) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="chart-wrap is-tall" style="margin-top:13px">
                <?php if ($labels): ?>
                    <canvas class="chart-canvas" data-chart="<?= e(json_encode($chartConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" aria-label="<?= e($metric['metric_name']) ?>趋势图"></canvas>
                <?php else: ?>
                    <div class="empty-state"><?= icon_svg('metrics', 24) ?>当前范围没有可用采样数据</div>
                <?php endif; ?>
            </div>
            <div class="chart-summary">
                <div class="chart-summary-item"><span>区间最低</span><strong><?= number_format($rangeMin, 1) ?><?= e($metric['unit']) ?></strong></div>
                <div class="chart-summary-item"><span>区间均值</span><strong><?= number_format($rangeAvg, 1) ?><?= e($metric['unit']) ?></strong></div>
                <div class="chart-summary-item"><span>区间最高</span><strong><?= number_format($rangeMax, 1) ?><?= e($metric['unit']) ?></strong></div>
                <div class="chart-summary-item"><span>当前状态</span><strong class="<?= $currentTone === '严重' ? 'text-red' : ($currentTone === '警告' ? 'text-amber' : 'text-teal') ?>"><?= e($currentTone) ?></strong></div>
            </div>
        </div>
    </section>

    <aside class="side-stack">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('sliders', 16) ?></span> 指标说明</h3>
                    <p class="panel-sub"><?= e($metric['metric_name']) ?> · <?= e($metric['category']) ?></p>
                </div>
            </div>
            <div class="panel-body">
                <p class="muted small" style="margin-bottom:12px"><?= e($metric['description']) ?></p>
                <dl class="definition-list">
                    <div><dt>指标编码</dt><dd><span class="mono"><?= e($metric['metric_code']) ?></span></dd></div>
                    <div><dt>取值单位</dt><dd><?= $metric['unit'] === '' ? '无单位' : e($metric['unit']) ?></dd></div>
                    <div><dt>当前范围</dt><dd><?= e($scopeLabel) ?></dd></div>
                    <div><dt>覆盖主机</dt><dd><?= (int) $scopeStats['host_count'] ?> 台</dd></div>
                </dl>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('policies', 16) ?></span> 关联告警策略</h3>
                    <p class="panel-sub">基于该指标评估的规则</p>
                </div>
                <a class="link-action" href="/alert-policies.php">管理 <?= icon_svg('chevron', 11) ?></a>
            </div>
            <div class="panel-body">
                <?php if (!$relatedPolicies): ?>
                    <div class="panel-note">当前指标尚未关联告警策略。</div>
                <?php else: ?>
                    <div class="alert-mini-list">
                        <?php foreach ($relatedPolicies as $policy): ?>
                            <div class="alert-mini">
                                <span class="alert-mini-dot <?= $policy['severity'] === '严重' ? 'is-danger' : ($policy['severity'] === '提示' ? 'is-info' : '') ?>"></span>
                                <div class="alert-mini-body">
                                    <div class="alert-mini-title"><strong><?= e($policy['policy_name']) ?></strong></div>
                                    <div class="alert-mini-meta">
                                        <span><?= e($policy['policy_code']) ?></span>
                                        <span>阈值 <?= e($policy['threshold_value']) ?><?= e($metric['unit']) ?></span>
                                        <span class="badge badge-<?= $policy['enabled'] ? 'success' : 'neutral' ?> no-dot"><?= $policy['enabled'] ? '启用' : '停用' ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </aside>
</div>

<div class="dashboard-grid equal mt-16">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('hosts', 16) ?></span> 主机当前水位</h3>
                <p class="panel-sub"><?= e($metric['metric_name']) ?>从高到低 · 阈值 <?= e($metric['warn_threshold']) ?> / <?= e($metric['critical_threshold']) ?><?= e($metric['unit']) ?></p>
            </div>
            <a class="link-action" href="/hosts.php">资产清单 <?= icon_svg('chevron', 11) ?></a>
        </div>
        <div class="panel-body is-flush">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>主机</th>
                            <th>分组</th>
                            <th>当前值</th>
                            <th>状态</th>
                            <th>采集时间</th>
                            <th class="t-right">查看</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($metricHosts as $row): ?>
                        <tr<?= $hostId === (int) $row['id'] ? ' style="background:rgba(240,169,75,.045)"' : '' ?>>
                            <td>
                                <div class="host-cell">
                                    <span class="host-avatar"><?= e(substr($row['hostname'], -2)) ?></span>
                                    <span class="host-cell-body">
                                        <strong><?= e($row['hostname']) ?></strong>
                                        <span><?= e($row['ip_address']) ?></span>
                                    </span>
                                </div>
                            </td>
                            <td><span class="group-chip"><i style="background:<?= e($row['color']) ?>"></i><?= e($row['group_name']) ?></span></td>
                            <td class="<?= $row['status'] === '严重' ? 'text-red' : ($row['status'] === '警告' ? 'text-amber' : '') ?>"><?= number_format((float) $row['value'], 1) ?><?= e($metric['unit']) ?></td>
                            <td><span class="badge badge-<?= e(metric_status_class($row['status'])) ?>"><?= e($row['status']) ?></span></td>
                            <td class="muted nowrap"><?= e(fmt_datetime($row['collected_at'], 'H:i:s')) ?></td>
                            <td class="t-right"><a class="link-action" href="/metrics.php?<?= e(build_query(['metric' => $metricCode, 'range' => $range, 'host' => $row['id']])) ?>">趋势</a></td>
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
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('layers', 16) ?></span> 水位分布</h3>
                <p class="panel-sub"><?= e($scopeLabel) ?> · 共 <?= $scopeTotal ?> 条当前指标</p>
            </div>
        </div>
        <div class="panel-body">
            <div class="report-summary">
                <?php
                $distribution = [
                    ['正常', 'var(--teal)', 'success'],
                    ['警告', 'var(--amber)', 'warning'],
                    ['严重', 'var(--red)', 'danger'],
                    ['未知', '#67768a', 'neutral'],
                ];
                foreach ($distribution as [$label, $color, $tone]):
                    $count = $statusCounts[$label] ?? 0;
                    $ratio = $scopeTotal > 0 ? $count / $scopeTotal * 100 : 0;
                ?>
                    <div class="report-bar-row">
                        <span class="report-bar-label"><span class="badge badge-<?= e($tone) ?> no-dot"><?= e($label) ?></span></span>
                        <span class="report-bar-track"><i style="width:<?= number_format($ratio, 1) ?>%;--bar-color:<?= e($color) ?>"></i></span>
                        <span class="report-bar-value"><?= $count ?> 台</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="panel-note" style="margin-top:16px">
                当前视图为按主机计算的小时聚合值。阈值判断以策略定义为准，未知状态通常表示主机处于维护或采集离线。
            </div>
        </div>
    </section>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
