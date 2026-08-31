<?php
/**
 * 数据访问层
 *
 * 职责：
 *   - 初始化并持有 SQLite 数据库连接（单例）
 *   - 配置上传目录与数据库路径常量（UPLOAD_DIR / DB_PATH / MAX_FILE_SIZE）
 *   - 提供文件元数据的增删查改及辅助函数
 *
 * 表结构：files（file_id 主键 / 原始名 / 体积 / 上传时间 / 下载次数 / 存储路径 / 状态）
 */

// 项目根目录：本文件位于 backend/，上一级即项目根
$baseDir = dirname(__DIR__);
// 上传根目录：项目根/uploads/
define('UPLOAD_DIR', rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
// SQLite 数据库文件路径：uploads/data/files.db
define('DB_PATH', UPLOAD_DIR . 'data' . DIRECTORY_SEPARATOR . 'files.db');
// 单文件大小上限：10GB
define('MAX_FILE_SIZE', 10 * 1024 * 1024 * 1024);

// 启动时确保上传目录与数据库目录存在
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

$dbDir = dirname(DB_PATH);
if (!file_exists($dbDir)) {
    mkdir($dbDir, 0755, true);
}

/**
 * Database 数据库连接单例。
 * 封装 PDO，并提供事务与跨进程文件锁（用于并发下载计数等场景）。
 */
class Database {
    private static $instance = null;
    private $pdo;
    private $lockFile;

    private function __construct() {
        // 跨进程排他锁文件，配合 acquireLock/releaseLock 使用
        $this->lockFile = dirname(DB_PATH) . '/db.lock';

        try {
            $this->pdo = new PDO('sqlite:' . DB_PATH);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // SQLite 性能与并发优化参数
            $this->pdo->exec('PRAGMA journal_mode = WAL');        // WAL 模式：读写不互斥
            $this->pdo->exec('PRAGMA synchronous = NORMAL');      // 平衡安全与性能
            $this->pdo->exec('PRAGMA temp_store = MEMORY');       // 临时表存内存
            $this->pdo->exec('PRAGMA mmap_size = 30000000000');   // 内存映射读
            $this->pdo->exec('PRAGMA cache_size = -64000');      // 页缓存大小（KB）

            $this->initTables();
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /** 获取单例实例。 */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** 初始化表结构与索引。 */
    private function initTables() {
        $sql = "CREATE TABLE IF NOT EXISTS files (
            file_id TEXT PRIMARY KEY,                    -- 文件唯一标识ID
            original_name TEXT NOT NULL,                 -- 原始文件名
            file_size INTEGER NOT NULL,                  -- 文件大小（字节）
            upload_time TEXT NOT NULL,                   -- 上传时间
            download_count INTEGER DEFAULT 0,            -- 下载次数
            file_path TEXT NOT NULL,                     -- 文件存储路径
            status TEXT DEFAULT 'active',                -- 文件状态（active: 正常, deleted: 已删除）
            md5 TEXT DEFAULT NULL                        -- 文件 MD5 哈希值（上传后异步计算）
        )";
        $this->pdo->exec($sql);

        // 按上传时间排序的查询较多，建立索引
        $sql = "CREATE INDEX IF NOT EXISTS idx_upload_time ON files(upload_time)";
        $this->pdo->exec($sql);

        // 按状态过滤（active/deleted）的索引
        $sql = "CREATE INDEX IF NOT EXISTS idx_status ON files(status)";
        $this->pdo->exec($sql);

        $this->addColumnComments();
    }

    /** 尝试为各列添加注释（部分 SQLite 版本不支持 COMMENT，忽略错误）。 */
    private function addColumnComments() {
        $comments = [
            "COMMENT ON COLUMN files.file_id IS '文件唯一标识ID'",
            "COMMENT ON COLUMN files.original_name IS '原始文件名'",
            "COMMENT ON COLUMN files.file_size IS '文件大小（字节）'",
            "COMMENT ON COLUMN files.upload_time IS '上传时间'",
            "COMMENT ON COLUMN files.download_count IS '下载次数'",
            "COMMENT ON COLUMN files.file_path IS '文件存储路径'",
            "COMMENT ON COLUMN files.status IS '文件状态（active: 正常, deleted: 已删除）'",
            "COMMENT ON COLUMN files.md5 IS '文件 MD5 哈希值（上传后异步计算）'"
        ];

        foreach ($comments as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                // 不支持 COMMENT 语法的 SQLite 版本会进入这里，忽略即可
            }
        }
    }

    /** 获取底层 PDO 句柄。 */
    public function getPdo() {
        return $this->pdo;
    }

    /** 开启事务。 */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    /** 提交事务。 */
    public function commit() {
        return $this->pdo->commit();
    }

    /** 回滚事务。 */
    public function rollback() {
        return $this->pdo->rollback();
    }

    /**
     * 获取跨进程排他锁（基于文件 flock）。
     * 用于需要串行化的写操作（如下载计数累加）。
     */
    public function acquireLock() {
        $fp = fopen($this->lockFile, 'w');
        if ($fp && flock($fp, LOCK_EX)) {
            return $fp;
        }
        return false;
    }

    /** 释放 acquireLock 获取的锁。 */
    public function releaseLock($fp) {
        if ($fp) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}

/**
 * 生成随机 fileId（默认 12 位字母数字）。
 * 注意：仅生成随机串，不保证唯一，需配合 fileIdExists 检查或使用 generateUniqueFileId。
 */
function generateFileId($length = 12) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    $max = strlen($characters) - 1;
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $max)];
    }
    return $randomString;
}

