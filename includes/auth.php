<?php
/**
 * 认证模块
 * 
 * 提供 Session 管理、密码验证、两步验证（Google Authenticator）、登录状态检查等功能。
 * 密码哈希存储在 data/.password_hash 文件中，2FA 密钥存储在 data/.2fa_secret 文件中。
 * 依赖：config.php, includes/totp.php
 */

/** 登录失败锁定文件 */
define('LOGIN_LOCKOUT_FILE', DATA_DIR . '/.login_lockout');

/** 最大失败尝试次数 */
define('LOGIN_MAX_ATTEMPTS', 5);

/** 锁定时间（秒） */
define('LOGIN_LOCKOUT_SECONDS', 300);

/**
 * 初始化 Session
 */
function init_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_samesite', 'Lax');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }
        ini_set('session.gc_maxlifetime', (string) max(SESSION_LIFETIME, 86400));
        session_start();
    }
}

/**
 * 获取密码哈希值
 */
function get_password_hash(): string
{
    $hashFile = DATA_DIR . '/.password_hash';

    if (!file_exists($hashFile)) {
        $hash = password_hash(DEFAULT_PASSWORD, PASSWORD_DEFAULT);
        file_put_contents($hashFile, $hash);
        return $hash;
    }

    return trim(file_get_contents($hashFile));
}

// ====== 暴力破解防护 ======

/**
 * 获取当前 IP 的失败尝试记录
 */
function get_login_attempts(): array
{
    if (!file_exists(LOGIN_LOCKOUT_FILE)) return [];
    $data = json_decode(file_get_contents(LOGIN_LOCKOUT_FILE), true);
    if (!is_array($data)) return [];
    return $data;
}

/**
 * 记录一次登录失败
 */
function record_login_failure(string $ip): void
{
    $attempts = get_login_attempts();
    $now = time();

    // 清理过期记录
    foreach ($attempts as $k => $v) {
        if ($now - $v['time'] > LOGIN_LOCKOUT_SECONDS) {
            unset($attempts[$k]);
        }
    }

    $attempts[] = ['ip' => $ip, 'time' => $now];
    file_put_contents(LOGIN_LOCKOUT_FILE, json_encode($attempts));
}

/**
 * 检查 IP 是否被锁定
 */
function is_login_locked(string $ip): bool
{
    $attempts = get_login_attempts();
    $now = time();
    $count = 0;

    foreach ($attempts as $v) {
        if ($v['ip'] === $ip && $now - $v['time'] < LOGIN_LOCKOUT_SECONDS) {
            $count++;
        }
    }

    return $count >= LOGIN_MAX_ATTEMPTS;
}

/**
 * 清除当前 IP 的失败记录（登录成功后调用）
 */
function clear_login_failures(string $ip): void
{
    $attempts = get_login_attempts();
    $now = time();
    $cleaned = [];

    foreach ($attempts as $v) {
        if ($v['ip'] !== $ip && $now - $v['time'] < LOGIN_LOCKOUT_SECONDS) {
            $cleaned[] = $v;
        }
    }

    file_put_contents(LOGIN_LOCKOUT_FILE, json_encode($cleaned));
}

// ====== 2FA 管理 ======

function get_2fa_secret_file(): string { return DATA_DIR . '/.2fa_secret'; }

function is_2fa_enabled(): bool
{
    $file = get_2fa_secret_file();
    if (!file_exists($file)) return false;
    $secret = trim(file_get_contents($file));
    return !empty($secret) && strlen($secret) >= 16;
}

function get_2fa_secret(): ?string
{
    $file = get_2fa_secret_file();
    if (!file_exists($file)) return null;
    $secret = trim(file_get_contents($file));
    return !empty($secret) ? $secret : null;
}

function set_2fa_secret(string $secret): bool
{
    return file_put_contents(get_2fa_secret_file(), $secret) !== false;
}

function remove_2fa_secret(): bool
{
    $file = get_2fa_secret_file();
    if (file_exists($file)) return unlink($file);
    return true;
}

function verify_2fa_code(string $code): bool
{
    $secret = get_2fa_secret();
    if ($secret === null) return false;
    return totp_verify($secret, $code);
}

// ====== 登录流程 ======

/**
 * 第一步：验证密码
 */
function login(string $password): array
{
    $ip = get_client_ip();

    // 暴力破解检查
    if (is_login_locked($ip)) {
        return ['success' => false, 'need_2fa' => false, 'error' => '登录失败次数过多，请 5 分钟后再试'];
    }

    $hash = get_password_hash();

    if (!password_verify($password, $hash)) {
        record_login_failure($ip);
        return ['success' => false, 'need_2fa' => false, 'error' => '密码错误'];
    }

    // 密码正确，清除失败记录
    clear_login_failures($ip);

    if (is_2fa_enabled()) {
        $_SESSION['password_verified'] = true;
        $_SESSION['password_verified_time'] = time();
        return ['success' => true, 'need_2fa' => true];
    }

    // 未启用 2FA：直接登录
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    unset($_SESSION['password_verified']);
    return ['success' => true, 'need_2fa' => false];
}

/**
 * 第二步：验证 2FA 验证码
 */
function login_2fa(string $code): array
{
    if (empty($_SESSION['password_verified'])) {
        return ['success' => false, 'error' => '请先输入密码'];
    }

    $verifiedTime = $_SESSION['password_verified_time'] ?? 0;
    if (time() - $verifiedTime > 300) {
        unset($_SESSION['password_verified'], $_SESSION['password_verified_time']);
        return ['success' => false, 'error' => '验证超时，请重新输入密码'];
    }

    if (!verify_2fa_code($code)) {
        return ['success' => false, 'error' => '验证码错误，请重试'];
    }

    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    unset($_SESSION['password_verified'], $_SESSION['password_verified_time']);
    return ['success' => true];
}

/**
 * 退出登录
 */
function logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();
}

/**
 * 检查是否已登录
 */
function is_logged_in(): bool
{
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        return false;
    }

    if (SESSION_LIFETIME > 0 && isset($_SESSION['login_time'])) {
        if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
            logout();
            return false;
        }
    }

    return true;
}

/**
 * 检查密码是否已验证（等待 2FA）
 */
function is_password_verified(): bool
{
    if (empty($_SESSION['password_verified'])) return false;
    $verifiedTime = $_SESSION['password_verified_time'] ?? 0;
    return (time() - $verifiedTime) <= 300;
}

/**
 * 要求登录
 */
function require_auth(): void
{
    if (!is_logged_in()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized', 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
