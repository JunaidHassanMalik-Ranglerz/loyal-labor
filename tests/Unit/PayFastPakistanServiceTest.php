<?php

namespace Tests\Unit;

use App\Services\PayFast\PayFastPakistanService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PayFastPakistanServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payfast.mode' => 'sandbox',
            'payfast.merchant_id' => '14833',
            'payfast.merchant_name' => 'LoyLabor',
            'payfast.secured_key' => 'test-secured-key',
            'payfast.grant_type' => 'client_credentials',
            'payfast.currency' => 'PKR',
            'payfast.proccode' => '00',
            'payfast.version' => 'LOYLABOR-RN-1.0',
            'payfast.sandbox.token_url' => 'https://ipguat.apps.net.pk/Ecommerce/api/Transaction/GetAccessToken',
            'payfast.sandbox.checkout_url' => 'https://ipguat.apps.net.pk/Ecommerce/api/Transaction/PostTransaction',
            'payfast.sandbox.api_base_url' => 'https://ipguat.apps.net.pk/Ecommerce/api/Transaction',
            'payfast.success_path' => '/api/payments/payfast/return/success',
            'payfast.failure_path' => '/api/payments/payfast/return/failure',
            'payfast.ipn_path' => '/payfast-checkout-ack.php',
        ]);
    }

    public function test_sandbox_mode_is_default_and_live_is_blocked(): void
    {
        $service = app(PayFastPakistanService::class);
        $this->assertTrue($service->isSandbox());
        $service->assertSandboxOnly();

        config(['payfast.mode' => 'live']);
        $this->expectException(RuntimeException::class);
        $service->assertSandboxOnly();
    }

    public function test_missing_secured_key_fails_closed(): void
    {
        config(['payfast.secured_key' => '']);
        $service = app(PayFastPakistanService::class);
        $this->expectException(RuntimeException::class);
        $service->securedKey();
    }

    public function test_missing_merchant_id_fails_closed(): void
    {
        config(['payfast.merchant_id' => '']);
        $service = app(PayFastPakistanService::class);
        $this->expectException(RuntimeException::class);
        $service->merchantId();
    }

    public function test_signature_matches_documented_formula(): void
    {
        $service = app(PayFastPakistanService::class);
        $expected = md5('14833:LoyLabor:570.00:LOY-TEST-1');
        $this->assertSame($expected, $service->makeSignature('570.00', 'LOY-TEST-1'));
    }

    public function test_checkout_fields_use_sandbox_host_and_hide_no_secured_key(): void
    {
        $service = app(PayFastPakistanService::class);
        $fields = $service->buildCheckoutFields([
            'amount' => 570,
            'basket_id' => 'LOY-TEST-1',
            'access_token' => 'access-token-value',
            'customer_mobile' => '923001234567',
            'customer_email' => 'tech@example.com',
            'description' => 'Test plan',
            'order_date' => '2026-11-09 12:00:00',
        ]);

        $this->assertSame('14833', $fields['MERCHANT_ID']);
        $this->assertSame('570.00', $fields['TXNAMT']);
        $this->assertSame('LOY-TEST-1', $fields['BASKET_ID']);
        $this->assertSame('access-token-value', $fields['TOKEN']);
        $this->assertArrayNotHasKey('SECURED_KEY', $fields);
        $this->assertSame(
            'https://ipguat.apps.net.pk/Ecommerce/api/Transaction/PostTransaction',
            $service->checkoutUrl()
        );
    }

    public function test_normalize_status_detects_success_and_failure(): void
    {
        $service = app(PayFastPakistanService::class);

        $ok = $service->normalizeStatusPayload([
            'status_code' => '00',
            'status_msg' => 'Success',
            'transaction_id' => 'PF-TXN-1',
            'basket_id' => 'LOY-TEST-1',
        ]);
        $this->assertTrue($ok['success']);
        $this->assertSame('PF-TXN-1', $ok['transaction_id']);

        $fail = $service->normalizeStatusPayload([
            'status_code' => '01',
            'status_msg' => 'Failed',
        ]);
        $this->assertFalse($fail['success']);
    }

    public function test_get_access_token_uses_sandbox_endpoint_with_mock(): void
    {
        Http::fake([
            'ipguat.apps.net.pk/*' => Http::response([
                'token' => 'sandbox-token-abc',
                'expiry' => 300,
            ], 200),
        ]);

        $service = app(PayFastPakistanService::class);
        $result = $service->getAccessToken('127.0.0.1');

        $this->assertSame('sandbox-token-abc', $result['token']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'ipguat.apps.net.pk')
                && str_contains($request->url(), 'GetAccessToken')
                && $request['merchant_id'] === '14833'
                && $request['grant_type'] === 'client_credentials'
                && isset($request['secured_key']);
        });
    }

    public function test_get_access_token_binds_basket_amount_currency_for_hosted_checkout(): void
    {
        Http::fake([
            'ipguat.apps.net.pk/*' => Http::response([
                'token' => 'sandbox-token-bound',
                'expiry' => 300,
            ], 200),
        ]);

        $service = app(PayFastPakistanService::class);
        $result = $service->getAccessToken('127.0.0.1', [
            'basket_id' => 'LOY-BIND-1',
            'amount' => 570,
            'currency' => 'PKR',
        ]);

        $this->assertSame('sandbox-token-bound', $result['token']);
        $this->assertSame('LOY-BIND-1', $result['basket_id']);
        Http::assertSent(function ($request) {
            return $request['basket_id'] === 'LOY-BIND-1'
                && $request['BASKET_ID'] === 'LOY-BIND-1'
                && $request['txnamt'] === '570.00'
                && $request['TXNAMT'] === '570.00'
                && $request['currency_code'] === 'PKR'
                && $request['CURRENCY_CODE'] === 'PKR';
        });
    }

    public function test_checkout_fields_include_public_callback_urls(): void
    {
        config([
            'payfast.public_base_url' => 'https://ranglerz.click/homeservices',
            'payfast.ipn_path' => '/payfast-checkout-ack.php',
        ]);

        $service = app(PayFastPakistanService::class);
        $fields = $service->buildCheckoutFields([
            'amount' => 570,
            'basket_id' => 'LOY-TEST-1',
            'access_token' => 'tok',
            'customer_mobile' => '923001234567',
            'customer_email' => 'tech@example.com',
        ]);

        $this->assertSame(
            'https://ranglerz.click/homeservices/api/payments/payfast/return/success',
            $fields['SUCCESS_URL']
        );
        $this->assertSame(
            'https://ranglerz.click/homeservices/api/payments/payfast/return/failure',
            $fields['FAILURE_URL']
        );
        $this->assertSame(
            'https://ranglerz.click/homeservices/payfast-checkout-ack.php',
            $fields['CHECKOUT_URL']
        );
        $this->assertStringNotContainsString('localhost', $fields['CHECKOUT_URL']);
    }

    public function test_live_host_is_not_used_while_sandbox_mode_enabled(): void
    {
        $service = app(PayFastPakistanService::class);
        $this->assertStringContainsString('ipguat.apps.net.pk', $service->tokenUrl());
        $this->assertStringContainsString('ipguat.apps.net.pk', $service->checkoutUrl());
        $this->assertStringNotContainsString('ipg1.apps.net.pk', $service->tokenUrl());
        $this->assertStringNotContainsString('ipg1.apps.net.pk', $service->checkoutUrl());
    }
}
