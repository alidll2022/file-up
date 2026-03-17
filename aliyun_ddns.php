<?php
// 阿里云DDNS解析更新工具

// 阿里云API配置

// 创建解析记录
function addDomainRecord($accessKeyId, $accessKeySecret, $domainName, $rr, $recordType, $ip) {
    $url = 'https://alidns.aliyuncs.com/';
    
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    
    $params = array(
        'Action' => 'AddDomainRecord',
        'Format' => 'JSON',
        'Version' => '2015-01-09',
        'AccessKeyId' => $accessKeyId,
        'SignatureMethod' => 'HMAC-SHA1',
        'SignatureVersion' => '1.0',
        'SignatureNonce' => uniqid(),
        'Timestamp' => $timestamp,
        'DomainName' => $domainName,
        'RR' => $rr,
        'Type' => $recordType,
        'Value' => $ip
    );
    
    $params['Signature'] = generateSignature($params, $accessKeySecret);
    
    $response = sendRequest($url, $params);
    
    // 处理记录已存在的情况
    if (!$response['success'] && (strpos($response['message'], 'The DNS record already exists') !== false || strpos($response['message'], 'DNS record already exists') !== false)) {
        // 记录已存在，直接返回成功消息
        return array('success' => true, 'message' => '解析记录已存在，无需创建');
    }
    
    // 如果创建成功，添加消息
    if ($response['success']) {
        $response['message'] = 'DNS解析记录创建成功';
    }
    
    error_log('AddDomainRecord response: ' . print_r($response, true));
    
    return $response;
}

function updateAliyunDNS($accessKeyId, $accessKeySecret, $domainName, $rr, $recordType, $ip) {
    // 阿里云API地址
    $url = 'https://alidns.aliyuncs.com/';
    
    // 时间戳
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    
    // 首先获取记录信息
    $recordInfo = getRecordInfo($accessKeyId, $accessKeySecret, $domainName, $rr, $recordType);
    
    if ($recordInfo) {
        // 记录存在，检查IP是否有变化
        if ($recordInfo['Value'] == $ip) {
            // IP一致，无需更新
            return array('success' => true, 'message' => 'DNS解析IP一致，更新成功', 'old_ip' => $recordInfo['Value'], 'new_ip' => $ip);
        }
        
        // IP有变化，更新记录
        $params = array(
            'Action' => 'UpdateDomainRecord',
            'Format' => 'JSON',
            'Version' => '2015-01-09',
            'AccessKeyId' => $accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce' => uniqid(),
            'Timestamp' => $timestamp,
            'DomainName' => $domainName,
            'RR' => $rr,
            'Type' => $recordType,
            'Value' => $ip,
            'RecordId' => $recordInfo['RecordId']
        );
        
        // 生成签名
        $params['Signature'] = generateSignature($params, $accessKeySecret);
        
        // 发送请求
        $response = sendRequest($url, $params);
        
        // 如果更新成功，添加IP信息和消息
        if ($response['success']) {
            $response['old_ip'] = $recordInfo['Value'];
            $response['new_ip'] = $ip;
            $response['message'] = 'DNS解析【'.$ip.'】更新成功';
        }
        
        return $response;
    } else {
        // 记录不存在，创建记录
        $response = addDomainRecord($accessKeyId, $accessKeySecret, $domainName, $rr, $recordType, $ip);
        
        // 如果创建成功，添加IP信息
        if ($response['success']) {
            $response['old_ip'] = null;
            $response['new_ip'] = $ip;
        }
        
        return $response;
    }
}

// 获取记录ID和当前IP
function getRecordInfo($accessKeyId, $accessKeySecret, $domainName, $rr, $recordType) {
    $url = 'https://alidns.aliyuncs.com/';
    
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    
    $params = array(
        'Action' => 'DescribeDomainRecords',
        'Format' => 'JSON',
        'Version' => '2015-01-09',
        'AccessKeyId' => $accessKeyId,
        'SignatureMethod' => 'HMAC-SHA1',
        'SignatureVersion' => '1.0',
        'SignatureNonce' => uniqid(),
        'Timestamp' => $timestamp,
        'DomainName' => $domainName
    );
    
    // 只在有值时添加，避免空值导致API调用失败
    if (!empty($rr)) {
        $params['RRKeyWord'] = $rr;
    }
    if (!empty($recordType)) {
        $params['TypeKeyWord'] = $recordType;
    }
    
    $params['Signature'] = generateSignature($params, $accessKeySecret);
    
    $response = sendRequest($url, $params);
    
    // 调试信息
    error_log('DescribeDomainRecords response: ' . print_r($response, true));
    
    if ($response['success']) {
        if (isset($response['data']['DomainRecords']['Record'])) {
            foreach ($response['data']['DomainRecords']['Record'] as $record) {
                if ($record['RR'] == $rr && $record['Type'] == $recordType) {
                    return array(
                        'RecordId' => $record['RecordId'],
                        'Value' => $record['Value']
                    );
                }
            }
            // 没有找到匹配的记录
            error_log('No matching record found for RR: ' . $rr . ', Type: ' . $recordType);
        } else {
            error_log('No records found in response');
        }
    } else {
        error_log('API request failed: ' . $response['message']);
    }
    
    return false;
}

// 兼容旧函数
function getRecordId($accessKeyId, $accessKeySecret, $domainName, $rr, $recordType) {
    $info = getRecordInfo($accessKeyId, $accessKeySecret, $domainName, $rr, $recordType);
    return $info ? $info['RecordId'] : false;
}

// 生成签名
function generateSignature($params, $accessKeySecret) {
    ksort($params);
    $canonicalizedQueryString = '';
    foreach ($params as $key => $value) {
        $canonicalizedQueryString .= '&' . percentEncode($key) . '=' . percentEncode($value);
    }
    $canonicalizedQueryString = substr($canonicalizedQueryString, 1);
    
    $stringToSign = 'GET&%2F&' . percentEncode($canonicalizedQueryString);
    $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
    
    return $signature;
}

