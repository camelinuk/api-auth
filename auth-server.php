<?php
/**
 * Time-Based Authentication Server
 * 時間基礎認證伺服器
 *
 * 提供基於時間戳的 HMAC 認證機制
 * 有效期：10分鐘
 */

class AuthenticationServer
{
    private $config;
    private $logFile;

    public function __construct($configPath = 'auth-config.php')
    {
        $this->config = require $configPath;
        $this->logFile = $this->config['log_file'];

        // 設定時區為 UTC
        date_default_timezone_set($this->config['timezone']);
    }

    /**
     * 處理認證請求
     */
    public function handleRequest()
    {
        // 設定 CORS 標頭（如需跨域請求）
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST');
        header('Access-Control-Allow-Headers: Content-Type');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendError('只接受 POST 請求', 405);
            return;
        }

        // 獲取客戶端 IP
        $clientIp = $this->getClientIp();

        // 驗證 IP 白名單
        if (!$this->isIpAllowed($clientIp)) {
            $this->log("IP 驗證失敗: {$clientIp}");
            $this->sendError('IP 地址未授權', 403);
            return;
        }

        // 解析請求數據
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError('無效的 JSON 格式', 400);
            return;
        }

        // 驗證必要欄位
        if (!isset($data['client_id']) || !isset($data['timestamp']) || !isset($data['signature'])) {
            $this->sendError('缺少必要欄位: client_id, timestamp, signature', 400);
            return;
        }

        $clientId = $data['client_id'];
        $timestamp = $data['timestamp'];
        $signature = $data['signature'];
        $requestData = $data['data'] ?? '';

        // 執行認證驗證
        $result = $this->authenticate($clientId, $timestamp, $signature, $requestData);

        if ($result['success']) {
            $this->log("認證成功: Client={$clientId}, IP={$clientIp}, Time={$timestamp}");
            $this->sendSuccess($result['message'], [
                'authenticated_at' => gmdate('Y-m-d H:i:s'),
                'authenticated_at_utc' => gmdate('Y-m-d H:i:s'),
                'expires_at' => gmdate('Y-m-d H:i:s', $timestamp + $this->config['time_window']),
                'client_id' => $clientId,
                'client_ip' => $clientIp
            ]);
        } else {
            $this->log("認證失敗: Client={$clientId}, IP={$clientIp}, Reason={$result['message']}");
            $this->sendError($result['message'], 401);
        }
    }

    /**
     * 認證邏輯
     */
    private function authenticate($clientId, $timestamp, $signature, $data = '')
    {
        // 1. 驗證時間戳格式
        if (!is_numeric($timestamp) || $timestamp <= 0) {
            return ['success' => false, 'message' => '無效的時間戳格式'];
        }

        // 2. 檢查時間是否在有效窗口內
        if (!$this->isTimeValid($timestamp)) {
            $currentTime = time();
            $diff = abs($currentTime - $timestamp);
            return [
                'success' => false,
                'message' => "時間戳已過期或無效（差異: {$diff} 秒）"
            ];
        }

        // 3. 生成服務端簽名
        $expectedSignature = $this->generateSignature($clientId, $timestamp, $data);

        // 4. 使用時間安全比較防止時序攻擊
        if (!hash_equals($expectedSignature, $signature)) {
            return ['success' => false, 'message' => '簽名驗證失敗'];
        }

        return ['success' => true, 'message' => '認證成功'];
    }

    /**
     * 檢查時間戳是否在有效窗口內
     */
    private function isTimeValid($timestamp)
    {
        $currentTime = time();
        $timeWindow = $this->config['time_window'];
        $tolerance = $this->config['tolerance'];

        // 計算時間槽
        $currentSlot = floor($currentTime / $timeWindow);
        $requestSlot = floor($timestamp / $timeWindow);

        // 允許當前時間槽以及前後 tolerance 個時間槽
        $slotDiff = abs($currentSlot - $requestSlot);

        return $slotDiff <= $tolerance;
    }

    /**
     * 生成 HMAC 簽名
     */
    private function generateSignature($clientId, $timestamp, $data = '')
    {
        $timeWindow = $this->config['time_window'];
        $timeSlot = floor($timestamp / $timeWindow);

        // 組合要簽名的訊息
        $message = $clientId . '|' . $timeSlot . '|' . $data;

        // 使用 HMAC 生成簽名
        return hash_hmac(
            $this->config['hash_algorithm'],
            $message,
            $this->config['shared_secret']
        );
    }

    /**
     * 發送成功響應
     */
    private function sendSuccess($message, $data = [])
    {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 發送錯誤響應
     */
    private function sendError($message, $code = 400)
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 記錄日誌
     */
    private function log($message)
    {
        if (!$this->config['enable_logging']) {
            return;
        }

        // 使用 UTC 時間記錄日誌
        $timestamp = gmdate('Y-m-d H:i:s');
        $logMessage = "[{$timestamp} UTC] {$message}\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    /**
     * 獲取客戶端 IP 地址
     */
    private function getClientIp()
    {
        // 按優先順序檢查不同的 IP 來源
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // 代理伺服器
            'HTTP_X_REAL_IP',        // Nginx 代理
            'REMOTE_ADDR'            // 直接連接
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // 如果是 X-Forwarded-For，可能包含多個 IP，取第一個
                if ($header === 'HTTP_X_FORWARDED_FOR') {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // 驗證 IP 格式
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * 檢查 IP 是否在白名單中
     */
    private function isIpAllowed($ip)
    {
        if (!$this->config['enable_ip_whitelist']) {
            return true;
        }

        $allowedIps = $this->config['allowed_ips'] ?? [];

        // 如果白名單為空，允許所有 IP
        if (empty($allowedIps)) {
            return true;
        }

        foreach ($allowedIps as $allowedIp) {
            // 檢查是否為 CIDR 範圍
            if (strpos($allowedIp, '/') !== false) {
                if ($this->ipInCidr($ip, $allowedIp)) {
                    return true;
                }
            } else {
                // 直接比對 IP
                if ($ip === $allowedIp) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 檢查 IP 是否在 CIDR 範圍內
     */
    private function ipInCidr($ip, $cidr)
    {
        list($subnet, $mask) = explode('/', $cidr);

        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - (int)$mask);
        $subnet_long &= $mask_long;

        return ($ip_long & $mask_long) === $subnet_long;
    }

    /**
     * 生成驗證令牌（用於測試或發放給客戶端）
     */
    public function generateToken($clientId, $data = '')
    {
        $timestamp = time();
        $signature = $this->generateSignature($clientId, $timestamp, $data);

        return [
            'client_id' => $clientId,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'data' => $data,
            'expires_at' => $timestamp + $this->config['time_window']
        ];
    }
}

// 如果直接訪問此檔案，處理認證請求
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    $server = new AuthenticationServer();
    $server->handleRequest();
}
