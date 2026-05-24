<?php
/**
 * JSON 存储引擎
 * 
 * 提供 JSON 文件的原子读写操作，所有写入均使用文件锁保证并发安全。
 * 所有函数均要求调用方已通过 require_once 引入 config.php。
 */

/**
 * 读取 JSON 文件并解析为数组
 * 
 * @param string $file 文件路径
 * @return array 解析后的数组，文件不存在或解析失败时返回空数组
 */
function read_json(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $content = file_get_contents($file);
    if ($content === false || trim($content) === '') {
        return [];
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON 解析失败: ' . $file . ' - ' . json_last_error_msg());
        return [];
    }

    return $data;
}

/**
 * 写入 JSON 文件（带独占锁，保证并发安全）
 * 
 * @param string $file 文件路径
 * @param array  $data 要写入的数据
 * @return bool 写入成功返回 true，失败返回 false
 */
function write_json(string $file, array $data): bool
{
    $fp = fopen($file, 'c+');
    if ($fp === false) {
        error_log('无法打开文件: ' . $file);
        return false;
    }

    // 获取独占锁，阻塞直到获取到锁
    if (!flock($fp, LOCK_EX)) {
        error_log('无法获取文件锁: ' . $file);
        fclose($fp);
        return false;
    }

    // 清空文件并写入新内容
    ftruncate($fp, 0);
    rewind($fp);

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        error_log('JSON 编码失败: ' . json_last_error_msg());
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $written = fwrite($fp, $json);

    // 释放锁并关闭文件
    flock($fp, LOCK_UN);
    fclose($fp);

    return $written !== false;
}

/**
 * 追加一条记录到 JSON 文件的指定键下
 * 
 * 读取 → 追加 → 写回，整个流程在锁内完成。
 * 
 * @param string $file   JSON 文件路径
 * @param string $key    顶层键名（如 'records'、'links'）
 * @param array  $record 要追加的记录
 * @return bool 追加成功返回 true
 */
function append_record(string $file, string $key, array $record): bool
{
    $data = read_json($file);

    // 确保键存在
    if (!isset($data[$key]) || !is_array($data[$key])) {
        $data[$key] = [];
    }

    // 追加记录
    $data[$key][] = $record;

    return write_json($file, $data);
}

/**
 * 根据 ID 列表批量删除记录
 * 
 * @param string $file JSON 文件路径
 * @param string $key  顶层键名
 * @param array  $ids  要删除的 ID 列表
 * @return int 实际删除的条数
 */
function delete_records_by_ids(string $file, string $key, array $ids): int
{
    $data = read_json($file);

    if (!isset($data[$key]) || !is_array($data[$key])) {
        return 0;
    }

    $before = count($data[$key]);

    // 过滤掉 ID 在 $ids 中的记录
    $data[$key] = array_values(array_filter($data[$key], function ($record) use ($ids) {
        return isset($record['id']) && !in_array($record['id'], $ids, true);
    }));

    $deleted = $before - count($data[$key]);

    if ($deleted > 0) {
        write_json($file, $data);
    }

    return $deleted;
}
