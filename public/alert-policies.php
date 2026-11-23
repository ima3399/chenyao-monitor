<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'toggle') {
        $policyId = (int) post_str('policy_id');
        $stmt = $pdo->prepare('SELECT policy_name, enabled FROM alert_policies WHERE id = ?');
        $stmt->execute([$policyId]);
        $policy = $stmt->fetch();
        if (!$policy) {
            flash('error', '策略不存在或已移除。');
        } else {
            $nextEnabled = (int) $policy['enabled'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE alert_policies SET enabled = ?, updated_at = ? WHERE id = ?')
                ->execute([$nextEnabled, date('Y-m-d H:i:s'), $policyId]);
            flash('success', '策略「' . $policy['policy_name'] . '」已' . ($nextEnabled ? '启用' : '停用') . '。');
        }
        redirect('/alert-policies.php');
    }

    if ($action === 'create') {
        $policyName = post_str('policy_name');
        $metricCode = post_str('metric_code');
        $thresholdRaw = post_str('threshold_value');
        $duration = (int) post_str('duration_min', '5');
        $severity = post_str('severity');
        $targetGroup = post_str('target_group');
        $notifyChannel = post_str('notify_channel');
        $description = post_str('description');

        $metricStmt = $pdo->prepare('SELECT metric_code FROM metric_catalog WHERE metric_code = ?');
        $metricStmt->execute([$metricCode]);
        $metricExists = (bool) $metricStmt->fetch();

        $validSeverities = ['严重', '警告', '提示'];
        $groupNames = $pdo->query('SELECT group_name FROM monitor_groups ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN);
        $validGroups = array_merge(['全部主机'], $groupNames);
        $validChannels = ['企业微信', '短信 / 企业微信', '电话 / 企业微信', '邮件', '邮件 / 企业微信', '短信 / 电话'];

        $errors = [];
        if ($policyName === '' || mb_strlen($policyName) > 60) {
            $errors[] = '策略名称不能为空且不超过 60 个字符。';
        }
        if (!$metricExists) {
            $errors[] = '请选择有效的指标。';
        }
        if (!is_numeric($thresholdRaw)) {
            $errors[] = '阈值必须为数字。';
        }
        if ($duration < 1 || $duration > 1440) {
            $errors[] = '持续时间需在 1 至 1440 分钟之间。';
        }
        if (!in_array($severity, $validSeverities, true)) {
            $errors[] = '请选择有效的告警级别。';
        }
        if (!in_array($targetGroup, $validGroups, true)) {
            $errors[] = '请选择有效的目标范围。';
        }
        if (!in_array($notifyChannel, $validChannels, true)) {
            $errors[] = '请选择有效的通知方式。';
        }

        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            $policyCode = policy_code_next($pdo);
            $check = $pdo->prepare('SELECT COUNT(*) FROM alert_policies WHERE policy_code = ?');
            $check->execute([$policyCode]);
            if ((int) $check->fetchColumn() > 0) {
                $policyCode = 'POL-CUS-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
            }
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("
                INSERT INTO alert_policies
                (policy_code, policy_name, metric_code, threshold_value, duration_min, severity, target_group, notify_channel, enabled, description, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $policyCode,
                mb_substr($policyName, 0, 60),
                $metricCode,
                (float) $thresholdRaw,
                $duration,
                $severity,
                $targetGroup,
                $notifyChannel,
                mb_substr($description, 0, 400),
                current_operator(),
                $now,
                $now,
            ]);
            flash('success', '告警策略「' . $policyName . '」已创建，编号 ' . $policyCode . '。');
        }
        redirect('/alert-policies.php');
    }

    redirect('/alert-policies.php');
}

$stats = [
    'total' => (int) $pdo->query('SELECT COUNT(*) FROM alert_policies')->fetchColumn(),
    'enabled' => (int) $pdo->query('SELECT COUNT(*) FROM alert_policies WHERE enabled = 1')->fetchColumn(),
    'critical' => (int) $pdo->query("SELECT COUNT(*) FROM alert_policies WHERE severity = '严重' AND enabled = 1")->fetchColumn(),
    'disabled' => (int) $pdo->query('SELECT COUNT(*) FROM alert_policies WHERE enabled = 0')->fetchColumn(),
];

