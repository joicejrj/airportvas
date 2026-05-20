<?php
echo "PHP version: " . PHP_VERSION . "\n";
echo "OpenSSL loaded: " . (extension_loaded('openssl') ? 'yes' : 'no') . "\n";
echo "OPENSSL_VERSION_TEXT: " . OPENSSL_VERSION_TEXT . "\n";
echo "OPENSSL_CONF env: " . (getenv('OPENSSL_CONF') ?: '(not set)') . "\n";
echo "EC supported: " . (defined('OPENSSL_KEYTYPE_EC') ? 'yes' : 'no') . "\n";

// Try it directly
$key = @openssl_pkey_new([
    'curve_name'       => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);
echo "openssl_pkey_new returned: " . ($key === false ? 'FALSE' : 'success') . "\n";

// Drain the openssl error queue
while ($msg = openssl_error_string()) {
    echo "OpenSSL error: $msg\n";
}

require __DIR__ . '/backend/config/config.php';
echo "Public:  " . VAPID_PUBLIC_KEY . "\n";
echo "Private: " . VAPID_PRIVATE_KEY . "\n";
echo "Public length:  " . strlen(VAPID_PUBLIC_KEY)  . " (expected 87)\n";
echo "Private length: " . strlen(VAPID_PRIVATE_KEY) . " (expected 43)\n";