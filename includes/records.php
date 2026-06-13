<?php
/**
 * 采集记录管理模块 (SQLite)
 * 
 * 依赖：config.php, includes/database.php, includes/ip.php
 */

/**
 * 保存一条采集记录
 */
function save_record(array $client_data): array
{
    $db = get_db();
    $id = 'rec_' . bin2hex(random_bytes(6));

    $stmt = $db->prepare('INSERT INTO records 
        (id, server_ip, link_id, ipv4, ipv6, webrtc_ips, location, user_agent_raw, os, browser, language, screen, dpr, battery_level, battery_charging, network_type, connection_downlink, is_proxy, proxy_detected_by, created_at)
        VALUES (:id, :server_ip, :link_id, :ipv4, :ipv6, :webrtc_ips, :location, :user_agent_raw, :os, :browser, :language, :screen, :dpr, :battery_level, :battery_charging, :network_type, :connection_downlink, :is_proxy, :proxy_detected_by, :created_at)');

    $stmt->bindValue(':id', $id);
    $stmt->bindValue(':server_ip', get_client_ip());
    $stmt->bindValue(':created_at', date('Y-m-d H:i:s'));
    $stmt->bindValue(':link_id', $client_data['link_id'] ?? '');
    $stmt->bindValue(':ipv4', $client_data['ipv4'] ?? '');
    $stmt->bindValue(':ipv6', $client_data['ipv6'] ?? '');
    $stmt->bindValue(':webrtc_ips', json_encode($client_data['webrtc_ips'] ?? []));
    $stmt->bindValue(':location', json_encode($client_data['location'] ?? []));
    $stmt->bindValue(':user_agent_raw', $client_data['user_agent_raw'] ?? '');
    $stmt->bindValue(':os', $client_data['os'] ?? '');
    $stmt->bindValue(':browser', $client_data['browser'] ?? '');
    $stmt->bindValue(':language', $client_data['language'] ?? '');
    $stmt->bindValue(':screen', $client_data['screen'] ?? '');
    $stmt->bindValue(':dpr', $client_data['dpr'] ?? 1, SQLITE3_FLOAT);
    $stmt->bindValue(':battery_level', $client_data['battery_level'] ?? null, SQLITE3_INTEGER);
    $stmt->bindValue(':battery_charging', $client_data['battery_charging'] ?? null, SQLITE3_INTEGER);
    $stmt->bindValue(':network_type', $client_data['network_type'] ?? null);
    $stmt->bindValue(':connection_downlink', $client_data['connection_downlink'] ?? null, SQLITE3_FLOAT);
    $stmt->bindValue(':is_proxy', ($client_data['is_proxy'] ?? false) ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':proxy_detected_by', $client_data['proxy_detected_by'] ?? null);

    $success = $stmt->execute() !== false;

    if ($success && !empty($client_data['link_id'])) {
        increment_link_count($client_data['link_id']);
    }

    return ['success' => $success, 'id' => $id];
}

/**
 * 获取记录列表（分页 + 搜索）
 */
function get_records(int $page = 1, int $limit = 20, string $search = ''): array
{
    $db = get_db();

    $where = '';
    $params = [];

    if (!empty($search)) {
        $where = "WHERE ipv4 LIKE :s OR ipv6 LIKE :s OR user_agent_raw LIKE :s OR os LIKE :s OR browser LIKE :s OR json_extract(location,'$.city') LIKE :s";
        $params[':s'] = '%' . $search . '%';
    }

    // 总数
    $countSql = "SELECT COUNT(*) FROM records $where";
    $stmt = $db->prepare($countSql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $total = $stmt->execute()->fetchArray(SQLITE3_NUM)[0];

    // 分页数据
    $offset = ($page - 1) * $limit;
    $sql = "SELECT id, ipv4, ipv6, os, browser, location, created_at FROM records $where ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $records = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['location'] = json_decode($row['location'] ?? '{}', true) ?: [];
        $records[] = $row;
    }

    return ['records' => $records, 'total' => $total];
}

/**
 * 获取单条记录详情
 */
function get_record(string $id): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM records WHERE id = :id');
    $stmt->bindValue(':id', $id);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if (!$row) return null;

    $row['location'] = json_decode($row['location'] ?? '{}', true) ?: [];
    $row['webrtc_ips'] = json_decode($row['webrtc_ips'] ?? '[]', true) ?: [];
    $row['is_proxy'] = (bool)$row['is_proxy'];

    return $row;
}

/**
 * 批量删除记录
 */
function delete_records(array $ids): int
{
    if (empty($ids)) return 0;
    $db = get_db();

    $placeholders = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $placeholders[] = ':id' . $i;
        $params[':id' . $i] = $id;
    }

    $sql = 'DELETE FROM records WHERE id IN (' . implode(',', $placeholders) . ')';
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();

    return $db->changes();
}

/**
 * 获取统计数据
 */
function get_stats(): array
{
    $db = get_db();

    $total = $db->querySingle("SELECT COUNT(*) FROM records");
    $today = date('Y-m-d');
    $todayCount = $db->querySingle("SELECT COUNT(*) FROM records WHERE created_at >= '$today'");
    $linksCount = $db->querySingle("SELECT COUNT(*) FROM links");

    $weekStart = date('Y-m-d', strtotime('-6 days'));
    $weekTotal = $db->querySingle("SELECT COUNT(*) FROM records WHERE created_at >= '$weekStart'");

    // 近 7 天趋势
    $weekTrend = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $short = date('m-d', strtotime($date));
        $count = $db->querySingle("SELECT COUNT(*) FROM records WHERE created_at >= '$date' AND created_at < '" . date('Y-m-d', strtotime("$date +1 day")) . "'");
        $weekTrend[] = ['date' => $short, 'count' => (int)$count];
    }

    // OS 分布
    $osDist = [];
    $result = $db->query("SELECT os, COUNT(*) as c FROM records WHERE os != '' AND os != '未知' GROUP BY os ORDER BY c DESC LIMIT 10");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $osDist[$row['os']] = (int)$row['c'];
    }

    // 浏览器分布
    $browserDist = [];
    $result = $db->query("SELECT browser, COUNT(*) as c FROM records WHERE browser != '' AND browser != '未知' GROUP BY browser ORDER BY c DESC LIMIT 10");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $browserDist[$row['browser']] = (int)$row['c'];
    }

    // 城市分布
    $cityDist = [];
    $result = $db->query("SELECT json_extract(location,'$.city') as city, COUNT(*) as c FROM records WHERE city != '' AND city IS NOT NULL GROUP BY city ORDER BY c DESC LIMIT 10");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $cityDist[$row['city']] = (int)$row['c'];
    }

    return [
        'total' => (int)$total,
        'today' => (int)$todayCount,
        'links_count' => (int)$linksCount,
        'week_total' => (int)$weekTotal,
        'week_trend' => $weekTrend,
        'os_dist' => $osDist,
        'browser_dist' => $browserDist,
        'city_dist' => $cityDist,
    ];
}

/**
 * 检查 IP 频率限制
 */
function is_rate_limited(string $table, string $unused, string $ip, int $seconds): bool
{
    $db = get_db();
    $since = date('Y-m-d H:i:s', time() - $seconds);
    $stmt = $db->prepare("SELECT COUNT(*) FROM $table WHERE server_ip = :ip AND created_at >= :since");
    $stmt->bindValue(':ip', $ip);
    $stmt->bindValue(':since', $since);
    $count = $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
    return $count > 0;
}
