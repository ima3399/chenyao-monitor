# 辰曜基础设施监控平台

辰曜基础设施监控平台（品牌：辰曜监控）是辰曜智能科技有限公司面向生产基础设施打造的一体化运维监控平台，覆盖主机资产、指标汇聚、告警策略、告警处置、事件工单、采集代理与运行报表，帮助运维团队在同一工作台中掌握华东生产域的资源水位与响应进展。

## 技术栈

- PHP 8.1（原生开发，无框架、无 Composer、无外部 CDN）
- SQLite 3 + PDO（首次访问自动建表并写入初始数据）
- HTML5 / CSS3 / 原生 JavaScript
- PHP 内置服务器运行，项目同级 `../tools/php` 提供便携式 PHP 运行环境

## 功能模块

| 模块 | 说明 |
| --- | --- |
| 工作台 | 主机可用率、未恢复告警、工单队列、资源趋势、告警等级分布与运行动态 |
| 主机资产 | 15 台纳管主机的分组、机柜、规格、运行状态、资源水位与采集代理信息 |
| 指标监控 | CPU、内存、磁盘、负载、入站流量与采集心跳的小时聚合趋势和阈值分析 |
| 告警策略 | 指标阈值、持续窗口、适用范围、通知方式与启停管理 |
| 告警记录 | 告警触发、确认与恢复过程，支持按级别、状态与分组筛选和处置 |
| 事件工单 | 问题分类、优先级、责任人、处置时间线与状态流转 |
| 采集代理 | 代理版本、部署方式、采集范围、心跳状态、令牌轮换与启停 |
| 报表中心 | 主机健康、告警统计、工单效率、采集覆盖的周期汇总与 CSV 导出 |

## 快速启动

### Windows

双击根目录的 `run.bat`，将同时启动主平台与合作方指标汇聚联调服务，并打开浏览器：

| 服务 | 地址 |
| --- | --- |
| 辰曜基础设施监控平台 | http://127.0.0.1:18114/ |
| 合作方指标汇聚联调服务 | http://127.0.0.1:18115/metrics.txt |

### 手动启动

```bat
REM 主平台
..\tools\php\php.exe -S 127.0.0.1:18114 -t public

REM 另开终端，启动合作方指标汇聚联调服务
..\tools\php\php.exe -S 127.0.0.1:18115 -t integrations\metrics-gateway integrations\metrics-gateway\router.php
```

Linux 或已安装 PHP 8.1 及 `pdo_sqlite`、`curl`、`mbstring` 扩展的环境可使用：

```bash
../tools/php/php.exe -S 127.0.0.1:18114 -t public
../tools/php/php.exe -S 127.0.0.1:18115 -t integrations/metrics-gateway integrations/metrics-gateway/router.php
```

也可以执行 `bash run.sh` 同时启动两个服务，按 `Ctrl+C` 结束。

## 默认账号

| 账号 | 密码 | 姓名 | 角色 | 部门 |
| --- | --- | --- | --- | --- |
| `admin` | `Chenyao@2026` | 陆知远 | 系统管理员 | 平台工程部 |
| `ops` | `Monitor@2026` | 季明澈 | 运维主管 | 运维中心 |

首次登录后请及时调整账号口令，并妥善保管运维中心账号。

## 初始化与重置

首次访问任意页面时，系统会自动创建 `database/chenyao-monitor.sqlite`，并写入 15 台主机、指标采样、10 条告警策略、12 条告警、9 个事件工单、15 个采集代理和 6 个监控分组等初始数据。

重置数据：

```bat
del /f /q database\chenyao-monitor.sqlite*
..\tools\php\php.exe database\init.php
```

Linux / Git Bash：

```bash
rm -f database/chenyao-monitor.sqlite database/chenyao-monitor.sqlite-wal database/chenyao-monitor.sqlite-shm
../tools/php/php.exe database/init.php
```

## 目录结构

```text
chenyao-monitor/
├─ lib/                          # 启动引导、数据库、认证与辅助函数
├─ database/
│  ├─ schema.sql                 # SQLite 表结构
│  ├─ init.php                   # 建表、初始数据与目录初始化
│  └─ chenyao-monitor.sqlite     # 首次访问自动生成
├─ public/                       # Web 根目录
│  ├─ login.php / dashboard.php
│  ├─ hosts.php / metrics.php
│  ├─ alert-policies.php / alerts.php
│  ├─ tickets.php / agents.php / reports.php
│  ├─ partials/                  # 页面布局
│  ├─ assets/                    # 样式、脚本与图标
│  ├─ cy-uploads/                # 代理包与告警截图上传目录
│  └─ cymon-*                    # 监控业务接口与网关目录
├─ integrations/
│  └─ metrics-gateway/           # 合作方指标汇聚联调服务
├─ docs/                         # 运维与接口资料
├─ storage/                      # 采集日志、归档与报表文件
├─ run.bat / run.sh              # 一键启动脚本
└─ README.md
```

## 使用范围

本系统用于辰曜智能科技有限公司华东生产域的基础设施监控与运维协作，涉及主机、指标、告警与处置记录等信息，仅限授权人员在内部网络使用。请勿将服务直接暴露到公网，账号、采集令牌与上传目录应定期检查，导出的 CSV 文件应存放在受控目录并及时清理。
