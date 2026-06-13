<?php
/**
 * 签到管理模块 (SQLite)
 */

function create_checkin_link(string $remark = ''): array
{
    $db = get_db();
    $id = 'ck_' . bin2hex(random_bytes(4));

    $stmt = $db->prepare('INSERT INTO checkin_links (id, remark) VALUES (:id, :remark)');
    $stmt->bindValue(':id', $id);
    $stmt->bindValue(':remark', $remark);

    if ($stmt->execute() === false) {
        return ['success' => false, 'error' => '签到链接创建失败'];
    }

    $fullUrl = SITE_URL . '/checkin.php?id=' . $id;

    return [
        'success' => true,
        'checkin' => [
            'id' => $id,
            'url' => $fullUrl,
            'remark' => $remark,
            'checkin_count' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ];
}

function get_checkin_links(): array
{
    $db = get_db();
    $result = $db->query('SELECT * FROM checkin_links ORDER BY created_at DESC');

    $checkins = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['url'] = SITE_URL . '/checkin.php?id=' . $row['id'];
        $checkins[] = $row;
    }

    return ['checkins' => $checkins, 'total' => count($checkins)];
}

function get_checkin_link(string $id): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM checkin_links WHERE id = :id');
    $stmt->bindValue(':id', $id);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function delete_checkin_link(string $id): bool
{
    $db = get_db();

    // 删除关联签到记录的照片文件
    $stmt = $db->prepare('SELECT photo FROM checkin_records WHERE checkin_id = :id AND photo IS NOT NULL');
    $stmt->bindValue(':id', $id);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $filepath = PHOTOS_DIR . '/' . basename($row['photo']);
        if (file_exists($filepath)) @unlink($filepath);
    }

    // 删除关联签到记录
    $stmt = $db->prepare('DELETE FROM checkin_records WHERE checkin_id = :id');
    $stmt->bindValue(':id', $id);
    $stmt->execute();

    // 删除链接
    $stmt = $db->prepare('DELETE FROM checkin_links WHERE id = :id');
    $stmt->bindValue(':id', $id);
    $stmt->execute();

    return $db->changes() > 0;
}

function increment_checkin_count(string $id): void
{
    if (empty($id)) return;
    $db = get_db();
    $stmt = $db->prepare('UPDATE checkin_links SET checkin_count = checkin_count + 1 WHERE id = :id');
    $stmt->bindValue(':id', $id);
    $stmt->execute();
}

function remove_checkin_link(string $id): bool
{
    $db = get_db();
    $stmt = $db->prepare('DELETE FROM checkin_links WHERE id = :id');
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    return $db->changes() > 0;
}

/**
 * 保存签到记录
 */
