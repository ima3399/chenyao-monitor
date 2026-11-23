<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');
    $agentId = (int) post_str('agent_id');
    $stmt = $pdo->prepare('SELECT a.*, h.hostname FROM agents a JOIN hosts h ON h.id = a.host_id WHERE a.id = ?');
    $stmt->execute([$agentId]);
    $agent = $stmt->fetch();
    if (!$agent) {
        flash('error', '采集代理不存在。');
        redirect('/agents.php');
    }

    $now = date('Y-m-d H:i:s');
    if ($action === 'rotate') {
        $tokenHint = 'cyagt_' . substr(bin2hex(random_bytes(4)), 0, 8) . '...' . substr(bin2hex(random_bytes(2)), 0, 4);
        $pdo->prepare('UPDATE agents SET token_hint = ?, note = ? WHERE id = ?')
            ->execute([$tokenHint, '访问令牌已于 ' . date('m-d H:i') . ' 轮换', $agentId]);
        $pdo->prepare('INSERT INTO system_events (event_type, title, detail, actor, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute(['agent', '采集代理令牌已轮换', $agent['hostname'] . ' 的代理访问令牌完成轮换。', current_operator(), $now]);
        flash('success', '代理 ' . $agent['agent_id'] . ' 的访问令牌已轮换，请同步更新部署配置。');
    } elseif ($action === 'pause' && $agent['status'] !== '已暂停') {
        $pdo->prepare("UPDATE agents SET status = '已暂停', note = ? WHERE id = ?")
            ->execute(['由 ' . current_operator() . ' 于 ' . date('m-d H:i') . ' 暂停采集', $agentId]);
        flash('success', '代理 ' . $agent['agent_id'] . ' 已暂停采集。');
    } elseif ($action === 'resume' && $agent['status'] === '已暂停') {
        $pdo->prepare("UPDATE agents SET status = '运行中', last_heartbeat_at = ?, note = ? WHERE id = ?")
            ->execute([$now, '由 ' . current_operator() . ' 于 ' . date('m-d H:i') . ' 恢复采集', $agentId]);
        flash('success', '代理 ' . $agent['agent_id'] . ' 已恢复采集。');
    } else {
        flash('warn', '当前代理状态不支持该操作。');
    }
    redirect('/agents.php');
}

$stats = [
    'total' => (int) $pdo->query('SELECT COUNT(*) FROM agents')->fetchColumn(),
    'running' => (int) $pdo->query("SELECT COUNT(*) FROM agents WHERE status = '运行中'")->fetchColumn(),
    'timeout' => (int) $pdo->query("SELECT COUNT(*) FROM agents WHERE status = '心跳超时'")->fetchColumn(),
    'upgrade' => (int) $pdo->query("SELECT COUNT(*) FROM agents WHERE status = '待升级'")->fetchColumn(),
    'paused' => (int) $pdo->query("SELECT COUNT(*) FROM agents WHERE status = '已暂停'")->fetchColumn(),
];
$hostTotal = (int) $pdo->query('SELECT COUNT(*) FROM hosts')->fetchColumn();

$groups = $pdo->query('SELECT id, group_name FROM monitor_groups ORDER BY sort_order')->fetchAll();
$keyword = q('keyword');
$statusFilter = q('status');
$groupId = query_int('group');
$statusOptions = ['运行中', '心跳超时', '待升级', '已暂停'];
if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = '';
}

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(a.agent_id LIKE ? OR h.hostname LIKE ? OR a.ip_address LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like);
}
if ($statusFilter !== '') {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}
if ($groupId > 0) {
    $where[] = 'h.group_id = ?';
    $params[] = $groupId;
}
$sql = "
    SELECT a.*, h.hostname, h.os_name, h.status AS host_status, g.group_name, g.color
    FROM agents a
    JOIN hosts h ON h.id = a.host_id
    JOIN monitor_groups g ON g.id = h.group_id
";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY CASE a.status WHEN '心跳超时' THEN 1 WHEN '待升级' THEN 2 WHEN '已暂停' THEN 3 ELSE 4 END, h.hostname";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$agents = $stmt->fetchAll();

