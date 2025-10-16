# 時間基礎加密認證系統

這是一個基於時間戳的 HMAC 加密認證機制，提供 10 分鐘有效期的安全認證，適用於 PHP 客戶端和伺服器之間的安全通訊。

## 功能特點

- **時間基礎認證**: 使用 10 分鐘時間窗口，防止重放攻擊
- **HMAC 簽名**: 使用 SHA-256 雜湊演算法確保訊息完整性
- **時間容錯**: 允許 ±1 個時間槽的偏移，處理時鐘不同步
- **防時序攻擊**: 使用 `hash_equals()` 進行時間安全比較
- **簡單易用**: 清晰的 API 設計，易於整合

## 檔案結構

```
api-auth/
├── auth-config.php      # 配置檔案（共享密鑰、時間窗口等）
├── auth-server.php      # 伺服器端認證 API
├── auth-client.php      # 客戶端認證類別
├── auth-example.php     # 使用範例
└── AUTH_README.md       # 說明文件（本檔案）
```

## 快速開始

### 1. 配置系統

編輯 `auth-config.php`，設定您的共享密鑰：

```php
return [
    'shared_secret' => 'YOUR_RANDOM_SECRET_KEY_CHANGE_THIS_IN_PRODUCTION',
    'time_window' => 600,  // 10 分鐘
    'tolerance' => 1,
    'hash_algorithm' => 'sha256',
    'api_endpoint' => 'https://your-domain.com/auth-server.php'
];
```

**重要**: 請務必更改 `shared_secret` 為您自己的隨機密鑰！

### 2. 啟動伺服器

使用 PHP 內建伺服器進行測試：

```bash
php -S localhost:8000
```

或將 `auth-server.php` 部署到您的 Web 伺服器。

### 3. 客戶端使用範例

```php
<?php
require_once 'auth-client.php';

// 建立客戶端實例
$client = new AuthenticationClient('client-001');

// 生成認證令牌
$token = $client->generateToken('訂單資料');

// 發送認證請求到伺服器
$response = $client->authenticate('訂單資料');

if ($response['success']) {
    echo "認證成功！\n";
    echo "過期時間: {$response['data']['expires_at']}\n";
} else {
    echo "認證失敗: {$response['error']}\n";
}
```

### 4. 執行範例

```bash
php auth-example.php
```

## 工作原理

### 認證流程

```
客戶端                                    伺服器
   |                                        |
   | 1. 生成時間戳 (timestamp)                |
   | 2. 計算時間槽 (timeSlot)                 |
   | 3. 組合訊息: clientId|timeSlot|data      |
   | 4. 生成 HMAC 簽名                        |
   |                                         |
   | 5. 發送: clientId, timestamp, signature  |
   |------------------------------------>    |
   |                                         |
   |                                    6. 驗證時間戳有效性
   |                                    7. 重新計算簽名
   |                                    8. 比較簽名
   |                                         |
   |         9. 返回認證結果                  |
   |<------------------------------------|    |
   |                                         |
```

### 簽名生成算法

```php
timeSlot = floor(timestamp / time_window)
message = clientId + '|' + timeSlot + '|' + data
signature = HMAC-SHA256(message, shared_secret)
```

### 時間窗口驗證

- **時間窗口**: 600 秒（10 分鐘）
- **容錯範圍**: ±1 個時間槽（允許前後各 10 分鐘）
- **總有效期**: 最多 30 分鐘（當前槽 + 前後各 1 槽）

## API 文件

### AuthenticationClient

#### 建構函式

```php
new AuthenticationClient($clientId, $configPath = 'auth-config.php')
```

#### 方法

- **`authenticate($data = '', $customEndpoint = null)`**
  發送認證請求到伺服器
  - 返回: `array` - 包含 success, message, data 等欄位

- **`generateToken($data = '')`**
  生成本地認證令牌
  - 返回: `array` - 包含 client_id, timestamp, signature 等

- **`getTokenInfo($data = '')`**
  獲取令牌詳細資訊（用於調試）
  - 返回: `array` - 包含過期時間、剩餘秒數等

- **`isTokenValid($timestamp)`**
  檢查時間戳是否仍在有效窗口內
  - 返回: `bool`

### AuthenticationServer

#### 方法

- **`handleRequest()`**
  處理 HTTP POST 認證請求（自動執行）

- **`generateToken($clientId, $data = '')`**
  伺服器端生成令牌（用於發放給客戶端）
  - 返回: `array`

