CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    real_name TEXT NOT NULL,
    role TEXT NOT NULL,
    dept TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    status TEXT NOT NULL DEFAULT '启用',
    last_login_at TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE monitor_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_code TEXT NOT NULL UNIQUE,
    group_name TEXT NOT NULL,
    owner TEXT NOT NULL,
    color TEXT NOT NULL,
    description TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);

CREATE TABLE hosts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT NOT NULL UNIQUE,
    ip_address TEXT NOT NULL UNIQUE,
    group_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT '在线',
    os_name TEXT NOT NULL,
    cpu_cores INTEGER NOT NULL,
    memory_gb REAL NOT NULL,
    disk_gb REAL NOT NULL,
    location TEXT NOT NULL,
    environment TEXT NOT NULL DEFAULT '生产环境',
    agent_version TEXT,
    uptime_days INTEGER NOT NULL DEFAULT 0,
    last_report_at TEXT,
    description TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (group_id) REFERENCES monitor_groups(id)
);

CREATE TABLE metric_catalog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    metric_code TEXT NOT NULL UNIQUE,
    metric_name TEXT NOT NULL,
    unit TEXT NOT NULL,
    category TEXT NOT NULL,
    warn_threshold REAL NOT NULL,
    critical_threshold REAL NOT NULL,
    description TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE host_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    host_id INTEGER NOT NULL,
    metric_code TEXT NOT NULL,
    metric_name TEXT NOT NULL,
    unit TEXT NOT NULL,
    value REAL NOT NULL,
    warn_threshold REAL NOT NULL,
    critical_threshold REAL NOT NULL,
    status TEXT NOT NULL DEFAULT '正常',
    collected_at TEXT NOT NULL,
    UNIQUE (host_id, metric_code),
    FOREIGN KEY (host_id) REFERENCES hosts(id)
);

CREATE TABLE metric_samples (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    host_id INTEGER NOT NULL,
    metric_code TEXT NOT NULL,
    sampled_at TEXT NOT NULL,
    value REAL NOT NULL,
    FOREIGN KEY (host_id) REFERENCES hosts(id)
);

CREATE TABLE alert_policies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    policy_code TEXT NOT NULL UNIQUE,
    policy_name TEXT NOT NULL,
    metric_code TEXT NOT NULL,
    threshold_value REAL NOT NULL,
    duration_min INTEGER NOT NULL DEFAULT 5,
    severity TEXT NOT NULL,
    target_group TEXT NOT NULL DEFAULT '全部主机',
    notify_channel TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    description TEXT,
    created_by TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alert_no TEXT NOT NULL UNIQUE,
    policy_id INTEGER NOT NULL,
    host_id INTEGER NOT NULL,
    severity TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    current_value REAL,
    threshold_value REAL,
    status TEXT NOT NULL DEFAULT '活跃',
    started_at TEXT NOT NULL,
    acknowledged_at TEXT,
    acknowledged_by TEXT,
    resolved_at TEXT,
    resolved_by TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (policy_id) REFERENCES alert_policies(id),
    FOREIGN KEY (host_id) REFERENCES hosts(id)
);

CREATE TABLE tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_no TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    category TEXT NOT NULL,
    priority TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT '待处理',
    source TEXT NOT NULL DEFAULT '告警关联',
    assignee TEXT NOT NULL,
    reporter TEXT NOT NULL,
    alert_id INTEGER,
    description TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    resolved_at TEXT,
    FOREIGN KEY (alert_id) REFERENCES alerts(id)
);

CREATE TABLE ticket_activities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER NOT NULL,
    actor TEXT NOT NULL,
    action TEXT NOT NULL,
    detail TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id)
);

CREATE TABLE agents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    agent_id TEXT NOT NULL UNIQUE,
    host_id INTEGER NOT NULL UNIQUE,
    version TEXT NOT NULL,
    install_type TEXT NOT NULL,
    platform TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT '运行中',
    ip_address TEXT NOT NULL,
    last_heartbeat_at TEXT,
    registered_at TEXT NOT NULL,
    token_hint TEXT NOT NULL,
    collector_scope TEXT NOT NULL,
    note TEXT,
    FOREIGN KEY (host_id) REFERENCES hosts(id)
);

CREATE TABLE system_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    title TEXT NOT NULL,
    detail TEXT,
    actor TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX idx_hosts_group ON hosts(group_id);
CREATE INDEX idx_hosts_status ON hosts(status);
CREATE INDEX idx_metrics_host ON host_metrics(host_id);
CREATE INDEX idx_metrics_code ON host_metrics(metric_code);
CREATE INDEX idx_samples_host_code ON metric_samples(host_id, metric_code);
CREATE INDEX idx_samples_time ON metric_samples(sampled_at);
CREATE INDEX idx_alerts_policy ON alerts(policy_id);
CREATE INDEX idx_alerts_host ON alerts(host_id);
CREATE INDEX idx_alerts_status ON alerts(status);
CREATE INDEX idx_tickets_status ON tickets(status);
CREATE INDEX idx_ticket_activities_ticket ON ticket_activities(ticket_id);
CREATE INDEX idx_agents_status ON agents(status);
