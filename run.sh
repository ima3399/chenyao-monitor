#!/usr/bin/env bash
set -e

cd "$(dirname "$0")"

PHP_BIN="../tools/php/php.exe"
if [ ! -f "$PHP_BIN" ]; then
    PHP_BIN="php"
fi

"$PHP_BIN" -S 127.0.0.1:18115 -t integrations/metrics-gateway integrations/metrics-gateway/router.php >/tmp/chenyao-metrics-gateway.log 2>&1 &
GATEWAY_PID=$!

"$PHP_BIN" -S 127.0.0.1:18114 -t public >/tmp/chenyao-monitor.log 2>&1 &
APP_PID=$!

echo "辰曜基础设施监控平台：http://127.0.0.1:18114"
echo "合作方指标汇聚联调服务：http://127.0.0.1:18115/metrics.txt"
echo "默认账号：admin / Chenyao@2026"
echo "按 Ctrl+C 停止服务。"

trap 'kill "$GATEWAY_PID" "$APP_PID" 2>/dev/null || true' EXIT INT TERM
wait
