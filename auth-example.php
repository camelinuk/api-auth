<?php
/**
 * Authentication System Usage Example
 * 認證系統使用範例
 */

require_once 'auth-client.php';
require_once 'auth-server.php';

echo "=== 時間基礎認證系統示範 (UTC 時間 + IP 驗證) ===\n\n";

// ========================================
// 範例 1: 客戶端生成令牌
// ========================================
echo "【範例 1】客戶端生成認證令牌\n";
echo str_repeat("-", 50) . "\n";

$client = new AuthenticationClient('client-001');

// 生成令牌
$token = $client->generateToken('訂單資料: {"order_id": 12345}');

echo "客戶端 ID: {$token['client_id']}\n";
echo "時間戳: {$token['timestamp']}\n";
echo "UTC 時間: {$token['utc_time']}\n";
echo "簽名: {$token['signature']}\n";
echo "過期時間: " . gmdate('Y-m-d H:i:s', $token['expires_at']) . " UTC\n";
echo "數據: {$token['data']}\n\n";

// ========================================
// 範例 2: 本地驗證（不發送 HTTP 請求）
// ========================================
echo "【範例 2】本地驗證簽名\n";
echo str_repeat("-", 50) . "\n";

$server = new AuthenticationServer();

// 模擬伺服器驗證過程
$authMethod = new ReflectionMethod('AuthenticationServer', 'authenticate');
$authMethod->setAccessible(true);

$result = $authMethod->invoke(
    $server,
    $token['client_id'],
    $token['timestamp'],
    $token['signature'],
    $token['data']
);

if ($result['success']) {
    echo "✓ 驗證成功: {$result['message']}\n\n";
} else {
    echo "✗ 驗證失敗: {$result['message']}\n\n";
}

// ========================================
// 範例 3: 查看令牌詳細資訊
// ========================================
echo "【範例 3】令牌詳細資訊\n";
echo str_repeat("-", 50) . "\n";

$tokenInfo = $client->getTokenInfo('測試數據');

echo "客戶端 ID: {$tokenInfo['client_id']}\n";
echo "時間槽: {$tokenInfo['time_slot']}\n";
echo "過期時間: {$tokenInfo['expires_at']}\n";
echo "是否有效: " . ($tokenInfo['is_valid'] ? '是' : '否') . "\n";
echo "剩餘秒數: {$tokenInfo['remaining_seconds']} 秒\n\n";

// ========================================
// 範例 4: 測試過期令牌
// ========================================
echo "【範例 4】測試過期令牌\n";
echo str_repeat("-", 50) . "\n";

// 模擬 15 分鐘前的時間戳（超過 10 分鐘有效期）
$expiredTimestamp = time() - (15 * 60);

// 生成過期的簽名
$signatureMethod = new ReflectionMethod('AuthenticationClient', 'generateSignature');
$signatureMethod->setAccessible(true);
$expiredSignature = $signatureMethod->invoke($client, 'client-001', $expiredTimestamp, '');

// 嘗試驗證
$result = $authMethod->invoke(
    $server,
    'client-001',
    $expiredTimestamp,
    $expiredSignature,
    ''
);

if ($result['success']) {
    echo "✓ 驗證成功（不應該發生）\n\n";
} else {
    echo "✗ 驗證失敗（預期結果）: {$result['message']}\n\n";
}

// ========================================
// 範例 5: 測試錯誤簽名
// ========================================
echo "【範例 5】測試錯誤簽名\n";
echo str_repeat("-", 50) . "\n";

$invalidSignature = 'invalid_signature_' . md5(rand());

$result = $authMethod->invoke(
    $server,
    'client-001',
    time(),
    $invalidSignature,
    ''
);

if ($result['success']) {
    echo "✓ 驗證成功（不應該發生）\n\n";
} else {
    echo "✗ 驗證失敗（預期結果）: {$result['message']}\n\n";
}

// ========================================
// 範例 6: 查看配置資訊
// ========================================
echo "【範例 6】系統配置資訊\n";
echo str_repeat("-", 50) . "\n";

$config = $client->getConfig();

echo "時間窗口: {$config['time_window']} 秒 ({$config['time_window_minutes']} 分鐘)\n";
echo "容錯範圍: {$config['tolerance']} 個時間槽\n";
echo "雜湊演算法: {$config['hash_algorithm']}\n";
echo "API 端點: {$config['api_endpoint']}\n";
echo "時區設定: {$config['timezone']}\n";
echo "當前 UTC 時間: {$config['current_utc_time']}\n";
echo "IP 白名單驗證: " . ($config['enable_ip_whitelist'] ? '啟用' : '停用') . "\n\n";

// ========================================
// 範例 7: 客戶端發送 HTTP 請求（需要伺服器運行）
// ========================================
echo "【範例 7】發送 HTTP 認證請求\n";
echo str_repeat("-", 50) . "\n";
echo "注意: 此範例需要 auth-server.php 在 Web 伺服器上運行\n";
echo "如需測試，請啟動 PHP 內建伺服器:\n";
echo "  php -S localhost:8000\n";
echo "然後修改 auth-config.php 中的 api_endpoint 為:\n";
echo "  'api_endpoint' => 'http://localhost:8000/auth-server.php'\n\n";

// 取消註解以下代碼進行實際 HTTP 測試
/*
$response = $client->authenticate('實際訂單數據');

if ($response['success']) {
    echo "✓ HTTP 認證成功!\n";
    echo "認證時間: {$response['data']['authenticated_at']}\n";
    echo "過期時間: {$response['data']['expires_at']}\n";
} else {
    echo "✗ HTTP 認證失敗: {$response['error']}\n";
    echo "HTTP 狀態碼: {$response['http_code']}\n";
}
*/

// ========================================
// 範例 8: UTC 時間功能測試
// ========================================
echo "【範例 8】UTC 時間功能\n";
echo str_repeat("-", 50) . "\n";

echo "當前 UTC 時間戳: " . $client->getUtcTimestamp() . "\n";
echo "當前 UTC 時間: " . $client->getUtcTimeString() . "\n";
echo "本地時區: " . date_default_timezone_get() . "\n\n";

// ========================================
// 範例 9: IP 驗證說明
// ========================================
echo "【範例 9】IP 白名單驗證說明\n";
echo str_repeat("-", 50) . "\n";
echo "IP 白名單功能已整合到系統中：\n";
echo "- 在 auth-config.php 中設定 'enable_ip_whitelist' => true 啟用\n";
echo "- 在 'allowed_ips' 陣列中添加允許的客戶端 IP\n";
echo "- 支援單一 IP (例如: '192.168.1.100') 和 CIDR 範圍 (例如: '192.168.1.0/24')\n";
echo "- 伺服器端會自動驗證請求來源 IP\n";
echo "- 客戶端可驗證伺服器回應的 IP (使用 'server_allowed_ips')\n";
echo "- 如果 IP 不在白名單中，請求會被拒絕並返回 403 錯誤\n\n";

echo "\n=== 示範完成 ===\n";
