<?php
/**
 * 认证模块 (SQLite)
 * 
 * 密码哈希、2FA密钥、登录锁定 全部存 settings 表
 * 依赖：config.php, includes/database.php, includes/totp.php
 */

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
 * 获取密码哈希（首次使用自动生成默认密码）
 */
function get_password_hash(): string
{
    $hash = get_setting('password_hash');
    if (empty($hash)) {
        $hash = password_hash(DEFAULT_PASSWORD, PASSWORD_DEFAULT);
        set_setting('password_hash', $hash);
    }
    return $hash;
}

// ====== 暴力破解防护 ======

function get_login_failures(): int
{
    $data = get_setting('login_failures', '{}');
    $attempts = json_decode($data, true) ?: [];
    $now = time();
    $count = 0;
    foreach ($attempts as $t) {
        if ($now - $t < LOGIN_LOCKOUT_SECONDS) $count++;
    }
    return $count;
}

function record_login_failure(): void
{
    $data = get_setting('login_failures', '{}');
    $attempts = json_decode($data, true) ?: [];
    $now = time();
    // 清理过期
    $attempts = array_filter($attempts, function ($t) use ($now) {
        return $now - $t < LOGIN_LOCKOUT_SECONDS;
    });
    $attempts[] = $now;
    set_setting('login_failures', json_encode(array_values($attempts)));
}

function is_login_locked(): bool
{
    return get_login_failures() >= LOGIN_MAX_ATTEMPTS;
}

function clear_login_failures(): void
{
    set_setting('login_failures', '[]');
}

// ====== 2FA 管理 ======

function is_2fa_enabled(): bool
{
    $secret = get_setting('twofa_secret');
    return !empty($secret) && strlen($secret) >= 16;
}

function get_2fa_secret(): ?string
{
    $secret = get_setting('twofa_secret');
    return !empty($secret) ? $secret : null;
}

function set_2fa_secret(string $secret): bool
{
    return set_setting('twofa_secret', $secret);
}

function remove_2fa_secret(): bool
{
    return delete_setting('twofa_secret');
}

function verify_2fa_code(string $code): bool
{
    $secret = get_2fa_secret();
    if ($secret === null) return false;
    return totp_verify($secret, $code);
}

// ====== 登录流程 ======

function login(string $password): array
{
    if (is_login_locked()) {
        return ['success' => false, 'need_2fa' => false, 'error' => '登录失败次数过多，请 5 分钟后再试'];
    }

    $hash = get_password_hash();

    if (!password_verify($password, $hash)) {
        record_login_failure();
        return ['success' => false, 'need_2fa' => false, 'error' => '密码错误'];
    }

    clear_login_failures();

    if (is_2fa_enabled()) {
        $_SESSION['password_verified'] = true;
        $_SESSION['password_verified_time'] = time();
        return ['success' => true, 'need_2fa' => true];
    }

    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    unset($_SESSION['password_verified']);
    return ['success' => true, 'need_2fa' => false];
}

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

function is_password_verified(): bool
{
    if (empty($_SESSION['password_verified'])) return false;
    $verifiedTime = $_SESSION['password_verified_time'] ?? 0;
    return (time() - $verifiedTime) <= 300;
}

function require_auth(): void
{
    if (!is_logged_in()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized', 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
