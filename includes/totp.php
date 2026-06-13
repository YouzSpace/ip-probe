<?php
/**
 * TOTP (Time-based One-Time Password) 纯 PHP 实现
 * 
 * 基于 RFC 6238，兼容 Google Authenticator / Authy 等
 * 无需第三方扩展，仅依赖 PHP 内置 hash_hmac
 */

/**
 * 生成 Base32 编码的随机密钥
 * 
 * @param int $length 密钥长度（字节），默认 20（160 位）
 * @return string Base32 编码的密钥（32 字符）
 */
function totp_generate_secret(int $length = 20): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $random = random_bytes($length);

    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[ord($random[$i]) % 32];
    }

    return $secret;
}

/**
 * Base32 解码
 * 
 * @param string $data Base32 编码的字符串
 * @return string 解码后的二进制数据
 */
function totp_base32_decode(string $data): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $data = strtoupper($data);
    $data = rtrim($data, '=');

    $result = '';
    $buffer = 0;
    $bitsLeft = 0;

    for ($i = 0; $i < strlen($data); $i++) {
        $val = strpos($chars, $data[$i]);
        if ($val === false) continue;

        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;

        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $result .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }

    return $result;
}

/**
 * 生成 TOTP 验证码
 * 
 * @param string $secret Base32 编码的密钥
 * @param int    $time    时间戳（默认当前时间）
 * @param int    $period  有效期秒数（默认 30）
 * @param int    $digits  验证码位数（默认 6）
 * @param string $algo    哈希算法（默认 sha1）
 * @return string 数字验证码
 */
function totp_generate(string $secret, int $time = 0, int $period = 30, int $digits = 6, string $algo = 'sha1'): string
{
    if ($time === 0) $time = time();

    $counter = intdiv($time, $period);
    $counterBytes = pack('N*', 0, $counter);

    $secretBytes = totp_base32_decode($secret);
    $hash = hash_hmac($algo, $counterBytes, $secretBytes, true);

    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $code = ((ord($hash[$offset]) & 0x7F) << 24)
          | ((ord($hash[$offset + 1]) & 0xFF) << 16)
          | ((ord($hash[$offset + 2]) & 0xFF) << 8)
          | (ord($hash[$offset + 3]) & 0xFF);

    $code = $code % (10 ** $digits);

    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/**
 * 验证 TOTP 验证码
 * 
 * 允许前后各 1 个时间窗口的偏移（±30 秒），防止时钟微小偏差
 * 
 * @param string $secret  Base32 编码的密钥
 * @param string $code    用户输入的验证码
 * @param int    $period  有效期秒数（默认 30）
 * @param int    $digits  验证码位数（默认 6）
 * @param int    $window  允许的时间窗口偏移（默认 1）
 * @return bool 验证是否通过
 */
function totp_verify(string $secret, string $code, int $period = 30, int $digits = 6, int $window = 1): bool
{
    $time = time();

    for ($i = -$window; $i <= $window; $i++) {
        $checkTime = $time + ($i * $period);
        $expected = totp_generate($secret, $checkTime, $period, $digits);

        if (hash_equals($expected, $code)) {
            return true;
        }
    }

    return false;
}

/**
 * 生成 Google Authenticator 的 otpauth URI
 * 
 * @param string $secret    Base32 编码的密钥
 * @param string $account   账户名（如 admin@ip-probe）
 * @param string $issuer    发行者名称（如 IP-Probe）
 * @return string otpauth:// URI
 */
function totp_get_uri(string $secret, string $account = 'admin', string $issuer = 'IP-Probe'): string
{
    return sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        rawurlencode($issuer),
        rawurlencode($account),
        $secret,
        rawurlencode($issuer)
    );
}

/**
 * 生成 Google Charts QR Code 图片 URL
 * 
 * @param string $uri otpauth URI
 * @return string QR Code 图片 URL
 */
function totp_get_qr_url(string $uri): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($uri);
}
