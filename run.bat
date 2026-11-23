@echo off
setlocal
cd /d "%~dp0"

set "PHP=%~dp0..\tools\php\php.exe"
if not exist "%PHP%" set "PHP=php"

echo [辰曜监控] 正在启动辰曜基础设施监控平台：http://127.0.0.1:18114
start "ChenyaoMonitor" "%PHP%" -S 127.0.0.1:18114 -t "%~dp0public"

echo [辰曜监控] 正在启动合作方指标汇聚联调服务：http://127.0.0.1:18115/metrics.txt
start "ChenyaoMetricsGateway" "%PHP%" -S 127.0.0.1:18115 -t "%~dp0integrations\metrics-gateway" "%~dp0integrations\metrics-gateway\router.php"

timeout /t 2 /nobreak >nul
start "" http://127.0.0.1:18114/

echo.
echo 服务已启动。关闭对应命令行窗口即可停止服务。
echo 默认账号：admin / Chenyao@2026
endlocal