$versionRows = $pdo->query('SELECT version, COUNT(*) AS count FROM agents GROUP BY version ORDER BY version DESC')->fetchAll();
$versionMax = max(1, ...array_map(static fn (array $row): int => (int) $row['count'], $versionRows));
$groupCoverage = $pdo->query("
    SELECT g.group_name, g.color, COUNT(a.id) AS agent_count, COUNT(h.id) AS host_count
    FROM monitor_groups g
    LEFT JOIN hosts h ON h.group_id = g.id
    LEFT JOIN agents a ON a.host_id = h.id
    GROUP BY g.id
    ORDER BY g.sort_order
")->fetchAll();
$sampleAgent = $agents[0] ?? null;
$sampleToken = $sampleAgent['token_hint'] ?? 'cyagt_8f4c...9c21';

$pageTitle = '采集代理';
$active = 'agents';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <div class="page-eyebrow">Collection Agent</div>
        <h1 class="page-title">采集代理</h1>
        <p class="page-sub">管理主机采集代理的部署状态、版本、心跳与访问凭证。</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="/export.php?type=agents"><?= icon_svg('download', 15) ?> 导出代理清单</a>
        <a class="btn btn-primary" href="/hosts.php"><?= icon_svg('hosts', 15) ?> 主机资产</a>
    </div>
</div>

<div class="stat-strip">
    <div class="strip-item"><span>代理总数</span><strong><?= $stats['total'] ?><small>个</small></strong></div>
    <div class="strip-item"><span>运行中</span><strong class="text-teal"><?= $stats['running'] ?><small>个</small></strong></div>
    <div class="strip-item"><span>心跳超时</span><strong class="text-red"><?= $stats['timeout'] ?><small>个</small></strong></div>
    <div class="strip-item"><span>待升级</span><strong class="text-amber"><?= $stats['upgrade'] ?><small>个</small></strong></div>
    <div class="strip-item"><span>采集覆盖</span><strong><?= percent($stats['total'], $hostTotal) ?></strong></div>
</div>

<div class="filter-card">
    <form class="filter-form" method="get" action="/agents.php">
        <label class="field-inline">
            <?= icon_svg('search', 14) ?>
            <input class="input" type="search" name="keyword" value="<?= e($keyword) ?>" placeholder="代理编号 / 主机名 / IP">
        </label>
        <select class="select" name="status" aria-label="代理状态">
            <option value="">全部状态</option>
            <?php foreach ($statusOptions as $option): ?>
                <option value="<?= e($option) ?>"<?= $statusFilter === $option ? ' selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="group" aria-label="监控分组">
            <option value="0">全部分组</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>"<?= $groupId === (int) $group['id'] ? ' selected' : '' ?>><?= e($group['group_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm" type="submit"><?= icon_svg('filter', 13) ?> 筛选</button>
        <?php if ($keyword !== '' || $statusFilter !== '' || $groupId > 0): ?>
            <a class="link-action" href="/agents.php">清除条件</a>
        <?php endif; ?>
    </form>
    <div class="filter-meta">当前显示 <strong><?= count($agents) ?></strong> 个代理</div>
</div>

<section class="panel">
    <div class="panel-head">
        <div>
            <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('agents', 16) ?></span> 代理运行清单</h3>
            <p class="panel-sub">心跳间隔超过 300 秒的代理会进入心跳超时状态</p>
        </div>
        <span class="filter-meta"><?= icon_svg('refresh', 12) ?> 状态每 60 秒刷新</span>
    </div>
    <div class="panel-body is-flush">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>代理 / 主机</th>
                        <th>部署方式</th>
                        <th>平台</th>
                        <th>版本</th>
                        <th>采集范围</th>
                        <th>最近心跳</th>
                        <th>状态</th>
                        <th>访问令牌</th>
                        <th class="t-right">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$agents): ?>
                    <tr><td colspan="9"><div class="empty-state"><?= icon_svg('search', 24) ?>没有符合条件的采集代理</div></td></tr>
                <?php endif; ?>
                <?php foreach ($agents as $agent): ?>
                    <?php
                    $health = match ($agent['status']) {
                        '运行中' => 100,
                        '待升级' => 72,
                        '心跳超时' => 12,
                        '已暂停' => 35,
                        default => 50,
                    };
                    $healthTone = $agent['status'] === '心跳超时' ? 'is-danger' : ($agent['status'] === '运行中' ? '' : 'is-warning');
                    ?>
                    <tr>
                        <td>
                            <div class="host-cell">
                                <span class="host-avatar"><?= e(substr($agent['hostname'], -2)) ?></span>
                                <span class="host-cell-body">
                                    <strong><?= e($agent['hostname']) ?></strong>
                                    <span><span class="mono"><?= e($agent['agent_id']) ?></span> · <?= e($agent['ip_address']) ?></span>
                                </span>
                            </div>
                        </td>
                        <td><?= e($agent['install_type']) ?></td>
                        <td class="muted nowrap"><?= e($agent['platform']) ?></td>
                        <td><span class="badge badge-<?= $agent['version'] === '4.2.6' ? 'success' : 'warning' ?> no-dot">v<?= e($agent['version']) ?></span></td>
                        <td>
                            <span class="mono small muted"><?= e($agent['collector_scope']) ?></span>
                            <span class="row-sub"><span class="group-chip"><i style="background:<?= e($agent['color']) ?>"></i><?= e($agent['group_name']) ?></span></span>
                        </td>
                        <td>
                            <div class="health-cell">
                                <div class="mini-health-bar"><i class="<?= $healthTone ?>" style="width:<?= $health ?>%"></i></div>
                                <time><?= e(relative_time($agent['last_heartbeat_at'])) ?></time>
                            </div>
                            <span class="row-sub"><?= e(fmt_datetime($agent['last_heartbeat_at'], 'm-d H:i:s')) ?></span>
                        </td>
                        <td><span class="badge badge-<?= e(agent_status_class($agent['status'])) ?>"><?= e($agent['status']) ?></span></td>
                        <td><span class="token"><?= e($agent['token_hint']) ?></span></td>
                        <td class="t-right">
                            <div class="actions">
                                <a class="link-action" href="/hosts.php?host=<?= e(urlencode($agent['hostname'])) ?>"><?= icon_svg('eye', 12) ?> 主机</a>
                                <form method="post" action="/agents.php" data-confirm="轮换后旧令牌立即失效，确认轮换 <?= e($agent['agent_id']) ?> 的访问令牌？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="rotate">
                                    <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                                    <button class="link-action" type="submit"><?= icon_svg('key', 12) ?> 轮换</button>
                                </form>
                                <?php if ($agent['status'] === '已暂停'): ?>
                                    <form method="post" action="/agents.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="resume">
                                        <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                                        <button class="link-action is-teal" type="submit"><?= icon_svg('play', 12) ?> 恢复</button>
                                    </form>
                                <?php elseif (in_array($agent['status'], ['运行中', '待升级'], true)): ?>
                                    <form method="post" action="/agents.php" data-confirm="确认暂停 <?= e($agent['agent_id']) ?> 的指标采集？">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="pause">
                                        <input type="hidden" name="agent_id" value="<?= (int) $agent['id'] ?>">
                                        <button class="link-action is-danger" type="submit"><?= icon_svg('pause', 12) ?> 暂停</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pager">
            <span>共 <?= count($agents) ?> 个代理 · 覆盖 <?= $stats['total'] ?>/<?= $hostTotal ?> 台主机</span>
            <span class="muted">令牌仅以掩码形式展示</span>
        </div>
    </div>
