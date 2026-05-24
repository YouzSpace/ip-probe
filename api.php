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
$authActions = ['login', 'logout', 'check_auth', 'get_stats', 'get_links', 'create_link', 'delete_link', 'get_records', 'get_record', 'delete_records', 'do_update'];

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

        case 'check_update':
            handle_check_update();
            break;

        case 'do_update':
            require_auth();
            handle_do_update();
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

/**
 * check_update: 检查 GitHub 仓库是否有新版本
 *
 * 先查 Releases API，没有 Release 则回退到 Commits API。
 * 公开接口，无需登录。
 */
function handle_check_update(): void
{
    $repo       = GITHUB_REPO;
    $currentVer = APP_VERSION;

    // --- 1. 检查最新 Release ---
    $release = github_api("https://api.github.com/repos/{$repo}/releases/latest");
    if ($release !== null && !empty($release['tag_name'])) {
        $latest = $release['tag_name'];
        echo json_encode([
            'current_version' => $currentVer,
            'latest_version'  => $latest,
            'has_update'      => $latest !== $currentVer,
            'type'            => 'release',
            'release_name'    => $release['name'] ?? $latest,
            'body'            => $release['body'] ?? '',
            'published_at'    => $release['published_at'] ?? '',
            'html_url'        => $release['html_url'] ?? "https://github.com/{$repo}/releases/tag/{$latest}",
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // --- 2. 回退：查最新 Commit ---
    $commits = github_api("https://api.github.com/repos/{$repo}/commits?per_page=1");
    if ($commits !== null && is_array($commits) && isset($commits[0])) {
        $commit = $commits[0];
        $sha = $commit['sha'] ?? '';
        $shortSha = substr($sha, 0, 7);
        $msg = $commit['commit']['message'] ?? '';
        $date = $commit['commit']['author']['date'] ?? '';

        echo json_encode([
            'current_version'  => $currentVer,
            'latest_version'   => $shortSha,
            'has_update'       => null, // 无法仅凭 commit SHA 判断
            'type'             => 'commit',
            'commit_message'   => $msg,
            'commit_date'      => $date,
            'html_url'         => $commit['html_url'] ?? "https://github.com/{$repo}/commit/{$sha}",
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // --- 3. 完全失败 ---
    http_response_code(502);
    echo json_encode(['error' => '无法获取 GitHub 仓库信息，请稍后重试'], JSON_UNESCAPED_UNICODE);
}

/**
 * 调用 GitHub API（带 User-Agent 和 10s 超时）
 */
function github_api(string $url): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: ip-probe\r\nAccept: application/vnd.github+json\r\n",
            'timeout' => 10,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) return null;

    return $data;
}

/**
 * do_update: 执行 git pull 拉取最新代码
 *
 * 需要管理权限。部署目录必须是 git 仓库。
 */
function handle_do_update(): void
{
    // 检查 exec 是否可用
    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'output' => 'exec() 已被禁用，无法执行 git pull。请手动 SSH 更新。'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $projectDir = __DIR__;
    $output    = [];
    $returnVal = 0;

    // 先 fetch，再 pull（避免 stale ref 问题）
    $cmd = sprintf(
        'git -C %s fetch origin main 2>&1 && git -C %s reset --hard origin/main 2>&1',
        escapeshellarg($projectDir),
        escapeshellarg($projectDir)
    );

    exec($cmd, $output, $returnVal);

    $lines = implode("\n", $output);

    if ($returnVal !== 0) {
        http_response_code(500);
        echo json_encode(['success' => false, 'output' => $lines ?: 'git pull 执行失败'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 更新成功：记录新版本号（如果有 git tag）
    $newTag = [];
    exec('git -C ' . escapeshellarg($projectDir) . ' describe --tags --abbrev=0 2>&1', $newTag);
    $version = !empty($newTag[0]) ? $newTag[0] : APP_VERSION;

    echo json_encode([
        'success'  => true,
        'output'   => $lines,
        'version'  => $version,
    ], JSON_UNESCAPED_UNICODE);
}
