<?php
declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/metrics.txt') {
    $file = __DIR__ . '/metrics.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Gateway-Marker: MONITORING-METRICS-GATEWAY');
    header('X-Gateway-Service: metrics-gateway');
    header('Cache-Control: no-store');
    readfile($file);
    return true;
}

if ($path === '/' || $path === '/index.php') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Gateway-Marker: MONITORING-METRICS-GATEWAY');
    header('X-Gateway-Service: metrics-gateway');
    echo "MONITORING-METRICS-GATEWAY\n";
    echo "辰曜监控指标汇聚联调服务\n";
    echo "指标报文：/metrics.txt\n";
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not Found\n";
return true;
