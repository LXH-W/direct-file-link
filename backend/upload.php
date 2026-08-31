<?php
/**
 * 文件上传接口
 *
 * 采用分片上传策略以突破大文件限制（支持最大 10GB）。
 * 前端 frontend/js/app.js 将文件切成 5MB 分片逐个 POST 到本接口，
 * 全部分片上传完成后调用 action=finish 合并，取消则调用 action=cancel 清理。
 *
 * 接口约定（统一返回 JSON）：
 *   - POST（默认）            上传单个分片（字段：file/chunk/chunks/fileId/fileName/fileSize）
 *   - POST  action=finish     合并全部分片并入库（字段：fileId/fileName/fileSize）
 *   - POST  action=cancel      取消上传并清理已传分片（字段：fileId）
 */

// 大文件上传所需的 PHP 运行时配置覆盖（上限 10GB）
ini_set('upload_max_filesize', '10G');     // 单个上传文件最大体积
ini_set('post_max_size', '10G');           // 单次 POST 最大体积
ini_set('max_execution_time', '7200');     // 脚本最长执行时间（秒）
ini_set('max_input_time', '7200');         // 解析输入最长耗时（秒）
ini_set('memory_limit', '256M');           // 脚本内存上限（分片+流式读写）

require_once 'database.php';

// 所有响应统一为 JSON 编码
header('Content-Type: application/json');

// 仅接受 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => '只支持 POST 请求']);
    exit;
}

// 按 action 分发：finish=合并，cancel=取消，默认=上传分片
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'finish') {
    finishUpload();
} elseif ($action === 'cancel') {
    cancelUpload();
} else {
    uploadChunk();
}

/**
 * 上传单个分片：接收分片文件并保存到 uploads/<fileId>/chunks/<chunk>.part。
 */
function uploadChunk() {
    // 校验必需参数
    if (!isset($_FILES['file']) || !isset($_POST['chunk']) || !isset($_POST['fileId'])) {
        echo json_encode(['status' => 'error', 'message' => '参数不完整']);
        exit;
    }

    $uploadedFile = $_FILES['file'];
    $chunkIndex = intval($_POST['chunk']);
    $fileId = $_POST['fileId'];

    // fileId 仅允许 12~16 位字母数字，避免目录穿越风险
    if (!preg_match('/^[a-zA-Z0-9]{12,16}$/', $fileId)) {
        echo json_encode(['status' => 'error', 'message' => '无效的文件ID']);
        exit;
    }

    // 校验上传是否成功
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => '文件上传失败']);
        exit;
    }

    // 分片保存目录：uploads/<fileId>/chunks/
    $fileDir = UPLOAD_DIR . $fileId . '/';
    $chunkDir = $fileDir . 'chunks/';

    if (!file_exists($chunkDir)) {
        mkdir($chunkDir, 0755, true);
    }

    // 将临时分片移动到目标位置，文件名用分片序号
    $chunkPath = $chunkDir . $chunkIndex . '.part';
    if (!move_uploaded_file($uploadedFile['tmp_name'], $chunkPath)) {
        echo json_encode(['status' => 'error', 'message' => '分片保存失败']);
        exit;
    }

    echo json_encode(['status' => 'success', 'message' => '分片上传成功']);
}

/**
 * 合并分片：按序号拼接所有分片为目标文件，入库并返回直链下载地址。
 */
