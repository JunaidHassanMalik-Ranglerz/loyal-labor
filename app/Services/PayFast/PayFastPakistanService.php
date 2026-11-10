<?php

namespace App\Services\PayFast;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayFast Pakistan (gopayfast / apps.net.pk) sandbox client.
 * Official docs: https://gopayfast.com/docs
 */
class PayFastPakistanService
{
    public function mode(): string
    {
        $mode = strtolower((string) config('payfast.mode', 'sandbox'));

        return $mode === 'live' ? 'live' : 'sandbox';
    }

    public function isSandbox(): bool
    {
        return $this->mode() === 'sandbox';
    }

    public function assertSandboxOnly(): void
    {
        if (!$this->isSandbox()) {
            throw new RuntimeException('PayFast live mode is disabled. Use PAYFAST_MODE=sandbox only.');
        }
    }

    public function merchantId(): string
    {
        $id = trim((string) config('payfast.merchant_id'));
        if ($id === '') {
            throw new RuntimeException('PAYFAST_MERCHANT_ID is not configured.');
        }

        return $id;
    }

    public function merchantName(): string
    {
        return trim((string) config('payfast.merchant_name', 'LoyLabor')) ?: 'LoyLabor';
    }

    public function securedKey(): string
    {
        $key = trim((string) config('payfast.secured_key'));
        if ($key === '') {
            throw new RuntimeException('PAYFAST_SECURED_KEY is not configured.');
        }

        return $key;
    }

    public function tokenUrl(): string
    {
        return (string) config('payfast.' . $this->mode() . '.token_url');
    }

    public function checkoutUrl(): string
    {
        return (string) config('payfast.' . $this->mode() . '.checkout_url');
    }

    public function apiBaseUrl(): string
    {
        return rtrim((string) config('payfast.' . $this->mode() . '.api_base_url'), '/');
    }

    /**
     * Signature used by PayFast Pakistan hosted checkout integrations:
     * md5(merchant_id:merchant_name:amount:basket_id)
     */
    public function makeSignature(string $amount, string $basketId): string
    {
        $payload = $this->merchantId() . ':' . $this->merchantName() . ':' . $amount . ':' . $basketId;

        return md5($payload);
    }

    /**
     * POST /GetAccessToken (sandbox) or configured token URL.
     *
     * Hosted Checkout (Merchant Integration Guide) requires the token to be
     * bound to the same BASKET_ID + TXNAMT (+ CURRENCY_CODE) used in PostTransaction.
     * Legacy callers may omit basket/amount; prefer always binding for checkout.
     *
     * @param  array{basket_id?:string,amount?:float|string,currency?:string,customer_ip?:string}  $context
     */
    public function getAccessToken(?string $customerIp = null, array $context = []): array
    {
        $this->assertSandboxOnly();

        $customerIp = $customerIp
            ?: ($context['customer_ip'] ?? null)
            ?: request()->ip()
            ?: '127.0.0.1';

        $payload = [
            'merchant_id' => $this->merchantId(),
            'secured_key' => $this->securedKey(),
            'grant_type' => config('payfast.grant_type', 'client_credentials'),
            'customer_ip' => $customerIp,
        ];

        // Hosted-checkout binding (required by Merchant Integration Guide 2.x).
        $basketId = trim((string) ($context['basket_id'] ?? ''));
        if ($basketId !== '') {
            $payload['basket_id'] = $basketId;
            $payload['BASKET_ID'] = $basketId;
        }

        if (array_key_exists('amount', $context) && $context['amount'] !== null && $context['amount'] !== '') {
            $amount = number_format((float) $context['amount'], 2, '.', '');
            $payload['txnamt'] = $amount;
            $payload['TXNAMT'] = $amount;
        }

        $currency = trim((string) ($context['currency'] ?? config('payfast.currency', 'PKR')));
        if ($currency !== '') {
            $payload['currency_code'] = $currency;
            $payload['CURRENCY_CODE'] = $currency;
        }

        $response = Http::asForm()
            ->timeout(30)
            ->acceptJson()
            ->withHeaders([
                // PayFast rejects empty user agents on some UAT edges.
                'User-Agent' => 'LoyLabor-PayFast/1.0',
            ])
            ->post($this->tokenUrl(), $payload);

        $body = $response->json() ?? [];

        if (!$response->successful()) {
            Log::warning('PayFast token HTTP failure', [
                'status' => $response->status(),
                'basket_bound' => $basketId !== '',
                // Never log secured_key / raw secrets.
            ]);
            throw new RuntimeException('Unable to authenticate with PayFast sandbox.');
        }

        $token = $body['token']
            ?? $body['access_token']
            ?? data_get($body, 'data.token')
            ?? data_get($body, 'ACCESS_TOKEN');

        if (!$token) {
            Log::warning('PayFast token missing in response', [
                'keys' => array_keys(is_array($body) ? $body : []),
                'basket_bound' => $basketId !== '',
            ]);
            throw new RuntimeException('PayFast sandbox did not return an access token.');
        }

        return [
            'token' => (string) $token,
            'refresh_token' => $body['refresh_token'] ?? null,
            'expiry' => $body['expiry'] ?? ($body['expires_in'] ?? null),
            'basket_id' => $basketId !== '' ? $basketId : null,
            'raw' => $this->redactSecrets($body),
        ];
    }

