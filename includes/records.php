<?php
/**
 * 采集记录管理模块
 * 
 * 提供采集记录的保存、查询、删除和统计功能。
 * 依赖：config.php, includes/storage.php, includes/ip.php, includes/links.php
 */

/**
 * 保存一条采集记录
 * 
 * 生成唯一 ID，合并 PHP 端注入字段（server_ip、created_at）和客户端传入数据。
 * 保存成功后自动增加对应链接的采集计数。
 * 
 * @param array $client_data 客户端传入的采集数据
 * @return array ['success' => bool, 'id' => string]
 */
function save_record(array $client_data): array
{
    // 生成唯一记录 ID
    $id = 'rec_' . uniqid();

    // 构建完整记录 — PHP 端注入字段 + 客户端传入字段
    $record = [
        // PHP 端注入
        'id' => $id,
        'server_ip' => get_client_ip(),
        'created_at' => date('Y-m-d H:i:s'),

        // 客户端传入字段
        'link_id' => $client_data['link_id'] ?? '',
        'ipv4' => $client_data['ipv4'] ?? '',
        'ipv6' => $client_data['ipv6'] ?? '',
        'webrtc_ips' => $client_data['webrtc_ips'] ?? [],
        'location' => $client_data['location'] ?? [],
        'user_agent_raw' => $client_data['user_agent_raw'] ?? '',
        'os' => $client_data['os'] ?? '',
        'browser' => $client_data['browser'] ?? '',
        'language' => $client_data['language'] ?? '',
        'screen' => $client_data['screen'] ?? '',
        'dpr' => $client_data['dpr'] ?? 1,
        'battery_level' => $client_data['battery_level'] ?? null,
        'battery_charging' => $client_data['battery_charging'] ?? null,
        'network_type' => $client_data['network_type'] ?? null,
        'connection_downlink' => $client_data['connection_downlink'] ?? null,
        'is_proxy' => $client_data['is_proxy'] ?? false,
        'proxy_detected_by' => $client_data['proxy_detected_by'] ?? null,
    ];

    $success = append_record(RECORDS_FILE, 'records', $record);

    if ($success) {
        // 增加链接的采集计数
        if (!empty($client_data['link_id'])) {
            increment_link_count($client_data['link_id']);
        }
    }

    return [
        'success' => $success,
        'id' => $id,
    ];
}

/**
 * 获取记录列表（分页 + 搜索）
 * 
 * 支持按 ipv4/ipv6/user_agent_raw/location.city 模糊搜索。
 * 
 * @param int    $page   页码（从 1 开始）
 * @param int    $limit  每页条数
 * @param string $search 搜索关键词（可选）
 * @return array ['records' => [...], 'total' => int]
 */
function get_records(int $page = 1, int $limit = 20, string $search = ''): array
{
    $data = read_json(RECORDS_FILE);
    $records = $data['records'] ?? [];

    // 按 created_at 倒序排列
    usort($records, function ($a, $b) {
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });

    // 搜索过滤
    if (!empty($search)) {
        $searchLower = mb_strtolower($search, 'UTF-8');
        $records = array_values(array_filter($records, function ($record) use ($searchLower) {
            $fields = [
                mb_strtolower($record['ipv4'] ?? '', 'UTF-8'),
                mb_strtolower($record['ipv6'] ?? '', 'UTF-8'),
                mb_strtolower($record['user_agent_raw'] ?? '', 'UTF-8'),
                mb_strtolower($record['location']['city'] ?? '', 'UTF-8'),
            ];
            foreach ($fields as $field) {
                if (stripos($field, $search) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    $total = count($records);

    // 分页切片
    $offset = ($page - 1) * $limit;
    $records = array_slice($records, $offset, $limit);

    // 列表只返回摘要字段
    $summaries = [];
    foreach ($records as $r) {
        $summaries[] = [
            'id' => $r['id'] ?? '',
            'ipv4' => $r['ipv4'] ?? '',
            'ipv6' => $r['ipv6'] ?? '',
            'webrtc_ips' => !empty($r['webrtc_ips']) ? $r['webrtc_ips'][0] : '',
            'os' => $r['os'] ?? '',
            'browser' => $r['browser'] ?? '',
            'location' => $r['location'] ?? [],
            'created_at' => $r['created_at'] ?? '',
        ];
    }

    return [
        'records' => $summaries,
        'total' => $total,
    ];
}

/**
 * 获取单条记录详情
 * 
 * @param string $id 记录 ID
 * @return array|null 完整记录或 null
 */
function get_record(string $id): ?array
{
    $data = read_json(RECORDS_FILE);
    $records = $data['records'] ?? [];

    foreach ($records as $record) {
        if (($record['id'] ?? '') === $id) {
            return $record;
        }
    }

    return null;
}

/**
 * 批量删除采集记录
 * 
 * @param array $ids 要删除的记录 ID 列表
 * @return int 实际删除的条数
 */
function delete_records(array $ids): int
{
    return delete_records_by_ids(RECORDS_FILE, 'records', $ids);
}

/**
 * 获取统计数据（仪表盘用）
 * 
 * @return array 包含 total/today/week_trend/os_dist/browser_dist/city_dist
 */
function get_stats(): array
{
    $data = read_json(RECORDS_FILE);
    $records = $data['records'] ?? [];

    $total = count($records);
    $today = date('Y-m-d');

    // 今日记录数
    $todayCount = 0;
    // 近 7 天趋势
    $weekTrend = [];
    // 操作系统分布
    $osDist = [];
    // 浏览器分布
    $browserDist = [];
    // 城市分布
    $cityDist = [];

    // 初始化近 7 天数据结构
    for ($i = 6; $i >= 0; $i--) {
        $date = date('m-d', strtotime("-{$i} days"));
        $weekTrend[$date] = 0;
    }

    foreach ($records as $record) {
        $createdAt = $record['created_at'] ?? '';
        if (empty($createdAt)) {
            continue;
        }

        // 今日计数
        $recordDate = substr($createdAt, 0, 10);
        if ($recordDate === $today) {
            $todayCount++;
        }

        // 近 7 天趋势
        $recordShort = date('m-d', strtotime($recordDate));
        if (isset($weekTrend[$recordShort])) {
            $weekTrend[$recordShort]++;
        }

        // OS 分布
        $os = $record['os'] ?? '未知';
        if (!empty($os)) {
            $osDist[$os] = ($osDist[$os] ?? 0) + 1;
        }

        // 浏览器分布
        $browser = $record['browser'] ?? '未知';
        if (!empty($browser)) {
            $browserDist[$browser] = ($browserDist[$browser] ?? 0) + 1;
        }

        // 城市分布
        $city = $record['location']['city'] ?? '';
        if (!empty($city)) {
            $cityDist[$city] = ($cityDist[$city] ?? 0) + 1;
        }
    }

    // 周趋势格式化为数组
    $weekTrendArr = [];
    foreach ($weekTrend as $date => $count) {
        $weekTrendArr[] = [
            'date' => $date,
            'count' => $count,
        ];
    }

    // 城市分布取 Top 10
    arsort($cityDist);
    $cityDist = array_slice($cityDist, 0, 10, true);

    return [
        'total' => $total,
        'today' => $todayCount,
        'week_trend' => $weekTrendArr,
        'os_dist' => $osDist,
        'browser_dist' => $browserDist,
        'city_dist' => $cityDist,
    ];
}