function finishUpload() {
    // 校验必需参数
    if (!isset($_POST['fileId']) || !isset($_POST['fileName']) || !isset($_POST['fileSize'])) {
        echo json_encode(['status' => 'error', 'message' => '参数不完整']);
        exit;
    }

    $fileId = $_POST['fileId'];
    $fileName = $_POST['fileName'];
    $fileSize = intval($_POST['fileSize']);

    if (!preg_match('/^[a-zA-Z0-9]{12,16}$/', $fileId)) {
        echo json_encode(['status' => 'error', 'message' => '无效的文件ID']);
        exit;
    }

    // 文件名长度限制（UTF-8 字符数）
    if (mb_strlen($fileName, 'UTF-8') > 120) {
        echo json_encode(['status' => 'error', 'message' => '文件名超过限制（最多120个字符）']);
        exit;
    }

    $fileDir = UPLOAD_DIR . $fileId . '/';
    $chunkDir = $fileDir . 'chunks/';
    $targetFile = $fileDir . $fileName;

    // 分片目录必须存在
    if (!file_exists($chunkDir)) {
        echo json_encode(['status' => 'error', 'message' => '分片目录不存在']);
        exit;
    }

    // 收集所有分片文件
    $chunks = glob($chunkDir . '*.part');
    if ($chunks === false || empty($chunks)) {
        echo json_encode(['status' => 'error', 'message' => '没有找到分片文件']);
        exit;
    }

    // 按分片序号升序排列，保证拼接顺序正确
    usort($chunks, function($a, $b) {
        $numA = intval(basename($a, '.part'));
        $numB = intval(basename($b, '.part'));
        return $numA - $numB;
    });

    if (!file_exists($fileDir)) {
        mkdir($fileDir, 0755, true);
    }

    // 创建目标文件并按顺序写入各分片内容
    $output = fopen($targetFile, 'wb');
    if (!$output) {
        echo json_encode(['status' => 'error', 'message' => '无法创建目标文件']);
        exit;
    }

    foreach ($chunks as $chunk) {
        $input = fopen($chunk, 'rb');
        if (!$input) {
            fclose($output);
            echo json_encode(['status' => 'error', 'message' => '无法读取分片文件']);
            exit;
        }
        // 以 8KB 为单位流式拷贝，避免一次性占用过多内存
        while ($buffer = fread($input, 8192)) {
            fwrite($output, $buffer);
        }
        fclose($input);
        // 分片已并入目标文件，删除临时分片
        unlink($chunk);
    }
    fclose($output);

    // 清理空的分片目录
    rmdir($chunkDir);
    chmod($targetFile, 0644);

    $uploadTime = date('Y-m-d H:i:s');
    // 数据库存储相对路径（fileId/fileName），避免绝对路径耦合部署位置
    $relativePath = $fileId . '/' . $fileName;

    // 入库失败则回滚已合并的文件
    if (!saveFileInfo($fileId, $fileName, $fileSize, $uploadTime, $relativePath)) {
        unlink($targetFile);
        rmdir($fileDir);
        echo json_encode(['status' => 'error', 'message' => '保存文件信息失败']);
        exit;
    }

    // 拼接直链下载地址（与 download.php 的路由规则对应）
    $downloadUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/download/' . $fileId . '/' . rawurlencode($fileName);

    // 先输出成功响应，让前端尽快拿到结果
    echo json_encode([
        'status' => 'success',
        'message' => '上传成功',
        'file_id' => $fileId,
        'download_url' => $downloadUrl,
        'original_name' => $fileName,
        'file_size' => formatFileSize($fileSize)
    ]);

    // 后台异步计算 MD5（断开客户端连接后继续执行）
    // PHP-FPM 环境可用 fastcgi_finish_request()；否则退化为同步计算
    @ob_flush();
    @flush();
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }

    if (file_exists($targetFile)) {
        $md5 = md5_file($targetFile);
        if ($md5 !== false) {
            updateFileMd5($fileId, $md5);
        }
    }
}

/**
 * 取消上传：清理指定 fileId 下已传的分片与文件。
 */
function cancelUpload() {
    if (!isset($_POST['fileId'])) {
        echo json_encode(['status' => 'error', 'message' => '参数不完整']);
        exit;
    }

    $fileId = $_POST['fileId'];

    if (!preg_match('/^[a-zA-Z0-9]{12,16}$/', $fileId)) {
        echo json_encode(['status' => 'error', 'message' => '无效的文件ID']);
        exit;
    }

    $fileDir = UPLOAD_DIR . $fileId . '/';
    $chunkDir = $fileDir . 'chunks/';

    $deletedChunks = 0;
    $deletedFiles = 0;

    // 删除所有分片文件
    if (file_exists($chunkDir)) {
        $chunks = glob($chunkDir . '*.part');
        if ($chunks !== false) {
            foreach ($chunks as $chunk) {
                if (unlink($chunk)) {
                    $deletedChunks++;
                }
            }
        }
        rmdir($chunkDir);
    }

    // 删除该目录下其余文件（如已合并但未入库的目标文件）
    if (file_exists($fileDir)) {
        $files = glob($fileDir . '*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    if (unlink($file)) {
                        $deletedFiles++;
                    }
                }
            }
        }
        rmdir($fileDir);
    }

    echo json_encode([
        'status' => 'success',
        'message' => '取消上传成功',
        'deleted_chunks' => $deletedChunks,
        'deleted_files' => $deletedFiles
    ]);
}
