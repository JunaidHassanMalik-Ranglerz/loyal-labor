<?php

/**
 * PayFast Pakistan (Premier PayFast / apps.net.pk) — SANDBOX by default.
 * Docs: https://gopayfast.com/docs
 *
 * Never commit real credentials. Use .env only.
 */
return [
    'mode' => env('PAYFAST_MODE', 'sandbox'), // sandbox | live

    'merchant_id' => env('PAYFAST_MERCHANT_ID'),
    'merchant_name' => env('PAYFAST_MERCHANT_NAME', 'LoyLabor'),
    'secured_key' => env('PAYFAST_SECURED_KEY'),
    'grant_type' => env('PAYFAST_GRANT_TYPE', 'client_credentials'),

    // Official UAT (sandbox) ecommerce endpoints used by PayFast Pakistan merchants.
    'sandbox' => [
        'token_url' => env(
            'PAYFAST_SANDBOX_TOKEN_URL',
            'https://ipguat.apps.net.pk/Ecommerce/api/Transaction/GetAccessToken'
        ),
        'checkout_url' => env(
            'PAYFAST_SANDBOX_CHECKOUT_URL',
            'https://ipguat.apps.net.pk/Ecommerce/api/Transaction/PostTransaction'
        ),
        // REST status base (same UAT host family as token API).
        'api_base_url' => env(
            'PAYFAST_SANDBOX_API_BASE_URL',
            'https://ipguat.apps.net.pk/Ecommerce/api/Transaction'
        ),
    ],

    // Live endpoints — unused until explicitly authorized.
    'live' => [
        'token_url' => env(
            'PAYFAST_LIVE_TOKEN_URL',
            'https://ipg1.apps.net.pk/Ecommerce/api/Transaction/GetAccessToken'
        ),
        'checkout_url' => env(
            'PAYFAST_LIVE_CHECKOUT_URL',
            'https://ipg1.apps.net.pk/Ecommerce/api/Transaction/PostTransaction'
        ),
        'api_base_url' => env(
            'PAYFAST_LIVE_API_BASE_URL',
            'https://ipg1.apps.net.pk/Ecommerce/api/Transaction'
        ),
    ],

    'currency' => env('PAYFAST_CURRENCY', 'PKR'),
    'proccode' => env('PAYFAST_PROCCODE', '00'),
    'version' => env('PAYFAST_VERSION', 'LOYLABOR-RN-1.0'),

    // App-facing callback routes (configured in PayFast payload, not secrets).
    'success_path' => env('PAYFAST_SUCCESS_PATH', '/api/payments/payfast/return/success'),
    'failure_path' => env('PAYFAST_FAILURE_PATH', '/api/payments/payfast/return/failure'),
    // Prefer the ultra-fast public ack script (no full Laravel boot) so PayFast UAT
    // does not show "No response from server" while waiting on CHECKOUT_URL.
    // Legacy Laravel JSON route /api/payments/payfast/return/callback remains available.
    'ipn_path' => env('PAYFAST_IPN_PATH', '/payfast-checkout-ack.php'),

    // Mobile deep links after browser return (optional).
    'app_success_deep_link' => env('PAYFAST_APP_SUCCESS_DEEP_LINK', 'loylabor://payfast?status=success'),
    'app_failure_deep_link' => env('PAYFAST_APP_FAILURE_DEEP_LINK', 'loylabor://payfast?status=failure'),

    /**
     * Public HTTPS origin used to build WebView checkout / status URLs.
     * Must NOT be localhost on deployed servers. Example:
     * https://ranglerz.click/homeservices
     */
    'public_base_url' => env('PAYFAST_PUBLIC_BASE_URL', env('APP_URL')),

    // Reuse pending checkout only within this window; older ones are expired + new basket.
    'pending_reuse_minutes' => (int) env('PAYFAST_PENDING_REUSE_MINUTES', 10),
];