// URL编码
function percentEncode($string) {
    $string = urlencode($string);
    $string = preg_replace('/\+/', '%20', $string);
    $string = preg_replace('/\*/', '%2A', $string);
    $string = preg_replace('/%7E/', '~', $string);
    return $string;
}

// 发送HTTP请求
function sendRequest($url, $params) {
    $queryString = http_build_query($params);
    $fullUrl = $url . '?' . $queryString;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return array('success' => false, 'message' => '网络请求失败: ' . $error);
    }
    
    $data = json_decode($response, true);
    if (isset($data['Code'])) {
        return array('success' => false, 'message' => $data['Message'], 'code' => $data['Code']);
    }
    
    return array('success' => true, 'data' => $data);
}

// 获取客户端公网IP
function getPublicIP() {
    // 优先通过外部服务获取客户端的外网IP
    // 按响应速度排序，最快的服务放在前面
    $ipServices = array(
        'https://ifconfig.me/ip',     // 最快，平均响应时间: 504.42ms
        'https://ipinfo.io/ip'        // 其次，平均响应时间: 516.26ms
    );
    
    foreach ($ipServices as $service) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $service);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);       // 减少超时时间
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1); // 减少连接超时时间
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        
        $ip = curl_exec($ch);
        curl_close($ch);
        
        if ($ip) {
            $ip = trim($ip);
            // 处理JSON格式的响应
            if (strpos($ip, '{') === 0) {
                $json = json_decode($ip, true);
                if (isset($json['ip'])) {
                    $ip = $json['ip'];
                }
            }
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP) && !isPrivateIP($ip)) {
                return $ip; // 一旦获取到有效IP，立即返回
            }
        }
    }
    
    // 如果外部服务获取失败，尝试从请求头获取（作为备用方案）
    // 1. 从HTTP_X_FORWARDED_FOR获取（代理服务器）
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP) && !isPrivateIP($ip)) {
                return $ip;
            }
        }
    }
    
    // 2. 从HTTP_CLIENT_IP获取
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = trim($_SERVER['HTTP_CLIENT_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP) && !isPrivateIP($ip)) {
            return $ip;
        }
    }
    
    // 3. 从REMOTE_ADDR获取
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = trim($_SERVER['REMOTE_ADDR']);
        if (filter_var($ip, FILTER_VALIDATE_IP) && !isPrivateIP($ip)) {
            return $ip;
        }
    }
    
    return false;
}

// 检查是否为局域网IP
function isPrivateIP($ip) {
    $privateRanges = array(
        '/^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/',
        '/^172\.(1[6-9]|2[0-9]|3[0-1])\.\d{1,3}\.\d{1,3}$/',
        '/^192\.168\.\d{1,3}\.\d{1,3}$/',
        '/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/',
        '/^169\.254\.\d{1,3}\.\d{1,3}$/'
    );
    
    foreach ($privateRanges as $range) {
        if (preg_match($range, $ip)) {
            return true;
        }
    }
    
    return false;
}

// 测试域名是否存在
function testDomainExists($accessKeyId, $accessKeySecret, $domainName) {
    $url = 'https://alidns.aliyuncs.com/';
    
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    
    $params = array(
        'Action' => 'DescribeDomainRecords',
        'Format' => 'JSON',
        'Version' => '2015-01-09',
        'AccessKeyId' => $accessKeyId,
        'SignatureMethod' => 'HMAC-SHA1',
        'SignatureVersion' => '1.0',
        'SignatureNonce' => uniqid(),
        'Timestamp' => $timestamp,
        'DomainName' => $domainName,
        'PageSize' => 1
    );
    
    $params['Signature'] = generateSignature($params, $accessKeySecret);
    
    $response = sendRequest($url, $params);
    
    if ($response['success']) {
        return array('success' => true, 'message' => '配置测试成功，域名信息正常');
    } else {
        // 处理特定错误信息
        if (strpos($response['message'], 'The specified domain name does not exist') !== false) {
            return array('success' => false, 'message' => '域名信息不存在，请添加到云解析DNS服务中');
        } elseif (strpos($response['message'], 'The domain name belongs to other users') !== false) {
            return array('success' => false, 'message' => '域名不属于当前用户，请转移后再尝试操作');
        } elseif (strpos($response['message'], 'SignatureDoesNotMatch') !== false || strpos($response['message'], 'signature is not matched') !== false) {
            return array('success' => false, 'message' => 'AccessKey Secret 无效或签名错误');
        } elseif (isset($response['code']) && $response['code'] == 'InvalidAccessKeyId' || strpos($response['message'], 'InvalidAccessKeyId') !== false || strpos($response['message'], 'access key is not found') !== false) {
            return array('success' => false, 'message' => 'AccessKey ID 无效');
        } elseif (strpos($response['message'], 'AccessKeyDisabled') !== false) {
            return array('success' => false, 'message' => 'AccessKey 已被禁用');
        } elseif (strpos($response['message'], 'InvalidAccessKeyType') !== false) {
            return array('success' => false, 'message' => 'AccessKey 类型错误');
        } elseif (strpos($response['message'], 'AccessKey') !== false || strpos($response['message'], 'access key') !== false) {
            return array('success' => false, 'message' => 'AccessKey 错误，请检查您的AccessKey配置');
        }
        return array('success' => false, 'message' => '配置测试失败，请检查您的阿里云配置信息');
    }
}

