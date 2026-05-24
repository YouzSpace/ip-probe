<?php
/**
 * IP 探针系统 — 统一 API 路由入口
 * 
 * 所有请求通过 ?action=xxx 路由到对应处理逻辑。
 * 依赖：config.php + 所有 includes/ 模块
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ip.php';
require_once __DIR__ . '/includes/links.php';
require_once __DIR__ . '/includes/records.php';

// 设置 JSON 响应头
header('Content-Type: application/json; charset=utf-8');

// 对管理接口初始化 Session（除 get_ip、save、collect_info 外都需要 Session）
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 需要 Session 的 action
$authActions = ['login', 'logout', 'check_auth', 'get_stats', 'get_links', 'create_link', 'delete_link', 'get_records', 'get_record', 'delete_records'];

if (in_array($action, $authActions, true)) {
    init_session();
}

try {
    switch ($action) {
        // ====== 公开接口 ======

        case 'get_ip':
            handle_get_ip();
            break;

        case 'save':
            handle_save();
            break;

        case 'collect_info':
            handle_collect_info();
            break;

        // ====== 管理接口 ======

        case 'login':
            handle_login();
            break;

        case 'logout':
            handle_logout();
            break;

        case 'check_auth':
            handle_check_auth();
            break;

        case 'get_stats':
            require_auth();
            handle_get_stats();
            break;

        case 'get_links':
            require_auth();
            handle_get_links();
            break;

        case 'create_link':
            require_auth();
            handle_create_link();
            break;

        case 'delete_link':
            require_auth();
            handle_delete_link();
            break;

        case 'get_records':
            require_auth();
            handle_get_records();
            break;

        case 'get_record':
            require_auth();
            handle_get_record();
            break;

        case 'delete_records':
            require_auth();
            handle_delete_records();
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => '未知操作'], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '服务器内部错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}


// ====== 处理函数 ======

/**
 * get_ip: 获取公网 IP + 归属地 + 代理检测
 */
function handle_get_ip(): void
{
    $clientIp = get_client_ip();

    // 判断客户端 IP 是否为私有/回环地址（本地测试时 REMOTE_ADDR = ::1 或 127.0.0.1）
    $isPrivate = filter_var(
        $clientIp,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;

    // 私有/回环地址无法查询归属地，改用 ipify 获取服务器公网 IP 作为参考
    if ($isPrivate) {
        $publicIp = get_public_ip();
        $geoIp    = $publicIp['ipv4'] ?: $publicIp['ipv6'] ?: '';
    } else {
        $geoIp = $clientIp;
    }

    $location = get_ip_location($geoIp);
    $proxy    = detect_proxy($_SERVER, $clientIp, $location);

    $isV4 = filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    $isV6 = filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

    // 私有地址时，把 ipify 返回的公网 IP 填入 ipv4/ipv6 供页面展示
    if ($isPrivate && isset($publicIp)) {
        $showIpv4 = $publicIp['ipv4'] ?? '';
        $showIpv6 = $publicIp['ipv6'] ?? '';
    } else {
        $showIpv4 = $isV4 ? $clientIp : '';
        $showIpv6 = $isV6 ? $clientIp : '';
    }

    $result = [
        'ipv4'      => $showIpv4,
        'ipv6'      => $showIpv6,
        'client_ip' => $clientIp,
        'is_local'  => $isPrivate,
        'location'  => [
            'country'  => $location['country'] ?? '未知',
            'province' => $location['province'] ?? '',
            'city'     => $location['city'] ?? '',
            'district' => $location['district'] ?? '',
            'isp'      => $location['isp'] ?? '',
            'source'   => $location['source'] ?? '',
        ],
        'is_proxy'          => $proxy['is_proxy'],
        'proxy_detected_by' => $proxy['detected_by'],
    ];

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * save: 保存采集记录
 */
function handle_save(): void
{
    // 读取 POST body
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => '无效的请求数据'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 验证必填字段
    if (empty($data['link_id'])) {
        http_response_code(400);
        echo json_encode(['error' => '缺少 link_id'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 防重放：检查同 IP 在 RATE_LIMIT_SECONDS 内是否已提交
    $clientIp = get_client_ip();
    $existingRecords = read_json(RECORDS_FILE);
    $records = $existingRecords['records'] ?? [];

    if (!empty($records)) {
        $lastRecord = $records[count($records) - 1];
        $lastIp = $lastRecord['server_ip'] ?? '';
        $lastTime = strtotime($lastRecord['created_at'] ?? '');

        if ($lastIp === $clientIp && $lastTime !== false) {
            $elapsed = time() - $lastTime;
            if ($elapsed < RATE_LIMIT_SECONDS) {
                echo json_encode(['success' => false, 'error' => '提交过于频繁，请稍后再试'], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
    }

    $result = save_record($data);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * collect_info: 获取采集链接的跳转 URL
 */
function handle_collect_info(): void
{
    $id = $_GET['id'] ?? '';

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => '缺少链接 ID'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $link = get_link($id);

    if ($link === null) {
        http_response_code(404);
        echo json_encode(['error' => '链接不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode([
        'redirect_url' => $link['redirect_url'],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * login: 管理员登录
 */
function handle_login(): void
{
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);
    $password = $data['password'] ?? '';

    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '请输入密码'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (login($password)) {
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => '密码错误'], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * logout: 退出登录
 */
function handle_logout(): void
{
    logout();
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
}

/**
 * check_auth: 检查登录状态
 */
function handle_check_auth(): void
{
    echo json_encode(['logged_in' => is_logged_in()], JSON_UNESCAPED_UNICODE);
}

/**
 * get_stats: 获取仪表盘统计数据
 */
function handle_get_stats(): void
{
    $stats = get_stats();
    echo json_encode($stats, JSON_UNESCAPED_UNICODE);
}

/**
 * get_links: 获取链接列表
 */
function handle_get_links(): void
{
    $result = get_links();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * create_link: 创建采集链接
 */
function handle_create_link(): void
{
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    $redirectUrl = $data['redirect_url'] ?? '';
    $remark = $data['remark'] ?? '';

    if (empty($redirectUrl)) {
        http_response_code(400);
        echo json_encode(['error' => '请输入跳转 URL'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $result = create_link($redirectUrl, $remark);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * delete_link: 删除采集链接
 */
function handle_delete_link(): void
{
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);
    $id = $data['id'] ?? '';

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => '缺少链接 ID'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $success = delete_link($id);
    echo json_encode(['success' => $success], JSON_UNESCAPED_UNICODE);
}

/**
 * get_records: 获取采集记录列表
 */
function handle_get_records(): void
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $search = $_GET['search'] ?? '';

    $result = get_records($page, $limit, $search);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * get_record: 获取单条记录详情
 */
function handle_get_record(): void
{
    $id = $_GET['id'] ?? '';

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => '缺少记录 ID'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $record = get_record($id);

    if ($record === null) {
        http_response_code(404);
        echo json_encode(['error' => '记录不存在'], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode(['record' => $record], JSON_UNESCAPED_UNICODE);
}

/**
 * delete_records: 批量删除采集记录
 */
function handle_delete_records(): void
{
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);
    $ids = $data['ids'] ?? [];

    if (empty($ids) || !is_array($ids)) {
        http_response_code(400);
        echo json_encode(['error' => '缺少记录 ID 列表'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $deleted = delete_records($ids);
    echo json_encode(['success' => true, 'deleted' => $deleted], JSON_UNESCAPED_UNICODE);
}
