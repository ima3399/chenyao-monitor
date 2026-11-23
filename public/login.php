<?php
require_once __DIR__ . '/../lib/bootstrap.php';
db();

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token = (string) ($_POST['_token'] ?? '');

    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        $error = '页面已过期，请刷新后重新登录。';
    } elseif ($username === '' || $password === '') {
        $error = '请输入平台账号与登录口令。';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND status = ?');
        $stmt->execute([$username, '启用']);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            login_user($user);
            db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), $user['id']]);
            redirect('/dashboard.php');
        }
        $error = '账号或口令不正确，请重试。';
    }
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070b10">
    <title>登录 · <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login-body">
<main class="login-shell">
    <section class="login-aside">
        <div class="brand">
            <span class="brand-mark"><span>辰</span></span>
            <span class="brand-text">
                <strong>辰曜监控</strong>
                <span>CHENYAO OBSERVABILITY</span>
            </span>
        </div>

        <div class="login-aside-content">
            <span class="login-eyebrow">Infrastructure Intelligence</span>
            <h1>让基础设施<br>始终<em>清晰可见</em></h1>
            <p class="login-slogan">统一采集主机、指标、告警与响应流程，为辰曜智能科技生产域提供持续、可信的运行视图。</p>
            <ul class="login-features">
                <li>多分组主机资产与采集状态统一管理</li>
                <li>分钟级指标分析与容量水位洞察</li>
                <li>告警策略、记录与事件工单闭环协同</li>
                <li>可追溯的运维报表与处置记录</li>
            </ul>
        </div>

        <div class="login-monitor">
            <div>
                <div class="login-monitor-head">
                    <span>生产域负载概览</span>
                    <span class="pulse-status"><i class="pulse-dot"></i> 实时</span>
                </div>
                <div class="login-bars" aria-hidden="true">
                    <span style="height:32%"></span>
                    <span style="height:46%"></span>
                    <span style="height:39%"></span>
                    <span style="height:60%"></span>
                    <span style="height:53%"></span>
                    <span style="height:68%"></span>
                    <span style="height:57%"></span>
                    <span style="height:74%"></span>
                    <span style="height:62%"></span>
                    <span style="height:81%"></span>
                    <span style="height:70%"></span>
                    <span style="height:88%"></span>
                    <span style="height:78%"></span>
                    <span style="height:92%"></span>
                    <span style="height:85%"></span>
                    <span style="height:96%"></span>
                    <span style="height:79%"></span>
                    <span style="height:90%"></span>
                    <span style="height:73%"></span>
                    <span style="height:84%"></span>
                    <span style="height:66%"></span>
                    <span style="height:75%"></span>
                </div>
                <div class="login-monitor-stats">
                    <div><strong>15</strong><small>纳管主机</small></div>
                    <div><strong>6</strong><small>监控分组</small></div>
                    <div><strong>99.8%</strong><small>采集可用率</small></div>
                </div>
            </div>
            <div class="login-side-list" aria-hidden="true">
                <div class="login-side-node"><span>API-GW-01</span><i></i></div>
                <div class="login-side-node"><span>APP-WEB-01</span><i></i></div>
                <div class="login-side-node"><span>DB-MYSQL-01</span><i></i></div>
                <div class="login-side-node is-warn"><span>EDGE-02</span><i></i></div>
            </div>
        </div>

        <div class="login-aside-foot">
            <span>© 2026 <?= e(APP_COMPANY) ?></span>
            <span>平台版本 v<?= e(APP_VERSION) ?></span>
        </div>
    </section>

    <section class="login-main">
        <div class="login-box">
            <div class="login-main-head">
                <span class="login-eyebrow">Secure Console</span>
                <h2>登录监控工作台</h2>
                <p>使用平台工程部分配的账号访问生产域监控中心</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error" style="margin-bottom:18px"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="login-form" autocomplete="off" novalidate>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="username">平台账号</label>
                    <div class="login-field-icon">
                        <?= icon_svg('user', 16) ?>
                        <input class="input" id="username" name="username" type="text" value="<?= e($username) ?>" placeholder="请输入平台账号" required autofocus>
                    </div>
                </div>
                <div class="field">
                    <label for="password">登录口令</label>
                    <div class="login-field-icon">
                        <?= icon_svg('key', 16) ?>
                        <input class="input" id="password" name="password" type="password" placeholder="请输入登录口令" required>
                    </div>
                </div>
                <div class="login-meta">
                    <span>账号由平台工程部统一开通</span>
                    <span>访问行为记录在平台日志中</span>
                </div>
                <button class="btn btn-primary btn-block" type="submit">进入监控中心</button>
            </form>

            <div class="login-tip">生产域监控中心仅面向授权运维人员开放。请妥善保管账号与口令，离开公共终端前及时退出登录。</div>
            <span class="login-secure"><?= icon_svg('shield', 13) ?> 会话加密传输 · 操作记录留存</span>
            <p class="login-copy">© 2026 <?= e(APP_COMPANY) ?> · 辰曜基础设施监控平台</p>
        </div>
    </section>
</main>
</body>
</html>