## 安全考量

### 1. 共享密鑰管理

- 使用強隨機密鑰（建議至少 32 字元）
- 不要將密鑰提交到版本控制系統
- 定期輪換密鑰
- 使用環境變數或加密配置檔案存儲

```php
// 建議使用方式
$config['shared_secret'] = getenv('AUTH_SECRET_KEY');
```

### 2. HTTPS 傳輸

在生產環境中，務必使用 HTTPS 協議：

```php
'api_endpoint' => 'https://your-domain.com/auth-server.php'
```

### 3. 防止重放攻擊

系統通過以下機制防止重放攻擊：
- 時間窗口限制（10 分鐘）
- 簽名與時間槽綁定
- 可選：在伺服器端記錄已使用的簽名（nonce）

### 4. 時間同步

確保客戶端和伺服器時間同步：
- 使用 NTP 服務同步時間
- tolerance 參數允許小幅度偏移
- 建議使用 UTC 時間

## 進階使用

### 1. 添加 Nonce 防止重放

修改伺服器端驗證邏輯：

```php
private $usedSignatures = [];

private function authenticate($clientId, $timestamp, $signature, $data = '')
{
    // 檢查簽名是否已使用
    if (isset($this->usedSignatures[$signature])) {
        return ['success' => false, 'message' => '簽名已被使用'];
    }

    // 原有驗證邏輯...

    // 記錄簽名
    $this->usedSignatures[$signature] = time();

    // 清理過期簽名（可選）
    $this->cleanupExpiredSignatures();
}
```

### 2. 與現有 API 整合

```php
// 在您的 API 端點中
require_once 'auth-server.php';

function protectedApiEndpoint()
{
    $server = new AuthenticationServer();

    // 解析請求
    $input = json_decode(file_get_contents('php://input'), true);

    // 驗證認證
    $authMethod = new ReflectionMethod('AuthenticationServer', 'authenticate');
    $authMethod->setAccessible(true);
    $result = $authMethod->invoke(
        $server,
        $input['client_id'],
        $input['timestamp'],
        $input['signature'],
        $input['data']
    );

    if (!$result['success']) {
        http_response_code(401);
        die(json_encode(['error' => '認證失敗']));
    }

    // 執行您的 API 邏輯
    processApiRequest($input['data']);
}
```

### 3. 多客戶端管理

在配置檔案中定義多個客戶端：

```php
'clients' => [
    'client-001' => ['name' => '行動應用', 'enabled' => true],
    'client-002' => ['name' => 'Web 介面', 'enabled' => true],
    'client-003' => ['name' => '第三方 API', 'enabled' => false]
]
```

## 效能優化

### 1. 簽名快取

對於高頻請求，可以快取簽名驗證結果：

```php
private $signatureCache = [];

private function getCachedSignature($key)
{
    if (isset($this->signatureCache[$key])) {
        if ($this->signatureCache[$key]['expires'] > time()) {
            return $this->signatureCache[$key]['signature'];
        }
    }
    return null;
}
```

### 2. 資料庫存儲

對於生產環境，將日誌和已使用簽名存儲到資料庫：

```php
// 使用 Redis 存儲已使用簽名
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->setex($signature, 1800, 1); // 30 分鐘過期
```

## 故障排除

### 常見問題

**Q: 認證一直失敗，提示「時間戳已過期」**
A: 檢查客戶端和伺服器時間是否同步，使用 NTP 服務同步時間。

**Q: 簽名驗證失敗**
A: 確認客戶端和伺服器使用相同的 `shared_secret`，檢查配置檔案。

**Q: cURL 錯誤**
A: 確認伺服器 URL 正確，檢查防火牆和 SSL 證書設定。

### 啟用調試日誌

在 `auth-config.php` 中啟用日誌：

```php
'enable_logging' => true,
'log_file' => __DIR__ . '/auth.log'
```

查看日誌檔案：

```bash
tail -f auth.log
```

## 測試

執行完整測試：

```bash
php auth-example.php
```

輸出應顯示：
- ✓ 正常令牌驗證成功
- ✗ 過期令牌被拒絕
- ✗ 錯誤簽名被拒絕

## 授權

此代碼範例提供作為參考，請根據您的需求進行修改和使用。

## 技術支援

如有問題或建議，請參考：
- PHP 官方文件: https://www.php.net/manual/
- HMAC 標準: RFC 2104
- 時間基礎 OTP: RFC 6238
