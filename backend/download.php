<?php
/**
 * 直链下载接口
 *
 * 路由规则由项目根目录 .htaccess 指定：
 *   /download/<fileId>/<fileName>  →  backend/download.php
 *
 * 能力：
 *   - 普通单线程下载；
 *   - HTTP Range 断点续传 / 多线程分块下载；
 *   - 大文件长时间传输，脚本不超时；
 *   - 传输前累加一次下载计数（Range 下载仅首次分片计数，避免重复累加）。
 */

// 下载可能持续数分钟甚至更久，解除所有 PHP 层超时与压缩干扰。
@set_time_limit(0);
@ini_set('max_execution_time',   '0');
@ini_set('max_input_time',       '0');
@ini_set('memory_limit',         '256M');
@ini_set('zlib.output_compression', 'Off');

// 清掉所有输出缓冲，保证 Content-Length 与实际传输字节一致。
while (ob_get_level()) {
    @ob_end_clean();
}

require_once __DIR__ . '/database.php';

// 解析：/download/<fileId>/<fileName>
$pathParts = explode('/', trim($_SERVER['REQUEST_URI'], '/'));
if (count($pathParts) < 3) {
    http_response_code(400);
    exit('无效的下载链接');
}
list(, $fileId, $fileName) = $pathParts;
$fileName = urldecode($fileName);

// 输入合法性校验（fileId 12~16 位字母数字，由 generateUniqueFileId 生成）
if (!preg_match('/^[a-zA-Z0-9]{12,16}$/', (string)$fileId)) {
    http_response_code(400);
    exit('无效的文件ID');
}
if ($fileName === '') {
    http_response_code(400);
    exit('无效的文件名');
}
$fileName = basename($fileName);

// 读文件元信息
$info = getFileInfo($fileId);
if (!$info) {
    http_response_code(404);
    exit('文件不存在或已被删除');
}
$filePath = UPLOAD_DIR . $info['file_path'];
if (!is_file($filePath)) {
    http_response_code(404);
    exit('文件不存在或已被删除');
}

$originalName = $info['original_name'];
$fileSize     = (int)$info['file_size'];

/* --------------------------------------------------------------------------
 * HTTP Range 解析
 *   bytes=start-end     例：bytes=0-1048575
 *   bytes=start-        例：bytes=1048576-   （到文件末尾）
 *   bytes=-N            例：bytes=-500       （最后 500 字节）
 * -------------------------------------------------------------------------- */
$hasRange   = false;
$rangeStart = 0;
$rangeEnd   = $fileSize - 1;
$httpRange  = isset($_SERVER['HTTP_RANGE']) ? trim($_SERVER['HTTP_RANGE']) : '';

if ($httpRange !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $httpRange, $m)) {
    list(, $start, $end) = $m;

    if ($start === '' && $end !== '') {
        // 最后 N 字节
        $suffixLen = (int)$end;
        if ($suffixLen > 0 && $suffixLen <= $fileSize) {
            $rangeStart = $fileSize - $suffixLen;
            $hasRange   = true;
        }
    } elseif ($start !== '') {
        $rangeStart = (int)$start;
        $rangeEnd   = ($end !== '') ? (int)$end : ($fileSize - 1);

        $startOK = ($rangeStart >= 0 && $rangeStart < $fileSize);
        $endOK   = ($rangeEnd < $fileSize && $rangeEnd >= $rangeStart);

        if ($startOK && $endOK) {
            $hasRange = true;
        } elseif ($startOK && $end === '') {
            // 省略 end = 下载到末尾
            $rangeEnd = $fileSize - 1;
            $hasRange = true;
        }
    }
}

// Range 请求但解析不合法
if ($httpRange !== '' && !$hasRange) {
    http_response_code(416);
    header('Content-Range: bytes */' . $fileSize);
    exit;
}

/* --------------------------------------------------------------------------
 * 下载计数：完整下载 / Range 起始分片（Range=0）各计一次，
 * 避免多线程下载时每个线程都 +1。
 * -------------------------------------------------------------------------- */
if (!$hasRange || $rangeStart === 0) {
    updateDownloadCount($fileId);
}

/* --------------------------------------------------------------------------
 * 响应头
 * -------------------------------------------------------------------------- */
header('Content-Type: application/octet-stream');
header('Accept-Ranges: bytes');
header('Content-Disposition: attachment; filename="' . rawurlencode($originalName) . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($hasRange) {
    http_response_code(206);
    $contentLength = $rangeEnd - $rangeStart + 1;
    header('Content-Range: bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $fileSize);
    header('Content-Length: ' . $contentLength);
} else {
    header('Content-Length: ' . $fileSize);
}

/* --------------------------------------------------------------------------
 * 流式输出（1MB 一块，防止 fread 空串/失败导致提前退出）
 * -------------------------------------------------------------------------- */
$chunkSize = 1024 * 1024;
$handle    = @fopen($filePath, 'rb');
if (!$handle) {
    http_response_code(500);
    exit('文件读取失败');
}

if ($hasRange && $rangeStart > 0) {
    fseek($handle, $rangeStart);
}

$remaining = $hasRange ? ($rangeEnd - $rangeStart + 1) : $fileSize;

$emptyReads = 0;
while ($remaining > 0 && !feof($handle)) {
    $data = @fread($handle, min($chunkSize, $remaining));

    // 读失败：重试一次再放弃
    if ($data === false) {
        $data = @fread($handle, min($chunkSize, $remaining));
        if ($data === false) {
            error_log('[download.php] fread failed: ' . $filePath);
            break;
        }
    }

    // 读到空串但文件尚未读完（磁盘/网络抖动）：短暂等待，最多 100ms
    if ($data === '') {
        if (++$emptyReads > 100) {
            error_log('[download.php] too many empty reads: ' . $filePath);
            break;
        }
        usleep(1000);
        continue;
    }
    $emptyReads = 0;

    // 防止越界写入超过剩余字节数
    $dataLen = strlen($data);
    if ($dataLen > $remaining) {
        $data    = substr($data, 0, $remaining);
        $dataLen = $remaining;
    }

    echo $data;
    $remaining -= $dataLen;

    @ob_flush();
    @flush();
    @set_time_limit(0);   // 每块重置超时计时，兼容某些 FPM SAPI
}

fclose($handle);
exit;