</section>

<div class="dashboard-grid equal mt-16">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('layers', 16) ?></span> 版本与状态分布</h3>
                <p class="panel-sub">当前代理版本收敛与分组覆盖情况</p>
            </div>
        </div>
        <div class="panel-body">
            <div class="report-summary">
                <?php foreach ($versionRows as $row): ?>
                    <div class="report-bar-row">
                        <span class="report-bar-label">v<?= e($row['version']) ?></span>
                        <span class="report-bar-track"><i style="width:<?= number_format((int) $row['count'] / $versionMax * 100, 1) ?>%;--bar-color:<?= $row['version'] === '4.2.6' ? 'var(--teal)' : 'var(--amber)' ?>"></i></span>
                        <span class="report-bar-value"><?= (int) $row['count'] ?> 个</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="panel-note" style="margin-top:16px">
                <strong style="color:var(--ink-soft)">分组覆盖：</strong>
                <?php foreach ($groupCoverage as $group): ?>
                    <span class="tag" style="margin-left:4px"><?= e($group['group_name']) ?> <?= (int) $group['agent_count'] ?>/<?= (int) $group['host_count'] ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('terminal', 16) ?></span> 代理接入指引</h3>
                <p class="panel-sub">新节点注册时使用平台访问令牌</p>
            </div>
        </div>
        <div class="panel-body">
            <div class="agent-install">
                <code>curl -fsSL https://packages.chenyao-tech.cn/cymon-agent/install.sh | sudo bash -s -- --token <?= e($sampleToken) ?> --server https://monitor.chenyao-tech.cn:18114</code>
                <button class="icon-button" type="button" data-copy="curl -fsSL https://packages.chenyao-tech.cn/cymon-agent/install.sh | sudo bash -s -- --token <?= e($sampleToken) ?> --server https://monitor.chenyao-tech.cn:18114" title="复制安装命令" aria-label="复制安装命令"><?= icon_svg('file', 14) ?></button>
            </div>
            <dl class="definition-list" style="margin-top:13px">
                <div><dt>心跳周期</dt><dd>30 秒</dd></div>
                <div><dt>指标汇聚</dt><dd>60 秒</dd></div>
                <div><dt>超时判定</dt><dd>300 秒</dd></div>
                <div><dt>推荐版本</dt><dd>v4.2.6</dd></div>
            </dl>
        </div>
    </section>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
