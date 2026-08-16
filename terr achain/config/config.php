<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'TerraChain',
        'env' => getenv('TC_ENV') ?: 'development',
        'debug' => (getenv('TC_ENV') ?: 'development') === 'development',
        'base_url' => 'http://localhost:8080',
        'timezone' => 'Africa/Addis_Ababa',
    ],
    'database' => [
        'host' => getenv('TC_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('TC_DB_PORT') ?: '33306',
        'name' => getenv('TC_DB_NAME') ?: 'terrachain',
        'user' => getenv('TC_DB_USER') ?: 'terrachain',
        'password' => getenv('TC_DB_PASS') ?: 'terrachain_local_dev',
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name' => 'TERRACHAIN_SESS',
        'timeout_minutes' => 60,
        'cookie_secure' => false,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],
    'security' => [
        'password_min_length' => 8,
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
        'csrf_token_name' => '_csrf',
        'upload_max_bytes' => 10 * 1024 * 1024,
        'allowed_upload_extensions' => ['pdf','doc','docx','xls','xlsx','txt','jpg','jpeg','png','tif','tiff'],
        'allowed_upload_mimes' => ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','text/plain','image/jpeg','image/png','image/tiff'],
    ],
    'integrity' => [
        'chains' => ['land_records', 'documents', 'tenders', 'bids', 'contracts', 'chats', 'integrations', 'audit'],
        'c_bin' => TERRACHAIN_ROOT . '/c/bin',
    ],
];