    /**
     * Hosted checkout form fields for PostTransaction (mobile WebView / browser POST).
     */
    public function buildCheckoutFields(array $input): array
    {
        $this->assertSandboxOnly();

        $amount = number_format((float) $input['amount'], 2, '.', '');
        $basketId = (string) $input['basket_id'];
        $token = (string) $input['access_token'];
        $signature = $this->makeSignature($amount, $basketId);

        // PayFast posts/redirects to these URLs after "Make Payment".
        // They MUST be public HTTPS — never localhost — or UAT shows "No response from server".
        $successUrl = (string) ($input['success_url'] ?? $this->publicCallbackUrl((string) config('payfast.success_path')));
        $failureUrl = (string) ($input['failure_url'] ?? $this->publicCallbackUrl((string) config('payfast.failure_path')));
        $ipnUrl = (string) ($input['ipn_url'] ?? $this->publicCallbackUrl((string) config('payfast.ipn_path')));

        return [
            'MERCHANT_ID' => $this->merchantId(),
            'MERCHANT_NAME' => $this->merchantName(),
            'TOKEN' => $token,
            'PROCCODE' => (string) config('payfast.proccode', '00'),
            'TXNAMT' => $amount,
            'CUSTOMER_MOBILE_NO' => (string) ($input['customer_mobile'] ?? ''),
            'CUSTOMER_EMAIL_ADDRESS' => (string) ($input['customer_email'] ?? ''),
            'SIGNATURE' => $signature,
            'VERSION' => (string) config('payfast.version', 'LOYLABOR-RN-1.0'),
            'TXNDESC' => (string) ($input['description'] ?? ('Subscription payment - ' . $this->merchantName())),
            'SUCCESS_URL' => $successUrl,
            'FAILURE_URL' => $failureUrl,
            'BASKET_ID' => $basketId,
            'ORDER_DATE' => (string) ($input['order_date'] ?? now()->format('Y-m-d H:i:s')),
            'CHECKOUT_URL' => $ipnUrl,
            'CURRENCY_CODE' => (string) config('payfast.currency', 'PKR'),
        ];
    }

    /**
     * Public HTTPS merchant callback URL (SUCCESS / FAILURE / IPN).
     */
    public function publicCallbackUrl(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $candidates = [
            (string) config('payfast.public_base_url'),
            (string) config('app.url'),
        ];

        $base = '';
        foreach ($candidates as $candidate) {
            $candidate = rtrim(trim($candidate), '/');
            if ($candidate === '' || str_contains(strtolower($candidate), 'localhost') || str_contains($candidate, '127.0.0.1')) {
                continue;
            }
            $base = $candidate;
            break;
        }

        if ($base === '') {
            $base = rtrim((string) url('/'), '/');
        }

        return $base . $path;
    }

