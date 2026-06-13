<?php
/**
 * 采集链接管理模块 (SQLite)
 */

function create_link(string $redirect_url, string $remark = ''): array
{
    $db = get_db();
    $id = 'lnk_' . bin2hex(random_bytes(4));

    $stmt = $db->prepare('INSERT INTO links (id, redirect_url, remark) VALUES (:id, :url, :remark)');
    $stmt->bindValue(':id', $id);
    $stmt->bindValue(':url', $redirect_url);
    $stmt->bindValue(':remark', $remark);

    if ($stmt->execute() === false) {
        return ['success' => false, 'error' => '链接创建失败'];
    }

    $fullUrl = SITE_URL . '/collect.php?id=' . $id;

    return [
        'success' => true,
        'link' => [
            'id' => $id,
            'url' => $fullUrl,
            'redirect_url' => $redirect_url,
            'remark' => $remark,
            'collect_count' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ];
}

function get_links(): array
{
    $db = get_db();
    $result = $db->query('SELECT * FROM links ORDER BY created_at DESC');

    $links = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['url'] = SITE_URL . '/collect.php?id=' . $row['id'];
        $links[] = $row;
    }

    return ['links' => $links, 'total' => count($links)];
}

function get_link(string $id): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM links WHERE id = :id');
    $stmt->bindValue(':id', $id);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function delete_link(string $id): bool
{
    $db = get_db();

    // 删除关联的采集记录
    $stmt = $db->prepare('DELETE FROM records WHERE link_id = :id');
    $stmt->bindValue(':id', $id);
    $stmt->execute();

    // 删除链接
    $stmt = $db->prepare('DELETE FROM links WHERE id = :id');
    $stmt->bindValue(':id', $id);
    $stmt->execute();

    return $db->changes() > 0;
}

function increment_link_count(string $id): void
{
    if (empty($id)) return;
    $db = get_db();
    $stmt = $db->prepare('UPDATE links SET collect_count = collect_count + 1 WHERE id = :id');
    $stmt->bindValue(':id', $id);
    $stmt->execute();
}
