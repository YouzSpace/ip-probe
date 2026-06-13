<?php
/**
 * SQLite 数据库模块
 * 
 * 单例连接 + 建表 + 通用查询辅助
 * 依赖：config.php
 */

/** 数据库文件路径 */
define('DB_FILE', DATA_DIR . '/ipprobe.db');

/**
 * 获取 SQLite 数据库连接（单例）
 * 
 * @return SQLite3
 */
function get_db(): SQLite3
{
    static $db = null;
    if ($db !== null) return $db;

    $db = new SQLite3(DB_FILE);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA encoding="UTF-8"');

    return $db;
}

/**
 * 初始化数据库表
 * 
 * 建表 IF NOT EXISTS，可重复调用
 */
function init_database(): void
{
    $db = get_db();

    $db->exec("
        CREATE TABLE IF NOT EXISTS links (
            id TEXT PRIMARY KEY,
            redirect_url TEXT NOT NULL DEFAULT '',
            remark TEXT NOT NULL DEFAULT '',
            collect_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS records (
            id TEXT PRIMARY KEY,
            server_ip TEXT NOT NULL DEFAULT '',
            link_id TEXT NOT NULL DEFAULT '',
            ipv4 TEXT NOT NULL DEFAULT '',
            ipv6 TEXT NOT NULL DEFAULT '',
            webrtc_ips TEXT NOT NULL DEFAULT '[]',
            location TEXT NOT NULL DEFAULT '{}',
            user_agent_raw TEXT NOT NULL DEFAULT '',
            os TEXT NOT NULL DEFAULT '',
            browser TEXT NOT NULL DEFAULT '',
            language TEXT NOT NULL DEFAULT '',
            screen TEXT NOT NULL DEFAULT '',
            dpr REAL NOT NULL DEFAULT 1,
            battery_level INTEGER,
            battery_charging INTEGER,
            network_type TEXT,
            connection_downlink REAL,
            is_proxy INTEGER NOT NULL DEFAULT 0,
            proxy_detected_by TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS checkin_links (
            id TEXT PRIMARY KEY,
            remark TEXT NOT NULL DEFAULT '',
            checkin_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS checkin_records (
            id TEXT PRIMARY KEY,
            server_ip TEXT NOT NULL DEFAULT '',
            checkin_id TEXT NOT NULL DEFAULT '',
            ipv4 TEXT NOT NULL DEFAULT '',
            ipv6 TEXT NOT NULL DEFAULT '',
            location TEXT NOT NULL DEFAULT '{}',
            user_agent_raw TEXT NOT NULL DEFAULT '',
            os TEXT NOT NULL DEFAULT '',
            browser TEXT NOT NULL DEFAULT '',
            language TEXT NOT NULL DEFAULT '',
            screen TEXT NOT NULL DEFAULT '',
            dpr REAL NOT NULL DEFAULT 1,
            is_proxy INTEGER NOT NULL DEFAULT 0,
            proxy_detected_by TEXT,
            gps TEXT,
            photo TEXT,
            photo_url TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    // 索引
    $db->exec("CREATE INDEX IF NOT EXISTS idx_records_link_id ON records(link_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_records_created ON records(created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_records_server_ip ON records(server_ip)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_checkin_records_checkin_id ON checkin_records(checkin_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_checkin_records_created ON checkin_records(created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_checkin_records_server_ip ON checkin_records(server_ip)");

    // 配置表（密码哈希、2FA密钥、登录锁定等）
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )
    ");
}

/**
 * 获取配置值
 * 
 * @param string $key     配置键
 * @param string $default 默认值
 * @return string
 */
function get_setting(string $key, string $default = ''): string
{
    $db = get_db();
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->bindValue(':key', $key);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_NUM);
    return $row ? $row[0] : $default;
}

/**
 * 设置配置值（存在则更新，不存在则插入）
 */
function set_setting(string $key, string $value): bool
{
    $db = get_db();
    $stmt = $db->prepare('INSERT INTO settings (key, value) VALUES (:key, :val) ON CONFLICT(key) DO UPDATE SET value = :val');
    $stmt->bindValue(':key', $key);
    $stmt->bindValue(':val', $value);
    return $stmt->execute() !== false;
}

/**
 * 删除配置值
 */
function delete_setting(string $key): bool
{
    $db = get_db();
    $stmt = $db->prepare('DELETE FROM settings WHERE key = :key');
    $stmt->bindValue(':key', $key);
    $stmt->execute();
    return $db->changes() > 0;
}

/**
 * 获取客户端 IP
 */
function db_get_client_ip(): string
{
    if (function_exists('get_client_ip')) return get_client_ip();

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
    return $ip;
}