/**
 * 保存文件元数据（上传完成后调用）。
 * @return bool 写入是否成功
 */
function saveFileInfo($fileId, $originalName, $fileSize, $uploadTime, $filePath) {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    try {
        $stmt = $pdo->prepare("INSERT INTO files (file_id, original_name, file_size, upload_time, file_path) 
                               VALUES (:file_id, :original_name, :file_size, :upload_time, :file_path)");
        $stmt->execute([
            ':file_id' => $fileId,
            ':original_name' => $originalName,
            ':file_size' => $fileSize,
            ':upload_time' => $uploadTime,
            ':file_path' => $filePath
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('Save file info failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * 根据 fileId 查询单个文件信息（仅返回 active 状态）。
 * @return array|null 找到返回记录数组，否则 null
 */
function getFileInfo($fileId) {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    try {
        $stmt = $pdo->prepare("SELECT * FROM files WHERE file_id = :file_id AND status = 'active'");
        $stmt->execute([':file_id' => $fileId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Get file info failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * 下载次数 +1。
 * 使用跨进程文件锁串行化，避免并发下载时计数丢失。
 * @return bool 是否更新成功
 */
function updateDownloadCount($fileId) {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    $lockFp = $db->acquireLock();
    if (!$lockFp) {
        error_log('Failed to acquire lock for download count update');
        return false;
    }

    try {
        $db->beginTransaction();

        $stmt = $pdo->prepare("UPDATE files SET download_count = download_count + 1 WHERE file_id = :file_id");
        $stmt->execute([':file_id' => $fileId]);

        $db->commit();
        $db->releaseLock($lockFp);
        return true;
    } catch (PDOException $e) {
        $db->rollback();
        $db->releaseLock($lockFp);
        error_log('Update download count failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * 获取全部有效文件（按上传时间倒序）。
 * 用于无分页场景；管理页分页请用 getFilesWithPagination。
 */
function getAllFiles() {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    try {
        $stmt = $pdo->query("SELECT * FROM files WHERE status = 'active' ORDER BY upload_time DESC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Get all files failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * 删除文件：先删物理文件目录，再将数据库记录置为 deleted（软删除）。
 * @return bool 是否删除成功
 */
function deleteFile($fileId) {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    try {
        // 先查出存储相对路径，用于定位物理文件
        $stmt = $pdo->prepare("SELECT file_path FROM files WHERE file_id = :file_id AND status = 'active'");
        $stmt->execute([':file_id' => $fileId]);
        $file = $stmt->fetch();

        if (!$file) {
            return false;
        }

        $filePath = UPLOAD_DIR . $file['file_path'];
        $fileDir = dirname($filePath);

        // 删除物理文件目录（含文件本身）
        $deleted = deleteDirectory($fileDir);

        if (!$deleted) {
            error_log('Failed to delete physical files for file ID: ' . $fileId);
            return false;
        }

        // 物理删除成功后，将记录标记为已删除（软删除保留审计痕迹）
        $db->beginTransaction();

        $stmt = $pdo->prepare("UPDATE files SET status = 'deleted' WHERE file_id = :file_id");
        $stmt->execute([':file_id' => $fileId]);

        $db->commit();

        return true;
    } catch (PDOException $e) {
        $db->rollback();
        error_log('Delete file failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * 更新文件的 MD5 哈希值。
 */
function updateFileMd5($fileId, $md5) {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    try {
        $stmt = $pdo->prepare("UPDATE files SET md5 = :md5 WHERE file_id = :file_id");
        $stmt->execute([':md5' => $md5, ':file_id' => $fileId]);
        return true;
    } catch (PDOException $e) {
        error_log('Update MD5 failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * 根据 fileId 获取文件在磁盘上的绝对路径。
 * 供 MD5 计算等后台任务使用。
 * @return string|null 绝对路径，找不到返回 null
 */
function getFilePathById($fileId) {
    $file = getFileInfo($fileId);
    if (!$file) return null;
    return UPLOAD_DIR . $file['file_path'];
}

/**
 * 递归删除目录（含其下所有文件与子目录）。
 * @return bool 是否删除成功
 */
function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }

    // 非目录则按文件删除
    if (!is_dir($dir)) {
        return unlink($dir);
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    
    foreach ($files as $file) {
        $filePath = $dir . DIRECTORY_SEPARATOR . $file;
        
        if (is_dir($filePath)) {
            if (!deleteDirectory($filePath)) {
                return false;
            }
        } else {
            if (!unlink($filePath)) {
                error_log('Failed to delete file: ' . $filePath);
                return false;
            }
        }
    }

    return rmdir($dir);
}

/**
 * 分页查询有效文件列表（按上传时间倒序）。
 * 供文件管理页（backend/files.php）使用。
 * @return array 包含 files/total/totalPages/currentPage
 */
function getFilesWithPagination($page = 1, $pageSize = 8) {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    try {
        // 总数
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM files WHERE status = 'active'");
        $total = $countStmt->fetch()['total'];

        $totalPages = ceil($total / $pageSize);
        $offset = ($page - 1) * $pageSize;

        // LIMIT/OFFSET 分页，参数绑定为整型
        $stmt = $pdo->prepare("SELECT * FROM files WHERE status = 'active' ORDER BY upload_time DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $files = $stmt->fetchAll();

        return [
            'files' => $files,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page
        ];
    } catch (PDOException $e) {
        error_log('Get files with pagination failed: ' . $e->getMessage());
        // 出错时返回空结果，保证接口不崩
        return [
            'files' => [],
            'total' => 0,
            'totalPages' => 0,
            'currentPage' => $page
        ];
    }
}

/** 将字节数格式化为人类可读的体积文本（B/KB/MB/GB）。 */
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 2) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / (1024 * 1024), 2) . ' MB';
    return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}

/** 判断 fileId 是否已存在于数据库。 */
function fileIdExists($fileId) {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM files WHERE file_id = :file_id");
        $stmt->execute([':file_id' => $fileId]);
        return $stmt->fetch()['count'] > 0;
    } catch (PDOException $e) {
        error_log('Check file ID exists failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * 生成保证唯一的 fileId。
 * 默认 12 位，最多重试 10 次；仍冲突则加长到 length+4 位。
 */
function generateUniqueFileId($length = 12) {
    $maxAttempts = 10;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $fileId = generateFileId($length);
        if (!fileIdExists($fileId)) {
            return $fileId;
        }
    }
    return generateFileId($length + 4);
}

/**
 * 清理上传目录下未完成的残留分片目录。
 * 条件：目录名形如 fileId 且数据库中无对应 active 记录，且存在时间超过 maxAgeHours。
 * @return int 清理掉的目录数
 */
function cleanupIncompleteUploads($maxAgeHours = 24) {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    try {
        $dirs = glob(UPLOAD_DIR . '*', GLOB_ONLYDIR);
        
        if ($dirs === false) {
            return 0;
        }

        $cleanedCount = 0;
        $maxAgeSeconds = $maxAgeHours * 3600;
        $currentTime = time();

        foreach ($dirs as $dir) {
            $dirName = basename($dir);
            
            // 仅处理形如 fileId 的目录
            if (!preg_match('/^[a-zA-Z0-9]{12,16}$/', $dirName)) {
                continue;
            }

            // 已入库的有效记录跳过
            $stmt = $pdo->prepare("SELECT file_id FROM files WHERE file_id = :file_id AND status = 'active'");
            $stmt->execute([':file_id' => $dirName]);
            $exists = $stmt->fetch();

            if ($exists) {
                continue;
            }

            $dirTime = filemtime($dir);
            
            // 超过阈值未完成的残留目录才清理
            if ($currentTime - $dirTime > $maxAgeSeconds) {
                if (deleteDirectory($dir)) {
                    $cleanedCount++;
                    error_log('Cleaned up incomplete upload: ' . $dirName);
                }
            }
        }

        return $cleanedCount;
    } catch (PDOException $e) {
        error_log('Cleanup incomplete uploads failed: ' . $e->getMessage());
        return 0;
    }
}
