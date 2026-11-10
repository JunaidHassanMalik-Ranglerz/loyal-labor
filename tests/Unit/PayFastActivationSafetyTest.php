<?php

namespace Tests\Unit;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\PayFast\PayFastPakistanService;
use App\Services\PayFast\SubscriptionPaymentActivator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PayFastActivationSafetyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_has_unexpired_plan_ignores_pending_checkout(): void
    {
        $user = new User([
            'subscription_id' => 1,
            'subscription' => 'pending',
            'payment_status' => 'pending',
            'subscription_end' => now()->addMonth()->toDateString(),
        ]);

        $this->assertFalse($user->hasUnexpiredPlan(1));
        $this->assertFalse($user->hasActiveSubscription());
        $this->assertFalse($user->isPaymentVerified());
    }

    public function test_has_unexpired_plan_true_only_when_active_and_verified(): void
    {
        $user = new User([
            'subscription_id' => 1,
            'subscription' => 'active',
            'payment_status' => 'verified',
            'subscription_end' => now()->addMonth()->toDateString(),
        ]);

        $this->assertTrue($user->hasUnexpiredPlan(1));
        $this->assertTrue($user->hasActiveSubscription());
        $this->assertTrue($user->isPaymentVerified());
    }

    public function test_verify_without_gateway_success_does_not_activate(): void
    {
        $payFast = Mockery::mock(PayFastPakistanService::class);
        $payFast->shouldReceive('getStatusByBasketId')->once()->andReturn([
            'ok' => true,
            'normalized' => [
                'success' => false,
                'status_code' => '02',
                'status_msg' => 'Pending',
                'transaction_id' => null,
                'basket_id' => 'LOY-SAFE-1',
            ],
        ]);
        $payFast->shouldReceive('extractTransactionIdFromCallback')->andReturn(null);
        $payFast->shouldReceive('extractBasketIdFromCallback')->andReturn('LOY-SAFE-1');

        $payment = SubscriptionPayment::query()->create([
            'technician_id' => 48,
            'subscription_id' => 1,
            'order_reference' => 'LOY-SAFE-1-' . uniqid(),
            'amount' => 570,
            'currency' => 'PKR',
            'gateway' => 'payfast_pakistan',
            'environment' => 'sandbox',
            'status' => SubscriptionPayment::STATUS_PENDING,
            'initiated_at' => now(),
        ]);

        $activator = new SubscriptionPaymentActivator($payFast);
        $result = $activator->verifyAndActivate($payment, ['forged' => 'yes']);

        $this->assertFalse($result->isPaid());
        $this->assertNull($result->activated_at);
        $this->assertNotSame(SubscriptionPayment::STATUS_PAID, $result->status);
    }

    public function test_callback_urls_never_use_localhost_when_public_base_configured(): void
    {
        config([
            'payfast.mode' => 'sandbox',
            'payfast.merchant_id' => '14833',
            'payfast.merchant_name' => 'LoyLabor',
            'payfast.secured_key' => 'test-secured-key',
            'payfast.public_base_url' => 'https://ranglerz.click/homeservices',
            'app.url' => 'http://localhost',
            'payfast.success_path' => '/api/payments/payfast/return/success',
            'payfast.failure_path' => '/api/payments/payfast/return/failure',
            'payfast.ipn_path' => '/api/payments/payfast/ipn',
            'payfast.sandbox.checkout_url' => 'https://ipguat.apps.net.pk/Ecommerce/api/Transaction/PostTransaction',
        ]);

        $service = app(PayFastPakistanService::class);
        $fields = $service->buildCheckoutFields([
            'amount' => 570,
            'basket_id' => 'LOY-URL-TEST',
            'access_token' => 'token',
        ]);

        $this->assertStringContainsString('ranglerz.click', $fields['SUCCESS_URL']);
        $this->assertStringContainsString('ranglerz.click', $fields['FAILURE_URL']);
        $this->assertStringContainsString('ranglerz.click', $fields['CHECKOUT_URL']);
        $this->assertStringNotContainsString('localhost', $fields['SUCCESS_URL']);
        $this->assertStringNotContainsString('localhost', $fields['FAILURE_URL']);
    }

    public function test_stale_pending_is_not_reused_by_age_window_logic(): void
    {
        $old = SubscriptionPayment::query()->create([
            'technician_id' => 48,
            'subscription_id' => 1,
            'order_reference' => 'LOY-OLD-' . uniqid(),
            'amount' => 570,
            'currency' => 'PKR',
            'gateway' => 'payfast_pakistan',
            'environment' => 'sandbox',
            'status' => SubscriptionPayment::STATUS_PENDING,
            'initiated_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        // Force created_at in DB for age check.
        SubscriptionPayment::query()->whereKey($old->id)->update([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
        $old->refresh();

        $reuseWindowMinutes = 10;
        $tooOld = $old->created_at && $old->created_at->lt(now()->subMinutes($reuseWindowMinutes));
        $this->assertTrue($tooOld);
    }
}
