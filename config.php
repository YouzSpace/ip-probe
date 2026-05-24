<?php
/**
 * IP 探针系统 — 全局配置文件
 * 
 * 定义所有全局常量，并在文件末尾自动初始化数据目录和 JSON 文件。
 * 所有模块通过 require_once 引入本文件获取配置。
 */

// ====== 目录与路径 ======

/** 数据存储目录 */
define('DATA_DIR', __DIR__ . '/data');

// ====== 认证配置 ======

/** 默认管理员密码 */
define('DEFAULT_PASSWORD', 'admin123');

// ====== 外部 API ======

/** 公网 IP 查询 API（ipify，支持 IPv4/IPv6） */
define('IP_API_URL', 'https://api64.ipify.org?format=json');

/** IP 归属地查询 API（ip-api.com，免费无需 Key） */
define('GEO_API_URL', 'http://ip-api.com/json/');

// IPDataCloud 已废弃，ip9.com.cn（免费免Key直接开通）替代

// ====== 存储文件路径 ======

/** 采集记录 JSON 文件 */
define('RECORDS_FILE', DATA_DIR . '/records.json');

/** 采集链接 JSON 文件 */
define('LINKS_FILE', DATA_DIR . '/links.json');

// ====== 站点配置 ======

/** 站点根 URL（自动检测，部署时也可手动覆盖） */
if (isset($_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME'])) {
    define('SITE_URL', $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'));
} else {
    define('SITE_URL', '');
}

// ====== 会话配置 ======

/** Session 有效期（秒），默认 2 小时 */
define('SESSION_LIFETIME', 7200);

// ====== 安全配置 ======

/** 同 IP 采集频率限制（秒），防止重复提交 */
define('RATE_LIMIT_SECONDS', 10);

// ====== 自动初始化 ======

// 自动初始化 data 目录
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// 自动初始化 JSON 文件
foreach ([RECORDS_FILE, LINKS_FILE] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([], JSON_PRETTY_PRINT));
    }
}
