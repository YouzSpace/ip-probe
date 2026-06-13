<?php
/**
 * 签到管理模块
 * 
 * 提供签到链接的创建、查询、删除和签到记录的保存、查询功能。
 * 依赖：config.php, includes/storage.php
 */

/**
 * 创建新的签到链接
 * 
 * 生成唯一 ID（ck_ + 8位随机hex），存储到 checkins.json 并返回完整 URL。
 * 
 * @param string $remark 链接备注（可选）
 * @return array 包含 success 状态和签到链接对象
 */
function create_checkin_link(string $remark = ''): array
{
    $id = 'ck_' . bin2hex(random_bytes(4));

    $checkin = [
        'id' => $id,
        'remark' => $remark,
        'checkin_count' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $success = append_record(CHECKINS_FILE, 'checkins', $checkin);

    if (!$success) {
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
            'created_at' => $checkin['created_at'],
        ],
    ];
}

/**
 * 获取所有签到链接列表
 * 
 * @return array ['checkins' => [...], 'total' => int]
 */
function get_checkin_links(): array
{
    $data = read_json(CHECKINS_FILE);
    $checkins = $data['checkins'] ?? [];

    usort($checkins, function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });

    foreach ($checkins as &$item) {
        $item['url'] = SITE_URL . '/checkin.php?id=' . $item['id'];
    }
    unset($item);

    return [
        'checkins' => $checkins,
        'total' => count($checkins),
    ];
}

/**
 * 根据 ID 获取单个签到链接
 * 
 * @param string $id 签到链接 ID
 * @return array|null
 */
function get_checkin_link(string $id): ?array
{
    $data = read_json(CHECKINS_FILE);
    $checkins = $data['checkins'] ?? [];

    foreach ($checkins as $item) {
        if ($item['id'] === $id) {
            return $item;
        }
    }

    return null;
}

/**
 * 删除签到链接及其关联的签到记录
 * 
 * @param string $id 签到链接 ID
 * @return bool
 */
function delete_checkin_link(string $id): bool
{
    // 删除关联的签到记录及其照片
    $recordsData = read_json(CHECKIN_RECORDS_FILE);
    $records = $recordsData['records'] ?? [];

    $keepRecords = [];
    foreach ($records as $record) {
        if (($record['checkin_id'] ?? '') === $id) {
            // 删除关联照片
            if (!empty($record['photo'])) {
                $filepath = PHOTOS_DIR . '/' . basename($record['photo']);
                if (file_exists($filepath)) {
                    @unlink($filepath);
                }
            }
        } else {
            $keepRecords[] = $record;
        }
    }

    if (count($keepRecords) !== count($records)) {
        write_json(CHECKIN_RECORDS_FILE, ['records' => $keepRecords]);
    }

    // 删除链接本身
    $deleted = delete_records_by_ids(CHECKINS_FILE, 'checkins', [$id]);

    return $deleted > 0;
}

/**
 * 增加签到链接的签到计数
 * 
 * @param string $id 签到链接 ID
 */
function increment_checkin_count(string $id): void
{
    if (empty($id)) return;

    $data = read_json(CHECKINS_FILE);
    $checkins = $data['checkins'] ?? [];

    foreach ($checkins as &$item) {
        if ($item['id'] === $id) {
            $item['checkin_count'] = ($item['checkin_count'] ?? 0) + 1;
            break;
        }
    }
    unset($item);

    write_json(CHECKINS_FILE, ['checkins' => $checkins]);
}

/**
 * 删除签到链接本身（不删除关联的签到记录）
 * 
 * @param string $id 签到链接 ID
 * @return bool
 */
function remove_checkin_link(string $id): bool
{
    return delete_records_by_ids(CHECKINS_FILE, 'checkins', [$id]) > 0;
}

/**
 * 保存一条签到记录（含照片和 GPS）
 * 
 * @param array $client_data 客户端传入的采集数据
 * @param string|null $photo_filename 已保存的照片文件名（相对路径）
 * @return array
 */
