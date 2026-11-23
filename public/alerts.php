<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    $alertId = (int) post_str('alert_id');
    $stmt = $pdo->prepare('SELECT * FROM alerts WHERE id = ?');
    $stmt->execute([$alertId]);
    $alert = $stmt->fetch();

    if (!$alert) {
        flash('error', '告警记录不存在或已被清理。');
        redirect('/alerts.php');
    }

    $now = date('Y-m-d H:i:s');
    if ($action === 'ack' && $alert['status'] === '活跃') {
        $pdo->prepare("UPDATE alerts SET status = '已确认', acknowledged_at = ?, acknowledged_by = ? WHERE id = ?")
            ->execute([$now, current_operator(), $alertId]);
        flash('success', '告警 ' . $alert['alert_no'] . ' 已确认，请跟进处置进展。');
    } elseif ($action === 'resolve' && in_array($alert['status'], ['活跃', '已确认'], true)) {
        $pdo->prepare("UPDATE alerts SET status = '已恢复', resolved_at = ?, resolved_by = ? WHERE id = ?")
            ->execute([$now, current_operator(), $alertId]);
        flash('success', '告警 ' . $alert['alert_no'] . ' 已标记恢复。');
    } else {
        flash('warn', '当前告警状态不支持该操作。');
    }
    redirect('/alerts.php');
}

