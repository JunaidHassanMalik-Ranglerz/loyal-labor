<?php
/**
 * Ultra-fast PayFast CHECKOUT_URL acknowledgement.
 * Intentionally does NOT boot Laravel and does NOT touch MySQL on the request path.
 * PayFast UAT fails with "No response from server" when merchant ACK is slow.
 * Activation remains exclusive to POST /payments/{id}/verify.
 */
declare(strict_types=1);

http_response_code(200);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Connection: close');
header('Content-Length: 2');
echo 'OK';

if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

$payload = array_merge($_GET ?? [], $_POST ?? []);
$basket = (string) ($payload['BASKET_ID'] ?? $payload['basket_id'] ?? $payload['ORDER_ID'] ?? $payload['order_id'] ?? '');
$blocked = ['TOKEN', 'token', 'secured_key', 'SECURED_KEY', 'card_number', 'cvv', 'otp', 'password', 'Authorization'];
foreach ($blocked as $key) {
    unset($payload[$key]);
}

$raw = json_encode([
    'at' => gmdate('c'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'basket' => $basket !== '' ? $basket : null,
    'keys' => array_keys($payload),
    'payload' => $payload,
], JSON_UNESCAPED_SLASHES);

$logDir = __DIR__ . '/../storage/app/payfast';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
@file_put_contents($logDir . '/checkout_acks.jsonl', $raw . PHP_EOL, FILE_APPEND | LOCK_EX);

exit;