// 处理AJAX请求
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'get_ip':
            $ip = getPublicIP();
            if ($ip) {
                echo json_encode(array('success' => true, 'ip' => $ip));
            } else {
                echo json_encode(array('success' => false, 'message' => '获取IP失败'));
            }
            exit;
            
        case 'test_config':
            $accessKeyId = $_POST['accessKeyId'] ?? '';
            $accessKeySecret = $_POST['accessKeySecret'] ?? '';
            $domainName = $_POST['domainName'] ?? '';
            
            if (empty($accessKeyId) || empty($accessKeySecret) || empty($domainName)) {
                echo json_encode(array('success' => false, 'message' => '请填写完整的配置信息'));
                exit;
            }
            
            // 验证域名格式
            if (!preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domainName)) {
                echo json_encode(array('success' => false, 'message' => '域名格式无效，请输入正确的域名格式，例如：example.com'));
                exit;
            }
            
            // 测试域名是否存在
            $result = testDomainExists($accessKeyId, $accessKeySecret, $domainName);
            
            echo json_encode($result);
            exit;
            
        case 'test_accesskey_error':
            // 测试AccessKey错误
            $url = 'https://alidns.aliyuncs.com/';
            
            $timestamp = gmdate('Y-m-d\TH:i:s\Z');
            
            // 使用错误的AccessKey
            $accessKeyId = '错误的AccessKeyId';
            $accessKeySecret = '错误的AccessKeySecret';
            $domainName = 'example.com';
            
            $params = array(
                'Action' => 'DescribeDomainRecords',
                'Format' => 'JSON',
                'Version' => '2015-01-09',
                'AccessKeyId' => $accessKeyId,
                'SignatureMethod' => 'HMAC-SHA1',
                'SignatureVersion' => '1.0',
                'SignatureNonce' => uniqid(),
                'Timestamp' => $timestamp,
                'DomainName' => $domainName,
                'PageSize' => 1
            );
            
            $params['Signature'] = generateSignature($params, $accessKeySecret);
            
            $response = sendRequest($url, $params);
            
            echo "<h1>测试AccessKey错误结果</h1>";
            echo "<p>成功: " . ($response['success'] ? '是' : '否') . "</p>";
            echo "<p>错误信息: " . $response['message'] . "</p>";
            if (isset($response['code'])) {
                echo "<p>错误代码: " . $response['code'] . "</p>";
            }
            echo "<p><a href='aliyun_ddns.php'>返回首页</a></p>";
            exit;
            
        case 'test_accesskey_secret_error':
            // 测试AccessKey Secret错误
            $url = 'https://alidns.aliyuncs.com/';
            
            $timestamp = gmdate('Y-m-d\TH:i:s\Z');
            
            // 使用正确的AccessKey ID和错误的AccessKey Secret
            $accessKeyId = 'LTAI5tD7RAZwc1roeDWLvvTj'; // 这是一个示例AccessKey ID
            $accessKeySecret = '错误的AccessKeySecret';
            $domainName = 'example.com';
            
            $params = array(
                'Action' => 'DescribeDomainRecords',
                'Format' => 'JSON',
                'Version' => '2015-01-09',
                'AccessKeyId' => $accessKeyId,
                'SignatureMethod' => 'HMAC-SHA1',
                'SignatureVersion' => '1.0',
                'SignatureNonce' => uniqid(),
                'Timestamp' => $timestamp,
                'DomainName' => $domainName,
                'PageSize' => 1
            );
            
            $params['Signature'] = generateSignature($params, $accessKeySecret);
            
            $response = sendRequest($url, $params);
            
            echo "<h1>测试AccessKey Secret错误结果</h1>";
            echo "<p>成功: " . ($response['success'] ? '是' : '否') . "</p>";
            echo "<p>错误信息: " . $response['message'] . "</p>";
            if (isset($response['code'])) {
                echo "<p>错误代码: " . $response['code'] . "</p>";
            }
            echo "<p><a href='aliyun_ddns.php'>返回首页</a></p>";
            exit;
            
        case 'update_ddns':
            $accessKeyId = $_POST['accessKeyId'] ?? '';
            $accessKeySecret = $_POST['accessKeySecret'] ?? '';
            $domainName = $_POST['domainName'] ?? '';
            $rr = $_POST['rr'] ?? '';
            $recordType = $_POST['recordType'] ?? '';
            $ip = $_POST['ip'] ?? '';
            
            if (empty($accessKeyId) || empty($accessKeySecret) || empty($domainName) || empty($rr) || empty($recordType) || empty($ip)) {
                echo json_encode(array('success' => false, 'message' => '请填写完整的配置信息'));
                exit;
            }
            
            // 验证域名格式
            if (!preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domainName)) {
                echo json_encode(array('success' => false, 'message' => '域名格式无效，请输入正确的域名格式，例如：example.com'));
                exit;
            }
            
            $result = updateAliyunDNS($accessKeyId, $accessKeySecret, $domainName, $rr, $recordType, $ip);
            
            // 处理特定错误信息
            if (!$result['success']) {
                if (strpos($result['message'], 'The specified domain name does not exist') !== false) {
                    $result['message'] = '域名信息不存在，请添加到云解析DNS服务中';
                } elseif (strpos($result['message'], 'The domain name belongs to other users') !== false) {
                    $result['message'] = '域名不属于当前用户，请转移后再尝试操作';
                } elseif (strpos($result['message'], 'SignatureDoesNotMatch') !== false || strpos($result['message'], 'signature is not matched') !== false) {
                    $result['message'] = 'AccessKey Secret 无效或签名错误';
                } elseif (strpos($result['message'], 'InvalidAccessKeyId') !== false || strpos($result['message'], 'access key is not found') !== false) {
                    $result['message'] = 'AccessKey ID 无效';
                } elseif (strpos($result['message'], 'AccessKeyDisabled') !== false) {
                    $result['message'] = 'AccessKey 已被禁用';
                } elseif (strpos($result['message'], 'InvalidAccessKeyType') !== false) {
                    $result['message'] = 'AccessKey 类型错误';
                } elseif (strpos($result['message'], 'AccessKey') !== false || strpos($result['message'], 'access key') !== false) {
                    $result['message'] = 'AccessKey 错误，请检查您的AccessKey配置';
                }
            }
            
            echo json_encode($result);
            exit;
            
        default:
            echo json_encode(array('success' => false, 'message' => '未知操作'));
            exit;
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>阿里云DDNS解析更新工具</title>
    <!-- 引入外部资源 -->
    <script src="css/4.0.7.js"></script>
 <link href="css/css/font-awesome.min.css" rel="stylesheet">
    
    <!-- Tailwind 配置 -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#0088ff', // 阿里云蓝
                        secondary: '#666666',
                        success: '#52c41a',
                        warning: '#faad14',
                        danger: '#ff4d4f',
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    
    <!-- 自定义样式 -->
    <style type="text/tailwindcss">
        @layer utilities {
            .content-auto {
                content-visibility: auto;
            }
            .shadow-soft {
                box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            }
            .transition-all-300 {
                transition: all 0.3s ease;
            }
        }
    </style>