    /**
     * Verify transaction status by basket/order id (authoritative).
     * GET {api_base}/basket_id/{basket_id}?order_date=...
     * Also tries GetTransactionStatus-style paths used on Ecommerce API hosts.
     */
    public function getStatusByBasketId(string $basketId, ?string $orderDate = null): array
    {
        $this->assertSandboxOnly();
        $access = $this->getAccessToken();
        $token = $access['token'];

        $paths = [
            $this->apiBaseUrl() . '/basket_id/' . rawurlencode($basketId),
            $this->apiBaseUrl() . '/GetTransactionStatusByBasketId/' . rawurlencode($basketId),
            rtrim(str_replace('/Transaction', '', $this->apiBaseUrl()), '/') . '/transaction/basket_id/' . rawurlencode($basketId),
        ];

        $lastError = null;
        foreach ($paths as $url) {
            try {
                $request = Http::withToken($token)
                    ->acceptJson()
                    ->timeout(30);

                $query = [];
                if ($orderDate) {
                    $query['order_date'] = $orderDate;
                }

                $response = $query
                    ? $request->get($url, $query)
                    : $request->get($url);

                $body = $response->json() ?? ['raw' => $response->body()];

                if ($response->successful()) {
                    return [
                        'ok' => true,
                        'http_status' => $response->status(),
                        'body' => $this->redactSecrets(is_array($body) ? $body : ['body' => $body]),
                        'normalized' => $this->normalizeStatusPayload(is_array($body) ? $body : []),
                    ];
                }

                $lastError = 'HTTP ' . $response->status();
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        return [
            'ok' => false,
            'error' => $lastError ?: 'Unable to fetch PayFast transaction status.',
            'normalized' => [
                'success' => false,
                'transaction_id' => null,
                'status_code' => null,
                'status_msg' => $lastError,
            ],
        ];
    }

    public function normalizeStatusPayload(array $body): array
    {
        $statusCode = (string) (
            $body['status_code']
            ?? $body['code']
            ?? data_get($body, 'data.status_code')
            ?? data_get($body, 'transaction_status')
            ?? ''
        );

        $statusMsg = (string) (
            $body['status_msg']
            ?? $body['message']
            ?? data_get($body, 'data.status_msg')
            ?? ''
        );

        $transactionId = $body['transaction_id']
            ?? data_get($body, 'data.transaction_id')
            ?? $body['Transaction_id']
            ?? null;

        $basketId = $body['basket_id']
            ?? data_get($body, 'data.basket_id')
            ?? null;

        // PayFast success commonly uses "00" / "000" / "success".
        $success = in_array(strtolower($statusCode), ['00', '000', '0', 'success', 'completed', 'paid'], true)
            || str_contains(strtolower($statusMsg), 'success');

        return [
            'success' => $success,
            'status_code' => $statusCode !== '' ? $statusCode : null,
            'status_msg' => $statusMsg !== '' ? $statusMsg : null,
            'transaction_id' => $transactionId ? (string) $transactionId : null,
            'basket_id' => $basketId ? (string) $basketId : null,
        ];
    }

    /**
     * Interpret browser return / IPN payload without trusting it alone.
     */
    public function extractBasketIdFromCallback(array $payload): ?string
    {
        foreach (['BASKET_ID', 'basket_id', 'order_id', 'ORDER_ID', 'basketId'] as $key) {
            if (!empty($payload[$key])) {
                return (string) $payload[$key];
            }
        }

        return null;
    }

    public function extractTransactionIdFromCallback(array $payload): ?string
    {
        foreach (['transaction_id', 'TRANSACTION_ID', 'err_msg', 'Transaction_id'] as $key) {
            if (!empty($payload[$key]) && $key !== 'err_msg') {
                return (string) $payload[$key];
            }
        }

        return null;
    }

    private function redactSecrets(array $data): array
    {
        $blocked = ['secured_key', 'SECURED_KEY', 'token', 'TOKEN', 'access_token', 'refresh_token', 'Authorization'];
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $blocked, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redactSecrets($value);
            }
        }

        return $data;
    }
}
