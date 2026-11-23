<?php
if (!defined('APP_NAME')) {
    http_response_code(404);
    exit;
}
if (!is_logged_in()) {
    redirect('/login.php');
}

$user = current_user();
$flashes = take_flashes();
$active = $active ?? '';
$pageTitle = $pageTitle ?? '工作台';
$alertsOpen = active_alert_count();
$hostOnline = (int) db()->query("SELECT COUNT(*) FROM hosts WHERE status = '在线'")->fetchColumn();
$hostTotal = (int) db()->query('SELECT COUNT(*) FROM hosts')->fetchColumn();
$weekdayMap = ['日', '一', '二', '三', '四', '五', '六'];
$today = date('Y年n月j日');
$weekday = '星期' . $weekdayMap[(int) date('w')];

$navGroups = [
    '监控视图' => [
        ['dashboard.php', '工作台', 'dashboard', 'dashboard', 0],
        ['hosts.php', '主机资产', 'hosts', 'hosts', 0],
        ['metrics.php', '指标监控', 'metrics', 'metrics', 0],
    ],
    '告警与响应' => [
        ['alert-policies.php', '告警策略', 'policies', 'policies', 0],
        ['alerts.php', '告警记录', 'alerts', 'alerts', $alertsOpen],
        ['tickets.php', '事件工单', 'tickets', 'tickets', 0],
    ],
    '平台运维' => [
        ['agents.php', '采集代理', 'agents', 'agents', 0],
        ['reports.php', '报表中心', 'reports', 'reports', 0],
    ],
];
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0a0e13">
    <title><?= e($pageTitle) ?> · <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="app-body">
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="/dashboard.php">
            <span class="brand-mark"><span>辰</span></span>
            <span class="brand-text">
                <strong>辰曜监控</strong>
                <span>CHENYAO OBSERVABILITY</span>
            </span>
        </a>

        <nav class="side-nav" aria-label="主导航">
            <?php foreach ($navGroups as $group => $items): ?>
                <div class="nav-group">
                    <div class="nav-group-label"><?= e($group) ?></div>
                    <?php foreach ($items as [$href, $label, $icon, $key, $badge]): ?>
                        <a class="nav-link<?= $active === $key ? ' is-active' : '' ?>" href="/<?= e($href) ?>">
                            <span class="nav-icon"><?= icon_svg($icon) ?></span>
                            <span><?= e($label) ?></span>
                            <?php if ($badge > 0): ?>
                                <span class="nav-badge"><?= (int) $badge ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <strong><?= e(APP_ZONE) ?> · 实时采集</strong>
            <span><?= $hostOnline ?>/<?= $hostTotal ?> 台主机在线</span>
            <span class="sidebar-status"><i></i> 监控引擎运行中</span>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="nav-toggle" id="navToggle" type="button" aria-label="打开导航">☰</button>
                <div class="crumb">监控中心 <span>/</span> <strong><?= e($pageTitle) ?></strong></div>
                <form class="topbar-search" method="get" action="/hosts.php" role="search">
                    <?= icon_svg('search', 15) ?>
                    <input type="search" name="keyword" placeholder="搜索主机名 / IP 地址" aria-label="搜索主机">
                </form>
            </div>
            <div class="topbar-right">
                <span class="topbar-date"><?= e($today) ?> <?= e($weekday) ?></span>
                <span class="topbar-sep"></span>
                <a class="icon-button bell-button" href="/alerts.php" title="告警记录" aria-label="告警记录">
                    <?= icon_svg('bell', 17) ?>
                    <?php if ($alertsOpen > 0): ?><span class="bell-count"><?= $alertsOpen ?></span><?php endif; ?>
                </a>
                <span class="topbar-user">
                    <span class="avatar"><?= e(initial_of($user['real_name'] ?? '')) ?></span>
                    <span class="user-meta">
                        <strong><?= e($user['real_name'] ?? '') ?></strong>
                        <small><?= e($user['role'] ?? '') ?></small>
                    </span>
                </span>
                <form class="logout-form" method="post" action="/logout.php">
                    <?= csrf_field() ?>
                    <button type="submit">退出</button>
                </form>
            </div>
        </header>

        <div class="content">
            <?php if ($flashes): ?>
                <div class="flash-row">
                    <?php foreach ($flashes as $flash): ?>
                        <div class="flash flash-<?= e($flash['type']) ?>">
                            <span><?= e($flash['message']) ?></span>
                            <button type="button" class="flash-close" aria-label="关闭">×</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
