<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('APP_NAME', '辰曜基础设施监控平台');
define('APP_SHORT', '辰曜监控');
define('APP_COMPANY', '辰曜智能科技有限公司');
define('APP_VERSION', '4.8.2');
define('APP_ZONE', '华东生产域');
define('APP_PORT', 18114);
define('GATEWAY_PORT', 18115);
define('DB_PATH', ROOT_PATH . '/database/chenyao-monitor.sqlite');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('UPLOAD_BASE', ROOT_PATH . '/public/cy-uploads');

date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(STORAGE_PATH)) {
    ini_set('error_log', STORAGE_PATH . '/app-error.log');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('CYMON_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
