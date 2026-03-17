<?php
// 加载数据库配置
function loadDbConfig() {
    $configFile = __DIR__ . '/config/db_config.ini';
    if (file_exists($configFile)) {
        $config = parse_ini_file($configFile, true);
        if (isset($config['database'])) {
            return $config['database'];
        }
    }
    return [
        'host' => 'localhost',
        'username' => 'root',
        'password' => '',
        'database' => 'file_share',
        'port' => 3306,
        'configured' => false
    ];
}

// 保存数据库配置
function saveDbConfig($config) {
    $configFile = __DIR__ . '/config/db_config.ini';
    $configDir = dirname($configFile);
    
    // 确保配置目录存在
    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    
    $iniContent = "[database]\n";
    foreach ($config as $key => $value) {
        $iniContent .= "$key = $value\n";
    }
    file_put_contents($configFile, $iniContent);
}

// 迁移数据从 JSON 到 MySQL
function migrateData() {
    if (!isDbConfigured()) {
        return ['success' => false, 'message' => '数据库未配置'];
    }
    
    $pdo = getDbConnection();
    if (!$pdo) {
        return ['success' => false, 'message' => '数据库连接失败'];
    }
    
    // 迁移 API Keys
    $authFile = __DIR__ . '/data/api_keys.json';
    if (file_exists($authFile)) {
        $apiKeys = json_decode(file_get_contents($authFile), true);
        if (is_array($apiKeys)) {
            foreach ($apiKeys as $key) {
                if (isset($key['api_key'], $key['name'])) {
                    $apiKeyHash = simpleHash($key['api_key']);
                    $createdAt = $key['created_at'] ?? date('Y-m-d H:i:s');
                    
                    // 检查是否已存在
                    $stmt = $pdo->prepare("SELECT id FROM file_api_keys WHERE api_key = ?");
                    $stmt->execute([$key['api_key']]);
                    if (!$stmt->fetch()) {
                        $stmt = $pdo->prepare("INSERT INTO file_api_keys (api_key, api_key_hash, name, created_at) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$key['api_key'], $apiKeyHash, $key['name'], $createdAt]);
                    }
                }
            }
        }
    }
    
    // 迁移上传记录
    $recordFile = __DIR__ . '/data/upload_history.json';
    if (file_exists($recordFile)) {
        $records = json_decode(file_get_contents($recordFile), true);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['filename'], $record['original_filename'], $record['category'], $record['size'], $record['url'], $record['api_key_hash'], $record['api_key_name'], $record['date'])) {
                    // 检查是否已存在
                    $stmt = $pdo->prepare("SELECT id FROM file_upload_records WHERE url = ?");
                    $stmt->execute([$record['url']]);
                    if (!$stmt->fetch()) {
                        $stmt = $pdo->prepare("INSERT INTO file_upload_records (filename, original_filename, category, size, url, remark, api_key_hash, api_key_name, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $record['filename'],
                            $record['original_filename'],
                            $record['category'],
                            $record['size'],
                            $record['url'],
                            $record['remark'] ?? '',
                            $record['api_key_hash'],
                            $record['api_key_name'],
                            $record['date']
                        ]);
                    }
                }
            }
        }
    }
    
    return ['success' => true, 'message' => '数据迁移成功'];
}

// 连接数据库
function getDbConnection() {
    $config = loadDbConfig();
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        return false;
    }
}

