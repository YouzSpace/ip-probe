<?php
/**
 * IP 获取与归属地查询模块
 * 
 * 提供客户端 IP 获取、公网 IP 查询、IP 归属地查询、代理检测等功能。
 * 依赖：config.php
 */

/**
 * 获取客户端真实 IP
 * 
 * 优先级：CF-Connecting-IP → True-Client-IP → X-Forwarded-For → X-Real-IP → REMOTE_ADDR
 * 从 X-Forwarded-For 取第一个 IP（最原始客户端）。
 * 支持 Cloudflare / Akamai / 通用反向代理。
 * 
 * @return string 客户端 IP 地址
 */
function get_client_ip(): string
{
    // Cloudflare CDN：最可靠，不会被伪造
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if ($ip !== '' && strtolower($ip) !== 'unknown') {
            return $ip;
        }
    }

    // Akamai / Cloudflare Enterprise
    if (!empty($_SERVER['HTTP_TRUE_CLIENT_IP'])) {
        $ip = trim($_SERVER['HTTP_TRUE_CLIENT_IP']);
        if ($ip !== '' && strtolower($ip) !== 'unknown') {
            return $ip;
        }
    }

    // 通用反向代理（Nginx/Caddy/Apache）：取逗号分隔列表的第一个
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($ips as $raw) {
            $ip = trim($raw);
            if ($ip !== '' && strtolower($ip) !== 'unknown') {
                return $ip;
            }
        }
    }

    // Nginx proxy_pass 常用
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        if ($ip !== '' && strtolower($ip) !== 'unknown') {
            return $ip;
        }
    }

    // 兜底：直连 IP
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * 通过 ipify.org 获取公网 IP
 * 
 * 使用 stream_context 设置 5 秒超时，支持 IPv4 和 IPv6。
 * 
 * @return array ['ipv4' => string, 'ipv6' => string] 或包含 error 字段
 */
function get_public_ip(): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'method' => 'GET',
        ],
    ]);

    $response = @file_get_contents(IP_API_URL, false, $context);

    if ($response === false) {
        return [
            'ipv4' => '',
            'ipv6' => '',
            'error' => '公网 IP 获取失败',
        ];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($data['ip'])) {
        return [
            'ipv4' => '',
            'ipv6' => '',
            'error' => '公网 IP 解析失败',
        ];
    }

    $ip = $data['ip'];

    // 区分 IPv4 和 IPv6
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return ['ipv4' => $ip, 'ipv6' => ''];
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return ['ipv4' => '', 'ipv6' => $ip];
    }

    return ['ipv4' => '', 'ipv6' => '', 'error' => 'IP 格式无法识别'];
}

/**
 * 空结果模板
 */
function empty_location(): array
{
    return [
        'country'  => '未知',
        'province' => '',
        'city'     => '',
        'district' => '',
        'isp'      => '',
        'hosting'  => false,
        'source'   => '',
    ];
}

/**
 * 判定归属地结果是否有效（至少有国家信息）
 */
function is_valid_location(array $loc): bool
{
    return !empty($loc['country']) && $loc['country'] !== '未知';
}

/**
 * 通用 HTTP GET 请求（返回解码后的 JSON 数组）
 */
function http_get_json(string $url, int $timeout = 5): ?array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'method'  => 'GET',
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) return null;

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) return null;

    return $data;
}

/**
 * 通过 ip-api.com 查询 IP 归属地
 */
function query_ip_api_com(string $ip): array
{
    $url  = 'http://ip-api.com/json/' . $ip . '?lang=zh-CN&fields=country,regionName,city,isp,hosting';
    $data = http_get_json($url);
    if ($data === null || ($data['status'] ?? '') === 'fail') return empty_location();

    return [
        'country'  => $data['country'] ?? '未知',
        'province' => $data['regionName'] ?? '',
        'city'     => $data['city'] ?? '',
        'district' => '',
        'isp'      => $data['isp'] ?? '',
        'hosting'  => !empty($data['hosting']),
        'source'   => 'ip-api.com',
    ];
}