$metrics = $pdo->query('SELECT * FROM metric_catalog ORDER BY sort_order')->fetchAll();
$groups = $pdo->query('SELECT group_name FROM monitor_groups ORDER BY sort_order')->fetchAll();
$keyword = q('keyword');
$severityFilter = q('severity');
$enabledFilter = q('enabled');
if (!in_array($severityFilter, ['严重', '警告', '提示', ''], true)) {
    $severityFilter = '';
}
if (!in_array($enabledFilter, ['1', '0', ''], true)) {
    $enabledFilter = '';
}

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(p.policy_name LIKE ? OR p.policy_code LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}
if ($severityFilter !== '') {
    $where[] = 'p.severity = ?';
    $params[] = $severityFilter;
}
if ($enabledFilter !== '') {
    $where[] = 'p.enabled = ?';
    $params[] = (int) $enabledFilter;
}
$sql = "
    SELECT p.*, mc.metric_name, mc.unit,
           (SELECT COUNT(*) FROM alerts a WHERE a.policy_id = p.id) AS alert_count,
           (SELECT COUNT(*) FROM hosts h JOIN monitor_groups g ON g.id = h.group_id
            WHERE p.target_group = '全部主机' OR g.group_name = p.target_group) AS target_host_count
    FROM alert_policies p
    LEFT JOIN metric_catalog mc ON mc.metric_code = p.metric_code
";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY p.enabled DESC, p.updated_at DESC, p.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$policies = $stmt->fetchAll();

$pageTitle = '告警策略';
$active = 'policies';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <div class="page-eyebrow">Alerting Policy</div>
        <h1 class="page-title">告警策略</h1>
        <p class="page-sub">定义指标阈值、持续窗口与通知范围，让关键异常及时进入处置流程。</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="#newPolicy"><?= icon_svg('plus', 15) ?> 新建告警策略</a>
    </div>
</div>

<div class="alert-severity-tiles">
    <div class="severity-tile">
        <span class="severity-tile-icon"><?= icon_svg('policies', 16) ?></span>
        <div><span>策略总数</span><strong><?= $stats['total'] ?></strong></div>
    </div>
    <div class="severity-tile is-success">
        <span class="severity-tile-icon"><?= icon_svg('check', 16) ?></span>
        <div><span>启用中</span><strong><?= $stats['enabled'] ?></strong></div>
    </div>
    <div class="severity-tile is-danger">
        <span class="severity-tile-icon"><?= icon_svg('warning', 16) ?></span>
        <div><span>启用中的严重策略</span><strong><?= $stats['critical'] ?></strong></div>
    </div>
    <div class="severity-tile">
        <span class="severity-tile-icon"><?= icon_svg('pause', 16) ?></span>
        <div><span>已停用</span><strong><?= $stats['disabled'] ?></strong></div>
    </div>
</div>

<div class="filter-card">
    <form class="filter-form" method="get" action="/alert-policies.php">
        <label class="field-inline">
            <?= icon_svg('search', 14) ?>
            <input class="input" type="search" name="keyword" value="<?= e($keyword) ?>" placeholder="搜索策略名称 / 编号">
        </label>
        <select class="select" name="severity" aria-label="告警级别">
            <option value="">全部级别</option>
            <?php foreach (['严重', '警告', '提示'] as $option): ?>
                <option value="<?= e($option) ?>"<?= $severityFilter === $option ? ' selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="enabled" aria-label="启用状态">
            <option value="">全部状态</option>
            <option value="1"<?= $enabledFilter === '1' ? ' selected' : '' ?>>启用中</option>
            <option value="0"<?= $enabledFilter === '0' ? ' selected' : '' ?>>已停用</option>
        </select>
        <button class="btn btn-sm" type="submit"><?= icon_svg('filter', 13) ?> 筛选</button>
        <?php if ($keyword !== '' || $severityFilter !== '' || $enabledFilter !== ''): ?>
            <a class="link-action" href="/alert-policies.php">清除条件</a>
        <?php endif; ?>
    </form>
    <div class="filter-meta">当前显示 <strong><?= count($policies) ?></strong> 条策略</div>
</div>

