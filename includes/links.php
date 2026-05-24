<?php
/**
 * 采集链接管理模块
 * 
 * 提供采集链接的创建、查询、删除和计数功能。
 * 依赖：config.php, includes/storage.php
 */

/**
 * 创建新的采集链接
 * 
 * 生成唯一 ID（lnk_ + 8位随机hex），存储到 links.json 并返回完整 URL。
 * 
 * @param string $redirect_url 采集完成后跳转的目标 URL
 * @param string $remark       链接备注（可选）
 * @return array 包含 success 状态和链接对象
 */
function create_link(string $redirect_url, string $remark = ''): array
{
    // 生成唯一链接 ID：lnk_ + 8位随机hex
    $id = 'lnk_' . bin2hex(random_bytes(4));

    $link = [
        'id' => $id,
        'redirect_url' => $redirect_url,
        'remark' => $remark,
        'collect_count' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    $success = append_record(LINKS_FILE, 'links', $link);

    if (!$success) {
        return ['success' => false, 'error' => '链接创建失败'];
    }

    // 拼接完整访问 URL
    $fullUrl = SITE_URL . '/collect.php?id=' . $id;

    return [
        'success' => true,
        'link' => [
            'id' => $id,
            'url' => $fullUrl,
            'redirect_url' => $redirect_url,
            'remark' => $remark,
            'collect_count' => 0,
            'created_at' => $link['created_at'],
        ],
    ];
}

/**
 * 获取所有采集链接列表
 * 
 * 按创建时间倒序排列。
 * 
 * @return array ['links' => [...], 'total' => int]
 */
function get_links(): array
{
    $data = read_json(LINKS_FILE);
    $links = $data['links'] ?? [];

    // 按创建时间倒序
    usort($links, function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });

    // 为每个链接拼接完整 URL
    foreach ($links as &$link) {
        $link['url'] = SITE_URL . '/collect.php?id=' . $link['id'];
    }
    unset($link);

    return [
        'links' => $links,
        'total' => count($links),
    ];
}

/**
 * 根据 ID 获取单个链接
 * 
 * @param string $id 链接 ID
 * @return array|null 链接对象或 null
 */
function get_link(string $id): ?array
{
    $data = read_json(LINKS_FILE);
    $links = $data['links'] ?? [];

    foreach ($links as $link) {
        if ($link['id'] === $id) {
            return $link;
        }
    }

    return null;
}

/**
 * 删除链接及其关联的采集记录
 * 
 * @param string $id 链接 ID
 * @return bool 删除成功返回 true
 */
function delete_link(string $id): bool
{
    // 删除关联的采集记录
    $recordsData = read_json(RECORDS_FILE);
    $records = $recordsData['records'] ?? [];

    $beforeCount = count($records);
    $records = array_values(array_filter($records, function ($record) use ($id) {
        return ($record['link_id'] ?? '') !== $id;
    }));

    if (count($records) !== $beforeCount) {
        write_json(RECORDS_FILE, ['records' => $records]);
    }

    // 删除链接本身
    $before = count(read_json(LINKS_FILE)['links'] ?? []);
    $deleted = delete_records_by_ids(LINKS_FILE, 'links', [$id]);

    return $deleted > 0;
}

/**
 * 增加链接的采集计数
 * 
 * @param string $id 链接 ID
 */
function increment_link_count(string $id): void
{
    if (empty($id)) {
        return;
    }

    $data = read_json(LINKS_FILE);
    $links = $data['links'] ?? [];

    foreach ($links as &$link) {
        if ($link['id'] === $id) {
            $link['collect_count'] = ($link['collect_count'] ?? 0) + 1;
            break;
        }
    }
    unset($link);

    write_json(LINKS_FILE, ['links' => $links]);
}