function save_checkin_record(array $client_data, ?string $photo_filename = null): array
{
    $db = get_db();
    $id = 'ci_' . bin2hex(random_bytes(6));

    $gps = $client_data['gps'] ?? null;

    $stmt = $db->prepare('INSERT INTO checkin_records 
        (id, server_ip, checkin_id, ipv4, ipv6, location, user_agent_raw, os, browser, language, screen, dpr, is_proxy, proxy_detected_by, gps, photo, photo_url, created_at)
        VALUES (:id, :server_ip, :checkin_id, :ipv4, :ipv6, :location, :user_agent_raw, :os, :browser, :language, :screen, :dpr, :is_proxy, :proxy_detected_by, :gps, :photo, :photo_url, :created_at)');

    $stmt->bindValue(':id', $id);
    $stmt->bindValue(':server_ip', get_client_ip());
    $stmt->bindValue(':created_at', date('Y-m-d H:i:s'));
    $stmt->bindValue(':checkin_id', $client_data['checkin_id'] ?? '');
    $stmt->bindValue(':ipv4', $client_data['ipv4'] ?? '');
    $stmt->bindValue(':ipv6', $client_data['ipv6'] ?? '');
    $stmt->bindValue(':location', json_encode($client_data['location'] ?? []));
    $stmt->bindValue(':user_agent_raw', $client_data['user_agent_raw'] ?? '');
    $stmt->bindValue(':os', $client_data['os'] ?? '');
    $stmt->bindValue(':browser', $client_data['browser'] ?? '');
    $stmt->bindValue(':language', $client_data['language'] ?? '');
    $stmt->bindValue(':screen', $client_data['screen'] ?? '');
    $stmt->bindValue(':dpr', $client_data['dpr'] ?? 1, SQLITE3_FLOAT);
    $stmt->bindValue(':is_proxy', ($client_data['is_proxy'] ?? false) ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':proxy_detected_by', $client_data['proxy_detected_by'] ?? null);
    $stmt->bindValue(':gps', $gps ? json_encode($gps) : null);
    $stmt->bindValue(':photo', $photo_filename);
    $stmt->bindValue(':photo_url', $photo_filename ? '/api.php?action=checkin_photo&file=' . $photo_filename : null);

    $success = $stmt->execute() !== false;

    if ($success && !empty($client_data['checkin_id'])) {
        increment_checkin_count($client_data['checkin_id']);
        remove_checkin_link($client_data['checkin_id']);
    }

    $record = [
        'id' => $id,
        'server_ip' => get_client_ip(),
        'created_at' => date('Y-m-d H:i:s'),
        'checkin_id' => $client_data['checkin_id'] ?? '',
        'ipv4' => $client_data['ipv4'] ?? '',
        'ipv6' => $client_data['ipv6'] ?? '',
        'location' => $client_data['location'] ?? [],
        'os' => $client_data['os'] ?? '',
        'browser' => $client_data['browser'] ?? '',
        'gps' => $gps,
        'photo_url' => $photo_filename ? '/api.php?action=checkin_photo&file=' . $photo_filename : null,
    ];

    return ['success' => $success, 'id' => $id, 'record' => $record];
}

/**
 * 获取签到记录列表
 */
function get_checkin_records(int $page = 1, int $limit = 20, string $search = '', string $checkin_id = ''): array
{
    $db = get_db();

    $where = [];
    $params = [];

    if (!empty($checkin_id)) {
        $where[] = 'checkin_id = :cid';
        $params[':cid'] = $checkin_id;
    }

    if (!empty($search)) {
        $where[] = '(ipv4 LIKE :s OR ipv6 LIKE :s OR user_agent_raw LIKE :s OR json_extract(location,"$.city") LIKE :s)';
        $params[':s'] = '%' . $search . '%';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $db->prepare("SELECT COUNT(*) FROM checkin_records $whereSql");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $total = $stmt->execute()->fetchArray(SQLITE3_NUM)[0];

    $offset = ($page - 1) * $limit;
    $stmt = $db->prepare("SELECT id, ipv4, ipv6, os, browser, location, checkin_id, created_at FROM checkin_records $whereSql ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $records = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['location'] = json_decode($row['location'] ?? '{}', true) ?: [];
        $records[] = $row;
    }

    return ['records' => $records, 'total' => (int)$total];
}

function get_checkin_record(string $id): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM checkin_records WHERE id = :id');
    $stmt->bindValue(':id', $id);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    if (!$row) return null;

    $row['location'] = json_decode($row['location'] ?? '{}', true) ?: [];
    $row['gps'] = json_decode($row['gps'] ?? 'null', true);
    $row['is_proxy'] = (bool)$row['is_proxy'];

    return $row;
}

function delete_checkin_records(array $ids): int
{
    if (empty($ids)) return 0;
    $db = get_db();

    // 先删照片
    $placeholders = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $placeholders[] = ':id' . $i;
        $params[':id' . $i] = $id;
    }
    $inClause = implode(',', $placeholders);

    $stmt = $db->prepare("SELECT photo FROM checkin_records WHERE id IN ($inClause) AND photo IS NOT NULL");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $filepath = PHOTOS_DIR . '/' . basename($row['photo']);
        if (file_exists($filepath)) @unlink($filepath);
    }

    // 删记录
    $stmt = $db->prepare("DELETE FROM checkin_records WHERE id IN ($inClause)");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();

    return $db->changes();
}

function get_checkin_stats(): array
{
    $db = get_db();

    $total = $db->querySingle("SELECT COUNT(*) FROM checkin_records");
    $today = date('Y-m-d');
    $todayCount = $db->querySingle("SELECT COUNT(*) FROM checkin_records WHERE created_at >= '$today'");
    $linksCount = $db->querySingle("SELECT COUNT(*) FROM checkin_links");

    return [
        'total' => (int)$total,
        'today' => (int)$todayCount,
        'links_count' => (int)$linksCount,
    ];
}
