<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

$isDirectCli = PHP_SAPI === 'cli'
    && isset($_SERVER['argv'][0])
    && realpath($_SERVER['argv'][0]) === realpath(__FILE__);

$dbPath = ROOT_PATH . '/database/chenyao-monitor.sqlite';
$uploadBase = ROOT_PATH . '/public/cy-uploads';
$storagePath = ROOT_PATH . '/storage';

foreach ([
    dirname($dbPath),
    $uploadBase . '/agents',
    $uploadBase . '/screenshots',
    $storagePath . '/monitor-logs',
    $storagePath . '/reports',
    $storagePath . '/archives',
] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

$seedFiles = [
    'monitor-logs/access-20261110.log' => "2026-11-10 10:02:58 10.24.8.21 GET /api/v1/cluster/health 200 12ms\n2026-11-10 10:03:06 10.24.8.11 POST /api/v1/metrics/batch 202 38ms\n2026-11-10 10:03:18 10.24.9.11 GET /api/v1/instances 200 21ms\n2026-11-10 10:03:31 10.24.11.12 POST /api/v1/edge/report 200 46ms\n2026-11-10 10:03:42 10.24.8.22 GET /api/v1/service/status 503 187ms\n",
    'monitor-logs/agent-20261110.log' => "2026-11-10 10:03:01 [agent] host=CY-API-GW-01 seq=482191 send_ok=28 latency_ms=18\n2026-11-10 10:03:08 [agent] host=CY-APP-WEB-02 seq=391740 send_ok=28 latency_ms=19\n2026-11-10 10:03:21 [agent] host=CY-DB-MYSQL-01 seq=512048 send_ok=28 latency_ms=11\n2026-11-10 10:03:33 [agent] host=CY-MQ-KAFKA-02 seq=307516 send_ok=28 latency_ms=31\n2026-11-10 10:03:44 [agent] host=CY-LOG-ES-01 seq=278221 send_ok=28 latency_ms=26\n",
    'monitor-logs/syslog-20261110.log' => "2026-11-10 09:41:56 CY-DB-MYSQL-01 kernel: TCP: request_sock_TCP: Possible SYN flooding on port 3306\n2026-11-10 09:42:18 CY-DB-MYSQL-01 monitor-engine: cpu threshold exceeded value=96.8 threshold=90\n2026-11-10 09:43:05 CY-APP-WEB-02 app-web: request queue depth=124 worker=32\n2026-11-10 09:58:44 CY-EDGE-02 edge-gateway: heartbeat jitter detected value=128s\n2026-11-10 10:03:22 CY-LOG-ES-01 elasticsearch: disk high watermark reached usage=82.1%\n",
    'monitor-logs/access-20261109.log' => "2026-11-09 23:58:10 10.24.8.11 GET /api/v1/cluster/health 200 15ms\n2026-11-09 23:59:21 10.24.9.11 POST /api/v1/instances/query 200 74ms\n",
    'reports/monitor-summary-2026-11.csv' => "日期,在线主机,告警总数,严重告警,工单新建,工单关闭,平均可用率\n2026-11-08,13,4,1,2,2,99.92%\n2026-11-09,13,6,2,3,2,99.87%\n2026-11-10,12,8,3,4,0,99.81%\n",
    'reports/host-health-2026-11-10.csv' => "主机名,IP地址,监控分组,状态,CPU使用率,内存使用率,磁盘使用率,最后上报\nCY-API-GW-01,10.24.8.11,接入网关组,在线,38.6%,54.2%,46.3%,2026-11-10 10:03:48\nCY-APP-WEB-02,10.24.8.22,应用服务组,警告,91.2%,89.4%,55.7%,2026-11-10 10:03:35\nCY-DB-MYSQL-01,10.24.9.11,数据服务组,在线,96.8%,84.2%,72.4%,2026-11-10 10:03:52\nCY-EDGE-01,10.24.11.11,边缘节点组,离线,31.8%,44.2%,57.1%,2026-11-09 22:17:32\n",
];

foreach ($seedFiles as $relative => $content) {
    $target = $storagePath . '/' . $relative;
    if (!is_file($target)) {
        file_put_contents($target, $content);
    }
}

foreach ([$uploadBase . '/agents', $uploadBase . '/screenshots'] as $uploadDir) {
    $index = $uploadDir . '/index.html';
    if (!is_file($index)) {
        file_put_contents($index, '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>辰曜监控文件服务</title><p>辰曜监控平台文件资源目录</p></html>');
    }
}

if (is_file($dbPath)) {
    if ($isDirectCli) {
        echo "数据库已存在：{$dbPath}\n";
    }
    return;
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

$insert = static function (string $table, array $row) use ($pdo): int {
    $columns = array_keys($row);
    $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($row);
    return (int) $pdo->lastInsertId();
};

$pdo->beginTransaction();

$users = [
    [
        'username' => 'admin',
        'password' => 'Chenyao@2026',
        'real_name' => '陆知远',
        'role' => '系统管理员',
        'dept' => '平台工程部',
        'phone' => '138-5712-8601',
        'email' => 'luzhiyuan@chenyao-tech.cn',
        'last_login_at' => '2026-11-10 08:46:12',
        'created_at' => '2024-06-18 09:30:00',
    ],
    [
        'username' => 'ops',
        'password' => 'Monitor@2026',
        'real_name' => '季明澈',
        'role' => '运维主管',
        'dept' => '运维中心',
        'phone' => '139-0651-4238',
        'email' => 'jimingche@chenyao-tech.cn',
        'last_login_at' => '2026-11-10 09:21:35',
        'created_at' => '2024-09-02 14:10:00',
    ],
];
foreach ($users as $user) {
    $insert('users', [
        'username' => $user['username'],
        'password_hash' => password_hash($user['password'], PASSWORD_DEFAULT),
        'real_name' => $user['real_name'],
        'role' => $user['role'],
        'dept' => $user['dept'],
        'phone' => $user['phone'],
        'email' => $user['email'],
        'status' => '启用',
        'last_login_at' => $user['last_login_at'],
        'created_at' => $user['created_at'],
    ]);
}

$groups = [
    ['group_code' => 'GW', 'group_name' => '接入网关组', 'owner' => '苏临川', 'color' => '#f0a94b', 'description' => '南北向流量入口、负载均衡与 TLS 卸载', 'sort_order' => 10, 'created_at' => '2024-07-08 10:00:00'],
    ['group_code' => 'APP', 'group_name' => '应用服务组', 'owner' => '程意', 'color' => '#2dd4bf', 'description' => '业务应用、批处理与对外服务运行节点', 'sort_order' => 20, 'created_at' => '2024-07-08 10:05:00'],
    ['group_code' => 'DATA', 'group_name' => '数据服务组', 'owner' => '韩沐川', 'color' => '#6ea8fe', 'description' => '关系型数据库、数据同步与查询服务', 'sort_order' => 30, 'created_at' => '2024-07-08 10:10:00'],
    ['group_code' => 'MQ', 'group_name' => '消息与缓存组', 'owner' => '江屿', 'color' => '#b794f6', 'description' => '消息队列、缓存与异步任务支撑组件', 'sort_order' => 40, 'created_at' => '2024-07-08 10:15:00'],
    ['group_code' => 'STORAGE', 'group_name' => '存储与日志组', 'owner' => '顾清越', 'color' => '#f6c86e', 'description' => '共享存储、日志检索与灾备数据节点', 'sort_order' => 50, 'created_at' => '2024-07-08 10:20:00'],
    ['group_code' => 'EDGE', 'group_name' => '边缘节点组', 'owner' => '温以宁', 'color' => '#ff7b72', 'description' => '区域边缘接入、轻量采集与本地转发节点', 'sort_order' => 60, 'created_at' => '2024-08-19 09:00:00'],
];
$groupIds = [];
foreach ($groups as $group) {
    $groupIds[$group['group_code']] = $insert('monitor_groups', $group);
}

$hosts = [
    [
        'hostname' => 'CY-API-GW-01', 'ip_address' => '10.24.8.11', 'group_code' => 'GW', 'status' => '在线',
        'os_name' => 'Rocky Linux 9.3', 'cpu_cores' => 8, 'memory_gb' => 32, 'disk_gb' => 500,
        'location' => '杭州滨江 A 区 · 08 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.6',
        'uptime_days' => 186, 'last_report_at' => '2026-11-10 10:03:48',
        'description' => '南北向 API 入口，承载门户、移动端与开放接口流量。',
    ],
    [
        'hostname' => 'CY-API-GW-02', 'ip_address' => '10.24.8.12', 'group_code' => 'GW', 'status' => '在线',
        'os_name' => 'Rocky Linux 9.3', 'cpu_cores' => 8, 'memory_gb' => 32, 'disk_gb' => 500,
        'location' => '杭州滨江 A 区 · 09 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.6',
        'uptime_days' => 181, 'last_report_at' => '2026-11-10 10:03:29',
        'description' => '网关双活节点，承担灰度流量与证书轮换。',
    ],
    [
        'hostname' => 'CY-APP-WEB-01', 'ip_address' => '10.24.8.21', 'group_code' => 'APP', 'status' => '在线',
        'os_name' => 'Ubuntu Server 22.04.3', 'cpu_cores' => 8, 'memory_gb' => 16, 'disk_gb' => 300,
        'location' => '杭州滨江 B 区 · 12 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.6',
        'uptime_days' => 96, 'last_report_at' => '2026-11-10 10:03:40',
        'description' => '客户工作台与运营后台应用节点。',
    ],
    [
        'hostname' => 'CY-APP-WEB-02', 'ip_address' => '10.24.8.22', 'group_code' => 'APP', 'status' => '警告',
        'os_name' => 'Ubuntu Server 22.04.3', 'cpu_cores' => 8, 'memory_gb' => 16, 'disk_gb' => 300,
        'location' => '杭州滨江 B 区 · 13 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.6',
        'uptime_days' => 96, 'last_report_at' => '2026-11-10 10:02:58',
        'description' => '客户工作台第二应用节点，当前存在请求排队。',
    ],
    [
        'hostname' => 'CY-APP-JOB-01', 'ip_address' => '10.24.8.23', 'group_code' => 'APP', 'status' => '维护',
        'os_name' => 'Rocky Linux 9.2', 'cpu_cores' => 4, 'memory_gb' => 8, 'disk_gb' => 200,
        'location' => '杭州滨江 B 区 · 14 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.5',
        'uptime_days' => 142, 'last_report_at' => '2026-11-10 07:03:14',
        'description' => '定时任务与报表生成节点，处于计划维护窗口。',
    ],
    [
        'hostname' => 'CY-DB-MYSQL-01', 'ip_address' => '10.24.9.11', 'group_code' => 'DATA', 'status' => '在线',
        'os_name' => 'Rocky Linux 9.3', 'cpu_cores' => 16, 'memory_gb' => 64, 'disk_gb' => 1200,
        'location' => '杭州滨江 A 区 · 16 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.6',
        'uptime_days' => 352, 'last_report_at' => '2026-11-10 10:03:18',
        'description' => '生产主数据库，承载订单与客户核心数据。',
    ],
    [
        'hostname' => 'CY-DB-MYSQL-02', 'ip_address' => '10.24.9.12', 'group_code' => 'DATA', 'status' => '在线',
        'os_name' => 'Rocky Linux 9.3', 'cpu_cores' => 16, 'memory_gb' => 64, 'disk_gb' => 1200,
        'location' => '杭州滨江 A 区 · 17 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.6',
        'uptime_days' => 352, 'last_report_at' => '2026-11-10 10:03:37',
        'description' => '生产只读数据库，承担报表与查询流量。',
    ],
    [
        'hostname' => 'CY-DB-REDIS-01', 'ip_address' => '10.24.9.21', 'group_code' => 'MQ', 'status' => '在线',
        'os_name' => 'Ubuntu Server 22.04.3', 'cpu_cores' => 8, 'memory_gb' => 32, 'disk_gb' => 400,
        'location' => '杭州滨江 A 区 · 18 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.6',
        'uptime_days' => 214, 'last_report_at' => '2026-11-10 10:03:05',
        'description' => '会话缓存与热点数据缓存集群入口。',
    ],
    [
        'hostname' => 'CY-MQ-KAFKA-01', 'ip_address' => '10.24.9.31', 'group_code' => 'MQ', 'status' => '在线',
        'os_name' => 'Rocky Linux 9.3', 'cpu_cores' => 8, 'memory_gb' => 32, 'disk_gb' => 800,
        'location' => '杭州滨江 A 区 · 21 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.6',
        'uptime_days' => 268, 'last_report_at' => '2026-11-10 10:03:44',
        'description' => '业务事件总线消息节点，承载实时同步链路。',
    ],
    [
        'hostname' => 'CY-MQ-KAFKA-02', 'ip_address' => '10.24.9.32', 'group_code' => 'MQ', 'status' => '警告',
        'os_name' => 'Rocky Linux 9.3', 'cpu_cores' => 8, 'memory_gb' => 32, 'disk_gb' => 800,
        'location' => '杭州滨江 A 区 · 22 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.5',
        'uptime_days' => 268, 'last_report_at' => '2026-11-10 10:03:31',
        'description' => '业务事件总线第二节点，数据盘水位偏高。',
    ],
    [
        'hostname' => 'CY-STG-NFS-01', 'ip_address' => '10.24.10.11', 'group_code' => 'STORAGE', 'status' => '在线',
        'os_name' => 'AlmaLinux 9.3', 'cpu_cores' => 4, 'memory_gb' => 16, 'disk_gb' => 4096,
        'location' => '杭州滨江 C 区 · 03 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.6',
        'uptime_days' => 402, 'last_report_at' => '2026-11-10 10:02:52',
        'description' => '共享文件存储节点，为应用与批处理提供挂载卷。',
    ],
    [
        'hostname' => 'CY-LOG-ES-01', 'ip_address' => '10.24.10.21', 'group_code' => 'STORAGE', 'status' => '在线',
        'os_name' => 'Ubuntu Server 22.04.3', 'cpu_cores' => 8, 'memory_gb' => 32, 'disk_gb' => 2048,
        'location' => '杭州滨江 C 区 · 04 机柜', 'environment' => '生产环境', 'agent_version' => '4.2.5',
        'uptime_days' => 304, 'last_report_at' => '2026-11-10 10:03:12',
        'description' => '日志集中检索集群热节点，当前磁盘水位接近策略阈值。',
    ],
    [
        'hostname' => 'CY-BAK-SVR-01', 'ip_address' => '10.24.10.31', 'group_code' => 'STORAGE', 'status' => '在线',
        'os_name' => 'Rocky Linux 9.3', 'cpu_cores' => 4, 'memory_gb' => 16, 'disk_gb' => 8192,
        'location' => '湖州灾备中心 · D 区', 'environment' => '灾备环境', 'agent_version' => '4.2.5',
        'uptime_days' => 521, 'last_report_at' => '2026-11-10 10:02:46',
        'description' => '结构化数据与配置备份节点，执行每日增量校验。',
    ],
    [
        'hostname' => 'CY-EDGE-01', 'ip_address' => '10.24.11.11', 'group_code' => 'EDGE', 'status' => '离线',
        'os_name' => 'Ubuntu Server 22.04.3', 'cpu_cores' => 4, 'memory_gb' => 8, 'disk_gb' => 240,
        'location' => '杭州萧山边缘机房 · E1', 'environment' => '边缘环境', 'agent_version' => '4.2.3',
        'uptime_days' => 23, 'last_report_at' => '2026-11-09 22:17:32',
        'description' => '萧山区域边缘接入节点，当前采集心跳中断。',
    ],
    [
        'hostname' => 'CY-EDGE-02', 'ip_address' => '10.24.11.12', 'group_code' => 'EDGE', 'status' => '警告',
        'os_name' => 'Ubuntu Server 22.04.3', 'cpu_cores' => 4, 'memory_gb' => 8, 'disk_gb' => 240,
        'location' => '宁波北仑边缘机房 · E2', 'environment' => '边缘环境', 'agent_version' => '4.2.6',
        'uptime_days' => 88, 'last_report_at' => '2026-11-10 10:03:12',
        'description' => '宁波区域边缘接入节点，心跳间隔存在抖动。',
    ],
];

$metricDefs = [
    'CPU_USAGE' => ['metric_name' => 'CPU 使用率', 'unit' => '%', 'category' => '计算', 'warn' => 85, 'critical' => 95, 'description' => '主机 CPU 综合利用率，按 1 分钟采样后滚动聚合。', 'sort' => 10],
    'MEMORY_USAGE' => ['metric_name' => '内存使用率', 'unit' => '%', 'category' => '计算', 'warn' => 88, 'critical' => 96, 'description' => '可用内存之外的实际占用比例，含缓存回写影响。', 'sort' => 20],
    'DISK_USAGE' => ['metric_name' => '磁盘使用率', 'unit' => '%', 'category' => '存储', 'warn' => 82, 'critical' => 92, 'description' => '数据盘根分区使用率，排除快照与临时挂载点。', 'sort' => 30],
    'LOAD_1M' => ['metric_name' => '1 分钟负载', 'unit' => '', 'category' => '计算', 'warn' => 8, 'critical' => 12, 'description' => '系统过去 1 分钟的平均可运行进程数。', 'sort' => 40],
    'NET_RX' => ['metric_name' => '入站流量', 'unit' => 'Mbps', 'category' => '网络', 'warn' => 780, 'critical' => 940, 'description' => '主机全部业务网卡入站流量合计。', 'sort' => 50],
    'AGENT_HEARTBEAT' => ['metric_name' => '采集心跳间隔', 'unit' => '秒', 'category' => '采集', 'warn' => 120, 'critical' => 300, 'description' => '距最近一次采集代理心跳的时间间隔。', 'sort' => 60],
    'REDIS_LATENCY' => ['metric_name' => 'Redis 响应延迟', 'unit' => 'ms', 'category' => '中间件', 'warn' => 20, 'critical' => 40, 'description' => '缓存实例 P99 命令响应延迟。', 'sort' => 70],
    'MYSQL_CONN' => ['metric_name' => 'MySQL 连接数', 'unit' => '个', 'category' => '数据库', 'warn' => 300, 'critical' => 450, 'description' => '数据库当前活跃连接总数。', 'sort' => 80],
];
$metricIds = [];
foreach ($metricDefs as $code => $def) {
    $metricIds[$code] = $insert('metric_catalog', [
        'metric_code' => $code,
        'metric_name' => $def['metric_name'],
        'unit' => $def['unit'],
        'category' => $def['category'],
        'warn_threshold' => $def['warn'],
        'critical_threshold' => $def['critical'],
        'description' => $def['description'],
        'sort_order' => $def['sort'],
    ]);
}

$hostValues = [
    'CY-API-GW-01' => [38.6, 54.2, 46.3, 2.14, 423.8, 18],
    'CY-API-GW-02' => [42.1, 58.7, 47.1, 2.36, 456.7, 21],
    'CY-APP-WEB-01' => [58.9, 67.4, 52.8, 3.42, 208.5, 16],
    'CY-APP-WEB-02' => [91.2, 89.4, 55.7, 7.86, 232.1, 19],
    'CY-APP-JOB-01' => [24.7, 43.1, 61.2, 1.23, 34.6, 11040],
    'CY-DB-MYSQL-01' => [96.8, 84.2, 72.4, 12.47, 96.3, 11],
    'CY-DB-MYSQL-02' => [61.3, 73.8, 68.9, 5.31, 104.8, 17],
    'CY-DB-REDIS-01' => [34.9, 62.5, 41.3, 1.87, 88.2, 14],
    'CY-MQ-KAFKA-01' => [47.6, 64.2, 78.5, 3.76, 302.4, 22],
    'CY-MQ-KAFKA-02' => [52.8, 69.7, 86.7, 4.18, 287.9, 31],
    'CY-STG-NFS-01' => [18.2, 33.6, 64.8, 0.82, 154.7, 19],
    'CY-LOG-ES-01' => [44.1, 71.3, 82.1, 3.11, 221.6, 26],
    'CY-BAK-SVR-01' => [12.6, 29.8, 73.5, 0.47, 62.4, 15],
    'CY-EDGE-01' => [31.8, 44.2, 57.1, 1.10, 88.4, 42480],
    'CY-EDGE-02' => [73.4, 65.8, 58.2, 5.67, 184.2, 128],
];

$metricCodes = array_keys(array_slice($metricDefs, 0, 6, true));
$metricState = static function (float $value, float $warn, float $critical): string {
    if ($value >= $critical) {
        return '严重';
    }
    if ($value >= $warn) {
        return '警告';
    }
    return '正常';
};
$hostIds = [];
$hostIndex = 0;
foreach ($hosts as $host) {
    $hostId = $insert('hosts', [
        'hostname' => $host['hostname'],
        'ip_address' => $host['ip_address'],
        'group_id' => $groupIds[$host['group_code']],
        'status' => $host['status'],
        'os_name' => $host['os_name'],
        'cpu_cores' => $host['cpu_cores'],
        'memory_gb' => $host['memory_gb'],
        'disk_gb' => $host['disk_gb'],
        'location' => $host['location'],
        'environment' => $host['environment'],
        'agent_version' => $host['agent_version'],
        'uptime_days' => $host['uptime_days'],
        'last_report_at' => $host['last_report_at'],
        'description' => $host['description'],
        'created_at' => '2025-05-12 09:00:00',
    ]);
    $hostIds[$host['hostname']] = $hostId;

    foreach ($metricCodes as $metricIndex => $metricCode) {
        $def = $metricDefs[$metricCode];
        $value = (float) $hostValues[$host['hostname']][$metricIndex];
        $status = in_array($host['status'], ['离线', '维护'], true)
            ? '未知'
            : $metricState($value, (float) $def['warn'], (float) $def['critical']);
        $insert('host_metrics', [
            'host_id' => $hostId,
            'metric_code' => $metricCode,
            'metric_name' => $def['metric_name'],
            'unit' => $def['unit'],
            'value' => $value,
            'warn_threshold' => $def['warn'],
            'critical_threshold' => $def['critical'],
            'status' => $status,
            'collected_at' => $host['last_report_at'],
        ]);

        for ($point = 0; $point < 24; $point++) {
            $sampleTs = strtotime('2026-11-09 11:00:00') + $point * 3600;
            if ($host['hostname'] === 'CY-EDGE-01' && $sampleTs > strtotime('2026-11-09 22:00:00')) {
                break;
            }
            $phase = $hostIndex * 0.73 + $metricIndex * 1.17;
            $factor = 1 + sin($phase + $point * 0.45) * 0.11 + cos($point * 0.9 + $metricIndex) * 0.04;
            $sampleValue = $point === 23 ? $value : round(max(0, $value * $factor), 2);
            $insert('metric_samples', [
                'host_id' => $hostId,
                'metric_code' => $metricCode,
                'sampled_at' => date('Y-m-d H:i:s', $sampleTs),
                'value' => $sampleValue,
            ]);
        }
    }
    $hostIndex++;
}

$policies = [
    [
        'policy_code' => 'POL-CPU-CORE', 'policy_name' => '核心主机 CPU 使用率过高', 'metric_code' => 'CPU_USAGE',
        'threshold_value' => 90, 'duration_min' => 5, 'severity' => '严重', 'target_group' => '全部主机',
        'notify_channel' => '短信 / 企业微信', 'enabled' => 1,
        'description' => '核心业务主机 CPU 连续 5 分钟高于 90% 时触发，适用于全部生产主机。',
        'created_by' => '季明澈', 'created_at' => '2025-03-12 10:20:00', 'updated_at' => '2026-10-29 16:08:00',
    ],
    [
        'policy_code' => 'POL-CPU-EDGE', 'policy_name' => '边缘节点 CPU 使用率升高', 'metric_code' => 'CPU_USAGE',
        'threshold_value' => 75, 'duration_min' => 10, 'severity' => '警告', 'target_group' => '边缘节点组',
        'notify_channel' => '企业微信', 'enabled' => 1,
        'description' => '边缘节点资源水位偏低，CPU 连续 10 分钟超过 75% 时预警。',
        'created_by' => '温以宁', 'created_at' => '2025-09-08 15:10:00', 'updated_at' => '2026-10-21 09:43:00',
    ],
    [
        'policy_code' => 'POL-MEM-APP', 'policy_name' => '应用服务组内存压力', 'metric_code' => 'MEMORY_USAGE',
        'threshold_value' => 88, 'duration_min' => 5, 'severity' => '严重', 'target_group' => '应用服务组',
        'notify_channel' => '电话 / 企业微信', 'enabled' => 1,
        'description' => '应用节点内存使用率连续 5 分钟超过 88% 时升级为严重告警。',
        'created_by' => '季明澈', 'created_at' => '2025-05-23 11:32:00', 'updated_at' => '2026-10-30 13:15:00',
    ],
    [
        'policy_code' => 'POL-DISK-LOG', 'policy_name' => '存储容量水位预警', 'metric_code' => 'DISK_USAGE',
        'threshold_value' => 82, 'duration_min' => 15, 'severity' => '警告', 'target_group' => '全部主机',
        'notify_channel' => '企业微信 / 邮件', 'enabled' => 1,
        'description' => '数据盘使用率连续 15 分钟高于 82% 时触发容量预警。',
        'created_by' => '顾清越', 'created_at' => '2025-01-19 09:45:00', 'updated_at' => '2026-11-10 08:45:00',
    ],
    [
        'policy_code' => 'POL-LOAD-DB', 'policy_name' => '数据库系统负载异常', 'metric_code' => 'LOAD_1M',
        'threshold_value' => 8, 'duration_min' => 5, 'severity' => '警告', 'target_group' => '数据服务组',
        'notify_channel' => '企业微信', 'enabled' => 1,
        'description' => '数据库主机 1 分钟负载连续 5 分钟高于 8 时触发排查提醒。',
        'created_by' => '韩沐川', 'created_at' => '2025-06-11 14:28:00', 'updated_at' => '2026-09-18 10:11:00',
    ],
    [
        'policy_code' => 'POL-NET-GW', 'policy_name' => '网关入站流量突增', 'metric_code' => 'NET_RX',
        'threshold_value' => 780, 'duration_min' => 3, 'severity' => '警告', 'target_group' => '接入网关组',
        'notify_channel' => '邮件', 'enabled' => 1,
        'description' => '网关节点入站流量持续 3 分钟超过 780 Mbps 时提示容量核查。',
        'created_by' => '苏临川', 'created_at' => '2025-04-07 16:00:00', 'updated_at' => '2026-10-16 17:22:00',
    ],
    [
        'policy_code' => 'POL-AGENT-OFFLINE', 'policy_name' => '采集代理心跳中断', 'metric_code' => 'AGENT_HEARTBEAT',
        'threshold_value' => 300, 'duration_min' => 2, 'severity' => '严重', 'target_group' => '全部主机',
        'notify_channel' => '短信 / 电话', 'enabled' => 1,
        'description' => '采集代理超过 300 秒无心跳时触发严重告警，并关联主机可用性。',
        'created_by' => '陆知远', 'created_at' => '2024-12-03 09:00:00', 'updated_at' => '2026-10-28 11:36:00',
    ],
    [
        'policy_code' => 'POL-REDIS-LAT', 'policy_name' => 'Redis 响应延迟升高', 'metric_code' => 'REDIS_LATENCY',
        'threshold_value' => 20, 'duration_min' => 5, 'severity' => '警告', 'target_group' => '消息与缓存组',
        'notify_channel' => '企业微信', 'enabled' => 1,
        'description' => '缓存实例 P99 响应延迟连续 5 分钟超过 20ms 时触发。',
        'created_by' => '江屿', 'created_at' => '2025-07-20 10:30:00', 'updated_at' => '2026-11-02 09:10:00',
    ],
    [
        'policy_code' => 'POL-MYSQL-CONN', 'policy_name' => '数据库连接数接近上限', 'metric_code' => 'MYSQL_CONN',
        'threshold_value' => 300, 'duration_min' => 5, 'severity' => '严重', 'target_group' => '数据服务组',
        'notify_channel' => '短信 / 企业微信', 'enabled' => 1,
        'description' => '生产数据库活跃连接连续 5 分钟超过 300 时升级为严重告警。',
        'created_by' => '韩沐川', 'created_at' => '2025-02-08 13:45:00', 'updated_at' => '2026-10-25 15:20:00',
    ],
    [
        'policy_code' => 'POL-EDGE-HEARTBEAT', 'policy_name' => '边缘节点心跳抖动', 'metric_code' => 'AGENT_HEARTBEAT',
        'threshold_value' => 120, 'duration_min' => 5, 'severity' => '警告', 'target_group' => '边缘节点组',
        'notify_channel' => '企业微信', 'enabled' => 0,
        'description' => '边缘节点心跳间隔连续 5 分钟超过 120 秒时提示链路抖动。',
        'created_by' => '温以宁', 'created_at' => '2025-11-16 11:20:00', 'updated_at' => '2026-10-31 14:05:00',
    ],
];
$policyIds = [];
foreach ($policies as $policy) {
    $policyIds[$policy['policy_code']] = $insert('alert_policies', $policy);
}

$alerts = [
    [
        'alert_no' => 'ALT-20261110-001', 'policy' => 'POL-CPU-CORE', 'host' => 'CY-DB-MYSQL-01', 'severity' => '严重',
        'title' => '核心数据库 CPU 使用率持续过高', 'message' => 'CPU 使用率 96.8%，连续 12 分钟高于 90% 阈值，慢查询数量同步上升。',
        'current_value' => 96.8, 'threshold_value' => 90, 'status' => '活跃',
        'started_at' => '2026-11-10 09:42:18', 'acknowledged_at' => null, 'acknowledged_by' => null,
        'resolved_at' => null, 'resolved_by' => null, 'created_at' => '2026-11-10 09:42:18',
    ],
    [
        'alert_no' => 'ALT-20261109-002', 'policy' => 'POL-AGENT-OFFLINE', 'host' => 'CY-EDGE-01', 'severity' => '严重',
        'title' => '边缘节点采集心跳中断', 'message' => '最近心跳停留在 2026-11-09 22:17:32，心跳间隔已超过 300 秒阈值。',
        'current_value' => 42480, 'threshold_value' => 300, 'status' => '活跃',
        'started_at' => '2026-11-09 22:23:05', 'acknowledged_at' => null, 'acknowledged_by' => null,
        'resolved_at' => null, 'resolved_by' => null, 'created_at' => '2026-11-09 22:23:05',
    ],
    [
        'alert_no' => 'ALT-20261110-003', 'policy' => 'POL-MEM-APP', 'host' => 'CY-APP-WEB-02', 'severity' => '严重',
        'title' => '应用节点内存使用率过高', 'message' => '内存使用率 89.4%，高于严重阈值 88%，请求排队深度持续增加。',
        'current_value' => 89.4, 'threshold_value' => 88, 'status' => '活跃',
        'started_at' => '2026-11-10 08:58:10', 'acknowledged_at' => null, 'acknowledged_by' => null,
        'resolved_at' => null, 'resolved_by' => null, 'created_at' => '2026-11-10 08:58:10',
    ],
    [
        'alert_no' => 'ALT-20261110-004', 'policy' => 'POL-DISK-LOG', 'host' => 'CY-MQ-KAFKA-02', 'severity' => '警告',
        'title' => 'Kafka 数据盘使用率超阈值', 'message' => '数据盘使用率 86.7%，高于 82% 预警值，保留策略窗口需复核。',
        'current_value' => 86.7, 'threshold_value' => 82, 'status' => '已确认',
        'started_at' => '2026-11-10 09:11:36', 'acknowledged_at' => '2026-11-10 09:18:05', 'acknowledged_by' => '季明澈',
        'resolved_at' => null, 'resolved_by' => null, 'created_at' => '2026-11-10 09:11:36',
    ],
    [
        'alert_no' => 'ALT-20261110-005', 'policy' => 'POL-DISK-LOG', 'host' => 'CY-LOG-ES-01', 'severity' => '警告',
        'title' => '日志检索节点磁盘水位偏高', 'message' => '热节点数据盘使用率 82.1%，接近只读保护水位。',
        'current_value' => 82.1, 'threshold_value' => 82, 'status' => '活跃',
        'started_at' => '2026-11-10 10:03:22', 'acknowledged_at' => null, 'acknowledged_by' => null,
        'resolved_at' => null, 'resolved_by' => null, 'created_at' => '2026-11-10 10:03:22',
    ],
    [
        'alert_no' => 'ALT-20261110-006', 'policy' => 'POL-REDIS-LAT', 'host' => 'CY-DB-REDIS-01', 'severity' => '警告',
        'title' => 'Redis 响应延迟升高', 'message' => '缓存 P99 延迟达到 24.6ms，连续 8 分钟高于 20ms 阈值。',
        'current_value' => 24.6, 'threshold_value' => 20, 'status' => '已确认',
        'started_at' => '2026-11-10 07:46:51', 'acknowledged_at' => '2026-11-10 08:05:17', 'acknowledged_by' => '季明澈',
        'resolved_at' => null, 'resolved_by' => null, 'created_at' => '2026-11-10 07:46:51',
    ],
    [
        'alert_no' => 'ALT-20261110-007', 'policy' => 'POL-NET-GW', 'host' => 'CY-API-GW-01', 'severity' => '警告',
        'title' => '网关入站流量突增', 'message' => '入站流量峰值 812.4Mbps，超过 780Mbps 预警阈值。',
        'current_value' => 812.4, 'threshold_value' => 780, 'status' => '已恢复',
        'started_at' => '2026-11-10 06:11:09', 'acknowledged_at' => '2026-11-10 06:16:44', 'acknowledged_by' => '苏临川',
        'resolved_at' => '2026-11-10 06:37:48', 'resolved_by' => '系统', 'created_at' => '2026-11-10 06:11:09',
    ],
    [
        'alert_no' => 'ALT-20261110-008', 'policy' => 'POL-MYSQL-CONN', 'host' => 'CY-DB-MYSQL-02', 'severity' => '严重',
        'title' => '只读数据库连接数接近上限', 'message' => '活跃连接数达到 481，超过 300 严重阈值，连接池等待明显。',
        'current_value' => 481, 'threshold_value' => 300, 'status' => '已恢复',
        'started_at' => '2026-11-10 04:27:31', 'acknowledged_at' => '2026-11-10 04:31:08', 'acknowledged_by' => '韩沐川',
        'resolved_at' => '2026-11-10 05:12:04', 'resolved_by' => '系统', 'created_at' => '2026-11-10 04:27:31',
    ],
    [
        'alert_no' => 'ALT-20261109-009', 'policy' => 'POL-LOAD-DB', 'host' => 'CY-DB-MYSQL-01', 'severity' => '警告',
        'title' => '主库系统负载异常', 'message' => '1 分钟负载达到 9.42，连续 6 分钟高于 8，已联系数据库组。',
        'current_value' => 9.42, 'threshold_value' => 8, 'status' => '已恢复',
        'started_at' => '2026-11-09 21:14:22', 'acknowledged_at' => '2026-11-09 21:19:11', 'acknowledged_by' => '季明澈',
        'resolved_at' => '2026-11-09 21:46:33', 'resolved_by' => '系统', 'created_at' => '2026-11-09 21:14:22',
    ],
    [
        'alert_no' => 'ALT-20261110-010', 'policy' => 'POL-EDGE-HEARTBEAT', 'host' => 'CY-EDGE-02', 'severity' => '警告',
        'title' => '边缘节点心跳间隔抖动', 'message' => '宁波边缘节点心跳间隔达到 128 秒，链路存在抖动。',
        'current_value' => 128, 'threshold_value' => 120, 'status' => '活跃',
        'started_at' => '2026-11-10 09:55:10', 'acknowledged_at' => null, 'acknowledged_by' => null,
        'resolved_at' => null, 'resolved_by' => null, 'created_at' => '2026-11-10 09:55:10',
    ],
    [
        'alert_no' => 'ALT-20261109-011', 'policy' => 'POL-CPU-EDGE', 'host' => 'CY-EDGE-02', 'severity' => '提示',
        'title' => '边缘节点 CPU 水位升高', 'message' => 'CPU 使用率短时达到 78.2%，持续 4 分钟后自行回落。',
        'current_value' => 78.2, 'threshold_value' => 75, 'status' => '已恢复',
        'started_at' => '2026-11-09 18:12:40', 'acknowledged_at' => null, 'acknowledged_by' => null,
        'resolved_at' => '2026-11-09 18:31:16', 'resolved_by' => '系统', 'created_at' => '2026-11-09 18:12:40',
    ],
    [
        'alert_no' => 'ALT-20261108-012', 'policy' => 'POL-AGENT-OFFLINE', 'host' => 'CY-BAK-SVR-01', 'severity' => '严重',
        'title' => '备份节点采集心跳中断', 'message' => '备份节点代理重启期间心跳中断 4 分钟，已恢复采集。',
        'current_value' => 286, 'threshold_value' => 300, 'status' => '已恢复',
        'started_at' => '2026-11-08 03:10:29', 'acknowledged_at' => '2026-11-08 03:15:10', 'acknowledged_by' => '顾清越',
        'resolved_at' => '2026-11-08 03:34:52', 'resolved_by' => '系统', 'created_at' => '2026-11-08 03:10:29',
    ],
];
$alertIds = [];
foreach ($alerts as $alert) {
    $alertIds[$alert['alert_no']] = $insert('alerts', [
        'alert_no' => $alert['alert_no'],
        'policy_id' => $policyIds[$alert['policy']],
        'host_id' => $hostIds[$alert['host']],
        'severity' => $alert['severity'],
        'title' => $alert['title'],
        'message' => $alert['message'],
        'current_value' => $alert['current_value'],
        'threshold_value' => $alert['threshold_value'],
        'status' => $alert['status'],
        'started_at' => $alert['started_at'],
        'acknowledged_at' => $alert['acknowledged_at'],
        'acknowledged_by' => $alert['acknowledged_by'],
        'resolved_at' => $alert['resolved_at'],
        'resolved_by' => $alert['resolved_by'],
        'created_at' => $alert['created_at'],
    ]);
}

$tickets = [
    [
        'ticket_no' => 'TKT-20261110-001', 'title' => '核心数据库 CPU 高位与慢查询排查', 'category' => '性能异常',
        'priority' => '紧急', 'status' => '处理中', 'source' => '告警关联', 'assignee' => '韩沐川', 'reporter' => '季明澈',
        'alert' => 'ALT-20261110-001',
        'description' => '主库 CPU 连续保持高位，QPS 未见明显上涨，需排查慢查询、锁等待和备份窗口重叠。',
        'created_at' => '2026-11-10 09:48:12', 'updated_at' => '2026-11-10 10:05:42', 'resolved_at' => null,
    ],
    [
        'ticket_no' => 'TKT-20261110-002', 'title' => '萧山边缘节点失联恢复', 'category' => '节点离线',
        'priority' => '紧急', 'status' => '待处理', 'source' => '告警关联', 'assignee' => '温以宁', 'reporter' => '季明澈',
        'alert' => 'ALT-20261109-002',
        'description' => 'CY-EDGE-01 心跳中断，需联系属地机房确认电力与上行链路，恢复后核验批量指标。',
        'created_at' => '2026-11-10 09:30:28', 'updated_at' => '2026-11-10 09:30:28', 'resolved_at' => null,
    ],
    [
        'ticket_no' => 'TKT-20261110-003', 'title' => 'Kafka-02 数据盘容量扩容', 'category' => '容量规划',
        'priority' => '高', 'status' => '处理中', 'source' => '告警关联', 'assignee' => '江屿', 'reporter' => '苏临川',
        'alert' => 'ALT-20261110-004',
        'description' => '数据盘水位 86.7%，计划在当日低峰窗口扩容 400GB，并调整七天保留策略。',
        'created_at' => '2026-11-10 09:22:04', 'updated_at' => '2026-11-10 09:55:10', 'resolved_at' => null,
    ],
    [
        'ticket_no' => 'TKT-20261110-004', 'title' => '日志索引只读状态处理', 'category' => '服务异常',
        'priority' => '高', 'status' => '待处理', 'source' => '告警关联', 'assignee' => '顾清越', 'reporter' => '季明澈',
        'alert' => 'ALT-20261110-005',
        'description' => '日志热节点水位接近只读保护线，需清理过期索引并评估节点磁盘扩容。',
        'created_at' => '2026-11-10 10:06:05', 'updated_at' => '2026-11-10 10:06:05', 'resolved_at' => null,
    ],
    [
        'ticket_no' => 'TKT-20261109-005', 'title' => '网关 P99 延迟波动复盘', 'category' => '网络质量',
        'priority' => '中', 'status' => '已解决', 'source' => '告警关联', 'assignee' => '苏临川', 'reporter' => '季明澈',
        'alert' => 'ALT-20261110-007',
        'description' => '早高峰流量突增导致网关 P99 延迟波动，扩容带宽调度并补充流量突增策略。',
        'created_at' => '2026-11-09 06:18:32', 'updated_at' => '2026-11-10 06:45:18', 'resolved_at' => '2026-11-10 06:45:18',
    ],
    [
        'ticket_no' => 'TKT-20261109-006', 'title' => 'Redis 内存碎片与延迟优化', 'category' => '性能优化',
        'priority' => '中', 'status' => '已解决', 'source' => '告警关联', 'assignee' => '江屿', 'reporter' => '季明澈',
        'alert' => 'ALT-20261110-006',
        'description' => '调整空闲内存回收参数，完成主从切换验证，P99 延迟回落至 12ms 以内。',
        'created_at' => '2026-11-09 08:02:17', 'updated_at' => '2026-11-09 16:42:30', 'resolved_at' => '2026-11-09 16:42:30',
    ],
    [
        'ticket_no' => 'TKT-20261108-007', 'title' => '批处理窗口执行失败重跑', 'category' => '作业失败',
        'priority' => '高', 'status' => '已关闭', 'source' => '主动上报', 'assignee' => '程意', 'reporter' => '苏临川',
        'alert' => null,
        'description' => '月末账单汇总任务因临时目录空间不足中断，清理缓存后重跑成功并增加空间检查。',
        'created_at' => '2026-11-08 02:14:52', 'updated_at' => '2026-11-08 11:30:16', 'resolved_at' => '2026-11-08 11:30:16',
    ],
    [
        'ticket_no' => 'TKT-20261108-008', 'title' => '备份节点时钟偏移校正', 'category' => '配置变更',
        'priority' => '低', 'status' => '已关闭', 'source' => '巡检发现', 'assignee' => '顾清越', 'reporter' => '季明澈',
        'alert' => null,
        'description' => '备份节点与内网 NTP 源存在 1.8 秒偏差，已调整时间同步策略并复核计划任务。',
        'created_at' => '2026-11-08 09:20:41', 'updated_at' => '2026-11-08 10:12:08', 'resolved_at' => '2026-11-08 10:12:08',
    ],
    [
        'ticket_no' => 'TKT-20261107-009', 'title' => 'NFS 挂载 IO 等待跟踪', 'category' => '存储性能',
        'priority' => '中', 'status' => '已解决', 'source' => '巡检发现', 'assignee' => '顾清越', 'reporter' => '温以宁',
        'alert' => null,
        'description' => '共享存储挂载点在高峰时段出现 IO 等待，已迁移一批冷数据并调整客户端缓存参数。',
        'created_at' => '2026-11-07 14:08:20', 'updated_at' => '2026-11-08 16:25:33', 'resolved_at' => '2026-11-08 16:25:33',
    ],
];
$ticketIds = [];
foreach ($tickets as $ticket) {
    $ticketIds[$ticket['ticket_no']] = $insert('tickets', [
        'ticket_no' => $ticket['ticket_no'],
        'title' => $ticket['title'],
        'category' => $ticket['category'],
        'priority' => $ticket['priority'],
        'status' => $ticket['status'],
        'source' => $ticket['source'],
        'assignee' => $ticket['assignee'],
        'reporter' => $ticket['reporter'],
        'alert_id' => $ticket['alert'] ? $alertIds[$ticket['alert']] : null,
        'description' => $ticket['description'],
        'created_at' => $ticket['created_at'],
        'updated_at' => $ticket['updated_at'],
        'resolved_at' => $ticket['resolved_at'],
    ]);
}

$activities = [
    ['TKT-20261110-001', '季明澈', '创建工单', '关联告警 ALT-20261110-001，指派至数据库组。', '2026-11-10 09:48:12'],
    ['TKT-20261110-001', '韩沐川', '更新进展', '已登录主库检查慢查询，发现报表查询并发偏高，正在限流。', '2026-11-10 10:05:42'],
    ['TKT-20261110-002', '季明澈', '创建工单', '已联系萧山机房值班人员，等待电力与链路反馈。', '2026-11-10 09:30:28'],
    ['TKT-20261110-003', '江屿', '更新进展', '扩容工单已提交云资源平台，等待存储卷挂载。', '2026-11-10 09:55:10'],
    ['TKT-20261110-004', '季明澈', '创建工单', '日志热节点磁盘接近保护水位，安排环境巡检。', '2026-11-10 10:06:05'],
    ['TKT-20261109-005', '苏临川', '处理完成', '调整带宽调度策略并观察 6 小时，延迟恢复稳定。', '2026-11-10 06:45:18'],
    ['TKT-20261109-006', '江屿', '处理完成', '完成缓存参数调整与主从切换，告警恢复。', '2026-11-09 16:42:30'],
    ['TKT-20261108-007', '程意', '关闭工单', '重跑成功，已将剩余空间检查加入作业前置步骤。', '2026-11-08 11:30:16'],
    ['TKT-20261108-008', '顾清越', '关闭工单', '时间同步恢复，计划任务校验通过。', '2026-11-08 10:12:08'],
    ['TKT-20261107-009', '顾清越', '处理完成', '冷数据迁移完成，NFS 客户端 IO 等待恢复到基线。', '2026-11-08 16:25:33'],
];
foreach ($activities as [$ticketNo, $actor, $action, $detail, $createdAt]) {
    $insert('ticket_activities', [
        'ticket_id' => $ticketIds[$ticketNo],
        'actor' => $actor,
        'action' => $action,
        'detail' => $detail,
        'created_at' => $createdAt,
    ]);
}

$agentSpecs = [
    'CY-API-GW-01' => ['AGT-GW-01', '4.2.6', '主机服务', '运行中', '2026-11-10 10:03:48', '2025-08-18 09:20:00', 'cyagt_8f4c...9c21', 'system, cpu, memory, network', '双网卡流量分采'],
    'CY-API-GW-02' => ['AGT-GW-02', '4.2.6', '主机服务', '运行中', '2026-11-10 10:03:29', '2025-08-18 09:26:00', 'cyagt_3d71...a8b4', 'system, cpu, memory, network', '备用网卡同采'],
    'CY-APP-WEB-01' => ['AGT-APP-01', '4.2.6', '容器边车', '运行中', '2026-11-10 10:03:40', '2025-03-11 12:08:00', 'cyagt_b29e...771e', 'system, container, process', '边车模式部署'],
    'CY-APP-WEB-02' => ['AGT-APP-02', '4.2.6', '容器边车', '运行中', '2026-11-10 10:02:58', '2025-03-11 12:12:00', 'cyagt_5c14...e392', 'system, container, process', '边车模式部署'],
    'CY-APP-JOB-01' => ['AGT-JOB-01', '4.2.5', '主机服务', '已暂停', '2026-11-10 07:03:14', '2025-04-22 10:35:00', 'cyagt_9a47...1f6c', 'system, process, log', '维护窗口暂停'],
    'CY-DB-MYSQL-01' => ['AGT-DB-01', '4.2.6', '主机服务', '运行中', '2026-11-10 10:03:18', '2024-12-16 16:30:00', 'cyagt_7e2b...4d80', 'system, mysql, disk', '数据库专项采集'],
    'CY-DB-MYSQL-02' => ['AGT-DB-02', '4.2.6', '主机服务', '运行中', '2026-11-10 10:03:37', '2024-12-16 16:34:00', 'cyagt_f1d6...8a35', 'system, mysql, disk', '数据库专项采集'],
    'CY-DB-REDIS-01' => ['AGT-CACHE-01', '4.2.6', '主机服务', '运行中', '2026-11-10 10:03:05', '2025-02-07 11:20:00', 'cyagt_6b83...c0f9', 'system, redis, network', '缓存链路采样'],
    'CY-MQ-KAFKA-01' => ['AGT-MQ-01', '4.2.6', '容器边车', '运行中', '2026-11-10 10:03:44', '2025-01-28 14:16:00', 'cyagt_2c5a...7d19', 'system, kafka, disk', '消息积压指标采集'],
    'CY-MQ-KAFKA-02' => ['AGT-MQ-02', '4.2.5', '容器边车', '待升级', '2026-11-10 10:03:31', '2025-01-28 14:20:00', 'cyagt_4e97...b3a2', 'system, kafka, disk', '等待窗口升级'],
    'CY-STG-NFS-01' => ['AGT-STG-01', '4.2.6', '主机服务', '运行中', '2026-11-10 10:02:52', '2025-05-06 09:45:00', 'cyagt_ab31...9e67', 'system, disk, nfs', '容量与 IO 指标采集'],
    'CY-LOG-ES-01' => ['AGT-LOG-01', '4.2.5', '主机服务', '待升级', '2026-11-10 10:03:12', '2025-02-18 10:05:00', 'cyagt_0f8d...551c', 'system, disk, elasticsearch', '等待灰度批次'],
    'CY-BAK-SVR-01' => ['AGT-BAK-01', '4.2.5', '主机服务', '待升级', '2026-11-10 10:02:46', '2025-06-03 08:38:00', 'cyagt_d720...e84f', 'system, disk, backup', '灾备域独立升级'],
    'CY-EDGE-01' => ['AGT-EDGE-01', '4.2.3', '边缘网关', '心跳超时', '2026-11-09 22:17:32', '2025-10-12 13:22:00', 'cyagt_71c9...2ab6', 'system, uptime, network', '属地链路待恢复'],
    'CY-EDGE-02' => ['AGT-EDGE-02', '4.2.6', '边缘网关', '运行中', '2026-11-10 10:03:12', '2025-10-12 13:30:00', 'cyagt_c4a8...6f03', 'system, uptime, network', '链路抖动观察中'],
];
foreach ($agentSpecs as $hostname => [$agentId, $version, $installType, $status, $lastHeartbeat, $registeredAt, $token, $scope, $note]) {
    $insert('agents', [
        'agent_id' => $agentId,
        'host_id' => $hostIds[$hostname],
        'version' => $version,
        'install_type' => $installType,
        'platform' => $hostname === 'CY-EDGE-01' || $hostname === 'CY-EDGE-02' ? 'Linux arm64' : 'Linux x86_64',
        'status' => $status,
        'ip_address' => $hosts[array_search($hostname, array_column($hosts, 'hostname'), true)]['ip_address'],
        'last_heartbeat_at' => $lastHeartbeat,
        'registered_at' => $registeredAt,
        'token_hint' => $token,
        'collector_scope' => $scope,
        'note' => $note,
    ]);
}

$events = [
    ['alert', '核心数据库 CPU 高水位告警触发', '策略 POL-CPU-CORE 命中，已通知值班人员。', '监控引擎', '2026-11-10 09:42:18'],
    ['agent', '采集代理 4.2.6 灰度批次完成', '第三批 5 个节点升级完成，心跳与指标上报正常。', '顾清越', '2026-11-10 09:12:36'],
    ['ticket', '工单 TKT-20261110-001 已分派', '工单进入处理中，责任人为韩沐川。', '季明澈', '2026-11-10 09:48:12'],
    ['alert', '日志检索节点容量告警触发', 'CY-LOG-ES-01 磁盘使用率超过 82%。', '监控引擎', '2026-11-10 10:03:22'],
    ['policy', '存储容量策略阈值复核完成', 'POL-DISK-LOG 阈值维持 82%，持续时长调整为 15 分钟。', '季明澈', '2026-11-10 08:45:10'],
    ['report', '日报运行摘要已生成', '2026-11-09 运行日报归档至报表中心。', '平台任务', '2026-11-10 08:00:00'],
    ['ticket', '工单 TKT-20261109-006 已解决', 'Redis 延迟回落至基线，工单转入已解决。', '江屿', '2026-11-09 16:42:30'],
    ['host', '主机资产信息完成月度校对', '15 台主机的分组、机柜与责任人信息完成复核。', '陆知远', '2026-11-09 15:20:44'],
    ['alert', '只读数据库连接告警恢复', '连接数回落至 184，告警自动关闭。', '监控引擎', '2026-11-10 05:12:04'],
    ['ticket', '工单 TKT-20261108-007 已关闭', '批处理失败工单完成复盘并关闭。', '程意', '2026-11-08 11:30:16'],
];
foreach ($events as [$type, $title, $detail, $actor, $createdAt]) {
    $insert('system_events', [
        'event_type' => $type,
        'title' => $title,
        'detail' => $detail,
        'actor' => $actor,
        'created_at' => $createdAt,
    ]);
}

$pdo->commit();

if ($isDirectCli) {
    echo "辰曜基础设施监控平台数据库初始化完成：{$dbPath}\n";
    echo "主机 15 台，告警策略 10 条，告警 12 条，事件工单 9 条，采集代理 15 个。\n";
}
