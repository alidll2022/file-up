<?php
// 阿里云DDNS解析更新API接口

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
        }
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
        return array('success' => false, 'message' => $data['Message']);
    }
    
    return array('success' => true, 'data' => $data);
}

// 获取客户端公网IP
function getPublicIP() {
    // 优先从客户端请求头获取IP
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
        // 跳过本地回环地址
        if (filter_var($ip, FILTER_VALIDATE_IP) && !isPrivateIP($ip) && !in_array($ip, ['127.0.0.1', '::1'])) {
            return $ip;
        }
    }
    
    // 如果以上方法都失败，尝试通过服务器获取（作为备用方案）
    $ipServices = array(
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://ipinfo.io/ip',
        'https://api.myip.com',
        'https://ip.seeip.org',
        'https://ifconfig.co/ip',
        'https://api.ip.sb/ip',
        'https://ifconfig.io/ip',
        'https://ipecho.net/plain',
        'https://wtfismyip.com/text',
        'https://api.ipify.org/?format=text',
        'https://ipaddr.site'
    );
    
    $ipResults = array();
    
    foreach ($ipServices as $service) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $service);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        
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
                $ipResults[] = $ip;
            }
        }
    }
    
    // 如果有多个IP结果，返回最常见的那个
    if (!empty($ipResults)) {
        $ipCounts = array_count_values($ipResults);
        arsort($ipCounts);
        return key($ipCounts);
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

// 主API处理函数
function handleDdnsUpdate() {
    // 获取参数
    $accessKeyId = '';
    $accessKeySecret = '';
    $domainName = '';
    $rr = '@';
    $recordType = 'A';
    $ip = '';
    
    // 检查是否是JSON POST请求
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $jsonData = file_get_contents('php://input');
        $data = json_decode($jsonData, true);
        
        if ($data) {
            $accessKeyId = $data['accessKeyId'] ?? '';
            $accessKeySecret = $data['accessKeySecret'] ?? '';
            $domainName = $data['domainName'] ?? '';
            $rr = $data['rr'] ?? '@';
            $recordType = $data['recordType'] ?? 'A';
            $ip = $data['ip'] ?? '';
        }
    } else {
        // 传统的GET或POST请求
        $accessKeyId = $_REQUEST['accessKeyId'] ?? '';
        $accessKeySecret = $_REQUEST['accessKeySecret'] ?? '';
        $domainName = $_REQUEST['domainName'] ?? '';
        $rr = $_REQUEST['rr'] ?? '@';
        $recordType = $_REQUEST['recordType'] ?? 'A';
        $ip = $_REQUEST['ip'] ?? '';
    }
    
    // 验证必填参数
    if (empty($accessKeyId) || empty($accessKeySecret) || empty($domainName)) {
        return array('success' => false, 'message' => '缺少必要参数: accessKeyId, accessKeySecret, domainName');
    }
    
    // 验证域名格式
    if (!preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domainName)) {
        return array('success' => false, 'message' => '域名格式无效，请输入正确的域名格式，例如：example.com');
    }
    
    // 2. 检测域名信息（验证阿里云配置是否正确）
    $recordInfo = getRecordInfo($accessKeyId, $accessKeySecret, $domainName, $rr, $recordType);
    if (!$recordInfo) {
        // 尝试获取域名记录，不指定RR和类型，验证域名是否存在
        $tempRecordInfo = getRecordInfo($accessKeyId, $accessKeySecret, $domainName, '', '');
        if (!$tempRecordInfo) {
            return array('success' => false, 'message' => '域名信息不存在或AccessKey配置错误，请检查您的阿里云配置');
        }
    }
    
    // 3. 获取客户端外网IP
    if (empty($ip)) {
        $ip = getPublicIP();
        if (!$ip) {
            return array('success' => false, 'message' => '获取公网IP失败');
        }
    } else {
        // 验证IP格式
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return array('success' => false, 'message' => 'IP地址格式无效');
        }
        // 检查是否为本地回环地址
        if (in_array($ip, ['127.0.0.1', '::1']) || isPrivateIP($ip)) {
            // 本地地址，重新获取
            $ip = getPublicIP();
            if (!$ip) {
                return array('success' => false, 'message' => '获取公网IP失败');
            }
        }
    }
    
    // 4. 执行更新动作
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
        } elseif (strpos($result['message'], 'The DNS record is invalid or in the wrong format') !== false) {
            $result['message'] = 'DNS记录无效或格式错误，请检查IP地址是否正确';
        } else {
            // 其他错误，保持原样或添加通用提示
            $result['message'] = '操作失败: ' . $result['message'];
        }
    }
    
    // 添加IP信息到结果
    $result['ip'] = $ip;
    
    return $result;
}

// 处理API请求
header('Content-Type: application/json');

// 允许跨域请求
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理OPTIONS请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 检查是否只是获取IP
if (empty($_REQUEST['accessKeyId']) && empty($_REQUEST['accessKeySecret']) && empty($_REQUEST['domainName'])) {
    // 检查JSON请求
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $jsonData = file_get_contents('php://input');
        $data = json_decode($jsonData, true);
        if ($data && (empty($data['accessKeyId']) && empty($data['accessKeySecret']) && empty($data['domainName']))) {
            // 只获取IP，不需要认证
            $ip = getPublicIP();
            if ($ip) {
                echo json_encode(array('success' => true, 'ip' => $ip, 'message' => 'IP检测成功'));
            } else {
                echo json_encode(array('success' => false, 'message' => '获取公网IP失败'));
            }
            exit;
        }
    }
    // 只获取IP，不需要认证
    $ip = getPublicIP();
    if ($ip) {
        echo json_encode(array('success' => true, 'ip' => $ip, 'message' => 'IP检测成功'));
    } else {
        echo json_encode(array('success' => false, 'message' => '获取公网IP失败'));
    }
    exit;
}

// 执行更新并返回结果
$result = handleDdnsUpdate();
echo json_encode($result);
exit;
?>