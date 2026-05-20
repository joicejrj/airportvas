<?php
// backend/config/config.php

declare(strict_types=1);

// ─── Environment ─────────────────────────────────────────────────────
define('APP_ENV',  getenv('APP_ENV')  ?: 'development');
define('APP_DEBUG', APP_ENV !== 'production');
define('APP_URL',  getenv('APP_URL')  ?: 'https://airportvas.jrjapp.com/');
define('APP_NAME', 'Airport VAS');
define('APP_CURRENCY', 'AED');
define('APP_TIMEZONE', 'Asia/Dubai');

// ─── Database ────────────────────────────────────────────────────────
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'airport_vas');
define('DB_USER', getenv('DB_USER') ?: 'airport_vas_user');
define('DB_PASS', getenv('DB_PASS') ?: 'b2JczD@8zx*Wh5cp');

// ─── Auth ────────────────────────────────────────────────────────────
define('SESSION_LIFETIME', 60 * 60 * 24 * 7);   // 7 days, in seconds
define('PASSWORD_COST', 12);

define('STRIPE_SECRET_KEY',      'sk_test_51LsTYmSFDwAHdlKGGUYaaYlCV3b7k6LS3JwKuqDCvCo8JJAsGGyKMbBNXfR2HE7vgoJBL7zwqT3WmyjGRBUouqSx00jSWT6peF');
define('STRIPE_SITE_KEY',      'pk_test_51LsTYmSFDwAHdlKGNPscWbW6q3TeEkPfK5UGvhWylUvUYtikJFk3K8T5hNwI2CPecJpmZ6WW35dvebLnUbnyAwyc00eaCVGGKI');
define('STRIPE_WEBHOOK_SECRET',  'whsec_xxxxxxxxxxxxxxxxxx');
define('STRIPE_CURRENCY',        'aed');
define('PUBLIC_BASE_URL',        'https://airportvas.jrjapp.com');

// ─── Uploads ─────────────────────────────────────────────────────────
define('UPLOAD_DIR',  __DIR__ . '/../../uploads');
define('UPLOAD_URL',  '/uploads');
define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);   // 5 MB

// ─── Mail (SMTP) ─────────────────────────────────────────────────────
// Used by Mailer.php for cash-handover notifications.
define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: 'noreply@airportvas.ae');
define('MAIL_FROM_NAME',  getenv('MAIL_FROM_NAME')  ?: 'Airport VAS');

// Comma-separated list of admin emails to notify on handover submission.
define('HANDOVER_NOTIFY_EMAILS', getenv('HANDOVER_NOTIFY_EMAILS') ?: 'admin@airportvas.ae');

// SMTP credentials — set these via env vars in production
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls'); // 'tls' or 'ssl' or ''

// ─── Public-link tokens (handover confirm) ───────────────────────────
define('HANDOVER_TOKEN_TTL_DAYS', 7);  // public confirm link valid for 7 days

// ─── Business rules ──────────────────────────────────────────────────
define('MIN_HANDOVER_AMOUNT', 0.01);    // refuse zero-amount submissions
define('UAE_PHONE_REGEX', '/^\+971[0-9]{8,9}$/');
define('UAE_PLATE_REGEX', '/^[A-Z0-9 ]{2,15}$/');
define('EMIRATES_ID_REGEX', '/^[0-9]{3}-?[0-9]{4}-?[0-9]{7}-?[0-9]{1}$/');

// ─── CORS (loosen as needed for dev) ─────────────────────────────────
define('CORS_ALLOWED_ORIGINS', '*');
define('ALLOWED_ORIGINS', CORS_ALLOWED_ORIGINS);
