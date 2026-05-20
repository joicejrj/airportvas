<?php

// Tell OpenSSL where its config file lives (WAMP fix)
putenv('OPENSSL_CONF=C:\wamp\bin\php\php8.2.18\extras\ssl\openssl.cnf');

require 'vendor/autoload.php';

$keys = Minishlink\WebPush\VAPID::createVapidKeys();
echo 'VAPID_PUBLIC_KEY:  ' . $keys['publicKey']  . PHP_EOL;
echo 'VAPID_PRIVATE_KEY: ' . $keys['privateKey'] . PHP_EOL;

?>