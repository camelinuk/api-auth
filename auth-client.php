<?php
/**
 * Time-Based Authentication Client
 * 時間基礎認證客戶端
 *
 * 用於生成時間戳簽名並向伺服器發送認證請求
 */

class AuthenticationClient
{
    private $config;
    private $clientId;

    public function __construct($clientId, $configPath = 'auth-config.php')
    {
        $this->config = require $configPath;
        $this->clientId = $clientId;

        // 設定時區為 UTC
        date_default_timezone_set($this->config['timezone']);
    }

    /**
     * 向伺服器發送認證請求
     */
    public function authenticate($data = '', $customEndpoint = null)
    {
        $token = $this->generateToken($data);
        $endpoint = $customEndpoint ?? $this->config['api_endpoint'];

        // 準備請求數據
        $requestData = [
            'client_id' => $token['client_id'],
            'timestamp' => $token['timestamp'],
            'signature' => $token['signature'],
            'data' => $token['data']
        ];

        // 發送 HTTP POST 請求
        $response = $this->sendRequest($endpoint, $requestData);

        return $response;
    }

    /**
     * 生成認證令牌
     */
    public function generateToken($data = '')
    {
        // 使用 UTC 時間
        $timestamp = time();
        $signature = $this->generateSignature($this->clientId, $timestamp, $data);

        return [
            'client_id' => $this->clientId,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'data' => $data,
            'expires_at' => $timestamp + $this->config['time_window'],
            'utc_time' => gmdate('Y-m-d H:i:s', $timestamp)
        ];
    }

    /**
     * 生成 HMAC 簽名（與伺服器端邏輯相同）
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
     * 驗證伺服器響應的簽名
     */
    public function verifyServerResponse($responseData, $serverIp = null)
    {
        if (!isset($responseData['timestamp']) || !isset($responseData['signature'])) {
            return false;
        }

        // 檢查伺服器 IP 是否在白名單中
        if ($serverIp !== null && !$this->isIpAllowed($serverIp, 'server_allowed_ips')) {
            error_log("伺服器 IP 驗證失敗: {$serverIp}");
            return false;
        }

        $expectedSignature = $this->generateSignature(
            'server',
            $responseData['timestamp'],
            json_encode($responseData['data'] ?? '')
        );

        return hash_equals($expectedSignature, $responseData['signature']);
    }

    /**
     * 檢查 IP 是否在白名單中
     */
    private function isIpAllowed($ip, $configKey = 'allowed_ips')
    {
        if (!$this->config['enable_ip_whitelist']) {
            return true;
        }

        $allowedIps = $this->config[$configKey] ?? [];

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
     * 發送 HTTP 請求
     */
    private function sendRequest($url, $data)
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'error' => 'cURL 錯誤: ' . $error,
                'http_code' => $httpCode
            ];
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'JSON 解析錯誤: ' . json_last_error_msg(),
                'http_code' => $httpCode,
                'raw_response' => $response
            ];
        }

        $result['http_code'] = $httpCode;
        return $result;
    }

    /**
     * 獲取當前令牌資訊（用於調試）
     */
    public function getTokenInfo($data = '')
    {
        $token = $this->generateToken($data);
        $currentTime = time();

        return [
            'client_id' => $token['client_id'],
            'timestamp' => $token['timestamp'],
            'signature' => $token['signature'],
            'data' => $token['data'],
            'expires_at' => date('Y-m-d H:i:s', $token['expires_at']),
            'time_slot' => floor($token['timestamp'] / $this->config['time_window']),
            'is_valid' => $token['expires_at'] > $currentTime,
            'remaining_seconds' => max(0, $token['expires_at'] - $currentTime)
        ];
    }

    /**
     * 檢查令牌是否仍然有效
     */
    public function isTokenValid($timestamp)
    {
        $currentTime = time();
        $timeWindow = $this->config['time_window'];
        $tolerance = $this->config['tolerance'];

        $currentSlot = floor($currentTime / $timeWindow);
        $tokenSlot = floor($timestamp / $timeWindow);

        $slotDiff = abs($currentSlot - $tokenSlot);

        return $slotDiff <= $tolerance;
    }

    /**
     * 獲取配置資訊
     */
    public function getConfig()
    {
        return [
            'time_window' => $this->config['time_window'],
            'time_window_minutes' => $this->config['time_window'] / 60,
            'tolerance' => $this->config['tolerance'],
            'hash_algorithm' => $this->config['hash_algorithm'],
            'api_endpoint' => $this->config['api_endpoint'],
            'timezone' => $this->config['timezone'],
            'enable_ip_whitelist' => $this->config['enable_ip_whitelist'],
            'current_utc_time' => gmdate('Y-m-d H:i:s')
        ];
    }

    /**
     * 獲取當前 UTC 時間戳
     */
    public function getUtcTimestamp()
    {
        return time();
    }

    /**
     * 獲取當前 UTC 時間字串
     */
    public function getUtcTimeString()
    {
        return gmdate('Y-m-d H:i:s');
    }
}