function save_checkin_record(array $client_data, ?string $photo_filename = null): array
{
    $id = 'ci_' . bin2hex(random_bytes(6));

    $record = [
        'id' => $id,
        'server_ip' => get_client_ip(),
        'created_at' => date('Y-m-d H:i:s'),

        'checkin_id' => $client_data['checkin_id'] ?? '',
        'ipv4' => $client_data['ipv4'] ?? '',
        'ipv6' => $client_data['ipv6'] ?? '',
        'location' => $client_data['location'] ?? [],
        'user_agent_raw' => $client_data['user_agent_raw'] ?? '',
        'os' => $client_data['os'] ?? '',
        'browser' => $client_data['browser'] ?? '',
        'language' => $client_data['language'] ?? '',
        'screen' => $client_data['screen'] ?? '',
        'dpr' => $client_data['dpr'] ?? 1,
        'is_proxy' => $client_data['is_proxy'] ?? false,
        'proxy_detected_by' => $client_data['proxy_detected_by'] ?? null,

        // GPS 真实坐标
        'gps' => $client_data['gps'] ?? null,

        // 签到照片
        'photo' => $photo_filename,
        'photo_url' => $photo_filename ? '/api.php?action=checkin_photo&file=' . $photo_filename : null,
    ];

    $success = append_record(CHECKIN_RECORDS_FILE, 'records', $record);

    if ($success && !empty($client_data['checkin_id'])) {
        increment_checkin_count($client_data['checkin_id']);
        // 一次性链接：签到成功后自动删除
        remove_checkin_link($client_data['checkin_id']);
    }

    return [
        'success' => $success,
        'id' => $id,
        'record' => $record,
    ];
}

/**
 * 获取签到记录列表（分页 + 搜索）
 * 
 * @param int    $page
 * @param int    $limit
 * @param string $search
 * @param string $checkin_id 按签到链接 ID 过滤（可选）
 * @return array
 */
function get_checkin_records(int $page = 1, int $limit = 20, string $search = '', string $checkin_id = ''): array
{
    $data = read_json(CHECKIN_RECORDS_FILE);
    $records = $data['records'] ?? [];

    usort($records, function ($a, $b) {
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });

    // 按签到链接 ID 过滤
    if (!empty($checkin_id)) {
        $records = array_values(array_filter($records, function ($record) use ($checkin_id) {
            return ($record['checkin_id'] ?? '') === $checkin_id;
        }));
    }

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
                if (stripos($field, $searchLower) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    $total = count($records);
    $offset = ($page - 1) * $limit;
    $records = array_slice($records, $offset, $limit);

    $summaries = [];
    foreach ($records as $r) {
        $summaries[] = [
            'id' => $r['id'] ?? '',
            'ipv4' => $r['ipv4'] ?? '',
            'ipv6' => $r['ipv6'] ?? '',
            'os' => $r['os'] ?? '',
            'browser' => $r['browser'] ?? '',
            'location' => $r['location'] ?? [],
            'checkin_id' => $r['checkin_id'] ?? '',
            'created_at' => $r['created_at'] ?? '',
        ];
    }

    return [
        'records' => $summaries,
        'total' => $total,
    ];
}

/**
 * 获取单条签到记录详情
 */
function get_checkin_record(string $id): ?array
{
    $data = read_json(CHECKIN_RECORDS_FILE);
    $records = $data['records'] ?? [];

    foreach ($records as $record) {
        if (($record['id'] ?? '') === $id) {
            return $record;
        }
    }

    return null;
}

/**
 * 批量删除签到记录（同时删除关联照片文件）
 */
function delete_checkin_records(array $ids): int
{
    // 先找到要删除的记录，收集照片文件名
    $data = read_json(CHECKIN_RECORDS_FILE);
    $records = $data['records'] ?? [];

    $photosToDelete = [];
    foreach ($records as $record) {
        if (isset($record['id']) && in_array($record['id'], $ids, true)) {
            if (!empty($record['photo'])) {
                $photosToDelete[] = $record['photo'];
            }
        }
    }

    // 删除照片文件
    foreach ($photosToDelete as $filename) {
        $filepath = PHOTOS_DIR . '/' . basename($filename);
        if (file_exists($filepath)) {
            @unlink($filepath);
        }
    }

    // 删除记录
    return delete_records_by_ids(CHECKIN_RECORDS_FILE, 'records', $ids);
}

/**
 * 获取签到统计
 */
function get_checkin_stats(): array
{
    $data = read_json(CHECKIN_RECORDS_FILE);
    $records = $data['records'] ?? [];

    $linksData = read_json(CHECKINS_FILE);
    $linksCount = count($linksData['checkins'] ?? []);

    $total = count($records);
    $today = date('Y-m-d');
    $todayCount = 0;

    foreach ($records as $record) {
        $recordDate = substr($record['created_at'] ?? '', 0, 10);
        if ($recordDate === $today) {
            $todayCount++;
        }
    }

    return [
        'total' => $total,
        'today' => $todayCount,
        'links_count' => $linksCount,
    ];
}
