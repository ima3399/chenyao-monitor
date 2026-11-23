<?php
declare(strict_types=1);

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string) ($_POST['_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        header('Content-Type: text/plain; charset=utf-8');
        exit('页面已过期，请返回后重新提交。');
    }
}

function post_str(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
}

function q(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
}

function query_int(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? null;
    return is_numeric($value) ? (int) $value : $default;
}

function fmt_datetime($value, string $format = 'Y-m-d H:i'): string
{
    $value = (string) $value;
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : '—';
}

function fmt_date($value): string
{
    return fmt_datetime($value, 'Y-m-d');
}

function fmt_time($value): string
{
    return fmt_datetime($value, 'H:i:s');
}

function relative_time($value): string
{
    $value = (string) $value;
    $ts = strtotime($value);
    if (!$ts) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        return date('m-d H:i', $ts);
    }
    if ($diff < 60) {
        return '刚刚';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . ' 分钟前';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' 小时前';
    }
    if ($diff < 172800) {
        return '昨天 ' . date('H:i', $ts);
    }
    return date('m-d H:i', $ts);
}

function money($amount): string
{
    return number_format((float) $amount, 2);
}

function percent($part, $total, int $decimals = 1): string
{
    $total = (float) $total;
    if ($total <= 0) {
        return number_format(0, $decimals) . '%';
    }
    return number_format((float) $part / $total * 100, $decimals) . '%';
}

function human_size($bytes): string
{
    $bytes = (float) $bytes;
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return (int) $bytes . ' B';
}

function severity_class(string $severity): string
{
    return match ($severity) {
        '严重' => 'danger',
        '警告' => 'warning',
        '提示' => 'info',
        default => 'neutral',
    };
}

function severity_rank(string $severity): int
{
    return match ($severity) {
        '严重' => 3,
        '警告' => 2,
        '提示' => 1,
        default => 0,
    };
}

function host_status_class(string $status): string
{
    return match ($status) {
        '在线' => 'success',
        '警告' => 'warning',
        '离线' => 'danger',
        '维护' => 'neutral',
        default => 'neutral',
    };
}

function alert_status_class(string $status): string
{
    return match ($status) {
        '活跃' => 'danger',
        '已确认' => 'warning',
        '已恢复' => 'success',
        default => 'neutral',
    };
}

function ticket_status_class(string $status): string
{
    return match ($status) {
        '待处理' => 'danger',
        '处理中' => 'warning',
        '已解决' => 'success',
        '已关闭' => 'neutral',
        default => 'neutral',
    };
}

function priority_class(string $priority): string
{
    return match ($priority) {
        '紧急' => 'danger',
        '高' => 'warning',
        '中' => 'info',
        '低' => 'neutral',
        default => 'neutral',
    };
}

function agent_status_class(string $status): string
{
    return match ($status) {
        '运行中' => 'success',
        '心跳超时' => 'danger',
        '待升级' => 'warning',
        '已暂停' => 'neutral',
        default => 'neutral',
    };
}

function metric_status_class(string $status): string
{
    return match ($status) {
        '正常' => 'success',
        '警告' => 'warning',
        '严重' => 'danger',
        '未知' => 'neutral',
        default => 'neutral',
    };
}

function metric_value_state(float $value, float $warn, float $critical): string
{
    if ($value >= $critical) {
        return '严重';
    }
    if ($value >= $warn) {
        return '警告';
    }
    return '正常';
}

function current_operator(): string
{
    $user = current_user();
    return (string) ($user['real_name'] ?? '系统');
}

