<?php
/**
 * 文件管理接口
 *
 * 提供文件列表分页查询与删除功能，所有响应统一返回 JSON，
 * 供前端 frontend/manage.html + js/manage.js 通过 AJAX 调用。
 *
 * 接口约定：
 *   - GET  backend/files.php?page=N        获取第 N 页文件列表（每页 8 条）
 *   - POST backend/files.php                删除指定文件（表单字段：action=delete、id=文件ID）
 *
 * 依赖 database.php 提供的数据层函数：getFilesWithPagination / deleteFile / formatFileSize。
 */

require_once 'database.php';

// 所有响应统一为 JSON 编码
header('Content-Type: application/json');

// 仅接受 GET（查询列表）与 POST（删除），其余方法直接拒绝
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => '只支持 GET / POST 请求']);
    exit;
}

// 请求路由：GET 可查列表或触发 MD5 计算/查询；POST 按 action 区分操作
if ($method === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    if ($action === 'calcMd5') {
        handleCalcMd5();
    } elseif ($action === 'getMd5Status') {
        handleGetMd5Status();
    } else {
        handleList();
    }
} else {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'delete') {
        handleDelete();
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '未知的操作']);
        exit;
    }
}

/**
 * 列表查询：返回分页文件记录。
 *
 * 输出字段说明：
 *   files[].download_url 为相对路径（/download/xxx/name），前端拼接 window.location.origin 得到完整分享链接
 *   files[].size_text    为格式化后的体积文本，前端可直接展示
 */
function handleList() {
    // 分页参数：页码最小为 1，每页固定 8 条
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = 8;

    $pagination = getFilesWithPagination($page, $pageSize);

    // 组装前端可直接渲染的记录：附加格式化体积文本与下载相对链接
    $files = [];
    foreach ($pagination['files'] as $file) {
        $files[] = [
            'file_id' => $file['file_id'],
            'original_name' => $file['original_name'],
            'file_size' => (int)$file['file_size'],
            'size_text' => formatFileSize((int)$file['file_size']),
            'download_count' => (int)$file['download_count'],
            'upload_time' => $file['upload_time'],
            'download_url' => '/download/' . $file['file_id'] . '/' . rawurlencode($file['original_name']),
            'md5' => $file['md5'],  // 可能为 null 表示尚未计算
        ];
    }

    echo json_encode([
        'status' => 'success',
        'total' => (int)$pagination['total'],
        'totalPages' => (int)$pagination['totalPages'],
        'currentPage' => (int)$pagination['currentPage'],
        'pageSize' => $pageSize,
        'files' => $files,
    ]);
}

/**
 * 删除文件：先校验 fileId 合法性，再调用数据层删除（含物理文件与数据库记录）。
 */
function handleDelete() {
    if (!isset($_POST['id']) || !is_string($_POST['id'])) {
        echo json_encode(['status' => 'error', 'message' => '缺少文件ID']);
        exit;
    }

    $fileId = $_POST['id'];

    // fileId 仅允许 12~16 位字母数字，避免路径穿越等注入风险
    if (!preg_match('/^[a-zA-Z0-9]{12,16}$/', $fileId)) {
        echo json_encode(['status' => 'error', 'message' => '无效的文件ID']);
        exit;
    }

    $ok = deleteFile($fileId);
    if (!$ok) {
        echo json_encode(['status' => 'error', 'message' => '删除失败，文件可能不存在或已被删除']);
        exit;
    }

    echo json_encode(['status' => 'success', 'message' => '删除成功']);
}

/**
 * 触发指定文件的 MD5 计算（用于旧数据补算或上传后异步计算）。
 * 如果文件已有 md5 则直接返回；否则计算并存库。
 */
function handleCalcMd5() {
    if (!isset($_GET['id']) || !is_string($_GET['id'])) {
        echo json_encode(['status' => 'error', 'message' => '缺少文件ID']);
        exit;
    }

    $fileId = $_GET['id'];

    // fileId 仅允许 12~16 位字母数字
    if (!preg_match('/^[a-zA-Z0-9]{12,16}$/', $fileId)) {
        echo json_encode(['status' => 'error', 'message' => '无效的文件ID']);
        exit;
    }

    // 先查现有记录
    $fileInfo = getFileInfo($fileId);
    if (!$fileInfo) {
        echo json_encode(['status' => 'error', 'message' => '文件不存在']);
        exit;
    }

    // 已有 md5 直接返回
    if (!empty($fileInfo['md5'])) {
        echo json_encode(['status' => 'success', 'md5' => $fileInfo['md5']]);
        exit;
    }

    // 获取磁盘路径
    $filePath = UPLOAD_DIR . $fileInfo['file_path'];
    if (!file_exists($filePath)) {
        echo json_encode(['status' => 'error', 'message' => '文件不在磁盘上']);
        exit;
    }

    // 计算 MD5（md5_file 内部流式读取，大文件安全）
    $md5 = md5_file($filePath);
    if ($md5 === false) {
        echo json_encode(['status' => 'error', 'message' => 'MD5 计算失败']);
        exit;
    }

    updateFileMd5($fileId, $md5);

    echo json_encode(['status' => 'success', 'md5' => $md5]);
}

/**
 * 批量查询文件 MD5 状态（轻量，仅 SELECT md5 字段）。
 * 参数：ids 为逗号分隔的 fileId 列表，如 "abc123,def456,ghi789"。
 * 返回：{status:'success', md5_map: {file_id: md5_or_null}}。
 */
function handleGetMd5Status() {
    $idsParam = isset($_GET['ids']) ? trim($_GET['ids']) : '';
    if ($idsParam === '') {
        echo json_encode(['status' => 'error', 'message' => '缺少 ids 参数']);
        exit;
    }

    $ids = explode(',', $idsParam);
    // 过滤并去重，保留合法 fileId
    $ids = array_values(array_unique(array_filter($ids, function ($id) {
        return preg_match('/^[a-zA-Z0-9]{12,16}$/', $id);
    })));

    if (empty($ids)) {
        echo json_encode(['status' => 'success', 'md5_map' => new stdClass()]);
        exit;
    }

    $db = Database::getInstance();
    $pdo = $db->getPdo();

    // 用 IN (...) 查询，参数绑定
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT file_id, md5 FROM files WHERE file_id IN ($placeholders)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    $md5Map = [];
    foreach ($rows as $row) {
        $md5Map[$row['file_id']] = $row['md5'];  // 可能是 null
    }

    // 保证请求的每个 id 都在 map 里（不存在的文件返回 null）
    foreach ($ids as $id) {
        if (!array_key_exists($id, $md5Map)) {
            $md5Map[$id] = null;
        }
    }

    echo json_encode(['status' => 'success', 'md5_map' => $md5Map]);
}
