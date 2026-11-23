<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$groups = $pdo->query('SELECT * FROM monitor_groups ORDER BY sort_order')->fetchAll();
$statusOptions = ['在线', '警告', '离线', '维护'];

$keyword = q('keyword');
$groupId = query_int('group');
$status = q('status');
if (!in_array($status, $statusOptions, true)) {
    $status = '';
}

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(h.hostname LIKE ? OR h.ip_address LIKE ? OR h.location LIKE ?)';
    $like = '%' . $keyword . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($groupId > 0) {
    $where[] = 'h.group_id = ?';
    $params[] = $groupId;
}
if ($status !== '') {
    $where[] = 'h.status = ?';
    $params[] = $status;
}

$sql = "
    SELECT h.*, g.group_name, g.color, g.owner,
           cpu.value AS cpu_value, cpu.status AS cpu_status,
           mem.value AS mem_value, mem.status AS mem_status,
           disk.value AS disk_value, disk.status AS disk_status,
           a.status AS agent_status, a.version AS agent_live_version,
           (SELECT COUNT(*) FROM alerts al WHERE al.host_id = h.id AND al.status IN ('活跃', '已确认')) AS active_alerts
    FROM hosts h
    JOIN monitor_groups g ON g.id = h.group_id
    LEFT JOIN host_metrics cpu ON cpu.host_id = h.id AND cpu.metric_code = 'CPU_USAGE'
    LEFT JOIN host_metrics mem ON mem.host_id = h.id AND mem.metric_code = 'MEMORY_USAGE'
    LEFT JOIN host_metrics disk ON disk.host_id = h.id AND disk.metric_code = 'DISK_USAGE'
    LEFT JOIN agents a ON a.host_id = h.id
";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY CASE h.status WHEN '离线' THEN 1 WHEN '警告' THEN 2 WHEN '维护' THEN 3 ELSE 4 END, h.hostname";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$hosts = $stmt->fetchAll();

$hostStats = [
    'total' => (int) $pdo->query('SELECT COUNT(*) FROM hosts')->fetchColumn(),
    'online' => (int) $pdo->query("SELECT COUNT(*) FROM hosts WHERE status = '在线'")->fetchColumn(),
    'warning' => (int) $pdo->query("SELECT COUNT(*) FROM hosts WHERE status = '警告'")->fetchColumn(),
    'offline' => (int) $pdo->query("SELECT COUNT(*) FROM hosts WHERE status = '离线'")->fetchColumn(),
    'maintenance' => (int) $pdo->query("SELECT COUNT(*) FROM hosts WHERE status = '维护'")->fetchColumn(),
];

