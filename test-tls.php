<?php
require 'vendor/autoload.php';

try {
    $client = new \RouterOS\Client([
        'host' => 'hamnet-chr',
        'user' => 'admin',
        'pass' => 'admin',
        'port' => 8729,
        'ssl' => true,
        'ssl_options' => [
            'cafile' => '/var/www/html/storage/app/certs/chr-ca.crt',
            'verify_peer' => true,
            'verify_peer_name' => false
        ]
    ]);
    
    $client->query('/system/resource/print');
    echo "SUCCESS! TLS VERIFIED.\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}