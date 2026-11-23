<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$user = current_user();
$team = ['韩沐川', '程意', '苏临川', '江屿', '顾清越', '温以宁', '季明澈'];
$categories = ['性能异常', '节点离线', '容量规划', '服务异常', '网络质量', '性能优化', '作业失败', '配置变更', '存储性能'];
$priorities = ['紧急', '高', '中', '低'];
$statuses = ['待处理', '处理中', '已解决', '已关闭'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_str('action');

    if ($action === 'create') {
        $title = post_str('title');
        $category = post_str('category');
        $priority = post_str('priority');
        $assignee = post_str('assignee');
        $description = post_str('description');
        $alertId = (int) post_str('alert_id');

        $errors = [];
        if ($title === '' || mb_strlen($title) > 100) {
            $errors[] = '工单标题不能为空且不超过 100 个字符。';
        }
        if (!in_array($category, $categories, true)) {
            $errors[] = '请选择有效的问题分类。';
        }
        if (!in_array($priority, $priorities, true)) {
            $errors[] = '请选择有效的优先级。';
        }
        if (!in_array($assignee, $team, true)) {
            $errors[] = '请选择有效的责任人。';
        }

        $linkedAlert = null;
        if ($alertId > 0) {
            $alertStmt = $pdo->prepare('SELECT id, alert_no FROM alerts WHERE id = ?');
            $alertStmt->execute([$alertId]);
            $linkedAlert = $alertStmt->fetch() ?: null;
            if (!$linkedAlert) {
                $alertId = 0;
            }
        }

        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/tickets.php?new=1');
        }

        $now = date('Y-m-d H:i:s');
        $ticketNo = ticket_no_next($pdo);
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO tickets
                (ticket_no, title, category, priority, status, source, assignee, reporter, alert_id, description, created_at, updated_at, resolved_at)
                VALUES (?, ?, ?, ?, '待处理', ?, ?, ?, ?, ?, ?, ?, NULL)
            ");
            $stmt->execute([
                $ticketNo,
                mb_substr($title, 0, 100),
                $category,
                $priority,
                $linkedAlert ? '告警关联' : '手动创建',
                $assignee,
                current_operator(),
                $alertId ?: null,
                mb_substr($description, 0, 1200),
                $now,
                $now,
            ]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO ticket_activities (ticket_id, actor, action, detail, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([
                    $newId,
                    current_operator(),
                    '创建工单',
                    $linkedAlert ? '关联告警 ' . $linkedAlert['alert_no'] . '，指派至' . $assignee . '。' : '创建事件工单并指派至' . $assignee . '。',
                    $now,
                ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', '工单保存失败，请稍后重试。');
            redirect('/tickets.php?new=1');
        }
        flash('success', '事件工单 ' . $ticketNo . ' 已创建。');
        redirect('/tickets.php?ticket=' . urlencode($ticketNo));
    }

    if ($action === 'update') {
        $ticketId = (int) post_str('ticket_id');
        $newStatus = post_str('status');
        $newAssignee = post_str('assignee');
        $note = post_str('note');
        $stmt = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            flash('error', '工单不存在或已归档。');
            redirect('/tickets.php');
        }
        if (!in_array($newStatus, $statuses, true) || !in_array($newAssignee, $team, true)) {
            flash('error', '请选择有效的工单状态和责任人员。');
            redirect('/tickets.php?ticket=' . urlencode($ticket['ticket_no']));
        }

        $now = date('Y-m-d H:i:s');
        $resolvedAt = in_array($newStatus, ['已解决', '已关闭'], true)
            ? ($ticket['resolved_at'] ?: $now)
            : null;
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE tickets SET status = ?, assignee = ?, updated_at = ?, resolved_at = ? WHERE id = ?')
            ->execute([$newStatus, $newAssignee, $now, $resolvedAt, $ticketId]);
        $changeNotes = [];
        if ($ticket['status'] !== $newStatus) {
            $changeNotes[] = '状态 ' . $ticket['status'] . ' → ' . $newStatus;
        }
        if ($ticket['assignee'] !== $newAssignee) {
            $changeNotes[] = '责任人 ' . $ticket['assignee'] . ' → ' . $newAssignee;
        }
        if ($note !== '') {
            $changeNotes[] = mb_substr($note, 0, 500);
        }
        if (!$changeNotes) {
            $changeNotes[] = '更新工单信息';
        }
        $pdo->prepare('INSERT INTO ticket_activities (ticket_id, actor, action, detail, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ticketId, current_operator(), '更新工单', implode('；', $changeNotes), $now]);
        $pdo->commit();
        flash('success', '工单 ' . $ticket['ticket_no'] . ' 已更新。');
        redirect('/tickets.php?ticket=' . urlencode($ticket['ticket_no']));
    }

    redirect('/tickets.php');
}

$stats = [
    'total' => (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = '待处理'")->fetchColumn(),
    'processing' => (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = '处理中'")->fetchColumn(),
    'done' => (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('已解决', '已关闭')")->fetchColumn(),
];

$keyword = q('keyword');
$statusFilter = q('status');
$priorityFilter = q('priority');
if (!in_array($statusFilter, $statuses, true)) {
    $statusFilter = '';
}
if (!in_array($priorityFilter, $priorities, true)) {
    $priorityFilter = '';
}

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(t.ticket_no LIKE ? OR t.title LIKE ? OR t.assignee LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like);
}
if ($statusFilter !== '') {
    $where[] = 't.status = ?';
    $params[] = $statusFilter;
}
if ($priorityFilter !== '') {
    $where[] = 't.priority = ?';
    $params[] = $priorityFilter;
}
$sql = "
    SELECT t.*, a.alert_no, a.severity, h.hostname
    FROM tickets t
    LEFT JOIN alerts a ON a.id = t.alert_id
    LEFT JOIN hosts h ON h.id = a.host_id
";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY CASE t.status WHEN '待处理' THEN 1 WHEN '处理中' THEN 2 WHEN '已解决' THEN 3 ELSE 4 END,
          CASE t.priority WHEN '紧急' THEN 1 WHEN '高' THEN 2 WHEN '中' THEN 3 ELSE 4 END,
          t.updated_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$selectedTicket = null;
$activities = [];
$selectedNo = q('ticket');
if ($selectedNo !== '') {
    $stmt = $pdo->prepare("
        SELECT t.*, a.alert_no, a.severity AS alert_severity, a.message AS alert_message, a.status AS alert_status,
               h.hostname, h.ip_address, g.group_name
        FROM tickets t
        LEFT JOIN alerts a ON a.id = t.alert_id
        LEFT JOIN hosts h ON h.id = a.host_id
        LEFT JOIN monitor_groups g ON g.id = h.group_id
        WHERE t.ticket_no = ?
    ");
    $stmt->execute([$selectedNo]);
    $selectedTicket = $stmt->fetch() ?: null;
    if ($selectedTicket) {
        $activityStmt = $pdo->prepare('SELECT * FROM ticket_activities WHERE ticket_id = ? ORDER BY created_at ASC, id ASC');
        $activityStmt->execute([(int) $selectedTicket['id']]);
        $activities = $activityStmt->fetchAll();
    }
}

$prefillAlert = null;
$prefillHost = null;
$alertParam = q('alert');
$hostParam = q('host');
if (!$selectedTicket) {
    if ($alertParam !== '') {
        $stmt = $pdo->prepare('SELECT a.id, a.alert_no, a.title, h.hostname FROM alerts a JOIN hosts h ON h.id = a.host_id WHERE a.alert_no = ?');
        $stmt->execute([$alertParam]);
        $prefillAlert = $stmt->fetch() ?: null;
    } elseif ($hostParam !== '') {
        $stmt = $pdo->prepare('SELECT hostname, ip_address FROM hosts WHERE hostname = ?');
        $stmt->execute([$hostParam]);
        $prefillHost = $stmt->fetch() ?: null;
    }
}

$prefillTitle = '';
$prefillDescription = '';
if ($prefillAlert) {
    $prefillTitle = '跟进告警：' . $prefillAlert['title'];
    $prefillDescription = '关联告警 ' . $prefillAlert['alert_no'] . '，涉及主机 ' . $prefillAlert['hostname'] . '。';
} elseif ($prefillHost) {
    $prefillTitle = $prefillHost['hostname'] . ' 运行异常跟进';
    $prefillDescription = '涉及主机 ' . $prefillHost['hostname'] . '（' . $prefillHost['ip_address'] . '）。';
}

$pageTitle = '事件工单';
$active = 'tickets';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <div class="page-eyebrow">Incident Response</div>
        <h1 class="page-title">事件工单</h1>
        <p class="page-sub">将告警与运维事件纳入统一处置流程，明确责任人与恢复进度。</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="/export.php?type=tickets"><?= icon_svg('download', 15) ?> 导出工单</a>
        <a class="btn btn-primary" href="/tickets.php?new=1#newTicket"><?= icon_svg('plus', 15) ?> 新建工单</a>
    </div>
</div>

<div class="stat-strip">
    <div class="strip-item"><span>全部工单</span><strong><?= $stats['total'] ?><small>个</small></strong></div>
    <div class="strip-item"><span>待处理</span><strong class="text-red"><?= $stats['pending'] ?><small>个</small></strong></div>
    <div class="strip-item"><span>处理中</span><strong class="text-amber"><?= $stats['processing'] ?><small>个</small></strong></div>
    <div class="strip-item"><span>已解决 / 关闭</span><strong class="text-teal"><?= $stats['done'] ?><small>个</small></strong></div>
    <div class="strip-item"><span>平均响应</span><strong>18<small>分钟</small></strong></div>
</div>

<?php if ($selectedTicket): ?>
    <section class="panel ticket-detail-card">
        <div class="panel-head">
            <div class="ticket-title-line">
                <div>
                    <div class="page-eyebrow" style="margin-bottom:5px"><?= e($selectedTicket['ticket_no']) ?></div>
                    <h3><?= e($selectedTicket['title']) ?></h3>
                    <p><?= e($selectedTicket['description']) ?></p>
                </div>
            </div>
            <div class="panel-actions">
                <a class="btn btn-sm btn-ghost" href="/tickets.php">关闭详情</a>
                <?php if ($selectedTicket['alert_no']): ?>
                    <a class="btn btn-sm" href="/alerts.php?keyword=<?= e(urlencode($selectedTicket['alert_no'])) ?>"><?= icon_svg('alerts', 13) ?> 关联告警</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="panel-body">
            <div class="ticket-meta-grid">
                <div><span>当前状态</span><strong><span class="badge badge-<?= e(ticket_status_class($selectedTicket['status'])) ?> no-dot"><?= e($selectedTicket['status']) ?></span></strong></div>
                <div><span>优先级</span><strong><span class="badge badge-<?= e(priority_class($selectedTicket['priority'])) ?> no-dot"><?= e($selectedTicket['priority']) ?></span></strong></div>
                <div><span>问题分类</span><strong><?= e($selectedTicket['category']) ?></strong></div>
                <div><span>责任人员</span><strong><?= e($selectedTicket['assignee']) ?></strong></div>
                <div><span>报告人员</span><strong><?= e($selectedTicket['reporter']) ?></strong></div>
                <div><span>来源</span><strong><?= e($selectedTicket['source']) ?></strong></div>
                <div><span>创建时间</span><strong><?= e(fmt_datetime($selectedTicket['created_at'])) ?></strong></div>
                <div><span>最近更新</span><strong><?= e(fmt_datetime($selectedTicket['updated_at'])) ?></strong></div>
            </div>
            <?php if ($selectedTicket['alert_no']): ?>
                <div class="panel-note" style="margin-bottom:16px">
                    <strong style="color:var(--ink-soft)">关联告警：</strong>
                    <span class="mono"><?= e($selectedTicket['alert_no']) ?></span>
                    · <?= e($selectedTicket['hostname']) ?> · <?= e($selectedTicket['alert_message']) ?>
                    <span class="badge badge-<?= e(alert_status_class((string) $selectedTicket['alert_status'])) ?> no-dot" style="margin-left:6px"><?= e($selectedTicket['alert_status']) ?></span>
                </div>
            <?php endif; ?>
            <h4 style="margin:2px 0 11px;font-size:11px;color:var(--muted)">处置时间线</h4>
            <?php if (!$activities): ?>
                <div class="panel-note">暂无处置记录，请在右侧更新工单进展。</div>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($activities as $activity): ?>
                        <li class="timeline-item">
                            <strong><?= e($activity['action']) ?></strong>
                            <span> · <?= e($activity['actor']) ?> · <?= e(fmt_datetime($activity['created_at'], 'm-d H:i')) ?></span>
                            <p><?= e($activity['detail']) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<div class="filter-card">
    <form class="filter-form" method="get" action="/tickets.php">
        <label class="field-inline">
            <?= icon_svg('search', 14) ?>
            <input class="input" type="search" name="keyword" value="<?= e($keyword) ?>" placeholder="工单编号 / 标题 / 责任人">
        </label>
        <select class="select" name="status" aria-label="工单状态">
            <option value="">全部状态</option>
            <?php foreach ($statuses as $option): ?>
                <option value="<?= e($option) ?>"<?= $statusFilter === $option ? ' selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="priority" aria-label="优先级">
            <option value="">全部优先级</option>
            <?php foreach ($priorities as $option): ?>
                <option value="<?= e($option) ?>"<?= $priorityFilter === $option ? ' selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm" type="submit"><?= icon_svg('filter', 13) ?> 筛选</button>
        <?php if ($keyword !== '' || $statusFilter !== '' || $priorityFilter !== ''): ?>
            <a class="link-action" href="/tickets.php">清除条件</a>
        <?php endif; ?>
    </form>
    <div class="filter-meta">当前显示 <strong><?= count($tickets) ?></strong> 个工单</div>
</div>

<div class="ticket-layout">
    <section class="panel">
        <div class="panel-head">
            <div>
                <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('clipboard', 16) ?></span> 工单队列</h3>
                <p class="panel-sub">按状态与优先级排序，点击标题查看处置时间线</p>
            </div>
            <span class="filter-meta">最近更新 <?= e(fmt_datetime($pdo->query('SELECT MAX(updated_at) FROM tickets')->fetchColumn(), 'm-d H:i')) ?></span>
        </div>
        <div class="panel-body is-flush">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>工单</th>
                            <th>分类</th>
                            <th>优先级</th>
                            <th>责任人</th>
                            <th>关联主机</th>
                            <th>状态</th>
                            <th>最近更新</th>
                            <th class="t-right">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$tickets): ?>
                        <tr><td colspan="8"><div class="empty-state"><?= icon_svg('search', 24) ?>没有符合条件的工单</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($tickets as $ticket): ?>
                        <tr<?= $selectedTicket && (int) $selectedTicket['id'] === (int) $ticket['id'] ? ' style="background:rgba(240,169,75,.045)"' : '' ?>>
                            <td>
                                <a class="table-primary" href="/tickets.php?ticket=<?= e(urlencode($ticket['ticket_no'])) ?>"><?= e($ticket['title']) ?></a>
                                <span class="row-sub"><span class="mono"><?= e($ticket['ticket_no']) ?></span> · <?= e($ticket['source']) ?></span>
                            </td>
                            <td><?= e($ticket['category']) ?></td>
                            <td><span class="badge badge-<?= e(priority_class($ticket['priority'])) ?>"><?= e($ticket['priority']) ?></span></td>
                            <td><?= e($ticket['assignee']) ?></td>
                            <td>
                                <?php if ($ticket['hostname']): ?>
                                    <span class="mono"><?= e($ticket['hostname']) ?></span>
                                    <span class="row-sub"><span class="mono"><?= e($ticket['alert_no']) ?></span></span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-<?= e(ticket_status_class($ticket['status'])) ?> no-dot"><?= e($ticket['status']) ?></span></td>
                            <td class="nowrap">
                                <span><?= e(relative_time($ticket['updated_at'])) ?></span>
                                <span class="row-sub"><?= e(fmt_datetime($ticket['updated_at'], 'm-d H:i')) ?></span>
                            </td>
                            <td class="t-right">
                                <a class="link-action" href="/tickets.php?ticket=<?= e(urlencode($ticket['ticket_no'])) ?>"><?= icon_svg('eye', 13) ?> 查看</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pager">
                <span>共 <?= count($tickets) ?> 个工单 · 待处理 <?= $stats['pending'] ?> 个</span>
                <span class="muted">工单处置过程完整留痕</span>
            </div>
        </div>
    </section>

    <aside class="sticky-form" id="newTicket">
        <?php if ($selectedTicket): ?>
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('refresh', 16) ?></span> 更新工单</h3>
                        <p class="panel-sub"><?= e($selectedTicket['ticket_no']) ?> · 当前 <?= e($selectedTicket['status']) ?></p>
                    </div>
                </div>
                <div class="panel-body">
                    <form method="post" action="/tickets.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="ticket_id" value="<?= (int) $selectedTicket['id'] ?>">
                        <div class="form-grid">
                            <div class="field">
                                <label for="update_status">处理状态</label>
                                <select class="select" id="update_status" name="status">
                                    <?php foreach ($statuses as $option): ?>
                                        <option value="<?= e($option) ?>"<?= $selectedTicket['status'] === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="update_assignee">责任人员</label>
                                <select class="select" id="update_assignee" name="assignee">
                                    <?php foreach ($team as $member): ?>
                                        <option value="<?= e($member) ?>"<?= $selectedTicket['assignee'] === $member ? ' selected' : '' ?>><?= e($member) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field span-2">
                                <label for="update_note">进展说明</label>
                                <textarea class="textarea" id="update_note" name="note" maxlength="500" placeholder="填写排查结论、操作内容或后续计划"></textarea>
                            </div>
                            <div class="span-2 form-actions">
                                <button class="btn btn-sm btn-primary" type="submit">保存更新</button>
                            </div>
                        </div>
                    </form>
                    <div class="panel-note" style="margin-top:14px">状态变更为已解决或已关闭时会记录恢复时间，并将进展写入时间线。</div>
                </div>
            </section>
            <section class="panel" style="margin-top:16px">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('plus', 16) ?></span> 新建其他工单</h3>
                    </div>
                </div>
                <div class="panel-body">
                    <a class="btn btn-block" href="/tickets.php?new=1#newTicket"><?= icon_svg('plus', 14) ?> 新建事件工单</a>
                </div>
            </section>
        <?php else: ?>
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title"><span class="panel-title-icon"><?= icon_svg('plus', 16) ?></span> 新建事件工单</h3>
                        <p class="panel-sub">登记问题并指派处置责任人</p>
                    </div>
                </div>
                <div class="panel-body">
                    <?php if ($prefillAlert || $prefillHost): ?>
                        <div class="panel-note" style="margin-bottom:15px">
                            已关联<?= $prefillAlert ? '告警 ' . e($prefillAlert['alert_no']) : '主机 ' . e($prefillHost['hostname']) ?>，保存后可在工单详情查看上下文。
                        </div>
                    <?php endif; ?>
                    <form method="post" action="/tickets.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">
                        <?php if ($prefillAlert): ?><input type="hidden" name="alert_id" value="<?= (int) $prefillAlert['id'] ?>"><?php endif; ?>
                        <div class="form-grid">
                            <div class="field span-2">
                                <label for="title">工单标题</label>
                                <input class="input" id="title" name="title" type="text" maxlength="100" value="<?= e($prefillTitle) ?>" placeholder="简要描述需要跟进的问题" required>
                            </div>
                            <div class="field">
                                <label for="category">问题分类</label>
                                <select class="select" id="category" name="category">
                                    <?php foreach ($categories as $option): ?>
                                        <option value="<?= e($option) ?>"><?= e($option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="priority">优先级</label>
                                <select class="select" id="priority" name="priority">
                                    <?php foreach ($priorities as $option): ?>
                                        <option value="<?= e($option) ?>"<?= $option === '高' ? ' selected' : '' ?>><?= e($option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field span-2">
                                <label for="assignee">责任人员</label>
                                <select class="select" id="assignee" name="assignee">
                                    <?php foreach ($team as $member): ?>
                                        <option value="<?= e($member) ?>"<?= $member === '季明澈' ? ' selected' : '' ?>><?= e($member) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field span-2">
                                <label for="description">问题描述</label>
                                <textarea class="textarea" id="description" name="description" maxlength="1200" placeholder="补充影响范围、初步判断与期望完成时间"><?= e($prefillDescription) ?></textarea>
                            </div>
                            <div class="span-2 form-actions">
                                <button class="btn btn-sm btn-primary" type="submit">创建并指派</button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        <?php endif; ?>
    </aside>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