$selectedHost = null;
$hostMetrics = [];
$hostAgent = null;
$hostAlerts = [];
$hostName = q('host');
if ($hostName !== '') {
    $hostStmt = $pdo->prepare("
        SELECT h.*, g.group_name, g.color, g.owner
        FROM hosts h JOIN monitor_groups g ON g.id = h.group_id
        WHERE h.hostname = ?
    ");
    $hostStmt->execute([$hostName]);
    $selectedHost = $hostStmt->fetch() ?: null;
    if ($selectedHost) {
        $metricStmt = $pdo->prepare('SELECT * FROM host_metrics WHERE host_id = ? ORDER BY id');
        $metricStmt->execute([(int) $selectedHost['id']]);
        $hostMetrics = $metricStmt->fetchAll();

        $agentStmt = $pdo->prepare('SELECT * FROM agents WHERE host_id = ?');
        $agentStmt->execute([(int) $selectedHost['id']]);
        $hostAgent = $agentStmt->fetch() ?: null;

        $alertStmt = $pdo->prepare("
            SELECT alert_no, title, severity, status, started_at
            FROM alerts WHERE host_id = ?
            ORDER BY started_at DESC LIMIT 3
        ");
        $alertStmt->execute([(int) $selectedHost['id']]);
        $hostAlerts = $alertStmt->fetchAll();
    }
}

$hostQueryBase = ['keyword' => $keyword, 'group' => $groupId > 0 ? $groupId : '', 'status' => $status];
$pageTitle = '主机资产';
$active = 'hosts';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <div class="page-eyebrow">Asset Inventory</div>
        <h1 class="page-title">主机资产</h1>
        <p class="page-sub">统一维护纳管主机、监控分组、代理状态与实时资源水位。</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="/export.php?type=hosts"><?= icon_svg('download', 15) ?> 导出资产清单</a>
        <a class="btn btn-primary" href="/tickets.php?new=1"><?= icon_svg('plus', 15) ?> 发起工单</a>
    </div>
</div>

<div class="stat-strip">
    <div class="strip-item"><span>全部主机</span><strong><?= $hostStats['total'] ?><small>台</small></strong></div>
    <div class="strip-item"><span>在线运行</span><strong class="text-teal"><?= $hostStats['online'] ?><small>台</small></strong></div>
    <div class="strip-item"><span>水位告警</span><strong class="text-amber"><?= $hostStats['warning'] ?><small>台</small></strong></div>
    <div class="strip-item"><span>计划维护</span><strong><?= $hostStats['maintenance'] ?><small>台</small></strong></div>
    <div class="strip-item"><span>采集离线</span><strong class="text-red"><?= $hostStats['offline'] ?><small>台</small></strong></div>
</div>

<?php if ($selectedHost): ?>
    <div class="detail-grid mb-16">
        <section class="panel detail-panel">
            <div class="panel-head">
                <div class="host-profile">
                    <span class="host-profile-mark"><?= icon_svg('server', 23) ?></span>
                    <div class="host-profile-body">
                        <h3><?= e($selectedHost['hostname']) ?></h3>
                        <div class="host-profile-meta">
                            <span class="mono"><?= e($selectedHost['ip_address']) ?></span>
                            <span class="group-chip"><i style="background:<?= e($selectedHost['color']) ?>"></i><?= e($selectedHost['group_name']) ?></span>
                            <span class="badge badge-<?= e(host_status_class($selectedHost['status'])) ?>"><?= e($selectedHost['status']) ?></span>
                            <span><?= e($selectedHost['environment']) ?></span>
                        </div>
                    </div>
                </div>
                <div class="panel-actions">
                    <a class="btn btn-sm btn-ghost" href="/hosts.php">关闭详情</a>
                    <a class="btn btn-sm btn-primary" href="/tickets.php?new=1&amp;host=<?= e(urlencode($selectedHost['hostname'])) ?>">创建工单</a>
                </div>
            </div>
            <div class="panel-body">
                <p class="muted small" style="margin-bottom:14px"><?= e($selectedHost['description']) ?></p>
                <div class="detail-facts">
                    <div><span>操作系统</span><strong><?= e($selectedHost['os_name']) ?></strong></div>
                    <div><span>规格</span><strong><?= (int) $selectedHost['cpu_cores'] ?>C / <?= (int) $selectedHost['memory_gb'] ?>GB</strong></div>
                    <div><span>机柜位置</span><strong><?= e($selectedHost['location']) ?></strong></div>
                    <div><span>持续运行</span><strong><?= (int) $selectedHost['uptime_days'] ?> 天</strong></div>
                </div>
                <div class="metric-mini-grid">
                    <?php foreach ($hostMetrics as $metric): ?>
                        <?php
                        $value = (float) $metric['value'];
                        $critical = max(0.01, (float) $metric['critical_threshold']);
                        $barWidth = min(100, max(2, ($value / $critical) * 100));
                        $barTone = $metric['status'] === '严重' ? 'is-danger' : ($metric['status'] === '警告' ? 'is-warning' : '');
                        ?>
                        <div class="metric-mini">
                            <div class="metric-mini-top">
                                <span><?= e($metric['metric_name']) ?></span>
                                <?= icon_svg(metric_icon($metric['metric_code']), 14) ?>
                            </div>
                            <div class="metric-mini-value">
                                <?= $metric['metric_code'] === 'AGENT_HEARTBEAT' ? number_format($value) : number_format($value, 1) ?><?php if ($metric['unit'] !== ''): ?><small><?= e($metric['unit']) ?></small><?php endif; ?>
                            </div>
                            <div class="meter"><i class="<?= $barTone ?>" style="width:<?= $barWidth ?>%"></i></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <aside class="side-stack">
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('agents', 16) ?></span> 采集代理</h3>
                        <p class="panel-sub"><?= e($hostAgent['agent_id'] ?? '尚未注册代理') ?></p>
                    </div>
                </div>
                <div class="panel-body">
                    <?php if ($hostAgent): ?>
                        <dl class="definition-list">
                            <div><dt>运行状态</dt><dd><span class="badge badge-<?= e(agent_status_class($hostAgent['status'])) ?>"><?= e($hostAgent['status']) ?></span></dd></div>
                            <div><dt>代理版本</dt><dd><?= e($hostAgent['version']) ?></dd></div>
                            <div><dt>部署方式</dt><dd><?= e($hostAgent['install_type']) ?></dd></div>
                            <div><dt>最近心跳</dt><dd><?= e(fmt_datetime($hostAgent['last_heartbeat_at'])) ?></dd></div>
                        </dl>
                    <?php else: ?>
                        <div class="panel-note">该主机尚未关联采集代理，请前往采集代理页面完成注册。</div>
                    <?php endif; ?>
                </div>
            </section>
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('alerts', 16) ?></span> 近期告警</h3>
                    </div>
                </div>
                <div class="panel-body">
                    <?php if (!$hostAlerts): ?>
                        <div class="panel-note">该主机近期运行平稳，没有产生告警记录。</div>
                    <?php else: ?>
                        <div class="alert-mini-list">
                            <?php foreach ($hostAlerts as $alert): ?>
                                <div class="alert-mini">
                                    <span class="alert-mini-dot <?= $alert['severity'] === '严重' ? 'is-danger' : ($alert['severity'] === '提示' ? 'is-info' : '') ?>"></span>
                                    <div class="alert-mini-body">
                                        <div class="alert-mini-title"><strong><?= e($alert['title']) ?></strong></div>
                                        <div class="alert-mini-meta">
                                            <span class="badge badge-<?= e(alert_status_class($alert['status'])) ?> no-dot"><?= e($alert['status']) ?></span>
                                            <span><?= e(relative_time($alert['started_at'])) ?></span>
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
<?php endif; ?>

<div class="filter-card">
    <form class="filter-form" method="get" action="/hosts.php">
        <label class="field-inline">
            <?= icon_svg('search', 14) ?>
            <input class="input" type="search" name="keyword" value="<?= e($keyword) ?>" placeholder="主机名 / IP / 机柜位置">
        </label>
        <select class="select" name="group" aria-label="监控分组">
            <option value="0">全部分组</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>"<?= $groupId === (int) $group['id'] ? ' selected' : '' ?>><?= e($group['group_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="status" aria-label="运行状态">
            <option value="">全部状态</option>
            <?php foreach ($statusOptions as $option): ?>
                <option value="<?= e($option) ?>"<?= $status === $option ? ' selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm" type="submit"><?= icon_svg('filter', 13) ?> 筛选</button>
        <?php if ($keyword !== '' || $groupId > 0 || $status !== ''): ?>
            <a class="link-action" href="/hosts.php">清除条件</a>
        <?php endif; ?>
    </form>
    <div class="filter-meta">当前结果 <strong><?= count($hosts) ?></strong> 台 · 最近采集 <strong><?= e(fmt_datetime($pdo->query('SELECT MAX(collected_at) FROM host_metrics')->fetchColumn(), 'H:i:s')) ?></strong></div>
</div>

<section class="panel">
    <div class="panel-head">
        <div>
            <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('layers', 16) ?></span> 主机清单</h3>
            <p class="panel-sub">资产信息、当前资源水位与采集代理运行状态</p>
        </div>
        <div class="panel-actions">
            <span class="filter-meta"><?= e(APP_ZONE) ?> · <?= count($groups) ?> 个监控分组</span>
        </div>
    </div>
    <div class="panel-body is-flush">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>主机</th>
                        <th>监控分组</th>
                        <th>运行状态</th>
                        <th>CPU</th>
                        <th>内存</th>
                        <th>磁盘</th>
                        <th>采集代理</th>
                        <th>最后上报</th>
                        <th class="t-right">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$hosts): ?>
                    <tr><td colspan="9"><div class="empty-state"><?= icon_svg('search', 24) ?>没有符合条件的主机</div></td></tr>
                <?php endif; ?>
                <?php foreach ($hosts as $row): ?>
                    <?php
                    $cpu = (float) $row['cpu_value'];
                    $cpuTone = $row['cpu_status'] === '严重' ? 'is-danger' : ($row['cpu_status'] === '警告' ? 'is-warning' : '');
                    $detailQuery = build_query($hostQueryBase + ['host' => $row['hostname']]);
                    ?>
                    <tr>
                        <td>
                            <div class="host-cell">
                                <span class="host-avatar"><?= e(substr($row['hostname'], -2)) ?></span>
                                <span class="host-cell-body">
                                    <strong><?= e($row['hostname']) ?></strong>
                                    <span><?= e($row['ip_address']) ?> · <?= e($row['environment']) ?></span>
                                </span>
                            </div>
                        </td>
                        <td><span class="group-chip"><i style="background:<?= e($row['color']) ?>"></i><?= e($row['group_name']) ?></span></td>
                        <td>
                            <span class="badge badge-<?= e(host_status_class($row['status'])) ?>"><?= e($row['status']) ?></span>
                            <?php if ((int) $row['active_alerts'] > 0): ?>
                                <span class="row-sub"><span class="text-red"><?= (int) $row['active_alerts'] ?> 条未恢复告警</span></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="mini-health">
                                <div class="mini-health-bar"><i class="<?= $cpuTone ?>" style="width:<?= min(100, $cpu) ?>%"></i></div>
                                <span><?= number_format($cpu, 1) ?>%</span>
                            </div>
                        </td>
                        <td class="<?= $row['mem_status'] === '严重' ? 'text-red' : ($row['mem_status'] === '警告' ? 'text-amber' : '') ?>"><?= number_format((float) $row['mem_value'], 1) ?>%</td>
                        <td class="<?= $row['disk_status'] === '严重' ? 'text-red' : ($row['disk_status'] === '警告' ? 'text-amber' : '') ?>"><?= number_format((float) $row['disk_value'], 1) ?>%</td>
                        <td>
                            <span class="badge badge-<?= e(agent_status_class((string) $row['agent_status'])) ?> no-dot"><?= e($row['agent_status'] ?: '未注册') ?></span>
                            <span class="row-sub">v<?= e($row['agent_version'] ?: '—') ?></span>
                        </td>
                        <td>
                            <span class="nowrap"><?= e(relative_time($row['last_report_at'])) ?></span>
                            <span class="row-sub"><?= e(fmt_datetime($row['last_report_at'], 'm-d H:i:s')) ?></span>
                        </td>
                        <td class="t-right">
                            <div class="actions">
                                <a class="link-action" href="/hosts.php?<?= e($detailQuery) ?>"><?= icon_svg('eye', 13) ?> 详情</a>
                                <a class="link-action" href="/metrics.php?host=<?= (int) $row['id'] ?>"><?= icon_svg('metrics', 13) ?> 指标</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pager">
            <span>共 <?= count($hosts) ?> 台主机 · 数据每 60 秒汇聚一次</span>
            <span class="muted"><?= icon_svg('refresh', 12) ?> 最后刷新 <?= e(fmt_datetime($pdo->query('SELECT MAX(collected_at) FROM host_metrics')->fetchColumn(), 'H:i:s')) ?></span>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