/**
 * 通过 ipapi.co 查询 IP 归属地（备用源，免费额度 1000/天）
 */
function query_ipapi_co(string $ip): array
{
    $url  = 'https://ipapi.co/' . $ip . '/json/';
    $data = http_get_json($url);
    if ($data === null || !empty($data['error'])) return empty_location();

    return [
        'country'  => $data['country_name'] ?? '未知',
        'province' => $data['region'] ?? '',
        'city'     => $data['city'] ?? '',
        'district' => '',
        'isp'      => $data['org'] ?? '',
        'hosting'  => false,
        'source'   => 'ipapi.co',
    ];
}

/**
 * 通过 ip9.com.cn 查询 IP 归属地（免费免 Key，支持区县级 + IPv6 精准定位）
 * 文档：https://ip9.com.cn/help.html
 */
function query_ip9(string $ip): array
{
    $url  = 'https://ip9.com.cn/get?ip=' . urlencode($ip);
    $data = http_get_json($url, 6);
    if ($data === null || ($data['ret'] ?? 0) !== 200) return empty_location();

    $d = $data['data'] ?? [];
    if (empty($d['country']) || $d['country'] === '未知') return empty_location();

    return [
        'country'  => $d['country']  ?? '未知',
        'province' => $d['prov']     ?? '',
        'city'     => $d['city']     ?? '',
        'district' => $d['area']     ?? '',
        'isp'      => $d['isp']      ?? '',
        'hosting'  => false,
        'source'   => 'ip9.com.cn',
    ];
}

/**
 * 多源 IP 归属地查询（按优先级回退）
 * 
 * 查询链：ip9.com.cn → ip-api.com → ipapi.co → 未知
 * ip9.com.cn 免费免 Key，支持区县级 + IPv6 精准定位。
 * 
 * @param string $ip IP 地址
 * @return array 包含 country/province/city/district/isp/hosting/source 字段
 */
function get_ip_location(string $ip): array
{
    if (empty($ip)) {
        return empty_location();
    }

    // 最高优先：ip9.com.cn（免费免 Key、区县级、IPv6 精准）
    $location = query_ip9($ip);
    if (is_valid_location($location)) {
        return $location;
    }

    // 第二优先：ip-api.com（免费、速度快、无日限额）
    $location = query_ip_api_com($ip);
    if (is_valid_location($location)) {
        return $location;
    }

    // 第三优先：ipapi.co（免费额度 1000/天）
    $location = query_ipapi_co($ip);
    if (is_valid_location($location)) {
        return $location;
    }

    return empty_location();
}

/**
 * 多维度检测是否使用了 VPN/代理
 * 
 * 检测维度：
 * 1. HTTP 代理头（Via、X-Forwarded-For、Forwarded）
 * 2. ip-api.com 返回的 hosting 字段（数据中心 IP）
 * 
 * @param array  $server   $_SERVER 超全局变量
 * @param string $ip       客户端 IP
 * @param array  $location IP 归属地信息
 * @return array ['is_proxy' => bool, 'detected_by' => ?string]
 */
function detect_proxy(array $server, string $ip, array $location): array
{
    $detectedBy = [];

    // 检测维度 1：HTTP 代理头
    if (!empty($server['HTTP_VIA'])) {
        $detectedBy[] = 'HTTP_VIA';
    }

    if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
        $detectedBy[] = 'X-Forwarded-For';
    }

    if (!empty($server['HTTP_FORWARDED'])) {
        $detectedBy[] = 'Forwarded';
    }

    // 检测维度 2：数据中心 IP（hosting 字段）
    if (!empty($location['hosting']) && $location['hosting'] === true) {
        $detectedBy[] = 'datacenter_ip';
    }

    $isProxy = !empty($detectedBy);

    return [
        'is_proxy' => $isProxy,
        'detected_by' => $isProxy ? implode(', ', $detectedBy) : null,
    ];
}
