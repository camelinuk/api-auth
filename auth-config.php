<?php
/**
 * Authentication Configuration
 * 時間基礎認證配置檔
 */

return [
    // 共享密鑰 - 請務必更改為您自己的隨機密鑰
    'shared_secret' => 'YOUR_RANDOM_SECRET_KEY_CHANGE_THIS_IN_PRODUCTION',

    // 時間窗口（秒）- 10分鐘 = 600秒
    'time_window' => 600,

    // 允許的時間偏移（允許前後各一個時間窗口，防止時鐘不同步）
    'tolerance' => 1,

    // 使用的雜湊演算法
    'hash_algorithm' => 'sha256',

    // API 端點
    'api_endpoint' => 'http://localhost/auth-server.php',

    // 啟用日誌記錄
    'enable_logging' => true,

    // 日誌檔案路徑
    'log_file' => __DIR__ . '/auth.log',

    // 時區設定 - 使用 UTC 時間
    'timezone' => 'UTC',

    // 啟用 IP 白名單驗證
    'enable_ip_whitelist' => true,

    // IP 白名單 - 允許的客戶端 IP 列表
    // 空陣列表示允許所有 IP
    'allowed_ips' => [
        '127.0.0.1',      // 本地主機
        '::1',            // IPv6 本地主機
        // '192.168.1.100', // 範例：內網 IP
        // '203.0.113.0/24', // 範例：CIDR 範圍
    ],

    // 伺服器端 IP 白名單 - 用於客戶端驗證伺服器回應時檢查
    'server_allowed_ips' => [
        '127.0.0.1',
        '::1',
        // 添加您的伺服器 IP
    ]
];