</head>
<body class="bg-gray-50 font-sans text-gray-800 min-h-screen">
    <!-- 顶部导航 -->
    <header class="bg-white shadow-soft sticky top-0 z-50">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-2">
                <i class="fa fa-cloud text-primary text-2xl"></i>
                <h1 class="text-xl md:text-2xl font-bold text-primary">阿里云DDNS解析工具</h1>
            </div>
            <div class="text-sm text-secondary">
                <span id="currentIpDisplay" class="hidden md:inline-block"></span>
            </div>
        </div>
    </header>

    <!-- 主内容区 -->
    <main class="container mx-auto px-4 py-8 max-w-3xl">
        <!-- IP检测卡片 -->
        <div class="bg-white rounded-xl shadow-soft p-6 mb-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold flex items-center">
                    <i class="fa fa-bolt text-warning mr-2"></i> 阿里云DDNS自动更新
                </h2>
                <div id="updateStatus" class="text-sm text-gray-600 flex items-center">
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
                            <input type="text" id="publicIp" class="flex-1 outline-none text-lg font-medium" placeholder="检测中..." readonly>
                            <button id="refreshIpBtn" class="ml-2 bg-primary/10 text-primary px-3 py-1 rounded-lg hover:bg-primary/20 transition-all-300 text-sm">
                                <i class="fa fa-refresh mr-1"></i> 刷新IP
                            </button>
                        </div>
                          
                    </div>
                    <div class="w-full md:w-1/3">
                        <div id="ipStatus" class="flex items-center justify-center p-3 rounded-lg bg-gray-100">
                            <i class="fa fa-spinner fa-spin mr-2"></i>
                            <span>检测IP中...</span>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center space-x-4">
                        <label class="flex items-center">
                            <input type="checkbox" id="autoUpdateToggle" class="mr-2">
                            <span>启用自动更新</span>
                        </label>
                        <div id="autoUpdateStatus" class="text-sm text-gray-600">
                            自动更新已禁用
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <select id="updateInterval" class="border rounded px-2 py-1 text-sm">
                            <option value="60000">1分钟</option>
                            <option value="300000">5分钟</option>
                            <option value="600000">10分钟</option>
                            <option value="1800000">30分钟</option>
                            <option value="3600000">1小时</option>
                        </select>
                        <div id="autoUpdateCountdown" class="text-sm text-gray-600 hidden">
                            计时: <span id="countdownTimer">00:00</span>
                        </div>
                    </div>
                </div>
            </div>
            
         
            
          
            
            <!-- 操作按钮 -->
            <div class="flex flex-col sm:flex-row gap-4 mb-6">
                <button id="openConfigBtn" class="flex-1 bg-primary text-white px-6 py-2 rounded-lg hover:bg-primary/90 transition-all-300 flex items-center justify-center">
                    <i class="fa fa-cog mr-2"></i> 阿里云配置
                </button>
                <button id="updateDdnsBtn" class="flex-1 bg-primary text-white py-2 rounded-lg hover:bg-primary/90 transition-all-300 flex items-center justify-center">
                    <i class="fa fa-cloud-upload mr-2"></i> 立即更新DNS解析
                </button>
            </div>
            
           
            
          
        </div>

 
        
        <!-- 阿里云配置弹出层 -->
        <div id="configModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;" class="flex">
            <div style="background: white; border-radius: 12px; padding: 28px; max-width: 550px; width: 90%; max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.15);">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold flex items-center text-gray-800">
                        <i class="fa fa-cog text-primary mr-3"></i> 阿里云配置
                    </h2>
                    <button id="closeConfigBtn" class="text-gray-500 hover:text-gray-700 transition-all-300 p-1 rounded-full hover:bg-gray-100">
                        <i class="fa fa-times text-xl"></i>
                    </button>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div>
                        <label class="block text-sm font-medium mb-3 text-gray-700">AccessKey ID</label>
                        <input type="text" id="accessKeyId" class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50" placeholder="请输入阿里云AccessKey ID" value="">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-3 text-gray-700">AccessKey Secret</label>
                        <input type="password" id="accessKeySecret" class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50" placeholder="请输入阿里云AccessKey Secret" value="">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div>
                        <label class="block text-sm font-medium mb-3 text-gray-700">域名 (例如: example.com)</label>
                        <input type="text" id="domainName" class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50" placeholder="请输入主域名">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-3 text-gray-700">解析记录 (例如: www)</label>
                        <input type="text" id="rr" class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50" placeholder="默认@" value="@">
                    </div>
                </div>
                
                <div class="mb-8">
                    <label class="block text-sm font-medium mb-3 text-gray-700">记录类型</label>
                    <select id="recordType" class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50">
                        <option value="A">A记录 (IPv4地址)</option>
                        <option value="AAAA">AAAA记录 (IPv6地址)</option>
                    </select>
                </div>
                
                <div class="flex justify-between items-center pt-4 border-t border-gray-200">
                    <button id="saveConfigBtn" class="bg-primary text-white px-5 py-2.5 rounded-lg hover:bg-primary/90 transition-all-300">
                        <i class="fa fa-save mr-2"></i> 保存配置
                    </button>
                    <button id="testConfigBtn" class="bg-warning text-white px-5 py-2.5 rounded-lg hover:bg-warning/90 transition-all-300">
                        <i class="fa fa-check mr-2"></i> 测试配置
                    </button>
                </div>
            </div>
        </div>

        <!-- 更新状态卡片 -->
        <div class="bg-white rounded-xl shadow-soft p-6">
            <h2 class="text-lg font-bold mb-4 flex items-center">
                <i class="fa fa-refresh text-primary mr-2"></i> 更新状态
            </h2>
            
            <div class="mb-6">
                <div class="flex items-center mb-2">
                    <i class="fa fa-clock-o text-secondary mr-2"></i>
                    <span class="text-sm text-secondary">上次更新时间:</span>
                    <span id="lastUpdateTime" class="ml-2 text-sm">从未更新</span>
                </div>
                <div class="flex items-center">
                    <i class="fa fa-history text-secondary mr-2"></i>
                    <span class="text-sm text-secondary">上次更新IP:</span>
                    <span id="lastUpdateIp" class="ml-2 text-sm">无</span>
                </div>
                <div class="flex items-center mt-2">
                    <i class="fa fa-wifi text-secondary mr-2"></i>
                    <span class="text-sm text-secondary">当前IP:</span>
                    <span id="currentIp" class="ml-2 text-sm">未检测</span>
                </div>
            </div>
            
            <!-- 日志记录 -->
            <div class="mb-6">
                <h3 class="text-md font-medium mb-3 flex items-center cursor-pointer" id="logToggle">
                    <i class="fa fa-chevron-right text-primary mr-2" id="logToggleIcon"></i>
                    <i class="fa fa-history text-primary mr-2"></i> 更新日志
                </h3>
                <div id="logContainer" class="border rounded-lg p-4 max-h-60 overflow-y-auto" style="display: none;">
                    <div id="logList" class="space-y-1">
                        <!-- 日志记录将在这里显示 -->
                    </div>
                </div>
                <div id="pagination" class="flex justify-center mt-3 space-x-2" style="display: none;">
                    <!-- 分页按钮将在这里显示 -->
                </div>
            </div>
        </div>
    </main>

    <!-- 页脚 -->
    <footer class="bg-white border-t mt-8 py-6">
        <div class="container mx-auto px-4 text-center text-sm text-secondary">
            <p>阿里云DDNS解析更新工具 &copy; 2026</p>
        </div>
    </footer>
    
    <!-- 提示弹出层 -->
    <div id="alertModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;" class="flex">
        <div style="background: white; border-radius: 12px; padding: 24px; max-width: 400px; width: 90%; box-shadow: 0 10px 25px rgba(0,0,0,0.15);">
            <div class="flex items-center mb-4" id="alertIconContainer">
                <i class="fa fa-info-circle text-primary text-2xl mr-3" id="alertIcon"></i>
                <h3 class="text-lg font-bold" id="alertTitle">提示</h3>
            </div>
            <div class="mb-6" id="alertMessage">
                提示信息
            </div>
            <div class="flex justify-end">
                <button id="alertOkBtn" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90 transition-all-300">
                    确定
                </button>
            </div>
        </div>
    </div>

    <!-- 脚本 -->
    <script>
        // 日志相关变量
        const MAX_LOGS = 30;
        const LOGS_PER_PAGE = 5;
        let currentPage = 1;
        
        // 页面加载时检测IP
        document.addEventListener('DOMContentLoaded', function() {
            checkIP();
            loadConfig();
            loadLogs();
        });

        // 检测IP
        function checkIP() {
            const ipStatus = document.getElementById('ipStatus');
            const publicIp = document.getElementById('publicIp');
            
            ipStatus.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i><span>检测IP中...</span>';
            ipStatus.className = 'flex items-center justify-center p-3 rounded-lg bg-gray-100';
            
            fetch('?action=get_ip')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        publicIp.value = data.ip;
                        ipStatus.innerHTML = '<i class="fa fa-check-circle text-success mr-2"></i><span>IP检测成功</span>';
                        ipStatus.className = 'flex items-center justify-center p-3 rounded-lg bg-success/10 text-success';
                        document.getElementById('currentIpDisplay').textContent = `当前IP: ${data.ip}`;
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

        // 刷新IP按钮
        document.getElementById('refreshIpBtn').addEventListener('click', function() {
            checkIP();
        });

        // 保存配置
        document.getElementById('saveConfigBtn').addEventListener('click', function() {
            const config = {
                accessKeyId: document.getElementById('accessKeyId').value,
                accessKeySecret: document.getElementById('accessKeySecret').value,
                domainName: document.getElementById('domainName').value,
                rr: document.getElementById('rr').value,
                recordType: document.getElementById('recordType').value
            };
            
            localStorage.setItem('aliyunDdnsConfig', JSON.stringify(config));
            
            // 显示保存成功提示
            const btn = this;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-check mr-1"></i> 保存成功';
            btn.className = 'bg-success/10 text-success px-4 py-2 rounded-lg transition-all-300';
            
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.className = 'bg-primary/10 text-primary px-4 py-2 rounded-lg hover:bg-primary/20 transition-all-300';
            }, 2000);
        });

        // 加载配置
        function loadConfig() {
            const config = localStorage.getItem('aliyunDdnsConfig');
            if (config) {
                try {
                    const parsedConfig = JSON.parse(config);
                    document.getElementById('accessKeyId').value = parsedConfig.accessKeyId || '';
                    document.getElementById('accessKeySecret').value = parsedConfig.accessKeySecret || '';
                    document.getElementById('domainName').value = parsedConfig.domainName || '';
                    document.getElementById('rr').value = parsedConfig.rr || '@';
                    document.getElementById('recordType').value = parsedConfig.recordType || 'A';
                } catch (error) {
                    console.error('加载配置失败:', error);
                }
            }
        }

        // 测试配置
        document.getElementById('testConfigBtn').addEventListener('click', function() {
            const btn = this;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin mr-1"></i> 测试中...';
            btn.disabled = true;
            
            // 简单验证配置
            const accessKeyId = document.getElementById('accessKeyId').value;
            const accessKeySecret = document.getElementById('accessKeySecret').value;
            const domainName = document.getElementById('domainName').value;
            
            if (!accessKeyId || !accessKeySecret || !domainName) {
                showAlert('提示', '请填写完整的配置信息', 'warning');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }
            
            // 验证域名格式
            const domainRegex = /^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/;
            if (!domainRegex.test(domainName)) {
                showAlert('提示', '请输入有效的域名格式，例如：example.com', 'warning');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }
            
            // 发送测试请求
            fetch('?action=test_config', {
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
                    btn.className = 'bg-success/10 text-success px-4 py-2 rounded-lg transition-all-300';
                    showAlert('测试成功', data.message, 'success');
                } else {
                    btn.innerHTML = '<i class="fa fa-times mr-1"></i> 测试失败';
                    btn.className = 'bg-danger/10 text-danger px-4 py-2 rounded-lg transition-all-300';
                    showAlert('测试失败', data.message, 'error');
                }
                
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.className = 'bg-warning text-white px-5 py-2.5 rounded-lg hover:bg-warning/90 transition-all-300';
                    btn.disabled = false;
                }, 2000);
            })
            .catch(error => {
                btn.innerHTML = '<i class="fa fa-times mr-1"></i> 测试失败';
                btn.className = 'bg-danger/10 text-danger px-4 py-2 rounded-lg transition-all-300';
                showAlert('测试失败', '网络错误，请稍后重试', 'error');
                
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.className = 'bg-warning text-white px-5 py-2.5 rounded-lg hover:bg-warning/90 transition-all-300';
                    btn.disabled = false;
                }, 2000);
            });
        });

        // 更新DDNS
        document.getElementById('updateDdnsBtn').addEventListener('click', function() {
            const btn = this;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i> 更新中...';
            btn.disabled = true;
            
            const accessKeyId = document.getElementById('accessKeyId').value;
            const accessKeySecret = document.getElementById('accessKeySecret').value;
            const domainName = document.getElementById('domainName').value;
            const rr = document.getElementById('rr').value;
            const recordType = document.getElementById('recordType').value;
            const ip = document.getElementById('publicIp').value;
            
            // 验证域名格式
            const domainRegex = /^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/;
            if (!domainRegex.test(domainName)) {
                showAlert('提示', '请输入有效的域名格式，例如：example.com', 'warning');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }
            
            if (!accessKeyId || !accessKeySecret || !domainName || !rr || !recordType || !ip || ip === '检测失败') {
                showAlert('提示', '请填写完整的配置信息并确保IP检测成功', 'warning');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }
            
            // 显示正在执行的提示信息
            const updateStatus = document.getElementById('updateStatus');
            updateStatus.innerHTML = '<i class="fa fa-spinner fa-spin mr-2"></i><span>正在执行...</span>';
            updateStatus.className = 'text-sm text-primary flex items-center';
            
            fetch('?action=update_ddns', {
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
                const updateStatus = document.getElementById('updateStatus');
                
                if (data.success) {
                    updateStatus.innerHTML = `<i class="fa fa-check-circle text-success mr-2"></i><span>${data.message}</span>`;
                    updateStatus.className = 'text-sm text-success flex items-center';
                    
                    // 更新上次更新时间和IP
                    const now = new Date().toLocaleString();
                    document.getElementById('lastUpdateTime').textContent = now;
                    document.getElementById('lastUpdateIp').textContent = ip;
                    
                    // 保存到本地存储
                    localStorage.setItem('lastUpdateTime', now);
                    localStorage.setItem('lastUpdateIp', ip);
                    
                    // 保存日志
                    saveLog(`手动更新: ${data.message}`);
                } else {
                    updateStatus.innerHTML = `<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>更新失败: ${data.message}</span>`;
                    updateStatus.className = 'text-sm text-danger flex items-center';
                    
                    // 保存日志
                    saveLog(`手动更新失败: ${data.message}`, false);
                }
                
                btn.innerHTML = originalText;
                btn.disabled = false;
            })
            .catch(error => {
                const updateStatus = document.getElementById('updateStatus');
                updateStatus.innerHTML = '<div class="flex items-center"><i class="fa fa-exclamation-circle text-danger mr-2"></i><span>网络错误，请稍后重试</span></div>';
                updateStatus.className = 'p-4 rounded-lg bg-danger/10 text-danger mb-6';
                
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        });

        // 加载上次更新信息
        function loadLastUpdate() {
            const lastUpdateTime = localStorage.getItem('lastUpdateTime');
            const lastUpdateIp = localStorage.getItem('lastUpdateIp');
            
            if (lastUpdateTime) {
                document.getElementById('lastUpdateTime').textContent = lastUpdateTime;
            }
            if (lastUpdateIp) {
                document.getElementById('lastUpdateIp').textContent = lastUpdateIp;
            }
        }
        
        // 保存日志
        function saveLog(message, isSuccess = true) {
            // 获取现有日志
            let logs = JSON.parse(localStorage.getItem('ddnsLogs') || '[]');
            
            // 创建新日志
            const newLog = {
                id: Date.now(),
                timestamp: new Date().toLocaleString(),
                message: message,
                success: isSuccess
            };
            
            // 添加到日志开头
            logs.unshift(newLog);
            
            // 限制日志数量
            if (logs.length > MAX_LOGS) {
                logs = logs.slice(0, MAX_LOGS);
            }
            
            // 保存到本地存储
            localStorage.setItem('ddnsLogs', JSON.stringify(logs));
            
            // 重新加载日志
            loadLogs();
        }
        
        // 加载日志
        function loadLogs() {
            const logs = JSON.parse(localStorage.getItem('ddnsLogs') || '[]');
            displayLogs(logs);
        }
        
        // 显示日志
        function displayLogs(logs) {
            const logList = document.getElementById('logList');
            const pagination = document.getElementById('pagination');
            
            // 计算总页数
            const totalPages = Math.ceil(logs.length / LOGS_PER_PAGE);
            
            // 计算当前页的日志
            const startIndex = (currentPage - 1) * LOGS_PER_PAGE;
            const endIndex = startIndex + LOGS_PER_PAGE;
            const currentLogs = logs.slice(startIndex, endIndex);
            
            // 显示日志
            logList.innerHTML = '';
            if (currentLogs.length === 0) {
                logList.innerHTML = '<div class="text-center text-gray-500">暂无日志记录</div>';
            } else {
                currentLogs.forEach(log => {
                    const logItem = document.createElement('div');
                    logItem.className = `flex items-start p-2 rounded ${log.success ? 'bg-success/5' : 'bg-danger/5'}`;
                    logItem.innerHTML = `
                        <i class="fa ${log.success ? 'fa-check-circle text-success' : 'fa-exclamation-circle text-danger'} mr-2 mt-1"></i>
                        <div class="flex-1 flex justify-between items-center">
                            <div class="text-sm font-medium">${log.message}</div>
                            <div class="text-xs text-gray-500">${log.timestamp}</div>
                        </div>
                    `;
                    logList.appendChild(logItem);
                });
            }
            
            // 显示分页按钮
            pagination.innerHTML = '';
            if (totalPages > 1) {
                // 上一页按钮
                const prevBtn = document.createElement('button');
                prevBtn.className = `px-3 py-1 rounded ${currentPage > 1 ? 'bg-primary text-white' : 'bg-gray-200 text-gray-500 cursor-not-allowed'}`;
                prevBtn.textContent = '上一页';
                prevBtn.disabled = currentPage === 1;
                prevBtn.addEventListener('click', () => {
                    if (currentPage > 1) {
                        currentPage--;
                        loadLogs();
                    }
                });
                pagination.appendChild(prevBtn);
                
                // 页码按钮
                for (let i = 1; i <= totalPages; i++) {
                    const pageBtn = document.createElement('button');
                    pageBtn.className = `px-3 py-1 rounded ${i === currentPage ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700'}`;
                    pageBtn.textContent = i;
                    pageBtn.addEventListener('click', () => {
                        currentPage = i;
                        loadLogs();
                    });
                    pagination.appendChild(pageBtn);
                }
                
                // 下一页按钮
                const nextBtn = document.createElement('button');
                nextBtn.className = `px-3 py-1 rounded ${currentPage < totalPages ? 'bg-primary text-white' : 'bg-gray-200 text-gray-500 cursor-not-allowed'}`;
                nextBtn.textContent = '下一页';
                nextBtn.disabled = currentPage === totalPages;
                nextBtn.addEventListener('click', () => {
                    if (currentPage < totalPages) {
                        currentPage++;
                        loadLogs();
                    }
                });
                pagination.appendChild(nextBtn);
            }
        }

        // 自动更新相关变量
        let autoUpdateInterval = null;
        let countdownInterval = null;
        let lastDetectedIp = '';
        
        // 初始化自动更新设置
        function initAutoUpdate() {
            // 加载保存的自动更新设置
            const autoUpdateEnabled = localStorage.getItem('autoUpdateEnabled') === 'true';
            const savedInterval = localStorage.getItem('updateInterval') || '60000';
            
            // 应用设置
            document.getElementById('autoUpdateToggle').checked = autoUpdateEnabled;
            document.getElementById('updateInterval').value = savedInterval;
            
            // 更新状态显示
            updateAutoUpdateStatus();
            
            // 如果启用了自动更新，开始检测
            if (autoUpdateEnabled) {
                startAutoUpdate();
            }
        }
        
        // 更新自动更新状态显示
        function updateAutoUpdateStatus() {
            const autoUpdateEnabled = document.getElementById('autoUpdateToggle').checked;
            const interval = document.getElementById('updateInterval').value;
            const statusElement = document.getElementById('autoUpdateStatus');
            
            if (autoUpdateEnabled) {
                const intervalMinutes = parseInt(interval) / 60000;
                statusElement.textContent = `每 ${intervalMinutes} 分钟检测IP变化`;
                statusElement.className = 'text-sm text-success';
            } else {
                statusElement.textContent = '自动更新已禁用';
                statusElement.className = 'text-sm text-gray-600';
            }
        }
        
        // 开始自动更新
        function startAutoUpdate() {
            // 清除现有的定时器
            if (autoUpdateInterval) {
                clearInterval(autoUpdateInterval);
            }
            if (countdownInterval) {
                clearInterval(countdownInterval);
            }
            
            // 获取更新间隔
            const interval = parseInt(document.getElementById('updateInterval').value);
            
            // 立即执行一次检测
            checkIpAndUpdate();
            
            // 设置定时器
            autoUpdateInterval = setInterval(checkIpAndUpdate, interval);
            
            // 启动倒计时
            startCountdown(interval);
            
            // 保存设置
            localStorage.setItem('autoUpdateEnabled', 'true');
            localStorage.setItem('updateInterval', interval.toString());
        }
        
        // 停止自动更新
        function stopAutoUpdate() {
            if (autoUpdateInterval) {
                clearInterval(autoUpdateInterval);
                autoUpdateInterval = null;
            }
            if (countdownInterval) {
                clearInterval(countdownInterval);
                countdownInterval = null;
            }
            
            // 隐藏倒计时
            document.getElementById('autoUpdateCountdown').classList.add('hidden');
            
            // 保存设置
            localStorage.setItem('autoUpdateEnabled', 'false');
        }
        
        // 启动倒计时
        function startCountdown(interval) {
            // 显示倒计时
            document.getElementById('autoUpdateCountdown').classList.remove('hidden');
            
            // 计算总秒数
            let totalSeconds = interval / 1000;
            let seconds = totalSeconds;
            
            // 更新倒计时显示
            function updateCountdown() {
                const minutes = Math.floor(seconds / 60);
                const remainingSeconds = Math.floor(seconds % 60);
                
                // 格式化为两位数
                const formattedMinutes = minutes.toString().padStart(2, '0');
                const formattedSeconds = remainingSeconds.toString().padStart(2, '0');
                
                // 更新显示
                document.getElementById('countdownTimer').textContent = `${formattedMinutes}:${formattedSeconds}`;
                
                // 减少秒数
                seconds--;
                
                // 如果倒计时结束，重置
                if (seconds < 0) {
                    seconds = totalSeconds;
                }
            }
            
            // 立即更新一次
            updateCountdown();
            
            // 设置定时器，每秒更新一次
            countdownInterval = setInterval(updateCountdown, 1000);
        }
        
        // 检查IP变化并更新
        function checkIpAndUpdate() {
            fetch('?action=get_ip')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const currentIp = data.ip;
                        document.getElementById('currentIp').textContent = currentIp;
                        
                        // 检查IP是否变化
                        if (currentIp !== lastDetectedIp && lastDetectedIp !== '') {
                            // IP发生变化，执行更新
                            performAutoUpdate(currentIp);
                        }
                        
                        // 更新上次检测的IP
                        lastDetectedIp = currentIp;
                    }
                })
                .catch(error => {
                    console.error('自动检测IP失败:', error);
                });
        }
        
        // 执行自动更新
        function performAutoUpdate(ip) {
            const accessKeyId = document.getElementById('accessKeyId').value;
            const accessKeySecret = document.getElementById('accessKeySecret').value;
            const domainName = document.getElementById('domainName').value;
            const rr = document.getElementById('rr').value;
            const recordType = document.getElementById('recordType').value;
            
            // 验证配置是否完整
            if (!accessKeyId || !accessKeySecret || !domainName || !rr || !recordType || !ip) {
                console.log('配置不完整，跳过自动更新');
                return;
            }
            
            // 发送更新请求
            fetch('?action=update_ddns', {
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
                const updateStatus = document.getElementById('updateStatus');
                
                if (data.success) {
                    updateStatus.innerHTML = `<i class="fa fa-check-circle text-success mr-2"></i><span>自动更新: ${data.message}</span>`;
                    updateStatus.className = 'text-sm text-success flex items-center';
                    
                    // 更新上次更新时间和IP
                    const now = new Date().toLocaleString();
                    document.getElementById('lastUpdateTime').textContent = now;
                    document.getElementById('lastUpdateIp').textContent = ip;
                    
                    // 保存到本地存储
                    localStorage.setItem('lastUpdateTime', now);
                    localStorage.setItem('lastUpdateIp', ip);
                    
                    // 保存日志
                    saveLog(`自动更新: ${data.message}`);
                } else {
                    updateStatus.innerHTML = `<i class="fa fa-exclamation-circle text-danger mr-2"></i><span>自动更新失败: ${data.message}</span>`;
                    updateStatus.className = 'text-sm text-danger flex items-center';
                    
                    // 保存日志
                    saveLog(`自动更新失败: ${data.message}`, false);
                }
                
                // 3秒后恢复默认状态
                setTimeout(() => {
                    updateStatus.innerHTML = '<i class="fa fa-info-circle text-primary mr-2"></i><span>点击下方按钮更新DNS解析</span>';
                    updateStatus.className = 'text-sm text-gray-600 flex items-center';
                }, 3000);
            })
            .catch(error => {
                const updateStatus = document.getElementById('updateStatus');
                updateStatus.innerHTML = '<div class="flex items-center"><i class="fa fa-exclamation-circle text-danger mr-2"></i><span>网络错误，请稍后重试</span></div>';
                updateStatus.className = 'p-4 rounded-lg bg-danger/10 text-danger mb-6';
                
                // 3秒后恢复默认状态
                setTimeout(() => {
                    updateStatus.innerHTML = '<i class="fa fa-info-circle text-primary mr-2"></i><span>点击下方按钮更新DNS解析</span>';
                    updateStatus.className = 'text-sm text-gray-600 flex items-center';
                }, 3000);
            });
        }
        
        // 自动更新开关事件
        document.getElementById('autoUpdateToggle').addEventListener('change', function() {
            if (this.checked) {
                startAutoUpdate();
            } else {
                stopAutoUpdate();
            }
            updateAutoUpdateStatus();
        });
        
        // 更新间隔变化事件
        document.getElementById('updateInterval').addEventListener('change', function() {
            if (document.getElementById('autoUpdateToggle').checked) {
                startAutoUpdate();
            }
            updateAutoUpdateStatus();
        });
        
        // 页面加载时加载上次更新信息
        loadLastUpdate();
        
        // 初始化自动更新设置
        initAutoUpdate();
        
        // 初始化日志折叠功能
        initLogToggle();
        
        // 初始化提示弹出层
        initAlertModal();
        
        // 打开配置弹出层
        document.getElementById('openConfigBtn').addEventListener('click', function() {
            const configModal = document.getElementById('configModal');
            configModal.style.display = 'flex';
        });
        
        // 关闭配置弹出层
        document.getElementById('closeConfigBtn').addEventListener('click', function() {
            const configModal = document.getElementById('configModal');
            configModal.style.display = 'none';
        });
        
        // 点击弹出层外部关闭
        document.getElementById('configModal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
        
        // 初始化日志折叠功能
        function initLogToggle() {
            const logToggle = document.getElementById('logToggle');
            const logContainer = document.getElementById('logContainer');
            const pagination = document.getElementById('pagination');
            const logToggleIcon = document.getElementById('logToggleIcon');
            
            logToggle.addEventListener('click', function() {
                if (logContainer.style.display === 'none') {
                    // 展开
                    logContainer.style.display = 'block';
                    pagination.style.display = 'flex';
                    logToggleIcon.className = 'fa fa-chevron-down text-primary mr-2';
                } else {
                    // 折叠
                    logContainer.style.display = 'none';
                    pagination.style.display = 'none';
                    logToggleIcon.className = 'fa fa-chevron-right text-primary mr-2';
                }
            });
        }
        
        // 初始化提示弹出层
        function initAlertModal() {
            const alertOkBtn = document.getElementById('alertOkBtn');
            const alertModal = document.getElementById('alertModal');
            
            // 点击确定按钮关闭弹出层
            alertOkBtn.addEventListener('click', function() {
                alertModal.style.display = 'none';
            });
            
            // 点击弹出层外部关闭
            alertModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        }
        
        // 显示提示弹出层
        function showAlert(title, message, type = 'info') {
            const alertModal = document.getElementById('alertModal');
            const alertTitle = document.getElementById('alertTitle');
            const alertMessage = document.getElementById('alertMessage');
            const alertIcon = document.getElementById('alertIcon');
            
            // 设置标题和消息
            alertTitle.textContent = title;
            alertMessage.textContent = message;
            
            // 设置图标和颜色
            switch (type) {
                case 'success':
                    alertIcon.className = 'fa fa-check-circle text-success text-2xl mr-3';
                    break;
                case 'error':
                    alertIcon.className = 'fa fa-exclamation-circle text-danger text-2xl mr-3';
                    break;
                case 'warning':
                    alertIcon.className = 'fa fa-exclamation-triangle text-warning text-2xl mr-3';
                    break;
                default:
                    alertIcon.className = 'fa fa-info-circle text-primary text-2xl mr-3';
            }
            
            // 显示弹出层
            alertModal.style.display = 'flex';
        }
    </script>
</body>
</html>