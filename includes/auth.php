<?php
/**
 * 认证模块
 * 
 * 提供 Session 管理、密码验证、登录状态检查等功能。
 * 密码哈希存储在 data/.password_hash 文件中，首次运行自动生成。
 * 依赖：config.php
 */

/**
 * 初始化 Session
 * 
 * 设置安全参数并启动会话。应在所有需要 Session 的页面调用。
 */
function init_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // 设置 Session 安全参数
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');

        // 设置 Session 过期时间
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

        session_start();
    }
}

/**
 * 获取密码哈希值
 * 
 * 首次运行时自动用 DEFAULT_PASSWORD 生成哈希并存储到文件。
 * 
 * @return string bcrypt 哈希值
 */
function get_password_hash(): string
{
    $hashFile = DATA_DIR . '/.password_hash';

    if (!file_exists($hashFile)) {
        // 首次运行：生成默认密码哈希并保存
        $hash = password_hash(DEFAULT_PASSWORD, PASSWORD_DEFAULT);
        file_put_contents($hashFile, $hash);
        return $hash;
    }

    return trim(file_get_contents($hashFile));
}

/**
 * 验证密码并登录
 * 
 * @param string $password 用户输入的密码
 * @return bool 验证成功返回 true
 */
function login(string $password): bool
{
    $hash = get_password_hash();

    if (password_verify($password, $hash)) {
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        return true;
    }

    return false;
}

/**
 * 退出登录
 * 
 * 清空 Session 数据并销毁会话。
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
 * 
 * 同时检查 Session 中的登录状态和过期时间。
 * 
 * @return bool 已登录且未过期返回 true
 */
function is_logged_in(): bool
{
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        return false;
    }

    // 检查 Session 是否过期
    if (!isset($_SESSION['login_time'])) {
        return false;
    }

    if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
        // Session 已过期，自动登出
        logout();
        return false;
    }

    return true;
}

/**
 * 要求登录，未登录时返回 JSON 错误并终止执行
 * 
 * 用于 API 接口的身份验证守卫。
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
