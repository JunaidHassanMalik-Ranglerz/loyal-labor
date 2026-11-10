<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\PayFastPaymentController;
use App\Models\SubscriptionPayment;
use App\Services\PayFast\PayFastPakistanService;
use App\Services\PayFast\SubscriptionPaymentActivator;
use Illuminate\Http\Request;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class PayFastCheckoutControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_public_api_url_prefers_payfast_public_base_over_localhost_app_url(): void
    {
        config([
            'app.url' => 'http://localhost',
            'payfast.public_base_url' => 'https://ranglerz.click/homeservices',
        ]);

        $controller = $this->makeController();
        $method = new ReflectionMethod($controller, 'publicApiUrl');
        $method->setAccessible(true);

        $url = $method->invoke($controller, '/api/payments/payfast/checkout/LOY-TEST-1');

        $this->assertSame(
            'https://ranglerz.click/homeservices/api/payments/payfast/checkout/LOY-TEST-1',
            $url
        );
        $this->assertStringNotContainsString('localhost', $url);
    }

    public function test_checkout_page_returns_404_when_payment_missing(): void
    {
        $controller = $this->makeController();
        $response = $controller->checkoutPage(Request::create('/api/payments/payfast/checkout/MISSING-REF', 'GET'), 'MISSING-REF');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Checkout payment record not found', $response->getContent());
    }

    public function test_checkout_page_rejects_cancelled_payment(): void
    {
        $payment = SubscriptionPayment::query()->create([
            'technician_id' => 48,
            'subscription_id' => 1,
            'order_reference' => 'LOY-CANCEL-TEST-' . uniqid(),
            'amount' => 100,
            'currency' => 'PKR',
            'gateway' => 'payfast_pakistan',
            'environment' => 'sandbox',
            'status' => SubscriptionPayment::STATUS_CANCELLED,
            'initiated_at' => now(),
        ]);

        $controller = $this->makeController();
        $response = $controller->checkoutPage(
            Request::create('/x?token=abc', 'GET', ['token' => 'abc']),
            $payment->order_reference
        );

        $this->assertSame(410, $response->getStatusCode());
        $this->assertStringContainsString('no longer payable', $response->getContent());
    }

    public function test_checkout_page_requires_token_for_pending_payment(): void
    {
        $payment = SubscriptionPayment::query()->create([
            'technician_id' => 48,
            'subscription_id' => 1,
            'order_reference' => 'LOY-PENDING-TEST-' . uniqid(),
            'amount' => 100,
            'currency' => 'PKR',
            'gateway' => 'payfast_pakistan',
            'environment' => 'sandbox',
            'status' => SubscriptionPayment::STATUS_PENDING,
            'initiated_at' => now(),
        ]);

        $controller = $this->makeController();
        $response = $controller->checkoutPage(
            Request::create('/api/payments/payfast/checkout/' . $payment->order_reference, 'GET'),
            $payment->order_reference
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('Missing checkout token', $response->getContent());
    }

    public function test_idempotency_query_ignores_cancelled_payments(): void
    {
        $cancelled = SubscriptionPayment::query()->create([
            'technician_id' => 48,
            'subscription_id' => 99,
            'order_reference' => 'LOY-OLD-CANCEL-' . uniqid(),
            'amount' => 100,
            'currency' => 'PKR',
            'gateway' => 'payfast_pakistan',
            'environment' => 'sandbox',
            'status' => SubscriptionPayment::STATUS_CANCELLED,
            'initiated_at' => now(),
        ]);

        $pending = SubscriptionPayment::query()->create([
            'technician_id' => 48,
            'subscription_id' => 99,
            'order_reference' => 'LOY-OPEN-' . uniqid(),
            'amount' => 100,
            'currency' => 'PKR',
            'gateway' => 'payfast_pakistan',
            'environment' => 'sandbox',
            'status' => SubscriptionPayment::STATUS_PENDING,
            'initiated_at' => now(),
        ]);

        $existing = SubscriptionPayment::query()
            ->where('technician_id', 48)
            ->where('subscription_id', 99)
            ->whereIn('status', [
                SubscriptionPayment::STATUS_PENDING,
                SubscriptionPayment::STATUS_PROCESSING,
            ])
            ->whereNotNull('order_reference')
            ->where('order_reference', '!=', '')
            ->where('created_at', '>=', now()->subHours(6))
            ->latest('id')
            ->first();

        $this->assertNotNull($existing);
        $this->assertSame($pending->id, $existing->id);
        $this->assertNotSame($cancelled->id, $existing->id);
    }

    public function test_valid_checkout_reference_lookup_matches_order_reference(): void
    {
        $ref = 'LOY-VALID-' . uniqid();
        $payment = SubscriptionPayment::query()->create([
            'technician_id' => 48,
            'subscription_id' => 1,
            'order_reference' => $ref,
            'amount' => 250,
            'currency' => 'PKR',
            'gateway' => 'payfast_pakistan',
            'environment' => 'sandbox',
            'status' => SubscriptionPayment::STATUS_PENDING,
            'initiated_at' => now(),
        ]);

        $found = SubscriptionPayment::query()->where('order_reference', $ref)->first();

        $this->assertNotNull($found);
        $this->assertSame($payment->id, $found->id);
        $this->assertSame(48, (int) $found->technician_id);
        $this->assertSame(SubscriptionPayment::STATUS_PENDING, $found->status);
    }

    private function makeController(): PayFastPaymentController
    {
        $payFast = Mockery::mock(PayFastPakistanService::class);
        $activator = Mockery::mock(SubscriptionPaymentActivator::class);

        return new PayFastPaymentController($payFast, $activator);
    }
}