<div class="ticket-layout">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('shield', 16) ?></span> 策略清单</h3>
                <p class="panel-sub">策略按启用状态与最近更新时间排序</p>
            </div>
            <span class="filter-meta">最近评估 <?= e(fmt_datetime($pdo->query('SELECT MAX(updated_at) FROM alert_policies')->fetchColumn(), 'm-d H:i')) ?></span>
        </div>
        <div class="panel-body is-flush">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>策略名称</th>
                            <th>监控指标</th>
                            <th>触发条件</th>
                            <th>适用范围</th>
                            <th>级别</th>
                            <th>通知</th>
                            <th>状态</th>
                            <th class="t-right">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$policies): ?>
                        <tr><td colspan="8"><div class="empty-state"><?= icon_svg('search', 24) ?>没有符合条件的策略</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($policies as $policy): ?>
                        <tr>
                            <td>
                                <span class="table-primary"><?= e($policy['policy_name']) ?></span>
                                <span class="row-sub"><span class="mono"><?= e($policy['policy_code']) ?></span> · 更新 <?= e(fmt_datetime($policy['updated_at'], 'm-d H:i')) ?></span>
                            </td>
                            <td>
                                <span><?= e($policy['metric_name'] ?: $policy['metric_code']) ?></span>
                                <span class="row-sub"><?= e($policy['metric_code']) ?></span>
                            </td>
                            <td class="nowrap">
                                <span class="mono">&gt; <?= e($policy['threshold_value']) ?><?= e($policy['unit']) ?></span>
                                <span class="row-sub">持续 <?= (int) $policy['duration_min'] ?> 分钟</span>
                            </td>
                            <td>
                                <span><?= e($policy['target_group']) ?></span>
                                <span class="row-sub"><?= (int) $policy['target_host_count'] ?> 台主机</span>
                            </td>
                            <td><span class="badge badge-<?= e(severity_class($policy['severity'])) ?>"><?= e($policy['severity']) ?></span></td>
                            <td class="muted"><?= e($policy['notify_channel']) ?></td>
                            <td>
                                <span class="badge badge-<?= $policy['enabled'] ? 'success' : 'neutral' ?>"><?= $policy['enabled'] ? '启用中' : '已停用' ?></span>
                                <span class="row-sub"><?= (int) $policy['alert_count'] ?> 次命中</span>
                            </td>
                            <td class="t-right">
                                <form method="post" action="/alert-policies.php" data-confirm="确认<?= $policy['enabled'] ? '停用' : '启用' ?>策略「<?= e($policy['policy_name']) ?>」？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="policy_id" value="<?= (int) $policy['id'] ?>">
                                    <button class="link-action <?= $policy['enabled'] ? 'is-danger' : 'is-teal' ?>" type="submit">
                                        <?= icon_svg($policy['enabled'] ? 'pause' : 'play', 12) ?><?= $policy['enabled'] ? '停用' : '启用' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pager">
                <span>共 <?= count($policies) ?> 条策略 · <?= $stats['enabled'] ?> 条正在评估</span>
                <span class="muted">策略变更记录在平台日志中</span>
            </div>
        </div>
    </section>

    <aside class="panel sticky-form" id="newPolicy">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('plus', 16) ?></span> 新建告警策略</h3>
                <p class="panel-sub">保存后立即加入指标评估</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="post" action="/alert-policies.php" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="field span-2">
                    <label for="policy_name">策略名称</label>
                    <input class="input" id="policy_name" name="policy_name" type="text" maxlength="60" placeholder="例如：网关 CPU 使用率预警" required>
                </div>
                <div class="field">
                    <label for="metric_code">监控指标</label>
                    <select class="select" id="metric_code" name="metric_code" required>
                        <?php foreach ($metrics as $metric): ?>
                            <option value="<?= e($metric['metric_code']) ?>"><?= e($metric['metric_name']) ?>（<?= e($metric['unit'] ?: '无单位') ?>）</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="threshold_value">阈值</label>
                    <input class="input" id="threshold_value" name="threshold_value" type="number" step="0.01" min="0" placeholder="90" required>
                </div>
                <div class="field">
                    <label for="duration_min">持续时长（分钟）</label>
                    <input class="input" id="duration_min" name="duration_min" type="number" min="1" max="1440" value="5" required>
                </div>
                <div class="field">
                    <label for="severity">告警级别</label>
                    <select class="select" id="severity" name="severity" required>
                        <option value="严重">严重</option>
                        <option value="警告" selected>警告</option>
                        <option value="提示">提示</option>
                    </select>
                </div>
                <div class="field">
                    <label for="target_group">适用范围</label>
                    <select class="select" id="target_group" name="target_group" required>
                        <option value="全部主机">全部主机</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= e($group['group_name']) ?>"><?= e($group['group_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="notify_channel">通知方式</label>
                    <select class="select" id="notify_channel" name="notify_channel" required>
                        <option value="企业微信">企业微信</option>
                        <option value="短信 / 企业微信">短信 / 企业微信</option>
                        <option value="电话 / 企业微信">电话 / 企业微信</option>
                        <option value="邮件">邮件</option>
                        <option value="邮件 / 企业微信">邮件 / 企业微信</option>
                        <option value="短信 / 电话">短信 / 电话</option>
                    </select>
                </div>
                <div class="field span-2">
                    <label for="description">策略说明</label>
                    <textarea class="textarea" id="description" name="description" maxlength="400" placeholder="说明适用场景、处置建议或阈值依据"></textarea>
                </div>
                <div class="span-2 form-hint">策略保存后默认启用。变更和命中记录将写入平台日志与告警记录。</div>
                <div class="span-2 form-actions">
                    <a class="btn btn-sm btn-ghost" href="/alert-policies.php">取消</a>
                    <button class="btn btn-sm btn-primary" type="submit">创建策略</button>
                </div>
            </form>
        </div>
    </aside>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