$stats = [
    'open' => (int) $pdo->query("SELECT COUNT(*) FROM alerts WHERE status IN ('活跃', '已确认')")->fetchColumn(),
    'critical' => (int) $pdo->query("SELECT COUNT(*) FROM alerts WHERE severity = '严重' AND status IN ('活跃', '已确认')")->fetchColumn(),
    'warning' => (int) $pdo->query("SELECT COUNT(*) FROM alerts WHERE severity = '警告' AND status IN ('活跃', '已确认')")->fetchColumn(),
    'resolved' => (int) $pdo->query("SELECT COUNT(*) FROM alerts WHERE status = '已恢复'")->fetchColumn(),
];
$groups = $pdo->query('SELECT id, group_name FROM monitor_groups ORDER BY sort_order')->fetchAll();
$keyword = q('keyword');
$severity = q('severity');
$status = q('status');
$groupId = query_int('group');
if (!in_array($severity, ['严重', '警告', '提示', ''], true)) {
    $severity = '';
}
if (!in_array($status, ['活跃', '已确认', '已恢复', ''], true)) {
    $status = '';
}

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(a.alert_no LIKE ? OR a.title LIKE ? OR h.hostname LIKE ? OR p.policy_name LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($severity !== '') {
    $where[] = 'a.severity = ?';
    $params[] = $severity;
}
if ($status !== '') {
    $where[] = 'a.status = ?';
    $params[] = $status;
}
if ($groupId > 0) {
    $where[] = 'h.group_id = ?';
    $params[] = $groupId;
}
$sql = "
    SELECT a.*, h.hostname, h.ip_address, g.group_name, g.color,
           p.policy_name, p.policy_code, p.notify_channel
    FROM alerts a
    JOIN hosts h ON h.id = a.host_id
    JOIN monitor_groups g ON g.id = h.group_id
    JOIN alert_policies p ON p.id = a.policy_id
";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY CASE a.status WHEN '活跃' THEN 1 WHEN '已确认' THEN 2 ELSE 3 END, a.started_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll();

$pageTitle = '告警记录';
$active = 'alerts';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <div class="page-eyebrow">Alert Timeline</div>
        <h1 class="page-title">告警记录</h1>
        <p class="page-sub">跟踪所有策略命中、确认和恢复过程，保留完整处置时间线。</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="/export.php?type=alerts"><?= icon_svg('download', 15) ?> 导出记录</a>
        <a class="btn btn-primary" href="/alert-policies.php"><?= icon_svg('policies', 15) ?> 策略管理</a>
    </div>
</div>

<div class="alert-severity-tiles">
    <div class="severity-tile is-warning">
        <span class="severity-tile-icon"><?= icon_svg('alerts', 16) ?></span>
        <div><span>未恢复总数</span><strong><?= $stats['open'] ?></strong></div>
    </div>
    <div class="severity-tile is-danger">
        <span class="severity-tile-icon"><?= icon_svg('warning', 16) ?></span>
        <div><span>严重告警</span><strong><?= $stats['critical'] ?></strong></div>
    </div>
    <div class="severity-tile is-warning">
        <span class="severity-tile-icon"><?= icon_svg('activity', 16) ?></span>
        <div><span>警告告警</span><strong><?= $stats['warning'] ?></strong></div>
    </div>
    <div class="severity-tile is-success">
        <span class="severity-tile-icon"><?= icon_svg('check', 16) ?></span>
        <div><span>已恢复</span><strong><?= $stats['resolved'] ?></strong></div>
    </div>
</div>

<div class="filter-card">
    <form class="filter-form" method="get" action="/alerts.php">
        <label class="field-inline">
            <?= icon_svg('search', 14) ?>
            <input class="input" type="search" name="keyword" value="<?= e($keyword) ?>" placeholder="告警编号 / 标题 / 主机">
        </label>
        <select class="select" name="severity" aria-label="告警级别">
            <option value="">全部级别</option>
            <?php foreach (['严重', '警告', '提示'] as $option): ?>
                <option value="<?= e($option) ?>"<?= $severity === $option ? ' selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="status" aria-label="处理状态">
            <option value="">全部状态</option>
            <?php foreach (['活跃', '已确认', '已恢复'] as $option): ?>
                <option value="<?= e($option) ?>"<?= $status === $option ? ' selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="group" aria-label="监控分组">
            <option value="0">全部分组</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>"<?= $groupId === (int) $group['id'] ? ' selected' : '' ?>><?= e($group['group_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm" type="submit"><?= icon_svg('filter', 13) ?> 筛选</button>
        <?php if ($keyword !== '' || $severity !== '' || $status !== '' || $groupId > 0): ?>
            <a class="link-action" href="/alerts.php">清除条件</a>
        <?php endif; ?>
    </form>
    <div class="filter-meta">当前显示 <strong><?= count($alerts) ?></strong> 条记录</div>
</div>

<section class="panel">
    <div class="panel-head">
        <div>
            <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('alerts', 16) ?></span> 告警清单</h3>
            <p class="panel-sub">活跃与已确认告警优先展示</p>
        </div>
        <span class="filter-meta">最近事件 <?= e(fmt_datetime($pdo->query('SELECT MAX(started_at) FROM alerts')->fetchColumn(), 'm-d H:i')) ?></span>
    </div>
    <div class="panel-body is-flush">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>告警信息</th>
                        <th>主机</th>
                        <th>命中策略</th>
                        <th>指标 / 阈值</th>
                        <th>级别</th>
                        <th>触发时间</th>
                        <th>状态</th>
                        <th class="t-right">处置</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$alerts): ?>
                    <tr><td colspan="8"><div class="empty-state"><?= icon_svg('search', 24) ?>没有符合条件的告警记录</div></td></tr>
                <?php endif; ?>
                <?php foreach ($alerts as $alert): ?>
                    <tr>
                        <td class="alert-title-cell">
                            <strong><?= e($alert['title']) ?></strong>
                            <span><?= e($alert['message']) ?></span>
                            <span class="row-sub"><span class="mono"><?= e($alert['alert_no']) ?></span></span>
                        </td>
                        <td>
                            <div class="host-cell">
                                <span class="host-avatar"><?= e(substr($alert['hostname'], -2)) ?></span>
                                <span class="host-cell-body">
                                    <strong><?= e($alert['hostname']) ?></strong>
                                    <span><?= e($alert['ip_address']) ?></span>
                                </span>
                            </div>
                            <span class="row-sub"><span class="group-chip"><i style="background:<?= e($alert['color']) ?>"></i><?= e($alert['group_name']) ?></span></span>
                        </td>
                        <td>
                            <span><?= e($alert['policy_name']) ?></span>
                            <span class="row-sub"><?= e($alert['policy_code']) ?></span>
                        </td>
                        <td>
                            <div class="value-stack">
                                <strong><?= e($alert['current_value']) ?></strong>
                                <span>阈值 <?= e($alert['threshold_value']) ?></span>
                            </div>
                        </td>
                        <td><span class="badge badge-<?= e(severity_class($alert['severity'])) ?>"><?= e($alert['severity']) ?></span></td>
                        <td class="nowrap">
                            <span><?= e(fmt_datetime($alert['started_at'], 'm-d H:i:s')) ?></span>
                            <span class="row-sub"><?= e(relative_time($alert['started_at'])) ?></span>
                        </td>
                        <td>
                            <span class="badge badge-<?= e(alert_status_class($alert['status'])) ?>"><?= e($alert['status']) ?></span>
                            <?php if ($alert['status'] === '已恢复' && $alert['resolved_at']): ?>
                                <span class="row-sub"><?= e($alert['resolved_by'] ?: '系统') ?> · <?= e(fmt_datetime($alert['resolved_at'], 'm-d H:i')) ?> 恢复</span>
                            <?php elseif ($alert['acknowledged_at']): ?>
                                <span class="row-sub"><?= e($alert['acknowledged_by']) ?> 确认</span>
                            <?php endif; ?>
                        </td>
                        <td class="t-right">
                            <div class="actions">
                                <?php if ($alert['status'] === '活跃'): ?>
                                    <form method="post" action="/alerts.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="ack">
                                        <input type="hidden" name="alert_id" value="<?= (int) $alert['id'] ?>">
                                        <button class="link-action" type="submit"><?= icon_svg('check', 12) ?> 确认</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($alert['status'], ['活跃', '已确认'], true)): ?>
                                    <form method="post" action="/alerts.php" data-confirm="确认将告警 <?= e($alert['alert_no']) ?> 标记为已恢复？">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="resolve">
                                        <input type="hidden" name="alert_id" value="<?= (int) $alert['id'] ?>">
                                        <button class="link-action is-teal" type="submit"><?= icon_svg('check', 12) ?> 恢复</button>
                                    </form>
                                    <a class="link-action" href="/tickets.php?new=1&amp;alert=<?= e(urlencode($alert['alert_no'])) ?>"><?= icon_svg('tickets', 12) ?> 工单</a>
                                <?php else: ?>
                                    <span class="muted small">处置完成</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pager">
            <span>共 <?= count($alerts) ?> 条告警 · 未恢复 <?= $stats['open'] ?> 条</span>
            <span class="muted">告警确认与恢复操作均记录操作人和时间</span>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
