<?php

namespace Tests\Unit;

use App\Models\SubscriptionPayment;
use Tests\TestCase;

class SubscriptionPaymentModelTest extends TestCase
{
    public function test_paid_and_final_helpers(): void
    {
        $paid = new SubscriptionPayment(['status' => SubscriptionPayment::STATUS_PAID]);
        $pending = new SubscriptionPayment(['status' => SubscriptionPayment::STATUS_PENDING]);
        $failed = new SubscriptionPayment(['status' => SubscriptionPayment::STATUS_FAILED]);

        $this->assertTrue($paid->isPaid());
        $this->assertTrue($paid->isFinal());
        $this->assertFalse($pending->isPaid());
        $this->assertFalse($pending->isFinal());
        $this->assertTrue($failed->isFinal());
    }

    public function test_api_array_exposes_frontend_contract_keys(): void
    {
        $payment = new SubscriptionPayment([
            'id' => 10,
            'order_reference' => 'LOY-TEST-10',
            'subscription_id' => 1,
            'amount' => 570.00,
            'currency' => 'PKR',
            'gateway' => 'payfast_pakistan',
            'environment' => 'sandbox',
            'status' => SubscriptionPayment::STATUS_PAID,
            'payfast_transaction_id' => 'PF-123',
            'subscription_end' => '2026-12-09',
        ]);
        $payment->id = 10;

        $array = $payment->toApiArray();

        $this->assertSame(10, $array['payment_id']);
        $this->assertSame('LOY-TEST-10', $array['order_reference']);
        $this->assertSame('paid', $array['status']);
        $this->assertSame('PF-123', $array['transaction_reference']);
        $this->assertSame('sandbox', $array['environment']);
    }
}