// 初始化数据库
function initDatabase() {
    $config = loadDbConfig();
    try {
        // 先连接到 MySQL 服务器
        $pdo = new PDO(
            "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4",
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]
        );
        
        // 创建数据库
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // 连接到创建的数据库
        $pdo = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]
        );
        
        // 创建上传记录表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `file_upload_records` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `filename` VARCHAR(255) NOT NULL,
            `original_filename` VARCHAR(255) NOT NULL,
            `category` VARCHAR(100) NOT NULL,
            `size` VARCHAR(50) NOT NULL,
            `url` VARCHAR(255) NOT NULL,
            `remark` TEXT,
            `api_key_hash` VARCHAR(100) NOT NULL,
            `api_key_name` VARCHAR(100) NOT NULL,
            `date` DATETIME NOT NULL,
            INDEX `idx_api_key_hash` (`api_key_hash`),
            INDEX `idx_date` (`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 创建 API 密钥表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `file_api_keys` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `api_key` VARCHAR(255) NOT NULL UNIQUE,
            `api_key_hash` VARCHAR(100) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `delete_password_hash` VARCHAR(255),
            `status` VARCHAR(20) NOT NULL DEFAULT '未使用',
            `created_at` DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 添加status字段（如果不存在）
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `file_api_keys` LIKE 'status'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `file_api_keys` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT '未使用'");
        }
        
        // 创建系统配置表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `file_config` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `key` VARCHAR(100) NOT NULL UNIQUE,
            `value` TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // 插入默认配置
        $pdo->exec("INSERT INTO `file_config` (`key`, `value`) VALUES
        ('admin_password_hash', ''),
        ('expiry_days', '30')
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// 检查数据库配置是否已设置
function isDbConfigured() {
    $config = loadDbConfig();
    $configured = $config['configured'];
    return $configured === true || $configured === 'true' || $configured === '1' || $configured === 1;
}

// 简单的哈希函数
function simpleHash($str) {
    $hash = 0;
    $length = strlen($str);
    for ($i = 0; $i < $length; $i++) {
        $char = ord($str[$i]);
        $hash = ((($hash << 5) - $hash) + $char) & 0xFFFFFFFF;
    }
    // 确保哈希值为正数
    if ($hash < 0) {
        $hash = abs($hash);
    }
    return dechex($hash);
}

// 清理过期文件（默认30天）
if (isDbConfigured()) {
    cleanExpiredFiles();
}

// 检查数据库配置状态
if (isset($_GET['action']) && $_GET['action'] === 'check_db_config') {
    echo json_encode(['configured' => isDbConfigured()]);
    exit;
}

// 测试数据库连接
if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'test_db_connection') {
    $host = $_REQUEST['host'] ?? 'localhost';
    $username = $_REQUEST['username'] ?? 'root';
    $password = $_REQUEST['password'] ?? '';
    $database = $_REQUEST['database'] ?? 'file_share';
    $port = $_REQUEST['port'] ?? 3306;
    
    try {
        // 先测试连接到 MySQL 服务器
        $pdo = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]
        );
        
        // 测试创建数据库
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // 测试连接到创建的数据库
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]
        );
        
        echo json_encode(['success' => true, 'message' => '数据库连接测试成功']);
    } catch (PDOException $e) {
        // 输出详细错误信息
        $errorInfo = [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ];
        
        // 生成简洁的错误信息
        $errorMessage = '数据库连接失败';
        $fullMessage = $e->getMessage();
        
        if (strpos($fullMessage, 'Access denied') !== false) {
            $errorMessage = ' 用户名或密码错误';
        } else if (strpos($fullMessage, 'Unknown database') !== false) {
            $errorMessage = '数据库不存在';
        } else if (strpos($fullMessage, 'Connection refused') !== false) {
            $errorMessage = '连接被拒绝，请检查主机和端口';
        } else if (strpos($fullMessage, 'No such host') !== false) {
            $errorMessage = ' 主机不存在';
        }
        
        echo json_encode(['success' => false, 'message' => $errorMessage, 'error' => $errorInfo]);
    } catch (Exception $e) {
        // 捕获其他异常
        echo json_encode(['success' => false, 'message' => '未知错误: 请检查网络连接和配置信息']);
    }
    exit;
}

// 保存数据库配置
if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'save_db_config') {
    $host = $_REQUEST['host'] ?? 'localhost';
    $username = $_REQUEST['username'] ?? 'root';
    $password = $_REQUEST['password'] ?? '';
    $database = $_REQUEST['database'] ?? 'file_share';
    $port = $_REQUEST['port'] ?? 3306;
    $adminKey = $_REQUEST['admin_key'] ?? '';
    $adminPassword = $_REQUEST['admin_password'] ?? '';
    
    // 保存配置
    $config = [
        'host' => $host,
        'username' => $username,
        'password' => $password,
        'database' => $database,
        'port' => (int)$port,
        'admin_key' => $adminKey,
        'admin_password' => $adminPassword,
        'configured' => false
    ];
    
    saveDbConfig($config);
    
    // 尝试初始化数据库
    if (initDatabase()) {
        // 初始化成功，标记为已配置
        $config['configured'] = true;
        saveDbConfig($config);
        
        // 保存管理员密码哈希到数据库
        $pdo = getDbConnection();
        if ($pdo && !empty($adminPassword)) {
            $adminPasswordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE file_config SET `value` = ? WHERE `key` = 'admin_password_hash'");
            $stmt->execute([$adminPasswordHash]);
        }
        
        // 直接迁移数据，不依赖isDbConfigured()检查
        $pdo = getDbConnection();
        if ($pdo) {
            // 迁移 API Keys
            $authFile = __DIR__ . '/data/api_keys.json';
            if (file_exists($authFile)) {
                $apiKeys = json_decode(file_get_contents($authFile), true);
                if (is_array($apiKeys)) {
                    foreach ($apiKeys as $key) {
                        if (isset($key['api_key'], $key['name'])) {
                            $apiKeyHash = simpleHash($key['api_key']);
                            $createdAt = $key['created_at'] ?? date('Y-m-d H:i:s');
                            
                            // 检查是否已存在
                            $stmt = $pdo->prepare("SELECT id FROM file_api_keys WHERE api_key = ? OR api_key_hash = ?");
                            $stmt->execute([$key['api_key'], $apiKeyHash]);
                            if (!$stmt->fetch()) {
                                $stmt = $pdo->prepare("INSERT INTO file_api_keys (api_key, api_key_hash, name, created_at) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$key['api_key'], $apiKeyHash, $key['name'], $createdAt]);
                            }
                        }
                    }
                }
            }
            
            // 添加管理员Key到数据库
            if (!empty($adminKey)) {
                $apiKeyHash = simpleHash($adminKey);
                // 检查是否已存在
                $stmt = $pdo->prepare("SELECT id FROM file_api_keys WHERE api_key = ? OR api_key_hash = ?");
                $stmt->execute([$adminKey, $apiKeyHash]);
                if (!$stmt->fetch()) {
                    $stmt = $pdo->prepare("INSERT INTO file_api_keys (api_key, api_key_hash, name, created_at) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$adminKey, $apiKeyHash, '管理员', date('Y-m-d H:i:s')]);
                }
            }
            
            // 迁移上传记录
            $recordFile = __DIR__ . '/data/upload_history.json';
            if (file_exists($recordFile)) {
                $records = json_decode(file_get_contents($recordFile), true);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (isset($record['filename'], $record['original_filename'], $record['category'], $record['size'], $record['url'], $record['api_key_hash'], $record['api_key_name'], $record['date'])) {
                            // 检查是否已存在
                            $stmt = $pdo->prepare("SELECT id FROM file_upload_records WHERE url = ?");
                            $stmt->execute([$record['url']]);
                            if (!$stmt->fetch()) {
                                $stmt = $pdo->prepare("INSERT INTO file_upload_records (filename, original_filename, category, size, url, remark, api_key_hash, api_key_name, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $stmt->execute([
                                    $record['filename'],
                                    $record['original_filename'],
                                    $record['category'],
                                    $record['size'],
                                    $record['url'],
                                    $record['remark'] ?? '',
                                    $record['api_key_hash'],
                                    $record['api_key_name'],
                                    $record['date']
                                ]);
                            }
                        }
                    }
                }
            }
            
            echo json_encode(['success' => true, 'message' => '数据库配置成功，数据迁移完成']);
        } else {
            echo json_encode(['success' => true, 'message' => '数据库配置成功，但数据迁移失败: 数据库连接失败']);
        }
    } else {
        // 初始化失败
        echo json_encode(['success' => false, 'message' => '数据库连接失败，请检查配置信息']);
    }
    exit;
}

// 处理文件上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $category = $_POST['category'] ?? '默认分类';
    $remark = $_POST['remark'] ?? '';
    $apiKey = $_POST['api_key'] ?? '';
    
    // 验证分类
    if ($category === '__add_new__') {
        echo json_encode(['success' => false, 'message' => '请选择有效的分类']);
        exit;
    }
    
    // 检查是否为多文件上传
    $files = $_FILES['file'];
    $isMultiple = is_array($files['name']);
    
    // 处理单个文件
    if (!$isMultiple) {
        $file = $files;
        
        // 验证文件
            if ($file['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => '文件上传失败']);
                exit;
            }
            
            // 验证文件类型
            $allowedTypes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain', 'text/csv',
                'application/zip', 'application/rar', 'application/7z-compressed',
                'application/x-msdownload', 'application/octet-stream'
            ];
            
            $fileType = mime_content_type($file['tmp_name']);
            if (!in_array($fileType, $allowedTypes)) {
                echo json_encode(['success' => false, 'message' => '不支持的文件类型']);
                exit;
            }
            
            // 验证文件大小（限制20M）
            $maxSize = 20 * 1024 * 1024; // 20MB
            if ($file['size'] > $maxSize) {
                echo json_encode(['success' => false, 'message' => '文件大小超过限制（最大20MB）']);
                exit;
            }
        
        // 检查数据库配置和表是否存在
        if (!isDbConfigured()) {
            echo json_encode(['success' => false, 'message' => '数据库未配置，请先配置数据库']);
            exit;
        }
        
        $pdo = getDbConnection();
        if (!$pdo) {
            echo json_encode(['success' => false, 'message' => '数据库连接失败，请检查数据库配置']);
            exit;
        }
        
        // 检查上传记录表是否存在
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'file_upload_records'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => '数据库表不存在，请重新配置数据库']);
                exit;
            }
            
            // 检查 API keys 表是否存在
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'file_api_keys'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => '数据库表不存在，请重新配置数据库']);
                exit;
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => '数据库表检查失败: ' . $e->getMessage()]);
            exit;
        }
        
        // 创建分类目录
            $uploadDir = __DIR__ . '/file_up/' . $category;
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0777, true)) {
                    echo json_encode(['success' => false, 'message' => '无法创建分类目录']);
                    exit;
                }
            }
            
            // 生成唯一文件名
            $filename = uniqid() . '_' . basename($file['name']);
            $filepath = $uploadDir . '/' . $filename;
            
            // 移动文件
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                // 计算文件大小
                $fileSize = filesize($filepath);
                $sizeFormatted = formatFileSize($fileSize);
                
                // 生成访问URL
                $url = 'file_up/' . $category . '/' . $filename;
            
            // 保存上传记录
            try {
                saveUploadRecord($category, $filename, $file['name'], $fileSize, $url, $remark, $apiKey);
                
                echo json_encode([
                    'success' => true,
                    'filename' => $file['name'],
                    'category' => $category,
                    'path' => $filepath,
                    'size' => $sizeFormatted,
                    'url' => $url,
                    'remark' => $remark
                ]);
            } catch (Exception $e) {
                // 保存记录失败，删除已上传的文件
                unlink($filepath);
                echo json_encode(['success' => false, 'message' => '保存上传记录失败: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '文件移动失败']);
        }
    } else {
        // 处理多文件上传
        
        // 检查数据库配置和表是否存在
        if (!isDbConfigured()) {
            echo json_encode(['success' => false, 'message' => '数据库未配置，请先配置数据库']);
            exit;
        }
        
        $pdo = getDbConnection();
        if (!$pdo) {
            echo json_encode(['success' => false, 'message' => '数据库连接失败，请检查数据库配置']);
            exit;
        }
        
        // 检查上传记录表是否存在
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'file_upload_records'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => '数据库表不存在，请重新配置数据库']);
                exit;
            }
            
            // 检查 API keys 表是否存在
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'file_api_keys'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => '数据库表不存在，请重新配置数据库']);
                exit;
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => '数据库表检查失败: ' . $e->getMessage()]);
            exit;
        }
        
        $uploadedFiles = [];
        $error = false;
        $errorMessage = '';
        $uploadedFilePaths = [];
        
        try {
            for ($i = 0; $i < count($files['name']); $i++) {
                $file = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i]
                ];
                
                // 验证文件
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('部分文件上传失败');
                }
                
                // 验证文件类型
                $allowedTypes = [
                    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                    'application/pdf',
                    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'text/plain', 'text/csv',
                    'application/zip', 'application/rar', 'application/7z-compressed',
                    'application/x-msdownload', 'application/octet-stream'
                ];
                
                $fileType = mime_content_type($file['tmp_name']);
                if (!in_array($fileType, $allowedTypes)) {
                    throw new Exception('部分文件类型不支持');
                }
                
                // 验证文件大小（限制20M）
                $maxSize = 20 * 1024 * 1024; // 20MB
                if ($file['size'] > $maxSize) {
                    throw new Exception('部分文件大小超过限制（最大20MB）');
                }
                
                // 创建分类目录
                $uploadDir = __DIR__ . '/file_up/' . $category;
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0777, true)) {
                        throw new Exception('无法创建分类目录');
                    }
                }
                
                // 生成唯一文件名
                $filename = uniqid() . '_' . basename($file['name']);
                $filepath = $uploadDir . '/' . $filename;
                
                // 移动文件
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // 计算文件大小
                    $fileSize = filesize($filepath);
                    $sizeFormatted = formatFileSize($fileSize);
                    
                    // 生成访问URL
                    $url = 'file_up/' . $category . '/' . $filename;
                    
                    // 保存上传记录
                    saveUploadRecord($category, $filename, $file['name'], $fileSize, $url, $remark, $apiKey);
                    
                    $uploadedFiles[] = [
                        'filename' => $file['name'],
                        'category' => $category,
                        'path' => $filepath,
                        'size' => $sizeFormatted,
                        'url' => $url,
                        'remark' => $remark
                    ];
                    
                    // 记录已上传的文件路径，用于出错时回滚
                    $uploadedFilePaths[] = $filepath;
                } else {
                    throw new Exception('部分文件移动失败');
                }
            }
            
            echo json_encode([
                'success' => true,
                'files' => $uploadedFiles,
                'message' => '成功上传 ' . count($uploadedFiles) . ' 个文件'
            ]);
        } catch (Exception $e) {
            // 出错时删除已上传的文件
            foreach ($uploadedFilePaths as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    exit;
}

// 获取上传记录
if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    $apiKey = $_GET['api_key'] ?? '';
    $apiKeyHash = $_GET['api_key_hash'] ?? '';
    $files = getUploadRecords($apiKey, $apiKeyHash);
    echo json_encode(['success' => true, 'files' => $files]);
    exit;
}

// 删除文件
if (isset($_POST['action']) && $_POST['action'] === 'delete_file') {
    // 写死的变量，控制是否允许删除
    $dell = 'y'; // 设置为 'y' 允许删除，其他值不允许删除
    $allowDelete = $dell === 'y';
    if (!$allowDelete) {
        echo json_encode(['success' => false, 'message' => '不允许删除文件']);
        exit;
    }
    
    $url = $_POST['url'] ?? '';
    $apiKey = $_POST['api_key'] ?? '';
    $deletePassword = $_POST['delete_password'] ?? '';
    
    if ($url) {
        // 验证删除密码
        if (isDbConfigured() && !empty($apiKey)) {
            $pdo = getDbConnection();
            if ($pdo) {
                // 获取 API Key 的删除密码哈希
                $stmt = $pdo->prepare("SELECT delete_password_hash FROM file_api_keys WHERE api_key = ?");
                $stmt->execute([$apiKey]);
                $result = $stmt->fetch();
                
                if ($result && !empty($result['delete_password_hash'])) {
                    // 验证密码
                    if (!password_verify($deletePassword, $result['delete_password_hash'])) {
                        echo json_encode(['success' => false, 'message' => '删除密码错误']);
                        exit;
                    }
                }
            }
        }
        
        // 构建文件路径
        $filePath = __DIR__ . '/' . $url;
        
        // 检查文件是否存在
        if (file_exists($filePath)) {
            // 删除文件
            if (unlink($filePath)) {
                // 更新上传历史记录
                updateUploadHistory($url);
                echo json_encode(['success' => true, 'message' => '文件删除成功']);
            } else {
                echo json_encode(['success' => false, 'message' => '文件删除失败']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '文件不存在']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => '无效的文件路径']);
    }
    exit;
}

// 添加 API Key
if (isset($_POST['action']) && $_POST['action'] === 'add_api_key') {
    $apiKey = $_POST['api_key'] ?? '';
    $name = $_POST['name'] ?? '';
    $adminPassword = $_POST['admin_password'] ?? '';
    
    if (empty($apiKey) || empty($name)) {
        echo json_encode(['success' => false, 'message' => 'API Key 和名称不能为空']);
        exit;
    }
    
    if (empty($adminPassword)) {
        echo json_encode(['success' => false, 'message' => '管理员密码不能为空']);
        exit;
    }
    
    // 验证管理员密码
    $adminPasswordHash = '';
    
    if (isDbConfigured()) {
        $pdo = getDbConnection();
        if ($pdo) {
            // 从数据库读取管理员密码哈希
            $stmt = $pdo->prepare("SELECT value FROM file_config WHERE `key` = 'admin_password_hash'");
            $stmt->execute();
            $result = $stmt->fetch();
            if ($result) {
                $adminPasswordHash = $result['value'];
            }
        }
    }
    
    // 检查密码是否正确
    $config = loadDbConfig();
    $storedAdminPassword = $config['admin_password'] ?? '';
    
    if (!empty($adminPasswordHash)) {
        if (!password_verify($adminPassword, $adminPasswordHash)) {
            echo json_encode(['success' => false, 'message' => '管理员密码错误']);
            exit;
        }
    } else if (!empty($storedAdminPassword)) {
        // 使用配置文件中的密码进行验证
        if ($adminPassword !== $storedAdminPassword) {
            echo json_encode(['success' => false, 'message' => '管理员密码错误']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => '管理员密码未设置，请先配置数据库']);
        exit;
    }
    
    // 存储 API Key 到数据库
    if (isDbConfigured()) {
        $pdo = getDbConnection();
        if ($pdo) {
            // 检查 API Key 是否已存在
            $stmt = $pdo->prepare("SELECT id FROM file_api_keys WHERE api_key = ?");
            $stmt->execute([$apiKey]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'API Key 已存在']);
                exit;
            }
            
            // 生成 API Key 的哈希值
            $apiKeyHash = simpleHash($apiKey);
            
            // 添加新的 API Key
            $stmt = $pdo->prepare("INSERT INTO file_api_keys (api_key, api_key_hash, name, status, created_at) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$apiKey, $apiKeyHash, $name, '未使用', date('Y-m-d H:i:s')])) {
                echo json_encode(['success' => true, 'message' => 'API Key 添加成功']);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => '保存失败，请稍后重试']);
                exit;
            }
        } else {
            // 数据库连接失败，显示错误信息
            echo json_encode(['success' => false, 'message' => '数据库连接失败，请检查数据库配置']);
            exit;
        }
    } else {
        // 数据库未配置，显示错误信息
        echo json_encode(['success' => false, 'message' => '数据库未配置，请先配置数据库']);
        exit;
    }
    
    // 兼容模式已移除，不再存储到 JSON 文件
    exit;
}

 

// 验证 API Key
if (isset($_POST['action']) && $_POST['action'] === 'verify_api_key') {
    $apiKey = $_POST['api_key'] ?? '';
    $apiKeyHash = $_POST['api_key_hash'] ?? '';
    
    if (empty($apiKeyHash)) {
        echo json_encode(['success' => false, 'message' => 'API Key hash 不能为空']);
        exit;
    }
    
    // 检查 API Key 是否有效（优先使用数据库）
    if (isDbConfigured()) {
        $pdo = getDbConnection();
        if ($pdo) {
            // 检查 API Key 是否存在
            $stmt = $pdo->prepare("SELECT id, name FROM file_api_keys WHERE api_key_hash = ? OR api_key = ?");
            $stmt->execute([$apiKeyHash, $apiKey]);
            $apiKeyInfo = $stmt->fetch();
            if ($apiKeyInfo) {
                // 不是管理员API Key，更新状态为已授权
                if ($apiKeyInfo['name'] !== '管理员') {
                    $updateStmt = $pdo->prepare("UPDATE file_api_keys SET status = '已授权' WHERE id = ?");
                    $updateStmt->execute([$apiKeyInfo['id']]);
                }
                echo json_encode(['success' => true, 'message' => 'API Key 验证成功']);
                exit;
            }
        }
    }
    
    // 检查默认 API Key (使用配置的管理员Key)
    $config = loadDbConfig();
    $defaultApiKey = $config['admin_key'] ?? '00110000';
    $defaultApiKeyHash = simpleHash($defaultApiKey);
    if ($defaultApiKeyHash === $apiKeyHash || $apiKey === $defaultApiKey) {
        // 支持完整哈希值和API Key直接匹配
        echo json_encode(['success' => true, 'message' => 'API Key 验证成功']);
        exit;
    }
    
    // 兼容模式：从 JSON 文件读取
    $authFile = __DIR__ . '/data/api_keys.json';
    $apiKeys = [];
    
    if (file_exists($authFile)) {
        $apiKeys = json_decode(file_get_contents($authFile), true);
    }
    
    // 检查 API Key 是否存在
    foreach ($apiKeys as $key) {
        if (isset($key['api_key_hash']) && $key['api_key_hash'] === $apiKeyHash) {
            echo json_encode(['success' => true, 'message' => 'API Key 验证成功']);
            exit;
        } else if (simpleHash($key['api_key']) === $apiKeyHash) {
            // 计算 API Key 的哈希值并与提供的哈希值比较
            echo json_encode(['success' => true, 'message' => 'API Key 验证成功']);
            exit;
        } else if ($key['api_key'] === $apiKey) {
            // 兼容旧格式，使用 api_key 进行匹配
            echo json_encode(['success' => true, 'message' => 'API Key 验证成功']);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'API Key 无效']);
    exit;
}

// 获取 API Key 名称
if (isset($_POST['action']) && $_POST['action'] === 'get_api_key_name') {
    $apiKey = $_POST['api_key'] ?? '';
    $apiKeyHash = $_POST['api_key_hash'] ?? '';
    
    if (empty($apiKeyHash)) {
        echo json_encode(['success' => false, 'message' => 'API Key hash 不能为空']);
        exit;
    }
    
    // 检查 API Key 是否有效（优先使用数据库）
    if (isDbConfigured()) {
        $pdo = getDbConnection();
        if ($pdo) {
            // 检查 API Key 是否存在
            $stmt = $pdo->prepare("SELECT name FROM file_api_keys WHERE api_key_hash = ? OR api_key = ?");
            $stmt->execute([$apiKeyHash, $apiKey]);
            $result = $stmt->fetch();
            if ($result) {
                echo json_encode(['success' => true, 'name' => $result['name']]);
                exit;
            }
        }
    }
    
    // 先检查是否为管理员Key
    $config = loadDbConfig();
    $defaultApiKey = $config['admin_key'] ?? '00110000';
    $defaultApiKeyHash = simpleHash($defaultApiKey);
    if ($defaultApiKeyHash === $apiKeyHash || $apiKey === $defaultApiKey) {
        // 支持完整哈希值和API Key直接匹配
        echo json_encode(['success' => true, 'name' => '管理员']);
        exit;
    }
    
    // 兼容模式：从 JSON 文件读取
    $authFile = __DIR__ . '/data/api_keys.json';
    $apiKeys = [];
    
    if (file_exists($authFile)) {
        $apiKeys = json_decode(file_get_contents($authFile), true);
    }
    
    // 检查 API Key 是否存在
    foreach ($apiKeys as $key) {
        if (isset($key['api_key_hash']) && $key['api_key_hash'] === $apiKeyHash) {
            echo json_encode(['success' => true, 'name' => $key['name']]);
            exit;
        } else if (simpleHash($key['api_key']) === $apiKeyHash) {
            // 计算 API Key 的哈希值并与提供的哈希值比较
            echo json_encode(['success' => true, 'name' => $key['name']]);
            exit;
        } else if ($key['api_key'] === $apiKey) {
            // 兼容旧格式，使用 api_key 进行匹配
            echo json_encode(['success' => true, 'name' => $key['name']]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'API Key 无效']);
    exit;
}

// 清空所有记录
if (isset($_POST['action']) && $_POST['action'] === 'clear_all_records') {
    $apiKeyHash = $_POST['api_key_hash'] ?? '';
    
    if (empty($apiKeyHash)) {
        echo json_encode(['success' => false, 'message' => 'API Key hash 不能为空']);
        exit;
    }
    
    // 优先使用数据库
    if (isDbConfigured()) {
        $pdo = getDbConnection();
        if ($pdo) {
            // 获取要删除的记录
            $stmt = $pdo->prepare("SELECT url FROM file_upload_records WHERE api_key_hash = ?");
            $stmt->execute([$apiKeyHash]);
            $recordsToDelete = $stmt->fetchAll();
            
            // 删除文件
            foreach ($recordsToDelete as $record) {
                if (isset($record['url'])) {
                    $filePath = __DIR__ . '/' . $record['url'];
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
            }
            
            // 从数据库中删除记录
            $stmt = $pdo->prepare("DELETE FROM file_upload_records WHERE api_key_hash = ?");
            $stmt->execute([$apiKeyHash]);
            
            echo json_encode(['success' => true, 'message' => '所有记录已清空']);
            exit;
        }
    }
    
    // 兼容模式：从 JSON 文件读取
    $recordFile = __DIR__ . '/data/upload_history.json';
    $records = [];
    
    if (file_exists($recordFile)) {
        $records = json_decode(file_get_contents($recordFile), true);
        // 确保 $records 是一个数组
        if (!is_array($records)) {
            $records = [];
        }
        // 如果 $records 是一个关联数组（对象），转换为索引数组
        if (isset($records[0]) && is_array($records[0])) {
            // 已经是索引数组，不需要转换
        } else {
            // 是关联数组，转换为索引数组
            $records = array_values($records);
        }
    }
    
    // 过滤出要删除的记录
    $recordsToDelete = [];
    $remainingRecords = [];
    
    foreach ($records as $record) {
        if (isset($record['api_key_hash']) && $record['api_key_hash'] === $apiKeyHash) {
            $recordsToDelete[] = $record;
        } else if (isset($record['api_key']) && simpleHash($record['api_key']) === $apiKeyHash) {
            $recordsToDelete[] = $record;
        } else if (isset($record['api_key'])) {
            // 特殊处理管理员Key
            $config = loadDbConfig();
            $adminKey = $config['admin_key'] ?? '00110000';
            if ($record['api_key'] === $adminKey && $apiKeyHash === simpleHash($adminKey)) {
                $recordsToDelete[] = $record;
            }
        } else if (isset($record['api_key_hash'])) {
            // 特殊处理管理员Key的哈希值
            $config = loadDbConfig();
            $adminKey = $config['admin_key'] ?? '00110000';
            if ($record['api_key_hash'] === simpleHash($adminKey) && $apiKeyHash === simpleHash($adminKey)) {
                $recordsToDelete[] = $record;
            }
        } else {
            $remainingRecords[] = $record;
        }
    }
    
    // 删除文件
    foreach ($recordsToDelete as $record) {
        if (isset($record['url'])) {
            $filePath = __DIR__ . '/' . $record['url'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }
    
    // 保存剩余的记录
    file_put_contents($recordFile, json_encode($remainingRecords, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    echo json_encode(['success' => true, 'message' => '所有记录已清空']);
    exit;
}

// 更新删除密码
if (isset($_POST['action']) && $_POST['action'] === 'update_delete_password') {
    $apiKey = $_POST['api_key'] ?? '';
    $deletePassword = $_POST['delete_password'] ?? '';
    
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'message' => 'API Key 不能为空']);
        exit;
    }
    
    // 优先使用数据库
    if (isDbConfigured()) {
        $pdo = getDbConnection();
        if ($pdo) {
            // 生成删除密码的哈希值
            $deletePasswordHash = !empty($deletePassword) ? password_hash($deletePassword, PASSWORD_DEFAULT) : null;
            
            // 更新 API Key 的删除密码哈希
            $stmt = $pdo->prepare("UPDATE file_api_keys SET delete_password_hash = ? WHERE api_key = ?");
            if ($stmt->execute([$deletePasswordHash, $apiKey])) {
                echo json_encode(['success' => true, 'message' => '删除密码更新成功']);
            } else {
                echo json_encode(['success' => false, 'message' => '删除密码更新失败']);
            }
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => '数据库未配置']);
    exit;
}

// 获取所有 API Key
if (isset($_POST['action']) && $_POST['action'] === 'get_all_api_keys') {
    $adminKey = $_POST['admin_key'] ?? '';
    
    // 验证管理员Key
    $config = loadDbConfig();
    $storedAdminKey = $config['admin_key'] ?? '00110000';
    
    if ($adminKey !== $storedAdminKey) {
        echo json_encode(['success' => false, 'message' => '无权限访问']);
        exit;
    }
    
    // 优先使用数据库
    if (isDbConfigured()) {
        $pdo = getDbConnection();
        if ($pdo) {
            // 获取所有 API Key
            $stmt = $pdo->prepare("SELECT api_key, name, status, created_at FROM file_api_keys ORDER BY created_at DESC");
            $stmt->execute();
            $apiKeys = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'api_keys' => $apiKeys]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => '数据库未配置']);
    exit;
}

// 删除 API Key
if (isset($_POST['action']) && $_POST['action'] === 'delete_api_key') {
    $apiKey = $_POST['api_key'] ?? '';
    $adminKey = $_POST['admin_key'] ?? '';
    
    // 验证管理员Key
    $config = loadDbConfig();
    $storedAdminKey = $config['admin_key'] ?? '00110000';
    
    if ($adminKey !== $storedAdminKey) {
        echo json_encode(['success' => false, 'message' => '无权限访问']);
        exit;
    }
    
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'message' => 'API Key 不能为空']);
        exit;
    }
    
    // 防止删除管理员API Key
    if ($apiKey === $storedAdminKey) {
        echo json_encode(['success' => false, 'message' => '管理员API Key不能删除']);
        exit;
    }
    
    // 优先使用数据库
    if (isDbConfigured()) {
        $pdo = getDbConnection();
        if ($pdo) {
            // 删除 API Key
            $stmt = $pdo->prepare("DELETE FROM file_api_keys WHERE api_key = ?");
            if ($stmt->execute([$apiKey])) {
                echo json_encode(['success' => true, 'message' => 'API Key 删除成功']);
            } else {
                echo json_encode(['success' => false, 'message' => 'API Key 删除失败']);
            }
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => '数据库未配置']);
    exit;
}

// 清理过期文件
if (isset($_POST['action']) && $_POST['action'] === 'clean_expired_files') {
    // 调用清理过期文件函数
    cleanExpiredFiles();
    echo json_encode(['success' => true, 'message' => '过期文件清理成功']);
    exit;
}

// 获取配置信息
if (isset($_POST['action']) && $_POST['action'] === 'get_config') {
    if (!isDbConfigured()) {
        echo json_encode(['success' => true, 'expiry_days' => 30]);
        exit;
    }
    
    $pdo = getDbConnection();
    if (!$pdo) {
        echo json_encode(['success' => true, 'expiry_days' => 30]);
        exit;
    }
    
    // 从数据库读取过期时间设置
    $stmt = $pdo->prepare("SELECT value FROM file_config WHERE `key` = 'expiry_days'");
    $stmt->execute();
    $result = $stmt->fetch();
    
    // 设置默认过期时间为30天
    $expiryDays = $result ? $result['value'] : 30;
    
    echo json_encode(['success' => true, 'expiry_days' => $expiryDays]);
    exit;
}

// 更新上传历史记录
function updateUploadHistory($urlToDelete) {
    if (!isDbConfigured()) {
        return;
    }
    
    $pdo = getDbConnection();
    if (!$pdo) {
        return;
    }
    
    // 从数据库中删除记录
    $stmt = $pdo->prepare("DELETE FROM file_upload_records WHERE url = ?");
    $stmt->execute([$urlToDelete]);
}



// 格式化文件大小
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unitIndex = 0;
    
    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }
    
    return round($size, 2) . ' ' . $units[$unitIndex];
}

// 清理过期文件
function cleanExpiredFiles($days = null) {
    if (!isDbConfigured()) {
        return;
    }
    
    $pdo = getDbConnection();
    if (!$pdo) {
        return;
    }
    
    // 从数据库读取过期时间设置
    $stmt = $pdo->prepare("SELECT value FROM file_config WHERE `key` = 'expiry_days'");
    $stmt->execute();
    $result = $stmt->fetch();
    
    // 如果没有设置过期时间，使用默认值30天
    if ($days === null) {
        $days = $result ? $result['value'] : 30;
    }
    
    $expiryDate = date('Y-m-d H:i:s', strtotime("-$days days"));
    
    // 获取过期记录
    $stmt = $pdo->prepare("SELECT url FROM file_upload_records WHERE date < ?");
    $stmt->execute([$expiryDate]);
    $expiredRecords = $stmt->fetchAll();
    
    // 删除过期文件
    foreach ($expiredRecords as $record) {
        if (isset($record['url'])) {
            $filePath = __DIR__ . '/' . $record['url'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }
    
    // 从数据库中删除过期记录
    $stmt = $pdo->prepare("DELETE FROM file_upload_records WHERE date < ?");
    $stmt->execute([$expiryDate]);
}

// 保存上传记录
function saveUploadRecord($category, $filename, $originalFilename, $size, $url, $remark, $apiKey = '') {
    if (!isDbConfigured()) {
        throw new Exception('数据库未配置');
    }
    
    $pdo = getDbConnection();
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    try {
        // 获取 API Key 对应的名称
        $apiKeyName = '';
        $stmt = $pdo->prepare("SELECT name FROM file_api_keys WHERE api_key = ?");
        $stmt->execute([$apiKey]);
        $result = $stmt->fetch();
        if ($result) {
            $apiKeyName = $result['name'];
        }
        
        // 检查管理员Key
        $config = loadDbConfig();
        $adminKey = $config['admin_key'] ?? '00110000';
        if ($apiKey === $adminKey) {
            $apiKeyName = '管理员';
        }
        
        // 生成 API Key 的哈希值，用于过滤记录
        $apiKeyHash = simpleHash($apiKey);
        
        // 插入上传记录
        $stmt = $pdo->prepare("INSERT INTO file_upload_records (filename, original_filename, category, size, url, remark, api_key_hash, api_key_name, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt->execute([
            $filename,
            $originalFilename,
            $category,
            formatFileSize($size),
            $url,
            $remark,
            $apiKeyHash,
            $apiKeyName,
            date('Y-m-d H:i:s')
        ])) {
            throw new Exception('保存上传记录失败');
        }
    } catch (PDOException $e) {
        throw new Exception('数据库操作失败: ' . $e->getMessage());
    }
}

// 获取上传记录
function getUploadRecords($apiKey = '', $apiKeyHash = '') {
    if (!isDbConfigured()) {
        return [];
    }
    
    $pdo = getDbConnection();
    if (!$pdo) {
        return [];
    }
    
    $records = [];
    
    // 按API Key哈希值过滤记录
    if (!empty($apiKeyHash)) {
        $stmt = $pdo->prepare("SELECT * FROM file_upload_records WHERE api_key_hash = ? ORDER BY date DESC");
        $stmt->execute([$apiKeyHash]);
        $records = $stmt->fetchAll();
    } else {
        // 未授权状态，返回空记录
        return [];
    }
    
    return $records;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文件分享 QR File Transfer Platform</title>
   
  <script src="css/4.0.7.js"></script>
 <link href="css/css/font-awesome.min.css" rel="stylesheet">
       <!-- 
 <script src="http://alidll.com/pdf/css/4.0.7.js"></script>
          <script src="http://alidll.com/pdf/css/qrcode.min.js"></script>
       <link href="http://alidll.com/pdf/css/css/font-awesome.min.css" rel="stylesheet">
  




  <script src="https://cdn.tailwindcss.com"></script>
        <link href="css/css/fontawesome.min.css" rel="stylesheet">

           <script src="https://cdn.tailwindcss.com"></script>
        <link href="css/css/fontawesome.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css" rel="stylesheet">
-->
    <!--
       <link href="http://alidll.com/pdf/css/css/font-awesome.min.css" rel="stylesheet">
    
-->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3b82f6',
                        secondary: '#64748b',
                        success: '#10b981',
                        danger: '#ef4444',
                        dark: '#1e293b',
                        light: '#f8fafc',
                        accent: '#8b5cf6',
                        info: '#06b6d4',
                        warning: '#f59e0b'
                    },
                    fontFamily: {
                        inter: ['Inter', 'system-ui', 'sans-serif']
                    },
                    boxShadow: {
                        'card': '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)',
                        'card-hover': '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)'
                    }
                }
            }
        }
        
        // 显示提示信息
        function showToast(message, type = 'info') {
            // 创建提示元素
            const toast = document.createElement('div');
            toast.className = 'bg-white rounded-lg shadow-lg p-4 flex items-center space-x-3 animate-fadeIn transform transition-all duration-300';
            
            // 设置图标
            let iconClass = '';
            if (type === 'success') {
                iconClass = 'fa fa-check-circle text-success';
            } else if (type === 'error') {
                iconClass = 'fa fa-exclamation-circle text-danger';
            } else {
                iconClass = 'fa fa-info-circle text-primary';
            }
            
            // 设置内容
            toast.innerHTML = `
                <i class="${iconClass}"></i>
                <span class="text-sm">${message}</span>
            `;
            
            // 添加到容器
            const toastContainer = document.getElementById('toast-container');
            toastContainer.appendChild(toast);
            
            // 3秒后自动关闭
            setTimeout(() => {
                // 添加关闭动画
                toast.classList.add('opacity-0', 'translate-x-10');
                // 动画结束后移除元素
                setTimeout(() => {
                    toastContainer.removeChild(toast);
                }, 300);
            }, 3000);
        }

        // 检查数据库配置状态
        // 检查是否已登录
        function checkAuth() {
            const apiKey = sessionStorage.getItem('apiKey') || localStorage.getItem('apiKey');
            const apiKeyHash = localStorage.getItem('apiKeyHash');
            if (!apiKey || !apiKeyHash) return false;
            
            // 验证 API Key 是否有效
            return true; // 实际验证会在服务器端进行
        }
        
        // 简单哈希函数（与PHP端保持一致）
        function simpleHash(str) {
            let hash = 0;
            const length = str.length;
            for (let i = 0; i < length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & 0xFFFFFFFF; // 确保32位整数
            }
            // 确保哈希值为正数
            if (hash < 0) {
                hash = Math.abs(hash);
            }
            return hash.toString(16);
        }
        
        // 全局变量
        let unauthorizedModal;
        
        // 加载API Key列表
        // API Key 分页相关变量
        let currentApiKeysPage = 1;
        const apiKeysPerPage = 10;
        
        function loadApiKeys(page = 1) {
            const apiKeysTableBody = document.getElementById('api-keys-table-body');
            const paginationContainer = document.getElementById('api-keys-pagination');
            if (!apiKeysTableBody) {
                console.error('apiKeysTableBody元素未找到');
                return;
            }
            
            const apiKey = localStorage.getItem('apiKey') || sessionStorage.getItem('apiKey');
            if (!apiKey) {
                apiKeysTableBody.innerHTML = '<tr><td colspan="4" class="py-4 px-4 border-b border-gray-200 text-center text-gray-500">请先登录</td></tr>';
                if (paginationContainer) {
                    paginationContainer.innerHTML = '';
                }
                return;
            }
            
            apiKeysTableBody.innerHTML = '<tr><td colspan="5" class="py-4 px-4 border-b border-gray-200 text-center text-gray-500">加载中...</td></tr>';
            if (paginationContainer) {
                paginationContainer.innerHTML = '<div class="text-gray-500">加载中...</div>';
            }
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=get_all_api_keys&admin_key=${encodeURIComponent(apiKey)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.api_keys.length === 0) {
                        apiKeysTableBody.innerHTML = '<tr><td colspan="5" class="py-4 px-4 border-b border-gray-200 text-center text-gray-500">暂无API Key</td></tr>';
                        if (paginationContainer) {
                            paginationContainer.innerHTML = '';
                        }
                    } else {
                        // 分页逻辑
                        currentApiKeysPage = page;
                        const startIndex = (page - 1) * apiKeysPerPage;
                        const endIndex = startIndex + apiKeysPerPage;
                        const paginatedKeys = data.api_keys.slice(startIndex, endIndex);
                        const totalPages = Math.ceil(data.api_keys.length / apiKeysPerPage);
                        
                        let html = '';
                        paginatedKeys.forEach(key => {
                            let deleteButton = '';
                            if (key.name !== '管理员') {
                                deleteButton = `
                                    <button class="text-danger hover:text-red-700 delete-api-key-btn" data-api-key="${key.api_key}">
                                        <i class="fa fa-trash"></i> 删除
                                    </button>
                                `;
                            }
                            // 处理使用状态，默认为未使用
                            let status = key.status || '未使用';
                            let statusClass = '';
                            // 管理员API Key显示为system
                            if (key.name === '管理员') {
                                status = 'system';
                                statusClass = 'text-blue-600 font-semibold';
                            } else if (status === '已授权') {
                                statusClass = 'text-green-600';
                            } else if (status === '未使用') {
                                statusClass = 'text-yellow-600';
                            } else {
                                statusClass = 'text-red-600';
                            }
                            html += `
                                <tr>
                                    <td class="py-2 px-4 border-b border-gray-200">
                                        <div class="flex items-center">
                                            <span>${key.api_key}</span>
                                            <button class="ml-2 bg-white text-gray-600 hover:bg-gray-100 px-2 py-1 rounded copy-api-key-btn" data-api-key="${key.api_key}">
                                                <i class="fa fa-copy"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="py-2 px-4 border-b border-gray-200">${key.name}</td>
                                    <td class="py-2 px-4 border-b border-gray-200 ${statusClass}">${status}</td>
                                    <td class="py-2 px-4 border-b border-gray-200">${key.created_at}</td>
                                    <td class="py-2 px-4 border-b border-gray-200">
                                        ${deleteButton}
                                    </td>
                                </tr>
                            `;
                        });
                        apiKeysTableBody.innerHTML = html;
                        
                        // 生成分页控件
                        if (paginationContainer) {
                            generateApiKeysPagination(totalPages, page);
                        }
                        
                        // 添加删除事件监听
                        document.querySelectorAll('.delete-api-key-btn').forEach(btn => {
                            btn.addEventListener('click', function() {
                                const apiKeyToDelete = this.getAttribute('data-api-key');
                                showDeleteApiKeyConfirm(apiKeyToDelete);
                            });
                        });
                        
                        // 添加复制API Key事件监听
                        document.querySelectorAll('.copy-api-key-btn').forEach(btn => {
                            btn.addEventListener('click', function() {
                                const apiKey = this.getAttribute('data-api-key');
                                if (!apiKey) {
                                    showToast('API Key不存在', 'error');
                                    return;
                                }
                                
                                // 尝试使用现代API复制
                                if (navigator.clipboard && window.isSecureContext) {
                                    navigator.clipboard.writeText(apiKey).then(() => {
                                        showToast('API Key已复制到剪贴板', 'success');
                                    }).catch(err => {
                                        console.error('剪贴板API错误:', err);
                                        // 回退到传统方法
                                        fallbackCopyTextToClipboard(apiKey);
                                    });
                                } else {
                                    // 回退到传统方法
                                    fallbackCopyTextToClipboard(apiKey);
                                }
                            });
                        });
                        

                        

                        
                        // 传统复制方法（兼容旧浏览器）
                        function fallbackCopyTextToClipboard(text) {
                            const textArea = document.createElement('textarea');
                            textArea.value = text;
                            
                            // 确保文本区域不在屏幕上可见
                            textArea.style.position = 'fixed';
                            textArea.style.left = '-999999px';
                            textArea.style.top = '-999999px';
                            document.body.appendChild(textArea);
                            
                            // 选择文本
                            textArea.focus();
                            textArea.select();
                            
                            try {
                                // 执行复制命令
                                const successful = document.execCommand('copy');
                                if (successful) {
                                    showToast('API Key已复制到剪贴板', 'success');
                                } else {
                                    showToast('复制失败，请手动复制', 'error');
                                }
                            } catch (err) {
                                console.error('传统复制方法错误:', err);
                                showToast('复制失败，请手动复制', 'error');
                            } finally {
                                // 清理
                                document.body.removeChild(textArea);
                            }
                        }
                    }
                } else {
                    apiKeysTableBody.innerHTML = '<tr><td colspan="5" class="py-4 px-4 border-b border-gray-200 text-center text-red-500">' + data.message + '</td></tr>';
                    if (paginationContainer) {
                        paginationContainer.innerHTML = '';
                    }
                }
            })
            .catch(error => {
                console.error('加载API Key失败:', error);
                apiKeysTableBody.innerHTML = '<tr><td colspan="5" class="py-4 px-4 border-b border-gray-200 text-center text-red-500">加载失败</td></tr>';
                if (paginationContainer) {
                    paginationContainer.innerHTML = '';
                }
            });
        }
        
        // 生成API Key分页控件
        function generateApiKeysPagination(totalPages, currentPage) {
            const paginationContainer = document.getElementById('api-keys-pagination');
            if (!paginationContainer) return;
            
            let paginationHtml = '';
            
            if (totalPages > 1) {
                paginationHtml = `
                    <nav class="flex items-center space-x-1">
                        <button class="page-btn px-3 py-1 rounded-md border border-gray-300 ${currentPage === 1 ? 'bg-gray-100 cursor-not-allowed' : 'hover:bg-gray-50'}" ${currentPage === 1 ? 'disabled' : 'data-page="' + (currentPage - 1) + '"'}>
                            <i class="fa fa-chevron-left"></i>
                        </button>
                `;
                
                // 生成页码按钮
                for (let i = 1; i <= totalPages; i++) {
                    if (i <= 3 || i >= totalPages - 2 || (i >= currentPage - 1 && i <= currentPage + 1)) {
                        paginationHtml += `
                            <button class="page-btn px-3 py-1 rounded-md border border-gray-300 ${i === currentPage ? 'bg-primary text-white' : 'hover:bg-gray-50'}" data-page="${i}">
                                ${i}
                            </button>
                        `;
                    } else if (i === 4 || i === totalPages - 3) {
                        paginationHtml += `
                            <span class="px-3 py-1">...</span>
                        `;
                    }
                }
                
                paginationHtml += `
                        <button class="page-btn px-3 py-1 rounded-md border border-gray-300 ${currentPage === totalPages ? 'bg-gray-100 cursor-not-allowed' : 'hover:bg-gray-50'}" ${currentPage === totalPages ? 'disabled' : 'data-page="' + (currentPage + 1) + '"'}>
                            <i class="fa fa-chevron-right"></i>
                        </button>
                    </nav>
                `;
            }
            
            paginationContainer.innerHTML = paginationHtml;
            
            // 添加分页按钮点击事件
            document.querySelectorAll('#api-keys-pagination .page-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const page = parseInt(this.getAttribute('data-page'));
                    if (!isNaN(page)) {
                        loadApiKeys(page);
                    }
                });
            });
        }
        
        // 显示删除API Key确认模态框
        function showDeleteApiKeyConfirm(apiKeyToDelete) {
            const alertModal = document.getElementById('alert-modal');
            const alertContent = document.getElementById('alert-content');
            const alertCloseBtn = document.getElementById('alert-close-btn');
            
            alertContent.innerHTML = `
                <p class="mb-4">确定要删除此API Key吗？</p>
                <p class="text-sm text-gray-600">此操作不可撤销，删除后将无法恢复。</p>
            `;
            alertCloseBtn.textContent = '确认删除';
            
            // 添加取消按钮
            const modalFooter = alertModal.querySelector('.border-t');
            let cancelBtn = modalFooter.querySelector('.btn-secondary');
            if (!cancelBtn) {
                cancelBtn = document.createElement('button');
                cancelBtn.className = 'btn btn-secondary px-5 py-1.5 text-sm mr-3';
                cancelBtn.textContent = '取消';
                cancelBtn.addEventListener('click', function() {
                    alertModal.style.display = 'none';
                });
                modalFooter.insertBefore(cancelBtn, alertCloseBtn);
            } else {
                cancelBtn.style.display = 'inline-block';
            }
            
            // 确保alert-modal显示在最上方
            alertModal.style.zIndex = '9999';
            alertModal.style.position = 'fixed';
            alertModal.style.top = '0';
            alertModal.style.left = '0';
            alertModal.style.width = '100%';
            alertModal.style.height = '100%';
            alertModal.style.display = 'flex';
            alertModal.style.alignItems = 'center';
            alertModal.style.justifyContent = 'center';
            alertModal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
            alertModal.style.pointerEvents = 'auto';
            alertModal.style.zIndex = '9999';
            
            // 确认删除按钮点击事件
            alertCloseBtn.onclick = function() {
                deleteApiKey(apiKeyToDelete);
                alertModal.style.display = 'none';
            };
        }
        
        // 删除API Key
        function deleteApiKey(apiKey) {
            const adminKey = localStorage.getItem('apiKey') || sessionStorage.getItem('apiKey');
            if (!adminKey) {
                showToast('请先登录', 'error');
                return;
            }
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=delete_api_key&api_key=${encodeURIComponent(apiKey)}&admin_key=${encodeURIComponent(adminKey)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('API Key删除成功', 'success');
                    loadApiKeys();
                } else {
                    showToast('API Key删除失败: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('删除API Key失败:', error);
                showToast('删除失败，请检查网络连接', 'error');
            });
        }
        
        // 显示/隐藏管理员菜单选项
        function checkAdminStatus() {
      
            const apiKey = localStorage.getItem('apiKey') || sessionStorage.getItem('apiKey');
          
            const addKeyBtn = document.getElementById('add-key-btn');
            const manageKeyBtn = document.getElementById('manage-key-btn');
            if (apiKey && addKeyBtn && manageKeyBtn) {
                const apiKeyHash = simpleHash(apiKey);
              
                // 直接获取API Key名称，不再重复验证
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=get_api_key_name&api_key=${encodeURIComponent(apiKey)}&api_key_hash=${encodeURIComponent(apiKeyHash)}`
                })
                .then(response => response.json())
                .then(data => {
              
                    if (data.success && data.name === '管理员') {
                        
                        addKeyBtn.classList.remove('hidden');
                        manageKeyBtn.classList.remove('hidden');
                    } else {
               
                        addKeyBtn.classList.add('hidden');
                        manageKeyBtn.classList.add('hidden');
                    }
                    
                    // 同时更新页脚API Key名称，避免重复请求
                    const footerApiKeyNameElement = document.getElementById('footer-api-key-name');
                    if (footerApiKeyNameElement) {
                        if (data.success && data.name) {
                            footerApiKeyNameElement.textContent = `当前用户：${data.name}`;
                        } else {
                            footerApiKeyNameElement.textContent = '';
                        }
                    }
                })
                .catch(error => {
                
                    if (addKeyBtn) {
                        addKeyBtn.classList.add('hidden');
                    }
                    if (manageKeyBtn) {
                        manageKeyBtn.classList.add('hidden');
                    }
                    
                    // 同时更新页脚API Key名称
                    const footerApiKeyNameElement = document.getElementById('footer-api-key-name');
                    if (footerApiKeyNameElement) {
                        footerApiKeyNameElement.textContent = '';
                    }
                });
            } else {
               
                if (addKeyBtn) {
                    addKeyBtn.classList.add('hidden');
                }
                if (manageKeyBtn) {
                    manageKeyBtn.classList.add('hidden');
                }
                
                // 同时更新页脚API Key名称
                const footerApiKeyNameElement = document.getElementById('footer-api-key-name');
                if (footerApiKeyNameElement) {
                    footerApiKeyNameElement.textContent = '';
                }
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化全局变量
            unauthorizedModal = document.getElementById('unauthorized-modal');
            // 数据库配置模态框相关元素
            const dbConfigModal = document.getElementById('database-config-modal');
            const closeDbConfigBtn = document.getElementById('close-database-config-btn');
            const cancelDbConfigBtn = document.getElementById('cancel-database-config-btn');
            const saveDbConfigBtn = document.getElementById('save-database-config-btn');
            // 管理员功能
            const adminModal = document.getElementById('admin-modal');
            const closeAdminModalBtn = document.getElementById('close-admin-modal-btn');
            const closeAdminModalBtn2 = document.getElementById('close-admin-modal-btn2');
            const apiKeysTableBody = document.getElementById('api-keys-table-body');
            
            // 关闭数据库配置模态框
            if (closeDbConfigBtn) {
                closeDbConfigBtn.addEventListener('click', function() {
                    dbConfigModal.style.display = 'none';
                });
            }
            
            if (cancelDbConfigBtn) {
                cancelDbConfigBtn.addEventListener('click', function() {
                    dbConfigModal.style.display = 'none';
                });
            }
            
            // 测试数据库连接
            const testDbConnectionBtn = document.getElementById('test-db-connection-btn');
            if (testDbConnectionBtn) {
                testDbConnectionBtn.addEventListener('click', function() {
                    const host = document.getElementById('db-host').value;
                    const username = document.getElementById('db-username').value;
                    const password = document.getElementById('db-password').value;
                    const database = document.getElementById('db-name').value;
                    const port = document.getElementById('db-port').value;
                    
                    // 验证参数
                    if (!host) {
                        showToast('请填写数据库主机', 'error');
                        return;
                    }
                    if (!username) {
                        showToast('请填写数据库用户名', 'error');
                        return;
                    }
                    if (!password) {
                        showToast('请填写数据库密码', 'error');
                        return;
                    }
                    if (!database) {
                        showToast('请填写数据库名称', 'error');
                        return;
                    }
                    if (!port) {
                        showToast('请填写数据库端口', 'error');
                        return;
                    }
                    
                    // 显示加载状态
                    testDbConnectionBtn.disabled = true;
                    testDbConnectionBtn.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i> 测试中...';
                    
                    // 发送测试请求
                    fetch('?action=test_db_connection', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `host=${encodeURIComponent(host)}&username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}&database=${encodeURIComponent(database)}&port=${encodeURIComponent(port)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        // 恢复按钮状态
                        testDbConnectionBtn.disabled = false;
                        testDbConnectionBtn.innerHTML = '测试连接';
                        
                        if (data.success) {
                            // 显示成功提示
                            showToast('数据库连接成功！', 'success');
                        } else {
                            // 显示错误提示
                            showToast('数据库连接失败: ' + data.message, 'error');
                        }
                    })
                    .catch(error => {
                        // 恢复按钮状态
                        testDbConnectionBtn.disabled = false;
                        testDbConnectionBtn.innerHTML = '测试连接';
                        
                        console.error('数据库连接失败:', error);
                        showToast('数据库连接失败，请检查网络连接', 'error');
                    });
                });
            }
            
            // 保存数据库配置
            if (saveDbConfigBtn) {
                saveDbConfigBtn.addEventListener('click', function() {
                    const host = document.getElementById('db-host').value;
                    const username = document.getElementById('db-username').value;
                    const password = document.getElementById('db-password').value;
                    const database = document.getElementById('db-name').value;
                    const port = document.getElementById('db-port').value;
                    const adminKey = document.getElementById('admin-key').value;
                    const adminPassword = document.getElementById('db-admin-password').value;
                    
                    // 验证参数
                    if (!host) {
                        showToast('请填写数据库主机', 'error');
                        return;
                    }
                    if (!username) {
                        showToast('请填写数据库用户名', 'error');
                        return;
                    }
                    if (!password) {
                        showToast('请填写数据库密码', 'error');
                        return;
                    }
                    if (!database) {
                        showToast('请填写数据库名称', 'error');
                        return;
                    }
                    if (!port) {
                        showToast('请填写数据库端口', 'error');
                        return;
                    }
                    if (!adminKey) {
                        showToast('请填写管理员Key', 'error');
                        return;
                    }
                    if (!adminPassword) {
                        showToast('请填写管理员密码', 'error');
                        return;
                    }
                    
                    // 显示加载状态
                    saveDbConfigBtn.disabled = true;
                    saveDbConfigBtn.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i> 保存中...';
                    
                    // 发送配置请求
                    fetch('?action=save_db_config', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `host=${encodeURIComponent(host)}&username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}&database=${encodeURIComponent(database)}&port=${encodeURIComponent(port)}&admin_key=${encodeURIComponent(adminKey)}&admin_password=${encodeURIComponent(adminPassword)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        // 恢复按钮状态
                        saveDbConfigBtn.disabled = false;
                        saveDbConfigBtn.innerHTML = '保存配置';
                        
                        if (data.success) {
                            // 显示成功提示
                            showToast(data.message, 'success');
                            // 重新加载页面
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            // 显示错误提示
                            showToast('数据库配置失败: ' + data.message, 'error');
                        }
                    })
                    .catch(error => {
                        // 恢复按钮状态
                        saveDbConfigBtn.disabled = false;
                        saveDbConfigBtn.innerHTML = '保存配置';
                        
                        console.error('保存数据库配置失败:', error);
                        showToast('保存数据库配置失败，请检查网络连接', 'error');
                    });
                });
            }
            
            // 加载API Key列表函数已移至全局作用域
            // 显示删除API Key确认模态框函数已移至全局作用域
            // 删除API Key函数已移至全局作用域
            

            

            
            // 关闭管理员模态框
            if (closeAdminModalBtn) {
                closeAdminModalBtn.addEventListener('click', function() {
                    adminModal.style.display = 'none';
                });
            }
            
            if (closeAdminModalBtn2) {
                closeAdminModalBtn2.addEventListener('click', function() {
                    adminModal.style.display = 'none';
                });
            }
            
            // 监听登录状态变化
            window.addEventListener('storage', function(e) {
                if (e.key === 'apiKey' && e.newValue) {
                    // 避免在登录过程中重复调用
                    setTimeout(() => {
                        checkAdminStatus();
                    }, 100);
                }
            });
            
            // 页面加载时检查管理员状态
            setTimeout(() => {
                checkAdminStatus();
            }, 100);
        });
    </script>
    <style type="text/tailwindcss">
        @layer utilities {
            .content-auto {
                content-visibility: auto;
            }
            .upload-area {
                @apply border-2 border-dashed border-gray-300 rounded-lg p-8 text-center transition-all duration-300;
            }
            .upload-area:hover {
                @apply border-primary bg-blue-50;
            }
            .upload-area.active {
                @apply border-success bg-green-50;
            }
            .card {
                @apply bg-white rounded-xl shadow-card p-6 transition-all duration-300;
            }
            .card:hover {
                @apply shadow-card-hover transform -translate-y-1;
            }
            .btn {
                @apply px-4 py-2 rounded-lg font-medium transition-all duration-300 flex items-center justify-center;
            }
            .btn-primary {
                @apply bg-primary text-white hover:bg-blue-600 hover:shadow-lg transform hover:-translate-y-0.5;
            }
            .btn-secondary {
                @apply bg-secondary text-white hover:bg-gray-600 hover:shadow-md transform hover:-translate-y-0.5;
            }
            .btn-danger {
                @apply bg-danger text-white hover:bg-red-600 hover:shadow-md transform hover:-translate-y-0.5;
            }
            .input-field {
                @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-300;
            }
            .input-field:focus {
                @apply shadow-md;
            }
            .form-label {
                @apply block text-sm font-medium text-gray-700 mb-2 transition-colors duration-200;
            }
            .form-label:hover {
                @apply text-primary;
            }
            .file-preview {
                @apply transition-all duration-300 hover:scale-105;
            }
            .selected-files {
                @apply transition-all duration-300 animate-fadeIn;
            }
            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            .animate-fadeIn {
                animation: fadeIn 0.3s ease-in-out;
            }
            .header-gradient {
                background: linear-gradient(135deg, #034c88ff 0%, #60708dff 100%);
            }
        }
        
        /* 响应式设计：当屏幕宽度小于600px时隐藏关于系统部分 */
        @media (max-width: 900px) {
            #about {
                display: none;
            }
            
            /* DDNS更新模态框响应式优化 */
            #ddns-modal .max-w-3xl {
                max-width: 95% !important;
                margin: 0 10px !important;
            }
            
            #ddns-config-modal .max-w-2xl {
                max-width: 95% !important;
                margin: 0 10px !important;
            }
            
            #ddns-modal .p-6,
            #ddns-config-modal .p-6 {
                padding: 1rem !important;
            }
            
            #ddns-modal .mb-6,
            #ddns-config-modal .mb-8 {
                margin-bottom: 1rem !important;
            }
            
            #ddns-modal .px-6,
            #ddns-config-modal .px-6 {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }
            
            #ddns-modal .py-3,
            #ddns-config-modal .py-3 {
                padding-top: 0.75rem !important;
                padding-bottom: 0.75rem !important;
            }
            
            /* 按钮响应式调整 */
            #ddns-config-btn,
            #ddns-update-btn {
                font-size: 0.75rem !important;
                padding: 0.625rem !important;
                flex: 1 !important;
                min-width: 0 !important;
            }
            
            /* 按钮容器调整 */
            #ddns-modal .flex.flex-col.sm\:flex-row.gap-4 {
                flex-direction: row !important;
                gap: 0.5rem !important;
                flex-wrap: nowrap !important;
            }
            
            /* 按钮文本调整 */
            #ddns-config-btn,
            #ddns-update-btn {
                font-size: 0.75rem !important;
            }
            
            /* 按钮图标调整 */
            #ddns-config-btn i,
            #ddns-update-btn i {
                font-size: 0.75rem !important;
                margin-right: 0.5rem !important;
            }
            
            /* 表单元素响应式调整 */
            #ddns-access-key-id,
            #ddns-access-key-secret,
            #ddns-domain-name,
            #ddns-rr,
            #ddns-record-type {
                padding: 0.75rem !important;
                font-size: 0.875rem !important;
            }
            
            /* 自动更新设置响应式调整 */
            #ddns-auto-update-toggle {
                transform: scale(0.8) !important;
            }
            
            #ddns-update-interval {
                font-size: 0.75rem !important;
                padding: 0.5rem !important;
            }
            
            /* 状态信息响应式调整 */
            #ddns-update-status,
            #ddns-auto-update-status {
                font-size: 0.75rem !important;
            }
            
            /* 刷新IP按钮只显示图标 */
            #ddns-refresh-ip-btn {
                padding: 0.75rem !important;
                min-width: 40px !important;
                width: 40px !important;
                height: 40px !important;
                justify-content: center !important;
                font-size: 0 !important;
                display: flex !important;
                align-items: center !important;
            }
            
            #ddns-refresh-ip-btn i {
                font-size: 16px !important;
            }
            
            /* 拼图模态框响应式优化 */
            #puzzle-modal .bg-white {
                width: 95% !important;
                max-width: 95% !important;
                margin: 0 10px !important;
            }
            
            #puzzle-modal .p-4 {
                padding: 1rem !important;
            }
            
            #puzzle-modal .mb-4 {
                margin-bottom: 1rem !important;
            }
            
            #puzzle-modal .btn {
                font-size: 0.75rem !important;
                padding: 0.625rem !important;
            }
            
            #puzzle-modal .input-field {
                padding: 0.75rem !important;
                font-size: 0.875rem !important;
            }
            
            #puzzle-modal #puzzle-selected-files {
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 0.5rem !important;
            }
            
            #puzzle-modal #puzzle-preview {
                max-height: 200px !important;
            }
            
            #puzzle-modal .flex.justify-end.space-x-3 {
                flex-wrap: wrap !important;
                gap: 0.5rem !important;
            }
            
            #puzzle-modal .flex.justify-end.space-x-3 button {
                flex: 1 !important;
                min-width: 80px !important;
            }
            
            /* 上传文件模态框响应式优化 */
            #upload-modal .bg-white {
                width: 95% !important;
                max-width: 95% !important;
                margin: 0 10px !important;
            }
            
            #upload-modal .p-4 {
                padding: 1rem !important;
            }
            
            #upload-modal .mb-4 {
                margin-bottom: 1rem !important;
            }
            
            #upload-modal .btn {
                font-size: 0.75rem !important;
                padding: 0.625rem !important;
            }
            
            #upload-modal .input-field {
                padding: 0.75rem !important;
                font-size: 0.875rem !important;
            }
            
            #upload-modal #modal-selected-files {
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 0.5rem !important;
            }
            
            #upload-modal .flex.justify-end.space-x-3 {
                flex-wrap: wrap !important;
                gap: 0.5rem !important;
            }
            
            #upload-modal .flex.justify-end.space-x-3 button {
                flex: 1 !important;
                min-width: 80px !important;
            }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    <!-- 导航栏 -->
    <header class="header-gradient text-white shadow-md fixed top-0 left-0 right-0 z-50">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <h1 class="text-2xl flex items-center" style="font-weight: 100;">
                <i class="fa fa-cloud-upload mr-2"></i>
                文件分享
            </h1>
            <nav>
                <ul class="flex space-x-6">
                    <!--
                    <li><a href="#" class="font-medium hover:bg-blue-700 px-3 py-2 rounded-lg transition-all duration-300 flex items-center">首页</a></li>
    -->

                    <li><a  href="#"  class="font-medium hover:bg-blue-700 px-3 py-2 rounded-lg transition-all duration-300 flex items-center" id="upload-modal-btn"  >上传</a></li>


                    <li><a   href="#"  class="font-medium hover:bg-blue-700 px-3 py-2 rounded-lg transition-all duration-300 flex items-center" id="puzzle-modal-btn"  >拼图</a></li>

                       




                    <!--  <li><a href="#uploads" class="hover:bg-blue-700 px-3 py-2 rounded-lg transition-all duration-300">上传记录</a></li>  -->
                    <!--   <li><a href="#about" class="hover:bg-blue-700 px-3 py-2 rounded-lg transition-all duration-300">关于</a></li>  -->
                    <li class="relative">

                    
                        <a href="#" class="font-medium hover:bg-blue-700 px-3 py-2 rounded-lg transition-all duration-300 flex items-center" id="login-modal-btn">
                            <span id="auth-status"><i class="fa fa-sign-in mr-1">登录</i></span>
                            
                        </a>
                        <div class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg z-50 hidden" id="auth-dropdown">
                            <div class="py-2">
                                <a href="#" class="block px-4 py-2 text-gray-800 hover:bg-gray-100" id="auth-info"><i class="fa fa-key mr-4"></i>用户信息</a>
                                <a href="#" class="block px-4 py-2 text-gray-800 hover:bg-gray-100" id="update-ddns-btn"><i class="fa fa-refresh mr-4"></i>更新DDNS</a>
                                <a href="#" class="block px-4 py-2 text-gray-800 hover:bg-gray-100" id="clear-all-btn"><i class="fa fa-trash mr-4"></i>全部清空</a>
                                <a href="#" class="block px-4 py-2 text-gray-800 hover:bg-gray-100" id="clean-expired-btn"><i class="fa fa-clock-o mr-4"></i>清理过期文件</a>
                                <a href="#" class="block px-4 py-2 text-gray-800 hover:bg-gray-100 hidden" id="add-key-btn"><i class="fa fa-plus mr-4"></i>添加KEY</a>
                                 <a href="#" class="block px-4 py-2 text-gray-800 hover:bg-gray-100 hidden" id="manage-key-btn"><i class="fa fa-cog mr-4"></i>用户管理</a>
                                <div class="border-t border-gray-200 my-1"></div>
                                <a href="#" class="block px-4 py-2 text-red-600 hover:bg-gray-100" id="logout-btn"><i class="fa fa-sign-out mr-4"></i>退出登录</a>
                            </div>
                        </div>
                    </li>

                </ul>
            </nav>
        </div>
    </header>
    <!-- 导航栏占位 -->
    <div class="h-16"></div>

    <!-- 主要内容 -->
    <main class="flex-grow container mx-auto px-4 py-8">
        <!-- 上传区域 -->
       

        <!-- 上传模态框 -->
        <div id="upload-modal" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-50 z-40" style="display: none; align-items: center; justify-content: center;">
            <div class="bg-white rounded-xl shadow-lg max-h-[90vh] overflow-y-auto" style="width: 90%; max-width: 500px; margin: 0 auto;">
                <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-dark">上传文件</h3>
                    <button id="close-modal-btn" class="text-gray-500 hover:text-gray-700">
                        <i class="fa fa-times text-lg"></i>
                    </button>
                </div>
                <form action="" method="post" enctype="multipart/form-data" id="upload-form">
                    <input type="file" id="file-input" name="file[]" multiple class="hidden">
                    <div class="p-4">
                    
                   
                        <!-- 分类选择 -->
                        <div class="mb-4">
                            <!-- 分类折叠/展开按钮 -->
                            <div class="flex justify-between items-center mb-2">
                                <label class="form-label mb-0">选择分类</label>
                                <button type="button" id="category-toggle" class="text-sm text-primary hover:text-blue-700 transition-colors">
                                    <i class="fa fa-chevron-down mr-1"></i> 展开
                                </button>
                            </div>
                            <!-- 分类选项（默认折叠） -->
                            <div id="category-options" class="flex flex-wrap gap-3 mb-2" style="display: none;">
                                <label class="px-4 py-2 border border-gray-300 rounded-lg cursor-pointer transition-all duration-300 hover:border-primary hover:bg-blue-50">
                                    <input type="radio" name="category" value="工作" class="hidden" required>
                                    <span>工作</span>
                                </label>
                                <label class="px-4 py-2 border border-primary bg-blue-50 rounded-lg cursor-pointer transition-all duration-300 hover:border-primary hover:bg-blue-100">
                                    <input type="radio" name="category" value="分享" class="hidden" checked required>
                                    <span>分享</span>
                                </label>
                                <label class="px-4 py-2 border border-gray-300 rounded-lg cursor-pointer transition-all duration-300 hover:border-primary hover:bg-blue-50">
                                    <input type="radio" name="category" value="备忘" class="hidden" required>
                                    <span>备忘</span>
                                </label>
                            </div>
                      <!--   <p class="mt-1 text-xs text-gray-500">文件将上传到 file_up/<span id="selected-category">分享</span> 目录下</p> -->

                        </div>
                        
                        <!-- 备注信息 -->
                        <div class="mb-4">
                       
                            <input type="text" id="remark" name="remark" class="input-field" maxlength="40" placeholder="请输入备注信息（可选，最多40字）">
                           
                        </div>
                        
                        <!-- 文件选择区域 -->
                        <div class="mb-4">
                      
                            <div id="modal-drop-area" class="upload-area border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                                <i class="fa fa-cloud-upload text-3xl text-gray-400 mb-3"></i>
                                <p class="mb-3">拖放文件到此处或按Ctrl+V粘贴图片</p>
                                <p class="mt-1 text-xs text-gray-500">支持图片、文档、视频等多种文件格式，单个文件大小不超过20MB</p>
                                <div class="flex flex-wrap gap-3 justify-center mt-4">
                                    <button type="button" id="browse-btn" class="btn btn-primary">
                                        <i class="fa fa-folder-open mr-2"></i>
                                        浏览文件
                                    </button>
                                    <button type="button" id="paste-btn" class="btn btn-secondary">
                                        <i class="fa fa-paste mr-2"></i>
                                        粘贴图片
                                    </button>
                                </div>
                                <input type="file" id="modal-file-input" name="file[]" multiple class="hidden">
                            </div>
                        </div>
                        
                        <!-- 已选择文件列表 -->
                        <div id="modal-selected-files" class="mb-4">
                            <!-- 已选择文件将显示在这里 -->
                        </div>
                    </div>
                    <div class="p-4 border-t border-gray-200 flex justify-end space-x-3">
                        <button type="button" id="cancel-modal-btn" class="btn btn-secondary px-4 py-2">
                            取消
                        </button>
                        <button type="submit" id="upload-submit-btn" class="btn btn-primary px-4 py-2">
                            <i class="fa fa-paper-plane mr-2"></i>
                            <span>开始上传</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>



        <!-- 图片查看模态框 -->
        <div id="image-modal" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-80 z-40" style="display: none; align-items: center; justify-content: center;">
            <div class="bg-white rounded-xl shadow-lg max-w-4xl max-h-[90vh] overflow-hidden">
                <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 id="image-title" class="text-lg font-semibold text-dark">查看图片</h3>
                    <button id="image-close-btn" class="text-gray-500 hover:text-gray-700">
                        <i class="fa fa-times text-lg"></i>
                    </button>
                </div>
                <div class="p-4 flex items-center justify-center" style="max-height: 70vh;">
                    <div id="image-content" class="relative flex flex-col items-center justify-center">
                        <img id="image-preview" src="" alt="图片预览" class="max-w-full max-h-[60vh] object-contain">
                        <div id="image-qr-code" class="mt-4 bg-white p-2 rounded-lg shadow-md">
                            <!-- 二维码将显示在这里 -->
                        </div>
                    </div>
                </div>
                <div class="p-4 border-t border-gray-200 flex flex-col md:flex-row items-start md:items-center justify-between gap-2">
                    <div id="image-remark" class="text-sm text-gray-600 flex-1" style="word-break: break-word;"></div>
                    <button id="image-close-btn2" class="btn btn-primary px-5 py-1.5 text-sm md:align-self-center">
                        关闭
                    </button>
                </div>
            </div>
        </div>

        <!-- 提示信息容器 -->
        <div id="toast-container" class="fixed top-20 right-4 z-50 flex flex-col items-end space-y-2"></div>

        <!-- 密码输入模态框 -->
        <div id="password-modal" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-50 z-40" style="display: none; align-items: center; justify-content: center;">
            <div class="bg-white rounded-xl shadow-lg max-w-sm overflow-hidden transform transition-all duration-300 scale-100 w-full max-w-sm">
                <div class="p-4 border-b border-gray-200">
                    <div class="flex items-center">
                        <i class="fa fa-lock text-lg text-primary mr-2"></i>
                        <h3 class="text-base font-semibold text-dark">输入删除密码</h3>
                    </div>
                    <p class="text-sm text-gray-600 mt-1">请输入密码以确认删除操作</p>
                </div>
                <div class="p-4">
                    <input type="password" id="delete-password" class="input-field" placeholder="请输入删除密码">
                </div>
                <div class="p-4 border-t border-gray-200 flex justify-end space-x-3">
                    <button id="cancel-password-btn" class="btn btn-secondary px-5 py-1.5 text-sm">
                        取消
                    </button>
                    <button id="confirm-password-btn" class="btn btn-primary px-5 py-1.5 text-sm">
                        确认
                    </button>
                </div>
            </div>
        </div>

        <!-- 登录模态框 -->
        <div id="login-modal" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-50 z-40" style="display: none; align-items: center; justify-content: center;">
            <div class="bg-white rounded-xl shadow-lg max-w-sm overflow-hidden transform transition-all duration-300 scale-100 w-full max-w-sm">
                <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-dark">API 验证</h3>
                    <button id="close-login-modal-btn" class="text-gray-500 hover:text-gray-700">
                        <i class="fa fa-times text-lg"></i>
                    </button>
                </div>
                <div class="p-4">
                    <div id="api-key-container" class="mb-4">
                        <div class="mb-4">
                            
                            <input type="password" id="login-api-key" class="input-field" placeholder="请输入 API Key">
                        </div>
                        <div id="api-key-info" class="bg-blue-50 p-3 rounded-lg mb-4">
                            <p class="text-sm text-blue-700">
                                <strong>API Key 请妥善保管，请勿共用。</strong>
                            </p>
                        </div>
                        <div class="flex flex-col mb-4">
                            <div class="flex items-center mb-3">
                                <label class="text-sm text-gray-700 mr-2 w-24">删除权限</label>
                                <select id="delete-permission" class="input-field text-sm flex-1">
                                    <option value="y">允许删除 (需要密码)</option>
                                    <option value="n">允许删除 (无需密码)</option>
                                    <option value="disabled">禁止删除</option>
                                </select>
                            </div>
                            <div id="delete-password-container" class="flex items-center mb-2">
                                <label class="text-sm text-gray-700 mr-2 w-24">删除密码</label>
                                <input type="password" id="delete-password-input" class="input-field text-sm flex-1" placeholder="请设置删除密码（默认：0000）">
                            </div>
                            <p class="text-xs text-gray-500 mt-1">设置后，删除文件时需要输入此密码</p>
                        </div>
                    </div>
                    <!-- API Key验证状态已移至下拉菜单，此处不再显示 -->
                    <div id="add-api-key-container" class="mb-4 hidden">
                        <h4 class="text-sm font-medium text-gray-700 mb-3">添加新的 API Key</h4>
                        <div class="mb-3">
                            <input type="password" id="admin-password" class="input-field" placeholder="请输入管理员密码">
                        </div>
                        <div class="mb-3">
                            <div class="flex space-x-2">
                                <input type="text" id="new-api-key" class="input-field flex-1" placeholder="请输入新的 API Key">
                                <button id="generate-api-key-btn" class="btn btn-secondary px-3 py-1 text-sm">
                                    随机生成
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <input type="text" id="new-api-key-name" class="input-field" placeholder="请输入 API Key 名称">
                        </div>
                        <div class="flex justify-end space-x-2">
                            <button id="cancel-add-api-key-btn" class="btn btn-secondary px-3 py-1 text-sm">
                                取消
                            </button>
                            <button id="confirm-add-api-key-btn" class="btn btn-primary px-3 py-1 text-sm">
                                确认添加
                            </button>
                        </div>
                    </div>
                </div>
                <div id="auth-buttons" class="p-4 border-t border-gray-200 flex justify-end space-x-3">
                    <button id="cancel-login-btn" class="btn btn-secondary px-5 py-1.5 text-sm">
                        取消
                    </button>
                    <button id="confirm-login-btn" class="btn btn-primary px-5 py-1.5 text-sm">
                        授权
                    </button>
                </div>
            </div>
        </div>

        <!-- 未登录提示模态框 -->
        <div id="unauthorized-modal" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-50 z-40" style="display: none; align-items: center; justify-content: center;">
            <div class="bg-white rounded-xl shadow-lg max-w-sm overflow-hidden transform transition-all duration-300 scale-100 w-full max-w-sm">
                <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-dark">未授权访问</h3>
                    <button id="close-unauthorized-btn" class="text-gray-500 hover:text-gray-700">
                        <i class="fa fa-times text-lg"></i>
                    </button>
                </div>
                <div class="p-4">
                  
                    <div class="bg-yellow-50 p-3 rounded-lg">
                        <p class="text-sm text-yellow-700">
                            API Key  <strong>请联系管理获取</strong>
                        </p>
                    </div>
                </div>
                <div class="p-4 border-t border-gray-200 flex justify-end">
                    <button id="go-login-btn" class="btn btn-primary px-5 py-1.5 text-sm">
                        前往验证
                    </button>
                </div>
            </div>
        </div>

        <!-- 数据库配置模态框 -->
        <div id="database-config-modal" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-50 z-50" style="display: none; align-items: center; justify-content: center;">
            <div class="bg-white rounded-xl shadow-lg max-w-2xl overflow-hidden transform transition-all duration-300 scale-100 w-full max-w-2xl">
                <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-dark">数据库配置</h3>
                    <button id="close-database-config-btn" class="text-gray-500 hover:text-gray-700">
                        <i class="fa fa-times text-lg"></i>
                    </button>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="mb-4">
                            <label class="form-label">数据库主机</label>
                            <input type="text" id="db-host" class="input-field" placeholder="例如：localhost" value="localhost">
                           <p class="text-xs text-gray-500 mt-1">本地：localhost 远程填写远程数据库地址</p>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">数据库用户名</label>
                            <input type="text" id="db-username" class="input-field" placeholder="例如：root" value="root">
                         <p class="text-xs text-gray-500 mt-1">登录后台查看数据库名</p>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">数据库密码</label>
                            <input type="password" id="db-password" class="input-field" placeholder="请输入数据库密码">
                            <p class="text-xs text-gray-500 mt-1">登录后台查看数据库密码</p>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">数据库名称</label>
                            <input type="text" id="db-name" class="input-field" placeholder="例如：file_share" value="file_share">
                             <p class="text-xs text-gray-500 mt-1">安装数据的数据库名。</p>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">数据库端口</label>
                            <input type="number" id="db-port" class="input-field" placeholder="例如：3306" value="3306">
                        </div>
                        <div class="mb-4 flex items-end">
                            <button id="test-db-connection-btn" class="btn btn-secondary px-5 py-1.5 text-sm w-full">
                                测试连接
                            </button>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">管理员Key</label>
                            <input type="password" id="admin-key" class="input-field" placeholder="请输入管理员Key">
                            <p class="text-xs text-gray-500 mt-1">设置管理员KEY 用于登录验证并开启管理功能</p>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">管理员密码</label>
                            <input type="password" id="db-admin-password" class="input-field" placeholder="请输入管理员密码">
                            <p class="text-xs text-gray-500 mt-1">管理员密码用于添加用户Key，请牢记！</p>
                        </div>
                      
                    </div>
                    <div class="bg-blue-50 p-3 rounded-lg mb-4">
                        <p class="text-sm text-blue-700 mb-2">
                            <strong>首次配置：</strong>系统将自动创建数据库和表结构
                        </p>
                        <p class="text-sm text-blue-700">
                            <strong>环境要求：</strong>PHP 5.6+，MySQL 5.7+，PDO 扩展启用
                        </p>
                    </div>
                </div>
                <div class="p-4 border-t border-gray-200 flex justify-end space-x-3">
                    <button id="cancel-database-config-btn" class="btn btn-secondary px-5 py-1.5 text-sm">
                        取消
                    </button>
                    <button id="save-database-config-btn" class="btn btn-primary px-5 py-1.5 text-sm">
                        开始安装
                    </button>
                </div>
            </div>
        </div>

        <!-- 拼图功能模态框 -->
        <div id="puzzle-modal" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-50 z-40" style="display: none; align-items: center; justify-content: center;">
            <div class="bg-white rounded-xl shadow-lg max-h-[90vh] overflow-y-auto" style="width: 90%; max-width: 800px; margin: 0 auto;">
                <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-dark">拼图</h3>
                    <button id="close-puzzle-modal-btn" class="text-gray-500 hover:text-gray-700">
                        <i class="fa fa-times text-lg"></i>
                    </button>
                </div>
                <div class="p-4">
                 
                    <!-- 分类选择 -->
                    <div class="mb-4">
                        <!-- 分类折叠/展开按钮 -->
                        <div class="flex justify-between items-center mb-2">
                            <label class="form-label mb-0">选择分类</label>
                            <button type="button" id="puzzle-category-toggle" class="text-sm text-primary hover:text-blue-700 transition-colors">
                                <i class="fa fa-chevron-down mr-1"></i> 展开
                            </button>
                        </div>
                        <!-- 分类选项（默认折叠） -->
                        <div id="puzzle-category-options" class="flex flex-wrap gap-3 mb-2" style="display: none;">
                            <label class="px-4 py-2 border border-gray-300 rounded-lg cursor-pointer transition-all duration-300 hover:border-primary hover:bg-blue-50">
                                <input type="radio" name="puzzle-category" value="工作" class="hidden" required>
                                <span>工作</span>
                            </label>
                            <label class="px-4 py-2 border border-primary bg-blue-50 rounded-lg cursor-pointer transition-all duration-300 hover:border-primary hover:bg-blue-100">
                                <input type="radio" name="puzzle-category" value="分享" class="hidden" checked required>
                                <span>分享</span>
                            </label>
                            <label class="px-4 py-2 border border-gray-300 rounded-lg cursor-pointer transition-all duration-300 hover:border-primary hover:bg-blue-50">
                                <input type="radio" name="puzzle-category" value="备忘" class="hidden" required>
                                <span>备忘</span>
                            </label>
                        </div>
                    </div>

                    <!-- 备注信息 -->
                    <div class="mb-4">
                        <input type="text" id="puzzle-remark" name="puzzle-remark" class="input-field" maxlength="40" placeholder="请输入备注信息（可选，最多40字）">
                    </div>

                    <!-- 图片上传 -->
                    <div class="mb-4">
                      
                        <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center">
                            <input type="file" id="puzzle-file-input" name="puzzle-files[]" multiple accept="image/*" class="hidden">
                            <div class="flex flex-col md:flex-row items-start md:items-center gap-4 mb-4">
                                <button type="button" id="puzzle-browse-btn" class="btn btn-primary">
                                    <i class="fa fa-folder-open mr-2"></i>
                                    选择图片
                                </button>
                                <p class="text-sm text-gray-600">请选择 2-9 张图片自动适应布局</p>
                            </div>
                           
                            <div id="puzzle-selected-files" class="mt-4 grid grid-cols-4 gap-2">
                                <!-- 已选择的图片将显示在这里 -->
                            </div>
                        </div>
                    </div>

                    <!-- 拼图预览 -->
                    <div class="mb-4">
                        
                        <div id="puzzle-preview" class="border border-gray-300 rounded-lg p-4 flex items-center justify-center max-h-[250px] bg-gray-50 overflow-hidden">
                            <p class="text-gray-500">请选择布局和上传图片</p>
                        </div>
                    </div>

                    <!-- 操作按钮 -->
                    <div class="p-4 border-t border-gray-200 flex justify-end space-x-3">
                        <button id="cancel-puzzle-btn" class="btn btn-secondary px-4 py-2">
                            取消
                        </button>
                        <button id="generate-puzzle-btn" class="btn btn-primary px-4 py-2">
                            <i class="fa fa-magic mr-2"></i>
                            生成拼图
                        </button>
                        <button id="upload-puzzle-btn" class="btn btn-success px-4 py-2" disabled>
                            <i class="fa fa-upload mr-2"></i>
                            上传拼图
                        </button>
                    </div>

              

                </div>
               
            </div>
        </div>



        <!-- 上传结果 -->
        <section id="upload-result" class="mb-12 hidden">
            <div class="card">
                <h2 class="text-xl font-semibold mb-6 text-dark flex items-center">
                    <i class="fa fa-check-circle mr-2 text-success"></i>
                    上传结果
                </h2>
                <div id="result-content" class="p-6 bg-gray-50 rounded-xl">
                    <!-- 上传结果将显示在这里 -->
                </div>
            </div>
        </section>

        <!-- 上传记录 -->
        <section id="uploads" class="mb-12">
            <div class="card">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                    <h2 class="text-xl font-semibold text-dark flex items-center mb-4 md:mb-0">
                        <i class="fa fa-history mr-2 text-primary"></i>
                        上传记录
                    </h2>
                    <div class="w-full md:w-64">
                        <div class="relative">
                            <input type="text" id="search-input" placeholder="搜索记录..." class="w-full px-4 py-2 border border-gray-300 rounded-lg pl-10 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <i class="fa fa-search absolute left-3 top-3 text-gray-400"></i>
                        </div>
                    </div>
                </div>
                <div id="upload-history" class="space-y-4">
                    <!-- 上传记录将显示在这里 -->
                </div>
            </div>
        </section>

        <!-- 关于 -->
        <section id="about">
            <div class="card">
        
               
            

                 <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fa fa-exclamation-circle text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">使用声明</h3>
                            <div class="mt-2 text-sm text-yellow-700">
                                <p>本系统仅限内网合规使用，所有操作须遵守公司保密制度。</p>
                            
                                <p class="mt-1">严禁上传涉密及敏感信息，违者将承担相应责任。</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                        <div class="text-primary text-2xl mb-2"><i class="fa fa-cloud-upload"></i></div>
                        <h3 class="font-medium text-dark mb-2">便捷上传</h3>
                        <p class="text-sm text-gray-600">支持拖拽上传和批量上传，操作简单便捷</p>
                    </div>
                    <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                        <div class="text-primary text-2xl mb-2"><i class="fa fa-folder"></i></div>
                        <h3 class="font-medium text-dark mb-2">文件类型</h3>
                        <p class="text-sm text-gray-600">支持 图片、文档、文本、压缩文件、exe、dll </p>
                    </div>
                    <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                        <div class="text-primary text-2xl mb-2"><i class="fa fa-history"></i></div>
                        <h3 class="font-medium text-dark mb-2">上传记录</h3>
                        <p class="text-sm text-gray-600">自动记录上传历史，方便查看和管理</p>
                    </div>
                </div>
            </div>
        </section>
    </main>
    
    <!-- 管理员模态框 -->
    <div id="admin-modal" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-50 z-9999" style="display: none; align-items: center; justify-content: center; position: fixed;">
        <div class="bg-white rounded-xl shadow-lg max-w-4xl overflow-hidden transform transition-all duration-300 scale-100 w-full max-w-4xl">
            <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-dark">管理员控制台</h3>
                <button id="close-admin-modal-btn" class="text-gray-500 hover:text-gray-700">
                    <i class="fa fa-times text-lg"></i>
                </button>
            </div>
            <div class="p-6">
                <div class="mb-6">
                  
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white border border-gray-200">
                            <thead>
                                <tr>
                                    <th class="py-2 px-4 border-b border-gray-200 bg-gray-50 text-left text-sm font-medium text-gray-600">API Key</th>
                                    <th class="py-2 px-4 border-b border-gray-200 bg-gray-50 text-left text-sm font-medium text-gray-600">名称</th>
                                    <th class="py-2 px-4 border-b border-gray-200 bg-gray-50 text-left text-sm font-medium text-gray-600">使用状态</th>
                                    <th class="py-2 px-4 border-b border-gray-200 bg-gray-50 text-left text-sm font-medium text-gray-600">创建时间</th>
                                    <th class="py-2 px-4 border-b border-gray-200 bg-gray-50 text-left text-sm font-medium text-gray-600">操作</th>
                                </tr>
                            </thead>
                            <tbody id="api-keys-table-body">
                                <!-- API keys will be dynamically added here -->
                                <tr>
                                    <td colspan="4" class="py-4 px-4 border-b border-gray-200 text-center text-gray-500">
                                        加载中...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="api-keys-pagination" class="mt-4 flex justify-center"></div>
                </div>
           
            </div>
            <div class="p-4 border-t border-gray-200 flex justify-end space-x-3">
                <button id="close-admin-modal-btn2" class="btn btn-secondary px-5 py-1.5 text-sm">
                    关闭
                </button>
            </div>
        </div>
    </div>
    
    <!-- DDNS更新模态框 -->
    <div id="ddns-modal" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-50 z-9999" style="display: none; align-items: center; justify-content: center; position: fixed;">
        <div class="bg-white rounded-xl shadow-lg max-w-3xl overflow-hidden transform transition-all duration-300 scale-100 w-full max-w-3xl">
            <div class="p-6">
                <!-- IP检测卡片 -->
                <div class="bg-gray-50 rounded-xl p-6 mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-bold flex items-center">
                            <i class="fa fa-bolt text-warning mr-2"></i> 阿里云DDNS自动更新
                        </h2>
                        <div id="ddns-update-status" class="text-sm text-gray-600 flex items-center">
                            <i class="fa fa-info-circle text-primary mr-2"></i>
                            <span>使用前请先配置好阿里云解析记录</span>
                        </div>
                    </div>
                    
                    <!-- 自动更新设置 -->
                    <div class="mb-6 p-4 border rounded-lg">
                        <!-- IP检测部分 -->
                        <div class="flex flex-col md:flex-row gap-4 items-center mb-4">
                            <div class="w-full md:w-2/3">
                                <div class="flex items-center border rounded-lg p-3 w-full">
                                    <i class="fa fa-globe text-primary mr-3"></i>
                                    <input type="text" id="ddns-public-ip" class="flex-1 outline-none text-lg font-medium" placeholder="检测中..." readonly>
                                    <button id="ddns-refresh-ip-btn" class="ml-2 bg-primary/10 text-primary px-3 py-1 rounded-lg hover:bg-primary/20 transition-all duration-300 text-sm">
                                        <i class="fa fa-refresh mr-1"></i> 刷新IP
                                    </button>
                                </div>
                                  
                            </div>
                            <div class="w-full md:w-1/3">
                                <div id="ddns-ip-status" class="flex items-center justify-center p-3 rounded-lg bg-gray-100">
                                    <i class="fa fa-spinner fa-spin mr-2"></i>
                                    <span>检测IP中...</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 定时更新设置 -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center space-x-4">
                                <label class="flex items-center">
                                    <input type="checkbox" id="ddns-auto-update-toggle" class="mr-2">
                                    <span>自动更新</span>
                                </label>
                                <div id="ddns-auto-update-status" class="text-sm text-gray-600">
                                    自动更新已禁用
                                </div>
                            </div>
                            <div class="flex items-center space-x-3">
                                <select id="ddns-update-interval" class="border rounded px-2 py-1 text-sm">
                                    <option value="60000">1分钟</option>
                                    <option value="300000">5分钟</option>
                                    <option value="600000">10分钟</option>
                                    <option value="1800000">30分钟</option>
                                    <option value="3600000">1小时</option>
                                </select>
                                <div id="ddns-auto-update-countdown" class="text-sm text-gray-600 hidden">
                                    计时: <span id="ddns-countdown-timer">00:00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 操作按钮 -->
                    <div class="flex flex-col sm:flex-row gap-4">
                        <button id="ddns-config-btn" class="flex-1 bg-primary text-white px-6 py-3 rounded-lg hover:bg-primary/90 transition-all duration-300 flex items-center justify-center">
                            <i class="fa fa-cog mr-2"></i> 阿里云配置
                        </button>
                        <button id="ddns-update-btn" class="flex-1 bg-primary text-white py-3 rounded-lg hover:bg-primary/90 transition-all duration-300 flex items-center justify-center">
                            <i class="fa fa-cloud-upload mr-2"></i> 立即更新DNS解析
                        </button>
                    </div>
                </div>
                
                <!-- 更新状态 -->
                <div class="bg-white rounded-xl shadow p-6">
                    <h3 class="text-md font-medium mb-4 flex items-center">
                        <i class="fa fa-refresh text-primary mr-2"></i> 更新状态
                    </h3>
                    <div class="mb-4">
                        <div class="flex items-center mb-2">
                            <i class="fa fa-clock-o text-secondary mr-2"></i>
                            <span class="text-sm text-secondary">上次更新时间:</span>
                            <span id="ddns-last-update-time" class="ml-2 text-sm">从未更新</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fa fa-history text-secondary mr-2"></i>
                            <span class="text-sm text-secondary">上次更新IP:</span>
                            <span id="ddns-last-update-ip" class="ml-2 text-sm">无</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-4 border-t border-gray-200 flex justify-end space-x-3">
                <button id="close-ddns-modal-btn2" class="btn btn-secondary px-5 py-1.5 text-sm">
                    关闭
                </button>
            </div>
        </div>
    </div>
    
    <!-- 阿里云配置弹出层 -->
    <div id="ddns-config-modal" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-50 z-9999" style="display: none; align-items: center; justify-content: center; position: fixed;">
        <div class="bg-white rounded-xl shadow-lg max-w-2xl overflow-hidden transform transition-all duration-300 scale-100 w-full max-w-2xl">
            <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-dark">阿里云配置</h3>
                <button id="close-ddns-config-modal-btn" class="text-gray-500 hover:text-gray-700">
                    <i class="fa fa-times text-lg"></i>
                </button>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div>
                        <label class="block text-sm font-medium mb-3 text-gray-700">AccessKey ID</label>
                        <input type="text" id="ddns-access-key-id" class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50" placeholder="请输入阿里云AccessKey ID">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-3 text-gray-700">AccessKey Secret</label>
                        <input type="password" id="ddns-access-key-secret" class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50" placeholder="请输入阿里云AccessKey Secret">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div>
                        <label class="block text-sm font-medium mb-3 text-gray-700">域名 (例如: example.com)</label>
                        <input type="text" id="ddns-domain-name" class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50" placeholder="请输入主域名">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-3 text-gray-700">解析记录 (例如: www)</label>
                        <input type="text" id="ddns-rr" class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50" placeholder="默认@" value="@">
                    </div>
                </div>
                
                <div class="mb-8">
                    <label class="block text-sm font-medium mb-3 text-gray-700">记录类型</label>
                    <select id="ddns-record-type" class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50">
                        <option value="A">A记录 (IPv4地址)</option>
                        <option value="AAAA">AAAA记录 (IPv6地址)</option>
                    </select>
                </div>
                
                <div class="flex justify-between items-center pt-4 border-t border-gray-200">
                    <button id="save-ddns-config-btn" class="bg-primary text-white px-5 py-2.5 rounded-lg hover:bg-primary/90 transition-all duration-300">
                        <i class="fa fa-save mr-2"></i> 保存配置
                    </button>
                    <button id="test-ddns-config-btn" class="bg-warning text-white px-5 py-2.5 rounded-lg hover:bg-warning/90 transition-all duration-300">
                        <i class="fa fa-check mr-2"></i> 测试配置
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 提示模态框 -->
    <div id="alert-modal" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-50 z-100" style="display: none; align-items: center; justify-content: center;">
        <div class="bg-white rounded-xl shadow-lg max-w-sm overflow-hidden transform transition-all duration-300 scale-100 w-full max-w-sm">
            <div class="p-4 border-b border-gray-200">
                <div class="flex items-center">
                    <i id="alert-icon" class="fa fa-info-circle text-lg text-primary mr-2"></i>
                    <h3 id="alert-title" class="text-base font-semibold text-dark">提示</h3>
                </div>
                <p id="alert-message" class="text-sm text-gray-600 mt-1"></p>
            </div>
            <div id="alert-content" class="p-4 relative">
                <!-- 提示内容将显示在这里 -->
            </div>
            <div class="p-4 border-t border-gray-200 flex justify-end">
                <button id="alert-close-btn" class="btn btn-primary px-5 py-1.5 text-sm">
                    确定
                </button>
            </div>
        </div>
    </div>

    <!-- 页脚 -->
    <footer class="header-gradient text-white py-8">
         
      
            <div class="text-center text-white/80 text-sm">
                 <p class="text-white/60 text-sm" id="footer-api-key-name">
                        <!-- API Key 名称将显示在这里 -->
                    </p>
                <p>&copy; 2026 QR File Converter Platform 保留所有权利.</p>
          
        </div>
    </footer>

    <!-- JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 主要元素
            const fileInput = document.getElementById('file-input');
            const uploadForm = document.getElementById('upload-form');
            const uploadResult = document.getElementById('upload-result');
            const resultContent = document.getElementById('result-content');
            const uploadHistory = document.getElementById('upload-history');
            const newCategoryInput = document.getElementById('new-category');
            const confirmCategoryBtn = document.getElementById('confirm-category-btn');
            
            // 模态框相关元素
            const uploadModal = document.getElementById('upload-modal');
            const uploadModalBtn = document.getElementById('upload-modal-btn');
            const closeModalBtn = document.getElementById('close-modal-btn');
            const cancelModalBtn = document.getElementById('cancel-modal-btn');
            const modalFileInput = document.getElementById('modal-file-input');
            const browseBtn = document.getElementById('browse-btn');
            const pasteBtn = document.getElementById('paste-btn');
            const modalDropArea = document.getElementById('modal-drop-area');
            const modalSelectedFiles = document.getElementById('modal-selected-files');
            const categoryToggle = document.getElementById('category-toggle');
            const categoryOptions = document.getElementById('category-options');
            
            // 提示模态框相关元素
            const alertModal = document.getElementById('alert-modal');
            const alertTitle = document.getElementById('alert-title');
            const alertContent = document.getElementById('alert-content');
            const alertCloseBtn = document.getElementById('alert-close-btn');
            
            // 获取选中的分类
            function getSelectedCategory() {
                const selectedRadio = document.querySelector('input[name="category"]:checked');
                return selectedRadio ? selectedRadio.value : '';
            }
            

            
            // 存储选中的文件
            let selectedFiles = [];

            // 打开模态框
            if (uploadModalBtn && uploadModal && modalFileInput && modalSelectedFiles) {
                uploadModalBtn.addEventListener('click', function() {
                    // 关闭其他模态框
                    const modalsToClose = ['puzzle-modal', 'admin-modal', 'login-modal', 'alert-modal'];
                    modalsToClose.forEach(modalId => {
                        const modal = document.getElementById(modalId);
                        if (modal) {
                            modal.style.display = 'none';
                        }
                    });
                    
                    uploadModal.style.display = 'flex';
                    // 重置模态框内的文件选择
                    modalFileInput.value = '';
                    modalSelectedFiles.innerHTML = '<p class="text-gray-500">暂无选择的文件</p>';
                    selectedFiles = [];
                    // 清空备注信息
                    const remarkInput = document.getElementById('remark');
                    if (remarkInput) {
                        remarkInput.value = '';
                    }
                    // 确保分类选择默认设置为分享
                    const shareRadio = document.querySelector('input[name="category"][value="分享"]');
                    if (shareRadio) {
                        shareRadio.checked = true;
                        // 触发change事件以更新样式
                        const event = new Event('change');
                        shareRadio.dispatchEvent(event);
                    }
                    // 隐藏新分类输入框
                    const newCategoryInputContainer = document.getElementById('new-category-input');
                    if (newCategoryInputContainer) {
                        newCategoryInputContainer.classList.add('hidden');
                        if (newCategoryInput) {
                            newCategoryInput.value = '';
                        }
                    }
                });

                // 关闭模态框
                function closeModal() {
                    if (uploadModal) {
                        uploadModal.style.display = 'none';
                    }
                }

                if (closeModalBtn) {
                    closeModalBtn.addEventListener('click', closeModal);
                }
                if (cancelModalBtn) {
                    cancelModalBtn.addEventListener('click', closeModal);
                }
            }

            // 移除点击模态框外部关闭的功能，只允许通过关闭按钮关闭

            // 提示模态框关闭按钮点击事件
            if (alertCloseBtn && alertModal) {
                alertCloseBtn.addEventListener('click', function() {
                    // 重置模态框状态
                    const alertContent = document.getElementById('alert-content');
                    
                    // 移除覆盖层
                    if (alertContent) {
                        const overlay = alertContent.querySelector('.absolute.inset-0');
                        if (overlay) {
                            overlay.remove();
                        }
                        
                        // 移除二维码模糊效果
                        const qrCodeElements = alertContent.querySelectorAll('img[alt="二维码"]');
                        qrCodeElements.forEach(element => {
                            element.style.filter = 'none';
                        });
                    }
                    
                    alertModal.style.display = 'none';
                });

                // 点击提示模态框外部关闭
                alertModal.addEventListener('click', function(e) {
                    if (e.target === alertModal) {
                        // 重置模态框状态
                        const alertContent = document.getElementById('alert-content');
                        
                        // 移除覆盖层
                        if (alertContent) {
                            const overlay = alertContent.querySelector('.absolute.inset-0');
                            if (overlay) {
                                overlay.remove();
                            }
                            
                            // 移除二维码模糊效果
                            const qrCodeElements = alertContent.querySelectorAll('img[alt="二维码"]');
                            qrCodeElements.forEach(element => {
                                element.style.filter = 'none';
                            });
                        }
                        
                        alertModal.style.display = 'none';
                    }
                });
            }

            // 浏览按钮点击事件
            if (browseBtn && modalFileInput) {
                browseBtn.addEventListener('click', function() {
                    modalFileInput.click();
                });
            }

            // 分类折叠/展开按钮点击事件
            if (categoryToggle && categoryOptions) {
                categoryToggle.addEventListener('click', function() {
                    const isExpanded = categoryOptions.style.display !== 'none';
                    if (isExpanded) {
                        // 折叠分类选项
                        categoryOptions.style.display = 'none';
                        categoryToggle.innerHTML = '<i class="fa fa-chevron-down mr-1"></i> 展开';
                    } else {
                        // 展开分类选项
                        categoryOptions.style.display = 'flex';
                        categoryToggle.innerHTML = '<i class="fa fa-chevron-up mr-1"></i> 折叠';
                    }
                });
            }

            // 粘贴按钮点击事件
            if (pasteBtn) {
                pasteBtn.addEventListener('click', function() {
                    showToast('请按 Ctrl+V 粘贴图片', 'info');
                });
            }

            // 监听粘贴事件
            document.addEventListener('paste', function(e) {
                // 检查是否在模态框打开的情况下
                if (uploadModal && uploadModal.style.display === 'flex') {
                    // 检查是否有粘贴的图片
                    const items = e.clipboardData.items;
                    if (!items) return;

                    for (let i = 0; i < items.length; i++) {
                        if (items[i].type.indexOf('image') !== -1) {
                            const file = items[i].getAsFile();
                            if (file) {
                                // 处理粘贴的图片
                                handlePastedImage(file);
                                break;
                            }
                        }
                    }
                }
            });

            // 处理粘贴的图片
            function handlePastedImage(file) {
                // 为粘贴的图片生成一个文件名
                const timestamp = Date.now();
                const filename = `paste_${timestamp}.png`;
                
                // 创建一个新的File对象，包含文件名
                const renamedFile = new File([file], filename, {
                    type: file.type,
                    lastModified: Date.now()
                });
                
                // 添加到选中文件列表
                selectedFiles.push(renamedFile);
                showModalSelectedFiles(selectedFiles);
                
                // 更新隐藏的文件输入
                const dataTransfer = new DataTransfer();
                selectedFiles.forEach(file => {
                    dataTransfer.items.add(file);
                });
                fileInput.files = dataTransfer.files;
                
                // 显示成功提示
                showToast('图片粘贴成功', 'success');
            }

            // 模态框内文件选择事件
            if (modalFileInput) {
                modalFileInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        handleFiles(this.files);
                    }
                });
            }

            // 处理文件
            function handleFiles(files) {
                selectedFiles = Array.from(files);
                showModalSelectedFiles(selectedFiles);
                // 更新隐藏的文件输入
                const dataTransfer = new DataTransfer();
                selectedFiles.forEach(file => {
                    dataTransfer.items.add(file);
                });
                fileInput.files = dataTransfer.files;
            }

            // 显示模态框内已选择的文件
            function showModalSelectedFiles(files) {
                if (!modalSelectedFiles) return;
                
                if (files.length === 0) {
                    modalSelectedFiles.innerHTML = '<p class="text-gray-500">暂无选择的文件</p>';
                    return;
                }
                
                let filesHtml = '';
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    
                    // 检查是否为图片文件
                    const isImage = file.type.startsWith('image/');
                    let previewHtml = '';
                    
                    if (isImage) {
                        // 创建图片预览
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const previewElement = document.querySelector(`.file-preview[data-index="${i}"]`);
                            if (previewElement) {
                                previewElement.innerHTML = `<img src="${e.target.result}" class="w-10 h-10 object-cover rounded" alt="预览">`;
                            }
                        };
                        reader.readAsDataURL(file);
                        previewHtml = '<div class="file-preview w-10 h-10 flex items-center justify-center bg-gray-100 rounded mr-2" data-index="' + i + '"><i class="fa fa-image text-gray-400"></i></div>';
                    } else {
                        previewHtml = '<i class="fa fa-file-' + getFileIcon(file.name) + ' text-primary mr-2"></i>';
                    }
                    
                    filesHtml += `
                        <div class="flex items-center justify-between p-2 border-b border-gray-200">
                            <div class="flex items-center">
                                ${previewHtml}
                                <span>${file.name}</span>
                            </div>
                            <span class="text-sm text-gray-500">${formatFileSize(file.size)}</span>
                        </div>
                    `;
                }
                
                modalSelectedFiles.innerHTML = filesHtml;
            }

            // 格式化文件大小
            function formatFileSize(size) {
                const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                let unitIndex = 0;
                
                while (size >= 1024 && unitIndex < units.length - 1) {
                    size /= 1024;
                    unitIndex++;
                }
                
                return round(size, 2) + ' ' + units[unitIndex];
            }

            // 四舍五入函数
            function round(number, digits) {
                return Math.round(number * Math.pow(10, digits)) / Math.pow(10, digits);
            }

            // 模态框内拖拽事件
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                modalDropArea.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover'].forEach(eventName => {
                modalDropArea.addEventListener(eventName, function() {
                    modalDropArea.classList.add('active');
                }, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                modalDropArea.addEventListener(eventName, function() {
                    modalDropArea.classList.remove('active');
                }, false);
            });

            // 拖拽上传
            modalDropArea.addEventListener('drop', function(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                handleFiles(files);
            }, false);

            // 表单提交
            uploadForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // 检查授权
                if (!checkAuth()) {
                    unauthorizedModal.style.display = 'flex';
                    return;
                }
                
                // 验证是否选择了文件
                if (fileInput.files.length === 0) {
                    showToast('请选择要上传的文件', 'error');
                    return;
                }
                
                // 验证是否选择了分类
                const selectedCategory = getSelectedCategory();
                if (!selectedCategory || selectedCategory === '__add_new__') {
                    showToast('请选择有效的分类', 'error');
                    return;
                }
                
                // 获取上传按钮
                const uploadSubmitBtn = document.getElementById('upload-submit-btn');
                if (uploadSubmitBtn) {
                    // 保存原始按钮内容
                    const originalBtnContent = uploadSubmitBtn.innerHTML;
                    // 设置按钮为正在上传状态
                    uploadSubmitBtn.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i><span>正在上传...</span>';
                    uploadSubmitBtn.disabled = true;
                }
                
                // 创建新的FormData，只包含fileInput的文件
                const formData = new FormData();
                // 添加文件
                for (let i = 0; i < fileInput.files.length; i++) {
                    formData.append('file[]', fileInput.files[i]);
                }
                // 添加分类
                formData.append('category', selectedCategory);
                // 添加备注
                const remarkInput = document.getElementById('remark');
                formData.append('remark', remarkInput ? remarkInput.value : '');
                // 添加API Key
                const apiKey = sessionStorage.getItem('apiKey') || localStorage.getItem('apiKey') || '';
                formData.append('api_key', apiKey);
                
                const xhr = new XMLHttpRequest();
                
                xhr.open('POST', window.location.href, true);
                
                xhr.onload = function() {
                    // 恢复上传按钮状态
                    const uploadSubmitBtn = document.getElementById('upload-submit-btn');
                    if (uploadSubmitBtn) {
                        uploadSubmitBtn.disabled = false;
                    }
                    
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                showResult(response);
                                loadUploadHistory();
                                loadCategories(); // 重新加载分类目录
                                // 清空模态框内的文件选择
                                modalFileInput.value = '';
                                modalSelectedFiles.innerHTML = '<p class="text-gray-500">暂无选择的文件</p>';
                                selectedFiles = [];
                                fileInput.value = '';
                                // 清空备注信息
                                const remarkInput = document.getElementById('remark');
                                if (remarkInput) {
                                    remarkInput.value = '';
                                }
                                // 关闭模态框
                                closeModal();
                                
                                // 更新按钮显示为上传成功
                                if (uploadSubmitBtn) {
                                    uploadSubmitBtn.innerHTML = '<i class="fa fa-check mr-2"></i><span>上传成功</span>';
                                    // 3秒后恢复原始状态
                                    setTimeout(() => {
                                        uploadSubmitBtn.innerHTML = '<i class="fa fa-paper-plane mr-2"></i><span>开始上传</span>';
                                    }, 3000);
                                }
                            } else {
                                showToast(response.message, 'error');
                                // 恢复按钮原始状态
                                if (uploadSubmitBtn) {
                                    uploadSubmitBtn.innerHTML = '<i class="fa fa-paper-plane mr-2"></i><span>开始上传</span>';
                                }
                            }
                        } catch (error) {
                            showToast('响应解析失败，请检查服务器配置', 'error');
                            // 恢复按钮原始状态
                            if (uploadSubmitBtn) {
                                uploadSubmitBtn.innerHTML = '<i class="fa fa-paper-plane mr-2"></i><span>开始上传</span>';
                            }
                        }
                    } else {
                        showToast('上传失败，请稍后重试', 'error');
                        // 恢复按钮原始状态
                        if (uploadSubmitBtn) {
                            uploadSubmitBtn.innerHTML = '<i class="fa fa-paper-plane mr-2"></i><span>开始上传</span>';
                        }
                    }
                };
                
                xhr.onerror = function() {
                    showToast('网络错误，请检查网络连接', 'error');
                    // 恢复按钮原始状态
                    const uploadSubmitBtn = document.getElementById('upload-submit-btn');
                    if (uploadSubmitBtn) {
                        uploadSubmitBtn.disabled = false;
                        uploadSubmitBtn.innerHTML = '<i class="fa fa-paper-plane mr-2"></i><span>开始上传</span>';
                    }
                };
                
                xhr.send(formData);
            });

            // 显示上传结果
            function showResult(response) {
                const alertIcon = document.getElementById('alert-icon');
                const alertMessage = document.getElementById('alert-message');
                alertTitle.textContent = '上传成功！';
                alertIcon.className = 'fa fa-check-circle text-lg text-success mr-2';
                
                // 显示上传消息
                if (response.message) {
                    alertMessage.textContent = response.message;
                } else {
                    alertMessage.textContent = '';
                }
                
                // 处理多文件上传
                if (response.files && response.files.length > 0) {
                    let filesHtml = '';
                    response.files.forEach(file => {
                        // 生成二维码链接
                        let qrCodeHtml = '';
                        if (file.url) {
                            // 生成完整的图片地址
                            const fullImageUrl = window.location.origin + '/' + file.url;
                            const qrCodeUrl = 'http://www.alidll.com/ew/a.php?text=' + encodeURIComponent(fullImageUrl) + '&size=12&margin=2';
                            qrCodeHtml = `
                                <div class="mt-3 flex flex-col items-center">
                                    <img src="${qrCodeUrl}" alt="二维码" class="w-32 h-32 object-contain">
                                </div>
                            `;
                        }
                        
                        filesHtml += `
                            <div class="border-b border-gray-200 pb-2 mb-2 last:border-0 last:pb-0 last:mb-0">

                            <!--
                                <p class="text-sm"><strong>文件名：</strong>${file.filename}</p>
                                <p class="text-sm"><strong>分类：</strong>${file.category}</p>
                                <p class="text-sm"><strong>文件大小：</strong>${file.size}</p>  
                                ${file.remark ? `<p class="text-sm"><strong>备注：</strong>${file.remark}</p>` : ''}  -->
                                ${qrCodeHtml}
                            </div>
                        `;
                    });
                    
                    alertContent.innerHTML = `
                        <div class="space-y-3">
                            ${filesHtml}
                        </div>
                    `;
                } else {
                    // 处理单个文件上传
                    // 生成二维码链接
                    let qrCodeHtml = '';
                    if (response.url) {
                        // 生成完整的图片地址
                        const fullImageUrl = window.location.origin + '/' + response.url;
                        const qrCodeUrl = 'http://www.alidll.com/ew/a.php?text=' + encodeURIComponent(fullImageUrl) + '&size=12&margin=2';
                        qrCodeHtml = `
                            <div class="mt-3 flex flex-col items-center">
                                <img src="${qrCodeUrl}" alt="二维码" class="w-32 h-32 object-contain">
                            </div>
                        `;
                    }
                    
                    alertContent.innerHTML = `
                        <div class="space-y-2 text-gray-700">
                            ${response.remark ? `<p class="text-sm"><strong>备注：</strong>${response.remark}</p>` : ''}
                            ${qrCodeHtml}
                        </div>
                    `;
                }
                
                // 检查授权状态
                if (!checkAuth()) {
                    // 模糊处理二维码
                    const qrCodeElements = alertContent.querySelectorAll('img[alt="二维码"]');
                    qrCodeElements.forEach(element => {
                        element.style.filter = 'blur(3px)';
                    });
                    // 未授权，添加遮挡层覆盖整个内容
                    const overlay = document.createElement('div');
                    overlay.className = 'absolute inset-0 flex flex-col items-center justify-center bg-white bg-opacity-80 z-50';
                    overlay.innerHTML = `
                        <p class="text-red-600 font-bold text-2xl mb-4">未授权</p>
                        <p class="text-red-600 text-center">
                            <span class="cursor-pointer underline" onclick="document.getElementById('login-modal-btn').click()"> [点此输入] </span>正确的  <span class="cursor-pointer underline" onclick="document.getElementById('login-modal-btn').click()"> [API KEY] </span> 以获得完整访问权限。
                        </p>
                    `;
                    alertContent.appendChild(overlay);
                }
                
                // 设置按钮标题为"关闭"
                alertCloseBtn.textContent = '关闭';
                // 重置按钮的点击事件处理函数为默认行为
                alertCloseBtn.onclick = function() {
                    // 重置模态框状态
                    const alertContent = document.getElementById('alert-content');
                    
                    // 移除覆盖层
                    const overlay = alertContent.querySelector('.absolute.inset-0');
                    if (overlay) {
                        overlay.remove();
                    }
                    
                    // 移除二维码模糊效果
                    const qrCodeElements = alertContent.querySelectorAll('img[alt="二维码"]');
                    qrCodeElements.forEach(element => {
                        element.style.filter = 'none';
                    });
                    
                    alertModal.style.display = 'none';
                };
                alertModal.style.display = 'flex';
            }

            // 显示错误信息
            function showError(message) {
                const alertIcon = document.getElementById('alert-icon');
                alertTitle.textContent = '上传失败';
                alertIcon.className = 'fa fa-exclamation-circle text-lg text-danger mr-2';
                alertContent.innerHTML = `
                    <p class="text-danger mb-3">${message}</p>
                    <p class="text-xs text-gray-500">请检查您的网络连接或文件大小，然后重试。</p>
                `;
                // 设置按钮标题为"关闭"
                alertCloseBtn.textContent = '关闭';
                // 重置按钮的点击事件处理函数为默认行为
                alertCloseBtn.onclick = function() {
                    // 重置模态框状态
                    const alertContent = document.getElementById('alert-content');
                    
                    // 移除覆盖层
                    const overlay = alertContent.querySelector('.absolute.inset-0');
                    if (overlay) {
                        overlay.remove();
                    }
                    
                    // 移除二维码模糊效果
                    const qrCodeElements = alertContent.querySelectorAll('img[alt="二维码"]');
                    qrCodeElements.forEach(element => {
                        element.style.filter = 'none';
                    });
                    
                    alertModal.style.display = 'none';
                };
                alertModal.style.display = 'flex';
            }

            // 存储原始上传记录
            let originalUploadRecords = [];
            // 存储当前要删除的文件信息
            let currentDeleteFile = null;
            // 分页相关变量
            let currentPage = 1;
            const recordsPerPage = 10;
            
            // 加载上传记录
            function loadUploadHistory() {
                const apiKey = sessionStorage.getItem('apiKey') || '';
                const apiKeyHash = localStorage.getItem('apiKeyHash') || '';
                
                // 检查是否已授权
                if (!apiKeyHash) {
                    uploadHistory.innerHTML = '<p class="text-gray-500 text-center py-8"><i class="fa fa-lock mr-2"></i>请授权后查看文件</p>';
                    return;
                }
                
                const xhr = new XMLHttpRequest();
                xhr.open('GET', '?action=get_history&api_key_hash=' + encodeURIComponent(apiKeyHash), true);
              //xhr.open('GET', '?action=get_history&api_key=' + encodeURIComponent(apiKey) + '&api_key_hash=' + encodeURIComponent(apiKeyHash), true);
                
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                // 保存原始记录
                                originalUploadRecords = response.files;
                                // 显示记录，从第一页开始
                                displayUploadHistory(response.files, 1);
                                // 初始化搜索功能
                                initSearch();
                            } else {
                                console.error('获取上传记录失败:', response.message);
                            }
                        } catch (error) {
                            console.error('上传记录加载失败:', error);
                        }
                    } else {
                        console.error('上传记录加载失败:', xhr.status);
                    }
                };
                
                xhr.onerror = function() {
                    console.error('上传记录加载网络错误');
                };
                
                xhr.send();
            }
            
            // 初始化搜索功能
            function initSearch() {
                const searchInput = document.getElementById('search-input');
                if (searchInput) {
                    searchInput.addEventListener('input', function() {
                        // 检查授权状态
                        if (!checkAuth()) {
                            // 显示未授权模态框
                            unauthorizedModal.style.display = 'flex';
                            // 清空搜索输入
                            this.value = '';
                            return;
                        }
                        
                        const searchTerm = this.value.toLowerCase().trim();
                        if (searchTerm === '') {
                            // 显示所有记录，重置到第一页
                            displayUploadHistory(originalUploadRecords, 1);
                        } else {
                            // 过滤记录
                            const filteredRecords = originalUploadRecords.filter(record => {
                                return (
                                    (record.original_filename || record.filename).toLowerCase().includes(searchTerm) ||
                                    record.category.toLowerCase().includes(searchTerm) ||
                                    record.remark.toLowerCase().includes(searchTerm)
                                );
                            });
                            // 显示过滤后的记录，重置到第一页
                            displayUploadHistory(filteredRecords, 1);
                        }
                    });
                }
            }
            
            // 初始化全部清空按钮
            function initClearAllButton() {
                // 获取所有的全部清空按钮（包括上传记录部分和 API 验证弹出层中的按钮）
                const clearAllButtons = document.querySelectorAll('#clear-all-btn');
                clearAllButtons.forEach(clearAllBtn => {
                    clearAllBtn.addEventListener('click', function() {
                        // 检查是否已授权
                        if (!checkAuth()) {
                            unauthorizedModal.style.display = 'flex';
                            return;
                        }
                        
                        // 检查是否允许删除
                        if (!allowDelete()) {
                            showToast('删除功能已被禁止', 'error');
                            return;
                        }
                        
                        // 关闭 API 验证弹出层
                        const loginModal = document.getElementById('login-modal');
                        if (loginModal) {
                            loginModal.style.display = 'none';
                        }
                        
                        // 检查是否需要密码
                        if (requireDeletePassword()) {
                            // 需要密码，打开密码模态框
                            // 存储操作类型为清空
                            currentOperation = 'clearAll';
                            passwordModal.style.display = 'flex';
                        } else {
                            // 不需要密码，直接显示确认模态框
                            showClearAllConfirm();
                        }
                    });
                });
            }
            
            // 显示清空确认模态框
            function showClearAllConfirm() {
                const alertModal = document.getElementById('alert-modal');
                const alertContent = document.getElementById('alert-content');
                const alertCloseBtn = document.getElementById('alert-close-btn');
                
                alertContent.innerHTML = `
                    <p class="mb-4">确定要清空所有上传记录吗？</p>
                    <p class="text-sm text-gray-600">此操作不可撤销，所有文件和记录将被删除。</p>
                `;
                alertCloseBtn.textContent = '确认清空';
                
                // 添加取消按钮
                const modalFooter = alertModal.querySelector('.border-t');
                let cancelBtn = modalFooter.querySelector('.btn-secondary');
                if (!cancelBtn) {
                    cancelBtn = document.createElement('button');
                    cancelBtn.className = 'btn btn-secondary px-5 py-1.5 text-sm mr-3';
                    cancelBtn.textContent = '取消';
                    cancelBtn.addEventListener('click', function() {
                        alertModal.style.display = 'none';
                    });
                    modalFooter.insertBefore(cancelBtn, alertCloseBtn);
                } else {
                    cancelBtn.style.display = 'inline-block';
                }
                
                alertModal.style.display = 'flex';
                
                // 确认清空按钮点击事件
                alertCloseBtn.onclick = function() {
                    const apiKeyHash = localStorage.getItem('apiKeyHash') || '';
                    
                    // 发送请求清空所有记录
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', window.location.href, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    showToast('所有记录已清空', 'success');
                                    // 重新加载上传记录
                                    loadUploadHistory();
                                } else {
                                    showToast(response.message, 'error');
                                }
                            } catch (error) {
                                showToast('清空失败，请稍后重试', 'error');
                            }
                        } else {
                            showToast('清空失败，请稍后重试', 'error');
                        }
                        alertModal.style.display = 'none';
                    };
                    
                    xhr.onerror = function() {
                        showToast('网络错误，请检查网络连接', 'error');
                        alertModal.style.display = 'none';
                    };
                    
                    xhr.send(`action=clear_all_records&api_key_hash=${encodeURIComponent(apiKeyHash)}`);
                };
            }

            // 显示上传记录
            function displayUploadHistory(files, page = 1) {
                if (files.length === 0) {
                    uploadHistory.innerHTML = '<p class="text-gray-500 text-center py-8">暂无上传记录</p>';
                    return;
                }
                
                // 分页逻辑
                currentPage = page;
                const startIndex = (page - 1) * recordsPerPage;
                const endIndex = startIndex + recordsPerPage;
                const paginatedFiles = files.slice(startIndex, endIndex);
                const totalPages = Math.ceil(files.length / recordsPerPage);
                
                let html = '';
                paginatedFiles.forEach(file => {
                    html += `
                        <div class="flex flex-col md:flex-row items-start md:items-center justify-between p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                            <div class="flex items-start mb-3 md:mb-0">
                                <div>
                                    <div class="flex items-center">
                                        <i class="fa fa-file-${getFileIcon(file.original_filename || file.filename)} text-primary mr-2"></i>
                                        <h4 class="font-medium text-dark">${file.original_filename || file.filename} ${file.api_key_name ? `<span class="text-xs text-gray-500 ml-2">(${file.api_key_name})</span>` : ''}</h4>
                                    </div>
                                   
                                    ${file.remark ? `<p class="text-sm text-gray-600 mt-1">备注：${file.remark}</p>` : ''}
                                     <p class="text-sm text-gray-500">${file.category} · ${file.size} · ${file.date}</p>
                                </div>
                            </div>
                            <div class="flex space-x-2">
                                ${file.url ? `<button type="button" class="btn btn-secondary text-sm view-image-btn" data-url="${file.url}" data-filename="${file.original_filename || file.filename}" data-remark="${file.remark}">
                                    <i class="fa fa-eye mr-1"></i> 查看
                                </button>` : ''}
                                ${checkAuth() ? `
                                <a href="${file.url}" download class="btn btn-primary p-2" title="下载">
                                    <i class="fa fa-download"></i>
                                </a>
                                <button type="button" class="btn btn-danger p-2 delete-file-btn" data-url="${file.url}" data-filename="${file.original_filename || file.filename}" title="删除">
                                    <i class="fa fa-trash"></i>
                                </button>
                                ` : ''}
                            </div>
                        </div>
                    `;
                });
                
                // 生成分页控件
                let paginationHtml = '';
                if (totalPages > 1) {
                    paginationHtml = `
                        <div class="flex justify-center mt-6">
                            <nav class="flex items-center space-x-1">
                                <button class="page-btn px-3 py-1 rounded-md border border-gray-300 ${page === 1 ? 'bg-gray-100 cursor-not-allowed' : 'hover:bg-gray-50'}" ${page === 1 ? 'disabled' : 'data-page="' + (page - 1) + '"'}>
                                    <i class="fa fa-chevron-left"></i>
                                </button>
                                `;
                    
                    // 生成页码按钮
                    for (let i = 1; i <= totalPages; i++) {
                        if (i <= 3 || i >= totalPages - 2 || (i >= page - 1 && i <= page + 1)) {
                            paginationHtml += `
                                <button class="page-btn px-3 py-1 rounded-md border border-gray-300 ${i === page ? 'bg-primary text-white' : 'hover:bg-gray-50'}" data-page="${i}">
                                    ${i}
                                </button>
                            `;
                        } else if (i === 4 || i === totalPages - 3) {
                            paginationHtml += `
                                <span class="px-3 py-1">...</span>
                            `;
                        }
                    }
                    
                    paginationHtml += `
                                <button class="page-btn px-3 py-1 rounded-md border border-gray-300 ${page === totalPages ? 'bg-gray-100 cursor-not-allowed' : 'hover:bg-gray-50'}" ${page === totalPages ? 'disabled' : 'data-page="' + (page + 1) + '"'}>
                                    <i class="fa fa-chevron-right"></i>
                                </button>
                            </nav>
                        </div>
                    `;
                }
                
                uploadHistory.innerHTML = html + paginationHtml;
                
                // 添加分页按钮点击事件
                document.querySelectorAll('.page-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const page = parseInt(this.getAttribute('data-page'));
                        if (!isNaN(page)) {
                            displayUploadHistory(files, page);
                        }
                    });
                });
            }

            // 获取文件图标
            function getFileIcon(filename) {
                const extension = filename.split('.').pop().toLowerCase();
                const icons = {
                    'jpg': 'image-o', 'jpeg': 'image-o', 'png': 'image-o', 'gif': 'image-o',
                    'pdf': 'pdf-o',
                    'doc': 'word-o', 'docx': 'word-o',
                    'xls': 'excel-o', 'xlsx': 'excel-o',
                    'ppt': 'powerpoint-o', 'pptx': 'powerpoint-o',
                    'txt': 'text-o', 'log': 'text-o',
                    'zip': 'archive-o', 'rar': 'archive-o', '7z': 'archive-o',
                    'mp3': 'audio-o', 'wav': 'audio-o',
                    'mp4': 'video-o', 'avi': 'video-o', 'mov': 'video-o'
                };
                return icons[extension] || 'file-o';
            }



            // 加载分类目录（已硬编码分类选项）
            function loadCategories() {
                // 分类已硬编码在HTML中，无需从服务器加载
                return;
            }



            // 拼图模态框相关元素（提升作用域）
            const puzzleModal = document.getElementById('puzzle-modal');
            const puzzleModalBtn = document.getElementById('puzzle-modal-btn');
            const closePuzzleModalBtn = document.getElementById('close-puzzle-modal-btn');
            const cancelPuzzleBtn = document.getElementById('cancel-puzzle-btn');
            const puzzleFileInput = document.getElementById('puzzle-file-input');
            const puzzleBrowseBtn = document.getElementById('puzzle-browse-btn');
            const puzzleSelectedFiles = document.getElementById('puzzle-selected-files');
            const puzzlePreview = document.getElementById('puzzle-preview');
            const generatePuzzleBtn = document.getElementById('generate-puzzle-btn');
            const uploadPuzzleBtn = document.getElementById('upload-puzzle-btn');

            // 拼图相关变量（提升作用域）
            let selectedPuzzleImages = [];
            let generatedPuzzle = null;

            // 关闭密码模态框
            function closePasswordModal() {
                const passwordModal = document.getElementById('password-modal');
                const deletePasswordInput = document.getElementById('delete-password');
                if (passwordModal) {
                    passwordModal.style.display = 'none';
                }
                if (deletePasswordInput) {
                    deletePasswordInput.value = '';
                }
                currentDeleteFile = null;
            }

            // 删除文件函数
            function deleteFile(url, filename) {
                // 发送删除请求
                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                // 重新加载上传记录
                                loadUploadHistory();
                                // 显示成功提示
                                showAlert('文件删除成功', 'success');
                            } else {
                                // 显示错误提示
                                showAlert(response.message, 'error');
                            }
                        } catch (error) {
                            showAlert('删除失败，请稍后重试', 'error');
                        }
                    } else {
                        showAlert('删除失败，请稍后重试', 'error');
                    }
                };
                
                xhr.onerror = function() {
                    showAlert('网络错误，请检查网络连接', 'error');
                };
                
                const apiKey = document.getElementById('login-api-key').value;
                const deletePassword = getDeletePassword();
                xhr.send(`action=delete_file&url=${encodeURIComponent(url)}&api_key=${encodeURIComponent(apiKey)}&delete_password=${encodeURIComponent(deletePassword)}`);
            }

            // 处理删除文件按钮点击事件
            function initDeleteFunctionality() {
                document.addEventListener('click', function(e) {
                    if (e.target.closest('.delete-file-btn')) {
                        // 检查授权
                        if (!checkAuth()) {
                            unauthorizedModal.style.display = 'flex';
                            return;
                        }

                        // 检查是否允许删除
                        if (!allowDelete()) {
                            showToast('删除功能已被禁止', 'error');
                            return;
                        }

                        const button = e.target.closest('.delete-file-btn');
                        const url = button.getAttribute('data-url');
                        const filename = button.getAttribute('data-filename');
                        
                        // 存储要删除的文件信息
                        currentDeleteFile = { url, filename };
                        
                        // 检查是否需要密码
                        if (requireDeletePassword()) {
                            // 需要密码，打开密码模态框
                            passwordModal.style.display = 'flex';
                        } else {
                            // 不需要密码，直接删除
                            deleteFile(url, filename);
                        }
                    }
                });
            }



            // 初始化拼图功能
            function initPuzzleFunctionality() {
                // 打开拼图模态框
                if (puzzleModalBtn) {
                    puzzleModalBtn.addEventListener('click', function() {
                        // 检查授权
                        if (!checkAuth()) {
                            // 显示未授权模态框
                            unauthorizedModal.style.display = 'flex';
                            return;
                        }
                        
                        // 关闭其他模态框
                        const modalsToClose = ['upload-modal', 'admin-modal', 'login-modal', 'alert-modal'];
                        modalsToClose.forEach(modalId => {
                            const modal = document.getElementById(modalId);
                            if (modal) {
                                modal.style.display = 'none';
                            }
                        });
                        
                        puzzleModal.style.display = 'flex';
                        // 重置拼图相关变量
                        selectedPuzzleImages = [];
                        generatedPuzzle = null;
                        // 重置UI
                        resetPuzzleUI();
                    });
                } else {
                    console.error('拼图按钮元素未找到');
                }

                // 关闭拼图模态框
                function closePuzzleModal() {
                    if (puzzleModal) {
                        puzzleModal.style.display = 'none';
                    }
                }

                if (closePuzzleModalBtn) {
                    closePuzzleModalBtn.addEventListener('click', closePuzzleModal);
                }
                if (cancelPuzzleBtn) {
                    cancelPuzzleBtn.addEventListener('click', closePuzzleModal);
                }

                // 点击模态框外部关闭
                // 移除点击模态框外部关闭的功能，只允许通过关闭按钮关闭

                // 分类折叠/展开按钮点击事件
                const puzzleCategoryToggle = document.getElementById('puzzle-category-toggle');
                const puzzleCategoryOptions = document.getElementById('puzzle-category-options');
                if (puzzleCategoryToggle && puzzleCategoryOptions) {
                    puzzleCategoryToggle.addEventListener('click', function() {
                        const isExpanded = puzzleCategoryOptions.style.display !== 'none';
                        if (isExpanded) {
                            // 折叠分类选项
                            puzzleCategoryOptions.style.display = 'none';
                            puzzleCategoryToggle.innerHTML = '<i class="fa fa-chevron-down mr-1"></i> 展开';
                        } else {
                            // 展开分类选项
                            puzzleCategoryOptions.style.display = 'flex';
                            puzzleCategoryToggle.innerHTML = '<i class="fa fa-chevron-up mr-1"></i> 折叠';
                        }
                    });
                }

                // 处理图片上传
                puzzleBrowseBtn.addEventListener('click', function() {
                    puzzleFileInput.click();
                });

                puzzleFileInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        const newFiles = Array.from(this.files).filter(file => file.type.startsWith('image/'));
                        
                        // 限制图片数量
                        const totalImages = selectedPuzzleImages.length + newFiles.length;
                        if (totalImages > 9) {
                            showToast('最多只能选择 9 张图片', 'error');
                            return;
                        }

                        // 添加新图片
                        selectedPuzzleImages = [...selectedPuzzleImages, ...newFiles].slice(0, 9);
                        updatePuzzleSelectedFiles();
                        updatePuzzlePreview();
                        // 自动生成拼图
                        generatePuzzle();
                    }
                });

                // 生成拼图
                generatePuzzleBtn.addEventListener('click', function() {
                    // 检查授权
                    if (!checkAuth()) {
                        // 关闭拼图模态框
                        puzzleModal.style.display = 'none';
                        // 显示未授权模态框
                        unauthorizedModal.style.display = 'flex';
                        return;
                    }
                    
                    if (selectedPuzzleImages.length < 2) {
                        showToast('请至少选择 2 张图片', 'error');
                        return;
                    }

                    if (selectedPuzzleImages.length > 9) {
                        showToast('最多只能选择 9 张图片', 'error');
                        return;
                    }

                    // 显示生成中提示
                    showToast('正在生成拼图...', 'info');

                    // 生成拼图
                    generatePuzzle();
                });

                // 上传拼图
                uploadPuzzleBtn.addEventListener('click', function() {
                    // 检查授权
                    if (!checkAuth()) {
                        // 关闭拼图模态框
                        puzzleModal.style.display = 'none';
                        // 显示未授权模态框
                        unauthorizedModal.style.display = 'flex';
                        return;
                    }

                    if (!generatedPuzzle) {
                        showToast('请先生成拼图', 'error');
                        return;
                    }

                    // 获取选中的分类
                    const selectedCategory = document.querySelector('input[name="puzzle-category"]:checked').value;
                    const remark = document.getElementById('puzzle-remark').value;

                    // 保存原始按钮内容
                    const originalBtnContent = uploadPuzzleBtn.innerHTML;
                    // 设置按钮为正在上传状态
                    uploadPuzzleBtn.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i><span>正在上传...</span>';
                    uploadPuzzleBtn.disabled = true;

                    // 显示上传中提示
                    const uploadToast = document.createElement('div');
                    uploadToast.className = 'bg-white rounded-lg shadow-lg p-4 flex items-center space-x-3 animate-fadeIn';
                    uploadToast.innerHTML = `
                        <i class="fa fa-spinner fa-spin text-primary"></i>
                        <div>
                            <p class="text-sm">正在上传拼图...</p>
                            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2">
                                <div class="bg-primary h-1.5 rounded-full" style="width: 0%"></div>
                            </div>
                        </div>
                    `;
                    const toastContainer = document.getElementById('toast-container');
                    toastContainer.appendChild(uploadToast);

                    // 上传拼图
                    uploadPuzzle(generatedPuzzle, selectedCategory, remark, uploadToast, uploadPuzzleBtn, originalBtnContent);
                });
            }

            // 重置拼图UI
            function resetPuzzleUI() {
                // 清空已选择的图片
                selectedPuzzleImages = [];
                // 重置生成的拼图
                generatedPuzzle = null;
                // 更新已选择图片的显示
                updatePuzzleSelectedFiles();

                // 重置预览
                puzzlePreview.innerHTML = '<p class="text-gray-500">请上传 2-9 张图片</p>';

                // 禁用上传按钮并恢复原始状态
                uploadPuzzleBtn.disabled = true;
                uploadPuzzleBtn.innerHTML = '<i class="fa fa-upload mr-2"></i><span>上传拼图</span>';

                // 清空备注信息
                document.getElementById('puzzle-remark').value = '';
                
                // 重置文件输入
                document.getElementById('puzzle-file-input').value = '';
            }

            // 更新已选择的图片显示
            function updatePuzzleSelectedFiles() {
                if (selectedPuzzleImages.length === 0) {
                    puzzleSelectedFiles.innerHTML = '<p class="text-gray-500 col-span-4">暂无选择的图片</p>';
                    return;
                }

                let html = '';
                selectedPuzzleImages.forEach((file, index) => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const imgElement = document.querySelector(`.puzzle-image-preview[data-index="${index}"]`);
                        if (imgElement) {
                            imgElement.src = e.target.result;
                        }
                    };
                    reader.readAsDataURL(file);

                    html += `
                        <div class="relative">
                            <div class="absolute top-1 left-1 z-10">
                                <input type="checkbox" class="puzzle-image-checkbox rounded text-primary" data-index="${index}" checked>
                            </div>
                            <img src="" alt="预览" class="w-full h-16 object-cover rounded puzzle-image-preview" data-index="${index}">
                            <button type="button" class="absolute top-1 right-1 bg-danger text-white rounded-full w-6 h-6 flex items-center justify-center text-xs delete-puzzle-image" data-index="${index}">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                    `;
                });

                puzzleSelectedFiles.innerHTML = html;

                // 添加删除图片的事件
                document.querySelectorAll('.delete-puzzle-image').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const index = parseInt(this.getAttribute('data-index'));
                        selectedPuzzleImages.splice(index, 1);
                        updatePuzzleSelectedFiles();
                        updatePuzzlePreview();
                        uploadPuzzleBtn.disabled = true;
                        // 自动生成拼图
                        generatePuzzle();
                    });
                });

                // 添加复选框变化事件
                document.querySelectorAll('.puzzle-image-checkbox').forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        // 自动生成拼图
                        generatePuzzle();
                    });
                });
            }

            // 根据图片数量获取布局
            function getLayoutByImageCount(count) {
                switch (count) {
                    case 2:
                        return { rows: 1, cols: 2 };
                    case 3:
                        return { rows: 1, cols: 3 };
                    case 4:
                        return { rows: 2, cols: 2 };
                    case 5:
                        return { rows: 2, cols: 3 };
                    case 6:
                        return { rows: 2, cols: 3 };
                    case 7:
                        return { rows: 3, cols: 3 };
                    case 8:
                        return { rows: 3, cols: 3 };
                    case 9:
                        return { rows: 3, cols: 3 };
                    default:
                        return { rows: 2, cols: 2 };
                }
            }

            // 生成拼图
            function generatePuzzle() {
                // 获取选中的图片
                const checkedCheckboxes = document.querySelectorAll('.puzzle-image-checkbox:checked');
                const selectedImages = [];
                
                checkedCheckboxes.forEach(checkbox => {
                    const index = parseInt(checkbox.getAttribute('data-index'));
                    selectedImages.push(selectedPuzzleImages[index]);
                });

                // 验证选中的图片数量
                if (selectedImages.length < 2) {
                    showToast('请至少选择 2 张图片', 'error');
                    return;
                }

                if (selectedImages.length > 9) {
                    showToast('最多只能选择 9 张图片', 'error');
                    return;
                }

                const { rows, cols } = getLayoutByImageCount(selectedImages.length);
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');

                // 设置画布大小
                const pieceSize = 200;
                canvas.width = pieceSize * cols;
                canvas.height = pieceSize * rows;

                // 绘制拼图
                let drawnImages = 0;
                const totalImages = selectedImages.length;
                
                selectedImages.forEach((file, index) => {
                    const row = Math.floor(index / cols);
                    const col = index % cols;

                    const img = new Image();
                    img.onload = function() {
                        // 计算缩放比例（让图片完全填充拼图块，保持比例，可能裁剪边缘）
                        const scale = Math.max(pieceSize / img.width, pieceSize / img.height);
                        const scaledWidth = img.width * scale;
                        const scaledHeight = img.height * scale;

                        // 计算居中位置（确保图片完全填充拼图块）
                        const x = col * pieceSize + (pieceSize - scaledWidth) / 2;
                        const y = row * pieceSize + (pieceSize - scaledHeight) / 2;

                        // 绘制图片
                        ctx.drawImage(img, x, y, scaledWidth, scaledHeight);

                        // 绘制边框（以图片边缘为边界）
                        ctx.strokeStyle = '#fff';
                        ctx.lineWidth = 2;
                        ctx.strokeRect(x, y, scaledWidth, scaledHeight);

                        // 增加已绘制图片计数
                        drawnImages++;
                        
                        // 当所有图片都绘制完成后保存拼图
                        if (drawnImages === totalImages) {
                            // 对于5张图片的特殊处理：调整画布大小，移除空白区域
                            if (selectedImages.length === 5) {
                                // 创建新的画布，只包含实际有图片的区域
                                const newCanvas = document.createElement('canvas');
                                const newCtx = newCanvas.getContext('2d');
                                newCanvas.width = pieceSize * 3;
                                newCanvas.height = pieceSize * 2;
                                
                                // 复制内容到新画布
                                newCtx.drawImage(canvas, 0, 0);
                                
                                // 保存新画布
                                newCanvas.toBlob(function(blob) {
                                    generatedPuzzle = blob;
                                    // 更新预览
                                    const puzzleUrl = URL.createObjectURL(blob);
                                    puzzlePreview.innerHTML = `<img src="${puzzleUrl}" alt="生成的拼图" class="max-w-full max-h-[200px] object-contain">`;
                                    // 启用上传按钮
                                    uploadPuzzleBtn.disabled = false;
                                    // 显示拼图生成成功提示
                                    showToast('拼图生成成功', 'success');
                                }, 'image/png');
                            } else {
                                canvas.toBlob(function(blob) {
                                    generatedPuzzle = blob;
                                    // 更新预览
                                    const puzzleUrl = URL.createObjectURL(blob);
                                    puzzlePreview.innerHTML = `<img src="${puzzleUrl}" alt="生成的拼图" class="max-w-full max-h-[200px] object-contain">`;
                                    // 启用上传按钮
                                    uploadPuzzleBtn.disabled = false;
                                    // 显示拼图生成成功提示
                                    showToast('拼图生成成功', 'success');
                                }, 'image/png');
                            }
                        }
                    };

                    // 读取图片
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        img.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                });
            }

            // 更新拼图预览
            function updatePuzzlePreview() {
                if (selectedPuzzleImages.length === 0) {
                    puzzlePreview.innerHTML = '<p class="text-gray-500">请上传 2-9 张图片</p>';
                    return;
                }

                const { rows, cols } = getLayoutByImageCount(selectedPuzzleImages.length);

                let html = `<div class="grid grid-cols-${cols} gap-0 w-full h-full">`;

                for (let i = 0; i < selectedPuzzleImages.length; i++) {
                    html += `
                        <div class="aspect-square overflow-hidden">
                            <img src="" alt="拼图部分" class="w-full h-full object-cover puzzle-preview-image" data-index="${i}">
                        </div>
                    `;
                }

                html += '</div>';
                puzzlePreview.innerHTML = html;

                // 现在HTML已经插入到DOM中，再加载图片
                for (let i = 0; i < selectedPuzzleImages.length; i++) {
                    const reader = new FileReader();
                    reader.onload = (function(index) {
                        return function(e) {
                            const imgElement = document.querySelector(`.puzzle-preview-image[data-index="${index}"]`);
                            if (imgElement) {
                                imgElement.src = e.target.result;
                            }
                        };
                    })(i);
                    reader.readAsDataURL(selectedPuzzleImages[i]);
                }
            }



            // 上传拼图
            function uploadPuzzle(puzzleBlob, category, remark, uploadToast, uploadPuzzleBtn, originalBtnContent) {
                const formData = new FormData();
                formData.append('file[]', puzzleBlob, 'puzzle_' + Date.now() + '.png');
                formData.append('category', category);
                formData.append('remark', remark);
                // 添加API Key
                const apiKey = sessionStorage.getItem('apiKey') || localStorage.getItem('apiKey') || '';
                formData.append('api_key', apiKey);

                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);

                // 添加上传进度监听
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percentComplete = (e.loaded / e.total) * 100;
                        if (uploadToast) {
                            const progressBar = uploadToast.querySelector('.bg-primary');
                            if (progressBar) {
                                progressBar.style.width = percentComplete + '%';
                            }
                        }
                    }
                });

                xhr.onload = function() {
                    // 移除上传中提示
                    if (uploadToast && uploadToast.parentNode) {
                        uploadToast.parentNode.removeChild(uploadToast);
                    }

                    // 恢复上传按钮状态
                    if (uploadPuzzleBtn) {
                        uploadPuzzleBtn.disabled = false;
                    }

                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                showToast('拼图上传成功', 'success');
                                loadUploadHistory();
                                // 重置拼图UI
                                resetPuzzleUI();
                                // 关闭拼图模态框
                                puzzleModal.style.display = 'none';
                                
                                // 更新按钮显示为上传成功
                                if (uploadPuzzleBtn) {
                                    uploadPuzzleBtn.innerHTML = '<i class="fa fa-check mr-2"></i><span>上传成功</span>';
                                    // 3秒后恢复原始状态
                                    setTimeout(() => {
                                        uploadPuzzleBtn.innerHTML = originalBtnContent;
                                    }, 3000);
                                }
                            } else {
                                showToast(response.message, 'error');
                                // 恢复按钮原始状态
                                if (uploadPuzzleBtn) {
                                    uploadPuzzleBtn.innerHTML = originalBtnContent;
                                }
                            }
                        } catch (error) {
                            showToast('上传失败，请稍后重试', 'error');
                            // 恢复按钮原始状态
                            if (uploadPuzzleBtn) {
                                uploadPuzzleBtn.innerHTML = originalBtnContent;
                            }
                        }
                    } else {
                        showToast('上传失败，请稍后重试', 'error');
                        // 恢复按钮原始状态
                        if (uploadPuzzleBtn) {
                            uploadPuzzleBtn.innerHTML = originalBtnContent;
                        }
                    }
                };

                xhr.onerror = function() {
                    // 移除上传中提示
                    if (uploadToast && uploadToast.parentNode) {
                        uploadToast.parentNode.removeChild(uploadToast);
                    }
                    showToast('网络错误，请检查网络连接', 'error');
                    // 恢复按钮原始状态
                    if (uploadPuzzleBtn) {
                        uploadPuzzleBtn.disabled = false;
                        uploadPuzzleBtn.innerHTML = originalBtnContent;
                    }
                };

                xhr.send(formData);
            }

            // 图片查看功能
            function initImageView() {
                // 图片查看模态框相关元素
                const imageModal = document.getElementById('image-modal');
                const imagePreview = document.getElementById('image-preview');
                const imageTitle = document.getElementById('image-title');
                const imageCloseBtn = document.getElementById('image-close-btn');
                const imageCloseBtn2 = document.getElementById('image-close-btn2');
                
                // 打开图片查看模态框
                function openImageModal(url, filename, remark) {
                    // 检查文件是否为图片类型
                    const isImage = /\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i.test(filename);
                    const imageContent = document.getElementById('image-content');
                    const imageQrCode = document.getElementById('image-qr-code');
                    
                    // 清除之前的事件监听器，防止图片的onerror事件影响非图片显示
                    imagePreview.onerror = null;
                    
                    // 生成完整的文件地址
                    const fullFileUrl = window.location.origin + '/' + url;
                    // 生成二维码链接
                    const qrCodeUrl = 'http://www.alidll.com/ew/a.php?text=' + encodeURIComponent(fullFileUrl) + '&size=8&margin=1';
                    
                    if (isImage) {
                        // 是图片，显示预览
                        imagePreview.src = url;
                        imagePreview.style.display = 'block';
                        imageTitle.textContent = `查看图片：${filename}`;
                        
                        // 添加图片加载失败处理
                        imagePreview.onerror = function() {
                            // 图片加载失败，显示错误信息
                            imagePreview.src = '';
                            imagePreview.style.display = 'none';
                            
                            // 显示错误信息和二维码
                            imageQrCode.innerHTML = `
                                <div class="text-center p-6" style="min-width: 300px;">
                                    <i class="fa fa-exclamation-circle text-4xl text-red-500 mb-3"></i>
                                    <p class="text-sm text-gray-600 mb-4">图片文件不存在或已被删除</p>
                                    <img src="${qrCodeUrl}" alt="二维码" class="w-24 h-24 object-contain mx-auto">
                                </div>
                            `;
                            
                            // 检查授权状态
                            if (!checkAuth()) {
                                // 未授权，模糊处理二维码
                                const qrCodeElement = imageQrCode.querySelector('img');
                                if (qrCodeElement) {
                                    qrCodeElement.style.filter = 'blur(3px)';
                                }
                                
                                // 未授权，添加遮挡层覆盖整个内容
                                const overlay = document.createElement('div');
                                overlay.className = 'absolute inset-0 flex flex-col items-center justify-center bg-white bg-opacity-80 z-50';
                                overlay.innerHTML = `
                                    <p class="text-red-600 font-bold text-2xl mb-4">未授权</p>
                                    <p class="text-red-600 text-center">
                                        <span class="cursor-pointer underline" onclick="document.getElementById('login-modal-btn').click()">[点此输入]</span>正确的   <span class="cursor-pointer underline" onclick="document.getElementById('login-modal-btn').click()">[API KEY]</span> 以获得完整访问权限。
                                    </p>
                                `;
                                imageContent.appendChild(overlay);
                            }
                            
                            // 调整布局，将内容居中显示
                            imageContent.style.flexDirection = 'column';
                            imageQrCode.style.position = 'static';
                            imageQrCode.style.bottom = 'auto';
                            imageQrCode.style.right = 'auto';
                            imageQrCode.style.marginTop = '4px';
                            imageQrCode.style.minWidth = '300px';
                        };
                        
                        // 调整布局，将二维码放在图片右下角
                        imageContent.style.flexDirection = 'row';
                        imageQrCode.style.position = 'absolute';
                        imageQrCode.style.bottom = '4px';
                        imageQrCode.style.right = '4px';
                        imageQrCode.style.marginTop = '0';
                        imageQrCode.style.minWidth = 'auto';
                        
                        // 显示二维码
                        imageQrCode.innerHTML = `
                            <img src="${qrCodeUrl}" alt="二维码" class="w-24 h-24 object-contain">
                        `;
                    } else {
                        // 不是图片，显示提示信息
                        imagePreview.src = '';
                        imagePreview.style.display = 'none';
                        imageTitle.textContent = `查看文件：${filename}`;
                        
                        // 调整布局，将内容居中显示
                        imageContent.style.flexDirection = 'column';
                        imageQrCode.style.position = 'static';
                        imageQrCode.style.bottom = 'auto';
                        imageQrCode.style.right = 'auto';
                        imageQrCode.style.marginTop = '4px';
                        imageQrCode.style.minWidth = '300px';
                        
                        // 显示提示信息和二维码
                        imageQrCode.innerHTML = `
                            <div class="text-center p-6" style="min-width: 300px;">
                                <i class="fa fa-file-${getFileIcon(filename)} text-4xl text-gray-400 mb-3"></i>
                                <p class="text-sm text-gray-600 mb-4">该文件不是图片，无法预览</p>
                                <img src="${qrCodeUrl}" alt="二维码" class="w-24 h-24 object-contain mx-auto">
                            </div>
                        `;
                    }
                    
                    // 显示备注信息在底部
                    const imageRemark = document.getElementById('image-remark');
                    imageRemark.textContent = remark ? `备注：${remark}` : '';
                    
                    // 检查授权状态
                    if (!checkAuth()) {
                        // 模糊处理二维码
                        const qrCodeElement = imageQrCode.querySelector('img');
                        if (qrCodeElement) {
                            qrCodeElement.style.filter = 'blur(3px)';
                        }
                        // 未授权，添加遮挡层覆盖整个内容
                        const overlay = document.createElement('div');
                        overlay.className = 'absolute inset-0 flex flex-col items-center justify-center bg-white bg-opacity-80 z-50';
                        overlay.innerHTML = `
                            <p class="text-red-600 font-bold text-2xl mb-4">未授权</p>
                            <p class="text-red-600 text-center">
                                <span class="cursor-pointer underline" onclick="document.getElementById('login-modal-btn').click()">[点此输入]</span>正确的   <span class="cursor-pointer underline" onclick="document.getElementById('login-modal-btn').click()">[API KEY]</span> 以获得完整访问权限。
                            </p>
                        `;
                        imageContent.appendChild(overlay);
                    }
                    
                    imageModal.style.display = 'flex';
                }
                
                // 关闭图片查看模态框
                function closeImageModal() {
                    // 重置模态框状态
                    const imageContent = document.getElementById('image-content');
                    const imageQrCode = document.getElementById('image-qr-code');
                    
                    // 移除覆盖层
                    const overlay = imageContent.querySelector('.absolute.inset-0');
                    if (overlay) {
                        overlay.remove();
                    }
                    
                    // 移除二维码模糊效果
                    const qrCodeElement = imageQrCode.querySelector('img');
                    if (qrCodeElement) {
                        qrCodeElement.style.filter = 'none';
                    }
                    
                    imageModal.style.display = 'none';
                }
                
                // 为查看按钮添加点击事件
                document.addEventListener('click', function(e) {
                    if (e.target.closest('.view-image-btn')) {
                        const button = e.target.closest('.view-image-btn');
                        const url = button.getAttribute('data-url');
                        const filename = button.getAttribute('data-filename');
                        const remark = button.getAttribute('data-remark') || '';
                        openImageModal(url, filename, remark);
                    }
                });
                
                // 关闭按钮点击事件
                imageCloseBtn.addEventListener('click', closeImageModal);
                imageCloseBtn2.addEventListener('click', closeImageModal);
                
                // 点击模态框外部关闭
                imageModal.addEventListener('click', function(e) {
                    if (e.target === imageModal) {
                        closeImageModal();
                    }
                });
            }

            // 初始化分类标签选择
            function initCategorySelection() {
                const categoryRadios = document.querySelectorAll('input[name="category"]');
                const selectedCategoryElement = document.getElementById('selected-category');
                
                // 添加标签选择事件
                categoryRadios.forEach(radio => {
                    radio.addEventListener('change', function() {
                        if (this.checked) {
                            // 更新显示的目录路径
                            if (selectedCategoryElement) {
                                selectedCategoryElement.textContent = this.value;
                            }
                            // 更新标签样式
                            categoryRadios.forEach(r => {
                                const label = r.closest('label');
                                if (r.checked) {
                                    label.classList.remove('border-gray-300', 'hover:bg-blue-50');
                                    label.classList.add('border-primary', 'bg-blue-50', 'hover:bg-blue-100');
                                } else {
                                    label.classList.remove('border-primary', 'bg-blue-50', 'hover:bg-blue-100');
                                    label.classList.add('border-gray-300', 'hover:bg-blue-50');
                                }
                            });
                        }
                    });
                });
            }

            // 存储要删除的文件信息
            let fileToDelete = { url: '', filename: '' };
            // 存储当前操作类型
            let currentOperation = null;

            // 初始化删除文件功能
            function initDeleteFile() {
                // 为删除按钮添加点击事件
                document.addEventListener('click', function(e) {
                    if (e.target.closest('.delete-file-btn')) {
                        // 检查授权
                        if (!checkAuth()) {
                            unauthorizedModal.style.display = 'flex';
                            return;
                        }

                        // 检查是否允许删除
                        if (!allowDelete()) {
                            showToast('删除功能已被禁止', 'error');
                            return;
                        }

                        const button = e.target.closest('.delete-file-btn');
                        const url = button.getAttribute('data-url');
                        const filename = button.getAttribute('data-filename');
                        
                        // 存储文件信息
                        fileToDelete = { url, filename };
                        
                        // 检查是否需要密码
                        if (requireDeletePassword()) {
                            // 需要密码，打开密码模态框
                            passwordModal.style.display = 'flex';
                        } else {
                            // 不需要密码，直接删除
                            deleteFile(url, filename);
                        }
                    }
                });
            }

            // 获取删除密码
            function getDeletePassword() {
                return localStorage.getItem('deletePassword') || '0000';
            }

            // 处理密码确认
            function initPasswordModal() {
                // 获取密码模态框相关元素
                const passwordModal = document.getElementById('password-modal');
                const deletePasswordInput = document.getElementById('delete-password');
                const cancelPasswordBtn = document.getElementById('cancel-password-btn');
                const confirmPasswordBtn = document.getElementById('confirm-password-btn');
                
                // 检查元素是否存在
                if (!passwordModal || !deletePasswordInput || !cancelPasswordBtn || !confirmPasswordBtn) {
                    console.error('密码模态框元素未找到');
                    return;
                }
                
                // 取消按钮点击事件
                cancelPasswordBtn.addEventListener('click', function() {
                    passwordModal.style.display = 'none';
                    deletePasswordInput.value = '';
                    fileToDelete = { url: '', filename: '' };
                    currentDeleteFile = null;
                    currentOperation = null;
                });
                
                // 确认按钮点击事件
                confirmPasswordBtn.addEventListener('click', function() {
                    const password = deletePasswordInput.value.trim();
                    
                    // 获取用户自定义的删除密码
                    const deletePassword = getDeletePassword();
                    
                    // 简单的密码验证（实际应用中应该使用更安全的验证方式）
                    if (password === deletePassword) {
                        // 密码正确，执行操作
                        if (currentOperation === 'clearAll') {
                            // 执行清空操作
                            showClearAllConfirm();
                        } else if (fileToDelete.url) {
                            // 使用deleteFile函数删除文件
                            deleteFile(fileToDelete.url, fileToDelete.filename);
                        } else if (currentDeleteFile) {
                            // 使用deleteFile函数删除文件
                            deleteFile(currentDeleteFile.url, currentDeleteFile.filename);
                        }
                        
                        // 关闭密码模态框
                        passwordModal.style.display = 'none';
                        deletePasswordInput.value = '';
                        fileToDelete = { url: '', filename: '' };
                        currentDeleteFile = null;
                        currentOperation = null;
                    } else {
                        // 密码错误，显示错误提示
                        showAlert('密码错误，请重试', 'error');
                    }
                });
                
                // 点击模态框外部关闭
                passwordModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        passwordModal.style.display = 'none';
                        deletePasswordInput.value = '';
                        fileToDelete = { url: '', filename: '' };
                        currentDeleteFile = null;
                        currentOperation = null;
                    }
                });
            }

            // 显示删除确认弹出层
            function showDeleteConfirm(filename) {
                const alertIcon = document.getElementById('alert-icon');
                const alertTitle = document.getElementById('alert-title');
                const alertContent = document.getElementById('alert-content');
                const alertCloseBtn = document.getElementById('alert-close-btn');
                
                alertTitle.textContent = '确认删除';
                alertIcon.className = 'fa fa-exclamation-triangle text-lg text-warning mr-2';
                alertContent.innerHTML = `
                    <p class="mb-4">确定要删除文件 "${filename}" 吗？</p>
                    <p class="text-sm text-gray-600">此操作不可撤销，删除后文件将无法恢复。</p>
                `;
                alertCloseBtn.textContent = '确认删除';
                
                // 添加取消按钮
                const alertModal = document.getElementById('alert-modal');
                const modalFooter = alertModal.querySelector('.border-t');
                
                // 检查是否已有取消按钮
                let cancelBtn = modalFooter.querySelector('.btn-secondary');
                if (!cancelBtn) {
                    cancelBtn = document.createElement('button');
                    cancelBtn.className = 'btn btn-secondary px-5 py-1.5 text-sm mr-3';
                    cancelBtn.textContent = '取消';
                    cancelBtn.addEventListener('click', function() {
                        alertModal.style.display = 'none';
                        // 重置文件信息
                        fileToDelete = { url: '', filename: '' };
                    });
                    modalFooter.insertBefore(cancelBtn, alertCloseBtn);
                } else {
                    // 显示取消按钮
                    cancelBtn.style.display = 'inline-block';
                }
                
                alertModal.style.display = 'flex';
            }

            // 显示提示信息
            function showAlert(message, type = 'info') {
                // 创建提示元素
                const toast = document.createElement('div');
                toast.className = 'bg-white rounded-lg shadow-lg p-4 flex items-center space-x-3 animate-fadeIn transform transition-all duration-300';
                
                // 设置图标
                let iconClass = '';
                if (type === 'success') {
                    iconClass = 'fa fa-check-circle text-success';
                } else if (type === 'error') {
                    iconClass = 'fa fa-exclamation-circle text-danger';
                } else {
                    iconClass = 'fa fa-info-circle text-primary';
                }
                
                // 设置内容
                toast.innerHTML = `
                    <i class="${iconClass}"></i>
                    <span class="text-sm">${message}</span>
                `;
                
                // 添加到容器
                const toastContainer = document.getElementById('toast-container');
                toastContainer.appendChild(toast);
                
                // 3秒后自动关闭
                setTimeout(() => {
                    // 添加关闭动画
                    toast.classList.add('opacity-0', 'translate-x-10');
                    // 动画结束后移除元素
                    setTimeout(() => {
                        toastContainer.removeChild(toast);
                    }, 300);
                }, 3000);
            }





            // 初始化登录功能
            function initLoginFunctionality() {
                // 登录模态框相关元素
                const loginModal = document.getElementById('login-modal');
                const loginModalBtn = document.getElementById('login-modal-btn');
                const closeLoginModalBtn = document.getElementById('close-login-modal-btn');
                const cancelLoginBtn = document.getElementById('cancel-login-btn');
                const confirmLoginBtn = document.getElementById('confirm-login-btn');
                // 未授权模态框相关元素
                // 使用全局变量 unauthorizedModal
                const closeUnauthorizedBtn = document.getElementById('close-unauthorized-btn');
                const goLoginBtn = document.getElementById('go-login-btn');



                // 登录按钮点击事件已在 initDropdown 函数中处理
                
                // 确保在未授权状态下显示登录模态框时的正确状态
                function openLoginModal() {
                    loginModal.style.display = 'flex';
                    // 获取元素
                    const apiKeyContainer = document.getElementById('api-key-container');
                    const authButtons = document.getElementById('auth-buttons');
                    const addApiKeyContainer = document.getElementById('add-api-key-container');
                    
                    // 加载删除权限设置
                    loadDeletePermissionSetting();
                    
                    // 未验证状态
                    apiKeyContainer.classList.remove('hidden');
                    authButtons.classList.remove('hidden');
                    if (addApiKeyContainer) {
                        addApiKeyContainer.classList.add('hidden');
                    }
                }

                // 注销按钮点击事件已移至initDropdown函数中
                
                // 清理过期文件按钮点击事件
                const cleanExpiredBtn = document.getElementById('clean-expired-btn');
                if (cleanExpiredBtn) {
                    cleanExpiredBtn.addEventListener('click', function() {
                        // 发送请求清理过期文件
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', window.location.href, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        
                        xhr.onload = function() {
                            if (xhr.status === 200) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    if (response.success) {
                                        showToast('过期文件清理成功', 'success');
                                        // 重新加载上传历史
                                        loadUploadHistory();
                                    } else {
                                        showToast(response.message, 'error');
                                    }
                                } catch (error) {
                                    showToast('清理失败，请稍后重试', 'error');
                                }
                            } else {
                                showToast('清理失败，请稍后重试', 'error');
                            }
                        };
                        
                        xhr.onerror = function() {
                            showToast('网络错误，请检查网络连接', 'error');
                        };
                        
                        xhr.send('action=clean_expired_files');
                    });
                }

                // 添加 API Key 按钮点击事件
                const addApiKeyBtn = document.getElementById('add-api-key-btn');
                if (addApiKeyBtn) {
                    addApiKeyBtn.addEventListener('click', function() {
                        const addApiKeyContainer = document.getElementById('add-api-key-container');
                        const apiKeyVerified = document.getElementById('api-key-verified');
                        if (addApiKeyContainer && apiKeyVerified) {
                            apiKeyVerified.classList.add('hidden');
                            addApiKeyContainer.classList.remove('hidden');
                        }
                    });
                }

                // 取消添加 API Key 按钮点击事件
                const cancelAddApiKeyBtn = document.getElementById('cancel-add-api-key-btn');
                if (cancelAddApiKeyBtn) {
                    cancelAddApiKeyBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        // 关闭登录模态框
                        closeLoginModal();
                    });
                }

                // 生成随机API Key
                function generateRandomApiKey() {
                    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                    let result = '';
                    for (let i = 0; i < 8; i++) {
                        result += chars.charAt(Math.floor(Math.random() * chars.length));
                    }
                    return result;
                }
                
                // 随机生成API Key按钮点击事件
                const generateApiKeyBtn = document.getElementById('generate-api-key-btn');
                if (generateApiKeyBtn) {
                    generateApiKeyBtn.addEventListener('click', function() {
                        const newApiKeyInput = document.getElementById('new-api-key');
                        const randomKey = generateRandomApiKey();
                        newApiKeyInput.value = randomKey;
                    });
                }
                
                // 确认添加 API Key 按钮点击事件
                const confirmAddApiKeyBtn = document.getElementById('confirm-add-api-key-btn');
                if (confirmAddApiKeyBtn) {
                    confirmAddApiKeyBtn.addEventListener('click', function() {
                        const adminPassword = document.getElementById('admin-password').value.trim();
                        const newApiKey = document.getElementById('new-api-key').value.trim();
                        const newApiKeyName = document.getElementById('new-api-key-name').value.trim();

                        if (!adminPassword) {
                            showToast('请输入管理员密码', 'error');
                            return;
                        }

                        if (!newApiKey) {
                            showToast('请输入新的 API Key', 'error');
                            return;
                        }

                        if (!newApiKeyName) {
                            showToast('请输入 API Key 名称', 'error');
                            return;
                        }

                        // 发送请求添加 API Key（密码验证在后端进行）
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', window.location.href, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        
                        xhr.onload = function() {
                            if (xhr.status === 200) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    if (response.success) {
                                        showToast('API Key 添加成功', 'success');
                                        // 隐藏添加 API Key 容器，显示已验证状态
                                        const addApiKeyContainer = document.getElementById('add-api-key-container');
                                        const apiKeyVerified = document.getElementById('api-key-verified');
                                        if (addApiKeyContainer && apiKeyVerified) {
                                            addApiKeyContainer.classList.add('hidden');
                                            apiKeyVerified.classList.remove('hidden');
                                        }
                                    } else {
                                        showToast(response.message, 'error');
                                    }
                                } catch (error) {
                                    showToast('添加失败，请稍后重试', 'error');
                                }
                            } else {
                                showToast('添加失败，请稍后重试', 'error');
                            }
                        };
                        
                        xhr.onerror = function() {
                            showToast('网络错误，请检查网络连接', 'error');
                        };
                        
                        xhr.send(`action=add_api_key&api_key=${encodeURIComponent(newApiKey)}&name=${encodeURIComponent(newApiKeyName)}&admin_password=${encodeURIComponent(adminPassword)}`);
                    });
                }

                // 关闭登录模态框
                function closeLoginModal() {
                    loginModal.style.display = 'none';
                    // 清空输入
                    document.getElementById('login-api-key').value = '';
                    if (document.getElementById('admin-password')) {
                        document.getElementById('admin-password').value = '';
                    }
                    if (document.getElementById('new-api-key')) {
                        document.getElementById('new-api-key').value = '';
                    }
                    if (document.getElementById('new-api-key-name')) {
                        document.getElementById('new-api-key-name').value = '';
                    }
                    // 重置添加 API Key 容器
                    const addApiKeyContainer = document.getElementById('add-api-key-container');
                    const apiKeyVerified = document.getElementById('api-key-verified');
                    if (addApiKeyContainer && apiKeyVerified) {
                        addApiKeyContainer.classList.add('hidden');
                        apiKeyVerified.classList.remove('hidden');
                    }
                }

                closeLoginModalBtn.addEventListener('click', closeLoginModal);
                // 启用取消按钮的关闭功能
                cancelLoginBtn.addEventListener('click', closeLoginModal);

                // 禁用点击模态框外部关闭
                loginModal.addEventListener('click', function(e) {
                    if (e.target === loginModal) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });

                // 生成 API Key 哈希值
                function generateApiKeyHash(apiKey) {
                    return simpleHash(apiKey);
                }

                // 验证按钮点击事件
                confirmLoginBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const apiKey = document.getElementById('login-api-key').value.trim();

                    if (!apiKey) {
                        showToast('请输入 API Key', 'error');
                        return;
                    }

                    // 禁用按钮，防止重复点击
                    confirmLoginBtn.disabled = true;
                    confirmLoginBtn.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i> 验证中...';

                    try {
                        // 生成 API Key 哈希值
                        const apiKeyHash = generateApiKeyHash(apiKey);

                        // 验证 API Key
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', window.location.href, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        
                        xhr.onload = function() {
                            // 恢复按钮状态
                            confirmLoginBtn.disabled = false;
                            confirmLoginBtn.innerHTML = '授权';
                            
                            if (xhr.status === 200) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    if (response.success) {
                                        // 验证成功，存储 API Key 哈希值到本地存储
                                        localStorage.setItem('apiKeyHash', apiKeyHash);
                                        // 存储原始 API Key 用于显示名称
                                        localStorage.setItem('apiKey', apiKey);
                                        sessionStorage.setItem('apiKey', apiKey);
                                        showToast('验证成功', 'success');
                                        closeLoginModal();
                                        
                                        // 延迟执行后续操作，避免并发请求
                                        setTimeout(() => {
                                            // 加载上传列表
                                            loadUploadHistory();
                                            // 更新页脚 API Key 名称
                                            updateFooterApiKeyName();
                                            // 更新登录按钮状态
                                            updateLoginButton();
                                            // 直接调用checkAdminStatus()，确保管理员菜单立即显示
                                            checkAdminStatus();
                                        }, 500);
                                    } else {
                                        showToast('API Key 错误', 'error');
                                    }
                                } catch (error) {
                                    console.error('解析响应失败:', error);
                                    showToast('验证失败，请稍后重试', 'error');
                                }
                            } else {
                                showToast('验证失败，请稍后重试', 'error');
                            }
                        };
                        
                        xhr.onerror = function() {
                            // 恢复按钮状态
                            confirmLoginBtn.disabled = false;
                            confirmLoginBtn.innerHTML = '授权';
                            showToast('网络错误，请检查网络连接', 'error');
                        };
                        
                        xhr.send(`action=verify_api_key&api_key=${encodeURIComponent(apiKey)}&api_key_hash=${encodeURIComponent(apiKeyHash)}`);
                    } catch (error) {
                        // 恢复按钮状态
                        confirmLoginBtn.disabled = false;
                        confirmLoginBtn.innerHTML = '授权';
                        console.error('哈希值生成失败:', error);
                        showToast('验证失败，请稍后重试', 'error');
                    }
                });

                // 关闭未授权模态框
                closeUnauthorizedBtn.addEventListener('click', function() {
                    unauthorizedModal.style.display = 'none';
                });

                // 前往登录按钮点击事件
                goLoginBtn.addEventListener('click', function() {
                    unauthorizedModal.style.display = 'none';
                    openLoginModal();
                });

                // 点击未授权模态框外部关闭
                unauthorizedModal.addEventListener('click', function(e) {
                    if (e.target === unauthorizedModal) {
                        unauthorizedModal.style.display = 'none';
                    }
                });

                // 加载删除权限设置
                function loadDeletePermissionSetting() {
                    const deletePermission = localStorage.getItem('deletePermission') || 'y';
                    // 加载认证前的删除权限设置
                    const selectBeforeAuth = document.querySelector('#api-key-container #delete-permission');
                    if (selectBeforeAuth) {
                        selectBeforeAuth.value = deletePermission;
                    }
                    // 加载删除密码
                    loadDeletePassword();
                    // 显示或隐藏删除密码输入字段
                    toggleDeletePasswordContainer();
                }

                // 保存删除权限设置
                function saveDeletePermissionSetting(permission) {
                    localStorage.setItem('deletePermission', permission);
                    // 显示或隐藏删除密码输入字段
                    toggleDeletePasswordContainer();
                }

                // 加载删除密码
                function loadDeletePassword() {
                    const deletePassword = localStorage.getItem('deletePassword') || '0000';
                    const passwordInput = document.getElementById('delete-password-input');
                    if (passwordInput) {
                        passwordInput.value = deletePassword;
                    }
                }

                // 保存删除密码
                function saveDeletePassword(password) {
                    localStorage.setItem('deletePassword', password || '0000');
                    
                    // 同步到数据库
                    const apiKey = document.getElementById('login-api-key').value;
                    if (apiKey) {
                        fetch('?action=update_delete_password', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: `api_key=${encodeURIComponent(apiKey)}&delete_password=${encodeURIComponent(password || '0000')}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (!data.success) {
                                console.error('删除密码同步失败:', data.message);
                            }
                        })
                        .catch(error => {
                            console.error('删除密码同步失败:', error);
                        });
                    }
                }

                // 显示或隐藏删除密码输入字段
                function toggleDeletePasswordContainer() {
                    const deletePermission = getDeletePermission();
                    const passwordContainer = document.getElementById('delete-password-container');
                    const passwordHint = document.querySelector('#delete-password-container + p');
                    if (passwordContainer) {
                        if (deletePermission === 'y') {
                            passwordContainer.style.display = 'flex';
                            if (passwordHint) {
                                passwordHint.style.display = 'block';
                            }
                        } else {
                            passwordContainer.style.display = 'none';
                            if (passwordHint) {
                                passwordHint.style.display = 'none';
                            }
                        }
                    }
                }

                // 初始化删除权限设置事件
                function initDeletePermissionSetting() {
                    // 为认证前的删除权限设置添加事件监听
                    const selectBeforeAuth = document.querySelector('#api-key-container #delete-permission');
                    if (selectBeforeAuth) {
                        selectBeforeAuth.addEventListener('change', function() {
                            saveDeletePermissionSetting(this.value);
                            let message = '';
                            switch(this.value) {
                                case 'y':
                                    message = '删除时需要密码';
                                    break;
                                case 'n':
                                    message = '删除时不需要密码';
                                    break;
                                case 'disabled':
                                    message = '已禁止删除';
                                    break;
                            }
                            showToast(message, 'success');
                        });
                    }
                    
                    // 为删除密码输入字段添加事件监听
                    const passwordInput = document.getElementById('delete-password-input');
                    if (passwordInput) {
                        passwordInput.addEventListener('change', function() {
                            saveDeletePassword(this.value);
                            showToast('删除密码已更新', 'success');
                        });
                    }
                }

                // 获取删除权限设置
                function getDeletePermission() {
                    return localStorage.getItem('deletePermission') || 'y';
                }

                // 检查是否允许删除
                function allowDelete() {
                    const permission = getDeletePermission();
                    return permission !== 'disabled';
                }

                // 检查是否需要删除密码
                function requireDeletePassword() {
                    const permission = getDeletePermission();
                    return permission === 'y';
                }

                // 更新登录按钮状态
                function updateLoginButton() {
                    const loginModalBtn = document.getElementById('login-modal-btn');
                    if (checkAuth()) {
                        // 显示用户图标而不是文本
                        document.getElementById('auth-status').innerHTML = '<i class="fa fa-user-circle mr-1"></i>';
                        // 移除背景色，保持与其他菜单按钮一致的样式
                        loginModalBtn.classList.remove('bg-green-600');
                        // 确保按钮样式与其他菜单一致
                        loginModalBtn.classList.add('hover:bg-blue-700');
                        // 加载删除权限设置
                        loadDeletePermissionSetting();
                    } else {
                        // 显示登录图标而不是文本
                        document.getElementById('auth-status').innerHTML = '<i class="fa fa-sign-in mr-1"></i>';
                        // 显示"登录"文本
                        document.getElementById('auth-status').innerHTML += '登录';
                        loginModalBtn.classList.remove('bg-green-600');
                        // 确保按钮样式与其他菜单一致
                        loginModalBtn.classList.add('hover:bg-blue-700');
                        // 加载删除权限设置
                        loadDeletePermissionSetting();
                    }
                }
                
                // 初始化下拉菜单
                function initDropdown() {
                    const loginModalBtn = document.getElementById('login-modal-btn');
                    const authDropdown = document.getElementById('auth-dropdown');
                    
                    // 切换下拉菜单
                    loginModalBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        if (checkAuth()) {
                            // 已授权状态，显示下拉菜单
                            authDropdown.classList.toggle('hidden');
                        } else {
                            // 未授权状态，打开登录模态框
                            openLoginModal();
                        }
                    });
                    
                    // 点击其他地方关闭下拉菜单
                    document.addEventListener('click', function(e) {
                        if (!loginModalBtn.contains(e.target) && !authDropdown.contains(e.target)) {
                            authDropdown.classList.add('hidden');
                        }
                    });
                    
                    // 下拉菜单选项点击事件
                    document.getElementById('auth-info').addEventListener('click', function(e) {
                        e.preventDefault();
                        // 关闭其他模态框
                        const modalsToClose = ['upload-modal', 'puzzle-modal', 'admin-modal', 'login-modal'];
                        modalsToClose.forEach(modalId => {
                            const modal = document.getElementById(modalId);
                            if (modal) {
                                modal.style.display = 'none';
                            }
                        });
                        // 显示API Key信息模态框
                        const alertModal = document.getElementById('alert-modal');
                        const alertTitle = document.getElementById('alert-title');
                        const alertContent = document.getElementById('alert-content');
                        const alertCloseBtn = document.getElementById('alert-close-btn');
                        
                        alertTitle.textContent = 'API Key 信息';
                        
                        // 获取API Key信息
                        const apiKey = sessionStorage.getItem('apiKey') || localStorage.getItem('apiKey');
                        const apiKeyHash = localStorage.getItem('apiKeyHash');
                        
                        if (apiKey && apiKeyHash) {
                            // 发送请求获取 API Key 名称
                            fetch(window.location.href, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: `action=get_api_key_name&api_key=${encodeURIComponent(apiKey)}&api_key_hash=${encodeURIComponent(apiKeyHash)}`
                            })
                            .then(response => response.json())
                            .then(data => {
                                let apiKeyName = '未知';
                                if (data.success && data.name) {
                                    apiKeyName = data.name;
                                }
                                
                                // 处理API Key显示，只显示前后各3位，不足的用**代替
                                function formatApiKey(key) {
                                    if (key.length < 3) {
                                        return '**';
                                    } else if (key.length <= 6) {
                                        return key.substring(0, 3) + '***';
                                    } else {
                                        return key.substring(0, 3) + '***' + key.substring(key.length - 3);
                                    }
                                }
                                
                                const formattedApiKey = formatApiKey(apiKey);
                                
                                alertContent.innerHTML = `
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <p class="text-sm font-medium text-gray-700 w-24">用户：</p>
                                            <p class="text-sm font-normal text-gray-900 flex-1">${apiKeyName}</p>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <p class="text-sm font-medium text-gray-700 w-24">API Key：</p>
                                            <p class="text-sm font-normal text-gray-900 flex-1">${formattedApiKey}</p>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <p class="text-sm font-medium text-gray-700 w-24">状态：</p>
                                            <p class="text-sm font-normal text-green-600 flex-1">已授权</p>
                                        </div>
                                    </div>
                                `;
                                
                                // 设置按钮标题为"关闭"
                                alertCloseBtn.textContent = '关闭';
                                // 重置按钮的点击事件处理函数为默认行为
                                alertCloseBtn.onclick = function() {
                                    alertModal.style.display = 'none';
                                };
                                
                                // 移除取消按钮
                                const modalFooter = alertModal.querySelector('.border-t');
                                let cancelBtn = modalFooter.querySelector('.btn-secondary');
                                if (cancelBtn) {
                                    cancelBtn.style.display = 'none';
                                }
                                
                                alertModal.style.display = 'flex';
                            })
                            .catch(error => {
                                // 处理API Key显示，只显示前后各3位，不足的用**代替
                                function formatApiKey(key) {
                                    if (key.length < 3) {
                                        return '**';
                                    } else if (key.length <= 6) {
                                        return key.substring(0, 3) + '***';
                                    } else {
                                        return key.substring(0, 3) + '***' + key.substring(key.length - 3);
                                    }
                                }
                                
                                const formattedApiKey = formatApiKey(apiKey);
                                
                                alertContent.innerHTML = `
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <p class="text-sm font-medium text-gray-700 w-24">用户：</p>
                                            <p class="text-sm font-normal text-gray-900 flex-1">管理员</p>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <p class="text-sm font-medium text-gray-700 w-24">API Key：</p>
                                            <p class="text-sm font-normal text-gray-900 flex-1">${formattedApiKey}</p>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <p class="text-sm font-medium text-gray-700 w-24">状态：</p>
                                            <p class="text-sm font-normal text-green-600 flex-1">已授权</p>
                                        </div>
                                    </div>
                                `;
                                
                                // 设置按钮标题为"关闭"
                                alertCloseBtn.textContent = '关闭';
                                // 重置按钮的点击事件处理函数为默认行为
                                alertCloseBtn.onclick = function() {
                                    alertModal.style.display = 'none';
                                };
                                
                                // 移除取消按钮
                                const modalFooter = alertModal.querySelector('.border-t');
                                let cancelBtn = modalFooter.querySelector('.btn-secondary');
                                if (cancelBtn) {
                                    cancelBtn.style.display = 'none';
                                }
                                
                                alertModal.style.display = 'flex';
                            });
                        } else {
                            alertContent.innerHTML = `
                                <div class="space-y-4">
                                    <div>
                                        <p class="text-sm font-medium text-gray-700">状态：<span class="font-normal text-red-600">未授权</span></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">请先授权后查看API Key信息</p>
                                    </div>
                                </div>
                            `;
                            
                            // 设置按钮标题为"关闭"
                            alertCloseBtn.textContent = '关闭';
                            // 重置按钮的点击事件处理函数为默认行为
                            alertCloseBtn.onclick = function() {
                                alertModal.style.display = 'none';
                            };
                            
                            // 移除取消按钮
                            const modalFooter = alertModal.querySelector('.border-t');
                            let cancelBtn = modalFooter.querySelector('.btn-secondary');
                            if (cancelBtn) {
                                cancelBtn.style.display = 'none';
                            }
                            
                            alertModal.style.display = 'flex';
                        }
                        
                        authDropdown.classList.add('hidden');
                    });
                    
                    document.getElementById('clear-all-btn').addEventListener('click', function(e) {
                        e.preventDefault();
                        // 执行清空操作
                        if (!checkAuth()) {
                            unauthorizedModal.style.display = 'flex';
                            return;
                        }
                        
                        // 检查是否允许删除
                        if (!allowDelete()) {
                            showToast('删除功能已被禁止', 'error');
                            return;
                        }
                        
                        // 关闭下拉菜单
                        authDropdown.classList.add('hidden');
                        
                        // 检查是否需要密码
                        if (requireDeletePassword()) {
                            // 需要密码，打开密码模态框
                            // 存储操作类型为清空
                            currentOperation = 'clearAll';
                            passwordModal.style.display = 'flex';
                        } else {
                            // 不需要密码，直接显示确认模态框
                            showClearAllConfirm();
                        }
                    });
                    
                    document.getElementById('clean-expired-btn').addEventListener('click', function(e) {
                        e.preventDefault();
                        // 执行清理过期文件操作
                        if (!checkAuth()) {
                            unauthorizedModal.style.display = 'flex';
                            return;
                        }
                        
                        // 关闭下拉菜单
                        authDropdown.classList.add('hidden');
                        
                        // 发送请求清理过期文件
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', window.location.href, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        
                        xhr.onload = function() {
                            if (xhr.status === 200) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    if (response.success) {
                                        showToast('过期文件清理成功', 'success');
                                        // 重新加载上传历史
                                        loadUploadHistory();
                                    } else {
                                        showToast(response.message, 'error');
                                    }
                                } catch (error) {
                                    showToast('清理失败，请稍后重试', 'error');
                                }
                            } else {
                                showToast('清理失败，请稍后重试', 'error');
                            }
                        };
                        
                        xhr.onerror = function() {
                            showToast('网络错误，请检查网络连接', 'error');
                        };
                        
                        xhr.send('action=clean_expired_files');
                    });
                    
                    document.getElementById('logout-btn').addEventListener('click', function(e) {
                        e.preventDefault();
                        // 关闭所有弹出层
                        const modals = [
                            'upload-modal', 'login-modal', 'unauthorized-modal',
                            'password-modal', 'image-modal', 'puzzle-modal',
                            'admin-modal', 'alert-modal', 'database-config-modal', 'ddns-modal', 'ddns-config-modal'
                        ];
                        modals.forEach(modalId => {
                            const modal = document.getElementById(modalId);
                            if (modal) {
                                modal.style.display = 'none';
                            }
                        });
                        
                        // 执行注销操作
                        // 清除本地存储中的API Key和哈希值
                        localStorage.removeItem('apiKey');
                        localStorage.removeItem('apiKeyHash');
                        sessionStorage.removeItem('apiKey');
                        showToast('已注销', 'success');
                        // 更新登录按钮状态
                        updateLoginButton();
                        // 更新页脚API Key名称
                        updateFooterApiKeyName();
                        // 重新加载上传历史，根据新的授权状态过滤
                        loadUploadHistory();
                        // 关闭下拉菜单
                        authDropdown.classList.add('hidden');
                    });
                    
                    // 更新DDNS按钮点击事件
                    document.getElementById('update-ddns-btn').addEventListener('click', function(e) {
                        e.preventDefault();
                        // 关闭下拉菜单
                        authDropdown.classList.add('hidden');
                        // 关闭其他模态框
                        const modalsToClose = ['upload-modal', 'puzzle-modal', 'admin-modal', 'login-modal', 'alert-modal'];
                        modalsToClose.forEach(modalId => {
                            const modal = document.getElementById(modalId);
                            if (modal) {
                                modal.style.display = 'none';
                            }
                        });
                        // 打开DDNS更新模态框
                        const ddnsModal = document.getElementById('ddns-modal');
                        if (ddnsModal) {
                            ddnsModal.style.display = 'flex';
                            // 检测IP
                            checkDdnsIp();
                            // 加载配置
                            loadDdnsConfig();
                            // 加载上次更新信息
                            loadDdnsLastUpdate();
                        }
                    });
                    
                    // 添加KEY按钮点击事件
                    document.getElementById('add-key-btn').addEventListener('click', function(e) {
                        e.preventDefault();
                        // 关闭其他模态框
                        const modalsToClose = ['upload-modal', 'puzzle-modal', 'admin-modal', 'alert-modal'];
                        modalsToClose.forEach(modalId => {
                            const modal = document.getElementById(modalId);
                            if (modal) {
                                modal.style.display = 'none';
                            }
                        });
                        // 打开登录模态框（显示添加API Key界面）
                        const loginModal = document.getElementById('login-modal');
                        if (loginModal) {
                            loginModal.style.display = 'flex';
                            // 显示添加API Key界面
                            const apiKeyContainer = document.getElementById('api-key-container');
                            const authButtons = document.getElementById('auth-buttons');
                            const addApiKeyContainer = document.getElementById('add-api-key-container');
                            
                            // 直接显示添加API Key选项
                            apiKeyContainer.classList.add('hidden');
                            authButtons.classList.add('hidden');
                            if (addApiKeyContainer) {
                                addApiKeyContainer.classList.remove('hidden');
                            }
                        }
                        // 关闭下拉菜单
                        authDropdown.classList.add('hidden');
                    });
                    
                    // 管理KEY按钮点击事件
                    const manageKeyBtn = document.getElementById('manage-key-btn');
                   
                    if (manageKeyBtn) {
                        manageKeyBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                       
                            // 关闭上传和拼图模态框
                            const uploadModal = document.getElementById('upload-modal');
                            if (uploadModal) {
                                uploadModal.style.display = 'none';
                            }
                            const puzzleModal = document.getElementById('puzzle-modal');
                            if (puzzleModal) {
                                puzzleModal.style.display = 'none';
                            }
                            
                            // 打开管理员模态框（显示KEY列表）
                            const adminModal = document.getElementById('admin-modal');
                        
                            if (adminModal) {
                                
                                loadApiKeys();
                                
                                adminModal.style.display = 'flex';
                              
                            }
                            // 关闭下拉菜单
                            authDropdown.classList.add('hidden');
                       
                        });
                    } else {
                       
                    }
                }

                // 初始化登录按钮状态
                updateLoginButton();
                // 初始化删除权限设置事件
                initDeletePermissionSetting();
                // 初始化下拉菜单
                initDropdown();

                // 导出检查授权函数
                window.checkAuth = checkAuth;
                // 导出未授权模态框
                window.unauthorizedModal = unauthorizedModal;
                // 导出删除权限相关函数
                window.allowDelete = allowDelete;
                window.requireDeletePassword = requireDeletePassword;
            }
            
            // DDNS模态框关闭按钮点击事件
            const closeDdnsModalBtn2 = document.getElementById('close-ddns-modal-btn2');
            const ddnsModal = document.getElementById('ddns-modal');
            if (closeDdnsModalBtn2 && ddnsModal) {
                closeDdnsModalBtn2.addEventListener('click', function() {
                    ddnsModal.style.display = 'none';
                });
            }
            
            // 刷新IP按钮点击事件
            const ddnsRefreshIpBtn = document.getElementById('ddns-refresh-ip-btn');
            if (ddnsRefreshIpBtn) {
                ddnsRefreshIpBtn.addEventListener('click', function() {
                    checkDdnsIp();
                });
            }
            

            
            // 更新DDNS按钮点击事件
            const ddnsUpdateBtn = document.getElementById('ddns-update-btn');
            if (ddnsUpdateBtn) {
                ddnsUpdateBtn.addEventListener('click', function() {
                    updateDdns();
                });
            }
            
            // 检测IP
            function checkDdnsIp() {
                const ipStatus = document.getElementById('ddns-ip-status');
                const publicIp = document.getElementById('ddns-public-ip');
                
                ipStatus.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i><span>检测IP中...</span>';
                ipStatus.className = 'flex items-center justify-center p-3 rounded-lg bg-gray-100';
                
                // 直接从客户端获取外网IP，按响应速度排序
                fetch('https://ifconfig.me/ip')
                    .then(response => response.text())
                    .then(ip => {
                        ip = ip.trim();
                        if (ip) {
                            publicIp.value = ip;
                            ipStatus.innerHTML = '<i class="fa fa-check-circle text-success mr-2"></i><span>IP检测成功</span>';
                            ipStatus.className = 'flex items-center justify-center p-3 rounded-lg bg-success/10 text-success';
                        } else {
                            // 如果ifconfig.me失败，尝试ipinfo.io
                            fetch('https://ipinfo.io/ip')
                                .then(response => response.text())
                                .then(ip => {
                                    ip = ip.trim();
                                    if (ip) {
                                        publicIp.value = ip;
                                        ipStatus.innerHTML = '<i class="fa fa-check-circle text-success mr-2"></i><span>IP检测成功</span>';
                                        ipStatus.className = 'flex items-center justify-center p-3 rounded-lg bg-success/10 text-success';
                                    } else {
                                        publicIp.value = '检测失败';
                                        ipStatus.innerHTML = '<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>IP检测失败</span>';
                                        ipStatus.className = 'flex items-center justify-center p-3 rounded-lg bg-danger/10 text-danger';
                                    }
                                })
                                .catch(error => {
                                    publicIp.value = '检测失败';
                                    ipStatus.innerHTML = '<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>网络错误</span>';
                                    ipStatus.className = 'flex items-center justify-center p-3 rounded-lg bg-danger/10 text-danger';
                                });
                        }
                    })
                    .catch(error => {
                        // 如果ifconfig.me失败，尝试ipinfo.io
                        fetch('https://ipinfo.io/ip')
                            .then(response => response.text())
                            .then(ip => {
                                ip = ip.trim();
                                if (ip) {
                                    publicIp.value = ip;
                                    ipStatus.innerHTML = '<i class="fa fa-check-circle text-success mr-2"></i><span>IP检测成功</span>';
                                    ipStatus.className = 'flex items-center justify-center p-3 rounded-lg bg-success/10 text-success';
                                } else {
                                    publicIp.value = '检测失败';
                                    ipStatus.innerHTML = '<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>IP检测失败</span>';
                                    ipStatus.className = 'flex items-center justify-center p-3 rounded-lg bg-danger/10 text-danger';
                                }
                            })
                            .catch(error => {
                                publicIp.value = '检测失败';
                                ipStatus.innerHTML = '<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>网络错误</span>';
                                ipStatus.className = 'flex items-center justify-center p-3 rounded-lg bg-danger/10 text-danger';
                            });
                    });
            }
            
            // 加载配置
            function loadDdnsConfig() {
                const config = localStorage.getItem('aliyunDdnsConfig');
                if (config) {
                    try {
                        const parsedConfig = JSON.parse(config);
                        document.getElementById('ddns-access-key-id').value = parsedConfig.accessKeyId || '';
                        document.getElementById('ddns-access-key-secret').value = parsedConfig.accessKeySecret || '';
                        document.getElementById('ddns-domain-name').value = parsedConfig.domainName || '';
                        document.getElementById('ddns-rr').value = parsedConfig.rr || '@';
                        document.getElementById('ddns-record-type').value = parsedConfig.recordType || 'A';
                    } catch (error) {
                        console.error('加载配置失败:', error);
                    }
                }
            }
            
            // 测试配置
            function testDdnsConfig() {
                const btn = document.getElementById('ddns-test-config-btn');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fa fa-spinner fa-spin mr-1"></i> 测试中...';
                btn.disabled = true;
                
                // 简单验证配置
                const accessKeyId = document.getElementById('ddns-access-key-id').value;
                const accessKeySecret = document.getElementById('ddns-access-key-secret').value;
                const domainName = document.getElementById('ddns-domain-name').value;
                
                if (!accessKeyId || !accessKeySecret || !domainName) {
                    showAlert('请填写完整的配置信息', 'warning');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    return;
                }
                
                // 验证域名格式
                const domainRegex = /^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/;
                if (!domainRegex.test(domainName)) {
                    showAlert('请输入有效的域名格式，例如：example.com', 'warning');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    return;
                }
                
                // 发送测试请求
                fetch('aliyun_ddns.php?action=test_config', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        accessKeyId: accessKeyId,
                        accessKeySecret: accessKeySecret,
                        domainName: domainName
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        btn.innerHTML = '<i class="fa fa-check mr-1"></i> 测试成功';
                        btn.className = 'flex-1 bg-success/10 text-success px-6 py-2 rounded-lg hover:bg-success/20 transition-all duration-300 flex items-center justify-center';
                        showAlert(data.message, 'success');
                    } else {
                        btn.innerHTML = '<i class="fa fa-times mr-1"></i> 测试失败';
                        btn.className = 'flex-1 bg-danger/10 text-danger px-6 py-2 rounded-lg hover:bg-danger/20 transition-all duration-300 flex items-center justify-center';
                        showAlert(data.message, 'error');
                    }
                    
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.className = 'flex-1 bg-warning text-white px-6 py-2 rounded-lg hover:bg-warning/90 transition-all duration-300 flex items-center justify-center';
                        btn.disabled = false;
                    }, 2000);
                })
                .catch(error => {
                    btn.innerHTML = '<i class="fa fa-times mr-1"></i> 测试失败';
                    btn.className = 'flex-1 bg-danger/10 text-danger px-6 py-2 rounded-lg hover:bg-danger/20 transition-all duration-300 flex items-center justify-center';
                    showAlert('网络错误，请稍后重试', 'error');
                    
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.className = 'flex-1 bg-warning text-white px-6 py-2 rounded-lg hover:bg-warning/90 transition-all duration-300 flex items-center justify-center';
                        btn.disabled = false;
                    }, 2000);
                });
            }
            
            // 更新DDNS
            function updateDdns() {
                const btn = document.getElementById('ddns-update-btn');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i> 更新中...';
                btn.disabled = true;
                
                const accessKeyId = document.getElementById('ddns-access-key-id').value;
                const accessKeySecret = document.getElementById('ddns-access-key-secret').value;
                const domainName = document.getElementById('ddns-domain-name').value;
                const rr = document.getElementById('ddns-rr').value;
                const recordType = document.getElementById('ddns-record-type').value;
                const ip = document.getElementById('ddns-public-ip').value;
                
                // 验证域名格式
                const domainRegex = /^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/;
                if (!domainRegex.test(domainName)) {
                    showAlert('请输入有效的域名格式，例如：example.com', 'warning');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    return;
                }
                
                if (!accessKeyId || !accessKeySecret || !domainName || !rr || !recordType || !ip || ip === '检测失败') {
                    showAlert('请填写完整的配置信息并确保IP检测成功', 'warning');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    return;
                }
                
                // 显示正在执行的提示信息
                const updateStatus = document.getElementById('ddns-update-status');
                updateStatus.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i><span>正在执行...</span>';
                updateStatus.className = 'text-sm text-primary flex items-center';
                
                fetch('aliyun_ddns.php?action=update_ddns', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        accessKeyId: accessKeyId,
                        accessKeySecret: accessKeySecret,
                        domainName: domainName,
                        rr: rr,
                        recordType: recordType,
                        ip: ip
                    })
                })
                .then(response => response.json())
                .then(data => {
                    const updateStatus = document.getElementById('ddns-update-status');
                    
                    if (data.success) {
                        updateStatus.innerHTML = `<i class="fa fa-check-circle text-success mr-2"></i><span>${data.message}</span>`;
                        updateStatus.className = 'text-sm text-success flex items-center';
                        
                        // 更新上次更新时间和IP
                        const now = new Date().toLocaleString();
                        document.getElementById('ddns-last-update-time').textContent = now;
                        document.getElementById('ddns-last-update-ip').textContent = ip;
                        
                        // 保存到本地存储
                        localStorage.setItem('ddnsLastUpdateTime', now);
                        localStorage.setItem('ddnsLastUpdateIp', ip);
                        
                        // 保存配置
                        const config = {
                            accessKeyId: accessKeyId,
                            accessKeySecret: accessKeySecret,
                            domainName: domainName,
                            rr: rr,
                            recordType: recordType
                        };
                        localStorage.setItem('aliyunDdnsConfig', JSON.stringify(config));
                        
                        showAlert(data.message, 'success');
                    } else {
                        updateStatus.innerHTML = `<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>更新失败: ${data.message}</span>`;
                        updateStatus.className = 'text-sm text-danger flex items-center';
                        showAlert(data.message, 'error');
                    }
                    
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                })
                .catch(error => {
                    const updateStatus = document.getElementById('ddns-update-status');
                    updateStatus.innerHTML = '<div class="flex items-center"><i class="fa fa-exclamation-circle text-danger mr-2"></i><span>网络错误，请稍后重试</span></div>';
                    updateStatus.className = 'p-4 rounded-lg bg-danger/10 text-danger mb-6';
                    
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    showAlert('网络错误，请稍后重试', 'error');
                });
            }
            
            // 加载上次更新信息
            function loadDdnsLastUpdate() {
                const lastUpdateTime = localStorage.getItem('ddnsLastUpdateTime');
                const lastUpdateIp = localStorage.getItem('ddnsLastUpdateIp');
                
                if (lastUpdateTime) {
                    document.getElementById('ddns-last-update-time').textContent = lastUpdateTime;
                }
                if (lastUpdateIp) {
                    document.getElementById('ddns-last-update-ip').textContent = lastUpdateIp;
                }
            }
            
            // 自动更新相关变量
            let ddnsAutoUpdateInterval = null;
            let ddnsCountdownInterval = null;
            let ddnsLastDetectedIp = '';
            
            // 初始化自动更新设置
            function initDdnsAutoUpdate() {
                // 加载保存的自动更新设置
                const autoUpdateEnabled = localStorage.getItem('ddnsAutoUpdateEnabled') === 'true';
                const savedInterval = localStorage.getItem('ddnsUpdateInterval') || '60000';
                
                // 应用设置
                document.getElementById('ddns-auto-update-toggle').checked = autoUpdateEnabled;
                document.getElementById('ddns-update-interval').value = savedInterval;
                
                // 更新状态显示
                updateDdnsAutoUpdateStatus();
                
                // 如果启用了自动更新，开始检测
                if (autoUpdateEnabled) {
                    startDdnsAutoUpdate();
                }
            }
            
            // 更新自动更新状态显示
            function updateDdnsAutoUpdateStatus() {
                const autoUpdateEnabled = document.getElementById('ddns-auto-update-toggle').checked;
                const interval = document.getElementById('ddns-update-interval').value;
                const statusElement = document.getElementById('ddns-auto-update-status');
                const countdownElement = document.getElementById('ddns-auto-update-countdown');
                
                if (autoUpdateEnabled) {
                    const intervalMinutes = parseInt(interval) / 60000;
                    statusElement.textContent = `每 ${intervalMinutes} 分钟检测IP变化`;
                    statusElement.className = 'text-sm text-success';
                    countdownElement.classList.remove('hidden');
                } else {
                    statusElement.textContent = '自动更新已禁用';
                    statusElement.className = 'text-sm text-gray-600';
                    countdownElement.classList.add('hidden');
                }
            }
            
            // 开始自动更新
            function startDdnsAutoUpdate() {
                // 清除现有的定时器
                if (ddnsAutoUpdateInterval) {
                    clearInterval(ddnsAutoUpdateInterval);
                }
                if (ddnsCountdownInterval) {
                    clearInterval(ddnsCountdownInterval);
                }
                
                // 获取更新间隔
                const interval = parseInt(document.getElementById('ddns-update-interval').value);
                
                // 立即执行一次检测
                checkDdnsIpAndUpdate();
                
                // 设置定时器
                ddnsAutoUpdateInterval = setInterval(checkDdnsIpAndUpdate, interval);
                
                // 启动倒计时
                startDdnsCountdown(interval);
                
                // 保存设置
                localStorage.setItem('ddnsAutoUpdateEnabled', 'true');
                localStorage.setItem('ddnsUpdateInterval', interval.toString());
            }
            
            // 停止自动更新
            function stopDdnsAutoUpdate() {
                if (ddnsAutoUpdateInterval) {
                    clearInterval(ddnsAutoUpdateInterval);
                    ddnsAutoUpdateInterval = null;
                }
                if (ddnsCountdownInterval) {
                    clearInterval(ddnsCountdownInterval);
                    ddnsCountdownInterval = null;
                }
                
                // 保存设置
                localStorage.setItem('ddnsAutoUpdateEnabled', 'false');
            }
            
            // 启动倒计时
            function startDdnsCountdown(interval) {
                let countdown = interval / 1000;
                const timerElement = document.getElementById('ddns-countdown-timer');
                
                function updateCountdown() {
                    const minutes = Math.floor(countdown / 60);
                    const seconds = Math.floor(countdown % 60);
                    timerElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                    
                    if (countdown <= 0) {
                        countdown = interval / 1000;
                    } else {
                        countdown--;
                    }
                }
                
                // 立即更新一次
                updateCountdown();
                
                // 设置定时器
                ddnsCountdownInterval = setInterval(updateCountdown, 1000);
            }
            
            // 检查IP并更新
            function checkDdnsIpAndUpdate() {
                // 直接从客户端获取外网IP，按响应速度排序
                fetch('https://ifconfig.me/ip')
                    .then(response => response.text())
                    .then(ip => {
                        ip = ip.trim();
                        if (ip) {
                            // 检查IP是否有变化
                            if (ip !== ddnsLastDetectedIp) {
                                // IP有变化，执行更新
                                const accessKeyId = document.getElementById('ddns-access-key-id').value;
                                const accessKeySecret = document.getElementById('ddns-access-key-secret').value;
                                const domainName = document.getElementById('ddns-domain-name').value;
                                const rr = document.getElementById('ddns-rr').value;
                                const recordType = document.getElementById('ddns-record-type').value;
                                
                                // 验证配置是否完整
                                if (accessKeyId && accessKeySecret && domainName && rr && recordType) {
                                    // 更新状态显示（如果存在）
                                    const updateStatusElement = document.getElementById('ddns-update-status');
                                    if (updateStatusElement) {
                                        updateStatusElement.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i><span>正在执行自动更新...</span>';
                                        updateStatusElement.className = 'text-sm text-primary flex items-center';
                                    }
                                    
                                    // 执行更新
                                    fetch('aliyun_ddns.php?action=update_ddns', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/x-www-form-urlencoded'
                                        },
                                        body: new URLSearchParams({
                                            accessKeyId: accessKeyId,
                                            accessKeySecret: accessKeySecret,
                                            domainName: domainName,
                                            rr: rr,
                                            recordType: recordType,
                                            ip: ip
                                        })
                                    })
                                    .then(response => response.json())
                                    .then(updateData => {
                                        // 更新成功
                                        const now = new Date().toLocaleString();
                                        
                                        // 保存到本地存储
                                        localStorage.setItem('ddnsLastUpdateTime', now);
                                        localStorage.setItem('ddnsLastUpdateIp', ip);
                                        
                                        // 更新UI元素（如果存在）
                                        const lastUpdateTimeElement = document.getElementById('ddns-last-update-time');
                                        const lastUpdateIpElement = document.getElementById('ddns-last-update-ip');
                                        const updateStatusElement = document.getElementById('ddns-update-status');
                                        
                                        if (lastUpdateTimeElement) {
                                            lastUpdateTimeElement.textContent = now;
                                        }
                                        if (lastUpdateIpElement) {
                                            lastUpdateIpElement.textContent = ip;
                                        }
                                        if (updateStatusElement) {
                                            if (updateData.success) {
                                                updateStatusElement.innerHTML = `<i class="fa fa-check-circle text-success mr-2"></i><span>${updateData.message}</span>`;
                                                updateStatusElement.className = 'text-sm text-success flex items-center';
                                            } else {
                                                updateStatusElement.innerHTML = `<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>更新失败: ${updateData.message}</span>`;
                                                updateStatusElement.className = 'text-sm text-danger flex items-center';
                                            }
                                        }
                                        
                                        // 显示提示信息
                                        if (updateData.success) {
                                            showAlert(updateData.message, 'success');
                                        } else {
                                            showAlert(updateData.message, 'error');
                                        }
                                    })
                                    .catch(error => {
                                        console.error('自动更新失败:', error);
                                        // 更新状态显示（如果存在）
                                        const updateStatusElement = document.getElementById('ddns-update-status');
                                        if (updateStatusElement) {
                                            updateStatusElement.innerHTML = `<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>更新失败: 网络错误</span>`;
                                            updateStatusElement.className = 'text-sm text-danger flex items-center';
                                        }
                                        // 显示提示信息
                                        showAlert('网络错误，请稍后重试', 'error');
                                    });
                                }
                                
                                // 更新上次检测的IP
                                ddnsLastDetectedIp = ip;
                            }
                        } else {
                            // 如果ifconfig.me失败，尝试ipinfo.io
                            fetch('https://ipinfo.io/ip')
                                .then(response => response.text())
                                .then(ip => {
                                    ip = ip.trim();
                                    if (ip) {
                                        // 检查IP是否有变化
                                        if (ip !== ddnsLastDetectedIp) {
                                            // IP有变化，执行更新
                                            const accessKeyId = document.getElementById('ddns-access-key-id').value;
                                            const accessKeySecret = document.getElementById('ddns-access-key-secret').value;
                                            const domainName = document.getElementById('ddns-domain-name').value;
                                            const rr = document.getElementById('ddns-rr').value;
                                            const recordType = document.getElementById('ddns-record-type').value;
                                            
                                            // 验证配置是否完整
                                            if (accessKeyId && accessKeySecret && domainName && rr && recordType) {
                                                // 更新状态显示（如果存在）
                                                const updateStatusElement = document.getElementById('ddns-update-status');
                                                if (updateStatusElement) {
                                                    updateStatusElement.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i><span>正在执行自动更新...</span>';
                                                    updateStatusElement.className = 'text-sm text-primary flex items-center';
                                                }
                                                
                                                // 执行更新
                                                fetch('aliyun_ddns.php?action=update_ddns', {
                                                    method: 'POST',
                                                    headers: {
                                                        'Content-Type': 'application/x-www-form-urlencoded'
                                                    },
                                                    body: new URLSearchParams({
                                                        accessKeyId: accessKeyId,
                                                        accessKeySecret: accessKeySecret,
                                                        domainName: domainName,
                                                        rr: rr,
                                                        recordType: recordType,
                                                        ip: ip
                                                    })
                                                })
                                                .then(response => response.json())
                                                .then(updateData => {
                                                    // 更新成功
                                                    const now = new Date().toLocaleString();
                                                    
                                                    // 保存到本地存储
                                                    localStorage.setItem('ddnsLastUpdateTime', now);
                                                    localStorage.setItem('ddnsLastUpdateIp', ip);
                                                    
                                                    // 更新UI元素（如果存在）
                                                    const lastUpdateTimeElement = document.getElementById('ddns-last-update-time');
                                                    const lastUpdateIpElement = document.getElementById('ddns-last-update-ip');
                                                    const updateStatusElement = document.getElementById('ddns-update-status');
                                                    
                                                    if (lastUpdateTimeElement) {
                                                        lastUpdateTimeElement.textContent = now;
                                                    }
                                                    if (lastUpdateIpElement) {
                                                        lastUpdateIpElement.textContent = ip;
                                                    }
                                                    if (updateStatusElement) {
                                                        if (updateData.success) {
                                                            updateStatusElement.innerHTML = `<i class="fa fa-check-circle text-success mr-2"></i><span>${updateData.message}</span>`;
                                                            updateStatusElement.className = 'text-sm text-success flex items-center';
                                                        } else {
                                                            updateStatusElement.innerHTML = `<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>更新失败: ${updateData.message}</span>`;
                                                            updateStatusElement.className = 'text-sm text-danger flex items-center';
                                                        }
                                                    }
                                                    
                                                    // 显示提示信息
                                                    if (updateData.success) {
                                                        showAlert(updateData.message, 'success');
                                                    } else {
                                                        showAlert(updateData.message, 'error');
                                                    }
                                                })
                                                .catch(error => {
                                                    console.error('自动更新失败:', error);
                                                    // 更新状态显示（如果存在）
                                                    const updateStatusElement = document.getElementById('ddns-update-status');
                                                    if (updateStatusElement) {
                                                        updateStatusElement.innerHTML = `<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>更新失败: 网络错误</span>`;
                                                        updateStatusElement.className = 'text-sm text-danger flex items-center';
                                                    }
                                                    // 显示提示信息
                                                    showAlert('网络错误，请稍后重试', 'error');
                                                });
                                            }
                                            
                                            // 更新上次检测的IP
                                            ddnsLastDetectedIp = ip;
                                        }
                                    }
                                })
                                .catch(error => {
                                    console.error('检测IP失败:', error);
                                });
                        }
                    })
                    .catch(error => {
                        // 如果ifconfig.me失败，尝试ipinfo.io
                        fetch('https://ipinfo.io/ip')
                            .then(response => response.text())
                            .then(ip => {
                                ip = ip.trim();
                                if (ip) {
                                    // 检查IP是否有变化
                                    if (ip !== ddnsLastDetectedIp) {
                                        // IP有变化，执行更新
                                        const accessKeyId = document.getElementById('ddns-access-key-id').value;
                                        const accessKeySecret = document.getElementById('ddns-access-key-secret').value;
                                        const domainName = document.getElementById('ddns-domain-name').value;
                                        const rr = document.getElementById('ddns-rr').value;
                                        const recordType = document.getElementById('ddns-record-type').value;
                                        
                                        // 验证配置是否完整
                                        if (accessKeyId && accessKeySecret && domainName && rr && recordType) {
                                            // 更新状态显示（如果存在）
                                            const updateStatusElement = document.getElementById('ddns-update-status');
                                            if (updateStatusElement) {
                                                updateStatusElement.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i><span>正在执行自动更新...</span>';
                                                updateStatusElement.className = 'text-sm text-primary flex items-center';
                                            }
                                            
                                            // 执行更新
                                            fetch('aliyun_ddns.php?action=update_ddns', {
                                                method: 'POST',
                                                headers: {
                                                    'Content-Type': 'application/x-www-form-urlencoded'
                                                },
                                                body: new URLSearchParams({
                                                    accessKeyId: accessKeyId,
                                                    accessKeySecret: accessKeySecret,
                                                    domainName: domainName,
                                                    rr: rr,
                                                    recordType: recordType,
                                                    ip: ip
                                                })
                                            })
                                            .then(response => response.json())
                                            .then(updateData => {
                                                // 更新成功
                                                const now = new Date().toLocaleString();
                                                
                                                // 保存到本地存储
                                                localStorage.setItem('ddnsLastUpdateTime', now);
                                                localStorage.setItem('ddnsLastUpdateIp', ip);
                                                
                                                // 更新UI元素（如果存在）
                                                const lastUpdateTimeElement = document.getElementById('ddns-last-update-time');
                                                const lastUpdateIpElement = document.getElementById('ddns-last-update-ip');
                                                const updateStatusElement = document.getElementById('ddns-update-status');
                                                
                                                if (lastUpdateTimeElement) {
                                                    lastUpdateTimeElement.textContent = now;
                                                }
                                                if (lastUpdateIpElement) {
                                                    lastUpdateIpElement.textContent = ip;
                                                }
                                                if (updateStatusElement) {
                                                    if (updateData.success) {
                                                        updateStatusElement.innerHTML = `<i class="fa fa-check-circle text-success mr-2"></i><span>${updateData.message}</span>`;
                                                        updateStatusElement.className = 'text-sm text-success flex items-center';
                                                    } else {
                                                        updateStatusElement.innerHTML = `<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>更新失败: ${updateData.message}</span>`;
                                                        updateStatusElement.className = 'text-sm text-danger flex items-center';
                                                    }
                                                }
                                                
                                                // 显示提示信息
                                                if (updateData.success) {
                                                    showAlert(updateData.message, 'success');
                                                } else {
                                                    showAlert(updateData.message, 'error');
                                                }
                                            })
                                            .catch(error => {
                                                console.error('自动更新失败:', error);
                                                // 更新状态显示（如果存在）
                                                const updateStatusElement = document.getElementById('ddns-update-status');
                                                if (updateStatusElement) {
                                                    updateStatusElement.innerHTML = `<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>更新失败: 网络错误</span>`;
                                                    updateStatusElement.className = 'text-sm text-danger flex items-center';
                                                }
                                                // 显示提示信息
                                                showAlert('网络错误，请稍后重试', 'error');
                                            });
                                        }
                                        
                                        // 更新上次检测的IP
                                        ddnsLastDetectedIp = ip;
                                    }
                                }
                            })
                            .catch(error => {
                                console.error('检测IP失败:', error);
                            });
                    });
            }
            
            // 自动更新切换事件
            const ddnsAutoUpdateToggle = document.getElementById('ddns-auto-update-toggle');
            if (ddnsAutoUpdateToggle) {
                ddnsAutoUpdateToggle.addEventListener('change', function() {
                    if (this.checked) {
                        startDdnsAutoUpdate();
                    } else {
                        stopDdnsAutoUpdate();
                    }
                    updateDdnsAutoUpdateStatus();
                });
            }
            
            // 更新间隔变化事件
            const ddnsUpdateInterval = document.getElementById('ddns-update-interval');
            if (ddnsUpdateInterval) {
                ddnsUpdateInterval.addEventListener('change', function() {
                    if (document.getElementById('ddns-auto-update-toggle').checked) {
                        startDdnsAutoUpdate();
                    }
                });
            }
            
            // 初始化自动更新设置
            initDdnsAutoUpdate();
            
            // 阿里云配置弹出层事件监听器
            const ddnsConfigBtn = document.getElementById('ddns-config-btn');
            const ddnsConfigModal = document.getElementById('ddns-config-modal');
            const closeDdnsConfigModalBtn = document.getElementById('close-ddns-config-modal-btn');
            const saveDdnsConfigBtn = document.getElementById('save-ddns-config-btn');
            const testDdnsConfigBtn = document.getElementById('test-ddns-config-btn');
            
            // 打开配置弹出层
            if (ddnsConfigBtn && ddnsConfigModal) {
                ddnsConfigBtn.addEventListener('click', function() {
                    // 加载配置
                    loadDdnsConfig();
                    // 打开配置弹出层
                    ddnsConfigModal.style.display = 'flex';
                });
            }
            
            // 关闭配置弹出层
            if (closeDdnsConfigModalBtn && ddnsConfigModal) {
                closeDdnsConfigModalBtn.addEventListener('click', function() {
                    ddnsConfigModal.style.display = 'none';
                });
            }
            
            // 保存配置
            if (saveDdnsConfigBtn) {
                saveDdnsConfigBtn.addEventListener('click', function() {
                    const config = {
                        accessKeyId: document.getElementById('ddns-access-key-id').value,
                        accessKeySecret: document.getElementById('ddns-access-key-secret').value,
                        domainName: document.getElementById('ddns-domain-name').value,
                        rr: document.getElementById('ddns-rr').value,
                        recordType: document.getElementById('ddns-record-type').value
                    };
                    
                    localStorage.setItem('aliyunDdnsConfig', JSON.stringify(config));
                    
                    // 显示保存成功提示
                    const btn = this;
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<i class="fa fa-check mr-1"></i> 保存成功';
                    btn.className = 'bg-success/10 text-success px-5 py-2.5 rounded-lg transition-all duration-300';
                    
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.className = 'bg-primary text-white px-5 py-2.5 rounded-lg hover:bg-primary/90 transition-all duration-300';
                        // 关闭配置弹出层
                        if (ddnsConfigModal) {
                            ddnsConfigModal.style.display = 'none';
                        }
                    }, 1500);
                });
            }
            
            // 测试配置
            if (testDdnsConfigBtn) {
                testDdnsConfigBtn.addEventListener('click', function() {
                    const btn = this;
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<i class="fa fa-spinner fa-spin mr-1"></i> 测试中...';
                    btn.disabled = true;
                    
                    // 简单验证配置
                    const accessKeyId = document.getElementById('ddns-access-key-id').value;
                    const accessKeySecret = document.getElementById('ddns-access-key-secret').value;
                    const domainName = document.getElementById('ddns-domain-name').value;
                    
                    if (!accessKeyId || !accessKeySecret || !domainName) {
                        showAlert('请填写完整的配置信息', 'warning');
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        return;
                    }
                    
                    


                    // 验证域名格式
            const domainRegex = /^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/;
            if (!domainRegex.test(domainName)) {
                showAlert('请输入有效的域名格式，例如：example.com', 'warning');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }


                    
                    
                    // 发送测试请求
                    fetch('aliyun_ddns.php?action=test_config', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({
                            accessKeyId: accessKeyId,
                            accessKeySecret: accessKeySecret,
                            domainName: domainName
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            btn.innerHTML = '<i class="fa fa-check mr-1"></i> 测试成功';
                            btn.className = 'bg-success/10 text-success px-5 py-2.5 rounded-lg transition-all duration-300';
                            showAlert(data.message, 'success');
                        } else {
                            btn.innerHTML = '<i class="fa fa-times mr-1"></i> 测试失败';
                            btn.className = 'bg-danger/10 text-danger px-5 py-2.5 rounded-lg transition-all duration-300';
                            showAlert(data.message, 'error');
                        }
                        
                        setTimeout(() => {
                            btn.innerHTML = originalText;
                            btn.className = 'bg-warning text-white px-5 py-2.5 rounded-lg hover:bg-warning/90 transition-all duration-300';
                            btn.disabled = false;
                        }, 2000);
                    })
                    .catch(error => {
                        btn.innerHTML = '<i class="fa fa-times mr-1"></i> 测试失败';
                        btn.className = 'bg-danger/10 text-danger px-5 py-2.5 rounded-lg transition-all duration-300';
                        showAlert('网络错误，请稍后重试', 'error');
                        
                        setTimeout(() => {
                            btn.innerHTML = originalText;
                            btn.className = 'bg-warning text-white px-5 py-2.5 rounded-lg hover:bg-warning/90 transition-all duration-300';
                            btn.disabled = false;
                        }, 2000);
                    });
                });
            }

            // 检查数据库配置状态
            function checkDatabaseConfig() {
                fetch('?action=check_db_config')
                    .then(response => response.json())
                    .then(data => {
                        if (!data.configured) {
                            // 数据库未配置，显示配置模态框
                            const dbConfigModal = document.getElementById('database-config-modal');
                            if (dbConfigModal) {
                                dbConfigModal.style.display = 'flex';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('检查数据库配置失败:', error);
                    });
            }

            // 执行初始化
            function init() {
                // 检查数据库配置状态
                checkDatabaseConfig();
                // 初始化登录功能
                initLoginFunctionality();
                // 加载分类目录
                loadCategories();
                // 加载上传记录
                loadUploadHistory();
                // 初始化图片查看功能
                initImageView();
                // 初始化分类标签选择
                initCategorySelection();
                // 初始化删除文件功能
                initDeleteFile();
                // 初始化密码模态框
                initPasswordModal();
                // 初始化拼图功能
                initPuzzleFunctionality();
                // 初始化全部清空按钮
                initClearAllButton();
                // 初始化搜索功能
                initSearch();
                // 加载并显示过期时间设置
                loadExpirySetting();
                
                // 不需要检查管理员状态，因为DOMContentLoaded事件中已经检查过了
                // 不需要单独调用updateFooterApiKeyName()，因为checkAdminStatus()会同时更新
            }

            // 更新页脚 API Key 名称
            function updateFooterApiKeyName() {
                const footerApiKeyNameElement = document.getElementById('footer-api-key-name');
                if (footerApiKeyNameElement) {
                    const apiKey = sessionStorage.getItem('apiKey');
                    const apiKeyHash = localStorage.getItem('apiKeyHash');
                    if (apiKey && apiKeyHash) {
                        // 发送请求获取 API Key 名称
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', window.location.href, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        
                        xhr.onload = function() {
                            if (xhr.status === 200) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    if (response.success && response.name) {
                                        footerApiKeyNameElement.textContent = `当前用户：${response.name}`;
                                    } else {
                                        footerApiKeyNameElement.textContent = '';
                                    }
                                } catch (error) {
                                    footerApiKeyNameElement.textContent = '';
                                }
                            } else {
                                footerApiKeyNameElement.textContent = '';
                            }
                        };
                        
                        xhr.onerror = function() {
                            footerApiKeyNameElement.textContent = '';
                        };
                        
                        xhr.send(`action=get_api_key_name&api_key_hash=${encodeURIComponent(apiKeyHash)}`);
                        // xhr.send(`action=get_api_key_name&api_key=${encodeURIComponent(apiKey)}&api_key_hash=${encodeURIComponent(apiKeyHash)}`);
                    } else {
                        footerApiKeyNameElement.textContent = '';
                    }
                }
            }
            
            // 加载并显示过期时间设置
            function loadExpirySetting() {
                // 发送请求获取配置信息
                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success && response.expiry_days) {
                                const expiryDaysElement = document.getElementById('expiry-days');
                                if (expiryDaysElement) {
                                    expiryDaysElement.textContent = response.expiry_days;
                                }
                                // 保存到本地存储
                                localStorage.setItem('expiryDays', response.expiry_days);
                            }
                        } catch (error) {
                            console.error('解析配置失败:', error);
                        }
                    }
                };
                
                xhr.onerror = function() {
                    console.error('获取配置失败');
                };
                
                xhr.send('action=get_config');
            }

            // 执行初始化
            init();
        });
    </script>
</body>
</html>