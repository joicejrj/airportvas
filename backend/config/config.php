<?php
// backend/config/config.php

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'airport_vas');
define('DB_USER', getenv('DB_USER') ?: 'sdfsdf');
define('DB_PASS', getenv('DB_PASS') ?: 'dsfsdfsd');

define('APP_SECRET', 'sddffGhr6544Hfgffdf');
define('SESSION_LIFETIME', 86400 * 7); // 7 days

// Assignment engine
define('ASSIGNMENT_ACCEPT_TIMEOUT_MINUTES', 5);
define('ASSIGNMENT_MAX_ATTEMPTS',            100);

// Sync engine
define('SYNC_MAX_BATCH_SIZE', 50);

// Push notifications (VAPID)
define('VAPID_PUBLIC_KEY',  getenv('VAPID_PUBLIC_KEY') ?: '');
define('VAPID_PRIVATE_KEY', getenv('VAPID_PRIVATE_KEY') ?: '');

// Upload paths
define('UPLOAD_DIR',   __DIR__ . '/../../uploads/');
define('UPLOAD_URL',   '/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5 MB

define('ALLOWED_ORIGINS', [
    'https://airportvas.jrjapp.com',
]);

define('STRIPE_SECRET_KEY',      'sk_test_51LsTYmSFDwAHdlKGGUYaaYlCV3b7k6LS3JwKuqDCvCo8JJAsGGyKMbBNXfR2HE7vgoJBL7zwqT3WmyjGRBUouqSx00jSWT6peF');
define('STRIPE_SITE_KEY',      'pk_test_51LsTYmSFDwAHdlKGNPscWbW6q3TeEkPfK5UGvhWylUvUYtikJFk3K8T5hNwI2CPecJpmZ6WW35dvebLnUbnyAwyc00eaCVGGKI');
define('STRIPE_WEBHOOK_SECRET',  'whsec_xxxxxxxxxxxxxxxxxx');
define('STRIPE_CURRENCY',        'aed');
define('PUBLIC_BASE_URL',        'https://airportvas.jrjapp.com');

// Payment gateway
define('PAYMENT_GATEWAY_URL',    getenv('PAYMENT_GATEWAY_URL')    ?: 'https://pay.example.com/checkout');
define('PAYMENT_WEBHOOK_SECRET', getenv('PAYMENT_WEBHOOK_SECRET') ?: 'CHANGE_ME_WEBHOOK_SECRET');

// Email (SMTP)
define('SMTP_HOST',     getenv('SMTP_HOST')     ?: 'smtp.example.com');
define('SMTP_PORT',     (int)(getenv('SMTP_PORT')     ?: 587));
define('SMTP_USER',     getenv('SMTP_USER')     ?: '');
define('SMTP_PASS',     getenv('SMTP_PASS')     ?: '');
define('SMTP_FROM',     getenv('SMTP_FROM')     ?: 'noreply@example.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Airport Parking');

// SMS (stub)
define('SMS_API_KEY',  getenv('SMS_API_KEY')  ?: '');
define('SMS_SENDER',   getenv('SMS_SENDER')   ?: 'AIRPARK');

// Timezone
date_default_timezone_set('UTC');