function active_alert_count(): int
{
    try {
        return (int) db()->query("SELECT COUNT(*) FROM alerts WHERE status IN ('活跃', '已确认')")->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function initial_of(?string $name): string
{
    $name = trim((string) $name);
    return $name === '' ? '辰' : mb_substr($name, 0, 1);
}

function ticket_no_next(PDO $pdo): string
{
    $prefix = 'TKT-' . date('Ymd') . '-';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE ticket_no LIKE ?');
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((string) ((int) $stmt->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
}

function policy_code_next(PDO $pdo): string
{
    return 'POL-CUS-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function mask_secret(string $value): string
{
    $length = mb_strlen($value);
    if ($length <= 8) {
        return str_repeat('•', max(4, $length));
    }
    return mb_substr($value, 0, 5) . '••••' . mb_substr($value, -4);
}

function build_query(array $query): string
{
    $filtered = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');
    return http_build_query($filtered);
}

function icon_svg(string $name, int $size = 18): string
{
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/>',
        'hosts' => '<rect x="3" y="4" width="18" height="6" rx="2"/><rect x="3" y="14" width="18" height="6" rx="2"/><path d="M7 7h.01M7 17h.01M11 7h7M11 17h7"/>',
        'metrics' => '<path d="M3 18h18"/><path d="m4 14 4-4 3.5 3 4.5-6 4 3"/><path d="M3 20V8"/>',
        'policies' => '<path d="M12 3 4.5 6v5.2c0 4.4 3.2 7.8 7.5 9.8 4.3-2 7.5-5.4 7.5-9.8V6z"/><path d="m9 12 2 2 4-4"/>',
        'alerts' => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'tickets' => '<path d="M5 4h14a2 2 0 0 1 2 2v3a2.5 2.5 0 0 0 0 5v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2.5 2.5 0 0 0 0-5V6a2 2 0 0 1 2-2z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'agents' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/>',
        'reports' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'bell' => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'arrow' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'arrow-up' => '<path d="M12 19V5"/><path d="m6 11 6-6 6 6"/>',
        'arrow-down' => '<path d="M12 5v14"/><path d="m18 13-6 6-6-6"/>',
        'server' => '<rect x="4" y="3" width="16" height="7" rx="2"/><rect x="4" y="14" width="16" height="7" rx="2"/><path d="M8 6.5h.01M8 17.5h.01M12 6.5h4M12 17.5h4"/>',
        'cpu' => '<rect x="6" y="6" width="12" height="12" rx="2"/><rect x="9" y="9" width="6" height="6" rx="1"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
        'memory' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10v4M11 10v4M15 10v4M19 10v4M6 18v2M10 18v2M14 18v2M18 18v2"/>',
        'disk' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2.2"/><path d="M12 4v3M20 12h-3"/>',
        'network' => '<circle cx="12" cy="5" r="2.5"/><circle cx="5" cy="19" r="2.5"/><circle cx="19" cy="19" r="2.5"/><path d="M12 7.5v4M10.5 15.5 6.5 17.5M13.5 15.5l4 2"/>',
        'shield' => '<path d="M12 3 4.5 6v5.2c0 4.4 3.2 7.8 7.5 9.8 4.3-2 7.5-5.4 7.5-9.8V6z"/><path d="m9 12 2 2 4-4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'download' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.9-4M4 13a8 8 0 0 0 14.9 4"/><path d="M20 4v7h-7M4 20v-7h7"/>',
        'activity' => '<path d="M3 12h4l2.5-7 5 14L17 12h4"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'logout' => '<path d="M10 4H5v16h5"/><path d="M14 8l4 4-4 4M8 12h10"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.6-3.5 3.4-5.5 7-5.5s6.4 2 7 5.5"/>',
        'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v5h5"/>',
        'archive' => '<rect x="3" y="4" width="18" height="5" rx="1.5"/><path d="M5 9h14v11H5zM9 13h6"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 16 9 5 9-5"/>',
        'heartbeat' => '<path d="M3 12h4l2-5 4 10 2-5h6"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l2 2M14 9l2 2"/>',
        'pause' => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
        'play' => '<path d="m8 5 11 7-11 7z"/>',
        'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="2.5"/>',
        'filter' => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>',
        'zap' => '<path d="M13 2 4 14h7l-1 8 10-13h-7z"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'terminal' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3M13 15h4"/>',
        'warning' => '<path d="m12 3 10 18H2z"/><path d="M12 9v5M12 17.5h.01"/>',
        'sliders' => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h7M15 18h5"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="13" cy="18" r="2"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 10h6M9 14h6M9 18h4"/>',
    ];
    $body = $icons[$name] ?? $icons['dashboard'];
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

function metric_icon(string $metricCode): string
{
    return match ($metricCode) {
        'CPU_USAGE' => 'cpu',
        'MEMORY_USAGE' => 'memory',
        'DISK_USAGE' => 'disk',
        'NET_RX' => 'network',
        'LOAD_1M' => 'activity',
        'AGENT_HEARTBEAT' => 'heartbeat',
        default => 'metrics',
    };
}

function metric_unit_label(string $unit): string
{
    $unit = trim($unit);
    return $unit === '%' ? '%' : $unit;
}
